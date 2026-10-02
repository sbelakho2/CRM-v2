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
        /** @var EmailUnsubscribe|null $result */
        $result = $this->createQueryBuilder('e')
            ->andWhere('e.email = :email')
            ->setParameter('email', $email)
            ->orderBy('e.unsubscribedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Get unsubscribe statistics by reason
     *
     * @return list<array{reason: mixed, count: int|string}>
     */
    public function getUnsubscribeStatsByReason(): array
    {
        /** @var list<array{reason: mixed, count: int|string}> $result */
        $result = $this->createQueryBuilder('e')
            ->select('e.reason, COUNT(e.id) as count')
            ->groupBy('e.reason')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Get recent unsubscribes
     *
     * @return list<EmailUnsubscribe>
     */
    public function findRecent(int $days = 30, int $limit = 50): array
    {
        $since = new \DateTime("-{$days} days");

        /** @var list<EmailUnsubscribe> $result */
        $result = $this->createQueryBuilder('e')
            ->andWhere('e.unsubscribedAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('e.unsubscribedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
