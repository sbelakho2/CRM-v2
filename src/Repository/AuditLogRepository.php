<?php

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * Find audit logs for a specific entity
     *
     * @return list<AuditLog>
     */
    public function findByEntity(string $entityType, int $entityId, int $limit = 50): array
    {
        /** @var list<AuditLog> $logs */
        $logs = $this->createQueryBuilder('a')
            ->andWhere('a.entityType = :entityType')
            ->andWhere('a.entityId = :entityId')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $logs;
    }

    /**
     * Find recent audit logs
     *
     * @return list<AuditLog>
     */
    public function findRecent(int $limit = 100): array
    {
        /** @var list<AuditLog> $logs */
        $logs = $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $logs;
    }

    /**
     * Find audit logs by user
     *
     * @return list<AuditLog>
     */
    public function findByUser(int $userId, int $limit = 100): array
    {
        /** @var list<AuditLog> $logs */
        $logs = $this->createQueryBuilder('a')
            ->andWhere('a.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $logs;
    }

    /**
     * Find audit logs by action type
     *
     * @return list<AuditLog>
     */
    public function findByAction(string $action, int $limit = 100): array
    {
        /** @var list<AuditLog> $logs */
        $logs = $this->createQueryBuilder('a')
            ->andWhere('a.action = :action')
            ->setParameter('action', $action)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $logs;
    }
}
