<?php

namespace App\Repository;

use App\Entity\ComplianceDocumentVersion;
use App\Entity\ComplianceDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ComplianceDocumentVersion>
 */
class ComplianceDocumentVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComplianceDocumentVersion::class);
    }
    
    /**
     * Find all versions for a document
     */
    public function findByDocument(ComplianceDocument $document): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.document = :document')
            ->setParameter('document', $document)
            ->orderBy('v.versionNumber', 'DESC')
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Get the current version of a document
     */
    public function findCurrentVersion(ComplianceDocument $document): ?ComplianceDocumentVersion
    {
        return $this->createQueryBuilder('v')
            ->where('v.document = :document')
            ->andWhere('v.isCurrent = true')
            ->setParameter('document', $document)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
    
    /**
     * Get the latest version number for a document
     */
    public function getNextVersionNumber(ComplianceDocument $document): int
    {
        $result = $this->createQueryBuilder('v')
            ->select('MAX(v.versionNumber)')
            ->where('v.document = :document')
            ->setParameter('document', $document)
            ->getQuery()
            ->getSingleScalarResult();
        
        return ((int) $result) + 1;
    }
    
    /**
     * Get version history summary for a document
     */
    public function getVersionHistory(ComplianceDocument $document): array
    {
        return $this->createQueryBuilder('v')
            ->select('v.versionNumber', 'v.fileName', 'v.status', 'v.uploadedAt', 'v.uploadedBy', 'v.approvedBy', 'v.approvedAt', 'v.isCurrent')
            ->where('v.document = :document')
            ->setParameter('document', $document)
            ->orderBy('v.versionNumber', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
