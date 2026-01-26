<?php

namespace App\Entity;

use App\Repository\BanditArmRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Thompson Sampling Bandit Arm
 * 
 * Multi-armed bandit for A/B testing subject lines, templates, send times.
 * Uses Beta distribution with alpha (successes) and beta (failures).
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
#[ORM\Entity(repositoryClass: BanditArmRepository::class)]
#[ORM\Table(name: 'bandit_arms')]
#[ORM\Index(name: 'idx_bandit_arm_type', columns: ['arm_type'])]
#[ORM\Index(name: 'idx_bandit_active', columns: ['active'])]
class BanditArm
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private ?string $armType = null; // 'subject_line', 'template', 'send_time'

    #[ORM\Column(length: 255)]
    private ?string $armName = null;

    #[ORM\Column(type: 'text')]
    private ?string $armValue = null;

    /**
     * Beta distribution alpha parameter (successes + 1 prior)
     */
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $alpha = 1;

    /**
     * Beta distribution beta parameter (failures + 1 prior)
     */
    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $beta = 1;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $totalTrials = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $totalSuccesses = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $active = true;

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

    public function getArmType(): ?string
    {
        return $this->armType;
    }

    public function setArmType(string $armType): self
    {
        $this->armType = $armType;
        return $this;
    }

    public function getArmName(): ?string
    {
        return $this->armName;
    }

    public function setArmName(string $armName): self
    {
        $this->armName = $armName;
        return $this;
    }

    public function getArmValue(): ?string
    {
        return $this->armValue;
    }

    public function setArmValue(string $armValue): self
    {
        $this->armValue = $armValue;
        return $this;
    }

    public function getAlpha(): int
    {
        return $this->alpha;
    }

    public function setAlpha(int $alpha): self
    {
        $this->alpha = $alpha;
        return $this;
    }

    public function getBeta(): int
    {
        return $this->beta;
    }

    public function setBeta(int $beta): self
    {
        $this->beta = $beta;
        return $this;
    }

    public function getTotalTrials(): int
    {
        return $this->totalTrials;
    }

    public function setTotalTrials(int $totalTrials): self
    {
        $this->totalTrials = $totalTrials;
        return $this;
    }

    public function getTotalSuccesses(): int
    {
        return $this->totalSuccesses;
    }

    public function setTotalSuccesses(int $totalSuccesses): self
    {
        $this->totalSuccesses = $totalSuccesses;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;
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

    /**
     * Calculate empirical success rate
     */
    public function getEmpiricalRate(): float
    {
        if ($this->totalTrials === 0) {
            return 0.0;
        }
        return $this->totalSuccesses / $this->totalTrials;
    }

    /**
     * Calculate expected rate from Beta distribution mean
     * E[Beta(α, β)] = α / (α + β)
     */
    public function getExpectedRate(): float
    {
        return $this->alpha / ($this->alpha + $this->beta);
    }

    /**
     * Record a success outcome
     */
    public function recordSuccess(): void
    {
        $this->alpha++;
        $this->totalTrials++;
        $this->totalSuccesses++;
        $this->updatedAt = new \DateTime();
    }

    /**
     * Record a failure outcome
     */
    public function recordFailure(): void
    {
        $this->beta++;
        $this->totalTrials++;
        $this->updatedAt = new \DateTime();
    }
}
