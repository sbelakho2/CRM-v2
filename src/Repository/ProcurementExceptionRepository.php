<?php

namespace App\Repository;

use App\Entity\ProcurementException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProcurementException>
 */
class ProcurementExceptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcurementException::class);
    }

    /**
     * Find exceptions by BOM line
     *
     * @return ProcurementException[]
     */
    public function findByBomLine(int $bomLineId): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.bomLine = :bomLineId')
            ->setParameter('bomLineId', $bomLineId)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find exceptions by Quote
     *
     * @return ProcurementException[]
     */
    public function findByQuote(int $quoteId): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.bomLine', 'b')
            ->andWhere('b.quote = :quoteId')
            ->setParameter('quoteId', $quoteId)
            ->orderBy('p.severity', 'ASC')
            ->addOrderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get exception count by severity for a quote
     */
    public function getExceptionCountsBySeverity(int $quoteId): array
    {
        return $this->createQueryBuilder('p')
            ->select('p.severity, COUNT(p.id) as count')
            ->join('p.bomLine', 'b')
            ->andWhere('b.quote = :quoteId')
            ->setParameter('quoteId', $quoteId)
            ->groupBy('p.severity')
            ->getQuery()
            ->getResult();
    }
}
