<?php

namespace App\Repository;

use App\Entity\FreightTable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FreightTable>
 */
class FreightTableRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FreightTable::class);
    }

    /**
     * Find applicable freight rate for a given route and date
     */
    public function findApplicableRate(
        string $originPort,
        string $destinationPort,
        string $containerType,
        ?\DateTimeInterface $date = null
    ): ?FreightTable {
        $date = $date ?? new \DateTime();

        /** @var FreightTable|null $rate */
        $rate = $this->createQueryBuilder('f')
            ->where('f.originPort = :origin')
            ->andWhere('f.destinationPort = :destination')
            ->andWhere('f.containerType = :containerType')
            ->andWhere('f.effectiveDate <= :date')
            ->andWhere('f.expiryDate IS NULL OR f.expiryDate >= :date')
            ->andWhere('f.isActive = :active')
            ->setParameter('origin', $originPort)
            ->setParameter('destination', $destinationPort)
            ->setParameter('containerType', $containerType)
            ->setParameter('date', $date)
            ->setParameter('active', true)
            ->orderBy('f.effectiveDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $rate;
    }

    /**
     * Get all rates for an origin port
     *
     * @return list<FreightTable>
     */
    public function findByOrigin(string $originPort): array
    {
        /** @var list<FreightTable> $rates */
        $rates = $this->createQueryBuilder('f')
            ->where('f.originPort = :origin')
            ->setParameter('origin', $originPort)
            ->orderBy('f.destinationPort', 'ASC')
            ->getQuery()
            ->getResult();

        return $rates;
    }
}
