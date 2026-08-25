<?php

namespace App\Repository;

use App\Entity\Playbook;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Playbook>
 */
class PlaybookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Playbook::class);
    }

    /**
     * Find all active playbooks ordered by priority
     * 
     * @return Playbook[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('p.priority', 'ASC')

            ->getQuery()
            ->getResult();
    }

    /**
     * Find playbook by name
     */
    public function findByName(string $name): ?Playbook
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.name = :name')
            ->setParameter('name', $name)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
