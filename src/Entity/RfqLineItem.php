<?php

namespace App\Entity;

use App\Repository\RfqLineItemRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * RFQ Line Item Entity
 * 
 * Represents individual line items in an RFQ:
 * - Part numbers and descriptions
 * - Quantities and pricing
 * - Technical specifications
 */
#[ORM\Entity(repositoryClass: RfqLineItemRepository::class)]
#[ORM\Table(name: 'rfq_line_items')]
#[ORM\HasLifecycleCallbacks]
class RfqLineItem
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUOTED = 'quoted';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_AWARDED = 'awarded';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    
    #[ORM\ManyToOne(targetEntity: RFQ::class, inversedBy: 'lineItems')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?RFQ $rfq = null;
    
    #[ORM\Column(type: 'integer')]
    private int $lineNumber = 1;
    
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $partNumber = null;
    
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $customerPartNumber = null;
    
    #[ORM\Column(length: 255)]
    private ?string $description = null;
    
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $quantityAnnual = null;
    
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $quantityPerBatch = null;
    
    #[ORM\Column(type: 'decimal', precision: 15, scale: 4, nullable: true)]
    private ?string $unitPrice = null;
    
    #[ORM\Column(type: 'decimal', precision: 15, scale: 4, nullable: true)]
    private ?string $nrePrice = null; // Non-Recurring Engineering (tooling, fixtures)
    
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $currency = 'EUR';
    
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $leadTimeDays = null;
    
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $technology = null; // SMT, THT, Mixed, Cable Assembly
    
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $componentCount = null; // Number of components on BOM
    
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $requiresXray = false;
    
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $requiresAoi = false;
    
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $requiresFunctionalTest = false;
    
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $requiresConformalCoating = false;
    
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $specifications = null;
    
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;
    
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $status = self::STATUS_PENDING; // pending, quoted, declined, awarded
    
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
    
    // Getters and Setters
    
    public function getId(): ?int
    {
        return $this->id;
    }
    
    public function getRfq(): ?RFQ
    {
        return $this->rfq;
    }
    
    public function setRfq(?RFQ $rfq): self
    {
        $this->rfq = $rfq;
        return $this;
    }
    
    public function getLineNumber(): int
    {
        return $this->lineNumber;
    }
    
    public function setLineNumber(int $lineNumber): self
    {
        $this->lineNumber = $lineNumber;
        return $this;
    }
    
    public function getPartNumber(): ?string
    {
        return $this->partNumber;
    }
    
    public function setPartNumber(?string $partNumber): self
    {
        $this->partNumber = $partNumber;
        return $this;
    }
    
    public function getCustomerPartNumber(): ?string
    {
        return $this->customerPartNumber;
    }
    
    public function setCustomerPartNumber(?string $customerPartNumber): self
    {
        $this->customerPartNumber = $customerPartNumber;
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
    
    public function getQuantityAnnual(): ?int
    {
        return $this->quantityAnnual;
    }
    
    public function setQuantityAnnual(?int $quantityAnnual): self
    {
        $this->quantityAnnual = $quantityAnnual;
        return $this;
    }
    
    public function getQuantityPerBatch(): ?int
    {
        return $this->quantityPerBatch;
    }
    
    public function setQuantityPerBatch(?int $quantityPerBatch): self
    {
        $this->quantityPerBatch = $quantityPerBatch;
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
    
    public function getNrePrice(): ?string
    {
        return $this->nrePrice;
    }
    
    public function setNrePrice(?string $nrePrice): self
    {
        $this->nrePrice = $nrePrice;
        return $this;
    }
    
    public function getCurrency(): ?string
    {
        return $this->currency;
    }
    
    public function setCurrency(?string $currency): self
    {
        $this->currency = $currency;
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
    
    public function getTechnology(): ?string
    {
        return $this->technology;
    }
    
    public function setTechnology(?string $technology): self
    {
        $this->technology = $technology;
        return $this;
    }
    
    public function getComponentCount(): ?int
    {
        return $this->componentCount;
    }
    
    public function setComponentCount(?int $componentCount): self
    {
        $this->componentCount = $componentCount;
        return $this;
    }
    
    public function getRequiresXray(): bool
    {
        return $this->requiresXray;
    }
    
    public function setRequiresXray(bool $requiresXray): self
    {
        $this->requiresXray = $requiresXray;
        return $this;
    }
    
    public function getRequiresAoi(): bool
    {
        return $this->requiresAoi;
    }
    
    public function setRequiresAoi(bool $requiresAoi): self
    {
        $this->requiresAoi = $requiresAoi;
        return $this;
    }
    
    public function getRequiresFunctionalTest(): bool
    {
        return $this->requiresFunctionalTest;
    }
    
    public function setRequiresFunctionalTest(bool $requiresFunctionalTest): self
    {
        $this->requiresFunctionalTest = $requiresFunctionalTest;
        return $this;
    }
    
    public function getRequiresConformalCoating(): bool
    {
        return $this->requiresConformalCoating;
    }
    
    public function setRequiresConformalCoating(bool $requiresConformalCoating): self
    {
        $this->requiresConformalCoating = $requiresConformalCoating;
        return $this;
    }
    
    public function getSpecifications(): ?string
    {
        return $this->specifications;
    }
    
    public function setSpecifications(?string $specifications): self
    {
        $this->specifications = $specifications;
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
    
    public function getStatus(): ?string
    {
        return $this->status;
    }
    
    public function setStatus(?string $status): self
    {
        $this->status = $status;
        return $this;
    }
    
    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
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
     * Calculate line total (annual quantity × unit price)
     */
    public function getLineTotal(): float
    {
        $unitPrice = (float) ($this->unitPrice ?? 0);
        $quantity = $this->quantityAnnual ?? 0;
        return $unitPrice * $quantity;
    }
    
    /**
     * Calculate total value including NRE
     */
    public function getTotalValue(): float
    {
        return $this->getLineTotal() + (float) ($this->nrePrice ?? 0);
    }
}
