<?php

namespace App\Repository;

use App\Entity\Task;
use App\Entity\User;
use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Task>
 *
 * @method Task|null find($id, $lockMode = null, $lockVersion = null)
 * @method Task|null findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method list<Task> findAll()
 * @method list<Task> findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, ?int $limit = null, ?int $offset = null)
 */
class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    public function save(Task $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Task $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find tasks for a user (assigned to or created by)
     *
     * @return list<Task>
     */
    public function findByUser(User $user, ?string $status = null, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.assignedTo = :user OR t.createdBy = :user')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('t.dueDate', 'ASC')
            ->addOrderBy('t.priority', 'DESC')
            ->setMaxResults($limit);

        if ($status !== null) {
            $qb->andWhere('t.status = :status')
               ->setParameter('status', $status);
        }

        /** @var list<Task> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Find tasks grouped by status for kanban view
     *
     * @return array<string, list<Task>>
     */
    public function findGroupedByStatus(?User $user = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.assignedTo', 'a')
            ->leftJoin('t.company', 'c')
            ->leftJoin('t.contact', 'co')
            ->addSelect('a', 'c', 'co')
            ->andWhere('t.archivedAt IS NULL')
            ->orderBy('t.sortOrder', 'ASC')
            ->addOrderBy('t.priority', 'DESC')
            ->addOrderBy('t.dueDate', 'ASC');

        if ($user !== null) {
            $qb->andWhere('t.assignedTo = :user OR t.createdBy = :user')
               ->setParameter('user', $user);
        }

        /** @var list<Task> $tasks */
        $tasks = $qb->getQuery()->getResult();

        $grouped = [];
        foreach (Task::STATUSES as $status) {
            $grouped[$status] = [];
        }

        foreach ($tasks as $task) {
            $grouped[$task->getStatus()][] = $task;
        }

        return $grouped;
    }

    /**
     * Find overdue tasks
     *
     * @return list<Task>
     */
    public function findOverdue(?User $user = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.dueDate < :today')
            ->andWhere('t.archivedAt IS NULL')
            ->andWhere('t.status NOT IN (:completedStatuses)')
            ->setParameter('today', new \DateTime('today'))
            ->setParameter('completedStatuses', [Task::STATUS_DONE, Task::STATUS_CANCELLED])
            ->orderBy('t.dueDate', 'ASC');

        if ($user !== null) {
            $qb->andWhere('t.assignedTo = :user')
               ->setParameter('user', $user);
        }

        /** @var list<Task> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Find tasks due today
     *
     * @return list<Task>
     */
    public function findDueToday(?User $user = null): array
    {
        $today = new \DateTime('today');
        $tomorrow = new \DateTime('tomorrow');

        $qb = $this->createQueryBuilder('t')
            ->where('t.dueDate >= :today')
            ->andWhere('t.dueDate < :tomorrow')
            ->andWhere('t.archivedAt IS NULL')
            ->andWhere('t.status NOT IN (:completedStatuses)')
            ->setParameter('today', $today)
            ->setParameter('tomorrow', $tomorrow)
            ->setParameter('completedStatuses', [Task::STATUS_DONE, Task::STATUS_CANCELLED])
            ->orderBy('t.dueTime', 'ASC')
            ->addOrderBy('t.priority', 'DESC');

        if ($user !== null) {
            $qb->andWhere('t.assignedTo = :user')
               ->setParameter('user', $user);
        }

        /** @var list<Task> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Find tasks due this week
     *
     * @return list<Task>
     */
    public function findDueThisWeek(?User $user = null): array
    {
        $today = new \DateTime('today');
        $endOfWeek = new \DateTime('sunday this week 23:59:59');

        $qb = $this->createQueryBuilder('t')
            ->where('t.dueDate >= :today')
            ->andWhere('t.dueDate <= :endOfWeek')
            ->andWhere('t.archivedAt IS NULL')
            ->andWhere('t.status NOT IN (:completedStatuses)')
            ->setParameter('today', $today)
            ->setParameter('endOfWeek', $endOfWeek)
            ->setParameter('completedStatuses', [Task::STATUS_DONE, Task::STATUS_CANCELLED])
            ->orderBy('t.dueDate', 'ASC')
            ->addOrderBy('t.priority', 'DESC');

        if ($user !== null) {
            $qb->andWhere('t.assignedTo = :user')
               ->setParameter('user', $user);
        }

        /** @var list<Task> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Find tasks by company
     *
     * @return list<Task>
     */
    public function findByCompany(Company $company, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.company = :company')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('t.dueDate', 'ASC');

        if ($status !== null) {
            $qb->andWhere('t.status = :status')
               ->setParameter('status', $status);
        }

        /** @var list<Task> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Find tasks needing reminders
     *
     * @return list<Task>
     */
    public function findPendingReminders(): array
    {
        $now = new \DateTime();

        /** @var list<Task> $result */
        $result = $this->createQueryBuilder('t')
            ->where('t.reminderAt IS NOT NULL')
            ->andWhere('t.reminderAt <= :now')
            ->andWhere('t.reminderSent = false')
            ->andWhere('t.archivedAt IS NULL')
            ->andWhere('t.status NOT IN (:completedStatuses)')
            ->setParameter('now', $now)
            ->setParameter('completedStatuses', [Task::STATUS_DONE, Task::STATUS_CANCELLED])
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Get task statistics for a user
     *
     * @return array{total: int, by_status: array<string, int>, overdue: int, due_today: int, due_this_week: int}
     */
    public function getStatistics(?User $user = null): array
    {
        $today = new \DateTime('today');
        $tomorrow = new \DateTime('tomorrow');
        $endOfWeek = new \DateTime('sunday this week 23:59:59');

        $qb = $this->createQueryBuilder('t')
            ->select('
                t.status,
                COUNT(t.id) as count,
                SUM(CASE WHEN t.dueDate < :today AND t.status NOT IN (:completedStatuses) THEN 1 ELSE 0 END) as overdueCnt,
                SUM(CASE WHEN t.dueDate >= :today AND t.dueDate < :tomorrow AND t.status NOT IN (:completedStatuses2) THEN 1 ELSE 0 END) as dueTodayCnt,
                SUM(CASE WHEN t.dueDate >= :today2 AND t.dueDate <= :endOfWeek AND t.status NOT IN (:completedStatuses3) THEN 1 ELSE 0 END) as dueWeekCnt
            ')
            ->groupBy('t.status')
            ->setParameter('today', $today)
            ->setParameter('today2', $today)
            ->setParameter('tomorrow', $tomorrow)
            ->setParameter('endOfWeek', $endOfWeek)
            ->setParameter('completedStatuses', [Task::STATUS_DONE, Task::STATUS_CANCELLED])
            ->setParameter('completedStatuses2', [Task::STATUS_DONE, Task::STATUS_CANCELLED])
            ->setParameter('completedStatuses3', [Task::STATUS_DONE, Task::STATUS_CANCELLED])
            ->andWhere('t.archivedAt IS NULL');

        if ($user !== null) {
            $qb->andWhere('t.assignedTo = :user')
               ->setParameter('user', $user);
        }

        /** @var list<array{status: string, count: int|string, overdueCnt: int|string|float|null, dueTodayCnt: int|string|float|null, dueWeekCnt: int|string|float|null}> $results */
        $results = $qb->getQuery()->getResult();

        $stats = [
            'total' => 0,
            'by_status' => [],
            'overdue' => 0,
            'due_today' => 0,
            'due_this_week' => 0,
        ];

        foreach ($results as $row) {
            $stats['by_status'][$row['status']] = (int) $row['count'];
            $stats['total'] += (int) $row['count'];
            // The query GROUPs BY status, so each duration bucket arrives
            // once per status group — accumulate across groups instead of
            // overwriting (last-group-wins bug).
            $stats['overdue'] += (int) ($row['overdueCnt'] ?? 0);
            $stats['due_today'] += (int) ($row['dueTodayCnt'] ?? 0);
            $stats['due_this_week'] += (int) ($row['dueWeekCnt'] ?? 0);
        }

        return $stats;
    }

    /**
     * Kept for API parity with findOverdue()/the stats buckets; retained as
     * protected so subclasses (and future callers) can use it.
     */
    protected function countOverdue(?User $user = null): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.dueDate < :today')
            ->andWhere('t.archivedAt IS NULL')
            ->andWhere('t.status NOT IN (:completedStatuses)')
            ->setParameter('today', new \DateTime('today'))
            ->setParameter('completedStatuses', [Task::STATUS_DONE, Task::STATUS_CANCELLED]);

        if ($user !== null) {
            $qb->andWhere('t.assignedTo = :user')
               ->setParameter('user', $user);
        }

        /** @var int|string|null $count */
        $count = $qb->getQuery()->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * Kept for API parity with findDueToday()/the stats buckets; retained as
     * protected so subclasses (and future callers) can use it.
     */
    protected function countDueToday(?User $user = null): int
    {
        $today = new \DateTime('today');
        $tomorrow = new \DateTime('tomorrow');

        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.dueDate >= :today')
            ->andWhere('t.dueDate < :tomorrow')
            ->andWhere('t.archivedAt IS NULL')
            ->andWhere('t.status NOT IN (:completedStatuses)')
            ->setParameter('today', $today)
            ->setParameter('tomorrow', $tomorrow)
            ->setParameter('completedStatuses', [Task::STATUS_DONE, Task::STATUS_CANCELLED]);

        if ($user !== null) {
            $qb->andWhere('t.assignedTo = :user')
               ->setParameter('user', $user);
        }

        /** @var int|string|null $count */
        $count = $qb->getQuery()->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * Kept for API parity with findDueThisWeek()/the stats buckets; retained as
     * protected so subclasses (and future callers) can use it.
     */
    protected function countDueThisWeek(?User $user = null): int
    {
        $today = new \DateTime('today');
        $endOfWeek = new \DateTime('sunday this week 23:59:59');

        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.dueDate >= :today')
            ->andWhere('t.dueDate <= :endOfWeek')
            ->andWhere('t.archivedAt IS NULL')
            ->andWhere('t.status NOT IN (:completedStatuses)')
            ->setParameter('today', $today)
            ->setParameter('endOfWeek', $endOfWeek)
            ->setParameter('completedStatuses', [Task::STATUS_DONE, Task::STATUS_CANCELLED]);

        if ($user !== null) {
            $qb->andWhere('t.assignedTo = :user')
               ->setParameter('user', $user);
        }

        /** @var int|string|null $count */
        $count = $qb->getQuery()->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * Update task sort orders for kanban drag-drop
     *
     * @param list<array{id: int|string, sortOrder: int|string, status?: string}> $taskOrders
     */
    public function updateSortOrders(array $taskOrders): void
    {
        $em = $this->getEntityManager();

        foreach ($taskOrders as $order) {
            $task = $this->find($order['id']);
            if ($task) {
                $task->setSortOrder((int) $order['sortOrder']);
                if (isset($order['status'])) {
                    try {
                        $task->transitionTo($order['status']);
                    } catch (\InvalidArgumentException | \LogicException) {
                        continue; // invalid/archived task — never corrupt it
                    }
                }
            }
        }
        
        $em->flush();
    }

    /**
     * Find recurring tasks that need to be duplicated
     *
     * @return list<Task>
     */
    public function findRecurringTasksToDuplicate(): array
    {
        /** @var list<Task> $result */
        $result = $this->createQueryBuilder('t')
            ->where('t.isRecurring = true')
            ->andWhere('t.status = :done')
            ->andWhere('t.archivedAt IS NULL')
            ->andWhere('t.recurringFrequency IS NOT NULL')
            ->setParameter('done', Task::STATUS_DONE)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Explicitly fetch archived tasks (admin archive browser). Live
     * repository paths exclude archived rows; this is the only query that
     * surfaces them.
     */
    /**
     * @return list<Task>
     */
    public function findArchived(?User $user = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.archivedAt IS NOT NULL')
            ->orderBy('t.archivedAt', 'DESC');

        if ($user !== null) {
            $qb->andWhere('t.assignedTo = :user OR t.createdBy = :user')
               ->setParameter('user', $user);
        }

        /** @var list<Task> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Search tasks by title or description
     *
     * @return list<Task>
     */
    public function search(string $query, ?User $user = null, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.title LIKE :query OR t.description LIKE :query')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('query', '%' . addcslashes($query, '%_') . '%')
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($user !== null) {
            $qb->andWhere('t.assignedTo = :user OR t.createdBy = :user')
               ->setParameter('user', $user);
        }

        /** @var list<Task> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }
}
