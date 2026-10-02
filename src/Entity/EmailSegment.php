<?php

namespace App\Entity;

use App\Repository\EmailSegmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailSegmentRepository::class)]
#[ORM\Table(name: 'email_segment')]
#[ORM\Index(name: 'idx_segment_name', columns: ['name'])]
#[ORM\Index(name: 'idx_segment_active', columns: ['is_active'])]
#[ORM\HasLifecycleCallbacks]
class EmailSegment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // Protected (not private): Doctrine assigns the identifier via reflection
    // on hydration, so static analysis never sees an int assignment.
    protected ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var array<string, mixed>|list<mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $filterRulesJson = [];

    #[ORM\Column]
    private int $contactCount = 0;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastCalculatedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $createdBy = null;

    public function __construct()
    {
        $this->filterRulesJson = [];
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    public function getFilterRulesJson(): array
    {
        return $this->filterRulesJson;
    }

    /**
     * @param array<string, mixed>|list<mixed> $filterRulesJson
     */
    public function setFilterRulesJson(array $filterRulesJson): self
    {
        $this->filterRulesJson = $filterRulesJson;
        return $this;
    }

    public function getContactCount(): ?int
    {
        return $this->contactCount;
    }

    public function setContactCount(int $contactCount): self
    {
        $this->contactCount = $contactCount;
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

    public function getLastCalculatedAt(): ?\DateTimeInterface
    {
        return $this->lastCalculatedAt;
    }

    public function setLastCalculatedAt(?\DateTimeInterface $lastCalculatedAt): self
    {
        $this->lastCalculatedAt = $lastCalculatedAt;
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

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?string $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }
}
