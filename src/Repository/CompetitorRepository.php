<?php

namespace App\Repository;

use App\Entity\Competitor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Competitor>
 */
class CompetitorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Competitor::class);
    }

    public function save(Competitor $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Competitor $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Find by canonical domain (unique lookup) */
    public function findByDomain(string $domain): ?Competitor
    {
        return $this->findOneBy(['canonicalDomain' => $domain]);
    }

    /** Find competitors matching any of the given domains (canonical or alt) */
    public function findByAnyDomain(string $domain): ?Competitor
    {
        // First check canonical
        $c = $this->findByDomain($domain);
        if ($c) return $c;

        // Check alt domains stored in JSON array using LIKE on serialised column
        // Safe: domains contain no SQL-special chars, and JSON wraps in quotes
        return $this->createQueryBuilder('c')
            ->where('c.altDomains LIKE :domain')
            ->setParameter('domain', '%"' . $domain . '"%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Dashboard query: get competitors with filters
     *
     * @param array $filters Keys: status, directness, type, region, sector, search, minThreat, minOverlap
     * @param string $sortBy Column to sort by
     * @param string $sortDir ASC or DESC
     * @return Competitor[]
     */
    public function findFiltered(array $filters = [], string $sortBy = 'threatScore', string $sortDir = 'DESC', int $limit = 100, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('c');

        if (!empty($filters['status'])) {
            $qb->andWhere('c.status = :status')->setParameter('status', $filters['status']);
        }
        if (!empty($filters['directness'])) {
            $qb->andWhere('c.directness = :directness')->setParameter('directness', $filters['directness']);
        }
        if (!empty($filters['type'])) {
            $qb->andWhere('c.competitorTypes LIKE :type')
               ->setParameter('type', '%"' . $filters['type'] . '"%');
        }
        if (!empty($filters['region'])) {
            $qb->andWhere('c.regions LIKE :region')
               ->setParameter('region', '%"' . $filters['region'] . '"%');
        }
        if (!empty($filters['sector'])) {
            $qb->andWhere('c.industries LIKE :sector')
               ->setParameter('sector', '%"' . $filters['sector'] . '"%');
        }
        if (!empty($filters['search'])) {
            $qb->andWhere('c.name LIKE :search OR c.canonicalDomain LIKE :search')
               ->setParameter('search', '%' . $filters['search'] . '%');
        }
        if (isset($filters['minThreat'])) {
            $qb->andWhere('c.threatScore >= :minThreat')->setParameter('minThreat', (int)$filters['minThreat']);
        }
        if (isset($filters['minOverlap'])) {
            $qb->andWhere('c.overlapScore >= :minOverlap')->setParameter('minOverlap', (int)$filters['minOverlap']);
        }
        if (!empty($filters['seedOnly'])) {
            $qb->andWhere('c.seedOnly = :seedOnly')->setParameter('seedOnly', $filters['seedOnly'] === 'yes');
        }

        // Validate sort column
        $validSorts = ['threatScore', 'overlapScore', 'strategicRelevanceScore', 'name', 'createdAt', 'updatedAt', 'lastCrawledAt', 'status'];
        if (!in_array($sortBy, $validSorts, true)) {
            $sortBy = 'threatScore';
        }
        $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        $qb->orderBy("c.{$sortBy}", $sortDir)
           ->setMaxResults($limit)
           ->setFirstResult($offset);

        return $qb->getQuery()->getResult();
    }

    /** Count competitors matching filters */
    public function countFiltered(array $filters = []): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)');

        if (!empty($filters['status'])) {
            $qb->andWhere('c.status = :status')->setParameter('status', $filters['status']);
        }
        if (!empty($filters['directness'])) {
            $qb->andWhere('c.directness = :directness')->setParameter('directness', $filters['directness']);
        }
        if (!empty($filters['type'])) {
            $qb->andWhere('c.competitorTypes LIKE :type')
               ->setParameter('type', '%"' . $filters['type'] . '"%');
        }
        if (!empty($filters['search'])) {
            $qb->andWhere('c.name LIKE :search OR c.canonicalDomain LIKE :search')
               ->setParameter('search', '%' . $filters['search'] . '%');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** Get all verified/profiled/monitoring competitors (active pipeline) */
    public function findActive(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status IN (:statuses)')
            ->setParameter('statuses', [Competitor::STATUS_VERIFIED, Competitor::STATUS_PROFILED, Competitor::STATUS_MONITORING])
            ->orderBy('c.threatScore', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Get competitors needing shallow crawl (not crawled in N days) */
    public function findDueForShallowCrawl(int $daysStale = 7, int $limit = 50): array
    {
        $cutoff = new \DateTime("-{$daysStale} days");
        return $this->createQueryBuilder('c')
            ->where('c.status IN (:statuses)')
            ->andWhere('c.seedOnly = false')
            ->andWhere('c.tosBlocksCrawl = false')
            ->andWhere('(c.lastShallowCrawlAt IS NULL OR c.lastShallowCrawlAt < :cutoff)')
            ->setParameter('statuses', [Competitor::STATUS_VERIFIED, Competitor::STATUS_PROFILED, Competitor::STATUS_MONITORING])
            ->setParameter('cutoff', $cutoff)
            ->orderBy('c.lastShallowCrawlAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Get competitors needing deep crawl (not crawled in N days) */
    public function findDueForDeepCrawl(int $daysStale = 30, int $limit = 20): array
    {
        $cutoff = new \DateTime("-{$daysStale} days");
        return $this->createQueryBuilder('c')
            ->where('c.status IN (:statuses)')
            ->andWhere('c.seedOnly = false')
            ->andWhere('c.tosBlocksCrawl = false')
            ->andWhere('(c.lastDeepCrawlAt IS NULL OR c.lastDeepCrawlAt < :cutoff)')
            ->setParameter('statuses', [Competitor::STATUS_VERIFIED, Competitor::STATUS_PROFILED, Competitor::STATUS_MONITORING])
            ->setParameter('cutoff', $cutoff)
            ->orderBy('c.threatScore', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Get top N competitors by overlap for a given region and sector */
    public function findTopOverlap(string $region, string $sector, int $limit = 5): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status IN (:statuses)')
            ->andWhere('c.regions LIKE :region')
            ->andWhere('c.industries LIKE :sector')
            ->setParameter('statuses', [Competitor::STATUS_VERIFIED, Competitor::STATUS_PROFILED, Competitor::STATUS_MONITORING])
            ->setParameter('region', '%"' . $region . '"%')
            ->setParameter('sector', '%"' . $sector . '"%')
            ->orderBy('c.overlapScore', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Dashboard stats */
    public function getDashboardStats(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $total = (int) $conn->fetchOne('SELECT COUNT(*) FROM competitors');
        $active = (int) $conn->fetchOne("SELECT COUNT(*) FROM competitors WHERE status IN ('verified','profiled','monitoring')");
        $candidates = (int) $conn->fetchOne("SELECT COUNT(*) FROM competitors WHERE status = 'candidate'");
        $rejected = (int) $conn->fetchOne("SELECT COUNT(*) FROM competitors WHERE status = 'rejected'");
        $highThreat = (int) $conn->fetchOne("SELECT COUNT(*) FROM competitors WHERE threat_score >= 70");
        $avgThreat = (float) $conn->fetchOne("SELECT COALESCE(AVG(threat_score), 0) FROM competitors WHERE status != 'rejected'");
        $avgOverlap = (float) $conn->fetchOne("SELECT COALESCE(AVG(overlap_score), 0) FROM competitors WHERE status != 'rejected'");
        $recentChanges = (int) $conn->fetchOne("SELECT COUNT(*) FROM competitor_change_events WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)");

        // Type distribution
        $typeDistribution = [];
        foreach (Competitor::VALID_TYPES as $type) {
            $count = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM competitors WHERE JSON_CONTAINS(competitor_types, ?)",
                [json_encode($type)]
            );
            if ($count > 0) {
                $typeDistribution[$type] = $count;
            }
        }

        return [
            'total' => $total,
            'active' => $active,
            'verified' => $active,
            'candidates' => $candidates,
            'rejected' => $rejected,
            'highThreat' => $highThreat,
            'high_threat' => $highThreat,
            'avgThreat' => round($avgThreat, 1),
            'avg_threat' => round($avgThreat, 1),
            'avgOverlap' => round($avgOverlap, 1),
            'avg_overlap' => round($avgOverlap, 1),
            'recentChanges' => $recentChanges,
            'typeDistribution' => $typeDistribution,
            'type_distribution' => $typeDistribution,
        ];
    }

    /** Get all verified competitor domains (for LeadCrawler blocklist) */
    public function getAllVerifiedDomains(): array
    {
        $results = $this->createQueryBuilder('c')
            ->select('c.canonicalDomain, c.altDomains')
            ->where('c.status IN (:statuses)')
            ->setParameter('statuses', [Competitor::STATUS_VERIFIED, Competitor::STATUS_PROFILED, Competitor::STATUS_MONITORING])
            ->getQuery()
            ->getArrayResult();

        $domains = [];
        foreach ($results as $row) {
            $domains[] = $row['canonicalDomain'];
            if (!empty($row['altDomains'])) {
                $domains = array_merge($domains, $row['altDomains']);
            }
        }
        return array_unique($domains);
    }
}
