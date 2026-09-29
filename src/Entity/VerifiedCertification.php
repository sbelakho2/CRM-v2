<?php

namespace App\Entity;

use App\Repository\VerifiedCertificationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A quality/standards certification Starz officially holds, with certificate
 * number, issuer and validity window. Outbound certification claims are
 * generated ONLY from rows here that are currently valid.
 */
#[ORM\Entity(repositoryClass: VerifiedCertificationRepository::class)]
#[ORM\Table(name: 'verified_certifications')]
#[ORM\UniqueConstraint(name: 'uniq_certification_site_standard', columns: ['site', 'standard'])]
class VerifiedCertification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private ?string $site = null;

    #[ORM\Column(length: 100)]
    private ?string $standard = null; // ISO 9001, ISO 14001, AS9100, ...

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $certificateNumber = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $issuer = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $validFrom = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $validUntil = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $evidenceDocument = null;

    #[ORM\Column(length: 20, options: ['default' => 'verified'])]
    private string $status = 'verified'; // verified | planned | expired

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function isClaimable(): bool
    {
        if ($this->status !== 'verified') {
            return false;
        }

        $today = new \DateTime('today');
        if ($this->validFrom !== null && $this->validFrom > $today) {
            return false;
        }
        if ($this->validUntil !== null && $this->validUntil < $today) {
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

    public function getStandard(): ?string
    {
        return $this->standard;
    }

    public function setStandard(string $standard): self
    {
        $this->standard = $standard;

        return $this;
    }

    public function getCertificateNumber(): ?string
    {
        return $this->certificateNumber;
    }

    public function setCertificateNumber(?string $certificateNumber): self
    {
        $this->certificateNumber = $certificateNumber;

        return $this;
    }

    public function getIssuer(): ?string
    {
        return $this->issuer;
    }

    public function setIssuer(?string $issuer): self
    {
        $this->issuer = $issuer;

        return $this;
    }

    public function getValidFrom(): ?\DateTimeInterface
    {
        return $this->validFrom;
    }

    public function setValidFrom(?\DateTimeInterface $validFrom): self
    {
        $this->validFrom = $validFrom;

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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
