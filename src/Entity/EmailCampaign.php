<?php

namespace App\Entity;

use App\Repository\EmailCampaignRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailCampaignRepository::class)]
#[ORM\Table(name: 'email_campaigns')]
#[ORM\HasLifecycleCallbacks]
class EmailCampaign
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENDING = 'sending';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const VALID_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENDING,
        self::STATUS_PAUSED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const TYPE_MANUAL = 'manual';
    public const TYPE_TRIGGERED = 'triggered';
    public const TYPE_DRIP = 'drip';

    public const LANGUAGE_EN = 'EN';
    public const LANGUAGE_FR = 'FR';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 10)]
    private ?string $language = self::LANGUAGE_EN; // EN or FR

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'integer')]
    private ?int $touchCount = 5; // Default 5-touch sequence

    #[ORM\Column(type: 'json')]
    private array $touchTemplates = []; // Array of template IDs

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $abTestVariants = null;

    #[ORM\Column(type: 'boolean')]
    private bool $active = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromEmail = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bodyHtml = null;

    #[ORM\Column(length: 20, options: ['default' => 'draft'])]
    private ?string $status = self::STATUS_DRAFT;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $type = self::TYPE_MANUAL;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $triggerType = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $triggerConditions = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $sendTimeOptimization = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $sentAt = null;
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $archivedAt = null;

    #[ORM\ManyToOne(targetEntity: \App\Entity\User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?\App\Entity\User $archivedBy = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $scheduledDispatchedAt = null;


    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'campaign', targetEntity: EmailSend::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $emailSends;

    #[ORM\ManyToMany(targetEntity: Contact::class, mappedBy: 'emailCampaigns')]
    private Collection $contacts;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $scheduledAt = null;

    #[ORM\ManyToOne(targetEntity: EmailTemplate::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EmailTemplate $template = null;

    #[ORM\ManyToOne(targetEntity: EmailSegment::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EmailSegment $segment = null;

    public function __construct()
    {
        $this->emailSends = new ArrayCollection();
        $this->contacts = new ArrayCollection();
        $this->createdAt = new \DateTime();
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

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(string $language): self
    {
        $this->language = $language;
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

    public function getTouchCount(): ?int
    {
        return $this->touchCount;
    }

    public function setTouchCount(int $touchCount): self
    {
        $this->touchCount = $touchCount;
        return $this;
    }

    public function getTouchTemplates(): array
    {
        return $this->touchTemplates;
    }

    public function setTouchTemplates(array $touchTemplates): self
    {
        $this->touchTemplates = $touchTemplates;
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

    /**
     * @return Collection<int, EmailSend>
     */
    public function getEmailSends(): Collection
    {
        return $this->emailSends;
    }

    public function addEmailSend(EmailSend $send): self
    {
        if (!$this->emailSends->contains($send)) {
            $this->emailSends->add($send);
            $send->setCampaign($this);
        }

        return $this;
    }

    public function removeEmailSend(EmailSend $send): self
    {
        if ($this->emailSends->removeElement($send)) {
            if ($send->getCampaign() === $this) {
                $send->setCampaign(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Contact>
     */
    public function getContacts(): Collection
    {
        return $this->contacts;
    }

    public function addContact(Contact $contact): self
    {
        if (!$this->contacts->contains($contact)) {
            $this->contacts->add($contact);
            // Contact owns the join table: without the reverse sync nothing
            // is persisted to contact_email_campaigns.
            $contact->addEmailCampaign($this);
        }

        return $this;
    }

    public function removeContact(Contact $contact): self
    {
        if ($this->contacts->removeElement($contact)) {
            $contact->removeEmailCampaign($this);
        }

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

    public function getTemplate(): ?EmailTemplate
    {
        return $this->template;
    }

    public function setTemplate(?EmailTemplate $template): self
    {
        $this->template = $template;
        return $this;
    }

    /**
     * A/B test variant configurations
     *
     * @return array<int, mixed>
     */
    public function getAbTestVariants(): array
    {
        return $this->abTestVariants ?? [];
    }

    public function setAbTestVariants(array $variants): self
    {
        $this->abTestVariants = $variants;
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

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    public function setFromName(?string $fromName): self
    {
        $this->fromName = $fromName;
        return $this;
    }

    public function getFromEmail(): ?string
    {
        return $this->fromEmail;
    }

    public function setFromEmail(?string $fromEmail): self
    {
        $this->fromEmail = $fromEmail;
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

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid campaign status "%s". Allowed: %s', $status, implode(', ', self::VALID_STATUSES)));
        }
        $this->status = $status;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getTriggerType(): ?string
    {
        return $this->triggerType;
    }

    public function setTriggerType(?string $triggerType): self
    {
        $this->triggerType = $triggerType;
        return $this;
    }

    public function getTriggerConditions(): ?array
    {
        return $this->triggerConditions;
    }

    public function setTriggerConditions(?array $triggerConditions): self
    {
        $this->triggerConditions = $triggerConditions;
        return $this;
    }

    public function isSendTimeOptimization(): bool
    {
        return $this->sendTimeOptimization;
    }

    public function setSendTimeOptimization(bool $sendTimeOptimization): self
    {
        $this->sendTimeOptimization = $sendTimeOptimization;
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

    public function getSegment(): ?EmailSegment
    {
        return $this->segment;
    }

    public function setSegment(?EmailSegment $segment): self
    {
        $this->segment = $segment;
        return $this;
    }
    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    public function getArchivedAt(): ?\DateTimeInterface
    {
        return $this->archivedAt;
    }

    public function archive(\App\Entity\User $by, ?string $reason = null): self
    {
        $this->archivedAt = $this->archivedAt ?? new \DateTime();
        $this->archivedBy = $by;
        $this->archiveReason = $reason;

        return $this;
    }

    public function restore(): self
    {
        $this->archivedAt = null;
        $this->archivedBy = null;
        $this->archiveReason = null;

        return $this;
    }

    public function getScheduledDispatchedAt(): ?\DateTimeInterface
    {
        return $this->scheduledDispatchedAt;
    }

    public function setScheduledDispatchedAt(?\DateTimeInterface $at): self
    {
        $this->scheduledDispatchedAt = $at;

        return $this;
    }

    public function getArchivedBy(): ?\App\Entity\User
    {
        return $this->archivedBy;
    }

    public function getArchiveReason(): ?string
    {
        return $this->archiveReason;
    }

}
