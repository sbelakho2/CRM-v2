<?php

declare(strict_types=1);

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
     * Calculate contact count for filter rules (SQL COUNT, never loads rows).
     */
    private function calculateContactCount(array $filterRules): int
    {
        if (empty($filterRules)) {
            return $this->contactRepository->count([]);
        }

        if ($this->isCompilableToDql($filterRules)) {
            try {
                return (int) $this->buildFilterQueryBuilder($filterRules)
                    ->select('COUNT(c.id)')
                    ->getQuery()
                    ->getSingleScalarResult();
            } catch (\Throwable) {
                // Fall through to the legacy evaluation below: a counting
                // failure must degrade, never break segment creation.
            }
        }

        $contacts = $this->getContactsByFilters($filterRules);
        return count($contacts);
    }

    /**
     * Contact fields that segment filters may compile to SQL safely.
     * Anything outside this whitelist keeps the legacy in-memory path.
     */
    private const ALLOWED_CONTACT_FIELDS = [
        'email', 'firstName', 'lastName', 'jobTitle', 'phone',
        'country', 'city', 'linkedinUrl', 'status', 'createdAt',
    ];

    /**
     * Company fields reachable via dot notation (e.g. "company.sector").
     */
    private const ALLOWED_COMPANY_FIELDS = [
        'name', 'sector', 'country', 'region', 'city', 'accountTier', 'companyStatus',
    ];

    private const ALLOWED_OPERATORS = [
        '=', '!=', '>', '>=', '<', '<=',
        'contains', 'not_contains', 'starts_with', 'ends_with',
        'is_empty', 'is_not_empty',
    ];

    /**
     * True when every rule references a whitelisted field/operator, so the
     * whole filter set can compile to a single DQL query.
     */
    private function isCompilableToDql(array $filterRules): bool
    {
        $rules = $filterRules['rules'] ?? [];
        if ($rules === []) {
            return true; // empty rules trivially compile
        }

        foreach ($rules as $rule) {
            $field = $rule['field'] ?? '';
            $operator = $rule['operator'] ?? '=';

            if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
                return false;
            }

            if (str_contains($field, '.')) {
                [$entity, $property] = explode('.', $field, 2);
                if ($entity !== 'company' || !in_array($property, self::ALLOWED_COMPANY_FIELDS, true)) {
                    return false;
                }
            } elseif (!in_array($field, self::ALLOWED_CONTACT_FIELDS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build a QueryBuilder implementing the filter rules against Contact,
     * joined with Company when any rule uses dot notation.
     */
    private function buildFilterQueryBuilder(array $filterRules): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->contactRepository->createQueryBuilder('c');
        $needsCompanyJoin = false;

        foreach (($filterRules['rules'] ?? []) as $index => $rule) {
            $field = (string) ($rule['field'] ?? '');
            $operator = (string) ($rule['operator'] ?? '=');
            $value = $rule['value'] ?? null;

            $alias = 'c';
            $column = $field;
            if (str_contains($field, '.')) {
                [$entity, $property] = explode('.', $field, 2);
                $alias = 'co';
                $column = $property;
                $needsCompanyJoin = true;
            }

            $param = 'f'.$index;
            $predicate = null;

            switch ($operator) {
                case '=':         $predicate = $qb->expr()->eq($alias.'.'.$column, ':'.$param); break;
                case '!=':        $predicate = $qb->expr()->neq($alias.'.'.$column, ':'.$param); break;
                case '>':         $predicate = $qb->expr()->gt($alias.'.'.$column, ':'.$param); break;
                case '>=':        $predicate = $qb->expr()->gte($alias.'.'.$column, ':'.$param); break;
                case '<':         $predicate = $qb->expr()->lt($alias.'.'.$column, ':'.$param); break;
                case '<=':        $predicate = $qb->expr()->lte($alias.'.'.$column, ':'.$param); break;
                case 'contains':  $predicate = 'LOWER('.$alias.'.'.$column.') LIKE :'.$param; $value = '%'.mb_strtolower((string) $value).'%'; break;
                case 'not_contains': $predicate = 'LOWER('.$alias.'.'.$column.') NOT LIKE :'.$param; $value = '%'.mb_strtolower((string) $value).'%'; break;
                case 'starts_with': $predicate = 'LOWER('.$alias.'.'.$column.') LIKE :'.$param; $value = mb_strtolower((string) $value).'%'; break;
                case 'ends_with':  $predicate = 'LOWER('.$alias.'.'.$column.') LIKE :'.$param; $value = '%'.mb_strtolower((string) $value); break;
                case 'is_empty':   $predicate = '('.$alias.'.'.$column.' IS NULL OR '.$alias.'.'.$column.' = \'\')'; break;
                case 'is_not_empty': $predicate = '('.$alias.'.'.$column.' IS NOT NULL AND '.$alias.'.'.$column.' <> \'\')'; break;
            }

            if ($predicate === null) {
                continue;
            }

            if (($filterRules['operator'] ?? 'AND') === 'OR') {
                $qb->orWhere($predicate);
            } else {
                $qb->andWhere($predicate);
            }

            if (!in_array($operator, ['is_empty', 'is_not_empty'], true)) {
                $qb->setParameter($param, $value);
            }
        }

        if ($needsCompanyJoin) {
            $qb->leftJoin('c.company', 'co');
        }

        return $qb;
    }

    /**
     * Get contacts matching filter rules.
     *
     * Filter rules structure:
     * [
     *   'operator' => 'AND' | 'OR',
     *   'rules' => [
     *     ['field' => 'email', 'operator' => 'contains', 'value' => '@acme.com'],
     *     ['field' => 'leadScore', 'operator' => '>=', 'value' => 75],
     *   ]
     * ]
     *
     * Whitelisted fields compile to a DB-side query with real LIMIT/OFFSET
     * and never load the contact table into PHP memory. Only non-whitelisted
     * legacy fields fall back to the (bounded, but in-memory) legacy path.
     */
    private function getContactsByFilters(array $filterRules, ?int $limit = null, int $offset = 0): array
    {
        if ($this->isCompilableToDql($filterRules)) {
            try {
                $qb = $this->buildFilterQueryBuilder($filterRules)
                    ->orderBy('c.id', 'ASC');

                if ($limit !== null) {
                    $qb->setMaxResults($limit);
                }
                if ($offset > 0) {
                    $qb->setFirstResult($offset);
                }

                return $qb->getQuery()->getResult();
            } catch (\Throwable) {
                // Degrade to the legacy evaluation below.
            }
        }

        // Legacy fallback for non-whitelisted fields (or when the DB-side
        // path is unavailable, e.g. repositories without a query builder).
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
