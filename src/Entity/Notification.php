<?php

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
#[ORM\Index(name: 'idx_notification_user_read', columns: ['user_id', 'read_at'])]
#[ORM\Index(name: 'idx_notification_created', columns: ['created_at'])]
class Notification
{
    // ── Notification type constants ──
    public const TYPE_RFQ_DUE        = 'rfq_due';
    public const TYPE_EMAIL_REPLY    = 'email_reply';
    public const TYPE_EMAIL_OPENED   = 'email_opened';
    public const TYPE_LEAD_APPROVAL  = 'lead_approval';
    public const TYPE_ENGAGEMENT_DROP = 'engagement_drop';
    public const TYPE_QUOTE_VIEWED   = 'quote_viewed';
    public const TYPE_COMP_CERT_ADDED   = 'comp_cert_added';
    public const TYPE_COMP_CERT_REMOVED = 'comp_cert_removed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * Notification type: rfq_due, email_reply, lead_approval, engagement_drop, quote_viewed, comp_cert_added, comp_cert_removed
     */
    #[ORM\Column(type: 'string', length: 50)]
    private string $type = '';

    /**
     * Entity type (RFQ, EmailSend, Lead, AbmAccount, Quote)
     */
    #[ORM\Column(type: 'string', length: 50)]
    private string $entityType = '';

    /**
     * ID of the entity this notification refers to
     */
    #[ORM\Column(type: 'integer')]
    private int $entityId = 0;

    /**
     * Human-readable notification message
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $message = '';

    /**
     * Additional data (JSON): company_name, contact_name, value_change, etc.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $data = null;

    /**
     * When the user marked this as read (null = unread)
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $readAt = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function setEntityType(string $entityType): self
    {
        $this->entityType = $entityType;
        return $this;
    }

    public function getEntityId(): int
    {
        return $this->entityId;
    }

    public function setEntityId(int $entityId): self
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function getData(): ?array
    {
        return $this->data;
    }

    public function setData(?array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function getReadAt(): ?\DateTimeInterface
    {
        return $this->readAt;
    }

    public function setReadAt(?\DateTimeInterface $readAt): self
    {
        $this->readAt = $readAt;
        return $this;
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function markAsRead(): self
    {
        $this->readAt = new \DateTime();
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * Get a human-readable type label
     */
    public function getTypeLabel(): string
    {
        $type = strtolower($this->type ?? '');
        
        return match($type) {
            'rfq_due' => '📋 RFQ Due Soon',
            'email_reply' => '📧 Email Reply',
            'email_opened' => '📧 Email Opened',
            'lead_approval' => '✅ Lead Approved',
            'engagement_drop' => '📉 Engagement Drop',
            'quote_viewed' => '📋 Quote Viewed',
            'comp_cert_added' => '🏭 Competitor Cert Added',
            'comp_cert_removed' => '🏭 Competitor Cert Removed',
            default => '🔔 Notification'
        };
    }

    /**
     * Get icon for notification type
     */
    public function getIcon(): string
    {
        $type = strtolower($this->type ?? '');
        
        return match($type) {
            'rfq_due' => '⏰',
            'email_reply' => '📧',
            'email_opened' => '📧',
            'lead_approval' => '✅',
            'engagement_drop' => '📉',
            'quote_viewed' => '📋',
            'comp_cert_added' => '🏭',
            'comp_cert_removed' => '🏭',
            default => '🔔'
        };
    }
}
