<?php

namespace App\Entity;

use App\Repository\AbmAccountRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AbmAccountRepository::class)]
#[ORM\Table(name: 'abm_account')]
#[ORM\Index(name: 'idx_abm_domain', columns: ['domain'])]
#[ORM\Index(name: 'idx_abm_icp_tier', columns: ['icp_tier'])]
#[ORM\Index(name: 'idx_abm_engagement', columns: ['engagement_score'])]
class AbmAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $accountName = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $domain = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $icpTier = null; // Tier1, Tier2, Tier3

    #[ORM\Column(nullable: true)]
    private ?int $engagementScore = 0;

    #[ORM\Column(nullable: true)]
    private ?int $totalVisits = 0;

    #[ORM\Column(nullable: true)]
    private ?int $totalPageViews = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $firstSeenAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastActivityAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->firstSeenAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccountName(): ?string
    {
        return $this->accountName;
    }

    public function setAccountName(string $accountName): self
    {
        $this->accountName = $accountName;
        return $this;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): self
    {
        $this->domain = $domain;
        return $this;
    }

    public function getIcpTier(): ?string
    {
        return $this->icpTier;
    }

    public function setIcpTier(?string $icpTier): self
    {
        $this->icpTier = $icpTier;
        return $this;
    }

    public function getEngagementScore(): ?int
    {
        return $this->engagementScore;
    }

    public function setEngagementScore(int $engagementScore): self
    {
        $this->engagementScore = $engagementScore;
        return $this;
    }

    public function getTotalVisits(): ?int
    {
        return $this->totalVisits;
    }

    public function setTotalVisits(int $totalVisits): self
    {
        $this->totalVisits = $totalVisits;
        return $this;
    }

    public function getTotalPageViews(): ?int
    {
        return $this->totalPageViews;
    }

    public function setTotalPageViews(int $totalPageViews): self
    {
        $this->totalPageViews = $totalPageViews;
        return $this;
    }

    public function getFirstSeenAt(): ?\DateTimeInterface
    {
        return $this->firstSeenAt;
    }

    public function setFirstSeenAt(?\DateTimeInterface $firstSeenAt): self
    {
        $this->firstSeenAt = $firstSeenAt;
        return $this;
    }

    public function getLastActivityAt(): ?\DateTimeInterface
    {
        return $this->lastActivityAt;
    }

    public function setLastActivityAt(?\DateTimeInterface $lastActivityAt): self
    {
        $this->lastActivityAt = $lastActivityAt;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
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
}
