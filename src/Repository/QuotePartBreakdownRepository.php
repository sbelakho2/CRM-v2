<?php

namespace App\Repository;

use App\Entity\QuotePartBreakdown;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuotePartBreakdown>
 */
class QuotePartBreakdownRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuotePartBreakdown::class);
    }

    /**
     * Find all part breakdown lines for a quote, ordered by id.
     *
     * @return array<int, QuotePartBreakdown>
     */
    public function findByQuote(int $quoteId): array
    {
        return $this->createQueryBuilder('qpb')
            ->where('qpb.quote = :quoteId')
            ->setParameter('quoteId', $quoteId)
            ->orderBy('qpb.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
