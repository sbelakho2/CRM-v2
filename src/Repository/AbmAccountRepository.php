<?php

namespace App\Repository;

use App\Entity\AbmAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AbmAccount>
 */
class AbmAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AbmAccount::class);
    }

    /**
     * Find account by company domain
     */
    public function findByDomain(string $domain): ?AbmAccount
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.domain = :domain')
            ->setParameter('domain', $domain)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find accounts by ICP tier
     *
     * @return AbmAccount[]
     */
    public function findByIcpTier(string $tier): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.icpTier = :tier')
            ->setParameter('tier', $tier)
            ->orderBy('a.lastActivityAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find engaged accounts (with recent activity)
     *
     * @return AbmAccount[]
     */
    public function findEngagedAccounts(int $daysBack = 30): array
    {
        $since = new \DateTime("-{$daysBack} days");

        return $this->createQueryBuilder('a')
            ->andWhere('a.lastActivityAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('a.engagementScore', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get top accounts by engagement score
     *
     * @return AbmAccount[]
     */
    public function getTopEngagedAccounts(int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.engagementScore', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Search accounts by name or domain
     *
     * @return AbmAccount[]
     */
    public function searchByNameOrDomain(string $query): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.accountName LIKE :query OR a.domain LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('a.engagementScore', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();
    }
}
