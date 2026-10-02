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
     * @return list<ProcurementException>
     */
    public function findByBomLine(int $bomLineId): array
    {
        /** @var list<ProcurementException> $result */
        $result = $this->createQueryBuilder('p')
            ->andWhere('p.bomLine = :bomLineId')
            ->setParameter('bomLineId', $bomLineId)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find exceptions by Quote
     *
     * @return list<ProcurementException>
     */
    public function findByQuote(int $quoteId): array
    {
        /** @var list<ProcurementException> $result */
        $result = $this->createQueryBuilder('p')
            ->join('p.bomLine', 'b')
            ->andWhere('b.quote = :quoteId')
            ->setParameter('quoteId', $quoteId)
            ->orderBy('p.severity', 'ASC')
            ->addOrderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Get exception count by severity for a quote
     *
     * @return list<array{severity: mixed, count: int|string}>
     */
    public function getExceptionCountsBySeverity(int $quoteId): array
    {
        /** @var list<array{severity: mixed, count: int|string}> $result */
        $result = $this->createQueryBuilder('p')
            ->select('p.severity, COUNT(p.id) as count')
            ->join('p.bomLine', 'b')
            ->andWhere('b.quote = :quoteId')
            ->setParameter('quoteId', $quoteId)
            ->groupBy('p.severity')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
