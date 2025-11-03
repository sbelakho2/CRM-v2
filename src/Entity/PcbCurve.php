<?php

namespace App\Entity;

use App\Repository\PcbCurveRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PcbCurveRepository::class)]
#[ORM\Table(name: 'pcb_curves')]
#[ORM\Index(name: 'idx_layers_asof', columns: ['layers', 'asof'])]
class PcbCurve
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private ?int $layers = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private ?string $areaM2 = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 4)]
    private ?string $costPerM2 = null;

    #[ORM\Column(length: 3)]
    private ?string $currency = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $asof = null;

    #[ORM\Column(length: 100)]
    private ?string $versionId = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLayers(): ?int
    {
        return $this->layers;
    }

    public function setLayers(int $layers): self
    {
        $this->layers = $layers;
        return $this;
    }

    public function getAreaM2(): ?string
    {
        return $this->areaM2;
    }

    public function setAreaM2(string $areaM2): self
    {
        $this->areaM2 = $areaM2;
        return $this;
    }

    public function getCostPerM2(): ?string
    {
        return $this->costPerM2;
    }

    public function setCostPerM2(string $costPerM2): self
    {
        $this->costPerM2 = $costPerM2;
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
}
