<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\QuotePartBreakdownRepository::class)]
#[ORM\Table(name: 'quote_part_breakdowns')]
class QuotePartBreakdown
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // Protected (not private): Doctrine assigns the identifier via reflection
    // on hydration, so static analysis never sees an int assignment.
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Quote::class, inversedBy: 'partBreakdowns')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Quote $quote = null;

    #[ORM\Column(length: 255)]
    private ?string $mpn = null; // Manufacturer Part Number

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $manufacturer = null;

    #[ORM\Column(type: 'integer')]
    private int $quantity = 1;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 4)]
    private string $unitPrice = '0.0000';

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $extendedPrice = '0.00';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $dataSource = null; // MOUSER|DIGIKEY|NEXAR|ALIBABA|PRICEBOOK|IMPUTED

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $leadTimeDays = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isImputed = false;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $category = null;

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

    public function getMpn(): ?string
    {
        return $this->mpn;
    }

    public function setMpn(string $mpn): self
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

    public function setUnitPrice(string $unitPrice): self
    {
        $this->unitPrice = $unitPrice;
        return $this;
    }

    public function getExtendedPrice(): ?string
    {
        return $this->extendedPrice;
    }

    public function setExtendedPrice(string $extendedPrice): self
    {
        $this->extendedPrice = $extendedPrice;
        return $this;
    }

    public function getDataSource(): ?string
    {
        return $this->dataSource;
    }

    public function setDataSource(?string $dataSource): self
    {
        $this->dataSource = $dataSource;
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

    public function isImputed(): bool
    {
        return $this->isImputed;
    }

    public function setIsImputed(bool $isImputed): self
    {
        $this->isImputed = $isImputed;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): self
    {
        $this->category = $category;
        return $this;
    }
}
