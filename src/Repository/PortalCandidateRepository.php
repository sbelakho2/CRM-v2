<?php

namespace App\Repository;

use App\Entity\PortalCandidate;
use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PortalCandidate>
 */
class PortalCandidateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PortalCandidate::class);
    }

    /**
     * Find candidates by company
     * 
     * @return PortalCandidate[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('pc')
            ->andWhere('pc.company = :company')
            ->setParameter('company', $company)
            ->orderBy('pc.discoveredAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find candidates by status
     * 
     * @return PortalCandidate[]
     */
    public function findByStatus(string $status): array
    {
        return $this->createQueryBuilder('pc')
            ->andWhere('pc.status = :status')
            ->setParameter('status', $status)
            ->orderBy('pc.discoveredAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find pending approval candidates
     * 
     * @return PortalCandidate[]
     */
    public function findPendingApproval(): array
    {
        return $this->createQueryBuilder('pc')
            ->andWhere('pc.status = :status')
            ->setParameter('status', 'discovered')
            ->orderBy('pc.discoveredAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active portals
     * 
     * @return PortalCandidate[]
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('pc')
            ->andWhere('pc.status = :status')
            ->setParameter('status', 'active')
            ->orderBy('pc.company', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
