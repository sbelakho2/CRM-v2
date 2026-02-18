<?php

namespace App\Entity;

use App\Repository\CompetitorChangeEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CompetitorChangeEventRepository::class)]
#[ORM\Table(name: 'competitor_change_events')]
#[ORM\Index(name: 'idx_cce_competitor', columns: ['competitor_id'])]
#[ORM\Index(name: 'idx_cce_type', columns: ['change_type'])]
#[ORM\Index(name: 'idx_cce_severity', columns: ['severity'])]
#[ORM\Index(name: 'idx_cce_created', columns: ['created_at'])]
class CompetitorChangeEvent
{
    // ── Change type constants ──
    public const TYPE_NEW_CERTIFICATION     = 'new_certification';
    public const TYPE_EXPIRED_CERTIFICATION = 'expired_certification';
    public const TYPE_NEW_FACILITY          = 'new_facility';
    public const TYPE_FACILITY_EXPANSION    = 'facility_expansion';
    public const TYPE_NEW_CAPABILITY        = 'new_capability';
    public const TYPE_REMOVED_CAPABILITY    = 'removed_capability';
    public const TYPE_NEW_INDUSTRY_FOCUS    = 'new_industry_focus';
    public const TYPE_REMOVED_INDUSTRY      = 'removed_industry';
    public const TYPE_POSITIONING_SHIFT     = 'major_positioning_shift';
    public const TYPE_HIRING_SURGE          = 'hiring_surge';
    public const TYPE_NEW_EQUIPMENT         = 'new_equipment';
    public const TYPE_NEW_CUSTOMER_CLAIM    = 'new_customer_claim';
    public const TYPE_DOMAIN_CHANGE         = 'domain_change';
    public const TYPE_NEW_5AXIS             = 'new_5axis_capability';
    public const TYPE_NEW_AS9100            = 'new_as9100_claim';
    public const TYPE_NEW_AERO_FOCUS        = 'new_aerospace_focus';
    public const TYPE_ADDED_OVERMOLDING     = 'added_overmolding';
    public const TYPE_NEW_IPC_WHMA          = 'new_ipc_whma_a620';
    public const TYPE_NEW_IATF_CLAIM        = 'new_automotive_iatf_claim';
    public const TYPE_NEW_EDLC_SERIES       = 'new_edlc_cell_series';
    public const TYPE_NEW_LIC_LINE          = 'new_lic_line';
    public const TYPE_DATASHEET_CHANGE      = 'datasheet_specs_changed';
    public const TYPE_FACTORY_EXPANSION     = 'factory_expansion';
    public const TYPE_WEBSITE_RESTRUCTURE   = 'website_restructure';
    public const TYPE_CAPACITY_CHANGE       = 'capacity_change';
    public const TYPE_SCORE_CHANGE          = 'score_change';
    public const TYPE_NEW_MATERIAL_FAMILY   = 'new_material_family';
    public const TYPE_NEW_CONNECTOR_PARTNER = 'new_connector_partner';

    // Aliases for convenience
    public const TYPE_CERT_ADDED            = self::TYPE_NEW_CERTIFICATION;
    public const TYPE_CERT_REMOVED          = self::TYPE_EXPIRED_CERTIFICATION;
    public const TYPE_CAPABILITY_ADDED      = self::TYPE_NEW_CAPABILITY;
    public const TYPE_CAPABILITY_REMOVED    = self::TYPE_REMOVED_CAPABILITY;
    public const TYPE_INDUSTRY_ADDED        = self::TYPE_NEW_INDUSTRY_FOCUS;
    public const TYPE_INDUSTRY_REMOVED      = self::TYPE_REMOVED_INDUSTRY;
    public const TYPE_NEW_5AXIS_CAPABILITY  = self::TYPE_NEW_5AXIS;
    public const TYPE_NEW_EDLC_CELL_SERIES  = self::TYPE_NEW_EDLC_SERIES;

    // ── Valid change types (canonical set) ──
    public const VALID_CHANGE_TYPES = [
        self::TYPE_NEW_CERTIFICATION,
        self::TYPE_EXPIRED_CERTIFICATION,
        self::TYPE_NEW_FACILITY,
        self::TYPE_FACILITY_EXPANSION,
        self::TYPE_NEW_CAPABILITY,
        self::TYPE_REMOVED_CAPABILITY,
        self::TYPE_NEW_INDUSTRY_FOCUS,
        self::TYPE_REMOVED_INDUSTRY,
        self::TYPE_POSITIONING_SHIFT,
        self::TYPE_HIRING_SURGE,
        self::TYPE_NEW_EQUIPMENT,
        self::TYPE_NEW_CUSTOMER_CLAIM,
        self::TYPE_DOMAIN_CHANGE,
        self::TYPE_NEW_5AXIS,
        self::TYPE_NEW_AS9100,
        self::TYPE_NEW_AERO_FOCUS,
        self::TYPE_ADDED_OVERMOLDING,
        self::TYPE_NEW_IPC_WHMA,
        self::TYPE_NEW_IATF_CLAIM,
        self::TYPE_NEW_EDLC_SERIES,
        self::TYPE_NEW_LIC_LINE,
        self::TYPE_DATASHEET_CHANGE,
        self::TYPE_FACTORY_EXPANSION,
        self::TYPE_WEBSITE_RESTRUCTURE,
        self::TYPE_CAPACITY_CHANGE,
        self::TYPE_SCORE_CHANGE,
        self::TYPE_NEW_MATERIAL_FAMILY,
        self::TYPE_NEW_CONNECTOR_PARTNER,
    ];

    // ── Severity constants ──
    public const SEVERITY_LOW    = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH   = 'high';

    public const VALID_SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Competitor::class, inversedBy: 'changeEvents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Competitor $competitor = null;

    #[ORM\Column(length: 60)]
    private ?string $changeType = null;

    #[ORM\Column(length: 10, options: ['default' => 'low'])]
    private string $severity = self::SEVERITY_LOW;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $diffSummary = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $evidenceUrls = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $oldValue = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $newValue = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    // ── Getters/Setters ──

    public function getId(): ?int { return $this->id; }

    public function getCompetitor(): ?Competitor { return $this->competitor; }
    public function setCompetitor(?Competitor $competitor): self { $this->competitor = $competitor; return $this; }

    public function getChangeType(): ?string { return $this->changeType; }
    public function setChangeType(string $type): self
    {
        if (!in_array($type, self::VALID_CHANGE_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid change type: $type");
        }
        $this->changeType = $type;
        return $this;
    }

    public function getSeverity(): string { return $this->severity; }
    public function setSeverity(string $severity): self
    {
        if (!in_array($severity, self::VALID_SEVERITIES, true)) {
            throw new \InvalidArgumentException("Invalid severity: $severity");
        }
        $this->severity = $severity;
        return $this;
    }

    public function getDiffSummary(): ?string { return $this->diffSummary; }
    public function setDiffSummary(?string $summary): self { $this->diffSummary = $summary; return $this; }

    public function getEvidenceUrls(): array { return $this->evidenceUrls ?? []; }
    public function setEvidenceUrls(?array $urls): self { $this->evidenceUrls = $urls; return $this; }

    public function getOldValue(): ?array { return $this->oldValue; }
    public function setOldValue(?array $value): self { $this->oldValue = $value; return $this; }

    public function getNewValue(): ?array { return $this->newValue; }
    public function setNewValue(?array $value): self { $this->newValue = $value; return $this; }

    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $dt): self { $this->createdAt = $dt; return $this; }
}
