<?php

namespace App\Repository;

use App\Entity\WebEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WebEvent>
 */
class WebEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebEvent::class);
    }

    /**
     * Find unprocessed events
     * 
     * @return WebEvent[]
     */
    public function findUnprocessed(int $limit = 1000): array
    {
        return $this->createQueryBuilder('we')
            ->andWhere('we.isProcessed = :processed')
            ->setParameter('processed', false)
            ->orderBy('we.timestamp', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find events by IP address
     * 
     * @return WebEvent[]
     */
    public function findByIpAddress(string $ipAddress, ?\DateTimeInterface $since = null): array
    {
        $qb = $this->createQueryBuilder('we')
            ->andWhere('we.ipAddress = :ip')
            ->setParameter('ip', $ipAddress);

        if ($since) {
            $qb->andWhere('we.timestamp >= :since')
                ->setParameter('since', $since);
        }

        return $qb->orderBy('we.timestamp', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Delete old events (data retention)
     */
    public function deleteOlderThan(\DateTimeInterface $date): int
    {
        return $this->createQueryBuilder('we')
            ->delete()
            ->where('we.timestamp < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();
    }
}
