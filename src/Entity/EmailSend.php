<?php

namespace App\Entity;

use App\Repository\EmailSendRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailSendRepository::class)]
#[ORM\Table(name: 'email_sends')]
#[ORM\Index(name: 'idx_email_sends_campaign', columns: ['campaign_id'])]
#[ORM\Index(name: 'idx_email_sends_contact', columns: ['contact_id'])]
#[ORM\UniqueConstraint(name: 'uniq_email_sends_touch', columns: ['campaign_id', 'contact_id', 'touch_number'])]
#[ORM\HasLifecycleCallbacks]
class EmailSend
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_BOUNCED = 'bounced';

    public const VALID_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_SENDING,
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_BOUNCED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EmailCampaign::class, inversedBy: 'emailSends')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?EmailCampaign $campaign = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Contact $contact = null;

    #[ORM\Column(type: 'integer')]
    private ?int $touchNumber = null; // 1-5

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $sentAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $opened = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $clicked = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $replied = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $bounced = false;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $variant = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $emailAddress = null;

    #[ORM\Column(length: 20, options: ['default' => 'queued'])]
    private ?string $status = self::STATUS_QUEUED;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $scheduledAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $openedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $clickedAt = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $retryCount = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $failureReason = null;

    public function __construct()
    {
        // sentAt is NOT auto-set — it should be set when the email is actually sent
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): ?EmailCampaign
    {
        return $this->campaign;
    }

    public function setCampaign(?EmailCampaign $campaign): self
    {
        $this->campaign = $campaign;
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

    public function getTouchNumber(): ?int
    {
        return $this->touchNumber;
    }

    public function setTouchNumber(int $touchNumber): self
    {
        $this->touchNumber = $touchNumber;
        return $this;
    }

    public function getSentAt(): ?\DateTimeInterface
    {
        return $this->sentAt;
    }

    public function setSentAt(\DateTimeInterface $sentAt): self
    {
        $this->sentAt = $sentAt;
        return $this;
    }

    public function isOpened(): bool
    {
        return $this->opened;
    }

    public function setOpened(bool $opened): self
    {
        $this->opened = $opened;
        return $this;
    }

    public function isClicked(): bool
    {
        return $this->clicked;
    }

    public function setClicked(bool $clicked): self
    {
        $this->clicked = $clicked;
        return $this;
    }

    public function isReplied(): bool
    {
        return $this->replied;
    }

    public function setReplied(bool $replied): self
    {
        $this->replied = $replied;
        return $this;
    }

    public function isBounced(): bool
    {
        return $this->bounced;
    }

    public function setBounced(bool $bounced): self
    {
        $this->bounced = $bounced;
        return $this;
    }

    public function getVariant(): ?string
    {
        return $this->variant;
    }

    public function setVariant(?string $variant): self
    {
        $this->variant = $variant;
        return $this;
    }

    public function getEmailAddress(): ?string
    {
        return $this->emailAddress ?? $this->contact?->getEmail();
    }

    public function setEmailAddress(?string $emailAddress): self
    {
        $this->emailAddress = $emailAddress;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid send status "%s". Allowed: %s', $status, implode(', ', self::VALID_STATUSES)));
        }
        $this->status = $status;
        return $this;
    }

    public function getScheduledAt(): ?\DateTimeInterface
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?\DateTimeInterface $scheduledAt): self
    {
        $this->scheduledAt = $scheduledAt;
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

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function setRetryCount(int $retryCount): self
    {
        $this->retryCount = $retryCount;
        return $this;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function setFailureReason(?string $failureReason): self
    {
        $this->failureReason = $failureReason;
        return $this;
    }
}
