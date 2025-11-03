<?php

namespace App\Entity;

use App\Repository\EmailSendRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailSendRepository::class)]
#[ORM\Table(name: 'email_sends')]
class EmailSend
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EmailCampaign::class, inversedBy: 'sends')]
    #[ORM\JoinColumn(nullable: false)]
    private ?EmailCampaign $campaign = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Contact $contact = null;

    #[ORM\Column(type: 'integer')]
    private ?int $touchNumber = null; // 1-5

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $emailAddress = null;

    #[ORM\Column(length: 20)]
    private string $status = 'queued'; // queued, sent, failed, cancelled

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $scheduledAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $sentAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $openedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $clickedAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $opened = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $clicked = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $replied = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $bounced = false;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $retryCount = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $failureReason = null;

    public function __construct()
    {
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

    public function getEmailAddress(): ?string
    {
        return $this->emailAddress;
    }

    public function setEmailAddress(?string $emailAddress): self
    {
        $this->emailAddress = $emailAddress;
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
