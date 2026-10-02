<?php

namespace App\Entity;

use App\Repository\InboxMessageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Inbox Message
 * 
 * Incoming email classification using Naive Bayes + rule-based classification.
 * Supports human-in-the-loop review for uncertain classifications.
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
#[ORM\Entity(repositoryClass: InboxMessageRepository::class)]
#[ORM\Table(name: 'inbox_messages')]
#[ORM\Index(name: 'idx_inbox_classification', columns: ['classification'])]
#[ORM\Index(name: 'idx_inbox_review', columns: ['requires_human_review'])]
#[ORM\Index(name: 'idx_inbox_from', columns: ['from_email'])]
#[ORM\HasLifecycleCallbacks]
class InboxMessage
{
    public const CLASSIFICATION_INTERESTED = 'INTERESTED';
    public const CLASSIFICATION_NOT_INTERESTED = 'NOT_INTERESTED';
    public const CLASSIFICATION_UNSUBSCRIBE = 'UNSUBSCRIBE';
    public const CLASSIFICATION_OUT_OF_OFFICE = 'OUT_OF_OFFICE';
    public const CLASSIFICATION_BOUNCE = 'BOUNCE';
    public const CLASSIFICATION_UNKNOWN = 'UNKNOWN';

    public const METHOD_RULE_BASED = 'rule_based';
    public const METHOD_NAIVE_BAYES = 'naive_bayes';
    public const METHOD_HUMAN = 'human';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $fromEmail = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bodyText = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $classification = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $classificationConfidence = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $classificationMethod = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $requiresHumanReview = false;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $humanReviewedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reviewedBy = null;

    /**
     * Link to the original outbound message this is replying to
     */
    #[ORM\ManyToOne(targetEntity: OutboundMessage::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?OutboundMessage $inReplyTo = null;

    /**
     * Link to contact if identified
     */
    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Contact $contact = null;

    /**
     * Raw email headers/metadata
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $receivedAt = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->receivedAt === null) {
            $this->receivedAt = new \DateTime();
        }
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTime();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFromEmail(): ?string
    {
        return $this->fromEmail;
    }

    public function setFromEmail(string $fromEmail): self
    {
        $this->fromEmail = $fromEmail;
        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function getBodyText(): ?string
    {
        return $this->bodyText;
    }

    public function setBodyText(?string $bodyText): self
    {
        $this->bodyText = $bodyText;
        return $this;
    }

    public function getClassification(): ?string
    {
        return $this->classification;
    }

    public function setClassification(?string $classification): self
    {
        $this->classification = $classification;
        return $this;
    }

    public function getClassificationConfidence(): ?float
    {
        return $this->classificationConfidence !== null ? (float) $this->classificationConfidence : null;
    }

    public function setClassificationConfidence(?float $confidence): self
    {
        $this->classificationConfidence = $confidence !== null ? (string) $confidence : null;
        return $this;
    }

    public function getClassificationMethod(): ?string
    {
        return $this->classificationMethod;
    }

    public function setClassificationMethod(?string $method): self
    {
        $this->classificationMethod = $method;
        return $this;
    }

    public function requiresHumanReview(): bool
    {
        return $this->requiresHumanReview;
    }

    public function setRequiresHumanReview(bool $requiresHumanReview): self
    {
        $this->requiresHumanReview = $requiresHumanReview;
        return $this;
    }

    public function getHumanReviewedAt(): ?\DateTimeInterface
    {
        return $this->humanReviewedAt;
    }

    public function setHumanReviewedAt(?\DateTimeInterface $humanReviewedAt): self
    {
        $this->humanReviewedAt = $humanReviewedAt;
        return $this;
    }

    public function getReviewedBy(): ?string
    {
        return $this->reviewedBy;
    }

    public function setReviewedBy(?string $reviewedBy): self
    {
        $this->reviewedBy = $reviewedBy;
        return $this;
    }

    public function getInReplyTo(): ?OutboundMessage
    {
        return $this->inReplyTo;
    }

    public function setInReplyTo(?OutboundMessage $inReplyTo): self
    {
        $this->inReplyTo = $inReplyTo;
        return $this;
    }

    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    public function setContact(?Contact $contact): self
    {
        $this->contact = $contact;
        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /** @param array<string, mixed>|null $metadata */
    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getReceivedAt(): ?\DateTimeInterface
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(\DateTimeInterface $receivedAt): self
    {
        $this->receivedAt = $receivedAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    /**
     * Check if classified as positive/interested response
     */
    public function isPositiveResponse(): bool
    {
        return $this->classification === self::CLASSIFICATION_INTERESTED;
    }

    /**
     * Check if classified as negative response
     */
    public function isNegativeResponse(): bool
    {
        return in_array($this->classification, [
            self::CLASSIFICATION_NOT_INTERESTED,
            self::CLASSIFICATION_UNSUBSCRIBE,
            self::CLASSIFICATION_BOUNCE
        ], true);
    }
}
