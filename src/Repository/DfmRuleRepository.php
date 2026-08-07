<?php

namespace App\Repository;

use App\Entity\DfmRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DfmRule>
 */
class DfmRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DfmRule::class);
    }

    /**
     * Find active rules by type
     * 
     * @return DfmRule[]
     */
    public function findActiveByType(string $ruleType): array
    {
        return $this->createQueryBuilder('dr')
            ->andWhere('dr.ruleType = :type')
            ->andWhere('dr.isActive = :active')
            ->setParameter('type', $ruleType)
            ->setParameter('active', true)
            ->orderBy('dr.severity', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all critical rules
     * 
     * @return DfmRule[]
     */
    public function findCriticalRules(): array
    {
        return $this->createQueryBuilder('dr')
            ->andWhere('dr.severity = :severity')
            ->andWhere('dr.isActive = :active')
            ->setParameter('severity', 'Critical')
            ->setParameter('active', true)
            ->orderBy('dr.ruleType', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all active rules
     * 
     * @return DfmRule[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('dr')
            ->andWhere('dr.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('dr.ruleType', 'ASC')
            ->addOrderBy('dr.severity', 'DESC')

            ->getQuery()
            ->getResult();
    }
}
