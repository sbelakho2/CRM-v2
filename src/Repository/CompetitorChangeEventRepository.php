<?php

namespace App\Repository;

use App\Entity\CompetitorChangeEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompetitorChangeEvent>
 */
class CompetitorChangeEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompetitorChangeEvent::class);
    }

    public function save(CompetitorChangeEvent $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Get recent change events across all competitors */
    public function findRecent(int $limit = 50, ?string $severity = null, int $days = 30): array
    {
        $qb = $this->createQueryBuilder('e')
            ->join('e.competitor', 'c')
            ->addSelect('c');

        $cutoff = new \DateTime("-{$days} days");
        $qb->andWhere('e.createdAt >= :cutoff')->setParameter('cutoff', $cutoff);

        if ($severity) {
            $qb->andWhere('e.severity = :severity')->setParameter('severity', $severity);
        }

        return $qb->orderBy('e.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Get change events for a specific competitor */
    public function findByCompetitor(int $competitorId, int $limit = 20): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.competitor = :id')
            ->setParameter('id', $competitorId)
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Count events in last N days */
    public function countRecentByType(int $days = 7): array
    {
        $cutoff = new \DateTime("-{$days} days");
        $results = $this->createQueryBuilder('e')
            ->select('e.changeType, COUNT(e.id) as cnt')
            ->where('e.createdAt > :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->groupBy('e.changeType')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($results as $row) {
            $out[$row['changeType']] = (int) $row['cnt'];
        }
        return $out;
    }
}
