<?php

namespace App\Repository;

use App\Entity\CooSupplierDecl;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CooSupplierDecl>
 */
class CooSupplierDeclRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CooSupplierDecl::class);
    }

    /**
     * Find COO declaration by supplier and MPN
     */
    public function findBySupplierAndMpn(string $supplierName, string $mpn): ?CooSupplierDecl
    {
        return $this->createQueryBuilder('csd')
            ->andWhere('csd.supplierName = :supplier')
            ->andWhere('csd.mpn = :mpn')
            ->andWhere('csd.expiresAt IS NULL OR csd.expiresAt > :now')
            ->setParameter('supplier', $supplierName)
            ->setParameter('mpn', $mpn)
            ->setParameter('now', new \DateTime())
            ->orderBy('csd.declaredAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all verified declarations
     * 
     * @return CooSupplierDecl[]
     */
    public function findVerified(): array
    {
        return $this->createQueryBuilder('csd')
            ->andWhere('csd.isVerified = :verified')
            ->andWhere('csd.expiresAt IS NULL OR csd.expiresAt > :now')
            ->setParameter('verified', true)
            ->setParameter('now', new \DateTime())
            ->orderBy('csd.supplierName', 'ASC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();
    }
}
