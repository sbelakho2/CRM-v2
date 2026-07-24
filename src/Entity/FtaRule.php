<?php

namespace App\Entity;

use App\Repository\FtaRuleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FtaRuleRepository::class)]
#[ORM\Table(name: 'fta_rules')]
#[ORM\Index(name: 'idx_hs_code', columns: ['hs_code'])]
#[ORM\HasLifecycleCallbacks]
class FtaRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private ?string $hsCode = null; // HS tariff code

    #[ORM\Column(length: 100)]
    private ?string $ftaAgreement = null; // Morocco-US FTA, USMCA, EU-Morocco, etc.

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rooRequirement = null; // Rules of Origin requirement text

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rooLanguage = null; // Full legal language for FTA certificate

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $minimumValueContent = null; // e.g., 35% for Morocco-US FTA

    #[ORM\Column(type: 'boolean')]
    private bool $requiresCertificate = true;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $certificateType = null; // EUR.1, Certificate of Origin, etc.

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $requiredDocuments = null; // List of supporting documents

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $effectiveDate = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $expiryDate = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTime();
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

    public function getHsCode(): ?string
    {
        return $this->hsCode;
    }

    public function setHsCode(string $hsCode): self
    {
        $this->hsCode = $hsCode;
        return $this;
    }

    public function getFtaAgreement(): ?string
    {
        return $this->ftaAgreement;
    }

    public function setFtaAgreement(string $ftaAgreement): self
    {
        $this->ftaAgreement = $ftaAgreement;
        return $this;
    }

    public function getRooRequirement(): ?string
    {
        return $this->rooRequirement;
    }

    public function setRooRequirement(string $rooRequirement): self
    {
        $this->rooRequirement = $rooRequirement;
        return $this;
    }

    public function getRooLanguage(): ?string
    {
        return $this->rooLanguage;
    }

    public function setRooLanguage(?string $rooLanguage): self
    {
        $this->rooLanguage = $rooLanguage;
        return $this;
    }

    public function getMinimumValueContent(): ?string
    {
        return $this->minimumValueContent;
    }

    public function setMinimumValueContent(?string $minimumValueContent): self
    {
        $this->minimumValueContent = $minimumValueContent;
        return $this;
    }

    public function isRequiresCertificate(): bool
    {
        return $this->requiresCertificate;
    }

    public function setRequiresCertificate(bool $requiresCertificate): self
    {
        $this->requiresCertificate = $requiresCertificate;
        return $this;
    }

    public function getCertificateType(): ?string
    {
        return $this->certificateType;
    }

    public function setCertificateType(?string $certificateType): self
    {
        $this->certificateType = $certificateType;
        return $this;
    }

    public function getRequiredDocuments(): ?string
    {
        return $this->requiredDocuments;
    }

    public function setRequiredDocuments(?string $requiredDocuments): self
    {
        $this->requiredDocuments = $requiredDocuments;
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

    public function getEffectiveDate(): ?\DateTimeInterface
    {
        return $this->effectiveDate;
    }

    public function setEffectiveDate(\DateTimeInterface $effectiveDate): self
    {
        $this->effectiveDate = $effectiveDate;
        return $this;
    }

    public function getExpiryDate(): ?\DateTimeInterface
    {
        return $this->expiryDate;
    }

    public function setExpiryDate(?\DateTimeInterface $expiryDate): self
    {
        $this->expiryDate = $expiryDate;
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
