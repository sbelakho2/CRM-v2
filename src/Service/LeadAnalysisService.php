<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Lead;
use App\Repository\LeadRepository;
use Doctrine\ORM\EntityManagerInterface;

class LeadAnalysisService
{
    public function __construct(
        private LeadRepository $leadRepository,
        private EntityManagerInterface $em,
    ) {}

    /**
     * Analyze lead quality distribution across score ranges
     * Returns array of ranges: ['0-25' => count, '26-50' => count, '51-75' => count, '76-100' => count]
     * Also includes approval_rate per range
     */
    public function getLeadQualityDistribution(): array
    {
        // Aggregate queries — avoids loading all leads into memory
        $ranges = [
            '0-25'  => ['min' => 0,  'max' => 25],
            '26-50' => ['min' => 26, 'max' => 50],
            '51-75' => ['min' => 51, 'max' => 75],
            '76-100'=> ['min' => 76, 'max' => 100],
        ];

        $results = [];
        foreach ($ranges as $key => $range) {
            $data = $this->leadRepository->createQueryBuilder('l')
                ->select('COUNT(l.id) AS cnt')
                ->addSelect('SUM(CASE WHEN l.reviewStatus = :approved THEN 1 ELSE 0 END) AS approved_cnt')
                ->where('l.leadScore >= :min AND l.leadScore <= :max')
                ->setParameter('min', $range['min'])
                ->setParameter('max', $range['max'])
                ->setParameter('approved', 'approved')
                ->getQuery()
                ->getOneOrNullResult();

            $count = (int) ($data['cnt'] ?? 0);
            $approved = (int) ($data['approved_cnt'] ?? 0);
            $results[$key] = [
                'count'         => $count,
                'approved'      => $approved,
                'total'         => $count,
                'approval_rate' => $count > 0 ? round(($approved / $count) * 100) : 0,
            ];
        }

        return $results;
    }

    /**
     * Analyze source effectiveness
     * Returns array of sources with count, avg_score, approval_rate
     */
    public function getSourceEffectiveness(): array
    {
        // Aggregate query — avoids loading all leads into memory
        $rows = $this->leadRepository->createQueryBuilder('l')
            ->select('COALESCE(l.source, :unknown) AS source_name')
            ->addSelect('COUNT(l.id) AS cnt')
            ->addSelect('AVG(l.leadScore) AS avg_score')
            ->addSelect('SUM(CASE WHEN l.reviewStatus = :approved THEN 1 ELSE 0 END) AS approved_cnt')
            ->addSelect('SUM(CASE WHEN l.reviewStatus = :denied THEN 1 ELSE 0 END) AS denied_cnt')
            ->addSelect('SUM(CASE WHEN l.reviewStatus = :pending THEN 1 ELSE 0 END) AS pending_cnt')
            ->where('l.source IS NOT NULL')
            ->setParameter('unknown', 'unknown')
            ->setParameter('approved', 'approved')
            ->setParameter('denied', 'denied')
            ->setParameter('pending', 'pending')
            ->groupBy('source_name')
            ->orderBy('cnt', 'DESC')
            ->getQuery()
            ->getResult();

        $results = [];
        foreach ($rows as $row) {
            $count = (int) $row['cnt'];
            $results[] = [
                'source'        => $row['source_name'],
                'count'         => $count,
                'avg_score'     => $row['avg_score'] !== null ? round((float) $row['avg_score'], 1) : 0,
                'approved'      => (int) ($row['approved_cnt'] ?? 0),
                'pending'       => (int) ($row['pending_cnt'] ?? 0),
                'denied'        => (int) ($row['denied_cnt'] ?? 0),
                'approval_rate' => $count > 0 ? round(((int) ($row['approved_cnt'] ?? 0) / $count) * 100) : 0,
            ];
        }

        return $results;
    }

    /**
     * Get funnel analytics — counts per nurturing stage
     */
    public function getFunnelAnalytics(): array
    {
        // Aggregate query — avoids loading all leads into memory
        $rows = $this->leadRepository->createQueryBuilder('l')
            ->select('COALESCE(l.nurturingStage, :defaultStage) AS stage')
            ->addSelect('COUNT(l.id) AS cnt')
            ->addSelect('AVG(l.leadScore) AS avg_score')
            ->setParameter('defaultStage', 'new')
            ->groupBy('stage')
            ->getQuery()
            ->getResult();

        // Build lookup from query results
        $stageData = [];
        foreach ($rows as $row) {
            $stageName = $row['stage'];
            $count = (int) $row['cnt'];
            $stageData[$stageName] = [
                'stage'     => $stageName,
                'count'     => $count,
                'avg_score' => $row['avg_score'] !== null ? round((float) $row['avg_score'], 1) : 0,
            ];
        }

        // Order by predefined pipeline order, then append any extras
        $stageOrder = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'closed_won', 'closed_lost'];
        $result = [];
        foreach ($stageOrder as $s) {
            if (isset($stageData[$s])) {
                $result[] = $stageData[$s];
                unset($stageData[$s]);
            }
        }
        foreach ($stageData as $data) {
            $result[] = $data;
        }

        return $result;
    }

    /**
     * Get geographic distribution
     */
    public function getGeographicDistribution(): array
    {
        // Aggregate query — avoids loading all leads into memory
        $rows = $this->leadRepository->createQueryBuilder('l')
            ->select('COALESCE(l.regionTag, :unknown) AS region')
            ->addSelect('COUNT(l.id) AS cnt')
            ->addSelect('AVG(l.leadScore) AS avg_score')
            ->setParameter('unknown', 'unknown')
            ->groupBy('region')
            ->orderBy('cnt', 'DESC')
            ->getQuery()
            ->getResult();

        $regions = [];
        foreach ($rows as $row) {
            $count = (int) $row['cnt'];
            $regions[] = [
                'region'    => $row['region'],
                'count'     => $count,
                'avg_score' => $row['avg_score'] !== null ? round((float) $row['avg_score'], 1) : 0,
            ];
        }

        return $regions;
    }

    /**
     * Get sector breakdown
     */
    public function getSectorBreakdown(): array
    {
        $sectors = [];

        // Select only needed columns instead of loading full entities
        $leads = $this->leadRepository->createQueryBuilder('l')
            ->select('l.sectorTags', 'l.leadScore')
            ->getQuery()
            ->getResult();

        foreach ($leads as $row) {
            $sectorTags = $row['sectorTags'] ?? null;
            if (is_array($sectorTags)) {
                foreach ($sectorTags as $tag) {
                    if (!isset($sectors[$tag])) {
                        $sectors[$tag] = [
                            'sector'    => $tag,
                            'count'     => 0,
                            'avg_score' => 0,
                            'total_score' => 0,
                        ];
                    }
                    $sectors[$tag]['count']++;
                    $sectors[$tag]['total_score'] += (int) ($row['leadScore'] ?? 0);
                }
            }
        }

        foreach ($sectors as &$s) {
            $s['avg_score'] = $s['count'] > 0 ? round($s['total_score'] / $s['count'], 1) : 0;
            unset($s['total_score']);
        }
        unset($s);

        usort($sectors, fn($a, $b) => $b['count'] - $a['count']);

        return $sectors;
    }

    /**
     * Get lead quality trend over time (last 30 days, grouped by week)
     */
    public function getLeadQualityTrend(): array
    {
        $thirtyDaysAgo = new \DateTimeImmutable('-30 days');

        // Only fetch leads created in the last 30 days — avoids full table scan
        $leads = $this->leadRepository->createQueryBuilder('l')
            ->select('l.createdAt', 'l.leadScore', 'l.reviewStatus')
            ->where('l.createdAt >= :since')
            ->setParameter('since', $thirtyDaysAgo)
            ->getQuery()
            ->getResult();

        $weeks = [];
        foreach ($leads as $row) {
            $createdAt = $row['createdAt'];
            if (!$createdAt) continue;

            $weekStart = (clone $createdAt)->modify('-' . $createdAt->format('N') . ' days')->format('Y-m-d');
            if (!isset($weeks[$weekStart])) {
                $weeks[$weekStart] = [
                    'week'        => $weekStart,
                    'count'       => 0,
                    'total_score' => 0,
                    'approved'    => 0,
                ];
            }
            $weeks[$weekStart]['count']++;
            $weeks[$weekStart]['total_score'] += (int) ($row['leadScore'] ?? 0);
            if (($row['reviewStatus'] ?? '') === 'approved') {
                $weeks[$weekStart]['approved']++;
            }
        }

        foreach ($weeks as &$w) {
            $w['avg_score'] = $w['count'] > 0 ? round($w['total_score'] / $w['count'], 1) : 0;
            $w['approval_rate'] = $w['count'] > 0 ? round(($w['approved'] / $w['count']) * 100) : 0;
            unset($w['total_score'], $w['approved']);
        }
        unset($w);

        ksort($weeks);
        return array_values($weeks);
    }

    /**
     * Get actionable insights — leads needing attention
     * Returns leads that are high-scoring but still pending, or stale leads
     */
    public function getActionableInsights(): array
    {
        $insights = [];
        $now = new \DateTimeImmutable();
        $fourteenDaysAgo = new \DateTimeImmutable('-14 days');

        // 1. High-score pending leads (score >= 70, status = pending)
        $highValuePending = $this->leadRepository->createQueryBuilder('l')
            ->where('l.leadScore >= 70')
            ->andWhere('l.reviewStatus = :pending')
            ->setParameter('pending', 'pending')
            ->orderBy('l.leadScore', 'DESC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        foreach ($highValuePending as $lead) {
            $insights[] = [
                'type'     => 'high_value_pending',
                'lead'     => $lead,
                'message'  => 'High-value lead pending review',
                'priority' => 'high',
                'score'    => $lead->getLeadScore() ?? 0,
            ];
        }

        // 2. Stale leads (created > 14 days, still pending)
        $staleLeads = $this->leadRepository->createQueryBuilder('l')
            ->where('l.createdAt <= :staleSince')
            ->andWhere('l.reviewStatus = :pending')
            ->setParameter('staleSince', $fourteenDaysAgo)
            ->setParameter('pending', 'pending')
            ->orderBy('l.createdAt', 'ASC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        foreach ($staleLeads as $lead) {
            $insights[] = [
                'type'     => 'stale_lead',
                'lead'     => $lead,
                'message'  => 'Lead has been pending for over 14 days',
                'priority' => 'medium',
                'score'    => $lead->getLeadScore() ?? 0,
            ];
        }

        // 3. High-score denied leads (score >= 80, status = denied)
        $deniedHighValue = $this->leadRepository->createQueryBuilder('l')
            ->where('l.leadScore >= 80')
            ->andWhere('l.reviewStatus = :denied')
            ->setParameter('denied', 'denied')
            ->orderBy('l.leadScore', 'DESC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        foreach ($deniedHighValue as $lead) {
            $insights[] = [
                'type'     => 'denied_high_value',
                'lead'     => $lead,
                'message'  => 'High-scoring lead was denied — review reason',
                'priority' => 'medium',
                'score'    => $lead->getLeadScore() ?? 0,
            ];
        }

        // Sort by score descending
        usort($insights, fn($a, $b) => $b['score'] - $a['score']);

        return $insights;
    }

    /**
     * Generate ICP (Ideal Customer Profile) comparison for a lead
     * Compares lead attributes against ideal profile defined by top-performing leads
     */
    public function generateIcpComparison(Lead $lead): array
    {
        // Get top 20 approved leads as reference profile
        $topLeads = $this->leadRepository->findBy(
            ['reviewStatus' => 'approved'],
            ['leadScore' => 'DESC'],
            20
        );

        $icpSectors = [];
        $icpRegions = [];
        foreach ($topLeads as $tl) {
            $tags = $tl->getSectorTags();
            if (is_array($tags)) {
                foreach ($tags as $tag) {
                    $icpSectors[$tag] = ($icpSectors[$tag] ?? 0) + 1;
                }
            }
            $region = $tl->getRegionTag();
            if ($region) {
                $icpRegions[$region] = ($icpRegions[$region] ?? 0) + 1;
            }
        }

        // Score sector fit
        $leadSectors = $lead->getSectorTags() ?? [];
        $sectorFit = 0;
        $totalSectors = count($icpSectors);
        if ($totalSectors > 0 && is_array($leadSectors)) {
            $matches = 0;
            foreach ($leadSectors as $s) {
                if (isset($icpSectors[$s])) $matches++;
            }
            $sectorFit = count($leadSectors) > 0
                ? round(($matches / count($leadSectors)) * 100)
                : 0;
        }

        // Score region fit
        $leadRegion = $lead->getRegionTag();
        $regionFit = 0;
        if ($leadRegion && isset($icpRegions[$leadRegion])) {
            $regionFit = min(100, round(($icpRegions[$leadRegion] / max(array_values($icpRegions))) * 100));
        }

        // Overall ICP score
        $icpScore = round(($sectorFit * 0.6 + $regionFit * 0.4));

        return [
            'icp_score'      => $icpScore,
            'sector_fit'     => $sectorFit,
            'region_fit'     => $regionFit,
            'top_sectors'    => array_slice($icpSectors, 0, 5, true),
            'top_regions'    => array_slice($icpRegions, 0, 5, true),
            'lead_score'     => $lead->getLeadScore(),
            'recommendation' => $icpScore >= 70 ? 'highly_recommended' : ($icpScore >= 40 ? 'moderate_fit' : 'low_fit'),
        ];
    }
}
