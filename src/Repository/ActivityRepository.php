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

    /**
     * Find recent activities with eager loading to prevent N+1 queries.
     * Loads company, contact, and user in a single query.
     */
    public function findRecent(int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.company', 'c')->addSelect('c')
            ->leftJoin('a.contact', 'ct')->addSelect('ct')
            ->leftJoin('a.user', 'u')->addSelect('u')
            ->orderBy('a.activityDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get counts for multiple activity types in a single query.
     * Optimized for dashboard weekly metrics - eliminates 4 separate queries.
     * 
     * @return array<string, int> Map of type => count
     */
    public function countByTypesBetween(array $types, \DateTime $start, \DateTime $end): array
    {
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
        
        return $this->createQueryBuilder('a')
            ->where('a.company = :company')
            ->andWhere('a.activityDate >= :since')
            ->setParameter('company', $company)
            ->setParameter('since', $since)
            ->orderBy('a.activityDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Find activities by company
     * 
     * @param \App\Entity\Company $company
     * @param int $limit
     * @return Activity[]
     */
    public function findByCompanyWithLimit(\App\Entity\Company $company, int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.company = :company')
            ->setParameter('company', $company)
            ->orderBy('a.activityDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Get activity summary by type for a date range
     * 
     * @return array<string, int>
     */
    public function getActivitySummary(\DateTime $start, \DateTime $end): array
    {
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
