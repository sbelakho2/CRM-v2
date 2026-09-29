<?php

namespace App\Repository;

use App\Entity\VerifiedCertification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VerifiedCertification>
 */
class VerifiedCertificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VerifiedCertification::class);
    }

    /**
     * Currently-valid certification standards — the single source for
     * outbound certification claims.
     *
     * @return list<string>
     */
    public function findClaimableStandards(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->andWhere('c.status = :verified')
            ->setParameter('verified', 'verified')
            ->getQuery()
            ->getResult();

        $standards = [];
        foreach ($rows as $row) {
            if ($row->isClaimable()) {
                $standards[] = $row->getStandard();
            }
        }

        return array_values(array_unique($standards));
    }
}
