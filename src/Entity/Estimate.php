<?php

namespace App\Entity;

use App\Repository\EstimateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EstimateRepository::class)]
#[ORM\Table(name: 'estimates')]
#[ORM\Index(name: 'idx_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_estimate_number', columns: ['estimate_number'])]
class Estimate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\ManyToOne(targetEntity: RFQ::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?RFQ $rfq = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $estimateNumber = null; // EST-2025-001

    #[ORM\Column(length: 100)]
    private ?string $originCountry = null; // Morocco, Mexico, etc.

    #[ORM\Column(length: 100)]
    private ?string $destinationCountry = null; // US, EU, UK

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $originPort = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $destinationPort = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private ?string $materialCost = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private ?string $laborCost = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private ?string $freightCost = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private ?string $dutyCost = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $otherCosts = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private ?string $totalLandedCost = null;

    #[ORM\Column(length: 10)]
    private ?string $currency = null; // USD, EUR, MAD

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $dutyRate = null; // Applicable duty percentage

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ftaAgreement = null; // Morocco-US FTA, USMCA, etc.

    #[ORM\Column(type: 'boolean')]
    private bool $ftaQualified = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bomData = null; // JSON string of BOM items

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sha256Hash = null; // SHA-256 hash for audit trail

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $versionId = null; // Dataset version identifier

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->generateEstimateNumber();
    }

    private function generateEstimateNumber(): void
    {
        $this->estimateNumber = 'EST-' . date('Y') . '-' . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);
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

    public function getRfq(): ?RFQ
    {
        return $this->rfq;
    }

    public function setRfq(?RFQ $rfq): self
    {
        $this->rfq = $rfq;
        return $this;
    }

    public function getEstimateNumber(): ?string
    {
        return $this->estimateNumber;
    }

    public function setEstimateNumber(string $estimateNumber): self
    {
        $this->estimateNumber = $estimateNumber;
        return $this;
    }

    public function getOriginCountry(): ?string
    {
        return $this->originCountry;
    }

    public function setOriginCountry(string $originCountry): self
    {
        $this->originCountry = $originCountry;
        return $this;
    }

    public function getDestinationCountry(): ?string
    {
        return $this->destinationCountry;
    }

    public function setDestinationCountry(string $destinationCountry): self
    {
        $this->destinationCountry = $destinationCountry;
        return $this;
    }

    public function getOriginPort(): ?string
    {
        return $this->originPort;
    }

    public function setOriginPort(?string $originPort): self
    {
        $this->originPort = $originPort;
        return $this;
    }

    public function getDestinationPort(): ?string
    {
        return $this->destinationPort;
    }

    public function setDestinationPort(?string $destinationPort): self
    {
        $this->destinationPort = $destinationPort;
        return $this;
    }

    public function getMaterialCost(): ?string
    {
        return $this->materialCost;
    }

    public function setMaterialCost(string $materialCost): self
    {
        $this->materialCost = $materialCost;
        return $this;
    }

    public function getLaborCost(): ?string
    {
        return $this->laborCost;
    }

    public function setLaborCost(string $laborCost): self
    {
        $this->laborCost = $laborCost;
        return $this;
    }

    public function getFreightCost(): ?string
    {
        return $this->freightCost;
    }

    public function setFreightCost(string $freightCost): self
    {
        $this->freightCost = $freightCost;
        return $this;
    }

    public function getDutyCost(): ?string
    {
        return $this->dutyCost;
    }

    public function setDutyCost(string $dutyCost): self
    {
        $this->dutyCost = $dutyCost;
        return $this;
    }

    public function getOtherCosts(): ?string
    {
        return $this->otherCosts;
    }

    public function setOtherCosts(?string $otherCosts): self
    {
        $this->otherCosts = $otherCosts;
        return $this;
    }

    public function getTotalLandedCost(): ?string
    {
        return $this->totalLandedCost;
    }

    public function setTotalLandedCost(string $totalLandedCost): self
    {
        $this->totalLandedCost = $totalLandedCost;
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

    public function getDutyRate(): ?string
    {
        return $this->dutyRate;
    }

    public function setDutyRate(?string $dutyRate): self
    {
        $this->dutyRate = $dutyRate;
        return $this;
    }

    public function getFtaAgreement(): ?string
    {
        return $this->ftaAgreement;
    }

    public function setFtaAgreement(?string $ftaAgreement): self
    {
        $this->ftaAgreement = $ftaAgreement;
        return $this;
    }

    public function isFtaQualified(): bool
    {
        return $this->ftaQualified;
    }

    public function setFtaQualified(bool $ftaQualified): self
    {
        $this->ftaQualified = $ftaQualified;
        return $this;
    }

    public function getBomData(): ?string
    {
        return $this->bomData;
    }

    public function setBomData(?string $bomData): self
    {
        $this->bomData = $bomData;
        return $this;
    }

    public function getSha256Hash(): ?string
    {
        return $this->sha256Hash;
    }

    public function setSha256Hash(?string $sha256Hash): self
    {
        $this->sha256Hash = $sha256Hash;
        return $this;
    }

    public function getVersionId(): ?string
    {
        return $this->versionId;
    }

    public function setVersionId(?string $versionId): self
    {
        $this->versionId = $versionId;
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
