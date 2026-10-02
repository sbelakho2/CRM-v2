<?php

namespace App\Repository;

use App\Entity\Contact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contact>
 */
class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    /**
     * Find contacts with eager loading of company to prevent N+1 queries.
     * Supports filtering by role, company, and search term.
     * 
     * @return Contact[]
     */
    /**
     * @return list<Contact>
     */
    public function findWithCompanyFiltered(?string $role = null, ?int $companyId = null, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.company', 'co')->addSelect('co');

        if ($role) {
            $qb->andWhere('c.role = :role')
               ->setParameter('role', $role);
        }

        if ($companyId) {
            $qb->andWhere('co.id = :company')
               ->setParameter('company', $companyId);
        }

        if ($search) {
            $qb->andWhere('c.firstName LIKE :search OR c.lastName LIKE :search OR c.email LIKE :search')
               ->setParameter('search', '%' . addcslashes($search, '%_') . '%');
        }

        /** @var list<Contact> $result */
        $result = $qb->orderBy('c.lastName', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Find all contacts with company eager loaded via single query.
     * Used for email campaign contact selection.
     *
     * @return list<Contact>
     */
    public function findAllWithCompany(): array
    {
        /** @var list<Contact> $result */
        $result = $this->createQueryBuilder('c')
            ->leftJoin('c.company', 'co')->addSelect('co')
            ->orderBy('c.lastName', 'ASC')

            ->getQuery()
            ->getResult();

        return $result;
    }
}
