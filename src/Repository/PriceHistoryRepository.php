<?php

namespace App\Repository;

use App\Entity\PriceHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PriceHistory>
 *
 * @method PriceHistory|null find($id, $lockMode = null, $lockVersion = null)
 * @method PriceHistory|null findOneBy(array $criteria, array $orderBy = null)
 * @method PriceHistory[]    findAll()
 * @method PriceHistory[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PriceHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PriceHistory::class);
    }

    /**
     * Get price history for a specific MPN
     * 
     * @param string $mpn
     * @param int $days Number of days to look back
     * @return PriceHistory[]
     */
    public function findByMpnRecent(string $mpn, int $days = 90): array
    {
        $since = new \DateTime("-{$days} days");
        
        return $this->createQueryBuilder('ph')
            ->where('ph.mpn = :mpn')
            ->andWhere('ph.recordedAt >= :since')
            ->setParameter('mpn', $mpn)
            ->setParameter('since', $since)
            ->orderBy('ph.recordedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get price trend for an MPN (average price over time)
     * 
     * @param string $mpn
     * @param string $source Optional source filter
     * @param int $days Number of days to analyze
     * @return array{date: string, avg_price: float, min_price: float, max_price: float, samples: int}[]
     */
    public function getPriceTrend(string $mpn, ?string $source = null, int $days = 90): array
    {
        $since = new \DateTime("-{$days} days");
        
        $qb = $this->createQueryBuilder('ph')
            ->select("DATE(ph.recordedAt) as date")
            ->addSelect("AVG(CAST(ph.unitPriceUsd AS DECIMAL(10,4))) as avg_price")
            ->addSelect("MIN(CAST(ph.unitPriceUsd AS DECIMAL(10,4))) as min_price")
            ->addSelect("MAX(CAST(ph.unitPriceUsd AS DECIMAL(10,4))) as max_price")
            ->addSelect("COUNT(ph.id) as samples")
            ->where('ph.mpn = :mpn')
            ->andWhere('ph.recordedAt >= :since')
            ->andWhere('ph.unitPriceUsd IS NOT NULL')
            ->setParameter('mpn', $mpn)
            ->setParameter('since', $since)
            ->groupBy('date')
            ->orderBy('date', 'ASC');
        
        if ($source) {
            $qb->andWhere('ph.source = :source')
               ->setParameter('source', $source);
        }
        
        return $qb->getQuery()->getResult();
    }

    /**
     * Get latest price for an MPN from any source
     */
    public function findLatestPrice(string $mpn, ?string $source = null): ?PriceHistory
    {
        $qb = $this->createQueryBuilder('ph')
            ->where('ph.mpn = :mpn')
            ->setParameter('mpn', $mpn)
            ->orderBy('ph.recordedAt', 'DESC')
            ->setMaxResults(1);
        
        if ($source) {
            $qb->andWhere('ph.source = :source')
               ->setParameter('source', $source);
        }
        
        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Get lowest historical price for an MPN
     */
    public function findLowestPrice(string $mpn, int $days = 365): ?PriceHistory
    {
        $since = new \DateTime("-{$days} days");
        
        return $this->createQueryBuilder('ph')
            ->where('ph.mpn = :mpn')
            ->andWhere('ph.recordedAt >= :since')
            ->andWhere('ph.unitPriceUsd IS NOT NULL')
            ->setParameter('mpn', $mpn)
            ->setParameter('since', $since)
            ->orderBy('ph.unitPriceUsd', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Detect price volatility (std deviation / mean)
     */
    public function getPriceVolatility(string $mpn, int $days = 90): ?float
    {
        $since = new \DateTime("-{$days} days");
        
        $result = $this->createQueryBuilder('ph')
            ->select("AVG(CAST(ph.unitPriceUsd AS DECIMAL(10,4))) as avg_price")
            ->addSelect("STDDEV(CAST(ph.unitPriceUsd AS DECIMAL(10,4))) as std_dev")
            ->where('ph.mpn = :mpn')
            ->andWhere('ph.recordedAt >= :since')
            ->andWhere('ph.unitPriceUsd IS NOT NULL')
            ->setParameter('mpn', $mpn)
            ->setParameter('since', $since)
            ->getQuery()
            ->getOneOrNullResult();
        
        if (!$result || !$result['avg_price'] || $result['avg_price'] == 0) {
            return null;
        }
        
        return $result['std_dev'] / $result['avg_price']; // Coefficient of variation
    }

    /**
     * Get price comparison across sources for an MPN
     */
    public function getSourceComparison(string $mpn): array
    {
        return $this->createQueryBuilder('ph')
            ->select('ph.source')
            ->addSelect("MIN(CAST(ph.unitPriceUsd AS DECIMAL(10,4))) as lowest_price")
            ->addSelect("MAX(ph.stockAvailable) as max_stock")
            ->addSelect("MAX(ph.recordedAt) as last_seen")
            ->where('ph.mpn = :mpn')
            ->setParameter('mpn', $mpn)
            ->groupBy('ph.source')
            ->getQuery()
            ->getResult();
    }

    /**
     * Clean up old history (retention policy)
     */
    public function cleanupOldRecords(int $retentionDays = 365): int
    {
        $cutoff = new \DateTime("-{$retentionDays} days");
        
        return $this->createQueryBuilder('ph')
            ->delete()
            ->where('ph.recordedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
