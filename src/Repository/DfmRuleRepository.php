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
     * @return list<DfmRule>
     */
    public function findActiveByType(string $ruleType): array
    {
        /** @var list<DfmRule> $result */
        $result = $this->createQueryBuilder('dr')
            ->andWhere('dr.ruleType = :type')
            ->andWhere('dr.isActive = :active')
            ->setParameter('type', $ruleType)
            ->setParameter('active', true)
            ->orderBy('dr.severity', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find all critical rules
     * 
     * @return list<DfmRule>
     */
    public function findCriticalRules(): array
    {
        /** @var list<DfmRule> $result */
        $result = $this->createQueryBuilder('dr')
            ->andWhere('dr.severity = :severity')
            ->andWhere('dr.isActive = :active')
            ->setParameter('severity', 'Critical')
            ->setParameter('active', true)
            ->orderBy('dr.ruleType', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find all active rules
     * 
     * @return list<DfmRule>
     */
    public function findAllActive(): array
    {
        /** @var list<DfmRule> $result */
        $result = $this->createQueryBuilder('dr')
            ->andWhere('dr.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('dr.ruleType', 'ASC')
            ->addOrderBy('dr.severity', 'DESC')

            ->getQuery()
            ->getResult();

        return $result;
    }
}
