<?php

namespace App\Entity;

use App\Repository\RFQRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RFQRepository::class)]
#[ORM\Table(name: 'rfqs')]
#[ORM\HasLifecycleCallbacks]
class RFQ
{
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $archivedAt = null;

    #[ORM\ManyToOne(targetEntity: \App\Entity\User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?\App\Entity\User $archivedBy = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $archiveReason = null;

    public const TYPE_NPI = 'NPI';
    public const TYPE_FRAMEWORK = 'Framework Agreement';
    public const TYPE_STANDARD = 'Standard RFQ';

    public const STATUS_PENDING = 'Pending';
    public const STATUS_SUBMITTED = 'Submitted';
    public const STATUS_WON = 'Won';
    public const STATUS_LOST = 'Lost';
    public const STATUS_IN_REVIEW = 'In Review';

    // Loss reason categories for competitive intelligence
    public const LOSS_REASON_PRICE = 'price';
    public const LOSS_REASON_LEAD_TIME = 'lead_time';
    public const LOSS_REASON_TECHNICAL = 'technical_capability';
    public const LOSS_REASON_QUALITY = 'quality_certification';
    public const LOSS_REASON_RELATIONSHIP = 'existing_relationship';
    public const LOSS_REASON_LOCATION = 'location_preference';
    public const LOSS_REASON_CAPACITY = 'capacity_constraints';
    public const LOSS_REASON_NO_RESPONSE = 'no_response';
    public const LOSS_REASON_CANCELLED = 'rfq_cancelled';
    public const LOSS_REASON_OTHER = 'other';
    
    public const LOSS_REASONS = [
        self::LOSS_REASON_PRICE,
        self::LOSS_REASON_LEAD_TIME,
        self::LOSS_REASON_TECHNICAL,
        self::LOSS_REASON_QUALITY,
        self::LOSS_REASON_RELATIONSHIP,
        self::LOSS_REASON_LOCATION,
        self::LOSS_REASON_CAPACITY,
        self::LOSS_REASON_NO_RESPONSE,
        self::LOSS_REASON_CANCELLED,
        self::LOSS_REASON_OTHER,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class, inversedBy: 'rfqs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Company $company = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Contact $contact = null;

    #[ORM\ManyToOne(targetEntity: Lead::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Lead $lead = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $rfqNumber = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $rfqDate = null;

    #[ORM\Column(length: 50)]
    private ?string $type = self::TYPE_STANDARD; // NPI, Framework Agreement, Standard RFQ

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $ndaSent = false;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $ndaDate = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $ndaExecuted = false;

    #[ORM\Column(length: 50)]
    private ?string $status = self::STATUS_PENDING; // Pending, Submitted, Won, Lost, In Review

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $estimatedValue = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $currency = 'EUR';

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $volumeAnnual = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $sopDate = null; // Start of Production

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $technicalScope = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;
    
    // ============================================================
    // WIN/LOSS COMPETITIVE INTELLIGENCE FIELDS
    // ============================================================
    
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $lossReason = null; // Standardized loss category
    
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lossReasonDetail = null; // Free text explanation
    
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $competitorWon = null; // Name of winning competitor
    
    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $winningBidAmount = null; // Competitor's winning price if known
    
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lessonsLearned = null; // What could we do better?
    
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $winFactors = null; // If won: key success factors
    
    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $decisionDate = null; // When was final decision made
    
    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $awardDate = null; // When was contract awarded (if won)

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'rfq', targetEntity: RfqLineItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lineItems;

    #[ORM\OneToMany(mappedBy: 'rfq', targetEntity: RfqVersion::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $versions;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->lineItems = new ArrayCollection();
        $this->versions = new ArrayCollection();
    }

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

    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    public function setContact(?Contact $contact): self
    {
        $this->contact = $contact;
        return $this;
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    public function setLead(?Lead $lead): self
    {
        $this->lead = $lead;
        return $this;
    }

    public function getRfqNumber(): ?string
    {
        return $this->rfqNumber;
    }

    public function setRfqNumber(?string $rfqNumber): self
    {
        $this->rfqNumber = $rfqNumber;
        return $this;
    }

    public function getRfqDate(): ?\DateTimeInterface
    {
        return $this->rfqDate;
    }

    public function setRfqDate(?\DateTimeInterface $rfqDate): self
    {
        $this->rfqDate = $rfqDate;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function isNdaSent(): bool
    {
        return $this->ndaSent;
    }

    public function setNdaSent(bool $ndaSent): self
    {
        $this->ndaSent = $ndaSent;
        return $this;
    }

    public function getNdaDate(): ?\DateTimeInterface
    {
        return $this->ndaDate;
    }

    public function setNdaDate(?\DateTimeInterface $ndaDate): self
    {
        $this->ndaDate = $ndaDate;
        return $this;
    }

    public function isNdaExecuted(): bool
    {
        return $this->ndaExecuted;
    }

    public function setNdaExecuted(bool $ndaExecuted): self
    {
        $this->ndaExecuted = $ndaExecuted;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
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

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): self
    {
        $this->currency = $currency ? strtoupper($currency) : null;
        return $this;
    }

    public function getVolumeAnnual(): ?int
    {
        return $this->volumeAnnual;
    }

    public function setVolumeAnnual(?int $volumeAnnual): self
    {
        $this->volumeAnnual = $volumeAnnual;
        return $this;
    }

    public function getSopDate(): ?\DateTimeInterface
    {
        return $this->sopDate;
    }

    public function setSopDate(?\DateTimeInterface $sopDate): self
    {
        $this->sopDate = $sopDate;
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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }
    
    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    /**
     * @return Collection<int, RfqLineItem>
     */
    public function getLineItems(): Collection
    {
        return $this->lineItems;
    }

    public function addLineItem(RfqLineItem $lineItem): self
    {
        if (!$this->lineItems->contains($lineItem)) {
            $this->lineItems->add($lineItem);
            $lineItem->setRfq($this);
        }
        return $this;
    }

    public function removeLineItem(RfqLineItem $lineItem): self
    {
        if ($this->lineItems->removeElement($lineItem)) {
            if ($lineItem->getRfq() === $this) {
                $lineItem->setRfq(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, RfqVersion>
     */
    public function getVersions(): Collection
    {
        return $this->versions;
    }

    public function addVersion(RfqVersion $version): self
    {
        if (!$this->versions->contains($version)) {
            $this->versions->add($version);
            $version->setRfq($this);
        }
        return $this;
    }

    public function removeVersion(RfqVersion $version): self
    {
        if ($this->versions->removeElement($version)) {
            if ($version->getRfq() === $this) {
                $version->setRfq(null);
            }
        }
        return $this;
    }

    // ============================================================
    // WIN/LOSS COMPETITIVE INTELLIGENCE METHODS
    // ============================================================
    
    public function getLossReason(): ?string
    {
        return $this->lossReason;
    }
    
    public function setLossReason(?string $lossReason): self
    {
        if ($lossReason !== null && !in_array($lossReason, self::LOSS_REASONS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid loss reason "%s". Valid reasons are: %s',
                $lossReason,
                implode(', ', self::LOSS_REASONS)
            ));
        }
        $this->lossReason = $lossReason;
        return $this;
    }
    
    public function getLossReasonDetail(): ?string
    {
        return $this->lossReasonDetail;
    }
    
    public function setLossReasonDetail(?string $detail): self
    {
        $this->lossReasonDetail = $detail;
        return $this;
    }
    
    public function getCompetitorWon(): ?string
    {
        return $this->competitorWon;
    }
    
    public function setCompetitorWon(?string $competitor): self
    {
        $this->competitorWon = $competitor;
        return $this;
    }
    
    public function getWinningBidAmount(): ?string
    {
        return $this->winningBidAmount;
    }
    
    public function setWinningBidAmount(?string $amount): self
    {
        $this->winningBidAmount = $amount;
        return $this;
    }
    
    public function getLessonsLearned(): ?string
    {
        return $this->lessonsLearned;
    }
    
    public function setLessonsLearned(?string $lessons): self
    {
        $this->lessonsLearned = $lessons;
        return $this;
    }
    
    public function getWinFactors(): ?string
    {
        return $this->winFactors;
    }
    
    public function setWinFactors(?string $factors): self
    {
        $this->winFactors = $factors;
        return $this;
    }
    
    public function getDecisionDate(): ?\DateTimeInterface
    {
        return $this->decisionDate;
    }
    
    public function setDecisionDate(?\DateTimeInterface $date): self
    {
        $this->decisionDate = $date;
        return $this;
    }
    
    public function getAwardDate(): ?\DateTimeInterface
    {
        return $this->awardDate;
    }
    
    public function setAwardDate(?\DateTimeInterface $date): self
    {
        $this->awardDate = $date;
        return $this;
    }
    
    /**
     * Check if RFQ was won
     */
    public function isWon(): bool
    {
        return $this->status === self::STATUS_WON;
    }

    public function isLost(): bool
    {
        return $this->status === self::STATUS_LOST;
    }

    public function markLost(string $reason, ?string $competitor = null, ?string $detail = null): self
    {
        $this->setStatus(self::STATUS_LOST);
        $this->setLossReason($reason);
        $this->setCompetitorWon($competitor);
        $this->setLossReasonDetail($detail);
        $this->setDecisionDate(new \DateTime());
        return $this;
    }

    public function markWon(?string $factors = null, ?\DateTimeInterface $awardDate = null): self
    {
        $this->setStatus(self::STATUS_WON);
        $this->setWinFactors($factors);
        $this->setAwardDate($awardDate ?? new \DateTime());
        $this->setDecisionDate(new \DateTime());
        return $this;
    }
    
    /**
     * Calculate price difference vs winning bid (negative = we were higher)
     */
    public function getPriceDifferencePercent(): ?float
    {
        if (!$this->winningBidAmount || !$this->estimatedValue || $this->winningBidAmount <= 0) {
            return null;
        }
        
        $ourBid = (float)$this->estimatedValue;
        $winningBid = (float)$this->winningBidAmount;
        
        return round((($ourBid - $winningBid) / $winningBid) * 100, 2);
    }
    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    public function getArchivedAt(): ?\DateTimeInterface
    {
        return $this->archivedAt;
    }

    public function archive(\App\Entity\User $by, ?string $reason = null): self
    {
        $this->archivedAt = $this->archivedAt ?? new \DateTime();
        $this->archivedBy = $by;
        $this->archiveReason = $reason;

        return $this;
    }

    public function restore(): self
    {
        $this->archivedAt = null;
        $this->archivedBy = null;
        $this->archiveReason = null;

        return $this;
    }

    public function getArchivedBy(): ?\App\Entity\User
    {
        return $this->archivedBy;
    }

    public function getArchiveReason(): ?string
    {
        return $this->archiveReason;
    }

}
