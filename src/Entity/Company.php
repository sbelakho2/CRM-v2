<?php

namespace App\Entity;

use App\Repository\CompanyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CompanyRepository::class)]
#[ORM\Table(name: 'companies')]
#[ORM\Index(name: 'idx_company_name', columns: ['name'])]
#[ORM\Index(name: 'idx_company_sector', columns: ['sector'])]
#[ORM\Index(name: 'idx_company_pipeline', columns: ['pipeline_stage'])]
#[ORM\Index(name: 'idx_company_status', columns: ['company_status'])]
#[ORM\Index(name: 'idx_company_archived_at', columns: ['archived_at'])]
#[ORM\HasLifecycleCallbacks]
class Company
{
    // Company Status Constants — controls lifecycle gating
    public const STATUS_DISCOVERED = 'discovered'; // Webcrawler-found, pending human review
    public const STATUS_APPROVED   = 'approved';   // Reviewed & accepted into company list (NOT in compliance yet)
    public const STATUS_ACTIVE     = 'active';     // Moved to active — eligible for compliance pipeline

    public const VALID_STATUSES = [
        self::STATUS_DISCOVERED,
        self::STATUS_APPROVED,
        self::STATUS_ACTIVE,
    ];

    // Pipeline Stage Constants
    public const STAGE_PROSPECT = 'Prospect';
    public const STAGE_MQL = 'MQL';        // Marketing Qualified Lead
    public const STAGE_SQL = 'SQL';        // Sales Qualified Lead
    public const STAGE_SQO = 'SQO';        // Sales Qualified Opportunity
    public const STAGE_PROPOSAL = 'Proposal';
    public const STAGE_AWARD = 'Award';
    
    public const VALID_STAGES = [
        self::STAGE_PROSPECT,
        self::STAGE_MQL,
        self::STAGE_SQL,
        self::STAGE_SQO,
        self::STAGE_PROPOSAL,
        self::STAGE_AWARD,
    ];
    
    // Account Tier Constants
    public const TIER_A = 'A';
    public const TIER_B = 'B';
    public const TIER_C = 'C';
    
    public const VALID_TIERS = [
        self::TIER_A,
        self::TIER_B,
        self::TIER_C,
    ];
    
    // Sector Constants — Top EMS/PCBA buyer verticals for Starz Electronics
    // Short, consistent names used across all modules (form, webcrawler, compliance, etc.)
    public const SECTOR_AUTOMOTIVE = 'Automotive';
    public const SECTOR_AEROSPACE = 'Aerospace';
    public const SECTOR_INDUSTRIAL = 'Industrial';
    public const SECTOR_RAIL = 'Rail';
    public const SECTOR_RENEWABLES = 'Renewables';
    public const SECTOR_MEDICAL = 'Medical';
    public const SECTOR_DEFENSE = 'Defense';
    public const SECTOR_TELECOM = 'Telecom';
    public const SECTOR_HVAC = 'HVAC';
    public const SECTOR_MARINE = 'Marine';
    public const SECTOR_POWER_ELECTRONICS = 'Power Electronics';
    public const SECTOR_CONSUMER_ELECTRONICS = 'Consumer Electronics';
    public const SECTOR_DATA_CENTER = 'Data Center';
    public const SECTOR_ENERGY_STORAGE = 'Energy Storage';
    public const SECTOR_OTHER = 'Other';

    // Backward-compatible aliases for legacy code
    public const SECTOR_MEDICAL_DEVICES = self::SECTOR_MEDICAL;
    public const SECTOR_TELECOMMUNICATIONS = self::SECTOR_TELECOM;
    
    public const VALID_SECTORS = [
        self::SECTOR_AUTOMOTIVE,
        self::SECTOR_AEROSPACE,
        self::SECTOR_INDUSTRIAL,
        self::SECTOR_RAIL,
        self::SECTOR_RENEWABLES,
        self::SECTOR_MEDICAL,
        self::SECTOR_DEFENSE,
        self::SECTOR_TELECOM,
        self::SECTOR_HVAC,
        self::SECTOR_MARINE,
        self::SECTOR_POWER_ELECTRONICS,
        self::SECTOR_CONSUMER_ELECTRONICS,
        self::SECTOR_DATA_CENTER,
        self::SECTOR_ENERGY_STORAGE,
        self::SECTOR_OTHER,
    ];
    
    // Human-readable sector labels for UI display
    public const SECTOR_LABELS = [
        self::SECTOR_AUTOMOTIVE => 'Automotive & EV',
        self::SECTOR_AEROSPACE => 'Aerospace & Aviation',
        self::SECTOR_INDUSTRIAL => 'Industrial & Automation',
        self::SECTOR_RAIL => 'Rail & Transportation',
        self::SECTOR_RENEWABLES => 'Renewables & Clean Energy',
        self::SECTOR_MEDICAL => 'Medical Devices',
        self::SECTOR_DEFENSE => 'Defense & Security',
        self::SECTOR_TELECOM => 'Telecommunications & 5G',
        self::SECTOR_HVAC => 'HVAC & Building Automation',
        self::SECTOR_MARINE => 'Marine & Shipbuilding',
        self::SECTOR_POWER_ELECTRONICS => 'Power Electronics',
        self::SECTOR_CONSUMER_ELECTRONICS => 'Consumer Electronics',
        self::SECTOR_DATA_CENTER => 'Data Center & Cloud',
        self::SECTOR_ENERGY_STORAGE => 'Energy Storage & Battery',
        self::SECTOR_OTHER => 'Other',
    ];
    
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $sector = null; // Automotive, Industrial, Aerospace, Rail, Renewables, Power Electronics

    #[ORM\Column(length: 10)]
    private ?string $accountTier = self::TIER_C; // A, B, C

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $region = null; // TAC, TFZ, AFZ Kenitra, Casablanca/Midparc, Bouskoura

    #[ORM\Column(length: 50)]
    private ?string $pipelineStage = self::STAGE_PROSPECT; // Prospect, MQL, SQL, SQO, Proposal, Award

    #[ORM\Column(length: 30, options: ['default' => 'approved'])]
    private string $companyStatus = self::STATUS_APPROVED; // discovered, approved, active

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $linkedInUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $physicalSite = null; // TAC, TFZ, AFZ Kenitra, Midparc, Bouskoura

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $linkedinCompanyUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sourceNotes = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $legalName = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $googleDriveLink = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $archivedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $archivedBy = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $archiveReason = null;

    // History-preserving relations: cascade "remove"/orphanRemoval is
    // deliberately absent so no code path can cascade-destroy CRM history
    // (contacts, activities, RFQs, compliance records, ...). Companies are
    // archived (archivedAt), never hard-deleted through the CRM UI.
    #[ORM\OneToMany(mappedBy: 'company', targetEntity: Contact::class, cascade: ['persist'])]
    private Collection $contacts;

    #[ORM\OneToMany(mappedBy: 'company', targetEntity: Activity::class, cascade: ['persist'])]
    private Collection $activities;

    #[ORM\OneToMany(mappedBy: 'company', targetEntity: RFQ::class, cascade: ['persist'])]
    private Collection $rfqs;

    #[ORM\OneToOne(mappedBy: 'company', targetEntity: SupplierPortal::class, cascade: ['persist'])]
    private ?SupplierPortal $supplierPortal = null;

    #[ORM\OneToMany(mappedBy: 'company', targetEntity: ComplianceDocument::class, cascade: ['persist'])]
    private Collection $complianceDocuments;

    #[ORM\OneToMany(mappedBy: 'company', targetEntity: PortalCandidate::class, cascade: ['persist'])]
    private Collection $portalCandidates;

    #[ORM\OneToMany(mappedBy: 'company', targetEntity: OnboardingPack::class, cascade: ['persist'])]
    private Collection $onboardingPacks;

    #[ORM\OneToMany(mappedBy: 'company', targetEntity: CompanyCanonical::class, cascade: ['persist'])]
    private Collection $companyCanonicals;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->contacts = new ArrayCollection();
        $this->activities = new ArrayCollection();
        $this->rfqs = new ArrayCollection();
        $this->complianceDocuments = new ArrayCollection();
        $this->portalCandidates = new ArrayCollection();
        $this->onboardingPacks = new ArrayCollection();
        $this->companyCanonicals = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTime();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSector(): ?string
    {
        return $this->sector;
    }

    public function setSector(?string $sector): self
    {
        if ($sector !== null && !in_array($sector, self::VALID_SECTORS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid sector "%s". Valid sectors are: %s',
                $sector,
                implode(', ', self::VALID_SECTORS)
            ));
        }
        $this->sector = $sector;
        return $this;
    }

    public function getAccountTier(): ?string
    {
        return $this->accountTier;
    }

    public function setAccountTier(string $accountTier): self
    {
        if (!in_array($accountTier, self::VALID_TIERS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid account tier "%s". Valid tiers are: %s',
                $accountTier,
                implode(', ', self::VALID_TIERS)
            ));
        }
        $this->accountTier = $accountTier;
        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): self
    {
        $this->region = $region;
        return $this;
    }

    public function getPipelineStage(): ?string
    {
        return $this->pipelineStage;
    }

    public function setPipelineStage(string $pipelineStage): self
    {
        if (!in_array($pipelineStage, self::VALID_STAGES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid pipeline stage "%s". Valid stages are: %s',
                $pipelineStage,
                implode(', ', self::VALID_STAGES)
            ));
        }
        $this->pipelineStage = $pipelineStage;
        return $this;
    }
    
    /**
     * Check if the company is in a won state
     */
    public function isWon(): bool
    {
        return $this->pipelineStage === self::STAGE_AWARD;
    }
    
    /**
     * Check if the company is in an active pipeline (not prospect, not won)
     */
    public function isInActivePipeline(): bool
    {
        return in_array($this->pipelineStage, [
            self::STAGE_MQL,
            self::STAGE_SQL,
            self::STAGE_SQO,
            self::STAGE_PROPOSAL,
        ], true);
    }
    
    /**
     * Advance to the next pipeline stage
     */
    public function advanceStage(): self
    {
        $currentIndex = array_search($this->pipelineStage, self::VALID_STAGES, true);
        if ($currentIndex !== false && $currentIndex < count(self::VALID_STAGES) - 1) {
            $this->pipelineStage = self::VALID_STAGES[$currentIndex + 1];
        }
        return $this;
    }

    public function getCompanyStatus(): string
    {
        return $this->companyStatus;
    }

    public function setCompanyStatus(string $companyStatus): self
    {
        if (!in_array($companyStatus, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid company status "%s". Valid statuses are: %s',
                $companyStatus,
                implode(', ', self::VALID_STATUSES)
            ));
        }
        $this->companyStatus = $companyStatus;
        return $this;
    }

    /**
     * Whether this company was auto-discovered and is awaiting review
     */
    public function isDiscovered(): bool
    {
        return $this->companyStatus === self::STATUS_DISCOVERED;
    }

    /**
     * Whether this company has been approved into the company list
     */
    public function isApproved(): bool
    {
        return $this->companyStatus === self::STATUS_APPROVED;
    }

    /**
     * Whether this company is active and eligible for the compliance pipeline
     */
    public function isActive(): bool
    {
        return $this->companyStatus === self::STATUS_ACTIVE;
    }

    /**
     * Whether this company can enter the compliance pipeline
     */
    public function canEnterCompliance(): bool
    {
        return $this->companyStatus === self::STATUS_ACTIVE;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): self
    {
        $this->website = $website;
        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;
        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): self
    {
        $this->city = $city;
        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        $this->country = $country;
        return $this;
    }

    public function getLinkedInUrl(): ?string
    {
        return $this->linkedInUrl;
    }

    public function setLinkedInUrl(?string $linkedInUrl): self
    {
        $this->linkedInUrl = $linkedInUrl;
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

    /**
     * @return Collection<int, Contact>
     */
    public function getContacts(): Collection
    {
        return $this->contacts;
    }

    public function addContact(Contact $contact): self
    {
        if (!$this->contacts->contains($contact)) {
            $this->contacts->add($contact);
            $contact->setCompany($this);
        }
        return $this;
    }

    public function removeContact(Contact $contact): self
    {
        if ($this->contacts->removeElement($contact)) {
            if ($contact->getCompany() === $this) {
                $contact->setCompany(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Activity>
     */
    public function getActivities(): Collection
    {
        return $this->activities;
    }

    /**
     * @return Collection<int, RFQ>
     */
    public function getRfqs(): Collection
    {
        return $this->rfqs;
    }

    public function getSupplierPortal(): ?SupplierPortal
    {
        return $this->supplierPortal;
    }

    /**
     * @return Collection<int, ComplianceDocument>
     */
    public function getComplianceDocuments(): Collection
    {
        return $this->complianceDocuments;
    }

    public function addComplianceDocument(ComplianceDocument $document): self
    {
        if (!$this->complianceDocuments->contains($document)) {
            $this->complianceDocuments->add($document);
            $document->setCompany($this);
        }
        return $this;
    }

    public function removeComplianceDocument(ComplianceDocument $document): self
    {
        if ($this->complianceDocuments->removeElement($document)) {
            if ($document->getCompany() === $this) {
                $document->setCompany(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, PortalCandidate>
     */
    public function getPortalCandidates(): Collection
    {
        return $this->portalCandidates;
    }

    public function addPortalCandidate(PortalCandidate $candidate): self
    {
        if (!$this->portalCandidates->contains($candidate)) {
            $this->portalCandidates->add($candidate);
            $candidate->setCompany($this);
        }
        return $this;
    }

    public function removePortalCandidate(PortalCandidate $candidate): self
    {
        if ($this->portalCandidates->removeElement($candidate)) {
            if ($candidate->getCompany() === $this) {
                $candidate->setCompany(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, OnboardingPack>
     */
    public function getOnboardingPacks(): Collection
    {
        return $this->onboardingPacks;
    }

    public function addOnboardingPack(OnboardingPack $pack): self
    {
        if (!$this->onboardingPacks->contains($pack)) {
            $this->onboardingPacks->add($pack);
            $pack->setCompany($this);
        }
        return $this;
    }

    public function removeOnboardingPack(OnboardingPack $pack): self
    {
        if ($this->onboardingPacks->removeElement($pack)) {
            if ($pack->getCompany() === $this) {
                $pack->setCompany(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, CompanyCanonical>
     */
    public function getCompanyCanonicals(): Collection
    {
        return $this->companyCanonicals;
    }

    public function addCompanyCanonical(CompanyCanonical $canonical): self
    {
        if (!$this->companyCanonicals->contains($canonical)) {
            $this->companyCanonicals->add($canonical);
            $canonical->setCompany($this);
        }
        return $this;
    }

    public function removeCompanyCanonical(CompanyCanonical $canonical): self
    {
        if ($this->companyCanonicals->removeElement($canonical)) {
            if ($canonical->getCompany() === $this) {
                $canonical->setCompany(null);
            }
        }
        return $this;
    }

    public function setSupplierPortal(?SupplierPortal $supplierPortal): self
    {
        if ($supplierPortal === null && $this->supplierPortal !== null) {
            $this->supplierPortal->setCompany(null);
        }

        if ($supplierPortal !== null && $supplierPortal->getCompany() !== $this) {
            $supplierPortal->setCompany($this);
        }

        $this->supplierPortal = $supplierPortal;
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

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    public function getArchivedAt(): ?\DateTimeInterface
    {
        return $this->archivedAt;
    }

    public function archive(User $by, ?string $reason = null): self
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

    public function getArchivedBy(): ?User
    {
        return $this->archivedBy;
    }

    public function setArchivedBy(?User $archivedBy): self
    {
        $this->archivedBy = $archivedBy;

        return $this;
    }

    public function getArchiveReason(): ?string
    {
        return $this->archiveReason;
    }

    public function setArchiveReason(?string $archiveReason): self
    {
        $this->archiveReason = $archiveReason;

        return $this;
    }

    public function getPhysicalSite(): ?string
    {
        return $this->physicalSite;
    }

    public function setPhysicalSite(?string $physicalSite): self
    {
        $this->physicalSite = $physicalSite;
        return $this;
    }

    public function getLinkedinCompanyUrl(): ?string
    {
        return $this->linkedinCompanyUrl;
    }

    public function setLinkedinCompanyUrl(?string $linkedinCompanyUrl): self
    {
        $this->linkedinCompanyUrl = $linkedinCompanyUrl;
        return $this;
    }

    public function getSourceNotes(): ?string
    {
        return $this->sourceNotes;
    }

    public function setSourceNotes(?string $sourceNotes): self
    {
        $this->sourceNotes = $sourceNotes;
        return $this;
    }

    public function getLegalName(): ?string
    {
        return $this->legalName;
    }

    public function setLegalName(?string $legalName): self
    {
        $this->legalName = $legalName;
        return $this;
    }

    public function getGoogleDriveLink(): ?string
    {
        return $this->googleDriveLink;
    }

    public function setGoogleDriveLink(?string $googleDriveLink): self
    {
        $this->googleDriveLink = $googleDriveLink;
        return $this;
    }

}
