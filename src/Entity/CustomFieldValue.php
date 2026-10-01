<?php

namespace App\Entity;

use App\Repository\CustomFieldValueRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CustomFieldValueRepository::class)]
#[ORM\Table(name: 'custom_field_values')]
#[ORM\UniqueConstraint(name: 'unique_field_value', columns: ['field_definition_id', 'entity_type', 'entity_id'])]
#[ORM\Index(columns: ['entity_type', 'entity_id'], name: 'idx_custom_value_entity')]
#[ORM\HasLifecycleCallbacks]
class CustomFieldValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CustomFieldDefinition::class, inversedBy: 'values')]
    #[ORM\JoinColumn(name: 'field_definition_id', nullable: false, onDelete: 'CASCADE')]
    private ?CustomFieldDefinition $fieldDefinition = null;

    #[ORM\Column(length: 50, name: 'entity_type')]
    private ?string $entityType = null;

    #[ORM\Column(name: 'entity_id')]
    private ?int $entityId = null;

    // Store different value types
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $textValue = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 6, nullable: true)]
    private ?string $numberValue = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateValue = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $datetimeValue = null;

    #[ORM\Column(nullable: true)]
    private ?bool $booleanValue = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $jsonValue = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    /**
     * Get the value in its appropriate type based on field definition
     */
    public function getValue(): mixed
    {
        if (!$this->fieldDefinition) {
            return $this->textValue;
        }

        return match($this->fieldDefinition->getFieldType()) {
            CustomFieldDefinition::TYPE_NUMBER, 
            CustomFieldDefinition::TYPE_DECIMAL,
            CustomFieldDefinition::TYPE_CURRENCY,
            CustomFieldDefinition::TYPE_PERCENTAGE => $this->numberValue !== null ? (float) $this->numberValue : null,
            
            CustomFieldDefinition::TYPE_DATE => $this->dateValue,
            CustomFieldDefinition::TYPE_DATETIME => $this->datetimeValue,
            CustomFieldDefinition::TYPE_BOOLEAN => $this->booleanValue,
            CustomFieldDefinition::TYPE_MULTISELECT => $this->jsonValue ?? [],
            
            default => $this->textValue,
        };
    }

    /**
     * Set the value, storing in the appropriate column based on field type
     */
    public function setValue(mixed $value): static
    {
        // Reset all value columns
        $this->textValue = null;
        $this->numberValue = null;
        $this->dateValue = null;
        $this->datetimeValue = null;
        $this->booleanValue = null;
        $this->jsonValue = null;

        if ($value === null) {
            return $this;
        }

        if (!$this->fieldDefinition) {
            $this->textValue = (string) $value;
            return $this;
        }

        switch ($this->fieldDefinition->getFieldType()) {
            case CustomFieldDefinition::TYPE_NUMBER:
            case CustomFieldDefinition::TYPE_DECIMAL:
            case CustomFieldDefinition::TYPE_CURRENCY:
            case CustomFieldDefinition::TYPE_PERCENTAGE:
                $this->numberValue = is_numeric($value) ? (string) $value : null;
                break;

            case CustomFieldDefinition::TYPE_DATE:
                if ($value instanceof \DateTimeInterface) {
                    $this->dateValue = $value;
                } elseif (is_string($value)) {
                    $this->dateValue = new \DateTime($value);
                }
                break;

            case CustomFieldDefinition::TYPE_DATETIME:
                if ($value instanceof \DateTimeInterface) {
                    $this->datetimeValue = $value;
                } elseif (is_string($value)) {
                    $this->datetimeValue = new \DateTime($value);
                }
                break;

            case CustomFieldDefinition::TYPE_BOOLEAN:
                $this->booleanValue = (bool) $value;
                break;

            case CustomFieldDefinition::TYPE_MULTISELECT:
                $this->jsonValue = is_array($value) ? $value : [$value];
                break;

            default:
                $this->textValue = (string) $value;
        }

        return $this;
    }

    /**
     * Get formatted display value
     */
    public function getDisplayValue(): string
    {
        $value = $this->getValue();

        if ($value === null) {
            return '';
        }

        if (!$this->fieldDefinition) {
            return (string) $value;
        }

        return match($this->fieldDefinition->getFieldType()) {
            CustomFieldDefinition::TYPE_DATE => $value instanceof \DateTimeInterface ? $value->format('M j, Y') : '',
            CustomFieldDefinition::TYPE_DATETIME => $value instanceof \DateTimeInterface ? $value->format('M j, Y g:i A') : '',
            CustomFieldDefinition::TYPE_BOOLEAN => $value ? 'Yes' : 'No',
            CustomFieldDefinition::TYPE_CURRENCY => '$' . number_format((float) $value, 2),
            CustomFieldDefinition::TYPE_PERCENTAGE => number_format((float) $value, 2) . '%',
            CustomFieldDefinition::TYPE_MULTISELECT => is_array($value) ? implode(', ', $value) : (string) $value,
            CustomFieldDefinition::TYPE_URL => $value,
            default => (string) $value,
        };
    }

    // Getters and Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFieldDefinition(): ?CustomFieldDefinition
    {
        return $this->fieldDefinition;
    }

    public function setFieldDefinition(?CustomFieldDefinition $fieldDefinition): static
    {
        $this->fieldDefinition = $fieldDefinition;
        return $this;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function setEntityType(string $entityType): static
    {
        $this->entityType = $entityType;
        return $this;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function setEntityId(int $entityId): static
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getTextValue(): ?string
    {
        return $this->textValue;
    }

    public function setTextValue(?string $textValue): static
    {
        $this->textValue = $textValue;
        return $this;
    }

    public function getNumberValue(): ?string
    {
        return $this->numberValue;
    }

    public function setNumberValue(?string $numberValue): static
    {
        $this->numberValue = $numberValue;
        return $this;
    }

    public function getDateValue(): ?\DateTimeInterface
    {
        return $this->dateValue;
    }

    public function setDateValue(?\DateTimeInterface $dateValue): static
    {
        $this->dateValue = $dateValue;
        return $this;
    }

    public function getDatetimeValue(): ?\DateTimeInterface
    {
        return $this->datetimeValue;
    }

    public function setDatetimeValue(?\DateTimeInterface $datetimeValue): static
    {
        $this->datetimeValue = $datetimeValue;
        return $this;
    }

    public function getBooleanValue(): ?bool
    {
        return $this->booleanValue;
    }

    public function setBooleanValue(?bool $booleanValue): static
    {
        $this->booleanValue = $booleanValue;
        return $this;
    }

    public function getJsonValue(): ?array
    {
        return $this->jsonValue;
    }

    /**
     * @param array<string|int, mixed> $jsonValue
     */
    public /**
 * @param array<string|int, mixed> $jsonValue
 */
function setJsonValue(?array $jsonValue): static
    {
        $this->jsonValue = $jsonValue;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
