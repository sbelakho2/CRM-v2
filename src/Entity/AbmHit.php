<?php

namespace App\Entity;

use App\Repository\AbmHitRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AbmHitRepository::class)]
#[ORM\Table(name: 'abm_hits')]
#[ORM\Index(name: 'idx_company_timestamp', columns: ['company_id', 'timestamp'])]
class AbmHit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    #[ORM\ManyToOne(targetEntity: AbmAccount::class, inversedBy: 'abmHits')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AbmAccount $abmAccount = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $timestamp = null;

    #[ORM\Column(length: 45)]
    private ?string $ipAddress = null; // Anonymized

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $organizationName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $urlVisited = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sessionDuration = null; // seconds

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $pageViews = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?string $firmographicData = null; // JSON

    #[ORM\Column(type: 'boolean')]
    private bool $isIdentified = false;

    #[ORM\Column(type: 'boolean')]
    private bool $playbookTriggered = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): self
    {
        $this->company = $company;
        return $this;
    }

    public function getTimestamp(): ?\DateTimeInterface
    {
        return $this->timestamp;
    }

    public function setTimestamp(\DateTimeInterface $timestamp): self
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getOrganizationName(): ?string
    {
        return $this->organizationName;
    }

    public function setOrganizationName(?string $organizationName): self
    {
        $this->organizationName = $organizationName;
        return $this;
    }

    public function getUrlVisited(): ?string
    {
        return $this->urlVisited;
    }

    public function setUrlVisited(?string $urlVisited): self
    {
        $this->urlVisited = $urlVisited;
        return $this;
    }

    public function getSessionDuration(): ?int
    {
        return $this->sessionDuration;
    }

    public function setSessionDuration(?int $sessionDuration): self
    {
        $this->sessionDuration = $sessionDuration;
        return $this;
    }

    public function getPageViews(): ?int
    {
        return $this->pageViews;
    }

    public function setPageViews(?int $pageViews): self
    {
        $this->pageViews = $pageViews;
        return $this;
    }

    public function getFirmographicData(): ?string
    {
        return $this->firmographicData;
    }

    public function setFirmographicData(?string $firmographicData): self
    {
        $this->firmographicData = $firmographicData;
        return $this;
    }

    public function isIdentified(): bool
    {
        return $this->isIdentified;
    }

    public function setIsIdentified(bool $isIdentified): self
    {
        $this->isIdentified = $isIdentified;
        return $this;
    }

    public function isPlaybookTriggered(): bool
    {
        return $this->playbookTriggered;
    }

    public function setPlaybookTriggered(bool $playbookTriggered): self
    {
        $this->playbookTriggered = $playbookTriggered;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function getAbmAccount(): ?AbmAccount
    {
        return $this->abmAccount;
    }

    public function setAbmAccount(?AbmAccount $abmAccount): self
    {
        $this->abmAccount = $abmAccount;
        return $this;
    }
}
