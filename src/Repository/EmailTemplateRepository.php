<?php

namespace App\Repository;

use App\Entity\EmailTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailTemplate>
 */
class EmailTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailTemplate::class);
    }

    /**
     * Find active templates
     *
     * @return list<EmailTemplate>
     */
    public function findActive(): array
    {
        /** @var list<EmailTemplate> $results */
        $results = $this->createQueryBuilder('e')
            ->andWhere('e.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('e.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Find templates by category
     *
     * @return list<EmailTemplate>
     */
    public function findByCategory(string $category): array
    {
        /** @var list<EmailTemplate> $results */
        $results = $this->createQueryBuilder('e')
            ->andWhere('e.category = :category')
            ->andWhere('e.isActive = :active')
            ->setParameter('category', $category)
            ->setParameter('active', true)
            ->orderBy('e.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Search templates by name or description
     *
     * @return list<EmailTemplate>
     */
    public function searchByName(string $query): array
    {
        /** @var list<EmailTemplate> $results */
        $results = $this->createQueryBuilder('e')
            ->andWhere('e.name LIKE :query OR e.description LIKE :query')
            ->setParameter('query', '%' . addcslashes($query, '%_') . '%')
            ->orderBy('e.name', 'ASC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        return $results;
    }
}
