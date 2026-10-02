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
     * @return list<PortalCandidate>
     */
    public function findByCompany(Company $company): array
    {
        /** @var list<PortalCandidate> $result */
        $result = $this->createQueryBuilder('pc')
            ->andWhere('pc.company = :company')
            ->setParameter('company', $company)
            ->orderBy('pc.discoveredAt', 'DESC')

            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find candidates by status
     * 
     * @return list<PortalCandidate>
     */
    public function findByStatus(string $status): array
    {
        /** @var list<PortalCandidate> $result */
        $result = $this->createQueryBuilder('pc')
            ->andWhere('pc.status = :status')
            ->setParameter('status', $status)
            ->orderBy('pc.discoveredAt', 'DESC')

            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find pending approval candidates
     * 
     * @return list<PortalCandidate>
     */
    public function findPendingApproval(): array
    {
        /** @var list<PortalCandidate> $result */
        $result = $this->createQueryBuilder('pc')
            ->andWhere('pc.status = :status')
            ->setParameter('status', 'discovered')
            ->orderBy('pc.discoveredAt', 'ASC')

            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find active portals
     * 
     * @return list<PortalCandidate>
     */
    public function findActive(): array
    {
        /** @var list<PortalCandidate> $result */
        $result = $this->createQueryBuilder('pc')
            ->andWhere('pc.status = :status')
            ->setParameter('status', 'active')
            ->orderBy('pc.company', 'ASC')

            ->getQuery()
            ->getResult();

        return $result;
    }
}
