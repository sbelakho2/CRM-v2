<?php

namespace App\Entity;

use App\Repository\LeadRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LeadRepository::class)]
#[ORM\Table(name: 'leads')]
#[ORM\Index(name: 'idx_leads_dupe', columns: ['dupe_key'])]
#[ORM\Index(name: 'idx_leads_region', columns: ['region_tag'])]
#[ORM\Index(name: 'idx_leads_score', columns: ['lead_score'])]
#[ORM\Index(name: 'idx_leads_website', columns: ['website_root'])]
#[ORM\Index(name: 'idx_leads_status', columns: ['review_status'])]
#[ORM\Index(name: 'idx_leads_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_leads_scraped', columns: ['last_scraped_at'])]
#[ORM\Index(name: 'idx_leads_nurturing', columns: ['nurturing_stage'])]
#[ORM\HasLifecycleCallbacks]
class Lead
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DENIED = 'denied';

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
    
    // Contact form detection (enhanced scraping)
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $hasContactForm = false;

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

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $externalCrmUrl = null; // Direct link to record in external CRM (Salesforce, HubSpot, etc.)

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ownerRep = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;
    
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $source = null;
    
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $scrapingMethod = null; // 'static', 'panther', 'static_fallback'

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $nurturingStage = null; // new, contacted, engaged, qualified, opportunity, converted, dormant, lost

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $pagesScraped = null;
    
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastScrapedAt = null;

    public function __construct()
    {
        $this->reviewStatus = self::STATUS_PENDING;
        $this->moroccoSignal = false;
        $this->defenseFlag = false;
        $this->alreadyInCrm = false;
        $this->createdAt = new \DateTime();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTime();
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
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

    public function getExternalCrmUrl(): ?string
    {
        return $this->externalCrmUrl;
    }

    public function setExternalCrmUrl(?string $externalCrmUrl): self
    {
        $this->externalCrmUrl = $externalCrmUrl;
        return $this;
    }

    /**
     * Generate external CRM URL based on CRM record ID and configured CRM type
     *
     * @param string $crmType Type of CRM: 'salesforce', 'hubspot', 'zoho', 'pipedrive', 'dynamics'
     * @param string|null $instanceUrl Base URL for the CRM instance (required for Salesforce)
     */
    public function generateExternalCrmUrl(string $crmType, ?string $instanceUrl = null): self
    {
        $url = self::generateExternalCrmUrlStatic($this->crmRecordId, $crmType, $instanceUrl);
        if ($url) {
            $this->externalCrmUrl = $url;
        }
        return $this;
    }

    public static function generateExternalCrmUrlStatic(?string $crmRecordId, string $crmType, ?string $instanceUrl = null): ?string
    {
        if (!$crmRecordId) {
            return null;
        }

        return match (strtolower($crmType)) {
            'salesforce' => $instanceUrl
                ? rtrim($instanceUrl, '/') . '/lightning/r/Lead/' . $crmRecordId . '/view'
                : null,
            'hubspot' => 'https://app.hubspot.com/contacts/' . $crmRecordId,
            'zoho' => 'https://crm.zoho.com/crm/tab/Leads/' . $crmRecordId,
            'pipedrive' => 'https://app.pipedrive.com/person/' . $crmRecordId,
            'dynamics' => $instanceUrl
                ? rtrim($instanceUrl, '/') . '/main.aspx?etn=lead&id=' . $crmRecordId . '&pagetype=entityrecord'
                : null,
            default => null,
        };
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
    
    // ==================== Contact Form Detection ====================
    
    public function hasContactForm(): bool
    {
        return $this->hasContactForm;
    }
    
    /**
     * Getter alias for Symfony property access (e.g. LeadNurturingService)
     */
    public function getHasContactForm(): bool
    {
        return $this->hasContactForm;
    }
    
    public function setHasContactForm(bool $hasContactForm): self
    {
        $this->hasContactForm = $hasContactForm;
        return $this;
    }
    
    // ==================== Scraping Metadata ====================
    
    public function getScrapingMethod(): ?string
    {
        return $this->scrapingMethod;
    }
    
    public function setScrapingMethod(?string $scrapingMethod): self
    {
        $this->scrapingMethod = $scrapingMethod;
        return $this;
    }
    
    // ==================== Nurturing Stage ====================
    
    public function getNurturingStage(): ?string
    {
        return $this->nurturingStage;
    }
    
    public function setNurturingStage(?string $nurturingStage): self
    {
        $this->nurturingStage = $nurturingStage;
        return $this;
    }
    
    public function getPagesScraped(): ?int
    {
        return $this->pagesScraped;
    }
    
    public function setPagesScraped(?int $pagesScraped): self
    {
        $this->pagesScraped = $pagesScraped;
        return $this;
    }
    
    public function getLastScrapedAt(): ?\DateTimeInterface
    {
        return $this->lastScrapedAt;
    }
    
    public function setLastScrapedAt(?\DateTimeInterface $lastScrapedAt): self
    {
        $this->lastScrapedAt = $lastScrapedAt;
        return $this;
    }
    
    /**
     * Check if lead was scraped with headless browser
     */
    public function wasScrapedWithHeadless(): bool
    {
        return $this->scrapingMethod === 'panther';
    }
    
    /**
     * Check if lead has contact information
     */
    public function hasContactInfo(): bool
    {
        return !empty($this->contactEmailsPublic) || 
               !empty($this->contactFormUrl) ||
               $this->hasContactForm;
    }
    
    // ============================================================
    // ALIAS METHODS for LeadDiscoveryController compatibility
    // ============================================================
    
    /**
     * Alias for setWebsiteRoot() - used by LeadDiscoveryController
     */
    public function setWebsite(?string $website): self
    {
        return $this->setWebsiteRoot($website);
    }
    
    /**
     * Alias for getWebsiteRoot() - used by LeadDiscoveryController
     */
    public function getWebsite(): ?string
    {
        return $this->getWebsiteRoot();
    }
    
    /**
     * Set the lead source (proper ORM column)
     */
    public function setSource(?string $source): self
    {
        $this->source = $source;
        return $this;
    }
    
    /**
     * Get the lead source
     */
    public function getSource(): ?string
    {
        return $this->source;
    }
    
    /**
     * Set description in notesAuto
     */
    public function setDescription(?string $description): self
    {
        $this->notesAuto = $description;
        return $this;
    }
    
    /**
     * Get description from notesAuto
     */
    public function getDescription(): ?string
    {
        return $this->notesAuto;
    }
}
