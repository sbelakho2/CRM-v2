<?php

namespace App\Entity;

use App\Repository\ComplianceDocumentVersionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Compliance Document Version Entity
 * 
 * Tracks historical versions of compliance documents:
 * - Maintains audit trail of document updates
 * - Records who uploaded each version
 * - Captures approval status changes
 */
#[ORM\Entity(repositoryClass: ComplianceDocumentVersionRepository::class)]
#[ORM\Table(name: 'compliance_document_versions')]
class ComplianceDocumentVersion
{
    public const STATUS_PENDING = 'Pending';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_REJECTED = 'Rejected';
    public const STATUS_SUPERSEDED = 'Superseded';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    
    #[ORM\ManyToOne(targetEntity: ComplianceDocument::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ComplianceDocument $document = null;
    
    #[ORM\Column(type: 'integer')]
    private int $versionNumber = 1;
    
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fileName = null;
    
    #[ORM\Column(nullable: true)]
    private ?int $fileSize = null;
    
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $mimeType = null;
    
    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $expiryDate = null;
    
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $status = null; // Pending, Approved, Rejected, Superseded
    
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;
    
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $uploadedBy = null;
    
    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $uploadedAt = null;
    
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $approvedBy = null;
    
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $approvedAt = null;
    
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rejectionReason = null;
    
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isCurrent = false;
    
    public function __construct()
    {
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->uploadedAt === null) {
            $this->uploadedAt = new \DateTime();
        }
    }
    
    // Getters and Setters
    
    public function getId(): ?int
    {
        return $this->id;
    }
    
    public function getDocument(): ?ComplianceDocument
    {
        return $this->document;
    }
    
    public function setDocument(?ComplianceDocument $document): self
    {
        $this->document = $document;
        return $this;
    }
    
    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }
    
    public function setVersionNumber(int $versionNumber): self
    {
        $this->versionNumber = $versionNumber;
        return $this;
    }
    
    public function getFileName(): ?string
    {
        return $this->fileName;
    }
    
    public function setFileName(?string $fileName): self
    {
        $this->fileName = $fileName;
        return $this;
    }
    
    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }
    
    public function setFileSize(?int $fileSize): self
    {
        $this->fileSize = $fileSize;
        return $this;
    }
    
    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }
    
    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;
        return $this;
    }
    
    public function getExpiryDate(): ?\DateTimeInterface
    {
        return $this->expiryDate;
    }
    
    public function setExpiryDate(?\DateTimeInterface $expiryDate): self
    {
        $this->expiryDate = $expiryDate;
        return $this;
    }
    
    public function getStatus(): ?string
    {
        return $this->status;
    }
    
    public function setStatus(?string $status): self
    {
        $this->status = $status;
        return $this;
    }
    
    public function getNotes(): ?string
    {
        return $this->notes;
    }
    
    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }
    
    public function getUploadedBy(): ?string
    {
        return $this->uploadedBy;
    }
    
    public function setUploadedBy(?string $uploadedBy): self
    {
        $this->uploadedBy = $uploadedBy;
        return $this;
    }
    
    public function getUploadedAt(): ?\DateTimeInterface
    {
        return $this->uploadedAt;
    }
    
    public function getApprovedBy(): ?string
    {
        return $this->approvedBy;
    }
    
    public function setApprovedBy(?string $approvedBy): self
    {
        $this->approvedBy = $approvedBy;
        return $this;
    }
    
    public function getApprovedAt(): ?\DateTimeInterface
    {
        return $this->approvedAt;
    }
    
    public function setApprovedAt(?\DateTimeInterface $approvedAt): self
    {
        $this->approvedAt = $approvedAt;
        return $this;
    }
    
    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }
    
    public function setRejectionReason(?string $rejectionReason): self
    {
        $this->rejectionReason = $rejectionReason;
        return $this;
    }
    
    public function isCurrent(): bool
    {
        return $this->isCurrent;
    }
    
    public function setIsCurrent(bool $isCurrent): self
    {
        $this->isCurrent = $isCurrent;
        return $this;
    }
    
    /**
     * Mark this version as approved
     */
    public function approve(string $approvedBy): self
    {
        $this->status = self::STATUS_APPROVED;
        $this->approvedBy = $approvedBy;
        $this->approvedAt = new \DateTime();
        return $this;
    }

    /**
     * Mark this version as rejected
     */
    public function reject(string $rejectedBy, string $reason): self
    {
        $this->status = self::STATUS_REJECTED;
        $this->approvedBy = $rejectedBy;
        $this->approvedAt = new \DateTime();
        $this->rejectionReason = $reason;
        return $this;
    }

    /**
     * Mark as superseded (newer version available)
     */
    public function supersede(): self
    {
        $this->status = self::STATUS_SUPERSEDED;
        $this->isCurrent = false;
        return $this;
    }
    
    public function getUploadedAtFormatted(): ?string
    {
        return $this->uploadedAt?->format('Y-m-d');
    }

    /**
     * Get display label
     */
    public function getDisplayLabel(): string
    {
        return sprintf('v%d - %s', $this->versionNumber, $this->uploadedAt?->format('Y-m-d') ?? 'N/A');
    }
}
