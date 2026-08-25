<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CompanyRepository;
use App\Repository\ActivityRepository;
use App\Repository\EmailSendRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * EngagementHeatMapService
 * 
 * Generates engagement heat map data for the ABM Dashboard.
 * Calculates engagement scores based on:
 * - Number of activities (calls, meetings, emails)
 * - Email opens and clicks
 * - Recent activity recency
 * - ABM hits (website visits)
 */
class EngagementHeatMapService
{
    private const CACHE_KEY = 'engagement_heat_map_data';
    private const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        private CompanyRepository $companyRepository,
        private ActivityRepository $activityRepository,
        private EmailSendRepository $emailSendRepository,
        private EntityManagerInterface $entityManager,
        private ?CacheItemPoolInterface $cache = null
    ) {}

    /**
     * Get top companies by engagement score
     * 
     * @param int $limit Number of companies to return (default 20)
     * @param bool $useCache Whether to use cached results
     * 
     * @return array<array{
     *   id: int,
     *   name: string,
     *   score: int,
     *   color: string,
     *   activities_count: int,
     *   email_opens: int,
     *   recent_activity_days: int|null
     * }>
     */
    public function getTopCompaniesByEngagement(int $limit = 20, bool $useCache = true): array
    {
        // Try to get from cache
        if ($useCache && $this->cache) {
            $cacheItem = $this->cache->getItem(self::CACHE_KEY . '_' . $limit);
            if ($cacheItem->isHit()) {
                return $cacheItem->get();
            }
        }

        // Get companies - prioritize those with activities, but include others too
        $qb = $this->companyRepository->createQueryBuilder('c')
            ->select('c')
            ->leftJoin('c.activities', 'a')
            ->groupBy('c.id')
            ->orderBy('COUNT(a.id)', 'DESC')
            ->addOrderBy('c.id', 'ASC') // Fallback ordering for companies without activities
            ->setMaxResults(50); // Limit to top 50 to speed up processing

        $companies = $qb->getQuery()->getResult();
        
        $engagementData = [];

        foreach ($companies as $company) {
            $score = $this->calculateEngagementScore($company);
            
            // Include all companies to show something on the heat map
            $engagementData[] = [
                'id' => $company->getId(),
                'name' => $company->getName(),
                'score' => $score,
                'color' => $this->getColorForScore($score),
                'activities_count' => $this->getActivitiesCount($company),
                'email_opens' => $this->getEmailOpens($company),
                'recent_activity_days' => $this->getDaysSinceLastActivity($company),
            ];
        }

        // Sort by score descending
        usort($engagementData, fn($a, $b) => $b['score'] <=> $a['score']);

        // Limit results
        $result = array_slice($engagementData, 0, $limit);

        // Cache the result
        if ($useCache && $this->cache) {
            $cacheItem->set($result);
            $cacheItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($cacheItem);
        }

        return $result;
    }

    /**
     * Calculate engagement score for a company (0-100)
     * 
     * Scoring formula:
     * - Base: 10 points per activity (capped at 50)
     * - Email opens: 2 points each (capped at 20)
     * - Recent activity bonus: 20 points if activity in last 7 days
     * - Pipeline stage bonus: 10 points if in active stages
     */
    private function calculateEngagementScore($company): int
    {
        $score = 0;

        // Activity score (max 50 points)
        $activitiesCount = $this->getActivitiesCount($company);
        $score += min($activitiesCount * 10, 50);

        // Email engagement score (max 20 points)
        $emailOpens = $this->getEmailOpens($company);
        $score += min($emailOpens * 2, 20);

        // Recency bonus (20 points)
        $daysSinceLastActivity = $this->getDaysSinceLastActivity($company);
        if ($daysSinceLastActivity !== null && $daysSinceLastActivity <= 7) {
            $score += 20;
        }

        // Pipeline stage bonus (10 points)
        $pipelineStage = $company->getPipelineStage();
        if (in_array($pipelineStage, ['Qualified', 'Proposal', 'Negotiation'])) {
            $score += 10;
        }

        return min($score, 100);
    }

    /**
     * Get count of activities for a company
     */
    private function getActivitiesCount($company): int
    {
        return count($company->getActivities());
    }

    /**
     * Get number of email opens for a company
     */
    private function getEmailOpens($company): int
    {
        try {
            $contacts = $company->getContacts();
            $totalOpens = 0;

            foreach ($contacts as $contact) {
                // Get email sends for this contact using query builder to avoid field errors
                $emailSends = $this->emailSendRepository->createQueryBuilder('e')
                    ->where('e.contact = :contact')
                    ->setParameter('contact', $contact)
                    ->getQuery()
                    ->getResult();
                
                foreach ($emailSends as $send) {
                    if ($send->isOpened()) {
                        $totalOpens++;
                    }
                }
            }

            return $totalOpens;
        } catch (\Exception $e) {
            // Return 0 if there's any database error
            return 0;
        }
    }

    /**
     * Get days since last activity for a company
     */
    private function getDaysSinceLastActivity($company): ?int
    {
        $activities = $company->getActivities();
        
        if ($activities->isEmpty()) {
            return null;
        }

        $lastActivity = null;
        foreach ($activities as $activity) {
            $createdAt = $activity->getCreatedAt();
            if ($createdAt && (!$lastActivity || $createdAt > $lastActivity)) {
                $lastActivity = $createdAt;
            }
        }

        if (!$lastActivity) {
            return null;
        }

        $now = new \DateTime();
        $interval = $now->diff($lastActivity);
        
        return $interval->days;
    }

    /**
     * Get color based on engagement score
     * 
     * @param int $score Engagement score (0-100)
     * @return string Hex color code
     */
    private function getColorForScore(int $score): string
    {
        if ($score >= 60) {
            return '#10B981'; // Green
        } elseif ($score >= 30) {
            return '#FBBF24'; // Yellow/Amber
        } else {
            return '#EF4444'; // Red
        }
    }

    /**
     * Clear the engagement heat map cache
     *
     * Cache keys are namespaced per limit ('engagement_heat_map_data_20',
     * 'engagement_heat_map_data_50', ...). Delete every known limit variant
     * plus a sweep of plausible values so no orphaned entries survive.
     */
    public function clearCache(): void
    {
        if (!$this->cache) {
            return;
        }

        // Known/plausible limit values the service is called with.
        $limits = array_unique(array_merge(
            [10, 15, 20, 25, 30, 50, 100],
            range(1, 50)
        ));

        $keys = array_map(fn(int $limit) => self::CACHE_KEY . '_' . $limit, $limits);

        try {
            $this->cache->deleteItems($keys);
        } catch (\Throwable $e) {
            // Fall back to per-item deletion for cache pools without
            // batch-delete support.
            foreach ($keys as $key) {
                $this->cache->deleteItem($key);
            }
        }
    }
}
