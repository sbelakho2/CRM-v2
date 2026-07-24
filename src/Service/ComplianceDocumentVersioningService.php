<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceDocument;
use App\Entity\ComplianceDocumentVersion;
use App\Repository\ComplianceDocumentVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Compliance Document Versioning Service
 * 
 * Manages version history for compliance documents:
 * - Creates versions when documents are uploaded or updated
 * - Tracks approval workflow
 * - Maintains audit trail
 */
class ComplianceDocumentVersioningService
{
    private EntityManagerInterface $entityManager;
    private ComplianceDocumentVersionRepository $versionRepository;
    private LoggerInterface $logger;
    
    public function __construct(
        EntityManagerInterface $entityManager,
        ComplianceDocumentVersionRepository $versionRepository,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->versionRepository = $versionRepository;
        $this->logger = $logger;
    }
    
    /**
     * Create a new version when a document is uploaded
     */
    public function createVersion(
        ComplianceDocument $document,
        string $fileName,
        int $fileSize,
        ?string $mimeType = null,
        ?string $uploadedBy = null,
        ?\DateTimeInterface $expiryDate = null,
        ?string $notes = null
    ): ComplianceDocumentVersion {
        // Mark previous current version as superseded
        $currentVersion = $this->versionRepository->findCurrentVersion($document);
        if ($currentVersion) {
            $currentVersion->supersede();
        }
        
        // Create new version
        $version = new ComplianceDocumentVersion();
        $version->setDocument($document);
        $version->setVersionNumber($this->versionRepository->getNextVersionNumber($document));
        $version->setFileName($fileName);
        $version->setFileSize($fileSize);
        $version->setMimeType($mimeType);
        $version->setExpiryDate($expiryDate);
        $version->setUploadedBy($uploadedBy);
        $version->setNotes($notes);
        $version->setStatus('Pending');
        $version->setIsCurrent(true);
        
        $this->entityManager->persist($version);
        $this->entityManager->flush();
        
        $this->logger->info('New compliance document version created', [
            'document' => $document->getName(),
            'company' => $document->getCompany()?->getName(),
            'version' => $version->getVersionNumber(),
            'uploadedBy' => $uploadedBy,
        ]);
        
        return $version;
    }
    
    /**
     * Approve a document version
     */
    public function approveVersion(ComplianceDocumentVersion $version, string $approvedBy): void
    {
        $version->approve($approvedBy);
        
        // Update the parent document status
        $document = $version->getDocument();
        if ($document) {
            $document->setStatus('Approved');
        }
        
        $this->entityManager->flush();
        
        $this->logger->info('Compliance document version approved', [
            'document' => $version->getDocument()?->getName(),
            'version' => $version->getVersionNumber(),
            'approvedBy' => $approvedBy,
        ]);
    }
    
    /**
     * Reject a document version
     */
    public function rejectVersion(
        ComplianceDocumentVersion $version,
        string $rejectedBy,
        string $reason
    ): void {
        $version->reject($rejectedBy, $reason);
        $version->setIsCurrent(false);
        
        // Revert to previous approved version if exists
        $previousApproved = $this->findPreviousApprovedVersion($version);
        if ($previousApproved) {
            $previousApproved->setIsCurrent(true);
        } else {
            // Update parent document to reflect rejection
            $document = $version->getDocument();
            if ($document) {
                $document->setStatus('Rejected');
            }
        }
        
        $this->entityManager->flush();
        
        $this->logger->info('Compliance document version rejected', [
            'document' => $version->getDocument()?->getName(),
            'version' => $version->getVersionNumber(),
            'rejectedBy' => $rejectedBy,
            'reason' => $reason,
        ]);
    }
    
    /**
     * Get all versions for a document
     */
    public function getVersions(ComplianceDocument $document): array
    {
        return $this->versionRepository->findByDocument($document);
    }
    
    /**
     * Get version history (summary)
     */
    public function getVersionHistory(ComplianceDocument $document): array
    {
        return $this->versionRepository->getVersionHistory($document);
    }
    
    /**
     * Get current version
     */
    public function getCurrentVersion(ComplianceDocument $document): ?ComplianceDocumentVersion
    {
        return $this->versionRepository->findCurrentVersion($document);
    }
    
    /**
     * Rollback to a specific version
     */
    public function rollbackToVersion(ComplianceDocumentVersion $targetVersion, string $performedBy): void
    {
        $document = $targetVersion->getDocument();
        if (!$document) {
            throw new \InvalidArgumentException('Version has no associated document');
        }
        
        // Mark current version as superseded
        $currentVersion = $this->versionRepository->findCurrentVersion($document);
        if ($currentVersion) {
            $currentVersion->setIsCurrent(false);
        }
        
        // Create a new version based on the target (copy)
        $newVersion = new ComplianceDocumentVersion();
        $newVersion->setDocument($document);
        $newVersion->setVersionNumber($this->versionRepository->getNextVersionNumber($document));
        $newVersion->setFileName($targetVersion->getFileName());
        $newVersion->setFileSize($targetVersion->getFileSize());
        $newVersion->setMimeType($targetVersion->getMimeType());
        $newVersion->setExpiryDate($targetVersion->getExpiryDate());
        $newVersion->setUploadedBy($performedBy);
        $newVersion->setNotes(sprintf('Rolled back from v%d by %s', $targetVersion->getVersionNumber(), $performedBy));
        $newVersion->setStatus('Pending');
        $newVersion->setIsCurrent(true);
        
        $this->entityManager->persist($newVersion);
        $this->entityManager->flush();
        
        $this->logger->info('Compliance document rolled back to previous version', [
            'document' => $document->getName(),
            'from_version' => $currentVersion?->getVersionNumber(),
            'to_version' => $targetVersion->getVersionNumber(),
            'new_version' => $newVersion->getVersionNumber(),
            'performedBy' => $performedBy,
        ]);
    }
    
    /**
     * Compare two versions
     */
    public function compareVersions(
        ComplianceDocumentVersion $older,
        ComplianceDocumentVersion $newer
    ): array {
        return [
            'older' => [
                'version' => $older->getVersionNumber(),
                'fileName' => $older->getFileName(),
                'uploadedAt' => $older->getUploadedAt(),
                'uploadedBy' => $older->getUploadedBy(),
                'expiryDate' => $older->getExpiryDate(),
                'status' => $older->getStatus(),
            ],
            'newer' => [
                'version' => $newer->getVersionNumber(),
                'fileName' => $newer->getFileName(),
                'uploadedAt' => $newer->getUploadedAt(),
                'uploadedBy' => $newer->getUploadedBy(),
                'expiryDate' => $newer->getExpiryDate(),
                'status' => $newer->getStatus(),
            ],
            'changes' => [
                'file_changed' => $older->getFileName() !== $newer->getFileName(),
                'expiry_changed' => $older->getExpiryDate()?->format('Y-m-d') !== $newer->getExpiryDate()?->format('Y-m-d'),
                'days_between' => $older->getUploadedAt() && $newer->getUploadedAt()
                    ? $older->getUploadedAt()->diff($newer->getUploadedAt())->days
                    : null,
            ],
        ];
    }
    
    // Private helpers
    
    private function findPreviousApprovedVersion(ComplianceDocumentVersion $version): ?ComplianceDocumentVersion
    {
        $document = $version->getDocument();
        if (!$document) {
            return null;
        }
        
        $versions = $this->versionRepository->findByDocument($document);
        
        foreach ($versions as $v) {
            if ($v->getId() !== $version->getId() 
                && $v->getStatus() === 'Approved'
                && $v->getVersionNumber() < $version->getVersionNumber()
            ) {
                return $v;
            }
        }
        
        return null;
    }
}
