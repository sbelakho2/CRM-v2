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

        return $this->createQueryBuilder('f')
            ->where('f.originPort = :origin')
            ->andWhere('f.destinationPort = :destination')
            ->andWhere('f.containerType = :containerType')
            ->andWhere('f.effectiveDate <= :date')
            ->andWhere('f.expiryDate IS NULL OR f.expiryDate >= :date')
            ->setParameter('origin', $originPort)
            ->setParameter('destination', $destinationPort)
            ->setParameter('containerType', $containerType)
            ->setParameter('date', $date)
            ->orderBy('f.effectiveDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get all rates for an origin port
     */
    public function findByOrigin(string $originPort): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.originPort = :origin')
            ->setParameter('origin', $originPort)
            ->orderBy('f.destinationPort', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
