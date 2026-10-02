<?php

namespace App\Entity;

use App\Repository\AsmCurveRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AsmCurveRepository::class)]
#[ORM\Table(name: 'asm_curves')]
#[ORM\Index(name: 'idx_component_count_asof', columns: ['component_count_min', 'asof'])]
class AsmCurve
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
// Protected (not private): Doctrine assigns the identifier via reflection
    // on hydration, so static analysis never sees an int assignment.
    protected ?int $id = null;

    #[ORM\Column(type: 'integer')]
    private ?int $componentCountMin = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $componentCountMax = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 4)]
    private ?string $costPerUnit = null;

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

    public function getComponentCountMin(): ?int
    {
        return $this->componentCountMin;
    }

    public function setComponentCountMin(int $componentCountMin): self
    {
        $this->componentCountMin = $componentCountMin;
        return $this;
    }

    public function getComponentCountMax(): ?int
    {
        return $this->componentCountMax;
    }

    public function setComponentCountMax(?int $componentCountMax): self
    {
        $this->componentCountMax = $componentCountMax;
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
