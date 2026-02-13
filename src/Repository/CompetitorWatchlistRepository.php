<?php

namespace App\Repository;

use App\Entity\CompetitorWatchlist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompetitorWatchlist>
 */
class CompetitorWatchlistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitorWatchlist::class);
    }

    public function save(CompetitorWatchlist $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Find all watchlists with alert enabled */
    public function findWithAlerts(): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.alertOnChange = true')
            ->orderBy('w.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
