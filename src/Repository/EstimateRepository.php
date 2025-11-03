<?php

namespace App\Repository;

use App\Entity\Estimate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EstimateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Estimate::class);
    }

    /**
     * Find all estimates for a company
     */
    public function findByCompany(int $companyId): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.company = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find FTA-qualified estimates
     */
    public function findFtaQualified(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.ftaQualified = :qualified')
            ->setParameter('qualified', true)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get estimates by destination country
     */
    public function findByDestination(string $destinationCountry): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.destinationCountry = :destination')
            ->setParameter('destination', $destinationCountry)
            ->orderBy('e.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
