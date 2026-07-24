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
     * @internal Uses raw SQL for performance. Callers should not depend on this approach.
     */
    public function getPriceTrend(string $mpn, ?string $source = null, int $days = 90): array
    {
        $since = new \DateTime("-{$days} days");
        $conn = $this->getEntityManager()->getConnection();
        
        $sql = 'SELECT DATE(ph.recorded_at) AS date,
                       AVG(CAST(ph.unit_price_usd AS DECIMAL(10,4))) AS avg_price,
                       MIN(CAST(ph.unit_price_usd AS DECIMAL(10,4))) AS min_price,
                       MAX(CAST(ph.unit_price_usd AS DECIMAL(10,4))) AS max_price,
                       COUNT(ph.id) AS samples
                FROM price_history ph
                WHERE ph.mpn = :mpn
                AND ph.recorded_at >= :since
                AND ph.unit_price_usd IS NOT NULL';
        
        $params = [
            'mpn' => $mpn,
            'since' => $since->format('Y-m-d H:i:s'),
        ];
        
        if ($source) {
            $sql .= ' AND ph.source = :source';
            $params['source'] = $source;
        }
        
        $sql .= ' GROUP BY DATE(ph.recorded_at) ORDER BY date ASC';
        
        return $conn->fetchAllAssociative($sql, $params);
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

        $prices = $this->createQueryBuilder('ph')
            ->select('CAST(ph.unitPriceUsd AS DECIMAL(10,4)) as price')
            ->where('ph.mpn = :mpn')
            ->andWhere('ph.recordedAt >= :since')
            ->andWhere('ph.unitPriceUsd IS NOT NULL')
            ->setParameter('mpn', $mpn)
            ->setParameter('since', $since)
            ->getQuery()
            ->getScalarResult();

        $values = array_column($prices, 'price');
        $count = count($values);

        if ($count < 2) {
            return null;
        }

        $mean = array_sum($values) / $count;
        if ($mean == 0) {
            return null;
        }

        $variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values)) / $count;
        $stdDev = sqrt($variance);

        return $stdDev / $mean;
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
