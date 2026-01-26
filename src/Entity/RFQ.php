<?php

namespace App\Entity;

use App\Repository\RFQRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RFQRepository::class)]
#[ORM\Table(name: 'rfqs')]
class RFQ
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class, inversedBy: 'rfqs')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $rfqNumber = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $rfqDate = null;

    #[ORM\Column(length: 50)]
    private ?string $type = 'Standard RFQ'; // NPI, Framework Agreement, Standard RFQ

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $ndaSent = false;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $ndaDate = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $ndaExecuted = false;

    #[ORM\Column(length: 50)]
    private ?string $status = 'Pending'; // Pending, Submitted, Won, Lost, In Review

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $estimatedValue = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $currency = 'EUR';

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $volumeAnnual = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $sopDate = null; // Start of Production

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $technicalScope = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
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

    public function getRfqNumber(): ?string
    {
        return $this->rfqNumber;
    }

    public function setRfqNumber(?string $rfqNumber): self
    {
        $this->rfqNumber = $rfqNumber;
        return $this;
    }

    public function getRfqDate(): ?\DateTimeInterface
    {
        return $this->rfqDate;
    }

    public function setRfqDate(?\DateTimeInterface $rfqDate): self
    {
        $this->rfqDate = $rfqDate;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function isNdaSent(): bool
    {
        return $this->ndaSent;
    }

    public function setNdaSent(bool $ndaSent): self
    {
        $this->ndaSent = $ndaSent;
        return $this;
    }

    public function getNdaDate(): ?\DateTimeInterface
    {
        return $this->ndaDate;
    }

    public function setNdaDate(?\DateTimeInterface $ndaDate): self
    {
        $this->ndaDate = $ndaDate;
        return $this;
    }

    public function isNdaExecuted(): bool
    {
        return $this->ndaExecuted;
    }

    public function setNdaExecuted(bool $ndaExecuted): self
    {
        $this->ndaExecuted = $ndaExecuted;
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

    public function getEstimatedValue(): ?string
    {
        return $this->estimatedValue;
    }

    public function setEstimatedValue(?string $estimatedValue): self
    {
        $this->estimatedValue = $estimatedValue;
        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): self
    {
        $this->currency = $currency ? strtoupper($currency) : null;
        return $this;
    }

    public function getVolumeAnnual(): ?int
    {
        return $this->volumeAnnual;
    }

    public function setVolumeAnnual(?int $volumeAnnual): self
    {
        $this->volumeAnnual = $volumeAnnual;
        return $this;
    }

    public function getSopDate(): ?\DateTimeInterface
    {
        return $this->sopDate;
    }

    public function setSopDate(?\DateTimeInterface $sopDate): self
    {
        $this->sopDate = $sopDate;
        return $this;
    }

    public function getTechnicalScope(): ?string
    {
        return $this->technicalScope;
    }

    public function setTechnicalScope(?string $technicalScope): self
    {
        $this->technicalScope = $technicalScope;
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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
