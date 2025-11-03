<?php

namespace App\Entity;

use App\Repository\EmailCampaignRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailCampaignRepository::class)]
#[ORM\Table(name: 'email_campaigns')]
class EmailCampaign
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 10)]
    private ?string $language = 'EN'; // EN or FR

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'integer')]
    private ?int $touchCount = 5; // Default 5-touch sequence

    #[ORM\Column(type: 'json')]
    private array $touchTemplates = []; // Array of template IDs

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(type: 'boolean')]
    private bool $sendTimeOptimization = false;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $abTestVariants = null; // Array of variant configurations

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $type = 'standard'; // standard, triggered, drip

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $triggerType = null; // pipeline_stage_change, rfq_submission, quote_sent, lead_score_change, abm_hit

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $triggerConditions = null; // Conditions for triggering

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bodyHtml = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromName = null;

    #[ORM\Column(length: 20)]
    private string $status = 'draft'; // draft, scheduled, sending, sent, paused, cancelled

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $scheduledAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $sentAt = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: EmailTemplate::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?EmailTemplate $template = null;

    #[ORM\ManyToOne(targetEntity: EmailSegment::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?EmailSegment $segment = null;

    #[ORM\OneToMany(mappedBy: 'campaign', targetEntity: EmailSend::class, cascade: ['persist', 'remove'])]
    private Collection $sends;

    public function __construct()
    {
        $this->sends = new ArrayCollection();
        $this->createdAt = new \DateTime();
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

    public function isSendTimeOptimization(): bool
    {
        return $this->sendTimeOptimization;
    }

    public function setSendTimeOptimization(bool $sendTimeOptimization): self
    {
        $this->sendTimeOptimization = $sendTimeOptimization;
        return $this;
    }

    public function getAbTestVariants(): ?array
    {
        return $this->abTestVariants;
    }

    public function setAbTestVariants(?array $abTestVariants): self
    {
        $this->abTestVariants = $abTestVariants;
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

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): self
    {
        $this->subject = $subject;
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

    public function getFromEmail(): ?string
    {
        return $this->fromEmail;
    }

    public function setFromEmail(?string $fromEmail): self
    {
        $this->fromEmail = $fromEmail;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
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

    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
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

    public function getSegment(): ?EmailSegment
    {
        return $this->segment;
    }

    public function setSegment(?EmailSegment $segment): self
    {
        $this->segment = $segment;
        return $this;
    }

    /**
     * @return Collection<int, EmailSend>
     */
    public function getSends(): Collection
    {
        return $this->sends;
    }
}
