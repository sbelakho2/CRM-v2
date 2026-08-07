<?php

namespace App\Entity;

use App\Repository\ComplianceDocumentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: ComplianceDocumentRepository::class)]
#[ORM\Table(name: 'compliance_documents')]
#[Vich\Uploadable]
class ComplianceDocument
{
    public const STATUS_PENDING = 'Pending';
    public const STATUS_APPROVED = 'Approved';
    public const STATUS_REJECTED = 'Rejected';
    public const STATUS_EXPIRED = 'Expired';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class, inversedBy: 'complianceDocuments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Company $company = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null; // ISO 9001, IATF 16949, etc.

    #[ORM\Column(type: 'boolean')]
    private bool $required = true;

    #[ORM\Column(type: 'boolean')]
    private bool $provided = false;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $status = null; // Pending, Approved, Rejected, Expired

    #[Vich\UploadableField(mapping: 'compliance_documents', fileNameProperty: 'fileName', size: 'fileSize')]
    private ?File $file = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fileName = null;

    #[ORM\Column(nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $expiryDate = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $uploadedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;
    
    /**
     * Snooze alerts until this date.
     * When set, this document's alerts will be suppressed until the snooze date passes.
     */
    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $snoozedUntil = null;
    
    /**
     * Reason for snoozing (e.g., "Renewal in progress", "Waiting for supplier")
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $snoozeReason = null;
    
    /**
     * Who snoozed the alert
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $snoozedBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): self
    {
        $this->company = $company;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
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

    public function setFile(?File $file = null): void
    {
        $this->file = $file;

        if (null !== $file) {
            $this->updatedAt = new \DateTime();
        }
    }

    public function getFile(): ?File
    {
        return $this->file;
    }

    public function setFileName(?string $fileName): void
    {
        $this->fileName = $fileName;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileSize(?int $fileSize): void
    {
        $this->fileSize = $fileSize;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
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

    public function getUploadedAt(): ?\DateTimeInterface
    {
        return $this->uploadedAt;
    }

    public function setUploadedAt(?\DateTimeInterface $uploadedAt): self
    {
        $this->uploadedAt = $uploadedAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function getDocumentType(): ?string
    {
        return $this->name;
    }

    public function setDocumentType(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): self
    {
        $this->required = $required;
        return $this;
    }

    public function isProvided(): bool
    {
        return $this->provided;
    }

    public function setProvided(bool $provided): self
    {
        $this->provided = $provided;
        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->fileName;
    }

    public function setFilePath(?string $filePath): self
    {
        $this->fileName = $filePath;
        return $this;
    }
    
    // ============================================================
    // EXPIRY ALERTING METHODS
    // ============================================================
    
    /**
     * Check if document has expired
     */
    public function isExpired(): bool
    {
        return self::isExpiredStatic($this->expiryDate);
    }

    public static function isExpiredStatic(?\DateTimeInterface $expiryDate): bool
    {
        if ($expiryDate === null) {
            return false;
        }
        return $expiryDate < new \DateTime('today');
    }

    /**
     * Check if document is expiring soon (within specified days)
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        return self::isExpiringSoonStatic($this->expiryDate, $days);
    }

    public static function isExpiringSoonStatic(?\DateTimeInterface $expiryDate, int $days = 30): bool
    {
        if ($expiryDate === null) {
            return false;
        }
        $today = new \DateTime('today');
        if ($expiryDate < $today) {
            return false;
        }
        $warningDate = (clone $today)->modify("+{$days} days");
        return $expiryDate <= $warningDate;
    }
    
    /**
     * Get days until expiry (negative if expired)
     */
    public function getDaysUntilExpiry(): ?int
    {
        if ($this->expiryDate === null) {
            return null;
        }
        
        $today = new \DateTime('today');
        $diff = $today->diff($this->expiryDate);
        
        return $diff->invert ? -$diff->days : $diff->days;
    }
    
    /**
     * Get expiry status as string
     */
    public function getExpiryStatus(): string
    {
        if ($this->expiryDate === null) {
            return 'no_expiry';
        }
        
        if ($this->isExpired()) {
            return 'expired';
        }
        
        $days = $this->getDaysUntilExpiry();
        
        if ($days <= 7) {
            return 'critical';  // Expiring within 7 days
        } elseif ($days <= 30) {
            return 'warning';   // Expiring within 30 days
        } elseif ($days <= 90) {
            return 'caution';   // Expiring within 90 days
        }
        
        return 'valid';
    }
    
    /**
     * Get human-readable expiry message
     */
    public function getExpiryMessage(): string
    {
        if ($this->expiryDate === null) {
            return 'No expiry date set';
        }
        
        $days = $this->getDaysUntilExpiry();
        
        if ($days < 0) {
            return sprintf('Expired %d days ago', abs($days));
        } elseif ($days === 0) {
            return 'Expires today';
        } elseif ($days === 1) {
            return 'Expires tomorrow';
        } else {
            return sprintf('Expires in %d days', $days);
        }
    }
    
    // ============================================================
    // SNOOZE FUNCTIONALITY
    // ============================================================
    
    /**
     * Check if alerts for this document are currently snoozed
     */
    public function isSnoozed(): bool
    {
        if ($this->snoozedUntil === null) {
            return false;
        }
        return $this->snoozedUntil >= new \DateTime('today');
    }
    
    /**
     * Snooze alerts until a specified date
     * 
     * @param int $days Number of days to snooze (default: 7)
     * @param string|null $reason Optional reason for snoozing
     * @param string|null $snoozedBy User who initiated the snooze
     */
    public function snooze(int $days = 7, ?string $reason = null, ?string $snoozedBy = null): self
    {
        $this->snoozedUntil = (new \DateTime())->modify("+{$days} days");
        $this->snoozeReason = $reason;
        $this->snoozedBy = $snoozedBy;
        return $this;
    }
    
    /**
     * Clear the snooze, re-enabling alerts
     */
    public function clearSnooze(): self
    {
        $this->snoozedUntil = null;
        $this->snoozeReason = null;
        $this->snoozedBy = null;
        return $this;
    }
    
    public function getSnoozedUntil(): ?\DateTimeInterface
    {
        return $this->snoozedUntil;
    }
    
    public function setSnoozedUntil(?\DateTimeInterface $snoozedUntil): self
    {
        $this->snoozedUntil = $snoozedUntil;
        return $this;
    }
    
    public function getSnoozeReason(): ?string
    {
        return $this->snoozeReason;
    }
    
    public function setSnoozeReason(?string $snoozeReason): self
    {
        $this->snoozeReason = $snoozeReason;
        return $this;
    }
    
    public function getSnoozedBy(): ?string
    {
        return $this->snoozedBy;
    }
    
    public function setSnoozedBy(?string $snoozedBy): self
    {
        $this->snoozedBy = $snoozedBy;
        return $this;
    }
    
    /**
     * Get days until snooze expires (negative if expired)
     */
    public function getDaysUntilSnoozeExpires(): ?int
    {
        if ($this->snoozedUntil === null) {
            return null;
        }
        
        $today = new \DateTime('today');
        $diff = $today->diff($this->snoozedUntil);
        
        return $diff->invert ? -$diff->days : $diff->days;
    }
    
    /**
     * Check if document needs renewal attention
     * Considers snooze status, provided status, and expiry
     * 
     * @param bool $respectSnooze If true, snoozed documents don't need attention
     */
    public function needsAttention(bool $respectSnooze = true): bool
    {
        // If snoozed and we're respecting snooze, no attention needed
        if ($respectSnooze && $this->isSnoozed()) {
            return false;
        }
        
        // Required but not provided
        if ($this->required && !$this->provided) {
            return true;
        }
        
        // Expired or expiring soon
        if ($this->isExpired() || $this->isExpiringSoon()) {
            return true;
        }
        
        // Status is rejected or expired
        if (in_array($this->status, ['Rejected', 'Expired'], true)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if document has an underlying issue (ignoring snooze)
     * Useful for showing that a snoozed item still has problems
     */
    public function hasUnderlyingIssue(): bool
    {
        return $this->needsAttention(false);
    }
}
