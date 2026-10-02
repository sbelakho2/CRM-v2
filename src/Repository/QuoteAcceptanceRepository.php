<?php

namespace App\Repository;

use App\Entity\QuoteAcceptance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<QuoteAcceptance>
 */
class QuoteAcceptanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuoteAcceptance::class);
    }

    public function findLatestForQuote(\App\Entity\Quote $quote): ?QuoteAcceptance
    {
        /** @var QuoteAcceptance|null $result */
        $result = $this->createQueryBuilder('a')
            ->andWhere('a.quote = :quote')
            ->setParameter('quote', $quote)
            ->orderBy('a.acceptedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }
}
