<?php

namespace App\Entity;

use App\Repository\PortalCandidateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PortalCandidateRepository::class)]
#[ORM\Table(name: 'portal_candidates')]
#[ORM\Index(name: 'idx_company_status', columns: ['company_id', 'status'])]
class PortalCandidate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
// Protected (not private): Doctrine assigns the identifier via reflection
    // on hydration, so static analysis never sees an int assignment.
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class, inversedBy: 'portalCandidates')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Company $company = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $portalUrl = null;

    #[ORM\Column(length: 50)]
    private ?string $status = null; // discovered, approved, onboarding, active, rejected

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $discoveredAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $evidenceSnapshot = null; // Screenshot/HTML evidence

    #[ORM\Column(type: 'boolean')]
    private bool $hasRobotsTxt = true;

    #[ORM\Column(type: 'boolean')]
    private bool $requiresManualSubmit = true; // Never auto-submit

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $tosUrl = null;

    #[ORM\Column(type: 'boolean')]
    private bool $tosReviewed = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $approvedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $approvedBy = null;

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

    public function getPortalUrl(): ?string
    {
        return $this->portalUrl;
    }

    public function setPortalUrl(string $portalUrl): self
    {
        $this->portalUrl = $portalUrl;
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

    public function getDiscoveredAt(): ?\DateTimeInterface
    {
        return $this->discoveredAt;
    }

    public function setDiscoveredAt(\DateTimeInterface $discoveredAt): self
    {
        $this->discoveredAt = $discoveredAt;
        return $this;
    }

    public function getEvidenceSnapshot(): ?string
    {
        return $this->evidenceSnapshot;
    }

    public function setEvidenceSnapshot(?string $evidenceSnapshot): self
    {
        $this->evidenceSnapshot = $evidenceSnapshot;
        return $this;
    }

    public function hasRobotsTxt(): bool
    {
        return $this->hasRobotsTxt;
    }

    public function setHasRobotsTxt(bool $hasRobotsTxt): self
    {
        $this->hasRobotsTxt = $hasRobotsTxt;
        return $this;
    }

    public function requiresManualSubmit(): bool
    {
        return $this->requiresManualSubmit;
    }

    public function setRequiresManualSubmit(bool $requiresManualSubmit): self
    {
        $this->requiresManualSubmit = $requiresManualSubmit;
        return $this;
    }

    public function getTosUrl(): ?string
    {
        return $this->tosUrl;
    }

    public function setTosUrl(?string $tosUrl): self
    {
        $this->tosUrl = $tosUrl;
        return $this;
    }

    public function isTosReviewed(): bool
    {
        return $this->tosReviewed;
    }

    public function setTosReviewed(bool $tosReviewed): self
    {
        $this->tosReviewed = $tosReviewed;
        return $this;
    }

    public function getApprovedAt(): ?\DateTimeInterface
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeInterface $approvedAt): self
    {
        $this->approvedAt = $approvedAt;
        return $this;
    }

    public function getApprovedBy(): ?string
    {
        return $this->approvedBy;
    }

    public function setApprovedBy(?string $approvedBy): self
    {
        $this->approvedBy = $approvedBy;
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
}
