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
 * @method Task|null findOneBy(array $criteria, array $orderBy = null)
 * @method Task[]    findAll()
 * @method Task[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
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
     */
    public function findByUser(User $user, ?string $status = null, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.assignedTo = :user OR t.createdBy = :user')
            ->setParameter('user', $user)
            ->orderBy('t.dueDate', 'ASC')
            ->addOrderBy('t.priority', 'DESC')
            ->setMaxResults($limit);

        if ($status !== null) {
            $qb->andWhere('t.status = :status')
               ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find tasks grouped by status for kanban view
     */
    public function findGroupedByStatus(?User $user = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.assignedTo', 'a')
            ->leftJoin('t.company', 'c')
            ->leftJoin('t.contact', 'co')
            ->addSelect('a', 'c', 'co')
            ->orderBy('t.sortOrder', 'ASC')
            ->addOrderBy('t.priority', 'DESC')
            ->addOrderBy('t.dueDate', 'ASC');

        if ($user !== null) {
            $qb->andWhere('t.assignedTo = :user OR t.createdBy = :user')
               ->setParameter('user', $user);
        }

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
     */
    public function findOverdue(?User $user = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.dueDate < :today')
            ->andWhere('t.status NOT IN (:completedStatuses)')
            ->setParameter('today', new \DateTime('today'))
            ->setParameter('completedStatuses', [Task::STATUS_DONE, Task::STATUS_CANCELLED])
            ->orderBy('t.dueDate', 'ASC');

        if ($user !== null) {
            $qb->andWhere('t.assignedTo = :user')
               ->setParameter('user', $user);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find tasks due today
     */
    public function findDueToday(?User $user = null): array
    {
        $today = new \DateTime('today');
        $tomorrow = new \DateTime('tomorrow');

        $qb = $this->createQueryBuilder('t')
            ->where('t.dueDate >= :today')
            ->andWhere('t.dueDate < :tomorrow')
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

        return $qb->getQuery()->getResult();
    }

    /**
     * Find tasks due this week
     */
    public function findDueThisWeek(?User $user = null): array
    {
        $today = new \DateTime('today');
        $endOfWeek = new \DateTime('sunday this week 23:59:59');

        $qb = $this->createQueryBuilder('t')
            ->where('t.dueDate >= :today')
            ->andWhere('t.dueDate <= :endOfWeek')
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

        return $qb->getQuery()->getResult();
    }

    /**
     * Find tasks by company
     */
    public function findByCompany(Company $company, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.company = :company')
            ->setParameter('company', $company)
            ->orderBy('t.dueDate', 'ASC');

        if ($status !== null) {
            $qb->andWhere('t.status = :status')
               ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find tasks needing reminders
     */
    public function findPendingReminders(): array
    {
        $now = new \DateTime();

        return $this->createQueryBuilder('t')
            ->where('t.reminderAt IS NOT NULL')
            ->andWhere('t.reminderAt <= :now')
            ->andWhere('t.reminderSent = false')
            ->andWhere('t.status NOT IN (:completedStatuses)')
            ->setParameter('now', $now)
            ->setParameter('completedStatuses', [Task::STATUS_DONE, Task::STATUS_CANCELLED])
            ->getQuery()
            ->getResult();
    }

    /**
     * Get task statistics for a user
     */
    public function getStatistics(?User $user = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.status, COUNT(t.id) as count')
            ->groupBy('t.status');

        if ($user !== null) {
            $qb->andWhere('t.assignedTo = :user')
               ->setParameter('user', $user);
        }

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
        }

        $stats['overdue'] = count($this->findOverdue($user));
        $stats['due_today'] = count($this->findDueToday($user));
        $stats['due_this_week'] = count($this->findDueThisWeek($user));

        return $stats;
    }

    /**
     * Update task sort orders for kanban drag-drop
     */
    public function updateSortOrders(array $taskOrders): void
    {
        $em = $this->getEntityManager();
        
        foreach ($taskOrders as $order) {
            $task = $this->find($order['id']);
            if ($task) {
                $task->setSortOrder($order['sortOrder']);
                if (isset($order['status'])) {
                    $task->setStatus($order['status']);
                }
            }
        }
        
        $em->flush();
    }

    /**
     * Find recurring tasks that need to be duplicated
     */
    public function findRecurringTasksToDuplicate(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.isRecurring = true')
            ->andWhere('t.status = :done')
            ->andWhere('t.recurringFrequency IS NOT NULL')
            ->setParameter('done', Task::STATUS_DONE)
            ->getQuery()
            ->getResult();
    }

    /**
     * Search tasks by title or description
     */
    public function search(string $query, ?User $user = null, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.title LIKE :query OR t.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($user !== null) {
            $qb->andWhere('t.assignedTo = :user OR t.createdBy = :user')
               ->setParameter('user', $user);
        }

        return $qb->getQuery()->getResult();
    }
}
