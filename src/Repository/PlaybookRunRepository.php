<?php

namespace App\Repository;

use App\Entity\PlaybookRun;
use App\Entity\Playbook;
use App\Entity\AbmHit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlaybookRun>
 */
class PlaybookRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlaybookRun::class);
    }

    /**
     * Find runs by playbook
     * 
     * @return list<PlaybookRun>
     */
    public function findByPlaybook(Playbook $playbook, int $limit = 100): array
    {
        /** @var list<PlaybookRun> $runs */
        $runs = $this->createQueryBuilder('pr')
            ->andWhere('pr.playbook = :playbook')
            ->setParameter('playbook', $playbook)
            ->orderBy('pr.triggeredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $runs;
    }

    /**
     * Find runs by ABM hit
     * 
     * @return list<PlaybookRun>
     */
    public function findByAbmHit(AbmHit $abmHit): array
    {
        /** @var list<PlaybookRun> $runs */
        $runs = $this->createQueryBuilder('pr')
            ->andWhere('pr.abmHit = :hit')
            ->setParameter('hit', $abmHit)
            ->orderBy('pr.triggeredAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $runs;
    }

    /**
     * Find pending runs
     * 
     * @return list<PlaybookRun>
     */
    public function findPending(int $limit = 50): array
    {
        /** @var list<PlaybookRun> $runs */
        $runs = $this->createQueryBuilder('pr')
            ->andWhere('pr.status = :status')
            ->setParameter('status', PlaybookRun::STATUS_PENDING)
            ->orderBy('pr.triggeredAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $runs;
    }

    /**
     * Count runs per day
     *
     * @return list<array<string, mixed>>
     */
    public function countRunsPerDay(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = "SELECT DATE(triggered_at) as run_date, COUNT(*) as run_count 
                FROM playbook_runs 
                WHERE triggered_at >= :start AND triggered_at <= :end
                GROUP BY DATE(triggered_at)
                ORDER BY run_date ASC";
        
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['start' => $startDate->format('Y-m-d'), 'end' => $endDate->format('Y-m-d')]);
        
        return $result->fetchAllAssociative();
    }
}
