<?php

namespace App\Entity;

use App\Repository\ProcurementExceptionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProcurementExceptionRepository::class)]
#[ORM\Table(name: 'procurement_exception')]
#[ORM\Index(name: 'idx_proc_exc_bomline', columns: ['bom_line_id'])]
#[ORM\Index(name: 'idx_proc_exc_severity', columns: ['severity'])]
#[ORM\HasLifecycleCallbacks]
class ProcurementException
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BomLine::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?BomLine $bomLine = null;

    #[ORM\Column(length: 50)]
    private ?string $exceptionType = null; // NO_STOCK, OBSOLETE, NO_API, PRICE_SPIKE, etc.

    #[ORM\Column(length: 20)]
    private ?string $severity = 'MEDIUM'; // CRITICAL, HIGH, MEDIUM, LOW

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $recommendation = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

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

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBomLine(): ?BomLine
    {
        return $this->bomLine;
    }

    public function setBomLine(?BomLine $bomLine): self
    {
        $this->bomLine = $bomLine;
        return $this;
    }

    public function getExceptionType(): ?string
    {
        return $this->exceptionType;
    }

    public function setExceptionType(string $exceptionType): self
    {
        $this->exceptionType = $exceptionType;
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

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function getRecommendation(): ?string
    {
        return $this->recommendation;
    }

    public function setRecommendation(?string $recommendation): self
    {
        $this->recommendation = $recommendation;
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
