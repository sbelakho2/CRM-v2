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
     *
     * @return list<ReportDefinition>
     */
    public function findAccessibleByUser(User $user, ?string $dataSource = null, ?string $category = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.createdBy', 'u')
            ->orderBy('r.isFavorite', 'DESC')
            ->addOrderBy('r.name', 'ASC');
        
        // Access control — the SAME predicate canUserAccess() enforces:
        // user's own reports, public reports, OR role-granted access
        // (previously role-shared reports were reachable directly but never
        // appeared in lists).
        // Base predicate in DQL; role-granted rows are merged in PHP below
        // (JSON columns cannot be LIKE-matched portably in DQL).
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

        /** @var list<ReportDefinition> $results */
        $results = $qb->getQuery()->getResult();

        // Merge role-granted rows (accessRoles JSON contains one of the
        // user's roles) that the base predicate missed — matching
        // canUserAccess() exactly. JSON containment is evaluated in PHP for
        // portability.
        $roles = array_flip($user->getRoles());
        $existing = [];
        foreach ($results as $report) {
            $existing[$report->getId() ?? 0] = true;
        }
        /** @var list<ReportDefinition> $roleGranted */
        $roleGranted = $this->createQueryBuilder('r2')
            ->andWhere('r2.accessRoles IS NOT NULL')
            ->getQuery()->getResult();
        foreach ($roleGranted as $report) {
            if (isset($existing[$report->getId() ?? 0])) {
                continue;
            }
            foreach ($report->getAccessRoles() ?? [] as $grantedRole) {
                if (is_string($grantedRole) && isset($roles[$grantedRole])) {
                    $results[] = $report;
                    break;
                }
            }
        }

        usort($results, static fn (ReportDefinition $a, ReportDefinition $b) => [$b->isFavorite(), $a->getName()] <=> [$a->isFavorite(), $b->getName()]);

        return $results;
    }
    
    /**
     * Find user's favorite reports
     *
     * @return list<ReportDefinition>
     */
    public function findFavorites(User $user): array
    {
        /** @var list<ReportDefinition> $reports */
        $reports = $this->createQueryBuilder('r')
            ->andWhere('r.createdBy = :user OR r.isPublic = true')
            ->andWhere('r.isFavorite = true')
            ->setParameter('user', $user)
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $reports;
    }

    /**
     * Find recent reports for a user
     *
     * @return list<ReportDefinition>
     */
    public function findRecent(User $user, int $limit = 10): array
    {
        /** @var list<ReportDefinition> $reports */
        $reports = $this->createQueryBuilder('r')
            ->andWhere('r.createdBy = :user')
            ->andWhere('r.lastRunAt IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('r.lastRunAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $reports;
    }

    /**
     * Find most popular reports
     *
     * @return list<ReportDefinition>
     */
    public function findPopular(int $limit = 10): array
    {
        /** @var list<ReportDefinition> $reports */
        $reports = $this->createQueryBuilder('r')
            ->andWhere('r.isPublic = true')
            ->andWhere('r.runCount > 0')
            ->orderBy('r.runCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $reports;
    }

    /**
     * Find reports by data source
     *
     * @return list<ReportDefinition>
     */
    public function findByDataSource(string $dataSource): array
    {
        /** @var list<ReportDefinition> $reports */
        $reports = $this->createQueryBuilder('r')
            ->andWhere('r.dataSource = :source')
            ->setParameter('source', $dataSource)
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $reports;
    }

    /**
     * Get unique categories
     *
     * @return array<int, string>
     */
    public function findAllCategories(): array
    {
        /** @var list<array{category: string}> $result */
        $result = $this->createQueryBuilder('r')
            ->select('DISTINCT r.category')
            ->andWhere('r.category IS NOT NULL')
            ->orderBy('r.category', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_values(array_filter(array_column($result, 'category')));
    }

    /**
     * Find reports with scheduled delivery
     *
     * @return list<ReportDefinition>
     */
    public function findScheduledReports(): array
    {
        /** @var list<ReportDefinition> $results */
        $results = $this->createQueryBuilder('r')
            ->andWhere('r.scheduledDelivery IS NOT NULL')
            ->getQuery()
            ->getResult();

        // TODO: Move this filter to DQL when multi-DB support is added. Use JSON_LENGTH
        // or JSON_CONTAINS for MySQL, jsonb_array_length for PostgreSQL.
        return array_values(array_filter($results, static function (ReportDefinition $r): bool {
            $scheduled = $r->getScheduledDelivery();
            return is_array($scheduled) && count($scheduled) > 0;
        }));
    }

    /**
     * Search reports by name/description
     *
     * @return list<ReportDefinition>
     */
    public function search(string $query, User $user): array
    {
        /** @var list<ReportDefinition> $reports */
        $reports = $this->createQueryBuilder('r')
            ->andWhere('r.createdBy = :user OR r.isPublic = true')
            ->andWhere('r.name LIKE :query OR r.description LIKE :query')
            ->setParameter('user', $user)
            ->setParameter('query', '%' . addcslashes($query, '%_') . '%')
            ->orderBy('r.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $reports;
    }
    
    /**
     * Get statistics
     *
     * @return array{total: int, myReports: int, favorites: int, bySource: array<string, int>, byType: array<string, int>}
     */
    public function getStatistics(User $user): array
    {
        /** @var list<array{total: int|string, myReports: int|string, favorites: int|string, dataSource: string|null, reportType: string|null}> $result */
        $result = $this->createQueryBuilder('r')
            ->select('
                COUNT(r.id) as total,
                SUM(CASE WHEN r.createdBy = :user THEN 1 ELSE 0 END) as myReports,
                SUM(CASE WHEN r.isFavorite = true THEN 1 ELSE 0 END) as favorites,
                r.dataSource,
                r.reportType
            ')
            ->andWhere('r.createdBy = :user2 OR r.isPublic = true')
            ->setParameter('user', $user)
            ->setParameter('user2', $user)
            ->groupBy('r.dataSource, r.reportType')
            ->getQuery()
            ->getResult();

        $total = 0;
        $myReports = 0;
        $favorites = 0;
        /** @var array<string, int> $sourceStats */
        $sourceStats = [];
        /** @var array<string, int> $typeStats */
        $typeStats = [];

        foreach ($result as $row) {
            $total += (int) $row['total'];
            $myReports += (int) $row['myReports'];
            $favorites += (int) $row['favorites'];
            $source = $row['dataSource'];
            // The query groups by (dataSource, reportType): each row carries
            // a COUNT for its whole group — SUM the counts, never ++
            // (two reports in the same group previously counted once).
            if (is_string($source) && $source !== '') {
                $sourceStats[$source] = ($sourceStats[$source] ?? 0) + (int) $row['total'];
            }
            $rType = $row['reportType'];
            if (is_string($rType) && $rType !== '') {
                $typeStats[$rType] = ($typeStats[$rType] ?? 0) + (int) $row['total'];
            }
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
             ->setReportType($report->getReportType() ?? '')
             ->setDataSource($report->getDataSource() ?? '')
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
