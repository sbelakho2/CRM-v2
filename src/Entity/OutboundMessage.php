<?php

namespace App\Entity;

use App\Repository\OutboundMessageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Outbound Message
 * 
 * Tracks sent emails with Thompson Sampler arm IDs for closed-loop learning.
 * Links to BanditArm to enable automatic optimization based on email outcomes.
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
#[ORM\Entity(repositoryClass: OutboundMessageRepository::class)]
#[ORM\Table(name: 'outbound_messages')]
#[ORM\Index(name: 'idx_outbound_contact', columns: ['contact_id'])]
#[ORM\Index(name: 'idx_outbound_arm', columns: ['subject_arm_id'])]
#[ORM\Index(name: 'idx_outbound_status', columns: ['status'])]
#[ORM\Index(name: 'idx_outbound_sent', columns: ['sent_at'])]
#[ORM\HasLifecycleCallbacks]
class OutboundMessage
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_OPENED = 'opened';
    public const STATUS_CLICKED = 'clicked';
    public const STATUS_REPLIED = 'replied';
    public const STATUS_BOUNCED = 'bounced';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Contact $contact = null;

    #[ORM\ManyToOne(targetEntity: RFQ::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RFQ $rfq = null;

    #[ORM\Column(type: 'text')]
    private ?string $subject = null;

    #[ORM\Column(type: 'text')]
    private ?string $bodyText = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bodyHtml = null;

    /**
     * Reference to the Thompson Sampler arm used for subject line
     */
    #[ORM\ManyToOne(targetEntity: BanditArm::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?BanditArm $subjectArm = null;

    #[ORM\ManyToOne(targetEntity: SpintaxTemplate::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SpintaxTemplate $template = null;

    /**
     * Hash of the spun variation for deduplication
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $variationHash = null;

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $sentAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $deliveredAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $openedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $clickedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $repliedAt = null;

    /**
     * External message ID from email provider (SendGrid, Mailgun, etc.)
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $messageId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $provider = null; // 'sendgrid', 'mailgun', 'resend'

    /**
     * Whether the outcome has been recorded in the bandit arm
     * @deprecated Use recordedEventType instead for proper tracking
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $outcomeRecorded = false;

    /**
     * The type of event that was recorded in Thompson Sampler
     * Allows separating open tracking from reply outcome tracking
     * Values: 'open', 'click', 'reply', 'bounce' or null
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $recordedEventType = null;

    /**
     * Classification of the reply (if replied) for proper Thompson update
     * Values: 'interested', 'not_interested', 'out_of_office', 'bounce', 'unknown'
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $replyClassification = null;

    /**
     * The raw content of the reply email for ML classification and learning
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $replyContent = null;

    // ==================== ATTRIBUTION IDS ====================
    /**
     * Value-prop bandit arm ID used for this message
     */
    #[ORM\Column(nullable: true)]
    private ?int $valuePropArmId = null;

    /**
     * Tone policy applied (formal, casual, direct, friendly)
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $toneApplied = null;

    /**
     * Send-time policy ID or label used
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $sendTimePolicy = null;

    /**
     * ICP cluster this send belongs to
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $icpCluster = null;

    /**
     * Whether this message is in the control group (baseline, no personalization)
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isControlGroup = false;

    /**
     * Full decision trace JSON (arms chosen, priors, decay, scores)
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $decisionTrace = null;

    // ==================== FUNNEL TRACKING ====================
    /**
     * Funnel stage: pending → sent → delivered → opened → clicked → replied → booked → activated
     */
    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $funnelStage = 'pending';

    /**
     * Reply window expiry (soft-failure applied after this)
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $replyWindowExpiry = null;

    /**
     * Whether the delayed soft-failure penalty has been applied
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $softFailureApplied = false;

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

    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    public function setContact(?Contact $contact): self
    {
        $this->contact = $contact;
        return $this;
    }

    public function getRfq(): ?RFQ
    {
        return $this->rfq;
    }

    public function setRfq(?RFQ $rfq): self
    {
        $this->rfq = $rfq;
        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function getBodyText(): ?string
    {
        return $this->bodyText;
    }

    public function setBodyText(string $bodyText): self
    {
        $this->bodyText = $bodyText;
        return $this;
    }

    public function getBodyHtml(): ?string
    {
        return $this->bodyHtml;
    }

    public function setBodyHtml(?string $bodyHtml): self
    {
        $this->bodyHtml = $bodyHtml;
        return $this;
    }

    public function getSubjectArm(): ?BanditArm
    {
        return $this->subjectArm;
    }

    public function setSubjectArm(?BanditArm $subjectArm): self
    {
        $this->subjectArm = $subjectArm;
        return $this;
    }

    public function getTemplate(): ?SpintaxTemplate
    {
        return $this->template;
    }

    public function setTemplate(?SpintaxTemplate $template): self
    {
        $this->template = $template;
        return $this;
    }

    public function getVariationHash(): ?string
    {
        return $this->variationHash;
    }

    public function setVariationHash(?string $variationHash): self
    {
        $this->variationHash = $variationHash;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getSentAt(): ?\DateTimeInterface
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeInterface $sentAt): self
    {
        $this->sentAt = $sentAt;
        return $this;
    }

    public function getDeliveredAt(): ?\DateTimeInterface
    {
        return $this->deliveredAt;
    }

    public function setDeliveredAt(?\DateTimeInterface $deliveredAt): self
    {
        $this->deliveredAt = $deliveredAt;
        return $this;
    }

    public function getOpenedAt(): ?\DateTimeInterface
    {
        return $this->openedAt;
    }

    public function setOpenedAt(?\DateTimeInterface $openedAt): self
    {
        $this->openedAt = $openedAt;
        return $this;
    }

    public function getClickedAt(): ?\DateTimeInterface
    {
        return $this->clickedAt;
    }

    public function setClickedAt(?\DateTimeInterface $clickedAt): self
    {
        $this->clickedAt = $clickedAt;
        return $this;
    }

    public function getRepliedAt(): ?\DateTimeInterface
    {
        return $this->repliedAt;
    }

    public function setRepliedAt(?\DateTimeInterface $repliedAt): self
    {
        $this->repliedAt = $repliedAt;
        return $this;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function setMessageId(?string $messageId): self
    {
        $this->messageId = $messageId;
        return $this;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(?string $provider): self
    {
        $this->provider = $provider;
        return $this;
    }

    public function isOutcomeRecorded(): bool
    {
        return $this->outcomeRecorded;
    }

    public function setOutcomeRecorded(bool $outcomeRecorded): self
    {
        $this->outcomeRecorded = $outcomeRecorded;
        return $this;
    }

    public function getRecordedEventType(): ?string
    {
        return $this->recordedEventType;
    }

    public function setRecordedEventType(?string $eventType): self
    {
        $this->recordedEventType = $eventType;
        return $this;
    }

    public function getReplyClassification(): ?string
    {
        return $this->replyClassification;
    }

    public function setReplyClassification(?string $classification): self
    {
        $this->replyClassification = $classification;
        return $this;
    }

    public function getReplyContent(): ?string
    {
        return $this->replyContent;
    }

    public function setReplyContent(?string $replyContent): self
    {
        $this->replyContent = $replyContent;
        return $this;
    }

    /**
     * Check if a specific event type has been recorded
     */
    public function hasRecordedEventType(string $eventType): bool
    {
        return $this->recordedEventType === $eventType;
    }

    /**
     * Check if reply outcome can override previous open tracking
     */
    public function canRecordReplyOutcome(): bool
    {
        // Reply can always override open/click tracking
        return $this->recordedEventType !== 'reply';
    }

    // ==================== ATTRIBUTION GETTERS/SETTERS ====================

    public function getValuePropArmId(): ?int { return $this->valuePropArmId; }
    public function setValuePropArmId(?int $id): self { $this->valuePropArmId = $id; return $this; }

    public function getToneApplied(): ?string { return $this->toneApplied; }
    public function setToneApplied(?string $tone): self { $this->toneApplied = $tone; return $this; }

    public function getSendTimePolicy(): ?string { return $this->sendTimePolicy; }
    public function setSendTimePolicy(?string $policy): self { $this->sendTimePolicy = $policy; return $this; }

    public function getIcpCluster(): ?string { return $this->icpCluster; }
    public function setIcpCluster(?string $cluster): self { $this->icpCluster = $cluster; return $this; }

    public function isControlGroup(): bool { return $this->isControlGroup; }
    public function setIsControlGroup(bool $isControl): self { $this->isControlGroup = $isControl; return $this; }

    public function getDecisionTrace(): ?array { return $this->decisionTrace; }
    /**
     * @param array<string|int, mixed> $trace
     */
    public /**
 * @param array<string|int, mixed> $trace
 */
function setDecisionTrace(?array $trace): self { $this->decisionTrace = $trace; return $this; }

    // ==================== FUNNEL TRACKING GETTERS/SETTERS ====================

    public function getFunnelStage(): string { return $this->funnelStage; }
    public function setFunnelStage(string $stage): self { $this->funnelStage = $stage; return $this; }

    public function getReplyWindowExpiry(): ?\DateTimeInterface { return $this->replyWindowExpiry; }
    public function setReplyWindowExpiry(?\DateTimeInterface $expiry): self { $this->replyWindowExpiry = $expiry; return $this; }

    public function isSoftFailureApplied(): bool { return $this->softFailureApplied; }
    public function setSoftFailureApplied(bool $applied): self { $this->softFailureApplied = $applied; return $this; }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    /**
     * Check if message had a successful outcome (for Thompson Sampling)
     */
    public function isSuccessOutcome(): bool
    {
        return in_array($this->status, [
            self::STATUS_OPENED,
            self::STATUS_CLICKED,
            self::STATUS_REPLIED
        ], true);
    }

    /**
     * Check if message had a failure outcome (for Thompson Sampling)
     */
    public function isFailureOutcome(): bool
    {
        return in_array($this->status, [
            self::STATUS_BOUNCED,
            self::STATUS_FAILED
        ], true);
    }
}
