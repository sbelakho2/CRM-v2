<?php

namespace App\Repository;

use App\Entity\AsmCurve;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AsmCurve>
 */
class AsmCurveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AsmCurve::class);
    }

    /**
     * Find active cost for component count
     */
    public function findActiveCost(int $componentCount): ?AsmCurve
    {
        return $this->createQueryBuilder('ac')
            ->andWhere('ac.componentCountMin <= :count')
            ->andWhere('ac.componentCountMax IS NULL OR ac.componentCountMax >= :count')
            ->andWhere('ac.isActive = :active')
            ->setParameter('count', $componentCount)
            ->setParameter('active', true)
            ->orderBy('ac.componentCountMin', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all active curves
     * 
     * @return AsmCurve[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('ac')
            ->andWhere('ac.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('ac.componentCountMin', 'ASC')

            ->getQuery()
            ->getResult();
    }
}
