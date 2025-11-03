<?php

namespace App\Entity;

use App\Repository\BomLineRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BomLineRepository::class)]
#[ORM\Table(name: 'bom_line')]
#[ORM\Index(name: 'idx_bom_quote', columns: ['quote_id'])]
#[ORM\Index(name: 'idx_bom_mpn', columns: ['mpn'])]
class BomLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Quote::class, inversedBy: 'bomLines')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Quote $quote = null;

    #[ORM\Column]
    private ?int $lineNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mpn = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $manufacturer = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private ?int $quantity = 1;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $unitPrice = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $extendedPrice = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $procurementSource = null; // API, Pricebook, Manual, Imputed

    #[ORM\Column(nullable: true)]
    private ?bool $hasException = false;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $exceptionReason = null;

    #[ORM\Column(nullable: true)]
    private ?int $leadTimeDays = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $availability = null; // In-Stock, Partial, Factory

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
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

    public function isHasException(): ?bool
    {
        return $this->hasException;
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
}
