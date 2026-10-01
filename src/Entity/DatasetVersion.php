<?php

namespace App\Entity;

use App\Repository\DatasetVersionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DatasetVersionRepository::class)]
#[ORM\Table(name: 'dataset_version')]
#[ORM\Index(name: 'idx_dataset_type', columns: ['dataset_type'])]
#[ORM\Index(name: 'idx_dataset_active', columns: ['is_active'])]
#[ORM\Index(name: 'idx_dataset_uuid', columns: ['version_uuid'])]
#[ORM\HasLifecycleCallbacks]
class DatasetVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $datasetType = null; // TARIFF_RATES, FREIGHT_TABLES, FX_RATES, etc.

    #[ORM\Column(length: 36, unique: true)]
    private ?string $versionUuid = null;

    #[ORM\Column(length: 64)]
    private ?string $sha256Hash = null;

    #[ORM\Column(nullable: true)]
    private ?int $recordCount = 0;

    #[ORM\Column(nullable: true)]
    private ?bool $isActive = false;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $importedAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $importedBy = null;

    public function __construct()
    {
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->importedAt === null) {
            $this->importedAt = new \DateTime();
        }
        if ($this->versionUuid === null) {
            $this->versionUuid = Uuid::v4()->toRfc4122();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDatasetType(): ?string
    {
        return $this->datasetType;
    }

    public function setDatasetType(string $datasetType): self
    {
        $this->datasetType = $datasetType;
        return $this;
    }

    public function getVersionUuid(): ?string
    {
        return $this->versionUuid;
    }

    public function setVersionUuid(string $versionUuid): self
    {
        $this->versionUuid = $versionUuid;
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

    public function getRecordCount(): ?int
    {
        return $this->recordCount;
    }

    public function setRecordCount(int $recordCount): self
    {
        $this->recordCount = $recordCount;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive ?? false;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * @param array<string|int, mixed> $metadata
     */
    public /**
 * @param array<string|int, mixed> $metadata
 */
function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getImportedAt(): ?\DateTimeInterface
    {
        return $this->importedAt;
    }

    public function setImportedAt(\DateTimeInterface $importedAt): self
    {
        $this->importedAt = $importedAt;
        return $this;
    }

    public function getImportedBy(): ?string
    {
        return $this->importedBy;
    }

    public function setImportedBy(?string $importedBy): self
    {
        $this->importedBy = $importedBy;
        return $this;
    }
}
