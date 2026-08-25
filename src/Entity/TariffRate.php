<?php

namespace App\Entity;

use App\Repository\TariffRateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TariffRateRepository::class)]
#[ORM\Table(name: 'tariff_rates')]
#[ORM\Index(name: 'idx_hs_code', columns: ['hs_code'])]
#[ORM\Index(name: 'idx_effective_date', columns: ['effective_date'])]
#[ORM\HasLifecycleCallbacks]
class TariffRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private ?string $hsCode = null; // HS tariff code (e.g., 8541.10)

    #[ORM\Column(length: 100)]
    private ?string $originCountry = null; // Morocco, Mexico, US, EU, China, etc.

    #[ORM\Column(length: 100)]
    private ?string $destinationCountry = null; // US, EU, UK, etc.

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    private ?string $dutyRate = null; // Percentage (e.g., 2.50 for 2.5%)

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $mfnRate = null; // Most Favored Nation rate

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $ftaRate = null; // FTA preferential rate (0.00 if qualified)

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $effectiveDate = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $expiryDate = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ftaAgreement = null; // Morocco-US FTA, USMCA, EU-Morocco, etc.

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $versionId = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

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

    public function getHsCode(): ?string
    {
        return $this->hsCode;
    }

    public function setHsCode(string $hsCode): self
    {
        $this->hsCode = $hsCode;
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

    public function getDutyRate(): ?string
    {
        return $this->dutyRate;
    }

    public function setDutyRate(string $dutyRate): self
    {
        $this->dutyRate = $dutyRate;
        return $this;
    }

    public function getMfnRate(): ?string
    {
        return $this->mfnRate;
    }

    public function setMfnRate(?string $mfnRate): self
    {
        $this->mfnRate = $mfnRate;
        return $this;
    }

    public function getFtaRate(): ?string
    {
        return $this->ftaRate;
    }

    public function setFtaRate(?string $ftaRate): self
    {
        $this->ftaRate = $ftaRate;
        return $this;
    }

    public function getEffectiveDate(): ?\DateTimeInterface
    {
        return $this->effectiveDate;
    }

    public function setEffectiveDate(\DateTimeInterface $effectiveDate): self
    {
        $this->effectiveDate = $effectiveDate;
        return $this;
    }

    public function getExpiryDate(): ?\DateTimeInterface
    {
        return $this->expiryDate;
    }

    public function setExpiryDate(?\DateTimeInterface $expiryDate): self
    {
        $this->expiryDate = $expiryDate;
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

    public function getFtaAgreement(): ?string
    {
        return $this->ftaAgreement;
    }

    public function setFtaAgreement(?string $ftaAgreement): self
    {
        $this->ftaAgreement = $ftaAgreement;
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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
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
