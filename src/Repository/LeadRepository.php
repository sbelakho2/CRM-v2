<?php

namespace App\Repository;

use App\Entity\Lead;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Lead>
 */
class LeadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Lead::class);
    }

    /**
     * Find leads by region tag with pagination
     */
    public function findByRegion(string $regionTag, int $page = 1, int $limit = 50): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.regionTag = :region')
            ->setParameter('region', $regionTag)
            ->orderBy('l.leadScore', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find pending leads for review
     */
    public function findPendingLeads(?string $regionTag = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->where('l.reviewStatus = :status')
            ->setParameter('status', 'pending')
            ->orderBy('l.leadScore', 'DESC')
            ->setMaxResults(500);

        if ($regionTag) {
            $qb->andWhere('l.regionTag = :region')
               ->setParameter('region', $regionTag);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find leads by score threshold with pagination
     */
    public function findByScoreThreshold(int $minScore, ?string $regionTag = null, int $page = 1, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('l')
            ->where('l.leadScore >= :minScore')
            ->setParameter('minScore', $minScore)
            ->orderBy('l.leadScore', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        if ($regionTag) {
            $qb->andWhere('l.regionTag = :region')
               ->setParameter('region', $regionTag);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Check for duplicate by dupe key
     */
    public function findByDupeKey(string $dupeKey): ?Lead
    {
        return $this->createQueryBuilder('l')
            ->where('l.dupeKey = :dupeKey')
            ->setParameter('dupeKey', $dupeKey)
            ->orderBy('l.leadScore', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find lead by website root URL (used for duplicate checking)
     */
    public function findByWebsiteRoot(string $websiteRoot): ?Lead
    {
        return $this->createQueryBuilder('l')
            ->where('l.websiteRoot = :website')
            ->setParameter('website', $websiteRoot)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Batch count leads needing enrichment
     */
    public function countLeadsNeedingEnrichment(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.websiteRoot IS NOT NULL')
            ->andWhere('l.contactEmailsPublic IS NULL OR l.contactEmailsPublic = :empty')
            ->andWhere('l.hasContactForm = false')
            ->andWhere('l.lastScrapedAt IS NULL')
            ->setParameter('empty', '[]')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get lead statistics by region
     */
    public function getStatsByRegion(): array
    {
        return $this->createQueryBuilder('l')
            ->select('l.regionTag, COUNT(l.id) as total, AVG(l.leadScore) as avg_score')
            ->groupBy('l.regionTag')
            ->setMaxResults(1000)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get top scoring leads
     */
    public function getTopLeads(int $limit = 50, ?string $regionTag = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->orderBy('l.leadScore', 'DESC')
            ->setMaxResults($limit);

        if ($regionTag) {
            $qb->where('l.regionTag = :region')
               ->setParameter('region', $regionTag);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get approval rate for precision calculation
     */
    public function getApprovalRate(?string $regionTag = null, ?\DateTime $since = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->select('COUNT(l.id) as total,
                      SUM(CASE WHEN l.reviewStatus = :approved THEN 1 ELSE 0 END) as approved,
                      SUM(CASE WHEN l.reviewStatus = :denied THEN 1 ELSE 0 END) as denied')
            ->setParameter('approved', 'approved')
            ->setParameter('denied', 'denied');

        if ($regionTag) {
            $qb->andWhere('l.regionTag = :region')
               ->setParameter('region', $regionTag);
        }

        if ($since) {
            $qb->andWhere('l.createdAt >= :since')
               ->setParameter('since', $since);
        }

        return $qb->getQuery()->getSingleResult();
    }
    /**
     * Count leads by specific nurturing stage
     */
    public function countByStage(string $stage): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.nurturingStage = :stage')
            ->setParameter('stage', $stage)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get average score by region
     */
    public function getAvgScoreByRegion(): array
    {
        return $this->createQueryBuilder('l')
            ->select('l.regionTag as region, AVG(l.leadScore) as avgScore, COUNT(l.id) as count')
            ->where('l.leadScore IS NOT NULL')
            ->groupBy('l.regionTag')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get lead counts by review status
     */
    public function getCountsByStatus(): array
    {
        return $this->createQueryBuilder('l')
            ->select('l.reviewStatus as status, COUNT(l.id) as count')
            ->groupBy('l.reviewStatus')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count leads needing enrichment (missing contact emails)
     */
    public function countNeedsContactData(): int
    {
        $orX = $this->createQueryBuilder('l')->expr()->orX();
        $orX->add('l.contactEmailsPublic IS NULL');
        $orX->add('l.contactEmailsPublic = :empty');

        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where($orX)
            ->setParameter('empty', '[]')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get recent leads with score > threshold for alerting
     */
    public function findHighScoreRecent(\DateTimeInterface $since, int $minScore = 70): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.createdAt >= :since')
            ->andWhere('l.leadScore >= :minScore')
            ->setParameter('since', $since)
            ->setParameter('minScore', $minScore)
            ->orderBy('l.leadScore', 'DESC')
            ->setMaxResults(1000)
            ->getQuery()
            ->getResult();
    }
}
