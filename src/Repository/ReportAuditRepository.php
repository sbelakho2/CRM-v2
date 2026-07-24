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
     */
    public function findByEntity(string $entityType, int $entityId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.entityType = :entityType')
            ->andWhere('r.entityId = :entityId')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->orderBy('r.generatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find audit records by report type
     */
    public function findByReportType(string $reportType): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.reportType = :reportType')
            ->setParameter('reportType', $reportType)
            ->orderBy('r.generatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Verify SHA-256 hash integrity
     */
    public function findByHash(string $sha256Hash): ?ReportAudit
    {
        return $this->createQueryBuilder('r')
            ->where('r.sha256Hash = :hash')
            ->setParameter('hash', $sha256Hash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
