<?php

namespace App\Entity;

use App\Repository\NreTableRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NreTableRepository::class)]
#[ORM\Table(name: 'nre_tables')]
#[ORM\Index(name: 'idx_service_type_asof', columns: ['service_type', 'asof'])]
class NreTable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $serviceType = null; // PCB_TOOLING, STENCIL, FIXTURE, FIRST_ARTICLE, etc.

    #[ORM\Column(type: 'decimal', precision: 15, scale: 4)]
    private ?string $flatFee = null;

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

    public function getServiceType(): ?string
    {
        return $this->serviceType;
    }

    public function setServiceType(string $serviceType): self
    {
        $this->serviceType = $serviceType;
        return $this;
    }

    public function getFlatFee(): ?string
    {
        return $this->flatFee;
    }

    public function setFlatFee(string $flatFee): self
    {
        $this->flatFee = $flatFee;
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
