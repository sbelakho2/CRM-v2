<?php

namespace App\Entity;

use App\Repository\ReportAuditRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReportAuditRepository::class)]
#[ORM\Table(name: 'report_audits')]
#[ORM\Index(name: 'idx_report_type', columns: ['report_type'])]
#[ORM\Index(name: 'idx_entity_type_id', columns: ['entity_type', 'entity_id'])]
#[ORM\HasLifecycleCallbacks]
class ReportAudit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // Protected (not private): Doctrine assigns the identifier via reflection
    // on hydration, so static analysis never sees an int assignment.
    protected ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $reportType = null; // quote, dfm_report, cost_breakdown, exceptions_report, sourcing_risk, etc.

    #[ORM\Column(length: 100)]
    private ?string $entityType = null; // Quote, Company

    #[ORM\Column]
    private ?int $entityId = null; // ID of the related entity

    #[ORM\Column(length: 64)]
    private ?string $sha256Hash = null; // SHA-256 hash of the generated report

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $versionId = null; // Dataset/API version identifier

    /** @var array<string, string>|null JSON: {tariff_v: "2025.1", freight_v: "2025.2", etc.} */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $datasetVersions = null;

    /** @var array<string, string>|null JSON: {mouser: "v1.2", digikey: "v3.0", etc.} */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $apiVersions = null;

    /** @var array<string, mixed>|null Additional audit metadata */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fileName = null; // Generated file name

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $fileSize = null; // File size in bytes

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $generatedBy = null; // User or system that generated the report

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $generatedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->generatedAt === null) {
            $this->generatedAt = new \DateTime();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReportType(): ?string
    {
        return $this->reportType;
    }

    public function setReportType(string $reportType): self
    {
        $this->reportType = $reportType;
        return $this;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function setEntityType(string $entityType): self
    {
        $this->entityType = $entityType;
        return $this;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function setEntityId(int $entityId): self
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getSha256Hash(): ?string
    {
        return $this->sha256Hash;
    }

    public function setSha256Hash(string $sha256Hash): self
    {
        $this->sha256Hash = $sha256Hash;
        return $this;
    }

    public function getVersionId(): ?string
    {
        return $this->versionId;
    }

    public function setVersionId(?string $versionId): self
    {
        $this->versionId = $versionId;
        return $this;
    }

    /**
     * @return array<string, string>|null
     */
    public function getDatasetVersions(): ?array
    {
        return $this->datasetVersions;
    }

    /**
     * @param array<string, string>|null $datasetVersions
     */
    public function setDatasetVersions(?array $datasetVersions): self
    {
        $this->datasetVersions = $datasetVersions;
        return $this;
    }

    /**
     * @return array<string, string>|null
     */
    public function getApiVersions(): ?array
    {
        return $this->apiVersions;
    }

    /**
     * @param array<string, string>|null $apiVersions
     */
    public function setApiVersions(?array $apiVersions): self
    {
        $this->apiVersions = $apiVersions;
        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(?string $fileName): self
    {
        $this->fileName = $fileName;
        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(?int $fileSize): self
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    public function getGeneratedBy(): ?string
    {
        return $this->generatedBy;
    }

    public function setGeneratedBy(?string $generatedBy): self
    {
        $this->generatedBy = $generatedBy;
        return $this;
    }

    public function getGeneratedAt(): ?\DateTimeInterface
    {
        return $this->generatedAt;
    }

    public function setGeneratedAt(\DateTimeInterface $generatedAt): self
    {
        $this->generatedAt = $generatedAt;
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
