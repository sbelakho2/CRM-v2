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
     * Find ranked routes for a destination country
     *
     * @param string $destinationCountry
     * @return list<\App\Entity\RoutePreference>
     */
    public function findRankedRoutes(string $destinationCountry): array
    {
        /** @var list<\App\Entity\RoutePreference> $result */
        $result = $this->createQueryBuilder('rp')
            ->andWhere('rp.destinationCountry = :country')
            ->andWhere('rp.isActive = :active')
            ->setParameter('country', $destinationCountry)
            ->setParameter('active', true)
            ->orderBy('rp.rank', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find all active routes
     *
     * @return list<\App\Entity\RoutePreference>
     */
    public function findAllActive(): array
    {
        /** @var list<\App\Entity\RoutePreference> $result */
        $result = $this->createQueryBuilder('rp')
            ->andWhere('rp.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('rp.destinationCountry', 'ASC')
            ->addOrderBy('rp.rank', 'ASC')

            ->getQuery()
            ->getResult();

        return $result;
    }
}
