<?php

namespace App\Entity;

use App\Repository\CooSupplierDeclRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CooSupplierDeclRepository::class)]
#[ORM\Table(name: 'coo_supplier_decls')]
#[ORM\Index(name: 'idx_supplier_mpn', columns: ['supplier_name', 'mpn'])]
class CooSupplierDecl
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $supplierName = null;

    #[ORM\Column(length: 255)]
    private ?string $mpn = null;

    #[ORM\Column(length: 2)]
    private ?string $countryOfOrigin = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $htsCode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $evidenceUrl = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $declaredAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $certificateNumber = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isVerified = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $verifiedBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSupplierName(): ?string
    {
        return $this->supplierName;
    }

    public function setSupplierName(string $supplierName): self
    {
        $this->supplierName = $supplierName;
        return $this;
    }

    public function getMpn(): ?string
    {
        return $this->mpn;
    }

    public function setMpn(string $mpn): self
    {
        $this->mpn = $mpn;
        return $this;
    }

    public function getCountryOfOrigin(): ?string
    {
        return $this->countryOfOrigin;
    }

    public function setCountryOfOrigin(string $countryOfOrigin): self
    {
        $this->countryOfOrigin = $countryOfOrigin;
        return $this;
    }

    public function getHtsCode(): ?string
    {
        return $this->htsCode;
    }

    public function setHtsCode(?string $htsCode): self
    {
        $this->htsCode = $htsCode;
        return $this;
    }

    public function getEvidenceUrl(): ?string
    {
        return $this->evidenceUrl;
    }

    public function setEvidenceUrl(?string $evidenceUrl): self
    {
        $this->evidenceUrl = $evidenceUrl;
        return $this;
    }

    public function getDeclaredAt(): ?\DateTimeInterface
    {
        return $this->declaredAt;
    }

    public function setDeclaredAt(\DateTimeInterface $declaredAt): self
    {
        $this->declaredAt = $declaredAt;
        return $this;
    }

    public function getExpiresAt(): ?\DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeInterface $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): self
    {
        $this->isVerified = $isVerified;
        return $this;
    }

    public function getVerifiedBy(): ?string
    {
        return $this->verifiedBy;
    }

    public function setVerifiedBy(?string $verifiedBy): self
    {
        $this->verifiedBy = $verifiedBy;
        return $this;
    }
}
