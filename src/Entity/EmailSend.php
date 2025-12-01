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

    #[ORM\ManyToOne(targetEntity: EmailCampaign::class, inversedBy: 'emailSends')]
    #[ORM\JoinColumn(nullable: false)]
    private ?EmailCampaign $campaign = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Contact $contact = null;

    #[ORM\Column(type: 'integer')]
    private ?int $touchNumber = null; // 1-5

    #[ORM\Column(type: 'datetime')]
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

    public function __construct()
    {
        $this->sentAt = new \DateTime();
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
}
