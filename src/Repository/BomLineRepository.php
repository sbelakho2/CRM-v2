<?php

namespace App\Repository;

use App\Entity\BomLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BomLine>
 */
class BomLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BomLine::class);
    }

    /**
     * Find BOM lines by Quote ID
     *
     * @return BomLine[]
     */
    public function findByQuote(int $quoteId): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.quote = :quoteId')
            ->setParameter('quoteId', $quoteId)
            ->orderBy('b.lineNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find lines with procurement exceptions
     *
     * @return BomLine[]
     */
    public function findWithExceptions(int $quoteId): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.quote = :quoteId')
            ->andWhere('b.hasException = :hasException')
            ->setParameter('quoteId', $quoteId)
            ->setParameter('hasException', true)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get total lines for a quote
     */
    public function countByQuote(int $quoteId): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->andWhere('b.quote = :quoteId')
            ->setParameter('quoteId', $quoteId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get coverage statistics for a quote
     */
    public function getCoverageStats(int $quoteId): array
    {
        $qb = $this->createQueryBuilder('b')
            ->select('
                COUNT(b.id) as totalLines,
                SUM(CASE WHEN b.unitPrice IS NOT NULL AND b.unitPrice > 0 THEN 1 ELSE 0 END) as pricedLines,
                SUM(CASE WHEN b.hasException = true THEN 1 ELSE 0 END) as exceptionLines
            ')
            ->andWhere('b.quote = :quoteId')
            ->setParameter('quoteId', $quoteId);

        return $qb->getQuery()->getSingleResult();
    }
}
