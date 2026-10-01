<?php

namespace App\Entity;

use App\Repository\PersonalizationProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Personalization Profile Entity
 * 
 * Stores learned personalization preferences and embeddings for contacts/leads.
 * Used by EmailPersonalizationService for ML-style email personalization.
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
#[ORM\Entity(repositoryClass: PersonalizationProfileRepository::class)]
#[ORM\Table(name: 'personalization_profiles')]
#[ORM\Index(name: 'idx_pers_contact', columns: ['contact_id'])]
#[ORM\UniqueConstraint(name: 'uniq_pers_contact', columns: ['contact_id'])]
#[ORM\Index(name: 'idx_pers_company', columns: ['company_id'])]
#[ORM\HasLifecycleCallbacks]
class PersonalizationProfile
{
    // Tone preferences
    public const TONE_FORMAL = 'formal';
    public const TONE_CASUAL = 'casual';
    public const TONE_DIRECT = 'direct';
    public const TONE_FRIENDLY = 'friendly';
    
    // Content preferences  
    public const CONTENT_TECHNICAL = 'technical';
    public const CONTENT_BUSINESS = 'business';
    public const CONTENT_VALUE_FOCUSED = 'value_focused';
    public const CONTENT_RELATIONSHIP = 'relationship';
    
    // Communication style
    public const STYLE_CONCISE = 'concise';
    public const STYLE_DETAILED = 'detailed';
    public const STYLE_BULLET_POINTS = 'bullet_points';
    public const STYLE_NARRATIVE = 'narrative';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?int $contactId = null;

    #[ORM\Column(nullable: true)]
    private ?int $companyId = null;

    #[ORM\Column(length: 50)]
    private string $preferredTone = self::TONE_FORMAL;

    #[ORM\Column(length: 50)]
    private string $preferredContent = self::CONTENT_BUSINESS;

    #[ORM\Column(length: 50)]
    private string $preferredStyle = self::STYLE_CONCISE;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $topicInterests = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $avoidTopics = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $featureEmbedding = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $interactionHistory = null;

    #[ORM\Column]
    private int $emailsOpened = 0;

    #[ORM\Column]
    private int $emailsReplied = 0;

    #[ORM\Column]
    private int $emailsBounced = 0;

    #[ORM\Column]
    private int $linksClicked = 0;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $bestSendTime = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $bestSendDay = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $successfulSubjectPatterns = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->topicInterests = [];
        $this->avoidTopics = [];
        $this->interactionHistory = [];
        $this->successfulSubjectPatterns = [];
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

    public function getContactId(): ?int
    {
        return $this->contactId;
    }

    public function setContactId(?int $contactId): static
    {
        $this->contactId = $contactId;
        return $this;
    }

    public function getCompanyId(): ?int
    {
        return $this->companyId;
    }

    public function setCompanyId(?int $companyId): static
    {
        $this->companyId = $companyId;
        return $this;
    }

    public function getPreferredTone(): string
    {
        return $this->preferredTone;
    }

    public function setPreferredTone(string $preferredTone): static
    {
        $this->preferredTone = $preferredTone;
        return $this;
    }

    public function getPreferredContent(): string
    {
        return $this->preferredContent;
    }

    public function setPreferredContent(string $preferredContent): static
    {
        $this->preferredContent = $preferredContent;
        return $this;
    }

    public function getPreferredStyle(): string
    {
        return $this->preferredStyle;
    }

    public function setPreferredStyle(string $preferredStyle): static
    {
        $this->preferredStyle = $preferredStyle;
        return $this;
    }

    public function getTopicInterests(): array
    {
        return $this->topicInterests ?? [];
    }

    public function setTopicInterests(?array $topicInterests): static
    {
        $this->topicInterests = $topicInterests;
        return $this;
    }

    public function addTopicInterest(string $topic, float $weight = 1.0): static
    {
        if ($this->topicInterests === null) { $this->topicInterests = []; }
        $this->topicInterests[$topic] = $weight;
        return $this;
    }

    public function getAvoidTopics(): array
    {
        return $this->avoidTopics ?? [];
    }

    public function setAvoidTopics(?array $avoidTopics): static
    {
        $this->avoidTopics = $avoidTopics;
        return $this;
    }

    public function getFeatureEmbedding(): array
    {
        return $this->featureEmbedding ?? [];
    }

    public function setFeatureEmbedding(?array $featureEmbedding): static
    {
        $this->featureEmbedding = $featureEmbedding;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getInteractionHistory(): array
    {
        return $this->interactionHistory ?? [];
    }

    public function setInteractionHistory(?array $interactionHistory): static
    {
        $this->interactionHistory = $interactionHistory;
        return $this;
    }

    public function recordInteraction(string $type, array $data = []): static
    {
        $this->interactionHistory[] = array_merge([
            'type' => $type,
            'timestamp' => time(),
        ], $data);
        
        // Keep only last 50 interactions
        if (count($this->interactionHistory) > 50) {
            $this->interactionHistory = array_slice($this->interactionHistory, -50);
        }
        
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getEmailsOpened(): int
    {
        return $this->emailsOpened;
    }

    public function incrementEmailsOpened(): static
    {
        $this->emailsOpened++;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getEmailsReplied(): int
    {
        return $this->emailsReplied;
    }

    public function incrementEmailsReplied(): static
    {
        $this->emailsReplied++;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getEmailsBounced(): int
    {
        return $this->emailsBounced;
    }

    public function incrementEmailsBounced(): static
    {
        $this->emailsBounced++;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getLinksClicked(): int
    {
        return $this->linksClicked;
    }

    public function incrementLinksClicked(): static
    {
        $this->linksClicked++;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getBestSendTime(): ?string
    {
        return $this->bestSendTime;
    }

    public function setBestSendTime(?string $bestSendTime): static
    {
        $this->bestSendTime = $bestSendTime;
        return $this;
    }

    public function getBestSendDay(): ?string
    {
        return $this->bestSendDay;
    }

    public function setBestSendDay(?string $bestSendDay): static
    {
        $this->bestSendDay = $bestSendDay;
        return $this;
    }

    public function getSuccessfulSubjectPatterns(): array
    {
        return $this->successfulSubjectPatterns ?? [];
    }

    public function addSuccessfulSubjectPattern(string $pattern): static
    {
        if (!in_array($pattern, $this->successfulSubjectPatterns ?? [])) {
            $this->successfulSubjectPatterns[] = $pattern;
        }
        return $this;
    }

    public function getMetadata(): array
    {
        return $this->metadata ?? [];
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;
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

    /**
     * Calculate engagement score with recency weighting (0-100)
     */
    public function getEngagementScore(): float
    {
        return self::calculateEngagementScore($this->interactionHistory, $this->emailsOpened, $this->emailsReplied, $this->emailsBounced);
    }

    public static function calculateEngagementScore(?array $interactionHistory, int $emailsOpened, int $emailsReplied, int $emailsBounced): float
    {
        $history = $interactionHistory ?? [];

        if (!empty($history)) {
            $now = time();
            $halfLifeDays = 30;
            $lambda = log(2) / ($halfLifeDays * 86400); // decay rate per second

            $weightedOpens = 0.0;
            $weightedReplies = 0.0;
            $weightedClicks = 0.0;
            $weightedTotal = 0.0;

            foreach ($history as $interaction) {
                $ts = $interaction['timestamp'] ?? 0;
                $age = max(0, $now - $ts);
                $w = exp(-$lambda * $age);

                $type = $interaction['type'] ?? '';
                $weightedTotal += $w;

                switch ($type) {
                    case 'open':
                        $weightedOpens += $w;
                        break;
                    case 'reply':
                    case 'positive_reply':
                        $weightedReplies += $w;
                        break;
                    case 'click':
                        $weightedClicks += $w;
                        break;
                }
            }

            if ($weightedTotal < 0.01) {
                // History exists but everything is ancient — fall through to counters
            } else {
                $openRate = $weightedOpens / $weightedTotal;
                $replyRate = $weightedOpens > 0.01 ? $weightedReplies / $weightedOpens : 0;
                $clickBonus = min(10.0, ($weightedClicks / $weightedTotal) * 20.0);
                return min(100.0, ($openRate * 45.0) + ($replyRate * 45.0) + $clickBonus);
            }
        }

        // Fallback: lifetime counters
        $totalEmails = $emailsOpened + $emailsBounced + 1;
        $openRate = $emailsOpened / $totalEmails;
        $replyRate = $emailsOpened > 0 ? $emailsReplied / $emailsOpened : 0;

        return min(100, ($openRate * 50) + ($replyRate * 50));
    }
}
