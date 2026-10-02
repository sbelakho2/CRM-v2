<?php

namespace App\Repository;

use App\Entity\NreTable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NreTable>
 */
class NreTableRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NreTable::class);
    }

    /**
     * Find active NRE fee by service type
     */
    public function findActiveByServiceType(string $serviceType): ?NreTable
    {
        /** @var NreTable|null $result */
        $result = $this->createQueryBuilder('nt')
            ->andWhere('nt.serviceType = :type')
            ->andWhere('nt.isActive = :active')
            ->setParameter('type', $serviceType)
            ->setParameter('active', true)
            ->orderBy('nt.asof', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Find all active NRE fees
     * 
     * @return list<NreTable>
     */
    public function findAllActive(): array
    {
        /** @var list<NreTable> $result */
        $result = $this->createQueryBuilder('nt')
            ->andWhere('nt.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('nt.serviceType', 'ASC')

            ->getQuery()
            ->getResult();

        return $result;
    }
}
