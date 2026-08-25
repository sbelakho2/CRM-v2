<?php

namespace App\Entity;

use App\Repository\FreightTableRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FreightTableRepository::class)]
#[ORM\Table(name: 'freight_tables')]
#[ORM\Index(name: 'idx_route', columns: ['origin_port', 'destination_port'])]
#[ORM\Index(name: 'idx_effective_date', columns: ['effective_date'])]
#[ORM\HasLifecycleCallbacks]
class FreightTable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $originPort = null; // Tangier, Casablanca, etc.

    #[ORM\Column(length: 100)]
    private ?string $destinationPort = null; // Rotterdam, Hamburg, LA, NY, etc.

    #[ORM\Column(length: 50)]
    private ?string $transportMode = null; // Ocean, Air, Rail, Truck

    #[ORM\Column(length: 20)]
    private ?string $containerType = null; // 20GP, 40GP, 40HQ, LCL, FCL

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $costPerUnit = null; // Cost per container or per kg

    #[ORM\Column(length: 10)]
    private ?string $currency = null; // USD, EUR, MAD

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $transitDays = null; // Estimated transit time

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $effectiveDate = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $expiryDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $carrier = null; // Maersk, CMA CGM, etc.

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

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

    public function getOriginPort(): ?string
    {
        return $this->originPort;
    }

    public function setOriginPort(string $originPort): self
    {
        $this->originPort = $originPort;
        return $this;
    }

    public function getDestinationPort(): ?string
    {
        return $this->destinationPort;
    }

    public function setDestinationPort(string $destinationPort): self
    {
        $this->destinationPort = $destinationPort;
        return $this;
    }

    public function getTransportMode(): ?string
    {
        return $this->transportMode;
    }

    public function setTransportMode(string $transportMode): self
    {
        $this->transportMode = $transportMode;
        return $this;
    }

    public function getContainerType(): ?string
    {
        return $this->containerType;
    }

    public function setContainerType(string $containerType): self
    {
        $this->containerType = $containerType;
        return $this;
    }

    public function getCostPerUnit(): ?string
    {
        return $this->costPerUnit;
    }

    public function setCostPerUnit(string $costPerUnit): self
    {
        $this->costPerUnit = $costPerUnit;
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

    public function getTransitDays(): ?int
    {
        return $this->transitDays;
    }

    public function setTransitDays(?int $transitDays): self
    {
        $this->transitDays = $transitDays;
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

    public function getCarrier(): ?string
    {
        return $this->carrier;
    }

    public function setCarrier(?string $carrier): self
    {
        $this->carrier = $carrier;
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
