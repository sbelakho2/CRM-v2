<?php

namespace App\Repository;

use App\Entity\HtsMapRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HtsMapRule>
 */
class HtsMapRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HtsMapRule::class);
    }

    /**
     * Find HTS mapping rule by category
     */
    public function findByCategory(string $category): ?HtsMapRule
    {
        return $this->createQueryBuilder('hmr')
            ->andWhere('hmr.category = :category')
            ->andWhere('hmr.isActive = :active')
            ->setParameter('category', $category)
            ->setParameter('active', true)
            ->orderBy('hmr.priority', 'ASC')
            ->addOrderBy('hmr.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all active mapping rules ordered by priority
     * 
     * @return HtsMapRule[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('hmr')
            ->andWhere('hmr.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('hmr.priority', 'ASC')

            ->getQuery()
            ->getResult();
    }
}
