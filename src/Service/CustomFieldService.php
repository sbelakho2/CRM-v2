<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Repository\CustomFieldDefinitionRepository;
use App\Repository\CustomFieldValueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraints as Assert;

class CustomFieldService
{
    public function __construct(
        private readonly CustomFieldDefinitionRepository $definitionRepository,
        private readonly CustomFieldValueRepository $valueRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Get all fields for an entity type
     *
     * @return list<CustomFieldDefinition>
     */
    public function getFieldsForEntity(string $entityType): array
    {
        return $this->definitionRepository->findByEntityType($entityType);
    }

    /**
     * Get all values for an entity
     *
     * @return list<CustomFieldValue>
     */
    public function getValuesForEntity(string $entityType, int $entityId): array
    {
        /** @var list<CustomFieldValue> $values */
        $values = $this->valueRepository->findByEntity($entityType, $entityId);

        return $values;
    }

    /**
     * Get values as an associative array [fieldKey => value]
     *
     * @return array<string, mixed>
     */
    public function getValuesAsArray(string $entityType, int $entityId): array
    {
        /** @var list<CustomFieldValue> $values */
        $values = $this->valueRepository->findByEntity($entityType, $entityId);

        $result = [];
        foreach ($values as $value) {
            $fieldKey = $value->getFieldDefinition()?->getFieldKey();
            if ($fieldKey === null) {
                continue;
            }
            $result[$fieldKey] = $value->getValue();
        }

        return $result;
    }

    /**
     * Get values with full field information
     *
     * @return list<array{field: CustomFieldDefinition, value: CustomFieldValue|null, displayValue: string, rawValue: mixed}>
     */
    public function getValuesWithFields(string $entityType, int $entityId): array
    {
        $fields = $this->getFieldsForEntity($entityType);
        /** @var array<string, CustomFieldValue> $valueMap */
        $valueMap = $this->valueRepository->findAsMap($entityType, $entityId);

        $result = [];
        foreach ($fields as $field) {
            $fieldKey = $field->getFieldKey() ?? '';
            $value = $valueMap[$fieldKey] ?? null;
            $result[] = [
                'field' => $field,
                'value' => $value,
                'displayValue' => $value ? $value->getDisplayValue() : ($field->getDefaultValue() ?? ''),
                'rawValue' => $value ? $value->getValue() : $field->getDefaultValue(),
            ];
        }

        return $result;
    }

    /**
     * Save custom field values for an entity
     *
     * Upserts the provided values, then removes stored values for this entity
     * whose field key is NOT present in the payload (fields removed from the
     * form / deactivated definitions leave orphan rows otherwise). Only values
     * belonging to this entity are touched.
      * @param array<string|int, mixed> $data
     */
    public function saveValues(string $entityType, int $entityId, array $data): void
    {
        $fields = $this->getFieldsForEntity($entityType);
        
        foreach ($fields as $field) {
            $key = $field->getFieldKey();
            if ($key === null) {
                continue; // field_key is set for persisted definitions; skip defensively
            }

            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                
                // Get or create value entity
                $fieldValue = $this->valueRepository->findValue($field, $entityType, $entityId);
                
                if (!$fieldValue) {
                    $fieldValue = new CustomFieldValue();
                    $fieldValue->setFieldDefinition($field);
                    $fieldValue->setEntityType($entityType);
                    $fieldValue->setEntityId($entityId);
                }

                $fieldValue->setValue($value);
                $this->entityManager->persist($fieldValue);
            }
        }

        // Delete stale values: stored values whose field key is not part of
        // this save payload belong to fields that were removed from the form
        // or deactivated — remove them so they stop surfacing as "current".
        /** @var list<CustomFieldValue> $staleCheckValues */
        $staleCheckValues = $this->valueRepository->findByEntity($entityType, $entityId);
        foreach ($staleCheckValues as $existingValue) {
            $existingKey = $existingValue->getFieldDefinition()?->getFieldKey();
            if ($existingKey !== null && !array_key_exists($existingKey, $data)) {
                $this->entityManager->remove($existingValue);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Add custom fields to a form builder
     */
    public function addFieldsToForm(FormBuilderInterface $builder, string $entityType, ?int $entityId = null): void
    {
        $fields = $this->getFieldsForEntity($entityType);
        /** @var array<string, CustomFieldValue> $existingValuesFull */
        $existingValuesFull = $entityId ? $this->valueRepository->findAsMap($entityType, $entityId) : [];
        $existingValues = $entityId ? $existingValuesFull : [];

        foreach ($fields as $field) {
            if (!$field->isActive()) {
                continue;
            }

            $fieldKey = $field->getFieldKey() ?? '';
            $existingValue = $existingValues[$fieldKey] ?? null;
            $defaultValue = $existingValue ? $existingValue->getValue() : $field->getDefaultValue();

            $options = $this->buildFormFieldOptions($field, $defaultValue);
            $type = $this->getFormFieldType($field);

            $builder->add('custom_' . $fieldKey, $type, $options);
        }
    }

    /**
     * Get the Symfony form type for a field
     *
     * @return class-string<\Symfony\Component\Form\FormTypeInterface>
     */
    private function getFormFieldType(CustomFieldDefinition $field): string
    {
        return match($field->getFieldType()) {
            CustomFieldDefinition::TYPE_TEXTAREA => TextareaType::class,
            CustomFieldDefinition::TYPE_NUMBER, 
            CustomFieldDefinition::TYPE_DECIMAL,
            CustomFieldDefinition::TYPE_CURRENCY,
            CustomFieldDefinition::TYPE_PERCENTAGE => NumberType::class,
            CustomFieldDefinition::TYPE_DATE => DateType::class,
            CustomFieldDefinition::TYPE_DATETIME => DateTimeType::class,
            CustomFieldDefinition::TYPE_BOOLEAN => CheckboxType::class,
            CustomFieldDefinition::TYPE_SELECT, 
            CustomFieldDefinition::TYPE_MULTISELECT => ChoiceType::class,
            CustomFieldDefinition::TYPE_EMAIL => EmailType::class,
            CustomFieldDefinition::TYPE_URL => UrlType::class,
            CustomFieldDefinition::TYPE_PHONE => TelType::class,
            default => TextType::class,
        };
    }

    /**
     * Build form field options
     *
     * @return array<string, mixed>
     */
    private function buildFormFieldOptions(CustomFieldDefinition $field, mixed $defaultValue): array
    {
        $options = [
            'label' => $field->getLabel(),
            'required' => $field->isRequired(),
            'data' => $defaultValue,
            'attr' => [
                'class' => 'rams-input',
            ],
        ];

        if ($field->getPlaceholder()) {
            $options['attr']['placeholder'] = $field->getPlaceholder();
        }

        if ($field->getDescription()) {
            $options['help'] = $field->getDescription();
        }

        // Add constraints
        $constraints = [];
        
        if ($field->isRequired()) {
            $constraints[] = new Assert\NotBlank();
        }

        $validationRules = $field->getValidationRules() ?? [];
        
        if (isset($validationRules['minLength'])) {
            $constraints[] = new Assert\Length(['min' => $validationRules['minLength']]);
        }
        
        if (isset($validationRules['maxLength'])) {
            $constraints[] = new Assert\Length(['max' => $validationRules['maxLength']]);
        }
        
        if (isset($validationRules['min'])) {
            $constraints[] = new Assert\GreaterThanOrEqual($validationRules['min']);
        }
        
        if (isset($validationRules['max'])) {
            $constraints[] = new Assert\LessThanOrEqual($validationRules['max']);
        }
        
        if (isset($validationRules['pattern'])) {
            $constraints[] = new Assert\Regex(['pattern' => $validationRules['pattern']]);
        }

        if (!empty($constraints)) {
            $options['constraints'] = $constraints;
        }

        // Type-specific options
        switch ($field->getFieldType()) {
            case CustomFieldDefinition::TYPE_SELECT:
                $options['choices'] = $field->getOptionChoices();
                $options['placeholder'] = 'Select...';
                $options['attr']['class'] = 'rams-select';
                break;

            case CustomFieldDefinition::TYPE_MULTISELECT:
                $options['choices'] = $field->getOptionChoices();
                $options['multiple'] = true;
                $options['expanded'] = false;
                $options['attr']['class'] = 'rams-select';
                break;

            case CustomFieldDefinition::TYPE_TEXTAREA:
                $options['attr']['rows'] = $field->getConfig()['rows'] ?? 4;
                break;

            case CustomFieldDefinition::TYPE_DATE:
                $options['widget'] = 'single_text';
                break;

            case CustomFieldDefinition::TYPE_DATETIME:
                $options['widget'] = 'single_text';
                break;

            case CustomFieldDefinition::TYPE_BOOLEAN:
                $options['attr']['class'] = 'rams-checkbox';
                break;

            case CustomFieldDefinition::TYPE_CURRENCY:
                $options['attr']['step'] = '0.01';
                break;

            case CustomFieldDefinition::TYPE_PERCENTAGE:
                $options['attr']['min'] = 0;
                $options['attr']['max'] = 100;
                $options['attr']['step'] = '0.1';
                break;
        }

        return $options;
    }

    /**
     * Extract custom field values from form data
     *
     * @return array<string, mixed>
     */
    public function extractCustomFieldData(FormInterface $form, string $entityType): array
    {
        $fields = $this->getFieldsForEntity($entityType);
        $data = [];

        foreach ($fields as $field) {
            $fieldKey = $field->getFieldKey();
            if ($fieldKey === null) {
                continue;
            }
            $formFieldName = 'custom_' . $fieldKey;

            if ($form->has($formFieldName)) {
                $data[$fieldKey] = $form->get($formFieldName)->getData();
            }
        }

        return $data;
    }

    /**
     * Delete all custom field values for an entity
     */
    public function deleteEntityValues(string $entityType, int $entityId): void
    {
        $this->valueRepository->deleteByEntity($entityType, $entityId);
    }

    /**
     * Search entities by custom field values
     *
     * @param array<string|int, mixed> $searchCriteria
     * @return list<int> Entity IDs matching every criterion
     */
    public function searchByCustomFields(string $entityType, array $searchCriteria): array
    {
        $entityIds = null;

        foreach ($searchCriteria as $fieldKey => $searchValue) {
            if (empty($searchValue) || !is_string($fieldKey) || !is_scalar($searchValue)) {
                continue;
            }

            $field = $this->definitionRepository->findOneBy([
                'fieldKey' => $fieldKey,
                'entityType' => $entityType,
            ]);

            if (!$field || !$field->isSearchable()) {
                continue;
            }

            /** @var list<int> $matchingIds */
            $matchingIds = $this->valueRepository->searchByFieldValue($field, (string) $searchValue);

            if ($entityIds === null) {
                $entityIds = $matchingIds;
            } else {
                $entityIds = array_intersect($entityIds, $matchingIds);
            }
        }

        return array_values($entityIds ?? []);
    }

    /**
     * Get fields grouped by group name
     *
     * @return array<string, list<CustomFieldDefinition>>
     */
    public function getGroupedFields(string $entityType): array
    {
        return $this->definitionRepository->findGroupedByFieldGroup($entityType);
    }

    /**
     * Create a new custom field
     */
    public function createField(CustomFieldDefinition $field): CustomFieldDefinition
    {
        // Auto-generate field key if not set
        if (!$field->getFieldKey()) {
            $field->setFieldKey($field->generateFieldKey());
        }

        // Ensure unique field key
        $baseKey = $field->getFieldKey() ?? '';
        $entityType = $field->getEntityType() ?? '';
        $counter = 1;
        while ($this->definitionRepository->fieldKeyExists($field->getFieldKey() ?? $baseKey, $entityType)) {
            $field->setFieldKey($baseKey . '_' . $counter);
            $counter++;
        }

        // Set sort order
        if ($field->getSortOrder() === 0) {
            $field->setSortOrder($this->definitionRepository->getNextSortOrder($entityType));
        }

        $this->entityManager->persist($field);
        $this->entityManager->flush();

        return $field;
    }
}
