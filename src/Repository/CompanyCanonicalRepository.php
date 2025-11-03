<?php

namespace App\Repository;

use App\Entity\CompanyCanonical;
use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompanyCanonical>
 */
class CompanyCanonicalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompanyCanonical::class);
    }

    /**
     * Find by domain
     */
    public function findByDomain(string $domain): ?CompanyCanonical
    {
        return $this->createQueryBuilder('cc')
            ->andWhere('cc.domain = :domain')
            ->setParameter('domain', $domain)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find by company
     * 
     * @return CompanyCanonical[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('cc')
            ->andWhere('cc.company = :company')
            ->setParameter('company', $company)
            ->orderBy('cc.isPrimary', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find primary domain for company
     */
    public function findPrimaryByCompany(Company $company): ?CompanyCanonical
    {
        return $this->createQueryBuilder('cc')
            ->andWhere('cc.company = :company')
            ->andWhere('cc.isPrimary = :primary')
            ->setParameter('company', $company)
            ->setParameter('primary', true)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
