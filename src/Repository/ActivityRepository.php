<?php

namespace App\Repository;

use App\Entity\Activity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Activity>
 */
class ActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Activity::class);
    }

    public function findRecent(int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.activityDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countMeetingsBetween(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        return $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.type = :type')
            ->andWhere('a.activityDate >= :start')
            ->andWhere('a.activityDate <= :end')
            ->setParameter('type', 'Meeting')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByTypeBetween(string $type, \DateTime $start, \DateTime $end): int
    {
        return $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.type = :type')
            ->andWhere('a.activityDate >= :start')
            ->andWhere('a.activityDate <= :end')
            ->setParameter('type', $type)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
