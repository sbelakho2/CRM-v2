<?php

namespace App\Entity;

use App\Repository\AbmAccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AbmAccountRepository::class)]
#[ORM\Table(name: 'abm_account')]
#[ORM\Index(name: 'idx_abm_domain', columns: ['domain'])]
#[ORM\Index(name: 'idx_abm_icp_tier', columns: ['icp_tier'])]
#[ORM\Index(name: 'idx_abm_engagement', columns: ['engagement_score'])]
#[ORM\HasLifecycleCallbacks]
class AbmAccount
{
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $archivedAt = null;

    #[ORM\ManyToOne(targetEntity: \App\Entity\User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?\App\Entity\User $archivedBy = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $archiveReason = null;

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

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    #[ORM\OneToMany(mappedBy: 'abmAccount', targetEntity: AbmHit::class, cascade: ['persist'])]
    private Collection $abmHits;

    public function __construct()
    {
        $this->abmHits = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTime();
        }
        if ($this->firstSeenAt === null) {
            $this->firstSeenAt = new \DateTime();
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

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): self
    {
        $this->company = $company;
        return $this;
    }

    /**
     * @return Collection<int, AbmHit>
     */
    public function getAbmHits(): Collection
    {
        return $this->abmHits;
    }

    public function addAbmHit(AbmHit $abmHit): self
    {
        if (!$this->abmHits->contains($abmHit)) {
            $this->abmHits->add($abmHit);
            $abmHit->setAbmAccount($this);
        }
        return $this;
    }

    public function removeAbmHit(AbmHit $abmHit): self
    {
        if ($this->abmHits->removeElement($abmHit)) {
            if ($abmHit->getAbmAccount() === $this) {
                $abmHit->setAbmAccount(null);
            }
        }
        return $this;
    }
    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    public function archive(\App\Entity\User $by, ?string $reason = null): self
    {
        $this->archivedAt = $this->archivedAt ?? new \DateTime();
        $this->archivedBy = $by;
        $this->archiveReason = $reason;

        return $this;
    }

    public function getArchivedAt(): ?\DateTimeInterface
    {
        return $this->archivedAt;
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
