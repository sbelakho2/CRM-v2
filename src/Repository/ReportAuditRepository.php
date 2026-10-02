<?php

namespace App\Repository;

use App\Entity\ReportAudit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReportAudit>
 */
class ReportAuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReportAudit::class);
    }

    /**
     * Find audit records for a specific entity
          *
     * @return list<ReportAudit>
     */
    public function findByEntity(string $entityType, int $entityId): array
    {
        /** @var list<ReportAudit> $results */
        $results = $this->createQueryBuilder('r')
            ->where('r.entityType = :entityType')
            ->andWhere('r.entityId = :entityId')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->orderBy('r.generatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Find audit records by report type
          *
     * @return list<ReportAudit>
     */
    public function findByReportType(string $reportType): array
    {
        /** @var list<ReportAudit> $results */
        $results = $this->createQueryBuilder('r')
            ->where('r.reportType = :reportType')
            ->setParameter('reportType', $reportType)
            ->orderBy('r.generatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $results;
    }

    /**
     * Verify SHA-256 hash integrity
          *
     * @return ReportAudit|null
     */
    public function findByHash(string $sha256Hash): ?ReportAudit
    {
        /** @var ReportAudit|null $result */
        $result = $this->createQueryBuilder('r')
            ->where('r.sha256Hash = :hash')
            ->setParameter('hash', $sha256Hash)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
    }
}
