<?php

namespace App\Entity;

use App\Repository\LeadRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LeadRepository::class)]
#[ORM\Table(name: 'leads')]
#[ORM\Index(name: 'idx_leads_dupe', columns: ['dupe_key'])]
#[ORM\Index(name: 'idx_leads_region', columns: ['region_tag'])]
#[ORM\Index(name: 'idx_leads_score', columns: ['lead_score'])]
class Lead
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $companyName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $legalName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $websiteRoot = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $leadUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $siteLocation = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $usState = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $usCityMetro = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $regionTag = null; // morocco, us_east, us_texas, uk, eu_core, eu_nordics, eu_cee

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $sectorTags = null; // ["automotive", "aerospace", "defense", etc.]

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $fitSignals = null; // {"pcba": true, "smt": true, "ems": true, etc.}

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $moroccoSignal = false;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $qualityStack = null; // ["IATF 16949", "AS9100", "ISO 13485", "CE", etc.]

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $contactEmailsPublic = null; // ["procurement@example.com", "supplier@example.com"]

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $contactFormUrl = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $supplierPortalUrl = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $rfqRfpPageUrl = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $supplierPortalComplexity = null; // simple, moderate, complex

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $defenseFlag = false;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastSeen = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $contentLastModified = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $leadScore = null; // 0-100

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notesAuto = null; // Auto-generated notes from crawler

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $dupeKey = null; // normalized company_name + domain root

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $alreadyInCrm = false;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $reviewStatus = null; // pending, approved, denied

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $denyReason = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $crmRecordId = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ownerRep = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->reviewStatus = 'pending';
        $this->moroccoSignal = false;
        $this->defenseFlag = false;
        $this->alreadyInCrm = false;
    }

    // Getters and Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function setCompanyName(string $companyName): self
    {
        $this->companyName = $companyName;
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

    public function getWebsiteRoot(): ?string
    {
        return $this->websiteRoot;
    }

    public function setWebsiteRoot(?string $websiteRoot): self
    {
        $this->websiteRoot = $websiteRoot;
        return $this;
    }

    public function getLeadUrl(): ?string
    {
        return $this->leadUrl;
    }

    public function setLeadUrl(?string $leadUrl): self
    {
        $this->leadUrl = $leadUrl;
        return $this;
    }

    public function getSiteLocation(): ?string
    {
        return $this->siteLocation;
    }

    public function setSiteLocation(?string $siteLocation): self
    {
        $this->siteLocation = $siteLocation;
        return $this;
    }

    public function getUsState(): ?string
    {
        return $this->usState;
    }

    public function setUsState(?string $usState): self
    {
        $this->usState = $usState;
        return $this;
    }

    public function getUsCityMetro(): ?string
    {
        return $this->usCityMetro;
    }

    public function setUsCityMetro(?string $usCityMetro): self
    {
        $this->usCityMetro = $usCityMetro;
        return $this;
    }

    public function getRegionTag(): ?string
    {
        return $this->regionTag;
    }

    public function setRegionTag(?string $regionTag): self
    {
        $this->regionTag = $regionTag;
        return $this;
    }

    public function getSectorTags(): ?array
    {
        return $this->sectorTags;
    }

    public function setSectorTags(?array $sectorTags): self
    {
        $this->sectorTags = $sectorTags;
        return $this;
    }

    public function getFitSignals(): ?array
    {
        return $this->fitSignals;
    }

    public function setFitSignals(?array $fitSignals): self
    {
        $this->fitSignals = $fitSignals;
        return $this;
    }

    public function getMoroccoSignal(): ?bool
    {
        return $this->moroccoSignal;
    }

    public function setMoroccoSignal(?bool $moroccoSignal): self
    {
        $this->moroccoSignal = $moroccoSignal;
        return $this;
    }

    public function getQualityStack(): ?array
    {
        return $this->qualityStack;
    }

    public function setQualityStack(?array $qualityStack): self
    {
        $this->qualityStack = $qualityStack;
        return $this;
    }

    public function getContactEmailsPublic(): ?array
    {
        return $this->contactEmailsPublic;
    }

    public function setContactEmailsPublic(?array $contactEmailsPublic): self
    {
        $this->contactEmailsPublic = $contactEmailsPublic;
        return $this;
    }

    public function getContactFormUrl(): ?string
    {
        return $this->contactFormUrl;
    }

    public function setContactFormUrl(?string $contactFormUrl): self
    {
        $this->contactFormUrl = $contactFormUrl;
        return $this;
    }

    public function getSupplierPortalUrl(): ?string
    {
        return $this->supplierPortalUrl;
    }

    public function setSupplierPortalUrl(?string $supplierPortalUrl): self
    {
        $this->supplierPortalUrl = $supplierPortalUrl;
        return $this;
    }

    public function getRfqRfpPageUrl(): ?string
    {
        return $this->rfqRfpPageUrl;
    }

    public function setRfqRfpPageUrl(?string $rfqRfpPageUrl): self
    {
        $this->rfqRfpPageUrl = $rfqRfpPageUrl;
        return $this;
    }

    public function getSupplierPortalComplexity(): ?string
    {
        return $this->supplierPortalComplexity;
    }

    public function setSupplierPortalComplexity(?string $supplierPortalComplexity): self
    {
        $this->supplierPortalComplexity = $supplierPortalComplexity;
        return $this;
    }

    public function getDefenseFlag(): ?bool
    {
        return $this->defenseFlag;
    }

    public function setDefenseFlag(?bool $defenseFlag): self
    {
        $this->defenseFlag = $defenseFlag;
        return $this;
    }

    public function getLastSeen(): ?\DateTimeInterface
    {
        return $this->lastSeen;
    }

    public function setLastSeen(?\DateTimeInterface $lastSeen): self
    {
        $this->lastSeen = $lastSeen;
        return $this;
    }

    public function getContentLastModified(): ?\DateTimeInterface
    {
        return $this->contentLastModified;
    }

    public function setContentLastModified(?\DateTimeInterface $contentLastModified): self
    {
        $this->contentLastModified = $contentLastModified;
        return $this;
    }

    public function getLeadScore(): ?int
    {
        return $this->leadScore;
    }

    public function setLeadScore(?int $leadScore): self
    {
        $this->leadScore = $leadScore;
        return $this;
    }

    public function getNotesAuto(): ?string
    {
        return $this->notesAuto;
    }

    public function setNotesAuto(?string $notesAuto): self
    {
        $this->notesAuto = $notesAuto;
        return $this;
    }

    public function getDupeKey(): ?string
    {
        return $this->dupeKey;
    }

    public function setDupeKey(?string $dupeKey): self
    {
        $this->dupeKey = $dupeKey;
        return $this;
    }

    public function getAlreadyInCrm(): ?bool
    {
        return $this->alreadyInCrm;
    }

    public function setAlreadyInCrm(?bool $alreadyInCrm): self
    {
        $this->alreadyInCrm = $alreadyInCrm;
        return $this;
    }

    public function getReviewStatus(): ?string
    {
        return $this->reviewStatus;
    }

    public function setReviewStatus(?string $reviewStatus): self
    {
        $this->reviewStatus = $reviewStatus;
        return $this;
    }

    public function getDenyReason(): ?string
    {
        return $this->denyReason;
    }

    public function setDenyReason(?string $denyReason): self
    {
        $this->denyReason = $denyReason;
        return $this;
    }

    public function getCrmRecordId(): ?string
    {
        return $this->crmRecordId;
    }

    public function setCrmRecordId(?string $crmRecordId): self
    {
        $this->crmRecordId = $crmRecordId;
        return $this;
    }

    public function getOwnerRep(): ?string
    {
        return $this->ownerRep;
    }

    public function setOwnerRep(?string $ownerRep): self
    {
        $this->ownerRep = $ownerRep;
        return $this;
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
}
