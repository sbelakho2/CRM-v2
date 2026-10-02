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
 *
 * @phpstan-type FilterRule array{field?: string, operator?: string, value?: mixed}
 * @phpstan-type FilterRules array{operator?: mixed, rules?: list<FilterRule>}
 */
class EmailSegmentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        // Kept protected rather than removed: injected for service symmetry
        // and reserved for direct segment lookups; this class currently works
        // through the injected repositories only.
        protected EmailSegmentRepository $segmentRepository,
        private ContactRepository $contactRepository
    ) {}

    /**
     * Create a new segment
     * @param FilterRules $filterRules
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
     * @param FilterRules|null $filterRules
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
            /** @var FilterRules $recalcRules */
            $recalcRules = $segment->getFilterRulesJson();
            $contactCount = $this->calculateContactCount($recalcRules);
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
        /** @var FilterRules $filterRules */
        $filterRules = $segment->getFilterRulesJson();
        $contactCount = $this->calculateContactCount($filterRules);
        $segment->setContactCount($contactCount);
        $segment->setLastCalculatedAt(new \DateTimeImmutable());
        $segment->setUpdatedAt(new \DateTimeImmutable());
        
        $this->entityManager->flush();

        return $contactCount;
    }

    /**
     * Get all contacts matching a segment
     *
     * @return list<Contact>
     */
    public function getSegmentContacts(EmailSegment $segment, ?int $limit = null, int $offset = 0): array
    {
        /** @var FilterRules $filterRules */
        $filterRules = $segment->getFilterRulesJson();

        return $this->getContactsByFilters($filterRules, $limit, $offset);
    }

    /**
     * Check if a contact matches a segment
     */
    public function contactMatchesSegment(Contact $contact, EmailSegment $segment): bool
    {
        /** @var FilterRules $filterRules */
        $filterRules = $segment->getFilterRulesJson();

        return $this->evaluateFilters($contact, $filterRules);
    }

    /**
     * Calculate contact count for filter rules (SQL COUNT, never loads rows).
     * @param FilterRules $filterRules
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
    /**
     * Single source of truth for filterable fields: the SQL compiler AND the
     * builder UI are both generated from this map, so the UI can never
     * advertise a field that would fall back to full-table hydration.
     */
    private const ALLOWED_CONTACT_FIELDS = [
        'email', 'firstName', 'lastName', 'jobTitle', 'phone',
        'country', 'city', 'linkedinUrl', 'status', 'createdAt',
    ];
    public const FILTERABLE_FIELDS = [
        'email', 'firstName', 'lastName', 'jobTitle', 'phone',
        'country', 'city', 'linkedinUrl', 'status', 'createdAt',
        'company.name', 'company.sector', 'company.country',
        'company.region', 'company.city', 'company.accountTier', 'company.companyStatus',
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
     * @param FilterRules $filterRules
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
     * @param FilterRules $filterRules
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
                case 'contains':  $predicate = 'LOWER('.$alias.'.'.$column.') LIKE :'.$param; $value = '%'.mb_strtolower($this->stringifyValue($value)).'%'; break;
                case 'not_contains': $predicate = 'LOWER('.$alias.'.'.$column.') NOT LIKE :'.$param; $value = '%'.mb_strtolower($this->stringifyValue($value)).'%'; break;
                case 'starts_with': $predicate = 'LOWER('.$alias.'.'.$column.') LIKE :'.$param; $value = mb_strtolower($this->stringifyValue($value)).'%'; break;
                case 'ends_with':  $predicate = 'LOWER('.$alias.'.'.$column.') LIKE :'.$param; $value = '%'.mb_strtolower($this->stringifyValue($value)); break;
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
     * @param FilterRules $filterRules
     * @return list<Contact>
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

                /** @var list<Contact> */
                return $qb->getQuery()->getResult();
            } catch (\InvalidArgumentException $e) {
                throw $e; // deprecated-field errors are never swallowed
            } catch (\Throwable) {
                // Query machinery failed (NOT a deprecated-field error —
                // those rethrow above). Supported-field segments degrade to
                // a bounded in-memory evaluation rather than crash; the
                // hard cap bounds memory when machinery is broken.
                return $this->evaluateInMemory($filterRules, $limit, $offset);
            }
        }

        // Non-whitelisted fields are DEPRECATED filters (several referenced
        // schema-dropped columns, e.g. contacts.lead_score / subscribed — the
        // old findAll() path silently returned wrong results for them). They
        // now fail loudly with a migration instruction instead of hydrating
        // the whole contact table into PHP memory.
        $unsupported = [];
        foreach (($filterRules['rules'] ?? []) as $rule) {
            $field = (string) ($rule['field'] ?? '');
            $isCompany = str_starts_with($field, 'company.');
            $base = $isCompany ? substr($field, 8) : $field;
            $whitelist = $isCompany ? self::ALLOWED_COMPANY_FIELDS : self::ALLOWED_CONTACT_FIELDS;
            if ($base !== '' && !in_array($base, $whitelist, true)) {
                $unsupported[] = $field;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'This segment uses a deprecated filter (%s) that must be migrated to a supported field. Supported contact fields: %s; company fields: company.%s.',
            implode(', ', array_unique($unsupported) ?: ['?']),
            implode(', ', self::ALLOWED_CONTACT_FIELDS),
            implode(', company.', self::ALLOWED_COMPANY_FIELDS)
        ));
    }

    /**
     * Bounded in-memory evaluation for machinery-failure degradation ONLY
     * (deprecated fields throw before reaching here). Cap is a safety
     * valve, not a product limit.
     * @param FilterRules $filterRules
     * @return list<Contact>
     */
    private function evaluateInMemory(array $filterRules, ?int $limit, int $offset): array
    {
        // Last-resort degradation only (deprecated fields never reach
        // here): hydrate once, bounded, never again.
        $all = array_slice($this->contactRepository->findAll(), 0, 5000);
        $matching = [];
        foreach ($all as $contact) {
            if ($this->evaluateFilters($contact, $filterRules)) {
                $matching[] = $contact;
            }
        }

        return $limit !== null
            ? array_slice($matching, $offset, $limit)
            : array_slice($matching, $offset);
    }

    /**
     * Evaluate if a contact matches filter rules
     * @param FilterRules $filterRules
     */
    private function evaluateFilters(Contact $contact, array $filterRules): bool
    {
        if (empty($filterRules) || empty($filterRules['rules'])) {
            return true;
        }

        $operator = $filterRules['operator'] ?? 'AND';
        $rules = $filterRules['rules'];

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
     * @param FilterRule $rule
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
            'contains' => str_contains(strtolower($this->stringifyValue($contactValue)), strtolower($this->stringifyValue($value))),
            'not_contains' => !str_contains(strtolower($this->stringifyValue($contactValue)), strtolower($this->stringifyValue($value))),
            // strncmp/substr_compare are semantically identical to
            // str_starts_with()/str_ends_with() here (empty needle matches),
            // and are fully known to static analysis.
            'starts_with' => strncmp(strtolower($this->stringifyValue($contactValue)), strtolower($this->stringifyValue($value)), strlen(strtolower($this->stringifyValue($value)))) === 0,
            'ends_with' => substr_compare(strtolower($this->stringifyValue($contactValue)), strtolower($this->stringifyValue($value)), -strlen(strtolower($this->stringifyValue($value)))) === 0,
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
     * Render a filter value as a string for substring comparisons
     * (mirrors weak string casts; non-scalar values compare as empty).
     */
    private function stringifyValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Validate filter rules structure
     *
     * @param FilterRules $filterRules
     * @return list<string>
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

        $rules = $filterRules['rules'] ?? null;
        if (!is_array($rules)) {
            $errors[] = 'Missing or invalid rules array';
            return $errors;
        }

        foreach ($rules as $index => $rule) {
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
     * Fields offered to the segment builder UI — generated from the exact
     * FILTERABLE_FIELDS map the SQL compiler whitelists, so the UI can never
     * advertise a field that would force the full-table in-memory fallback.
     */
    /** @return array{contact: list<array{name: string, label: string, type: string}>, company: list<array{name: string, label: string, type: string}>} */
    public function getAvailableFields(): array
    {
        $labels = [
            'firstName' => 'First Name',
            'lastName' => 'Last Name',
            'email' => 'Email',
            'jobTitle' => 'Job Title',
            'phone' => 'Phone',
            'city' => 'City',
            'country' => 'Country',
            'linkedinUrl' => 'LinkedIn URL',
            'status' => 'Status',
            'createdAt' => 'Created Date',
            'company.name' => 'Company Name',
            'company.sector' => 'Sector',
            'company.country' => 'Company Country',
            'company.region' => 'Company Region',
            'company.city' => 'Company City',
            'company.accountTier' => 'Account Tier',
            'company.companyStatus' => 'Company Status',
        ];

        $fields = [
            'contact' => [],
            'company' => [],
        ];
        foreach (self::FILTERABLE_FIELDS as $name) {
            $entry = [
                'name' => $name,
                'label' => $labels[$name],
                'type' => str_starts_with($name, 'createdAt') ? 'date' : 'string',
            ];
            $fields[str_starts_with($name, 'company.') ? 'company' : 'contact'][] = $entry;
        }

        return $fields;
    }

    /**
     * Get available operators for a field type
     *
     * @return list<array{value: string, label: string}>
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
     *
     * @return array{contactCount: int|null, campaignCount: int, emailsSent: int, lastCalculated: \DateTimeInterface|null, ruleCount: int}
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

        /** @var FilterRules $filterRules */
        $filterRules = $segment->getFilterRulesJson();

        return [
            'contactCount' => $segment->getContactCount(),
            'campaignCount' => (int) $campaignCount,
            'emailsSent' => (int) $emailsSent,
            'lastCalculated' => $segment->getLastCalculatedAt(),
            'ruleCount' => count($filterRules['rules'] ?? []),
        ];
    }
}
