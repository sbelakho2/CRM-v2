<?php

namespace App\Entity;

use App\Repository\FxRateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FxRateRepository::class)]
#[ORM\Table(name: 'fx_rates')]
#[ORM\Index(name: 'idx_currencies', columns: ['from_currency', 'to_currency'])]
#[ORM\Index(name: 'idx_asof', columns: ['asof'])]
class FxRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private ?string $fromCurrency = null; // USD, EUR, MAD, etc.

    #[ORM\Column(length: 10)]
    private ?string $toCurrency = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 6)]
    private ?string $rate = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $asof = null;

    #[ORM\Column(length: 36)]
    private ?string $versionId = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->asof = new \DateTime();
        $this->versionId = bin2hex(random_bytes(18));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFromCurrency(): ?string
    {
        return $this->fromCurrency;
    }

    public function setFromCurrency(string $fromCurrency): self
    {
        $this->fromCurrency = $fromCurrency;
        return $this;
    }

    public function getToCurrency(): ?string
    {
        return $this->toCurrency;
    }

    public function setToCurrency(string $toCurrency): self
    {
        $this->toCurrency = $toCurrency;
        return $this;
    }

    public function getRate(): ?string
    {
        return $this->rate;
    }

    public function setRate(string $rate): self
    {
        $this->rate = $rate;
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
