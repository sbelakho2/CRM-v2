<?php

namespace App\Repository;

use App\Entity\WorkerHeartbeat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkerHeartbeat>
 */
class WorkerHeartbeatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkerHeartbeat::class);
    }

    /**
     * Record a completed worker run (upsert by worker name).
     */
    public function beat(string $workerName, string $result = 'ok'): void
    {
        $heartbeat = $this->findOneBy(['name' => $workerName]);
        if ($heartbeat === null) {
            $heartbeat = new WorkerHeartbeat();
            $heartbeat->setName($workerName);
            $this->getEntityManager()->persist($heartbeat);
        }

        $heartbeat->setLastRunAt(new \DateTime());
        $heartbeat->setRunCount($heartbeat->getRunCount() + 1);
        $heartbeat->setLastResult(mb_substr($result, 0, 255));

        $this->getEntityManager()->flush();
    }

    /**
     * Seconds since the named worker last completed a run (null when the
     * worker has never run).
     */
    public function stalenessSeconds(string $workerName): ?int
    {
        /** @var \DateTimeInterface|null $row */
        $row = $this->createQueryBuilder('h')
            ->select('h.lastRunAt')
            ->andWhere('h.name = :name')
            ->setParameter('name', $workerName)
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleScalarResult();

        if ($row === null) {
            return null;
        }

        return time() - $row->getTimestamp();
    }
}
