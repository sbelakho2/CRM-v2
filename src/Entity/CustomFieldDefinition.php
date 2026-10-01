<?php

namespace App\Entity;

use App\Repository\CustomFieldDefinitionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CustomFieldDefinitionRepository::class)]
#[ORM\Table(name: 'custom_field_definitions')]
#[ORM\UniqueConstraint(name: 'unique_field_entity', columns: ['field_key', 'entity_type'])]
#[ORM\Index(columns: ['entity_type'], name: 'idx_custom_field_entity')]
#[ORM\HasLifecycleCallbacks]
class CustomFieldDefinition
{
    // Entity types that can have custom fields
    public const ENTITY_COMPANY = 'company';
    public const ENTITY_CONTACT = 'contact';
    public const ENTITY_LEAD = 'lead';
    public const ENTITY_RFQ = 'rfq';
    public const ENTITY_QUOTE = 'quote';

    // Field types
    public const TYPE_TEXT = 'text';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_NUMBER = 'number';
    public const TYPE_DECIMAL = 'decimal';
    public const TYPE_DATE = 'date';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_SELECT = 'select';
    public const TYPE_MULTISELECT = 'multiselect';
    public const TYPE_EMAIL = 'email';
    public const TYPE_URL = 'url';
    public const TYPE_PHONE = 'phone';
    public const TYPE_CURRENCY = 'currency';
    public const TYPE_PERCENTAGE = 'percentage';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, name: 'field_key')]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z][a-z0-9_]*$/', message: 'Field key must start with a letter and contain only lowercase letters, numbers, and underscores')]
    private ?string $fieldKey = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $label = null;

    #[ORM\Column(length: 50, name: 'entity_type')]
    #[Assert\NotBlank]
    private ?string $entityType = null;

    #[ORM\Column(length: 50, name: 'field_type')]
    private string $fieldType = self::TYPE_TEXT;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $placeholder = null;

    #[ORM\Column(nullable: true)]
    private ?string $defaultValue = null;

    #[ORM\Column]
    private bool $isRequired = false;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private bool $isSearchable = false;

    #[ORM\Column]
    private bool $showInList = false;

    #[ORM\Column]
    private bool $showInDetail = true;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $fieldGroup = null;

    // Validation rules stored as JSON
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $validationRules = null;

    // Options for select/multiselect fields
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $options = null;

    // Additional configuration
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $config = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\OneToMany(mappedBy: 'fieldDefinition', targetEntity: CustomFieldValue::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $values;

    public function __construct()
    {
        $this->values = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public static function getEntityTypes(): array
    {
        return [
            'Company' => self::ENTITY_COMPANY,
            'Contact' => self::ENTITY_CONTACT,
            'Lead' => self::ENTITY_LEAD,
            'RFQ' => self::ENTITY_RFQ,
            'Quote' => self::ENTITY_QUOTE,
        ];
    }

    public static function getFieldTypes(): array
    {
        return [
            'Text' => self::TYPE_TEXT,
            'Long Text' => self::TYPE_TEXTAREA,
            'Number' => self::TYPE_NUMBER,
            'Decimal' => self::TYPE_DECIMAL,
            'Date' => self::TYPE_DATE,
            'Date & Time' => self::TYPE_DATETIME,
            'Yes/No' => self::TYPE_BOOLEAN,
            'Dropdown' => self::TYPE_SELECT,
            'Multi-select' => self::TYPE_MULTISELECT,
            'Email' => self::TYPE_EMAIL,
            'URL' => self::TYPE_URL,
            'Phone' => self::TYPE_PHONE,
            'Currency' => self::TYPE_CURRENCY,
            'Percentage' => self::TYPE_PERCENTAGE,
        ];
    }

    public function getFieldTypeIcon(): string
    {
        return match($this->fieldType) {
            self::TYPE_TEXT => 'font',
            self::TYPE_TEXTAREA => 'align-left',
            self::TYPE_NUMBER, self::TYPE_DECIMAL => 'hashtag',
            self::TYPE_DATE, self::TYPE_DATETIME => 'calendar',
            self::TYPE_BOOLEAN => 'toggle-on',
            self::TYPE_SELECT, self::TYPE_MULTISELECT => 'list',
            self::TYPE_EMAIL => 'envelope',
            self::TYPE_URL => 'link',
            self::TYPE_PHONE => 'phone',
            self::TYPE_CURRENCY => 'dollar-sign',
            self::TYPE_PERCENTAGE => 'percent',
            default => 'tag',
        };
    }

    public function hasOptions(): bool
    {
        return in_array($this->fieldType, [self::TYPE_SELECT, self::TYPE_MULTISELECT]);
    }

    public function getOptionChoices(): array
    {
        if (!$this->options) {
            return [];
        }

        $choices = [];
        foreach ($this->options as $option) {
            $label = $option['label'] ?? $option;
            $value = $option['value'] ?? $option;
            $choices[$label] = $value;
        }

        return $choices;
    }

    public function generateFieldKey(): string
    {
        if ($this->label) {
            // Convert label to snake_case field key
            $key = strtolower($this->label);
            $key = preg_replace('/[^a-z0-9]+/', '_', $key);
            $key = trim($key, '_');
            return $key;
        }

        // 8 random bytes: collision-proof and not enumerable (uniqid() is
        // microsecond-time based).
        return 'field_' . bin2hex(random_bytes(8));
    }

    // Getters and Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFieldKey(): ?string
    {
        return $this->fieldKey;
    }

    public function setFieldKey(string $fieldKey): static
    {
        $this->fieldKey = $fieldKey;
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;
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

    public function getFieldType(): string
    {
        return $this->fieldType;
    }

    public function setFieldType(string $fieldType): static
    {
        $this->fieldType = $fieldType;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getPlaceholder(): ?string
    {
        return $this->placeholder;
    }

    public function setPlaceholder(?string $placeholder): static
    {
        $this->placeholder = $placeholder;
        return $this;
    }

    public function getDefaultValue(): ?string
    {
        return $this->defaultValue;
    }

    public function setDefaultValue(?string $defaultValue): static
    {
        $this->defaultValue = $defaultValue;
        return $this;
    }

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function setIsRequired(bool $isRequired): static
    {
        $this->isRequired = $isRequired;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function isSearchable(): bool
    {
        return $this->isSearchable;
    }

    public function setIsSearchable(bool $isSearchable): static
    {
        $this->isSearchable = $isSearchable;
        return $this;
    }

    public function getShowInList(): bool
    {
        return $this->showInList;
    }

    public function setShowInList(bool $showInList): static
    {
        $this->showInList = $showInList;
        return $this;
    }

    public function getShowInDetail(): bool
    {
        return $this->showInDetail;
    }

    public function setShowInDetail(bool $showInDetail): static
    {
        $this->showInDetail = $showInDetail;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function getFieldGroup(): ?string
    {
        return $this->fieldGroup;
    }

    public function setFieldGroup(?string $fieldGroup): static
    {
        $this->fieldGroup = $fieldGroup;
        return $this;
    }

    public function getValidationRules(): ?array
    {
        return $this->validationRules;
    }

    /**
     * @param array<string|int, mixed> $validationRules
     */
    public /**
 * @param array<string|int, mixed> $validationRules
 */
function setValidationRules(?array $validationRules): static
    {
        $this->validationRules = $validationRules;
        return $this;
    }

    public function getOptions(): ?array
    {
    /**
     * @param array<string|int, mixed> $options
     */
        return $this->options;
    }

    public /**
 * @param array<string|int, mixed> $options
 */
function setOptions(?array $options): static
    {
        $this->options = $options;
        return $this;
    }
    /**
     * @param array<string|int, mixed> $config
     */

    public function getConfig(): ?array
    {
        return $this->config;
    }

    public /**
 * @param array<string|int, mixed> $config
 */
function setConfig(?array $config): static
    {
        $this->config = $config;
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

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    /**
     * @return Collection<int, CustomFieldValue>
         /** @return Collection<int, App\Entity\CustomFieldValue> */
    public function getValues(): Collection
    {
        return $this->values;
    }

    public function addValue(CustomFieldValue $value): static
    {
        if (!$this->values->contains($value)) {
            $this->values->add($value);
            $value->setFieldDefinition($this);
        }
        return $this;
    }

    public function removeValue(CustomFieldValue $value): static
    {
        if ($this->values->removeElement($value)) {
            if ($value->getFieldDefinition() === $this) {
                $value->setFieldDefinition(null);
            }
        }
        return $this;
    }
}
