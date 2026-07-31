<?php

namespace App\Repository;

use App\Entity\MeetingSlot;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use DateTimeImmutable;

/**
 * @extends ServiceEntityRepository<MeetingSlot>
 */
class MeetingSlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MeetingSlot::class);
    }
    
    public function save(MeetingSlot $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
    
    public function remove(MeetingSlot $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
    
    /**
     * Find available slots for a user within a date range
     */
    public function findAvailableByUser(User $user, ?DateTimeImmutable $start = null, ?DateTimeImmutable $end = null): array
    {
        $start = $start ?? new DateTimeImmutable();
        $end = $end ?? $start->modify('+30 days');
        
        return $this->createQueryBuilder('s')
            ->andWhere('s.owner = :user')
            ->andWhere('s.status = :status')
            ->andWhere('s.startTime >= :start')
            ->andWhere('s.startTime <= :end')
            ->setParameter('user', $user)
            ->setParameter('status', MeetingSlot::STATUS_AVAILABLE)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('s.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Find booked meetings for a user
     */
    public function findBookedByUser(User $user, bool $upcomingOnly = true): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.owner = :user')
            ->andWhere('s.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', MeetingSlot::STATUS_BOOKED)
            ->orderBy('s.startTime', 'ASC');
        
        if ($upcomingOnly) {
            $qb->andWhere('s.startTime >= :now')
               ->setParameter('now', new DateTimeImmutable());
        }
        
        return $qb->getQuery()->getResult();
    }
    
    /**
     * Find all slots for a user within a date range
     */
    public function findByUserAndDateRange(User $user, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.owner = :user')
            ->andWhere('s.startTime >= :start')
            ->andWhere('s.startTime <= :end')
            ->setParameter('user', $user)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('s.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Find slot by booking token
     */
    public function findByBookingToken(string $token): ?MeetingSlot
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.bookingToken = :token')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }
    
    /**
     * Find slot by cancellation token
     */
    public function findByCancellationToken(string $token): ?MeetingSlot
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.cancellationToken = :token')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }
    
    /**
     * Find today's meetings for a user
     */
    public function findTodayByUser(User $user): array
    {
        $today = new DateTimeImmutable('today');
        $tomorrow = $today->modify('+1 day');
        
        return $this->createQueryBuilder('s')
            ->andWhere('s.owner = :user')
            ->andWhere('s.startTime >= :today')
            ->andWhere('s.startTime < :tomorrow')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('today', $today)
            ->setParameter('tomorrow', $tomorrow)
            ->setParameter('statuses', [MeetingSlot::STATUS_BOOKED, MeetingSlot::STATUS_AVAILABLE])
            ->orderBy('s.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Find upcoming meetings needing reminders
     */
    public function findNeedingReminders(int $hoursAhead = 24): array
    {
        $now = new DateTimeImmutable();
        $cutoff = $now->modify("+{$hoursAhead} hours");
        
        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :status')
            ->andWhere('s.reminderSent = false')
            ->andWhere('s.startTime >= :now')
            ->andWhere('s.startTime <= :cutoff')
            ->setParameter('status', MeetingSlot::STATUS_BOOKED)
            ->setParameter('now', $now)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('s.startTime', 'ASC')
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Find slots with conflicts
     */
    public function findConflicts(User $user, DateTimeImmutable $start, DateTimeImmutable $end, ?int $excludeId = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.owner = :user')
            ->andWhere('s.status IN (:statuses)')
            ->andWhere('s.startTime < :end')
            ->andWhere('s.endTime > :start')
            ->setParameter('user', $user)
            ->setParameter('statuses', [MeetingSlot::STATUS_AVAILABLE, MeetingSlot::STATUS_BOOKED])
            ->setParameter('start', $start)
            ->setParameter('end', $end);
        
        if ($excludeId) {
            $qb->andWhere('s.id != :excludeId')
               ->setParameter('excludeId', $excludeId);
        }
        
        return $qb->getQuery()->getResult();
    }
    
    /**
     * Get statistics for a user
     */
    public function getStatistics(User $user): array
    {
        $now = new DateTimeImmutable();
        $weekStart = new DateTimeImmutable('monday this week');
        $weekEnd = $weekStart->modify('+7 days');

        $result = $this->createQueryBuilder('s')
            ->select('
                SUM(CASE WHEN s.status = :available AND s.startTime >= :now THEN 1 ELSE 0 END) as available,
                SUM(CASE WHEN s.status = :booked AND s.startTime >= :now THEN 1 ELSE 0 END) as bookedUpcoming,
                SUM(CASE WHEN s.status = :booked THEN 1 ELSE 0 END) as totalBooked,
                SUM(CASE WHEN s.status = :booked AND s.startTime >= :weekStart AND s.startTime < :weekEnd THEN 1 ELSE 0 END) as thisWeek
            ')
            ->andWhere('s.owner = :user')
            ->setParameter('user', $user)
            ->setParameter('available', MeetingSlot::STATUS_AVAILABLE)
            ->setParameter('booked', MeetingSlot::STATUS_BOOKED)
            ->setParameter('now', $now)
            ->setParameter('weekStart', $weekStart)
            ->setParameter('weekEnd', $weekEnd)
            ->getQuery()
            ->getSingleResult();

        // By meeting type
        $byType = $this->createQueryBuilder('s')
            ->select('s.meetingType, COUNT(s.id) as count')
            ->andWhere('s.owner = :user')
            ->andWhere('s.status = :booked')
            ->setParameter('user', $user)
            ->setParameter('booked', MeetingSlot::STATUS_BOOKED)
            ->groupBy('s.meetingType')
            ->getQuery()
            ->getResult();

        $typeStats = [];
        foreach ($byType as $row) {
            $typeStats[$row['meetingType']] = (int) $row['count'];
        }

        return [
            'available' => (int) ($result['available'] ?? 0),
            'bookedUpcoming' => (int) ($result['bookedUpcoming'] ?? 0),
            'totalBooked' => (int) ($result['totalBooked'] ?? 0),
            'thisWeek' => (int) ($result['thisWeek'] ?? 0),
            'byType' => $typeStats,
        ];
    }
    
    /**
     * Generate recurring slots
     */
    public function generateRecurringSlots(
        User $owner,
        string $title,
        string $meetingType,
        int $durationMinutes,
        array $weekdays, // 0 = Sunday, 6 = Saturday
        string $startTimeOfDay, // "09:00"
        DateTimeImmutable $rangeStart,
        DateTimeImmutable $rangeEnd,
        ?string $location = null,
        ?string $meetingUrl = null,
        string $timezone = 'UTC'
    ): array {
        $slots = [];

        $existingSlots = $this->findByUserAndDateRange($owner, $rangeStart, $rangeEnd);
        $conflictIndex = [];
        foreach ($existingSlots as $existing) {
            $dateKey = $existing->getStartTime()->format('Y-m-d');
            $conflictIndex[$dateKey][] = [
                'start' => $existing->getStartTime(),
                'end' => $existing->getEndTime(),
            ];
        }

        $current = $rangeStart;
        while ($current <= $rangeEnd) {
            $dayOfWeek = (int) $current->format('w');

            if (in_array($dayOfWeek, $weekdays)) {
                [$hour, $minute] = explode(':', $startTimeOfDay);
                $startTime = $current->setTime((int) $hour, (int) $minute);
                $endTime = $startTime->modify("+{$durationMinutes} minutes");

                $dateKey = $startTime->format('Y-m-d');
                $hasConflict = false;
                if (isset($conflictIndex[$dateKey])) {
                    foreach ($conflictIndex[$dateKey] as $conflict) {
                        if ($startTime < $conflict['end'] && $endTime > $conflict['start']) {
                            $hasConflict = true;
                            break;
                        }
                    }
                }

                if (!$hasConflict) {
                    $slot = new MeetingSlot();
                    $slot->setTitle($title)
                         ->setMeetingType($meetingType)
                         ->setDurationMinutes($durationMinutes)
                         ->setStartTime($startTime)
                         ->setEndTime($endTime)
                         ->setOwner($owner)
                         ->setStatus(MeetingSlot::STATUS_AVAILABLE)
                         ->setLocation($location)
                         ->setMeetingUrl($meetingUrl)
                         ->setTimezone($timezone);

                    $this->getEntityManager()->persist($slot);
                    $slots[] = $slot;
                }
            }

            $current = $current->modify('+1 day');
        }

        return $slots;
    }

    /**
     * Persist and flush a batch of slots generated by generateRecurringSlots.
     */
    public function saveBatch(array $slots): void
    {
        $em = $this->getEntityManager();
        foreach ($slots as $slot) {
            $em->persist($slot);
        }
        $em->flush();
    }

    /**
     * Clean up old cancelled/available slots
     */
    public function cleanupOldSlots(int $daysOld = 30): int
    {
        $cutoff = new DateTimeImmutable("-{$daysOld} days");
        
        return $this->createQueryBuilder('s')
            ->delete()
            ->andWhere('s.endTime < :cutoff')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('cutoff', $cutoff)
            ->setParameter('statuses', [MeetingSlot::STATUS_AVAILABLE, MeetingSlot::STATUS_CANCELLED])
            ->getQuery()
            ->execute();
    }
}
