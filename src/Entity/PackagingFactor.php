<?php

namespace App\Entity;

use App\Repository\PackagingFactorRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PackagingFactorRepository::class)]
#[ORM\Table(name: 'packaging_factors')]
#[ORM\Index(name: 'idx_category', columns: ['category'])]
#[ORM\Index(name: 'idx_asof', columns: ['asof'])]
#[ORM\HasLifecycleCallbacks]
class PackagingFactor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $category = null; // Resistors, Capacitors, Connectors, PCB Assemblies, etc.

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4)]
    private ?string $kgPerUnit = null; // Weight per unit in kg

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4)]
    private ?string $dm3PerUnit = null; // Volume per unit in dm³

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $palletizationRuleJson = null; // JSON: {carton_dims_cm: [36,24,24], units_per_carton: 1000, cartons_per_pallet: 60}

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $asof = null; // Effective timestamp

    #[ORM\Column(length: 36)]
    private ?string $versionId = null; // UUID for dataset version

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTime();
        }
        if ($this->asof === null) {
            $this->asof = new \DateTime();
        }
        if ($this->versionId === null) {
            $this->versionId = bin2hex(random_bytes(18));
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function getKgPerUnit(): ?string
    {
        return $this->kgPerUnit;
    }

    public function setKgPerUnit(string $kgPerUnit): self
    {
        $this->kgPerUnit = $kgPerUnit;
        return $this;
    }

    public function getDm3PerUnit(): ?string
    {
        return $this->dm3PerUnit;
    }

    public function setDm3PerUnit(string $dm3PerUnit): self
    {
        $this->dm3PerUnit = $dm3PerUnit;
        return $this;
    }

    public function getPalletizationRuleJson(): ?array
    {
        return $this->palletizationRuleJson;
    }

    public function setPalletizationRuleJson(?array $palletizationRuleJson): self
    {
        $this->palletizationRuleJson = $palletizationRuleJson;
        return $this;
    }

    public function getAsof(): ?\DateTimeInterface
    {
        return $this->asof;
    }

    public function setAsof(\DateTimeInterface $asof): self
    {
        $this->asof = $asof;
        return $this;
    }

    public function getVersionId(): ?string
    {
        return $this->versionId;
    }

    public function setVersionId(string $versionId): self
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
}
