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
            ->andWhere('r.status = :status')
            ->setParameter('quote', $quote)
            ->setParameter('status', QuoteCustomerRequest::STATUS_NEW)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
