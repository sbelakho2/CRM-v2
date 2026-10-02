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
    private const TYPE_MEETING = 'Meeting';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Activity::class);
    }

    /**
     * Find recent activities with eager loading to prevent N+1 queries.
     * Loads company, contact, and user in a single query.
     *
     * @return list<Activity>
     */
    public function findRecent(int $limit = 10): array
    {
        /** @var list<Activity> $activities */
        $activities = $this->createQueryBuilder('a')
            ->leftJoin('a.company', 'c')->addSelect('c')
            ->leftJoin('a.contact', 'ct')->addSelect('ct')
            ->leftJoin('a.user', 'u')->addSelect('u')
            ->orderBy('a.activityDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $activities;
    }

    /**
     * Get counts for multiple activity types in a single query.
     * Optimized for dashboard weekly metrics - eliminates 4 separate queries.
     * 
     * @return array<string, int> Map of type => count
     * @param array<int|string, string> $types
     */
    public function countByTypesBetween(array $types, \DateTime $start, \DateTime $end): array
    {
        /** @var list<array{type: string, cnt: int|string}> $results */
        $results = $this->createQueryBuilder('a')
            ->select('a.type, COUNT(a.id) as cnt')
            ->where('a.type IN (:types)')
            ->andWhere('a.activityDate >= :start')
            ->andWhere('a.activityDate <= :end')
            ->setParameter('types', $types)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->groupBy('a.type')
            ->getQuery()
            ->getResult();
        
        // Initialize all types with 0
        $counts = array_fill_keys($types, 0);
        foreach ($results as $row) {
            $counts[$row['type']] = (int) $row['cnt'];
        }
        
        return $counts;
    }

    public function countMeetingsBetween(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.type = :type')
            ->andWhere('a.activityDate >= :start')
            ->andWhere('a.activityDate <= :end')
            ->setParameter('type', self::TYPE_MEETING)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByTypeBetween(string $type, \DateTime $start, \DateTime $end): int
    {
        return (int) $this->createQueryBuilder('a')
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
    
    /**
     * Find recent activities for a company within a given number of days
     * 
     * @param \App\Entity\Company $company
     * @param int $days Number of days to look back
     * @return Activity[]
     */
    public function findRecentByCompany(\App\Entity\Company $company, int $days = 30): array
    {
        $since = new \DateTime("-{$days} days");

        /** @var list<Activity> $activities */
        $activities = $this->createQueryBuilder('a')
            ->where('a.company = :company')
            ->andWhere('a.activityDate >= :since')
            ->setParameter('company', $company)
            ->setParameter('since', $since)
            ->orderBy('a.activityDate', 'DESC')
            ->getQuery()
            ->getResult();

        return $activities;
    }

    /**
     * Find the latest activities for a company, newest first, without any
     * date window. The company detail page's "Recent Activity" panel must
     * surface the most recent five activities regardless of age.
     *
     * @return list<Activity>
     */
    public function findLatestByCompany(\App\Entity\Company $company, int $limit = 5): array
    {
        /** @var list<Activity> $activities */
        $activities = $this->createQueryBuilder('a')
            ->where('a.company = :company')
            ->andWhere('a.archivedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('a.activityDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $activities;
    }
    
    /**
     * Find activities by company
     * 
     * @param \App\Entity\Company $company
     * @param int $limit
     * @return list<Activity>
     */
    public function findByCompanyWithLimit(\App\Entity\Company $company, int $limit = 10): array
    {
        /** @var list<Activity> $activities */
        $activities = $this->createQueryBuilder('a')
            ->where('a.company = :company')
            ->setParameter('company', $company)
            ->orderBy('a.activityDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $activities;
    }
    
    /**
     * Get activity summary by type for a date range
     * 
     * @return array<string, int>
     */
    public function getActivitySummary(\DateTime $start, \DateTime $end): array
    {
        /** @var list<array{type: string, count: int|string}> $results */
        $results = $this->createQueryBuilder('a')
            ->select('a.type, COUNT(a.id) as count')
            ->where('a.activityDate >= :start')
            ->andWhere('a.activityDate <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->groupBy('a.type')
            ->getQuery()
            ->getResult();

        $summary = [];
        foreach ($results as $row) {
            $summary[$row['type']] = (int) $row['count'];
        }
        
        return $summary;
    }
}
