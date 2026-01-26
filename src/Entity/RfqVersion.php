<?php

namespace App\Entity;

use App\Repository\RfqVersionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * RFQ Version Entity
 * 
 * Tracks versions/revisions of RFQs for audit trail:
 * - Captures snapshots when quotes are revised
 * - Records reason for revision
 * - Maintains complete history
 */
#[ORM\Entity(repositoryClass: RfqVersionRepository::class)]
#[ORM\Table(name: 'rfq_versions')]
class RfqVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    
    #[ORM\ManyToOne(targetEntity: RFQ::class, inversedBy: 'versions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?RFQ $rfq = null;
    
    #[ORM\Column(type: 'integer')]
    private int $versionNumber = 1;
    
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $revisionCode = null; // A, B, C or 1.0, 1.1, 2.0
    
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $revisionReason = null;
    
    #[ORM\Column(length: 50)]
    private ?string $status = 'Draft'; // Draft, Submitted, Superseded
    
    // Snapshot of RFQ data at time of version
    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $estimatedValue = null;
    
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $lineItemsSnapshot = null;
    
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $technicalScope = null;
    
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;
    
    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $validUntil = null;
    
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $createdBy = null;
    
    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;
    
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $submittedAt = null;
    
    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }
    
    // Getters and Setters
    
    public function getId(): ?int
    {
        return $this->id;
    }
    
    public function getRfq(): ?RFQ
    {
        return $this->rfq;
    }
    
    public function setRfq(?RFQ $rfq): self
    {
        $this->rfq = $rfq;
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
    
    public function getRevisionCode(): ?string
    {
        return $this->revisionCode;
    }
    
    public function setRevisionCode(?string $revisionCode): self
    {
        $this->revisionCode = $revisionCode;
        return $this;
    }
    
    public function getRevisionReason(): ?string
    {
        return $this->revisionReason;
    }
    
    public function setRevisionReason(?string $revisionReason): self
    {
        $this->revisionReason = $revisionReason;
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
    
    public function getEstimatedValue(): ?string
    {
        return $this->estimatedValue;
    }
    
    public function setEstimatedValue(?string $estimatedValue): self
    {
        $this->estimatedValue = $estimatedValue;
        return $this;
    }
    
    public function getLineItemsSnapshot(): ?array
    {
        return $this->lineItemsSnapshot;
    }
    
    public function setLineItemsSnapshot(?array $lineItemsSnapshot): self
    {
        $this->lineItemsSnapshot = $lineItemsSnapshot;
        return $this;
    }
    
    public function getTechnicalScope(): ?string
    {
        return $this->technicalScope;
    }
    
    public function setTechnicalScope(?string $technicalScope): self
    {
        $this->technicalScope = $technicalScope;
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
    
    public function getValidUntil(): ?\DateTimeInterface
    {
        return $this->validUntil;
    }
    
    public function setValidUntil(?\DateTimeInterface $validUntil): self
    {
        $this->validUntil = $validUntil;
        return $this;
    }
    
    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }
    
    public function setCreatedBy(?string $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }
    
    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
    
    public function getSubmittedAt(): ?\DateTimeInterface
    {
        return $this->submittedAt;
    }
    
    public function setSubmittedAt(?\DateTimeInterface $submittedAt): self
    {
        $this->submittedAt = $submittedAt;
        return $this;
    }
    
    /**
     * Generate display label for this version
     */
    public function getDisplayLabel(): string
    {
        if ($this->revisionCode) {
            return sprintf('Rev %s', $this->revisionCode);
        }
        return sprintf('v%d', $this->versionNumber);
    }
    
    /**
     * Check if this version is still valid
     */
    public function isValid(): bool
    {
        if (!$this->validUntil) {
            return true;
        }
        return $this->validUntil >= new \DateTime();
    }
    
    /**
     * Mark as submitted
     */
    public function submit(): self
    {
        $this->status = 'Submitted';
        $this->submittedAt = new \DateTime();
        return $this;
    }
    
    /**
     * Mark as superseded (by newer version)
     */
    public function supersede(): self
    {
        $this->status = 'Superseded';
        return $this;
    }
}
