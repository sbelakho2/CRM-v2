<?php

namespace App\Entity;

use App\Repository\BomLineRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BomLineRepository::class)]
#[ORM\Table(name: 'bom_lines')]
#[ORM\Index(name: 'idx_bom_quote', columns: ['quote_id'])]
#[ORM\Index(name: 'idx_bom_mpn', columns: ['mpn'])]
#[ORM\Index(name: 'idx_bom_review', columns: ['requires_review'])]
#[ORM\HasLifecycleCallbacks]
class BomLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Quote::class, inversedBy: 'bomLines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Quote $quote = null;

    #[ORM\Column]
    private ?int $lineNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mpn = null;
    
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalMpn = null; // Original MPN from BOM before normalization

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $manufacturer = null;
    
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $matchedMpn = null; // MPN returned by API (may differ from requested)

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;
    
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bomDescription = null; // Original description from BOM

    #[ORM\Column]
    protected ?int $quantity = 1;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $unitPrice = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $extendedPrice = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $procurementSource = null; // mouser, digikey, nexar, manual

    #[ORM\Column(nullable: true)]
    protected ?bool $hasException = false;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $exceptionReason = null;

    #[ORM\Column(nullable: true)]
    private ?int $leadTimeDays = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $availability = null; // In-Stock, Partial, Factory
    
    // Confidence scoring fields
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $confidenceScore = null; // 0-100
    
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $confidenceLevel = null; // HIGH, MEDIUM, LOW, VERY_LOW
    
    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $confidenceReasons = null;
    
    /** @var list<string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $confidenceWarnings = null;
    
    #[ORM\Column(nullable: true)]
    private ?bool $requiresReview = false;
    
    // Manual override fields
    #[ORM\Column(nullable: true)]
    private ?bool $manuallyVerified = false;
    
    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $manualUnitPrice = null;
    
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $manualNotes = null;
    
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $verifiedBy = null;
    
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $verifiedAt = null;
    
    // Lifecycle tracking
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $lifecycleStatus = null; // Active, NRND, Obsolete, etc.
    
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $lifecycleWarning = null; // null, 'warning', 'critical'
    
    // Source tracking for manual overrides
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $priceSourceUrl = null; // URL where the user found the price
    
    // Alternative parts storage
    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $alternativeParts = null; // Top 3-5 alternative part matches
    
    // Multi-distributor tracking
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $distributorSearchUrl = null; // Direct link to search on distributor site
    
    // Supplier tracking — actual source data per BOM line
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $supplierName = null; // Actual supplier/distributor name (e.g., "Shenzhen Jeking Electronics")
    
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $supplierProductUrl = null; // Direct link to supplier's product listing
    
    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $sourcingData = null; // Rich sourcing metadata (supplier info, crawl data, MOQ, etc.)

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
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

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuote(): ?Quote
    {
        return $this->quote;
    }

    public function setQuote(?Quote $quote): self
    {
        $this->quote = $quote;
        return $this;
    }

    public function getLineNumber(): ?int
    {
        return $this->lineNumber;
    }

    public function setLineNumber(int $lineNumber): self
    {
        $this->lineNumber = $lineNumber;
        return $this;
    }

    public function getMpn(): ?string
    {
        return $this->mpn;
    }

    public function setMpn(?string $mpn): self
    {
        $this->mpn = $mpn;
        return $this;
    }

    public function getManufacturer(): ?string
    {
        return $this->manufacturer;
    }

    public function setManufacturer(?string $manufacturer): self
    {
        $this->manufacturer = $manufacturer;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getUnitPrice(): ?string
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(?string $unitPrice): self
    {
        $this->unitPrice = $unitPrice;
        return $this;
    }

    public function getExtendedPrice(): ?string
    {
        return $this->extendedPrice;
    }

    public function setExtendedPrice(?string $extendedPrice): self
    {
        $this->extendedPrice = $extendedPrice;
        return $this;
    }

    public function getProcurementSource(): ?string
    {
        return $this->procurementSource;
    }

    public function setProcurementSource(?string $procurementSource): self
    {
        $this->procurementSource = $procurementSource;
        return $this;
    }

    public function hasException(): bool
    {
        return $this->hasException ?? false;
    }

    public function setHasException(bool $hasException): self
    {
        $this->hasException = $hasException;
        return $this;
    }

    public function getExceptionReason(): ?string
    {
        return $this->exceptionReason;
    }

    public function setExceptionReason(?string $exceptionReason): self
    {
        $this->exceptionReason = $exceptionReason;
        return $this;
    }

    public function getLeadTimeDays(): ?int
    {
        return $this->leadTimeDays;
    }

    public function setLeadTimeDays(?int $leadTimeDays): self
    {
        $this->leadTimeDays = $leadTimeDays;
        return $this;
    }

    public function getAvailability(): ?string
    {
        return $this->availability;
    }

    public function setAvailability(?string $availability): self
    {
        $this->availability = $availability;
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
    
    // Confidence scoring getters/setters
    
    public function getOriginalMpn(): ?string
    {
        return $this->originalMpn;
    }
    
    public function setOriginalMpn(?string $originalMpn): self
    {
        $this->originalMpn = $originalMpn;
        return $this;
    }
    
    public function getMatchedMpn(): ?string
    {
        return $this->matchedMpn;
    }
    
    public function setMatchedMpn(?string $matchedMpn): self
    {
        $this->matchedMpn = $matchedMpn;
        return $this;
    }
    
    public function getBomDescription(): ?string
    {
        return $this->bomDescription;
    }
    
    public function setBomDescription(?string $bomDescription): self
    {
        $this->bomDescription = $bomDescription;
        return $this;
    }
    
    public function getConfidenceScore(): ?int
    {
        return $this->confidenceScore;
    }
    
    public function setConfidenceScore(?int $confidenceScore): self
    {
        $this->confidenceScore = $confidenceScore;
        return $this;
    }
    
    public function getConfidenceLevel(): ?string
    {
        return $this->confidenceLevel;
    }
    
    public function setConfidenceLevel(?string $confidenceLevel): self
    {
        $this->confidenceLevel = $confidenceLevel;
        return $this;
    }
    
    /** @return list<string>|null */
    public function getConfidenceReasons(): ?array
    {
        return $this->confidenceReasons;
    }
    
    /** @param list<string>|null $confidenceReasons */
    public function setConfidenceReasons(?array $confidenceReasons): self
    {
        $this->confidenceReasons = $confidenceReasons;
        return $this;
    }
    
    /** @return list<string>|null */
    public function getConfidenceWarnings(): ?array
    {
        return $this->confidenceWarnings;
    }
    
    /** @param list<string>|null $confidenceWarnings */
    public function setConfidenceWarnings(?array $confidenceWarnings): self
    {
        $this->confidenceWarnings = $confidenceWarnings;
        return $this;
    }
    
    public function requiresReview(): bool
    {
        return $this->requiresReview ?? false;
    }

    public function setRequiresReview(?bool $requiresReview): self
    {
        $this->requiresReview = $requiresReview;
        return $this;
    }
    
    // Manual override getters/setters
    
    public function isManuallyVerified(): ?bool
    {
        return $this->manuallyVerified;
    }
    
    public function setManuallyVerified(?bool $manuallyVerified): self
    {
        $this->manuallyVerified = $manuallyVerified;
        return $this;
    }
    
    public function getManualUnitPrice(): ?string
    {
        return $this->manualUnitPrice;
    }
    
    public function setManualUnitPrice(?string $manualUnitPrice): self
    {
        $this->manualUnitPrice = $manualUnitPrice;
        return $this;
    }
    
    public function getManualNotes(): ?string
    {
        return $this->manualNotes;
    }
    
    public function setManualNotes(?string $manualNotes): self
    {
        $this->manualNotes = $manualNotes;
        return $this;
    }
    
    public function getVerifiedBy(): ?string
    {
        return $this->verifiedBy;
    }
    
    public function setVerifiedBy(?string $verifiedBy): self
    {
        $this->verifiedBy = $verifiedBy;
        return $this;
    }
    
    public function getVerifiedAt(): ?\DateTimeInterface
    {
        return $this->verifiedAt;
    }
    
    public function setVerifiedAt(?\DateTimeInterface $verifiedAt): self
    {
        $this->verifiedAt = $verifiedAt;
        return $this;
    }
    
    /**
     * Get the effective unit price (manual override takes precedence)
     */
    public function getEffectiveUnitPrice(): ?string
    {
        return self::resolveEffectiveUnitPrice($this->manualUnitPrice, $this->unitPrice);
    }

    public static function resolveEffectiveUnitPrice(?string $manualUnitPrice, ?string $unitPrice): ?string
    {
        if ($manualUnitPrice !== null) {
            return $manualUnitPrice;
        }
        return $unitPrice;
    }
    
    /**
     * Get the effective extended price as a formatted string
     * to maintain type consistency with other price fields.
     */
    public function getEffectiveExtendedPrice(): ?string
    {
        $unitPrice = $this->getEffectiveUnitPrice();
        if ($unitPrice === null) {
            return null;
        }
        return number_format((float)$unitPrice * ($this->quantity ?? 1), 2, '.', '');
    }
    
    /**
     * Check if this line needs attention
     */
    public function needsAttention(): bool
    {
        // Needs attention if requires review and not yet verified
        if ($this->requiresReview && !$this->manuallyVerified) {
            return true;
        }
        
        // Needs attention if no price set
        if ($this->unitPrice === null && $this->manualUnitPrice === null) {
            return true;
        }
        
        // Needs attention if lifecycle is critical
        if ($this->lifecycleWarning === 'critical') {
            return true;
        }
        
        return false;
    }
    
    // ==================== Lifecycle Tracking ====================
    
    public function getLifecycleStatus(): ?string
    {
        return $this->lifecycleStatus;
    }
    
    public function setLifecycleStatus(?string $lifecycleStatus): self
    {
        $this->lifecycleStatus = $lifecycleStatus;
        return $this;
    }
    
    public function getLifecycleWarning(): ?string
    {
        return $this->lifecycleWarning;
    }
    
    public function setLifecycleWarning(?string $lifecycleWarning): self
    {
        $this->lifecycleWarning = $lifecycleWarning;
        return $this;
    }
    
    /**
     * Check if lifecycle is critical (obsolete/EOL)
     */
    public function isLifecycleCritical(): bool
    {
        return $this->lifecycleWarning === 'critical';
    }
    
    /**
     * Check if lifecycle has any warning
     */
    public function hasLifecycleWarning(): bool
    {
        return $this->lifecycleWarning !== null;
    }
    
    // ==================== Source Tracking ====================
    
    public function getPriceSourceUrl(): ?string
    {
        return $this->priceSourceUrl;
    }
    
    public function setPriceSourceUrl(?string $priceSourceUrl): self
    {
        $this->priceSourceUrl = $priceSourceUrl;
        return $this;
    }
    
    public function getDistributorSearchUrl(): ?string
    {
        return $this->distributorSearchUrl;
    }
    
    public function setDistributorSearchUrl(?string $distributorSearchUrl): self
    {
        $this->distributorSearchUrl = $distributorSearchUrl;
        return $this;
    }
    
    // ==================== Alternative Parts ====================
    
    /** @return list<array<string, mixed>>|null */
    public function getAlternativeParts(): ?array
    {
        return $this->alternativeParts;
    }
    
    /** @param list<array<string, mixed>>|null $alternativeParts */
    public function setAlternativeParts(?array $alternativeParts): self
    {
        $this->alternativeParts = $alternativeParts;
        return $this;
    }
    
    /**
     * Check if alternatives are available
     */
    public function hasAlternatives(): bool
    {
        return !empty($this->alternativeParts);
    }
    
    /**
     * Get the number of available alternatives
     */
    public function getAlternativeCount(): int
    {
        return count($this->alternativeParts ?? []);
    }
    
    /**
     * Add a single alternative part
     *
     * @param array<string, mixed> $alternative
     */
    public function addAlternativePart(array $alternative): self
    {
        if ($this->alternativeParts === null) {
            $this->alternativeParts = [];
        }
        $this->alternativeParts[] = $alternative;
        return $this;
    }
    
    // ==================== Supplier Tracking ====================
    
    public function getSupplierName(): ?string
    {
        return $this->supplierName;
    }
    
    public function setSupplierName(?string $supplierName): self
    {
        $this->supplierName = $supplierName;
        return $this;
    }
    
    public function getSupplierProductUrl(): ?string
    {
        return $this->supplierProductUrl;
    }
    
    public function setSupplierProductUrl(?string $supplierProductUrl): self
    {
        $this->supplierProductUrl = $supplierProductUrl;
        return $this;
    }
    
    /** @return array<string, mixed>|null */
    public function getSourcingData(): ?array
    {
        return $this->sourcingData;
    }
    
    /** @param array<string, mixed>|null $sourcingData */
    public function setSourcingData(?array $sourcingData): self
    {
        $this->sourcingData = $sourcingData;
        return $this;
    }

    /**
     * Backward-compatible stock accessor sourced from JSON metadata.
     */
    public function getStock(): ?int
    {
        $stock = $this->sourcingData['stock'] ?? null;

        if ($stock === null || $stock === '' || !is_scalar($stock)) {
            return null;
        }

        return (int) $stock;
    }

    /**
     * Alias for getStock() - compatibility with QuoteWinPredictorService
     */
    public function getStockQuantity(): ?int
    {
        return $this->getStock();
    }

    /**
     * Check if this part was found via smart fallback (not an exact MPN match).
     */
    public function isFallback(): bool
    {
        $data = $this->sourcingData ?? [];
        return !empty($data['fallback_method']) || !empty($data['alt_mpn_used']);
    }

    /**
     * Get a human-readable label for the fallback method used.
     */
    public function getFallbackLabel(): ?string
    {
        $data = $this->sourcingData ?? [];
        $method = $data['fallback_method'] ?? null;

        if ($method) {
            return match ($method) {
                'cleaned_mpn' => 'Cleaned MPN',
                'base_mpn' => 'Base MPN',
                'keyword_search' => 'Keyword Search',
                'description_search' => 'Description Search',
                'mfr_keyword_search' => 'Mfr Keyword Search',
                default => 'Smart Fallback',
            };
        }

        if (!empty($data['alt_mpn_used'])) {
            return 'Alt MPN';
        }

        return null;
    }

    /**
     * Get the original MPN that this fallback replaced (if applicable).
     */
    public function getFallbackOriginalMpn(): ?string
    {
        $data = $this->sourcingData ?? [];
        $value = $data['fallback_original_mpn'] ?? null;
        if ($value === null) {
            return null;
        }
        return is_scalar($value) ? (string) $value : null;
    }
}
