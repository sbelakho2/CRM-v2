<?php

namespace App\Entity;

use App\Repository\HtsMapRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: HtsMapRuleRepository::class)]
#[ORM\Table(name: 'hts_map_rules')]
#[ORM\Index(name: 'idx_category_keywords', columns: ['category'])]
#[ORM\HasLifecycleCallbacks]
class HtsMapRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $category = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $keywords = null; // JSON array of keywords

    #[ORM\Column(length: 100)]
    private ?string $htsCode = null;

    #[ORM\Column(length: 50)]
    private ?string $confidence = null; // PROVIDED, MAPPED, HEURISTIC

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $heuristicLogic = null; // JSON with matching rules

    #[ORM\Column(type: 'integer')]
    private ?int $priority = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
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
        if ($this->updatedAt === null) {
            $this->updatedAt = new \DateTime();
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

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function getKeywords(): ?array
    {
        return $this->keywords;
    }

    public function setKeywords(?array $keywords): self
    {
        $this->keywords = $keywords;
        return $this;
    }

    public function getHtsCode(): ?string
    {
        return $this->htsCode;
    }

    public function setHtsCode(string $htsCode): self
    {
        $this->htsCode = $htsCode;
        return $this;
    }

    public function getConfidence(): ?string
    {
        return $this->confidence;
    }

    public function setConfidence(string $confidence): self
    {
        $this->confidence = $confidence;
        return $this;
    }

    public function getHeuristicLogic(): ?string
    {
        return $this->heuristicLogic;
    }

    public function setHeuristicLogic(?string $heuristicLogic): self
    {
        $this->heuristicLogic = $heuristicLogic;
        return $this;
    }

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;
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

    public function setCreatedAt(?\DateTimeInterface $createdAt): self
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
     * Get confidence score based on confidence level
     */
    public function getConfidenceScore(): int
    {
        return match($this->confidence) {
            'PROVIDED' => 95,
            'MAPPED' => 85,
            'HEURISTIC' => 60,
            default => 50
        };
    }
}
