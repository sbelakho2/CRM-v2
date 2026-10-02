<?php

namespace App\Repository;

use App\Entity\CapacityCalendar;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CapacityCalendar>
 */
class CapacityCalendarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CapacityCalendar::class);
    }

    /**
     * Find available slots for a date range
     *
     * @return list<CapacityCalendar>
     */
    public function findAvailableInRange(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        /** @var list<CapacityCalendar> $calendars */
        $calendars = $this->createQueryBuilder('cc')
            ->andWhere('cc.productionDate >= :start')
            ->andWhere('cc.productionDate <= :end')
            ->andWhere('cc.isHoliday = :holiday')
            ->andWhere('cc.availableSlots > cc.bookedSlots')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->setParameter('holiday', false)
            ->orderBy('cc.productionDate', 'ASC')
            ->getQuery()
            ->getResult();

        return $calendars;
    }

    /**
     * Find capacity by specific date
     */
    public function findByDate(\DateTimeInterface $date): ?CapacityCalendar
    {
        /** @var CapacityCalendar|null $calendar */
        $calendar = $this->createQueryBuilder('cc')
            ->andWhere('cc.productionDate = :date')
            ->setParameter('date', $date)
            ->orderBy('cc.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $calendar;
    }
}
