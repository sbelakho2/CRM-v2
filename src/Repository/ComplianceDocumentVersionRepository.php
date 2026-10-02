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
     *
     * @return list<ComplianceDocumentVersion>
     */
    public function findByDocument(ComplianceDocument $document): array
    {
        /** @var list<ComplianceDocumentVersion> $results */
        $results = $this->createQueryBuilder('v')
            ->where('v.document = :document')
            ->setParameter('document', $document)
            ->orderBy('v.versionNumber', 'DESC')
            ->getQuery()
            ->getResult();

        return $results;
    }
    
    /**
     * Get the current version of a document
     */
    public function findCurrentVersion(ComplianceDocument $document): ?ComplianceDocumentVersion
    {
        /** @var ComplianceDocumentVersion|null $result */
        $result = $this->createQueryBuilder('v')
            ->where('v.document = :document')
            ->andWhere('v.isCurrent = true')
            ->setParameter('document', $document)
            ->orderBy('v.versionNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result;
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
     *
     * @return array<int, array<string, mixed>>
     */
    public function getVersionHistory(ComplianceDocument $document): array
    {
        /** @var array<int, array<string, mixed>> $results */
        $results = $this->createQueryBuilder('v')
            ->select('v.versionNumber', 'v.fileName', 'v.status', 'v.uploadedAt', 'v.uploadedBy', 'v.approvedBy', 'v.approvedAt', 'v.isCurrent')
            ->where('v.document = :document')
            ->setParameter('document', $document)
            ->orderBy('v.versionNumber', 'DESC')
            ->getQuery()
            ->getResult();

        return $results;
    }
}
