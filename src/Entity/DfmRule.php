<?php

namespace App\Entity;

use App\Repository\DfmRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DfmRuleRepository::class)]
#[ORM\Table(name: 'dfm_rules')]
#[ORM\Index(name: 'idx_rule_type_severity', columns: ['rule_type', 'severity'])]
class DfmRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $ruleType = null; // PCB, ASSEMBLY, COMPONENT, etc.

    #[ORM\Column(length: 255)]
    private ?string $ruleName = null;

    #[ORM\Column(length: 50)]
    private ?string $severity = null; // Critical, Warning, Info

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $remediationText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $checkLogic = null; // JSON with validation logic

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRuleType(): ?string
    {
        return $this->ruleType;
    }

    public function setRuleType(string $ruleType): self
    {
        $this->ruleType = $ruleType;
        return $this;
    }

    public function getRuleName(): ?string
    {
        return $this->ruleName;
    }

    public function setRuleName(string $ruleName): self
    {
        $this->ruleName = $ruleName;
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

    public function getRemediationText(): ?string
    {
        return $this->remediationText;
    }

    public function setRemediationText(?string $remediationText): self
    {
        $this->remediationText = $remediationText;
        return $this;
    }

    public function getCheckLogic(): ?string
    {
        return $this->checkLogic;
    }

    public function setCheckLogic(?string $checkLogic): self
    {
        $this->checkLogic = $checkLogic;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
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

    public function setCreatedAt(?\DateTimeInterface $createdAt): self
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
