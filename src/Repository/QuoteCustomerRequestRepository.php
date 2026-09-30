<?php

namespace App\Repository;

use App\Entity\QuoteCustomerRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuoteCustomerRequest>
 */
class QuoteCustomerRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuoteCustomerRequest::class);
    }

    /**
     * Open (unhandled) requests for a quote, newest first — the sales
     * follow-up queue.
     *
     * @return list<QuoteCustomerRequest>
     */
    public function findOpenByQuote(\App\Entity\Quote $quote): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.quote = :quote')
            ->andWhere('r.status IN (:open)')
            ->setParameter('quote', $quote)
            ->setParameter('open', [QuoteCustomerRequest::STATUS_NEW, QuoteCustomerRequest::STATUS_IN_REVIEW])
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count a token's requests since $since — the public rate limiter's
     * per-token bucket (the token fingerprint is stored, never the token).
     */
    public function countRecentByFingerprint(string $tokenFingerprint, \DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.tokenFingerprint = :fp')
            ->andWhere('r.createdAt >= :since')
            ->setParameter('fp', $tokenFingerprint)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count an IP's requests since $since — the per-IP abuse bucket.
     */
    public function countRecentByIp(string $requestIp, \DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.requestIp = :ip')
            ->andWhere('r.createdAt >= :since')
            ->setParameter('ip', $requestIp)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
