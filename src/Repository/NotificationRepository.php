<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * Get unread notifications for a user, ordered by newest first
     *
     * @return list<Notification>
     */
    public function findUnreadForUser(User $user, int $limit = 5): array
    {
        /** @var list<Notification> $result */
        $result = $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Get count of unread notifications for a user
     */
    public function countUnreadForUser(User $user): int
    {
        /** @var int|string|null $result */
        $result = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    /**
     * Get all notifications for a user (paginated)
     *
     * @return Paginator<Notification>
     */
    public function findForUser(User $user, int $page = 1, int $limit = 20): Paginator
    {
        $offset = ($page - 1) * $limit;

        $query = $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery();

        /** @var Paginator<Notification> $paginator */
        $paginator = new Paginator($query);

        return $paginator;
    }

    /**
     * Delete notifications older than specified days
     */
    public function deleteOlderThan(\DateTime $date): int
    {
        $deleted = $this->createQueryBuilder('n')
            ->delete()
            ->where('n.createdAt < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();

        return is_numeric($deleted) ? (int) $deleted : 0;
    }

    /**
     * Check if notification of type exists for entity
     */
    public function existsForEntity(User $user, string $type, string $entityType, int $entityId): bool
    {
        $count = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.type = :type')
            ->andWhere('n.entityType = :entityType')
            ->andWhere('n.entityId = :entityId')
            ->setParameter('user', $user)
            ->setParameter('type', $type)
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
