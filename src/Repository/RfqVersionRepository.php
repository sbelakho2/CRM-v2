<?php

namespace App\Repository;

use App\Entity\RfqVersion;
use App\Entity\RFQ;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RfqVersion>
 */
class RfqVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RfqVersion::class);
    }
    
    /**
     * Find all versions for an RFQ
     *
     * @return list<RfqVersion>
     */
    public function findByRfq(RFQ $rfq): array
    {
        /** @var list<RfqVersion> $versions */
        $versions = $this->createQueryBuilder('v')
            ->where('v.rfq = :rfq')
            ->setParameter('rfq', $rfq)
            ->orderBy('v.versionNumber', 'DESC')
            ->getQuery()
            ->getResult();

        return $versions;
    }
    
    /**
     * Get the latest version for an RFQ
     */
    public function findLatestVersion(RFQ $rfq): ?RfqVersion
    {
        /** @var RfqVersion|null $version */
        $version = $this->createQueryBuilder('v')
            ->where('v.rfq = :rfq')
            ->setParameter('rfq', $rfq)
            ->orderBy('v.versionNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $version;
    }
    
    /**
     * Get the next version number for an RFQ
     */
    public function getNextVersionNumber(RFQ $rfq): int
    {
        $result = $this->createQueryBuilder('v')
            ->select('MAX(v.versionNumber)')
            ->where('v.rfq = :rfq')
            ->setParameter('rfq', $rfq)
            ->getQuery()
            ->getSingleScalarResult();
        
        return ((int) $result) + 1;
    }
    
    /**
     * Get version history summary for an RFQ
     *
     * @return array<int, array<string, mixed>>
     */
    public function getVersionHistory(RFQ $rfq): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->createQueryBuilder('v')
            ->select('v.versionNumber', 'v.revisionCode', 'v.status', 'v.estimatedValue', 'v.createdAt', 'v.createdBy', 'v.revisionReason')
            ->where('v.rfq = :rfq')
            ->setParameter('rfq', $rfq)
            ->orderBy('v.versionNumber', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
