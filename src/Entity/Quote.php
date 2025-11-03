<?php

namespace App\Entity;

use App\Repository\QuoteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuoteRepository::class)]
#[ORM\Table(name: 'quotes')]
#[ORM\Index(name: 'idx_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_quote_number', columns: ['quote_number'])]
#[ORM\Index(name: 'idx_status', columns: ['status'])]
class Quote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $quoteNumber = null; // QTE-2025-001

    #[ORM\Column(length: 50)]
    private ?string $status = 'draft'; // draft, pending_review, approved, sent, accepted, rejected

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private ?string $totalCost = '0.00';

    #[ORM\Column(length: 10)]
    private ?string $currency = 'USD';

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $coveragePercent = null; // % of spend from validated sources

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $alibabaPercent = null; // % of spend from Alibaba (indicative)

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $criticalDfmCount = null; // Number of critical DFM issues

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxImputedLeadTimeDays = null; // Max imputed lead time

    #[ORM\Column(type: 'boolean')]
    private bool $autoPublished = false; // Met auto-publish criteria

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $datasetVersionId = null; // Version of datasets used

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $apiVersions = null; // JSON: {mouser: "v1.2", digikey: "v3.0", etc.}

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bomDataJson = null; // Uploaded BOM data

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $shipToCountry = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $incoterms = null; // FCA, CIF, DDP, etc.

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $quantity = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\OneToMany(mappedBy: 'quote', targetEntity: QuotePartBreakdown::class, cascade: ['persist', 'remove'])]
    private Collection $partBreakdowns;

    #[ORM\OneToMany(mappedBy: 'quote', targetEntity: BomLine::class, cascade: ['persist', 'remove'])]
    private Collection $bomLines;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->partBreakdowns = new ArrayCollection();
        $this->bomLines = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->generateQuoteNumber();
    }

    private function generateQuoteNumber(): void
    {
        $this->quoteNumber = 'QTE-' . date('Y') . '-' . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);
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

    public function getQuoteNumber(): ?string
    {
        return $this->quoteNumber;
    }

    public function setQuoteNumber(string $quoteNumber): self
    {
        $this->quoteNumber = $quoteNumber;
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

    public function getTotalCost(): ?string
    {
        return $this->totalCost;
    }

    public function setTotalCost(string $totalCost): self
    {
        $this->totalCost = $totalCost;
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

    public function getCoveragePercent(): ?string
    {
        return $this->coveragePercent;
    }

    public function setCoveragePercent(?string $coveragePercent): self
    {
        $this->coveragePercent = $coveragePercent;
        return $this;
    }

    public function getAlibabaPercent(): ?string
    {
        return $this->alibabaPercent;
    }

    public function setAlibabaPercent(?string $alibabaPercent): self
    {
        $this->alibabaPercent = $alibabaPercent;
        return $this;
    }

    public function getCriticalDfmCount(): ?int
    {
        return $this->criticalDfmCount;
    }

    public function setCriticalDfmCount(?int $criticalDfmCount): self
    {
        $this->criticalDfmCount = $criticalDfmCount;
        return $this;
    }

    public function getMaxImputedLeadTimeDays(): ?int
    {
        return $this->maxImputedLeadTimeDays;
    }

    public function setMaxImputedLeadTimeDays(?int $maxImputedLeadTimeDays): self
    {
        $this->maxImputedLeadTimeDays = $maxImputedLeadTimeDays;
        return $this;
    }

    public function isAutoPublished(): bool
    {
        return $this->autoPublished;
    }

    public function setAutoPublished(bool $autoPublished): self
    {
        $this->autoPublished = $autoPublished;
        return $this;
    }

    public function getDatasetVersionId(): ?string
    {
        return $this->datasetVersionId;
    }

    public function setDatasetVersionId(?string $datasetVersionId): self
    {
        $this->datasetVersionId = $datasetVersionId;
        return $this;
    }

    public function getApiVersions(): ?array
    {
        return $this->apiVersions;
    }

    public function setApiVersions(?array $apiVersions): self
    {
        $this->apiVersions = $apiVersions;
        return $this;
    }

    public function getBomDataJson(): ?string
    {
        return $this->bomDataJson;
    }

    public function setBomDataJson(?string $bomDataJson): self
    {
        $this->bomDataJson = $bomDataJson;
        return $this;
    }

    public function getShipToCountry(): ?string
    {
        return $this->shipToCountry;
    }

    public function setShipToCountry(?string $shipToCountry): self
    {
        $this->shipToCountry = $shipToCountry;
        return $this;
    }

    public function getIncoterms(): ?string
    {
        return $this->incoterms;
    }

    public function setIncoterms(?string $incoterms): self
    {
        $this->incoterms = $incoterms;
        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(?int $quantity): self
    {
        $this->quantity = $quantity;
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
     * @return Collection<int, QuotePartBreakdown>
     */
    public function getPartBreakdowns(): Collection
    {
        return $this->partBreakdowns;
    }

    public function addPartBreakdown(QuotePartBreakdown $partBreakdown): self
    {
        if (!$this->partBreakdowns->contains($partBreakdown)) {
            $this->partBreakdowns->add($partBreakdown);
            $partBreakdown->setQuote($this);
        }
        return $this;
    }

    public function removePartBreakdown(QuotePartBreakdown $partBreakdown): self
    {
        if ($this->partBreakdowns->removeElement($partBreakdown)) {
            if ($partBreakdown->getQuote() === $this) {
                $partBreakdown->setQuote(null);
            }
        }
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

    /**
     * @return Collection<int, BomLine>
     */
    public function getBomLines(): Collection
    {
        return $this->bomLines;
    }

    public function addBomLine(BomLine $bomLine): self
    {
        if (!$this->bomLines->contains($bomLine)) {
            $this->bomLines->add($bomLine);
            $bomLine->setQuote($this);
        }
        return $this;
    }

    public function removeBomLine(BomLine $bomLine): self
    {
        if ($this->bomLines->removeElement($bomLine)) {
            if ($bomLine->getQuote() === $this) {
                $bomLine->setQuote(null);
            }
        }
        return $this;
    }
}
