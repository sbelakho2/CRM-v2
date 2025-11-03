<?php

namespace App\Repository;

use App\Entity\PcbCurve;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PcbCurve>
 */
class PcbCurveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PcbCurve::class);
    }

    /**
     * Find active cost for layers and area
     */
    public function findActiveCost(int $layers, float $areaM2): ?PcbCurve
    {
        return $this->createQueryBuilder('pc')
            ->andWhere('pc.layers = :layers')
            ->andWhere('pc.areaM2 <= :area')
            ->andWhere('pc.isActive = :active')
            ->setParameter('layers', $layers)
            ->setParameter('area', $areaM2)
            ->setParameter('active', true)
            ->orderBy('pc.areaM2', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all active curves
     * 
     * @return PcbCurve[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('pc')
            ->andWhere('pc.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('pc.layers', 'ASC')
            ->addOrderBy('pc.areaM2', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
