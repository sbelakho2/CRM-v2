<?php

namespace App\Repository;

use App\Entity\ReportDefinition;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReportDefinition>
 */
class ReportDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReportDefinition::class);
    }
    
    public function save(ReportDefinition $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
    
    public function remove(ReportDefinition $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
    
    /**
     * Find reports accessible by a user
     */
    public function findAccessibleByUser(User $user, ?string $dataSource = null, ?string $category = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.createdBy', 'u')
            ->orderBy('r.isFavorite', 'DESC')
            ->addOrderBy('r.name', 'ASC');
        
        // Access control: user's own reports, public reports, or role-matched reports
        $qb->andWhere('r.createdBy = :user OR r.isPublic = true')
           ->setParameter('user', $user);
        
        if ($dataSource) {
            $qb->andWhere('r.dataSource = :source')
               ->setParameter('source', $dataSource);
        }
        
        if ($category) {
            $qb->andWhere('r.category = :category')
               ->setParameter('category', $category);
        }
        
        return $qb->getQuery()->getResult();
    }
    
    /**
     * Find user's favorite reports
     */
    public function findFavorites(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.createdBy = :user OR r.isPublic = true')
            ->andWhere('r.isFavorite = true')
            ->setParameter('user', $user)
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Find recent reports for a user
     */
    public function findRecent(User $user, int $limit = 10): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.createdBy = :user')
            ->andWhere('r.lastRunAt IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('r.lastRunAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Find most popular reports
     */
    public function findPopular(int $limit = 10): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.isPublic = true')
            ->andWhere('r.runCount > 0')
            ->orderBy('r.runCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Find reports by data source
     */
    public function findByDataSource(string $dataSource): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.dataSource = :source')
            ->setParameter('source', $dataSource)
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Get unique categories
     */
    public function findAllCategories(): array
    {
        $result = $this->createQueryBuilder('r')
            ->select('DISTINCT r.category')
            ->andWhere('r.category IS NOT NULL')
            ->orderBy('r.category', 'ASC')
            ->getQuery()
            ->getScalarResult();
        
        return array_filter(array_column($result, 'category'));
    }
    
    /**
     * Find reports with scheduled delivery
     */
    public function findScheduledReports(): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.scheduledDelivery IS NOT NULL')
            ->andWhere("JSON_LENGTH(r.scheduledDelivery) > 0")
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Search reports by name/description
     */
    public function search(string $query, User $user): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.createdBy = :user OR r.isPublic = true')
            ->andWhere('r.name LIKE :query OR r.description LIKE :query')
            ->setParameter('user', $user)
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Get statistics
     */
    public function getStatistics(User $user): array
    {
        $qb = $this->createQueryBuilder('r');
        
        $total = (int) $qb->select('COUNT(r.id)')
            ->andWhere('r.createdBy = :user OR r.isPublic = true')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
        
        $myReports = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.createdBy = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
        
        $favorites = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.createdBy = :user OR r.isPublic = true')
            ->andWhere('r.isFavorite = true')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
        
        // By data source
        $bySource = $this->createQueryBuilder('r')
            ->select('r.dataSource, COUNT(r.id) as count')
            ->andWhere('r.createdBy = :user OR r.isPublic = true')
            ->setParameter('user', $user)
            ->groupBy('r.dataSource')
            ->getQuery()
            ->getResult();
        
        $sourceStats = [];
        foreach ($bySource as $row) {
            $sourceStats[$row['dataSource']] = (int) $row['count'];
        }
        
        // By report type
        $byType = $this->createQueryBuilder('r')
            ->select('r.reportType, COUNT(r.id) as count')
            ->andWhere('r.createdBy = :user OR r.isPublic = true')
            ->setParameter('user', $user)
            ->groupBy('r.reportType')
            ->getQuery()
            ->getResult();
        
        $typeStats = [];
        foreach ($byType as $row) {
            $typeStats[$row['reportType']] = (int) $row['count'];
        }
        
        return [
            'total' => $total,
            'myReports' => $myReports,
            'favorites' => $favorites,
            'bySource' => $sourceStats,
            'byType' => $typeStats,
        ];
    }
    
    /**
     * Duplicate a report
     */
    public function duplicate(ReportDefinition $report, User $newOwner, string $newName): ReportDefinition
    {
        $copy = new ReportDefinition();
        $copy->setName($newName)
             ->setDescription($report->getDescription())
             ->setReportType($report->getReportType())
             ->setDataSource($report->getDataSource())
             ->setColumns($report->getColumns())
             ->setFilters($report->getFilters())
             ->setGroupBy($report->getGroupBy())
             ->setOrderBy($report->getOrderBy())
             ->setChartConfig($report->getChartConfig())
             ->setDateRangePreset($report->getDateRangePreset())
             ->setDateField($report->getDateField())
             ->setRecordLimit($report->getRecordLimit())
             ->setCategory($report->getCategory())
             ->setCreatedBy($newOwner)
             ->setIsPublic(false)
             ->setIsFavorite(false);
        
        $this->save($copy, true);
        
        return $copy;
    }
}
