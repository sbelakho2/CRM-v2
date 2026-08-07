<?php

namespace App\Entity;

use App\Repository\OnboardingPackRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OnboardingPackRepository::class)]
#[ORM\Table(name: 'onboarding_packs')]
#[ORM\Index(name: 'idx_company_portal', columns: ['company_id', 'portal_candidate_id'])]
#[ORM\HasLifecycleCallbacks]
class OnboardingPack
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_READY = 'ready';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class, inversedBy: 'onboardingPacks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Company $company = null;

    #[ORM\ManyToOne(targetEntity: PortalCandidate::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?PortalCandidate $portalCandidate = null;

    #[ORM\Column(length: 50)]
    private ?string $status = null; // draft, ready, submitted, approved, rejected

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $packContents = null; // JSON with document references

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $submittedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $submittedBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sha256Hash = null;

    public function __construct()
    {
        $this->status = self::STATUS_DRAFT;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTime();
        }
    }

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

    public function getPortalCandidate(): ?PortalCandidate
    {
        return $this->portalCandidate;
    }

    public function setPortalCandidate(?PortalCandidate $portalCandidate): self
    {
        $this->portalCandidate = $portalCandidate;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getPackContents(): ?string
    {
        return $this->packContents;
    }

    public function setPackContents(?string $packContents): self
    {
        $this->packContents = $packContents;
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

    public function getSubmittedAt(): ?\DateTimeInterface
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?\DateTimeInterface $submittedAt): self
    {
        $this->submittedAt = $submittedAt;
        return $this;
    }

    public function getSubmittedBy(): ?string
    {
        return $this->submittedBy;
    }

    public function setSubmittedBy(?string $submittedBy): self
    {
        $this->submittedBy = $submittedBy;
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

    public function getSha256Hash(): ?string
    {
        return $this->sha256Hash;
    }

    public function setSha256Hash(?string $sha256Hash): self
    {
        $this->sha256Hash = $sha256Hash;
        return $this;
    }
}
