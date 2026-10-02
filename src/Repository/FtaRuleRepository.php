<?php

namespace App\Repository;

use App\Entity\FtaRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FtaRule>
 */
class FtaRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FtaRule::class);
    }

    /**
     * Find FTA rule for a given HS code and agreement
     */
    public function findApplicableRule(
        string $hsCode,
        string $ftaAgreement,
        ?\DateTimeInterface $date = null
    ): ?FtaRule {
        $date = $date ?? new \DateTime();

        /** @var FtaRule|null $result */
        $result = $this->createQueryBuilder('f')
            ->where('f.hsCode = :hsCode')
            ->andWhere('f.ftaAgreement = :agreement')
            ->andWhere('f.effectiveDate <= :date')
            ->andWhere('f.expiryDate IS NULL OR f.expiryDate >= :date')
            ->setParameter('hsCode', $hsCode)
            ->setParameter('agreement', $ftaAgreement)
            ->setParameter('date', $date)
            ->orderBy('f.effectiveDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Get all rules for an FTA agreement
     *
     * @return list<FtaRule>
     */
    public function findByAgreement(string $ftaAgreement): array
    {
        /** @var list<FtaRule> $result */
        $result = $this->createQueryBuilder('f')
            ->where('f.ftaAgreement = :agreement')
            ->setParameter('agreement', $ftaAgreement)
            ->orderBy('f.hsCode', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
