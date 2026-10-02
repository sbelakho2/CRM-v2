<?php

namespace App\Repository;

use App\Entity\LearnedCompetitor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LearnedCompetitor>
 */
class LearnedCompetitorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LearnedCompetitor::class);
    }

    /**
     * Find by domain (exact match)
     */
    public function findByDomain(string $domain): ?LearnedCompetitor
    {
        return $this->findOneBy(['domain' => strtolower(trim($domain))]);
    }

    /**
     * Find all active competitors by tier
          *
     * @return list<LearnedCompetitor>
     */
    public function findActiveByTier(int $tier): array
    {
        /** @var list<LearnedCompetitor> $results */
        $results = $this->createQueryBuilder('c')
            ->where('c.active = :active')
            ->andWhere('c.tier = :tier')
            ->setParameter('active', true)
            ->setParameter('tier', $tier)
            ->orderBy('c.detectionCount', 'DESC')

            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Find all active competitors
          *
     * @return list<LearnedCompetitor>
     */
    public function findAllActive(): array
    {
        /** @var list<LearnedCompetitor> $results */
        $results = $this->createQueryBuilder('c')
            ->where('c.active = :active')
            ->setParameter('active', true)
            ->orderBy('c.tier', 'ASC')
            ->addOrderBy('c.detectionCount', 'DESC')

            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Find verified competitors only
          *
     * @return list<LearnedCompetitor>
     */
    public function findVerified(): array
    {
        /** @var list<LearnedCompetitor> $results */
        $results = $this->createQueryBuilder('c')
            ->where('c.active = :active')
            ->andWhere('c.verified = :verified')
            ->setParameter('active', true)
            ->setParameter('verified', true)
            ->orderBy('c.tier', 'ASC')

            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Find competitors with high confidence (>= threshold)
          *
     * @return list<LearnedCompetitor>
     */
    public function findHighConfidence(int $minConfidence = 70): array
    {
        /** @var list<LearnedCompetitor> $results */
        $results = $this->createQueryBuilder('c')
            ->where('c.active = :active')
            ->andWhere('c.confidenceScore >= :minConfidence')
            ->setParameter('active', true)
            ->setParameter('minConfidence', $minConfidence)
            ->orderBy('c.confidenceScore', 'DESC')

            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Find competitors pending verification
          *
     * @return list<LearnedCompetitor>
     */
    public function findPendingVerification(int $minDetections = 5): array
    {
        /** @var list<LearnedCompetitor> $results */
        $results = $this->createQueryBuilder('c')
            ->where('c.active = :active')
            ->andWhere('c.verified = :verified')
            ->andWhere('c.detectionCount >= :minDetections')
            ->setParameter('active', true)
            ->setParameter('verified', false)
            ->setParameter('minDetections', $minDetections)
            ->orderBy('c.detectionCount', 'DESC')

            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Find competitors by industry
          *
     * @return list<LearnedCompetitor>
     */
    public function findByIndustry(string $industry): array
    {
        /** @var list<LearnedCompetitor> $results */
        $results = $this->createQueryBuilder('c')
            ->where('c.active = :active')
            ->andWhere('c.industry = :industry')
            ->setParameter('active', true)
            ->setParameter('industry', $industry)
            ->orderBy('c.tier', 'ASC')
            ->addOrderBy('c.detectionCount', 'DESC')

            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Search competitors by name or alias
          *
     * @return list<LearnedCompetitor>
     */
    public function searchByName(string $query): array
    {
        $query = strtolower(trim($query));
        
        /** @var list<LearnedCompetitor> $results */
        $results = $this->createQueryBuilder('c')
            ->where('c.active = :active')
            ->andWhere('LOWER(c.name) LIKE :query OR LOWER(c.domain) LIKE :query OR LOWER(c.fullName) LIKE :query')
            ->setParameter('active', true)
            ->setParameter('query', '%' . addcslashes($query, '%_') . '%')
            ->orderBy('c.tier', 'ASC')

            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Get competitor statistics
     *
     * @return array{byTier: array<int|string, int>, byIndustry: array<int|string, int>, total: int, verified: int, highConfidence: int}
     */
    public function getStatistics(): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('
                c.tier,
                c.industry,
                COUNT(c.id) as count,
                SUM(c.detectionCount) as totalDetections,
                AVG(c.confidenceScore) as avgConfidence,
                SUM(CASE WHEN c.verified = :verified THEN 1 ELSE 0 END) as verifiedCount,
                SUM(CASE WHEN c.confidenceScore >= :minConfidence THEN 1 ELSE 0 END) as highConfidenceCount
            ')
            ->where('c.active = :active')
            ->setParameter('active', true)
            ->setParameter('verified', true)
            ->setParameter('minConfidence', 70)
            ->groupBy('c.tier, c.industry');

        /** @var array<int, array<string, mixed>> $results */
        $results = $qb->getQuery()->getArrayResult();

        $stats = [
            'byTier' => [],
            'byIndustry' => [],
            'total' => 0,
            'verified' => 0,
            'highConfidence' => 0,
        ];

        foreach ($results as $row) {
            $tier = is_scalar($row['tier'] ?? null) ? (string) $row['tier'] : 'unknown';
            $industry = is_string($row['industry'] ?? null) ? $row['industry'] : 'unknown';
            $count = is_numeric($row['count'] ?? null) ? (int) $row['count'] : 0;

            if (!isset($stats['byTier'][$tier])) {
                $stats['byTier'][$tier] = 0;
            }
            if (!isset($stats['byIndustry'][$industry])) {
                $stats['byIndustry'][$industry] = 0;
            }

            $stats['byTier'][$tier] += $count;
            $stats['byIndustry'][$industry] += $count;
            $stats['total'] += $count;
            $stats['verified'] += is_numeric($row['verifiedCount'] ?? null) ? (int) $row['verifiedCount'] : 0;
            $stats['highConfidence'] += is_numeric($row['highConfidenceCount'] ?? null) ? (int) $row['highConfidenceCount'] : 0;
        }

        return $stats;
    }

    /**
     * Find recently discovered competitors
          *
     * @return list<LearnedCompetitor>
     */
    public function findRecentlyDiscovered(int $days = 7, int $limit = 20): array
    {
        $since = new \DateTime("-{$days} days");
        
        /** @var list<LearnedCompetitor> $results */
        $results = $this->createQueryBuilder('c')
            ->where('c.firstDetectedAt >= :since')
            ->andWhere('c.active = :active')
            ->setParameter('since', $since)
            ->setParameter('active', true)
            ->orderBy('c.firstDetectedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Find most frequently detected competitors
          *
     * @return list<LearnedCompetitor>
     */
    public function findMostFrequent(int $limit = 20): array
    {
        /** @var list<LearnedCompetitor> $results */
        $results = $this->createQueryBuilder('c')
            ->where('c.active = :active')
            ->setParameter('active', true)
            ->orderBy('c.detectionCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Get competitors as map for quick lookup (domain => entity)
     *
     * @return array<string, LearnedCompetitor>
     */
    public function getCompetitorMap(): array
    {
        $competitors = $this->findAllActive();
        $map = [];
        
        foreach ($competitors as $competitor) {
            $map[$competitor->getDomain() ?? ''] = $competitor;
        }
        
        return $map;
    }
}
