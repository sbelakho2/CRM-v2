<?php

namespace App\Repository;

use App\Entity\AbmHit;
use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AbmHit>
 */
class AbmHitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AbmHit::class);
    }

    /**
     * Find hits by company
     * 
     * @return AbmHit[]
     */
    public function findByCompany(Company $company, int $limit = 100): array
    {
        return $this->createQueryBuilder('ah')
            ->andWhere('ah.company = :company')
            ->setParameter('company', $company)
            ->orderBy('ah.timestamp', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find unidentified hits
     * 
     * @return AbmHit[]
     */
    public function findUnidentified(int $limit = 100): array
    {
        return $this->createQueryBuilder('ah')
            ->andWhere('ah.isIdentified = :identified')
            ->setParameter('identified', false)
            ->orderBy('ah.timestamp', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find hits without playbook trigger
     * 
     * @return AbmHit[]
     */
    public function findPendingPlaybook(int $limit = 100): array
    {
        return $this->createQueryBuilder('ah')
            ->andWhere('ah.isIdentified = :identified')
            ->andWhere('ah.playbookTriggered = :triggered')
            ->setParameter('identified', true)
            ->setParameter('triggered', false)
            ->orderBy('ah.timestamp', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count hits per day
     */
    public function countHitsPerDay(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = "SELECT DATE(timestamp) as hit_date, COUNT(*) as hit_count 
                FROM abm_hits 
                WHERE timestamp >= :start AND timestamp <= :end
                GROUP BY DATE(timestamp)
                ORDER BY hit_date ASC";
        
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['start' => $startDate->format('Y-m-d'), 'end' => $endDate->format('Y-m-d')]);
        
        return $result->fetchAllAssociative();
    }
}
