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
        /** @var CompanyCanonical|null $result */
        $result = $this->createQueryBuilder('cc')
            ->andWhere('cc.domain = :domain')
            ->setParameter('domain', $domain)
            ->orderBy('cc.isPrimary', 'DESC')
            ->addOrderBy('cc.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }

    /**
     * Find by company
     * 
     * @return list<CompanyCanonical>
     */
    public function findByCompany(Company $company): array
    {
        /** @var list<CompanyCanonical> $result */
        $result = $this->createQueryBuilder('cc')
            ->andWhere('cc.company = :company')
            ->setParameter('company', $company)
            ->orderBy('cc.isPrimary', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find primary domain for company
     */
    public function findPrimaryByCompany(Company $company): ?CompanyCanonical
    {
        /** @var CompanyCanonical|null $result */
        $result = $this->createQueryBuilder('cc')
            ->andWhere('cc.company = :company')
            ->andWhere('cc.isPrimary = :primary')
            ->setParameter('company', $company)
            ->setParameter('primary', true)
            ->orderBy('cc.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }
}
