<?php

namespace App\Repository;

use App\Entity\OnboardingPack;
use App\Entity\Company;
use App\Entity\PortalCandidate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OnboardingPack>
 */
class OnboardingPackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OnboardingPack::class);
    }

    /**
     * Find packs by company
     * 
     * @return list<OnboardingPack>
     */
    public function findByCompany(Company $company): array
    {
        /** @var list<OnboardingPack> $results */
        $results = $this->createQueryBuilder('op')
            ->andWhere('op.company = :company')
            ->setParameter('company', $company)
            ->orderBy('op.createdAt', 'DESC')

            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Find packs by portal candidate
     * 
     * @return list<OnboardingPack>
     */
    public function findByPortalCandidate(PortalCandidate $portalCandidate): array
    {
        /** @var list<OnboardingPack> $results */
        $results = $this->createQueryBuilder('op')
            ->andWhere('op.portalCandidate = :portal')
            ->setParameter('portal', $portalCandidate)
            ->orderBy('op.createdAt', 'DESC')

            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Find packs by status
     * 
     * @return list<OnboardingPack>
     */
    public function findByStatus(string $status): array
    {
        /** @var list<OnboardingPack> $results */
        $results = $this->createQueryBuilder('op')
            ->andWhere('op.status = :status')
            ->setParameter('status', $status)
            ->orderBy('op.createdAt', 'DESC')

            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Find ready packs for submission
     * 
     * @return list<OnboardingPack>
     */
    public function findReady(): array
    {
        /** @var list<OnboardingPack> $results */
        $results = $this->createQueryBuilder('op')
            ->andWhere('op.status = :status')
            ->setParameter('status', 'ready')
            ->orderBy('op.createdAt', 'ASC')

            ->getQuery()
            ->getResult();

        return $results;
    }
}
