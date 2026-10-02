<?php

namespace App\Repository;

use App\Entity\FxRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FxRate>
 */
class FxRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FxRate::class);
    }

    /**
     * Find latest active rate for currency pair
     */
    public function findActiveRate(string $fromCurrency, string $toCurrency): ?FxRate
    {
        /** @var FxRate|null $result */
        $result = $this->createQueryBuilder('f')
            ->where('f.fromCurrency = :from')
            ->andWhere('f.toCurrency = :to')
            ->andWhere('f.isActive = :active')
            ->setParameter('from', $fromCurrency)
            ->setParameter('to', $toCurrency)
            ->setParameter('active', true)
            ->orderBy('f.asof', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }
}
