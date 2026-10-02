<?php

namespace App\Entity;

use App\Repository\SpintaxTemplateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Spintax Email Template
 * 
 * Email templates with spintax syntax for message personalization.
 * Supports {option1|option2|option3} and {{variable}} syntax.
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
#[ORM\Entity(repositoryClass: SpintaxTemplateRepository::class)]
#[ORM\Table(name: 'spintax_templates')]
#[ORM\Index(name: 'idx_spintax_type', columns: ['template_type'])]
#[ORM\Index(name: 'idx_spintax_active', columns: ['active'])]
#[ORM\HasLifecycleCallbacks]
class SpintaxTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50, options: ['default' => 'email'])]
    private string $templateType = 'email'; // 'email', 'subject', 'followup'

    /**
     * Subject line with spintax: {Quick question about|Question re:} {{app_name}}
     */
    #[ORM\Column(type: 'text')]
    private string $subjectSpintax = '';

    /**
     * Body with spintax: {Hi|Hello|Hey} {{first_name}}, {I noticed|I came across} ...
     */
    #[ORM\Column(type: 'text')]
    private string $bodySpintax = '';

    /**
     * Available variables like ["first_name", "company_name", "app_name"]
     *
     * @var array<int|string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $availableVariables = ['first_name', 'company_name'];

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $timesUsed = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $totalOpens = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $totalReplies = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $active = true;

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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getTemplateType(): string
    {
        return $this->templateType;
    }

    public function setTemplateType(string $templateType): self
    {
        $this->templateType = $templateType;
        return $this;
    }

    public function getSubjectSpintax(): string
    {
        return $this->subjectSpintax;
    }

    public function setSubjectSpintax(string $subjectSpintax): self
    {
        $this->subjectSpintax = $subjectSpintax;
        return $this;
    }

    public function getBodySpintax(): string
    {
        return $this->bodySpintax;
    }

    public function setBodySpintax(string $bodySpintax): self
    {
        $this->bodySpintax = $bodySpintax;
        return $this;
    }

    /** @return array<int|string, mixed> */
    public function getAvailableVariables(): array
    {
        return $this->availableVariables;
    }

    /** @param array<int|string, mixed> $availableVariables */
    public function setAvailableVariables(array $availableVariables): self
    {
        $this->availableVariables = $availableVariables;
        return $this;
    }

    public function getTimesUsed(): int
    {
        return $this->timesUsed;
    }

    public function setTimesUsed(int $timesUsed): self
    {
        $this->timesUsed = $timesUsed;
        return $this;
    }

    public function incrementTimesUsed(): self
    {
        $this->timesUsed++;
        return $this;
    }

    public function getTotalOpens(): int
    {
        return $this->totalOpens;
    }

    public function setTotalOpens(int $totalOpens): self
    {
        $this->totalOpens = $totalOpens;
        return $this;
    }

    public function incrementTotalOpens(): self
    {
        $this->totalOpens++;
        return $this;
    }

    public function getTotalReplies(): int
    {
        return $this->totalReplies;
    }

    public function setTotalReplies(int $totalReplies): self
    {
        $this->totalReplies = $totalReplies;
        return $this;
    }

    public function incrementTotalReplies(): self
    {
        $this->totalReplies++;
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
     * Calculate open rate
     */
    public function getOpenRate(): float
    {
        if ($this->timesUsed === 0) {
            return 0.0;
        }
        return round(($this->totalOpens / $this->timesUsed) * 100, 2);
    }

    /**
     * Calculate reply rate
     */
    public function getReplyRate(): float
    {
        if ($this->timesUsed === 0) {
            return 0.0;
        }
        return round(($this->totalReplies / $this->timesUsed) * 100, 2);
    }
}
