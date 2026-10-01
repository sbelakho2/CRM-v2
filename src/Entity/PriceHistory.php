<?php

namespace App\Entity;

use App\Repository\PriceHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Price History Entity
 * 
 * Stores historical pricing data from distributor APIs for:
 * - Price trend analysis ("best time to buy")
 * - Audit trail of pricing decisions
 * - Alternative sourcing comparisons
 * - Price volatility detection
 */
#[ORM\Entity(repositoryClass: PriceHistoryRepository::class)]
#[ORM\Table(name: 'price_history')]
#[ORM\Index(name: 'idx_ph_mpn', columns: ['mpn'])]
#[ORM\Index(name: 'idx_ph_source', columns: ['source'])]
#[ORM\Index(name: 'idx_ph_date', columns: ['recorded_at'])]
#[ORM\Index(name: 'idx_ph_mpn_source', columns: ['mpn', 'source'])]
#[ORM\HasLifecycleCallbacks]
class PriceHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $mpn = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $matchedMpn = null; // MPN returned by API

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $manufacturer = null;

    #[ORM\Column(length: 50)]
    private ?string $source = null; // mouser, digikey, nexar

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4)]
    private ?string $unitPrice = null; // Price at qty 1

    #[ORM\Column(type: 'json')]
    private array $priceBreaks = []; // Full price break structure

    #[ORM\Column(length: 10)]
    private ?string $currency = 'USD';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $unitPriceUsd = null; // Normalized to USD

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $stockAvailable = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $leadTimeDays = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $lifecycleStatus = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $confidenceScore = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $confidenceLevel = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $moq = null; // Minimum order quantity

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $packQuantity = null; // Pack/reel quantity

    #[ORM\ManyToOne(targetEntity: Quote::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Quote $quote = null; // Which quote triggered this lookup

    #[ORM\ManyToOne(targetEntity: BomLine::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?BomLine $bomLine = null; // Which BOM line this was for

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $recordedAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rawApiResponse = null; // For debugging

    public function __construct()
    {
        $this->priceBreaks = [];
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->recordedAt === null) {
            $this->recordedAt = new \DateTime();
        }
    }

    // ==================== Getters and Setters ====================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMpn(): ?string
    {
        return $this->mpn;
    }

    public function setMpn(string $mpn): self
    {
        $this->mpn = $mpn;
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

    public function getManufacturer(): ?string
    {
        return $this->manufacturer;
    }

    public function setManufacturer(?string $manufacturer): self
    {
        $this->manufacturer = $manufacturer;
        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;
        return $this;
    }

    public function getUnitPrice(): ?string
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(string $unitPrice): self
    {
        $this->unitPrice = $unitPrice;
        return $this;
    }

    public function getPriceBreaks(): array
    {
        return $this->priceBreaks;
    }

    /**
     * @param array<string|int, mixed> $priceBreaks
     */
    public /**
 * @param array<string|int, mixed> $priceBreaks
 */
function setPriceBreaks(array $priceBreaks): self
    {
        $this->priceBreaks = $priceBreaks;
        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;
        return $this;
    }

    public function getUnitPriceUsd(): ?string
    {
        return $this->unitPriceUsd;
    }

    public function setUnitPriceUsd(?string $unitPriceUsd): self
    {
        $this->unitPriceUsd = $unitPriceUsd;
        return $this;
    }

    public function getStockAvailable(): ?int
    {
        return $this->stockAvailable;
    }

    public function setStockAvailable(?int $stockAvailable): self
    {
        $this->stockAvailable = $stockAvailable;
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

    public function getLifecycleStatus(): ?string
    {
        return $this->lifecycleStatus;
    }

    public function setLifecycleStatus(?string $lifecycleStatus): self
    {
        $this->lifecycleStatus = $lifecycleStatus;
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

    public function getMoq(): ?int
    {
        return $this->moq;
    }

    public function setMoq(?int $moq): self
    {
        $this->moq = $moq;
        return $this;
    }

    public function getPackQuantity(): ?int
    {
        return $this->packQuantity;
    }

    public function setPackQuantity(?int $packQuantity): self
    {
        $this->packQuantity = $packQuantity;
        return $this;
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

    public function getBomLine(): ?BomLine
    {
        return $this->bomLine;
    }

    public function setBomLine(?BomLine $bomLine): self
    {
        $this->bomLine = $bomLine;
        return $this;
    }

    public function getRecordedAt(): ?\DateTimeInterface
    {
        return $this->recordedAt;
    }

    public function setRecordedAt(\DateTimeInterface $recordedAt): self
    {
        $this->recordedAt = $recordedAt;
        return $this;
    }

    public function getRawApiResponse(): ?array
    {
    /**
     * @param array<string|int, mixed> $rawApiResponse
     */
        return $this->rawApiResponse;
    }

    public /**
 * @param array<string|int, mixed> $rawApiResponse
 */
function setRawApiResponse(?array $rawApiResponse): self
    {
        $this->rawApiResponse = $rawApiResponse;
        return $this;
    }

    // ==================== Helper Methods ====================

    /**
     * Get price for a specific quantity using price breaks
     */
    public function getPriceForQuantity(int $quantity): ?float
    {
        if (empty($this->priceBreaks)) {
            return $this->unitPrice ? (float) $this->unitPrice : null;
        }

        // Sort by quantity ascending
        $breaks = $this->priceBreaks;
        usort($breaks, fn($a, $b) => ($a['quantity'] ?? 0) <=> ($b['quantity'] ?? 0));

        $applicablePrice = $breaks[0]['price'] ?? null;

        foreach ($breaks as $break) {
            if ($quantity >= ($break['quantity'] ?? 0)) {
                $applicablePrice = $break['price'] ?? $applicablePrice;
            } else {
                break;
            }
        }

        return $applicablePrice ? (float) $applicablePrice : null;
    }

    /**
     * Calculate effective quantity considering MOQ
     */
    public function getEffectiveQuantity(int $requestedQty): int
    {
        if ($this->moq && $requestedQty < $this->moq) {
            return $this->moq;
        }

        // Snap to pack quantity if applicable
        if ($this->packQuantity && $this->packQuantity > 1) {
            return (int) ceil($requestedQty / $this->packQuantity) * $this->packQuantity;
        }

        return $requestedQty;
    }
}
