<?php

namespace App\Repository;

use App\Entity\TariffRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TariffRate>
 */
class TariffRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TariffRate::class);
    }

    /**
     * Find applicable tariff rate for a given route and date
     */
    public function findApplicableRate(
        string $hsCode,
        string $originCountry,
        string $destinationCountry,
        ?\DateTimeInterface $date = null
    ): ?TariffRate {
        $date = $date ?? new \DateTime();

        return $this->createQueryBuilder('t')
            ->where('t.hsCode = :hsCode')
            ->andWhere('t.originCountry = :origin')
            ->andWhere('t.destinationCountry = :destination')
            ->andWhere('t.effectiveDate <= :date')
            ->andWhere('t.expiryDate IS NULL OR t.expiryDate >= :date')
            ->setParameter('hsCode', $hsCode)
            ->setParameter('origin', $originCountry)
            ->setParameter('destination', $destinationCountry)
            ->setParameter('date', $date)
            ->orderBy('t.effectiveDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get all rates for a destination country
     */
    public function findByDestination(string $destinationCountry): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.destinationCountry = :destination')
            ->setParameter('destination', $destinationCountry)
            ->orderBy('t.hsCode', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
