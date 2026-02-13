<?php

namespace App\Repository;

use App\Entity\CompetitorBlockIntel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompetitorBlockIntel>
 */
class CompetitorBlockIntelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitorBlockIntel::class);
    }

    public function save(CompetitorBlockIntel $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Get all unsynced intel records */
    public function findUnsynced(): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.syncedToLeadcrawler = false')
            ->orderBy('i.generatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
