<?php

namespace App\Entity;

use App\Repository\PersonalizationArchetypeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Personalization Archetype Entity
 * 
 * Synthetic profiles representing common buyer personas for cold-start
 * collaborative filtering. These provide similarity targets until real
 * engagement data accumulates.
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
#[ORM\Entity(repositoryClass: PersonalizationArchetypeRepository::class)]
#[ORM\Table(name: 'personalization_archetypes')]
#[ORM\UniqueConstraint(name: 'unique_archetype_name', columns: ['archetype_name'])]
#[ORM\Index(name: 'idx_archetype_industry', columns: ['target_industry'])]
#[ORM\Index(name: 'idx_archetype_role', columns: ['target_role'])]
#[ORM\HasLifecycleCallbacks]
class PersonalizationArchetype
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $archetypeName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50)]
    private ?string $targetIndustry = null;

    #[ORM\Column(length: 50)]
    private ?string $targetRole = null;

    #[ORM\Column(length: 50)]
    private string $preferredTone = 'formal';

    #[ORM\Column(length: 50)]
    private string $preferredContent = 'business';

    #[ORM\Column(length: 50)]
    private string $preferredStyle = 'concise';

    #[ORM\Column(type: Types::JSON)]
    private array $featureEmbedding = [];

    #[ORM\Column(type: 'integer', options: ['default' => 75])]
    private int $syntheticEngagementScore = 75;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $topicInterests = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->featureEmbedding = array_fill(0, 64, 0.5);
        $this->topicInterests = [];
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTime();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArchetypeName(): ?string
    {
        return $this->archetypeName;
    }

    public function setArchetypeName(string $archetypeName): self
    {
        $this->archetypeName = $archetypeName;
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

    public function getTargetIndustry(): ?string
    {
        return $this->targetIndustry;
    }

    public function setTargetIndustry(string $targetIndustry): self
    {
        $this->targetIndustry = $targetIndustry;
        return $this;
    }

    public function getTargetRole(): ?string
    {
        return $this->targetRole;
    }

    public function setTargetRole(string $targetRole): self
    {
        $this->targetRole = $targetRole;
        return $this;
    }

    public function getPreferredTone(): string
    {
        return $this->preferredTone;
    }

    public function setPreferredTone(string $preferredTone): self
    {
        $this->preferredTone = $preferredTone;
        return $this;
    }

    public function getPreferredContent(): string
    {
        return $this->preferredContent;
    }

    public function setPreferredContent(string $preferredContent): self
    {
        $this->preferredContent = $preferredContent;
        return $this;
    }

    public function getPreferredStyle(): string
    {
        return $this->preferredStyle;
    }

    public function setPreferredStyle(string $preferredStyle): self
    {
        $this->preferredStyle = $preferredStyle;
        return $this;
    }

    public function getFeatureEmbedding(): array
    {
        return $this->featureEmbedding;
    }

    public function setFeatureEmbedding(array $featureEmbedding): self
    {
        $this->featureEmbedding = $featureEmbedding;
        return $this;
    }

    public function getSyntheticEngagementScore(): int
    {
        return $this->syntheticEngagementScore;
    }

    public function setSyntheticEngagementScore(int $score): self
    {
        $this->syntheticEngagementScore = $score;
        return $this;
    }

    public function getTopicInterests(): ?array
    {
        return $this->topicInterests;
    }

    public function setTopicInterests(?array $topicInterests): self
    {
        $this->topicInterests = $topicInterests;
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

    /**
     * Get engagement score (interface compatibility with PersonalizationProfile)
     */
    public function getEngagementScore(): int
    {
        return $this->syntheticEngagementScore;
    }
}
