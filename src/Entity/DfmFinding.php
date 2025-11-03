<?php

namespace App\Entity;

use App\Repository\DfmFindingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DfmFindingRepository::class)]
#[ORM\Table(name: 'dfm_finding')]
#[ORM\Index(name: 'idx_dfm_quote', columns: ['quote_id'])]
#[ORM\Index(name: 'idx_dfm_severity', columns: ['severity'])]
class DfmFinding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Quote::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Quote $quote = null;

    #[ORM\ManyToOne(targetEntity: DfmRule::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?DfmRule $dfmRule = null;

    #[ORM\Column(length: 100)]
    private ?string $findingType = null; // TRACE_WIDTH, SPACING, DRILL, SOLDERMASK, etc.

    #[ORM\Column(length: 20)]
    private ?string $severity = 'MEDIUM'; // CRITICAL, HIGH, MEDIUM, LOW

    #[ORM\Column(type: 'text')]
    private ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $remediation = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $costImpact = null;

    #[ORM\Column(nullable: true)]
    private ?int $leadTimeImpact = null; // Days added

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

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

    public function getQuote(): ?Quote
    {
        return $this->quote;
    }

    public function setQuote(?Quote $quote): self
    {
        $this->quote = $quote;
        return $this;
    }

    public function getDfmRule(): ?DfmRule
    {
        return $this->dfmRule;
    }

    public function setDfmRule(?DfmRule $dfmRule): self
    {
        $this->dfmRule = $dfmRule;
        return $this;
    }

    public function getFindingType(): ?string
    {
        return $this->findingType;
    }

    public function setFindingType(string $findingType): self
    {
        $this->findingType = $findingType;
        return $this;
    }

    public function getSeverity(): ?string
    {
        return $this->severity;
    }

    public function setSeverity(string $severity): self
    {
        $this->severity = $severity;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getRemediation(): ?string
    {
        return $this->remediation;
    }

    public function setRemediation(?string $remediation): self
    {
        $this->remediation = $remediation;
        return $this;
    }

    public function getCostImpact(): ?string
    {
        return $this->costImpact;
    }

    public function setCostImpact(?string $costImpact): self
    {
        $this->costImpact = $costImpact;
        return $this;
    }

    public function getLeadTimeImpact(): ?int
    {
        return $this->leadTimeImpact;
    }

    public function setLeadTimeImpact(?int $leadTimeImpact): self
    {
        $this->leadTimeImpact = $leadTimeImpact;
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
}
