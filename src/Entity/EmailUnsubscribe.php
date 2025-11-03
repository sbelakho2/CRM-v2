<?php

namespace App\Entity;

use App\Repository\EmailUnsubscribeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailUnsubscribeRepository::class)]
#[ORM\Table(name: 'email_unsubscribe')]
#[ORM\Index(name: 'idx_unsubscribe_contact', columns: ['contact_id'])]
#[ORM\Index(name: 'idx_unsubscribe_email', columns: ['email'])]
class EmailUnsubscribe
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Contact $contact = null;

    #[ORM\Column(length: 255)]
    private ?string $email = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $reason = null; // NO_LONGER_INTERESTED, TOO_FREQUENT, IRRELEVANT, etc.

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $feedbackText = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $unsubscribedAt = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    public function __construct()
    {
        $this->unsubscribedAt = new \DateTime();
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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): self
    {
        $this->reason = $reason;
        return $this;
    }

    public function getFeedbackText(): ?string
    {
        return $this->feedbackText;
    }

    public function setFeedbackText(?string $feedbackText): self
    {
        $this->feedbackText = $feedbackText;
        return $this;
    }

    public function getUnsubscribedAt(): ?\DateTimeInterface
    {
        return $this->unsubscribedAt;
    }

    public function setUnsubscribedAt(\DateTimeInterface $unsubscribedAt): self
    {
        $this->unsubscribedAt = $unsubscribedAt;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }
}
