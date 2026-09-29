<?php

namespace App\Repository;

use App\Entity\VerifiedCapability;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VerifiedCapability>
 */
class VerifiedCapabilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VerifiedCapability::class);
    }

    /**
     * Claimable (verified, currently valid) capabilities keyed by
     * capability_key — the single source for outbound capability claims.
     *
     * @return array<string, string> key => label
     */
    public function findClaimable(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->andWhere('c.status = :verified')
            ->setParameter('verified', VerifiedCapability::STATUS_VERIFIED)
            ->getQuery()
            ->getResult();

        $claimable = [];
        foreach ($rows as $row) {
            if ($row->isClaimable()) {
                $claimable[$row->getCapabilityKey()] = $row->getLabel() ?? $row->getCapabilityKey();
            }
        }

        return $claimable;
    }
}
