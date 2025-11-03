<?php

namespace App\Repository;

use App\Entity\RoutePreference;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoutePreference>
 */
class RoutePreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoutePreference::class);
    }

    /**
     * Find ranked routes for a destination country
     * 
     * @param string $destinationCountry
     * @return RoutePreference[]
     */
    public function findRankedRoutes(string $destinationCountry): array
    {
        return $this->createQueryBuilder('rp')
            ->andWhere('rp.destinationCountry = :country')
            ->andWhere('rp.isActive = :active')
            ->setParameter('country', $destinationCountry)
            ->setParameter('active', true)
            ->orderBy('rp.rank', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all active routes
     * 
     * @return RoutePreference[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('rp')
            ->andWhere('rp.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('rp.destinationCountry', 'ASC')
            ->addOrderBy('rp.rank', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
