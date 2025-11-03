<?php

namespace App\Service;

use App\Entity\EmailSegment;
use App\Entity\Contact;
use App\Repository\EmailSegmentRepository;
use App\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for managing email segments and contact filtering
 * 
 * Features:
 * - Dynamic segment creation with filter rules
 * - Contact matching based on multiple criteria
 * - Segment size calculation and caching
 * - Filter rule validation and evaluation
 * - Support for complex AND/OR logic
 */
class EmailSegmentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailSegmentRepository $segmentRepository,
        private ContactRepository $contactRepository
    ) {}

    /**
     * Create a new segment
     */
    public function createSegment(
        string $name,
        ?string $description = null,
        array $filterRules = [],
        bool $isActive = true
    ): EmailSegment {
        $segment = new EmailSegment();
        $segment->setName($name);
        $segment->setDescription($description);
        $segment->setFilterRulesJson($filterRules);
        $segment->setIsActive($isActive);
        $segment->setCreatedAt(new \DateTimeImmutable());
        $segment->setUpdatedAt(new \DateTimeImmutable());

        // Calculate initial contact count
        $contactCount = $this->calculateContactCount($filterRules);
        $segment->setContactCount($contactCount);
        $segment->setLastCalculatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($segment);
        $this->entityManager->flush();

        return $segment;
    }

    /**
     * Update segment
     */
    public function updateSegment(
        EmailSegment $segment,
        ?string $name = null,
        ?string $description = null,
        ?array $filterRules = null,
        ?bool $isActive = null,
        bool $recalculate = true
    ): EmailSegment {
        if ($name !== null) {
            $segment->setName($name);
        }
        if ($description !== null) {
            $segment->setDescription($description);
        }
        if ($filterRules !== null) {
            $segment->setFilterRulesJson($filterRules);
            $recalculate = true; // Force recalculation if rules changed
        }
        if ($isActive !== null) {
            $segment->setIsActive($isActive);
        }

        if ($recalculate) {
            $contactCount = $this->calculateContactCount($segment->getFilterRulesJson() ?? []);
            $segment->setContactCount($contactCount);
            $segment->setLastCalculatedAt(new \DateTimeImmutable());
        }

        $segment->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $segment;
    }

    /**
     * Delete segment
     */
    public function deleteSegment(EmailSegment $segment, bool $hardDelete = false): void
    {
        if ($hardDelete) {
            $this->entityManager->remove($segment);
        } else {
            $segment->setIsActive(false);
            $segment->setUpdatedAt(new \DateTimeImmutable());
        }

        $this->entityManager->flush();
    }

    /**
     * Recalculate contact count for a segment
     */
    public function recalculateSegment(EmailSegment $segment): int
    {
        $contactCount = $this->calculateContactCount($segment->getFilterRulesJson() ?? []);
        $segment->setContactCount($contactCount);
        $segment->setLastCalculatedAt(new \DateTimeImmutable());
        $segment->setUpdatedAt(new \DateTimeImmutable());
        
        $this->entityManager->flush();

        return $contactCount;
    }

    /**
     * Get all contacts matching a segment
     */
    public function getSegmentContacts(EmailSegment $segment, ?int $limit = null, int $offset = 0): array
    {
        return $this->getContactsByFilters($segment->getFilterRulesJson() ?? [], $limit, $offset);
    }

    /**
     * Check if a contact matches a segment
     */
    public function contactMatchesSegment(Contact $contact, EmailSegment $segment): bool
    {
        return $this->evaluateFilters($contact, $segment->getFilterRulesJson() ?? []);
    }

    /**
     * Calculate contact count for filter rules
     */
    private function calculateContactCount(array $filterRules): int
    {
        if (empty($filterRules)) {
            return $this->contactRepository->count([]);
        }

        $contacts = $this->getContactsByFilters($filterRules);
        return count($contacts);
    }

    /**
     * Get contacts matching filter rules
     * 
     * Filter rules structure:
     * [
     *   'operator' => 'AND' | 'OR',
     *   'rules' => [
     *     ['field' => 'email', 'operator' => 'contains', 'value' => '@acme.com'],
     *     ['field' => 'leadScore', 'operator' => '>=', 'value' => 75],
     *   ]
     * ]
     */
    private function getContactsByFilters(array $filterRules, ?int $limit = null, int $offset = 0): array
    {
        if (empty($filterRules)) {
            return $this->contactRepository->findAll();
        }

        // Get all contacts and filter in memory
        // For production, this should be converted to DQL for better performance
        $allContacts = $this->contactRepository->findAll();
        $matchingContacts = [];

        foreach ($allContacts as $contact) {
            if ($this->evaluateFilters($contact, $filterRules)) {
                $matchingContacts[] = $contact;
            }
        }

        // Apply limit and offset
        if ($limit !== null) {
            return array_slice($matchingContacts, $offset, $limit);
        }

        return array_slice($matchingContacts, $offset);
    }

    /**
     * Evaluate if a contact matches filter rules
     */
    private function evaluateFilters(Contact $contact, array $filterRules): bool
    {
        if (empty($filterRules) || empty($filterRules['rules'])) {
            return true;
        }

        $operator = $filterRules['operator'] ?? 'AND';
        $rules = $filterRules['rules'] ?? [];

        $results = [];
        foreach ($rules as $rule) {
            $results[] = $this->evaluateRule($contact, $rule);
        }

        if ($operator === 'OR') {
            return in_array(true, $results, true);
        }

        // AND logic
        return !in_array(false, $results, true);
    }

    /**
     * Evaluate a single filter rule
     */
    private function evaluateRule(Contact $contact, array $rule): bool
    {
        $field = $rule['field'] ?? '';
        $operator = $rule['operator'] ?? '=';
        $value = $rule['value'] ?? null;

        // Get field value from contact
        $contactValue = $this->getContactFieldValue($contact, $field);

        // Evaluate based on operator
        return match ($operator) {
            '=' => $contactValue == $value,
            '!=' => $contactValue != $value,
            '>' => $contactValue > $value,
            '>=' => $contactValue >= $value,
            '<' => $contactValue < $value,
            '<=' => $contactValue <= $value,
            'contains' => str_contains(strtolower((string) $contactValue), strtolower((string) $value)),
            'not_contains' => !str_contains(strtolower((string) $contactValue), strtolower((string) $value)),
            'starts_with' => str_starts_with(strtolower((string) $contactValue), strtolower((string) $value)),
            'ends_with' => str_ends_with(strtolower((string) $contactValue), strtolower((string) $value)),
            'is_empty' => empty($contactValue),
            'is_not_empty' => !empty($contactValue),
            'in' => is_array($value) && in_array($contactValue, $value),
            'not_in' => is_array($value) && !in_array($contactValue, $value),
            default => false,
        };
    }

    /**
     * Get field value from contact using reflection
     */
    private function getContactFieldValue(Contact $contact, string $field): mixed
    {
        // Support dot notation for company fields (e.g., 'company.industry')
        if (str_contains($field, '.')) {
            [$entity, $property] = explode('.', $field, 2);
            
            if ($entity === 'company' && $contact->getCompany()) {
                return $this->getEntityProperty($contact->getCompany(), $property);
            }
            
            return null;
        }

        return $this->getEntityProperty($contact, $field);
    }

    /**
     * Get property value from entity using getter methods
     */
    private function getEntityProperty(object $entity, string $property): mixed
    {
        // Convert snake_case to camelCase
        $camelCase = lcfirst(str_replace('_', '', ucwords($property, '_')));
        
        // Try standard getter
        $getter = 'get' . ucfirst($camelCase);
        if (method_exists($entity, $getter)) {
            return $entity->$getter();
        }

        // Try is* getter for boolean
        $isGetter = 'is' . ucfirst($camelCase);
        if (method_exists($entity, $isGetter)) {
            return $entity->$isGetter();
        }

        // Try has* getter
        $hasGetter = 'has' . ucfirst($camelCase);
        if (method_exists($entity, $hasGetter)) {
            return $entity->$hasGetter();
        }

        return null;
    }

    /**
     * Validate filter rules structure
     */
    public function validateFilterRules(array $filterRules): array
    {
        $errors = [];

        if (empty($filterRules)) {
            return $errors; // Empty rules are valid (match all)
        }

        if (!isset($filterRules['operator'])) {
            $errors[] = 'Missing operator (AND/OR)';
        } elseif (!in_array($filterRules['operator'], ['AND', 'OR'])) {
            $errors[] = 'Invalid operator: must be AND or OR';
        }

        if (!isset($filterRules['rules']) || !is_array($filterRules['rules'])) {
            $errors[] = 'Missing or invalid rules array';
            return $errors;
        }

        foreach ($filterRules['rules'] as $index => $rule) {
            if (!isset($rule['field'])) {
                $errors[] = sprintf('Rule #%d: missing field', $index + 1);
            }
            if (!isset($rule['operator'])) {
                $errors[] = sprintf('Rule #%d: missing operator', $index + 1);
            }
            if (!isset($rule['value']) && !in_array($rule['operator'] ?? '', ['is_empty', 'is_not_empty'])) {
                $errors[] = sprintf('Rule #%d: missing value', $index + 1);
            }
        }

        return $errors;
    }

    /**
     * Get available filter fields
     */
    public function getAvailableFields(): array
    {
        return [
            'contact' => [
                ['name' => 'firstName', 'label' => 'First Name', 'type' => 'string'],
                ['name' => 'lastName', 'label' => 'Last Name', 'type' => 'string'],
                ['name' => 'email', 'label' => 'Email', 'type' => 'string'],
                ['name' => 'phone', 'label' => 'Phone', 'type' => 'string'],
                ['name' => 'title', 'label' => 'Job Title', 'type' => 'string'],
                ['name' => 'leadScore', 'label' => 'Lead Score', 'type' => 'number'],
                ['name' => 'isSubscribed', 'label' => 'Is Subscribed', 'type' => 'boolean'],
                ['name' => 'createdAt', 'label' => 'Created Date', 'type' => 'date'],
            ],
            'company' => [
                ['name' => 'company.name', 'label' => 'Company Name', 'type' => 'string'],
                ['name' => 'company.industry', 'label' => 'Industry', 'type' => 'string'],
                ['name' => 'company.employeeCount', 'label' => 'Employee Count', 'type' => 'number'],
                ['name' => 'company.annualRevenue', 'label' => 'Annual Revenue', 'type' => 'number'],
                ['name' => 'company.website', 'label' => 'Website', 'type' => 'string'],
                ['name' => 'company.country', 'label' => 'Country', 'type' => 'string'],
            ],
        ];
    }

    /**
     * Get available operators for a field type
     */
    public function getOperatorsForFieldType(string $type): array
    {
        return match ($type) {
            'string' => [
                ['value' => '=', 'label' => 'equals'],
                ['value' => '!=', 'label' => 'not equals'],
                ['value' => 'contains', 'label' => 'contains'],
                ['value' => 'not_contains', 'label' => 'does not contain'],
                ['value' => 'starts_with', 'label' => 'starts with'],
                ['value' => 'ends_with', 'label' => 'ends with'],
                ['value' => 'is_empty', 'label' => 'is empty'],
                ['value' => 'is_not_empty', 'label' => 'is not empty'],
            ],
            'number' => [
                ['value' => '=', 'label' => 'equals'],
                ['value' => '!=', 'label' => 'not equals'],
                ['value' => '>', 'label' => 'greater than'],
                ['value' => '>=', 'label' => 'greater than or equal'],
                ['value' => '<', 'label' => 'less than'],
                ['value' => '<=', 'label' => 'less than or equal'],
                ['value' => 'is_empty', 'label' => 'is empty'],
                ['value' => 'is_not_empty', 'label' => 'is not empty'],
            ],
            'boolean' => [
                ['value' => '=', 'label' => 'is'],
            ],
            'date' => [
                ['value' => '=', 'label' => 'on'],
                ['value' => '!=', 'label' => 'not on'],
                ['value' => '>', 'label' => 'after'],
                ['value' => '>=', 'label' => 'on or after'],
                ['value' => '<', 'label' => 'before'],
                ['value' => '<=', 'label' => 'on or before'],
            ],
            default => [],
        };
    }

    /**
     * Get segment statistics
     */
    public function getSegmentStats(EmailSegment $segment): array
    {
        $campaignCount = $this->entityManager->createQuery(
            'SELECT COUNT(ec.id) FROM App\Entity\EmailCampaign ec WHERE ec.segment = :segment'
        )
        ->setParameter('segment', $segment)
        ->getSingleScalarResult();

        $emailsSent = $this->entityManager->createQuery(
            'SELECT COUNT(es.id) FROM App\Entity\EmailSend es 
             JOIN es.campaign ec 
             WHERE ec.segment = :segment'
        )
        ->setParameter('segment', $segment)
        ->getSingleScalarResult();

        return [
            'contactCount' => $segment->getContactCount(),
            'campaignCount' => (int) $campaignCount,
            'emailsSent' => (int) $emailsSent,
            'lastCalculated' => $segment->getLastCalculatedAt(),
            'ruleCount' => count($segment->getFilterRulesJson()['rules'] ?? []),
        ];
    }
}
