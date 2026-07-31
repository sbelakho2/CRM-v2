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
     */
    public function findActiveByTier(int $tier): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.active = :active')
            ->andWhere('c.tier = :tier')
            ->setParameter('active', true)
            ->setParameter('tier', $tier)
            ->orderBy('c.detectionCount', 'DESC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all active competitors
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.active = :active')
            ->setParameter('active', true)
            ->orderBy('c.tier', 'ASC')
            ->addOrderBy('c.detectionCount', 'DESC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find verified competitors only
     */
    public function findVerified(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.active = :active')
            ->andWhere('c.verified = :verified')
            ->setParameter('active', true)
            ->setParameter('verified', true)
            ->orderBy('c.tier', 'ASC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find competitors with high confidence (>= threshold)
     */
    public function findHighConfidence(int $minConfidence = 70): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.active = :active')
            ->andWhere('c.confidenceScore >= :minConfidence')
            ->setParameter('active', true)
            ->setParameter('minConfidence', $minConfidence)
            ->orderBy('c.confidenceScore', 'DESC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find competitors pending verification
     */
    public function findPendingVerification(int $minDetections = 5): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.active = :active')
            ->andWhere('c.verified = :verified')
            ->andWhere('c.detectionCount >= :minDetections')
            ->setParameter('active', true)
            ->setParameter('verified', false)
            ->setParameter('minDetections', $minDetections)
            ->orderBy('c.detectionCount', 'DESC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find competitors by industry
     */
    public function findByIndustry(string $industry): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.active = :active')
            ->andWhere('c.industry = :industry')
            ->setParameter('active', true)
            ->setParameter('industry', $industry)
            ->orderBy('c.tier', 'ASC')
            ->addOrderBy('c.detectionCount', 'DESC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();
    }

    /**
     * Search competitors by name or alias
     */
    public function searchByName(string $query): array
    {
        $query = strtolower(trim($query));
        
        return $this->createQueryBuilder('c')
            ->where('c.active = :active')
            ->andWhere('LOWER(c.name) LIKE :query OR LOWER(c.domain) LIKE :query OR LOWER(c.fullName) LIKE :query')
            ->setParameter('active', true)
            ->setParameter('query', '%' . addcslashes($query, '%_') . '%')
            ->orderBy('c.tier', 'ASC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get competitor statistics
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

        $results = $qb->getQuery()->getArrayResult();

        $stats = [
            'byTier' => [],
            'byIndustry' => [],
            'total' => 0,
            'verified' => 0,
            'highConfidence' => 0,
        ];

        foreach ($results as $row) {
            $tier = $row['tier'];
            $industry = $row['industry'];

            if (!isset($stats['byTier'][$tier])) {
                $stats['byTier'][$tier] = 0;
            }
            if (!isset($stats['byIndustry'][$industry])) {
                $stats['byIndustry'][$industry] = 0;
            }

            $stats['byTier'][$tier] += (int) $row['count'];
            $stats['byIndustry'][$industry] += (int) $row['count'];
            $stats['total'] += (int) $row['count'];
            $stats['verified'] = (int) ($row['verifiedCount'] ?? 0);
            $stats['highConfidence'] = (int) ($row['highConfidenceCount'] ?? 0);
        }

        return $stats;
    }

    /**
     * Find recently discovered competitors
     */
    public function findRecentlyDiscovered(int $days = 7, int $limit = 20): array
    {
        $since = new \DateTime("-{$days} days");
        
        return $this->createQueryBuilder('c')
            ->where('c.firstDetectedAt >= :since')
            ->andWhere('c.active = :active')
            ->setParameter('since', $since)
            ->setParameter('active', true)
            ->orderBy('c.firstDetectedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find most frequently detected competitors
     */
    public function findMostFrequent(int $limit = 20): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.active = :active')
            ->setParameter('active', true)
            ->orderBy('c.detectionCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get competitors as map for quick lookup (domain => entity)
     */
    public function getCompetitorMap(): array
    {
        $competitors = $this->findAllActive();
        $map = [];
        
        foreach ($competitors as $competitor) {
            $map[$competitor->getDomain()] = $competitor;
        }
        
        return $map;
    }
}
