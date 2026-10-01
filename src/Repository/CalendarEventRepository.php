<?php

namespace App\Repository;

use App\Entity\CalendarEvent;
use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CalendarEvent>
 */
class CalendarEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalendarEvent::class);
    }

    public function save(CalendarEvent $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(CalendarEvent $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find events within a date range
     *
     * @return list<CalendarEvent>
     */
    public function findByDateRange(
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        ?User $user = null,
        bool $includeTeam = true
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->where('e.startAt < :end')
            ->andWhere('e.endAt > :start')
            ->andWhere('e.status != :cancelled')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('cancelled', CalendarEvent::STATUS_CANCELLED)
            ->orderBy('e.startAt', 'ASC');

        if ($user) {
            if ($includeTeam) {
                $qb->andWhere(
                    $qb->expr()->orX(
                        'e.organizer = :user',
                        ':user MEMBER OF e.attendees',
                        'e.visibility = :public',
                        'e.visibility = :team'
                    )
                )
                ->setParameter('user', $user)
                ->setParameter('public', CalendarEvent::VISIBILITY_PUBLIC)
                ->setParameter('team', CalendarEvent::VISIBILITY_TEAM);
            } else {
                $qb->andWhere(
                    $qb->expr()->orX(
                        'e.organizer = :user',
                        ':user MEMBER OF e.attendees'
                    )
                )
                ->setParameter('user', $user);
            }
        }

        /** @var list<CalendarEvent> $events */
        $events = $qb->getQuery()->getResult();

        return $events;
    }

    /**
     * Find events for a specific day
     *
     * @return list<CalendarEvent>
     */
    public function findByDate(\DateTimeInterface $date, ?User $user = null): array
    {
        $start = \DateTime::createFromInterface($date)->setTime(0, 0, 0);
        $end = \DateTime::createFromInterface($date)->setTime(23, 59, 59);

        return $this->findByDateRange($start, $end, $user);
    }

    /**
     * Find events for a week
     *
     * @return list<CalendarEvent>
     */
    public function findByWeek(\DateTimeInterface $date, ?User $user = null): array
    {
        $dayOfWeek = (int) $date->format('N');
        $start = \DateTime::createFromInterface($date)->modify('-' . ($dayOfWeek - 1) . ' days')->setTime(0, 0, 0);
        $end = (clone $start)->modify('+6 days')->setTime(23, 59, 59);

        return $this->findByDateRange($start, $end, $user);
    }

    /**
     * Find events for a month
     *
     * @return list<CalendarEvent>
     */
    public function findByMonth(int $year, int $month, ?User $user = null): array
    {
        $start = new \DateTime("$year-$month-01 00:00:00");
        $end = (clone $start)->modify('last day of this month')->setTime(23, 59, 59);

        return $this->findByDateRange($start, $end, $user);
    }

    /**
     * Find upcoming events
     *
     * @return list<CalendarEvent>
     */
    public function findUpcoming(?User $user = null, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.startAt > :now')
            ->andWhere('e.status != :cancelled')
            ->setParameter('now', new \DateTime())
            ->setParameter('cancelled', CalendarEvent::STATUS_CANCELLED)
            ->orderBy('e.startAt', 'ASC')
            ->setMaxResults($limit);

        if ($user) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'e.organizer = :user',
                    ':user MEMBER OF e.attendees'
                )
            )
            ->setParameter('user', $user);
        }

        /** @var list<CalendarEvent> $events */
        $events = $qb->getQuery()->getResult();

        return $events;
    }

    /**
     * Find today's events
     *
     * @return list<CalendarEvent>
     */
    public function findToday(?User $user = null): array
    {
        return $this->findByDate(new \DateTime(), $user);
    }

    /**
     * Find events happening now
     *
     * @return list<CalendarEvent>
     */
    public function findHappeningNow(?User $user = null): array
    {
        $now = new \DateTime();

        $qb = $this->createQueryBuilder('e')
            ->where('e.startAt <= :now')
            ->andWhere('e.endAt >= :now')
            ->andWhere('e.status = :confirmed')
            ->setParameter('now', $now)
            ->setParameter('confirmed', CalendarEvent::STATUS_CONFIRMED)
            ->orderBy('e.startAt', 'ASC');

        if ($user) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'e.organizer = :user',
                    ':user MEMBER OF e.attendees'
                )
            )
            ->setParameter('user', $user);
        }

        /** @var list<CalendarEvent> $events */
        $events = $qb->getQuery()->getResult();

        return $events;
    }

    /**
     * Find events by company
     *
     * @return list<CalendarEvent>
     */
    public function findByCompany(Company $company, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.company = :company')
            ->andWhere('e.status != :cancelled')
            ->setParameter('company', $company)
            ->setParameter('cancelled', CalendarEvent::STATUS_CANCELLED)
            ->orderBy('e.startAt', 'DESC');

        if ($limit) {
            $qb->setMaxResults($limit);
        }

        /** @var list<CalendarEvent> $events */
        $events = $qb->getQuery()->getResult();

        return $events;
    }

    /**
     * Find events by contact
     *
     * @return list<CalendarEvent>
     */
    public function findByContact(Contact $contact, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.contact = :contact')
            ->andWhere('e.status != :cancelled')
            ->setParameter('contact', $contact)
            ->setParameter('cancelled', CalendarEvent::STATUS_CANCELLED)
            ->orderBy('e.startAt', 'DESC');

        if ($limit) {
            $qb->setMaxResults($limit);
        }

        /** @var list<CalendarEvent> $events */
        $events = $qb->getQuery()->getResult();

        return $events;
    }

    /**
     * Find events that need reminders sent
     *
     * @return array<int, CalendarEvent>
     */
    public function findPendingReminders(): array
    {
        $now = new \DateTime();

        /** @var list<CalendarEvent> $results */
        $results = $this->createQueryBuilder('e')
            ->where('e.reminderMinutes IS NOT NULL')
            ->andWhere('e.reminderSent = :false')
            ->andWhere('e.startAt > :now')
            ->andWhere('e.status = :confirmed')
            ->setParameter('false', false)
            ->setParameter('now', $now)
            ->setParameter('confirmed', CalendarEvent::STATUS_CONFIRMED)
            ->getQuery()
            ->getResult();

        return array_filter($results, function (CalendarEvent $e) use ($now) {
            $startAt = $e->getStartAt();
            if ($startAt === null) {
                return false;
            }
            $reminderThreshold = \DateTime::createFromInterface($startAt)
                ->modify('-' . (int) $e->getReminderMinutes() . ' minutes');
            return $reminderThreshold <= $now;
        });
    }

    /**
     * Get events formatted for FullCalendar
     *
     * @return list<array<string, mixed>>
     */
    public function findForFullCalendar(
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        ?User $user = null
    ): array {
        $events = $this->findByDateRange($start, $end, $user);

        /** @var list<array<string, mixed>> $formatted */
        $formatted = array_map(fn(CalendarEvent $e) => $e->toFullCalendarEvent(), $events);

        return $formatted;
    }

    /**
     * Find conflicting events
     *
     * @return list<CalendarEvent>
     */
    public function findConflicts(
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        User $user,
        ?int $excludeEventId = null
    ): array {
        $qb = $this->createQueryBuilder('e');
        $qb->where('e.startAt < :end')
            ->andWhere('e.endAt > :start')
            ->andWhere('e.status = :confirmed')
            ->andWhere(
                $qb->expr()->orX(
                    'e.organizer = :user',
                    ':user MEMBER OF e.attendees'
                )
            )
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('user', $user)
            ->setParameter('confirmed', CalendarEvent::STATUS_CONFIRMED);

        if ($excludeEventId) {
            $qb->andWhere('e.id != :excludeId')
               ->setParameter('excludeId', $excludeEventId);
        }

        /** @var list<CalendarEvent> $events */
        $events = $qb->getQuery()->getResult();

        return $events;
    }

    /**
     * Get user's availability for a date
     *
     * @return list<array{start: string, end: string, title: string|null}>
     */
    public function getUserAvailability(User $user, \DateTimeInterface $date): array
    {
        $events = $this->findByDate($date, $user);

        $busySlots = [];
        foreach ($events as $event) {
            if ($event->isCancelled() || $event->getStartAt() === null || $event->getEndAt() === null) {
                continue;
            }
            $busySlots[] = [
                'start' => $event->getStartAt()->format('H:i'),
                'end' => $event->getEndAt()->format('H:i'),
                'title' => $event->getTitle(),
            ];
        }

        return $busySlots;
    }

    /**
     * Get event statistics for a user
     *
     * @return array{byType: array<string, array{count: int, minutes: int}>, totalEvents: int, totalMinutes: int}
     */
    public function getStatistics(User $user, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $from = $from ?? new \DateTime('first day of this month 00:00:00');
        $to = $to ?? new \DateTime('last day of this month 23:59:59');

        $qb = $this->createQueryBuilder('e');
        $qb->select('e.eventType, e.startAt, e.endAt')
            ->where('e.startAt BETWEEN :from AND :to')
            ->andWhere(
                $qb->expr()->orX(
                    'e.organizer = :user',
                    ':user MEMBER OF e.attendees'
                )
            )
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('user', $user);

        /** @var list<array{eventType: string, startAt: \DateTimeInterface|null, endAt: \DateTimeInterface|null}> $results */
        $results = $qb->getQuery()->getResult();

        $stats = [
            'byType' => [],
            'totalEvents' => 0,
            'totalMinutes' => 0,
        ];

        foreach ($results as $row) {
            $eventType = $row['eventType'];
            $minutes = 0;
            if ($row['startAt'] && $row['endAt']) {
                $minutes = (int) (($row['endAt']->getTimestamp() - $row['startAt']->getTimestamp()) / 60);
            }
            if (!isset($stats['byType'][$eventType])) {
                $stats['byType'][$eventType] = ['count' => 0, 'minutes' => 0];
            }
            $stats['byType'][$eventType]['count']++;
            $stats['byType'][$eventType]['minutes'] += $minutes;
            $stats['totalEvents']++;
            $stats['totalMinutes'] += $minutes;
        }

        return $stats;
    }

    /**
     * Search events
     *
     * @return list<CalendarEvent>
     */
    public function search(string $query, ?User $user = null, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('e');
        $qb->leftJoin('e.company', 'c')
            ->leftJoin('e.contact', 'ct')
            ->where(
                $qb->expr()->orX(
                    'e.title LIKE :query',
                    'e.description LIKE :query',
                    'e.location LIKE :query',
                    'c.name LIKE :query',
                    'ct.firstName LIKE :query',
                    'ct.lastName LIKE :query'
                )
            )
            ->setParameter('query', '%' . addcslashes($query, '%_') . '%')
            ->orderBy('e.startAt', 'DESC')
            ->setMaxResults($limit);

        if ($user) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'e.organizer = :user',
                    ':user MEMBER OF e.attendees',
                    'e.visibility != :private'
                )
            )
            ->setParameter('user', $user)
            ->setParameter('private', CalendarEvent::VISIBILITY_PRIVATE);
        }

        /** @var list<CalendarEvent> $events */
        $events = $qb->getQuery()->getResult();

        return $events;
    }
}
