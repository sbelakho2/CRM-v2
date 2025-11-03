<?php

namespace App\Repository;

use App\Entity\EmailUnsubscribe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailUnsubscribe>
 */
class EmailUnsubscribeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailUnsubscribe::class);
    }

    /**
     * Check if email is unsubscribed
     */
    public function isUnsubscribed(string $email): bool
    {
        $count = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Find unsubscribe record by email
     */
    public function findByEmail(string $email): ?EmailUnsubscribe
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.email = :email')
            ->setParameter('email', $email)
            ->orderBy('e.unsubscribedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get unsubscribe statistics by reason
     */
    public function getUnsubscribeStatsByReason(): array
    {
        return $this->createQueryBuilder('e')
            ->select('e.reason, COUNT(e.id) as count')
            ->groupBy('e.reason')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get recent unsubscribes
     *
     * @return EmailUnsubscribe[]
     */
    public function findRecent(int $days = 30, int $limit = 50): array
    {
        $since = new \DateTime("-{$days} days");

        return $this->createQueryBuilder('e')
            ->andWhere('e.unsubscribedAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('e.unsubscribedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
