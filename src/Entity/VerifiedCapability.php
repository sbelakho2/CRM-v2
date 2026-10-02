<?php

namespace App\Entity;

use App\Repository\VerifiedCapabilityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A manufacturing/service capability Starz OFFICIALLY claims, with the
 * operating site and verification state. Sales-outbound capability claims
 * are generated ONLY from rows here (status=verified) — never from
 * hard-coded defaults.
 */
#[ORM\Entity(repositoryClass: VerifiedCapabilityRepository::class)]
#[ORM\Table(name: 'verified_capabilities')]
#[ORM\UniqueConstraint(name: 'uniq_capability_site_key', columns: ['site', 'capability_key'])]
class VerifiedCapability
{
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_PLANNED = 'planned';
    public const STATUS_UNAVAILABLE = 'unavailable';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(length: 50)]
    private ?string $site = null; // e.g. 'Tanger', 'Casablanca', 'company-wide'

    #[ORM\Column(length: 100)]
    private ?string $capabilityKey = null; // e.g. 'pcba', 'smt', 'box_build'

    #[ORM\Column(length: 100)]
    private ?string $label = null; // e.g. 'PCB Assembly'

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_VERIFIED])]
    private string $status = self::STATUS_VERIFIED;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $verifiedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $validUntil = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $evidenceDocument = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function isClaimable(): bool
    {
        if ($this->status !== self::STATUS_VERIFIED) {
            return false;
        }

        if ($this->validUntil !== null && $this->validUntil < new \DateTime()) {
            return false;
        }

        return true;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): ?string
    {
        return $this->site;
    }

    public function setSite(string $site): self
    {
        $this->site = $site;

        return $this;
    }

    public function getCapabilityKey(): ?string
    {
        return $this->capabilityKey;
    }

    public function setCapabilityKey(string $capabilityKey): self
    {
        $this->capabilityKey = $capabilityKey;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

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

    public function getVerifiedAt(): ?\DateTimeInterface
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeInterface $verifiedAt): self
    {
        $this->verifiedAt = $verifiedAt;

        return $this;
    }

    public function getValidUntil(): ?\DateTimeInterface
    {
        return $this->validUntil;
    }

    public function setValidUntil(?\DateTimeInterface $validUntil): self
    {
        $this->validUntil = $validUntil;

        return $this;
    }

    public function getEvidenceDocument(): ?string
    {
        return $this->evidenceDocument;
    }

    public function setEvidenceDocument(?string $evidenceDocument): self
    {
        $this->evidenceDocument = $evidenceDocument;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
