<?php

namespace App\Repository;

use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Company>
 */
class CompanyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Company::class);
    }

    public function countByStageAndPeriod(string $stage, \DateTimeInterface $startDate, \DateTimeInterface $endDate): int
    {
        return $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.pipelineStage = :stage')
            ->andWhere('c.createdAt >= :start')
            ->andWhere('c.createdAt <= :end')
            ->setParameter('stage', $stage)
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findBySectorAndTier(string $sector, string $tier)
    {
        return $this->createQueryBuilder('c')
            ->where('c.sector = :sector')
            ->andWhere('c.accountTier = :tier')
            ->setParameter('sector', $sector)
            ->setParameter('tier', $tier)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countBySector(string $sector): int
    {
        return $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.sector = :sector')
            ->setParameter('sector', $sector)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByPipelineStage(string $stage): int
    {
        return $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.pipelineStage = :stage')
            ->setParameter('stage', $stage)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
