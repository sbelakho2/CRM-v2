<?php

namespace App\Entity;

use App\Repository\CompetitorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CompetitorRepository::class)]
#[ORM\Table(name: 'competitors')]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_comp_domain', columns: ['canonical_domain'])]
#[ORM\Index(name: 'idx_comp_status', columns: ['status'])]
#[ORM\Index(name: 'idx_comp_threat', columns: ['threat_score'])]
#[ORM\Index(name: 'idx_comp_overlap', columns: ['overlap_score'])]
#[ORM\Index(name: 'idx_comp_directness', columns: ['directness'])]
class Competitor
{
    // ── Status constants ──
    public const STATUS_CANDIDATE  = 'candidate';   // Discovered, not yet verified
    public const STATUS_VERIFIED   = 'verified';     // Passed evidence gate
    public const STATUS_PROFILED   = 'profiled';     // Deep profile completed
    public const STATUS_MONITORING = 'monitoring';   // Active monitoring
    public const STATUS_ARCHIVED   = 'archived';     // Historically relevant, not active
    public const STATUS_REJECTED   = 'rejected';     // Failed verification
    public const STATUS_SEED_ONLY  = 'seed_only';    // Directory/expo — metadata only

    public const VALID_STATUSES = [
        self::STATUS_CANDIDATE,
        self::STATUS_VERIFIED,
        self::STATUS_PROFILED,
        self::STATUS_MONITORING,
        self::STATUS_ARCHIVED,
        self::STATUS_REJECTED,
        self::STATUS_SEED_ONLY,
    ];

    // ── Directness constants ──
    public const DIRECTNESS_DIRECT            = 'direct';
    public const DIRECTNESS_ADJACENT          = 'adjacent';
    public const DIRECTNESS_PARTNER_CANDIDATE = 'partner_candidate';
    public const DIRECTNESS_FUTURE_THREAT     = 'future_threat';

    public const VALID_DIRECTNESS = [
        self::DIRECTNESS_DIRECT,
        self::DIRECTNESS_ADJACENT,
        self::DIRECTNESS_PARTNER_CANDIDATE,
        self::DIRECTNESS_FUTURE_THREAT,
    ];

    // ── Competitor type taxonomy ──
    // Core EMS
    public const TYPE_EMS_CONTRACT_MANUFACTURER       = 'ems_contract_manufacturer';
    public const TYPE_PCBA_ASSEMBLY_HOUSE              = 'pcba_assembly_house';
    public const TYPE_BOX_BUILD_INTEGRATOR             = 'box_build_integrator';
    public const TYPE_ODM_JDM                          = 'odm_jdm';
    // Interconnect
    public const TYPE_WIRE_HARNESS_ASSEMBLER           = 'wire_harness_assembler';
    public const TYPE_CABLE_ASSEMBLY_SHOP              = 'cable_assembly_shop';
    public const TYPE_CONNECTOR_INTEGRATION_SHOP       = 'connector_integration_shop';
    // Precision manufacturing
    public const TYPE_CNC_MACHINE_SHOP                 = 'cnc_machine_shop';
    public const TYPE_PRECISION_MACHINING              = 'precision_machining';
    public const TYPE_SHEET_METAL_FABRICATION           = 'sheet_metal_fabrication';
    public const TYPE_TOOL_AND_DIE                     = 'tool_and_die';
    // Energy / supercapacitors
    public const TYPE_SUPERCAP_CELL_MANUFACTURER       = 'supercapacitor_cell_manufacturer';
    public const TYPE_SUPERCAP_MODULE_INTEGRATOR       = 'supercapacitor_module_pack_integrator';
    public const TYPE_ACTIVATED_CARBON_ELECTRODE        = 'activated_carbon_electrode_manufacturer';
    public const TYPE_GRAPHENE_MATERIALS_PRODUCER       = 'graphene_materials_producer';
    public const TYPE_BATTERY_PACK_MANUFACTURER         = 'battery_pack_manufacturer';

    public const VALID_TYPES = [
        self::TYPE_EMS_CONTRACT_MANUFACTURER,
        self::TYPE_PCBA_ASSEMBLY_HOUSE,
        self::TYPE_BOX_BUILD_INTEGRATOR,
        self::TYPE_ODM_JDM,
        self::TYPE_WIRE_HARNESS_ASSEMBLER,
        self::TYPE_CABLE_ASSEMBLY_SHOP,
        self::TYPE_CONNECTOR_INTEGRATION_SHOP,
        self::TYPE_CNC_MACHINE_SHOP,
        self::TYPE_PRECISION_MACHINING,
        self::TYPE_SHEET_METAL_FABRICATION,
        self::TYPE_TOOL_AND_DIE,
        self::TYPE_SUPERCAP_CELL_MANUFACTURER,
        self::TYPE_SUPERCAP_MODULE_INTEGRATOR,
        self::TYPE_ACTIVATED_CARBON_ELECTRODE,
        self::TYPE_GRAPHENE_MATERIALS_PRODUCER,
        self::TYPE_BATTERY_PACK_MANUFACTURER,
    ];

    public const TYPE_LABELS = [
        self::TYPE_EMS_CONTRACT_MANUFACTURER => 'EMS / Contract Manufacturer',
        self::TYPE_PCBA_ASSEMBLY_HOUSE       => 'PCBA Assembly House',
        self::TYPE_BOX_BUILD_INTEGRATOR      => 'Box Build Integrator',
        self::TYPE_ODM_JDM                   => 'ODM / JDM',
        self::TYPE_WIRE_HARNESS_ASSEMBLER    => 'Wire Harness Assembler',
        self::TYPE_CABLE_ASSEMBLY_SHOP       => 'Cable Assembly Shop',
        self::TYPE_CONNECTOR_INTEGRATION_SHOP => 'Connector Integration',
        self::TYPE_CNC_MACHINE_SHOP          => 'CNC Machine Shop',
        self::TYPE_PRECISION_MACHINING       => 'Precision Machining',
        self::TYPE_SHEET_METAL_FABRICATION   => 'Sheet Metal Fabrication',
        self::TYPE_TOOL_AND_DIE              => 'Tool & Die',
        self::TYPE_SUPERCAP_CELL_MANUFACTURER => 'Supercapacitor Cell Manufacturer',
        self::TYPE_SUPERCAP_MODULE_INTEGRATOR => 'Supercapacitor Module/Pack',
        self::TYPE_ACTIVATED_CARBON_ELECTRODE => 'Activated Carbon Electrode Mfr',
        self::TYPE_GRAPHENE_MATERIALS_PRODUCER => 'Graphene Materials Producer',
        self::TYPE_BATTERY_PACK_MANUFACTURER  => 'Battery Pack Manufacturer',
    ];

    // ── Type category groupings ──
    public const TYPE_GROUP_CORE_EMS = [
        self::TYPE_EMS_CONTRACT_MANUFACTURER,
        self::TYPE_PCBA_ASSEMBLY_HOUSE,
        self::TYPE_BOX_BUILD_INTEGRATOR,
        self::TYPE_ODM_JDM,
    ];
    public const TYPE_GROUP_INTERCONNECT = [
        self::TYPE_WIRE_HARNESS_ASSEMBLER,
        self::TYPE_CABLE_ASSEMBLY_SHOP,
        self::TYPE_CONNECTOR_INTEGRATION_SHOP,
    ];
    public const TYPE_GROUP_MACHINING = [
        self::TYPE_CNC_MACHINE_SHOP,
        self::TYPE_PRECISION_MACHINING,
        self::TYPE_SHEET_METAL_FABRICATION,
        self::TYPE_TOOL_AND_DIE,
    ];
    public const TYPE_GROUP_ENERGY = [
        self::TYPE_SUPERCAP_CELL_MANUFACTURER,
        self::TYPE_SUPERCAP_MODULE_INTEGRATOR,
        self::TYPE_ACTIVATED_CARBON_ELECTRODE,
        self::TYPE_GRAPHENE_MATERIALS_PRODUCER,
        self::TYPE_BATTERY_PACK_MANUFACTURER,
    ];

    // ── Proof grade constants ──
    public const PROOF_GRADE_A = 'A'; // PDF certificate / official registry
    public const PROOF_GRADE_B = 'B'; // Quality/capability page explicit claim
    public const PROOF_GRADE_C = 'C'; // Marketing page / homepage mention
    public const PROOF_GRADE_D = 'D'; // Footer badge / scraped snippet only

    // ── Discovery source constants ──
    public const SOURCE_SEED         = 'seed';
    public const SOURCE_SEARCH       = 'search';
    public const SOURCE_DIRECTORY    = 'directory';
    public const SOURCE_LEADCRAWLER  = 'leadcrawler';
    public const SOURCE_TRADESHOW    = 'tradeshow';
    public const SOURCE_CERT_REGISTRY = 'cert_registry';
    public const SOURCE_PRESS        = 'press';
    public const SOURCE_MANUAL       = 'manual';

    // ═══════════════════════════════════════════════════════════════════
    // ORM Columns
    // ═══════════════════════════════════════════════════════════════════

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $aliases = [];

    #[ORM\Column(length: 255)]
    private ?string $canonicalDomain = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $altDomains = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $competitorTypes = [];

    #[ORM\Column(length: 30, options: ['default' => 'direct'])]
    private string $directness = self::DIRECTNESS_DIRECT;

    #[ORM\Column(length: 30, options: ['default' => 'candidate'])]
    private string $status = self::STATUS_CANDIDATE;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $regions = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $facilities = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $capabilities = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $certifications = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $industries = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $positioningClaims = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $customerClaims = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $hiringSignals = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $equipmentHints = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $contactsPublic = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $languagesSupported = [];

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $employeeEstimate = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $hqCountry = null;

    // ── Type-specific structured profiles ──

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $emsProfile = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $machiningProfile = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $harnessProfile = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $supercapProfile = null;

    // ── Verification evidence ──

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $evidenceFamiliesPassed = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $evidenceUrls = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $verificationTrace = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    // ── Scoring ──

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $threatScore = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $overlapScore = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $strategicRelevanceScore = 0;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $proofGrade = null;

    // ── Relationships ──

    #[ORM\ManyToOne(targetEntity: Competitor::class)]
    #[ORM\JoinColumn(name: 'parent_competitor_id', nullable: true, onDelete: 'SET NULL')]
    private ?Competitor $parentCompetitor = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $relationshipGraph = null;

    // ── Raw extraction / expiry ──

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rawExtract = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $rawExtractExpiresAt = null;

    // ── Crawl metadata ──

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $discoverySource = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $seedOnly = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $tosBlocksCrawl = false;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastShallowCrawlAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastDeepCrawlAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastCrawledAt = null;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $profileVersion = 1;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $pagesCrawled = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $discoveredAt = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $updatedAt = null;

    // ── Collections ──

    #[ORM\OneToMany(mappedBy: 'competitor', targetEntity: CompetitorChangeEvent::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $changeEvents;

    #[ORM\OneToMany(mappedBy: 'competitor', targetEntity: CompetitorPageFingerprint::class, cascade: ['persist', 'remove'])]
    private Collection $pageFingerprints;

    public function __construct()
    {
        $this->changeEvents = new ArrayCollection();
        $this->pageFingerprints = new ArrayCollection();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Lifecycle callbacks
    // ═══════════════════════════════════════════════════════════════════

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTime();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Getters / Setters
    // ═══════════════════════════════════════════════════════════════════

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getAliases(): array { return $this->aliases ?? []; }
    public function setAliases(?array $aliases): self { $this->aliases = $aliases; return $this; }

    public function getCanonicalDomain(): ?string { return $this->canonicalDomain; }
    public function setCanonicalDomain(string $domain): self { $this->canonicalDomain = $domain; return $this; }

    public function getAltDomains(): array { return $this->altDomains ?? []; }
    public function setAltDomains(?array $altDomains): self { $this->altDomains = $altDomains; return $this; }

    public function getCompetitorTypes(): array { return $this->competitorTypes ?? []; }
    public function setCompetitorTypes(?array $types): self { $this->competitorTypes = $types; return $this; }

    public function getDirectness(): string { return $this->directness; }
    public function setDirectness(string $directness): self
    {
        if (!in_array($directness, self::VALID_DIRECTNESS, true)) {
            throw new \InvalidArgumentException("Invalid directness: $directness");
        }
        $this->directness = $directness;
        return $this;
    }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status: $status");
        }
        $this->status = $status;
        return $this;
    }

    public function getRegions(): array { return $this->regions ?? []; }
    public function setRegions(?array $regions): self { $this->regions = $regions; return $this; }

    public function getFacilities(): array { return $this->facilities ?? []; }
    public function setFacilities(?array $facilities): self { $this->facilities = $facilities; return $this; }

    public function getCapabilities(): array { return $this->capabilities ?? []; }
    public function setCapabilities(?array $capabilities): self { $this->capabilities = $capabilities; return $this; }

    public function getCertifications(): array { return $this->certifications ?? []; }
    public function setCertifications(?array $certifications): self { $this->certifications = $certifications; return $this; }

    public function getIndustries(): array { return $this->industries ?? []; }
    public function setIndustries(?array $industries): self { $this->industries = $industries; return $this; }

    public function getPositioningClaims(): array { return $this->positioningClaims ?? []; }
    public function setPositioningClaims(?array $claims): self { $this->positioningClaims = $claims; return $this; }

    public function getCustomerClaims(): array { return $this->customerClaims ?? []; }
    public function setCustomerClaims(?array $claims): self { $this->customerClaims = $claims; return $this; }

    public function getHiringSignals(): array { return $this->hiringSignals ?? []; }
    public function setHiringSignals(?array $signals): self { $this->hiringSignals = $signals; return $this; }

    public function getEquipmentHints(): array { return $this->equipmentHints ?? []; }
    public function setEquipmentHints(?array $hints): self { $this->equipmentHints = $hints; return $this; }

    public function getContactsPublic(): array { return $this->contactsPublic ?? []; }
    public function setContactsPublic(?array $contacts): self { $this->contactsPublic = $contacts; return $this; }

    public function getLanguagesSupported(): array { return $this->languagesSupported ?? []; }
    public function setLanguagesSupported(?array $languages): self { $this->languagesSupported = $languages; return $this; }

    public function getEmployeeEstimate(): ?int { return $this->employeeEstimate; }
    public function setEmployeeEstimate(?int $estimate): self { $this->employeeEstimate = $estimate; return $this; }

    public function getHqCountry(): ?string { return $this->hqCountry; }
    public function setHqCountry(?string $country): self { $this->hqCountry = $country; return $this; }

    public function getEmsProfile(): ?array { return $this->emsProfile; }
    public function setEmsProfile(?array $profile): self { $this->emsProfile = $profile; return $this; }

    public function getMachiningProfile(): ?array { return $this->machiningProfile; }
    public function setMachiningProfile(?array $profile): self { $this->machiningProfile = $profile; return $this; }

    public function getHarnessProfile(): ?array { return $this->harnessProfile; }
    public function setHarnessProfile(?array $profile): self { $this->harnessProfile = $profile; return $this; }

    public function getSupercapProfile(): ?array { return $this->supercapProfile; }
    public function setSupercapProfile(?array $profile): self { $this->supercapProfile = $profile; return $this; }

    public function getEvidenceFamiliesPassed(): array { return $this->evidenceFamiliesPassed ?? []; }
    public function setEvidenceFamiliesPassed(?array $families): self { $this->evidenceFamiliesPassed = $families; return $this; }

    public function getEvidenceUrls(): array { return $this->evidenceUrls ?? []; }
    public function setEvidenceUrls(?array $urls): self { $this->evidenceUrls = $urls; return $this; }

    public function getVerificationTrace(): array { return $this->verificationTrace ?? []; }
    public function setVerificationTrace(?array $trace): self { $this->verificationTrace = $trace; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }

    public function getThreatScore(): int { return $this->threatScore; }
    public function setThreatScore(int $score): self { $this->threatScore = max(0, min(100, $score)); return $this; }

    public function getOverlapScore(): int { return $this->overlapScore; }
    public function setOverlapScore(int $score): self { $this->overlapScore = max(0, min(100, $score)); return $this; }

    public function getStrategicRelevanceScore(): int { return $this->strategicRelevanceScore; }
    public function setStrategicRelevanceScore(int $score): self { $this->strategicRelevanceScore = max(0, min(100, $score)); return $this; }

    public function getProofGrade(): ?string { return $this->proofGrade; }
    public function setProofGrade(?string $grade): self { $this->proofGrade = $grade; return $this; }

    public function getParentCompetitor(): ?Competitor { return $this->parentCompetitor; }
    public function setParentCompetitor(?Competitor $parent): self { $this->parentCompetitor = $parent; return $this; }

    public function getRelationshipGraph(): ?array { return $this->relationshipGraph; }
    public function setRelationshipGraph(?array $graph): self { $this->relationshipGraph = $graph; return $this; }

    public function getRawExtract(): ?string { return $this->rawExtract; }
    public function setRawExtract(?string $raw): self { $this->rawExtract = $raw; return $this; }

    public function getRawExtractExpiresAt(): ?\DateTimeInterface { return $this->rawExtractExpiresAt; }
    public function setRawExtractExpiresAt(?\DateTimeInterface $dt): self { $this->rawExtractExpiresAt = $dt; return $this; }

    public function getDiscoverySource(): ?string { return $this->discoverySource; }
    public function setDiscoverySource(?string $source): self { $this->discoverySource = $source; return $this; }

    public function isSeedOnly(): bool { return $this->seedOnly; }
    public function setSeedOnly(bool $seedOnly): self { $this->seedOnly = $seedOnly; return $this; }

    public function isTosBlocksCrawl(): bool { return $this->tosBlocksCrawl; }
    public function setTosBlocksCrawl(bool $blocks): self { $this->tosBlocksCrawl = $blocks; return $this; }

    public function getLastShallowCrawlAt(): ?\DateTimeInterface { return $this->lastShallowCrawlAt; }
    public function setLastShallowCrawlAt(?\DateTimeInterface $dt): self { $this->lastShallowCrawlAt = $dt; return $this; }

    public function getLastDeepCrawlAt(): ?\DateTimeInterface { return $this->lastDeepCrawlAt; }
    public function setLastDeepCrawlAt(?\DateTimeInterface $dt): self { $this->lastDeepCrawlAt = $dt; return $this; }

    public function getLastCrawledAt(): ?\DateTimeInterface { return $this->lastCrawledAt; }
    public function setLastCrawledAt(?\DateTimeInterface $dt): self { $this->lastCrawledAt = $dt; return $this; }

    public function getProfileVersion(): int { return $this->profileVersion; }
    public function setProfileVersion(int $v): self { $this->profileVersion = $v; return $this; }
    public function incrementProfileVersion(): self { $this->profileVersion++; return $this; }

    public function getPagesCrawled(): int { return $this->pagesCrawled; }
    public function setPagesCrawled(int $count): self { $this->pagesCrawled = $count; return $this; }

    public function getDiscoveredAt(): ?\DateTimeInterface
    {
        return $this->discoveredAt;
    }

    public function setDiscoveredAt(?\DateTimeInterface $discoveredAt): self
    {
        $this->discoveredAt = $discoveredAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $createdAt): self { $this->createdAt = $createdAt; return $this; }

    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeInterface $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }

    /** @return Collection<int, CompetitorChangeEvent> */
    public function getChangeEvents(): Collection { return $this->changeEvents; }

    /** @return Collection<int, CompetitorPageFingerprint> */
    public function getPageFingerprints(): Collection { return $this->pageFingerprints; }

    // ═══════════════════════════════════════════════════════════════════
    // Convenience helpers
    // ═══════════════════════════════════════════════════════════════════

    /** Primary type label for display */
    public function getPrimaryTypeLabel(): string
    {
        $types = $this->getCompetitorTypes();
        if (empty($types)) {
            return 'Unknown';
        }
        return self::TYPE_LABELS[$types[0]] ?? $types[0];
    }

    /** Get the type group name */
    public function getTypeGroup(): string
    {
        $types = $this->getCompetitorTypes();
        $first = $types[0] ?? '';
        if (in_array($first, self::TYPE_GROUP_CORE_EMS, true)) return 'Core EMS';
        if (in_array($first, self::TYPE_GROUP_INTERCONNECT, true)) return 'Interconnect';
        if (in_array($first, self::TYPE_GROUP_MACHINING, true)) return 'Machining';
        if (in_array($first, self::TYPE_GROUP_ENERGY, true)) return 'Energy / Supercapacitors';
        return 'Other';
    }

    /** Is this a high-threat competitor? */
    public function isHighThreat(): bool
    {
        return $this->threatScore >= 70;
    }

    /** Has profiling data? */
    public function isProfiled(): bool
    {
        return !empty($this->capabilities) || !empty($this->certifications);
    }

    public function __toString(): string
    {
        return $this->name ?? '(unnamed competitor)';
    }
}
