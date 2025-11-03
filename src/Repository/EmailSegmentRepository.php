<?php

namespace App\Repository;

use App\Entity\EmailSegment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailSegment>
 */
class EmailSegmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailSegment::class);
    }

    /**
     * Find active segments
     *
     * @return EmailSegment[]
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('e.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find segments by minimum contact count
     *
     * @return EmailSegment[]
     */
    public function findByMinContactCount(int $minCount): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.contactCount >= :minCount')
            ->andWhere('e.isActive = :active')
            ->setParameter('minCount', $minCount)
            ->setParameter('active', true)
            ->orderBy('e.contactCount', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
