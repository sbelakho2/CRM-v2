<?php

namespace App\Repository;

use App\Entity\DfmFinding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DfmFinding>
 */
class DfmFindingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DfmFinding::class);
    }

    /**
     * Find findings by Quote
     *
     * @return DfmFinding[]
     */
    public function findByQuote(int $quoteId): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.quote = :quoteId')
            ->setParameter('quoteId', $quoteId)
            ->orderBy('d.severity', 'ASC')
            ->addOrderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find findings by severity
     *
     * @return DfmFinding[]
     */
    public function findBySeverity(int $quoteId, string $severity): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.quote = :quoteId')
            ->andWhere('d.severity = :severity')
            ->setParameter('quoteId', $quoteId)
            ->setParameter('severity', $severity)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get finding counts by severity
     */
    public function getCountsBySeverity(int $quoteId): array
    {
        return $this->createQueryBuilder('d')
            ->select('d.severity, COUNT(d.id) as count')
            ->andWhere('d.quote = :quoteId')
            ->setParameter('quoteId', $quoteId)
            ->groupBy('d.severity')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if quote has critical findings
     */
    public function hasCriticalFindings(int $quoteId): bool
    {
        $count = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.quote = :quoteId')
            ->andWhere('d.severity = :severity')
            ->setParameter('quoteId', $quoteId)
            ->setParameter('severity', 'CRITICAL')
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
