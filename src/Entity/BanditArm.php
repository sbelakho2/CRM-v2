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
#[ORM\HasLifecycleCallbacks]
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
     * Beta distribution alpha parameter (successes + prior)
     * Float to support weighted/fractional updates
     */
    #[ORM\Column(type: 'float', options: ['default' => 1.0])]
    private float $alpha = 1.0;

    /**
     * Beta distribution beta parameter (failures + prior)
     * Float to support weighted/fractional updates
     */
    #[ORM\Column(type: 'float', options: ['default' => 1.0])]
    private float $beta = 1.0;

    /**
     * ICP cluster this arm belongs to (for per-ICP bandit pools)
     * e.g. 'automotive_procurement', 'aerospace_engineering', 'global'
     */
    #[ORM\Column(length: 100, options: ['default' => 'global'])]
    private string $icpCluster = 'global';

    /**
     * Recent negative rate (rolling window) for catastrophic exploration cap
     */
    #[ORM\Column(type: 'float', options: ['default' => 0.0])]
    private float $recentNegativeRate = 0.0;

    /**
     * Whether this arm is quarantined (poison pill detected)
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $quarantined = false;

    /**
     * Whether this arm is a control-group baseline arm
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isControl = false;

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

    /**
     * Last time this arm was selected for use (for confidence decay)
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastUsedAt = null;

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

    public function getAlpha(): float
    {
        return $this->alpha;
    }

    public function setAlpha(float $alpha): self
    {
        $this->alpha = max(0.01, $alpha);
        return $this;
    }

    public function getBeta(): float
    {
        return $this->beta;
    }

    public function setBeta(float $beta): self
    {
        $this->beta = max(0.01, $beta);
        return $this;
    }

    public function getIcpCluster(): string
    {
        return $this->icpCluster;
    }

    public function setIcpCluster(string $icpCluster): self
    {
        $this->icpCluster = $icpCluster;
        return $this;
    }

    public function getRecentNegativeRate(): float
    {
        return $this->recentNegativeRate;
    }

    public function setRecentNegativeRate(float $rate): self
    {
        $this->recentNegativeRate = $rate;
        return $this;
    }

    public function isQuarantined(): bool
    {
        return $this->quarantined;
    }

    public function setQuarantined(bool $quarantined): self
    {
        $this->quarantined = $quarantined;
        return $this;
    }

    public function isControl(): bool
    {
        return $this->isControl;
    }

    public function setIsControl(bool $isControl): self
    {
        $this->isControl = $isControl;
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

    public function getLastUsedAt(): ?\DateTimeInterface
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeInterface $lastUsedAt): self
    {
        $this->lastUsedAt = $lastUsedAt;
        return $this;
    }

    /**
     * Mark arm as used (updates lastUsedAt timestamp)
     */
    public function markUsed(): self
    {
        $this->lastUsedAt = new \DateTime();
        return $this;
    }

    /**
     * Get days since this arm was last used
     */
    public function getDaysSinceLastUse(): int
    {
        if (!$this->lastUsedAt) {
            return 365; // Never-used arms are maximally stale → triggers exploration
        }
        $now = new \DateTime();
        $diff = $now->diff($this->lastUsedAt);
        return (int) $diff->days;
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
        return self::calculateExpectedRate($this->alpha, $this->beta);
    }

    public static function calculateExpectedRate(float $alpha, float $beta): float
    {
        return $alpha / ($alpha + $beta);
    }

    /**
     * Record a weighted success outcome
     *
     * @param float $weight Weight of the update (default 1.0)
     *   open:           0.1
     *   click:          0.3
     *   positive reply: 2.5
     *   neutral reply:  0.5
     */
    public function recordSuccess(float $weight = 1.0): void
    {
        $this->alpha += $weight;
        $this->totalTrials++;
        $this->totalSuccesses++;
        $this->updatedAt = new \DateTime();
    }

    /**
     * Record a weighted failure outcome
     *
     * @param float $weight Weight of the update (default 1.0)
     *   bounce:          1.0
     *   negative reply:  3.0
     *   unsubscribe:     7.0
     *   no-response:     0.3  (soft delayed failure)
     */
    public function recordFailure(float $weight = 1.0): void
    {
        $this->beta += $weight;
        $this->totalTrials++;
        $this->updatedAt = new \DateTime();
    }
}
