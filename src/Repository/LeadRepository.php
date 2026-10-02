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
     *
     * @return list<Lead>
     */
    public function findByRegion(string $regionTag, int $page = 1, int $limit = 50): array
    {
        /** @var list<Lead> $result */
        $result = $this->createQueryBuilder('l')
            ->where('l.regionTag = :region')
            ->setParameter('region', $regionTag)
            ->orderBy('l.leadScore', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find pending leads for review
     *
     * @return list<Lead>
     */
    public function findPendingLeads(?string $regionTag = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->where('l.reviewStatus = :status')
            ->setParameter('status', 'pending')
            ->orderBy('l.leadScore', 'DESC');

        if ($regionTag) {
            $qb->andWhere('l.regionTag = :region')
               ->setParameter('region', $regionTag);
        }

        /** @var list<Lead> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Find leads by score threshold with pagination
     */
    /**
     * @return list<Lead>
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

        /** @var list<Lead> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Check for duplicate by dupe key
     */
    public function findByDupeKey(string $dupeKey): ?Lead
    {
        /** @var Lead|null $result */
        $result = $this->createQueryBuilder('l')
            ->where('l.dupeKey = :dupeKey')
            ->setParameter('dupeKey', $dupeKey)
            ->orderBy('l.leadScore', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Find lead by website root URL (used for duplicate checking)
     */
    public function findByWebsiteRoot(string $websiteRoot): ?Lead
    {
        /** @var Lead|null $result */
        $result = $this->createQueryBuilder('l')
            ->where('l.websiteRoot = :website')
            ->setParameter('website', $websiteRoot)
            ->orderBy('l.leadScore', 'DESC')
            ->addOrderBy('l.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
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
     *
     * @return list<array{regionTag: mixed, total: mixed, avg_score: mixed}>
     */
    public function getStatsByRegion(): array
    {
        /** @var list<array{regionTag: mixed, total: mixed, avg_score: mixed}> $result */
        $result = $this->createQueryBuilder('l')
            ->select('l.regionTag, COUNT(l.id) as total, AVG(l.leadScore) as avg_score')
            ->groupBy('l.regionTag')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Get top scoring leads
     *
     * @return list<Lead>
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

        /** @var list<Lead> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Get approval rate for precision calculation
     *
     * @return array{total: mixed, approved: mixed, denied: mixed}
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

        /** @var array{total: mixed, approved: mixed, denied: mixed} $result */
        $result = $qb->getQuery()->getSingleResult();

        return $result;
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
     *
     * @return list<array{region: mixed, avgScore: mixed, count: mixed}>
     */
    public function getAvgScoreByRegion(): array
    {
        /** @var list<array{region: mixed, avgScore: mixed, count: mixed}> $result */
        $result = $this->createQueryBuilder('l')
            ->select('l.regionTag as region, AVG(l.leadScore) as avgScore, COUNT(l.id) as count')
            ->where('l.leadScore IS NOT NULL')
            ->groupBy('l.regionTag')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Get lead counts by review status
     *
     * @return list<array{status: mixed, count: mixed}>
     */
    public function getCountsByStatus(): array
    {
        /** @var list<array{status: mixed, count: mixed}> $result */
        $result = $this->createQueryBuilder('l')
            ->select('l.reviewStatus as status, COUNT(l.id) as count')
            ->groupBy('l.reviewStatus')
            ->getQuery()
            ->getResult();

        return $result;
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
     *
     * @return list<Lead>
     */
    public function findHighScoreRecent(\DateTimeInterface $since, int $minScore = 70): array
    {
        /** @var list<Lead> $result */
        $result = $this->createQueryBuilder('l')
            ->where('l.createdAt >= :since')
            ->andWhere('l.leadScore >= :minScore')
            ->setParameter('since', $since)
            ->setParameter('minScore', $minScore)
            ->orderBy('l.leadScore', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
