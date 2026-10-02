<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ReportDefinition;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

class ReportBuilderService
{
    // Field definitions for each data source
    private const SOURCE_FIELDS = [
        'company' => [
            // Corrected to the REAL Company mapping (round-7 audit: several
            // advertised fields did not exist — phone/email/industry/size/
            // state/status were phantom and produced broken reports).
            'id' => ['label' => 'ID', 'type' => 'integer'],
            'name' => ['label' => 'Company Name', 'type' => 'string'],
            'legalName' => ['label' => 'Legal Name', 'type' => 'string'],
            'website' => ['label' => 'Website', 'type' => 'string'],
            'sector' => ['label' => 'Sector', 'type' => 'string'],
            'accountTier' => ['label' => 'Account Tier', 'type' => 'string'],
            'companyStatus' => ['label' => 'Status', 'type' => 'string'],
            'pipelineStage' => ['label' => 'Pipeline Stage', 'type' => 'string'],
            'city' => ['label' => 'City', 'type' => 'string'],
            'country' => ['label' => 'Country', 'type' => 'string'],
            'region' => ['label' => 'Region', 'type' => 'string'],
            'createdAt' => ['label' => 'Created Date', 'type' => 'datetime'],
            'updatedAt' => ['label' => 'Updated Date', 'type' => 'datetime'],
        ],
        'contact' => [
            'id' => ['label' => 'ID', 'type' => 'integer'],
            'firstName' => ['label' => 'First Name', 'type' => 'string'],
            'lastName' => ['label' => 'Last Name', 'type' => 'string'],
            'email' => ['label' => 'Email', 'type' => 'string'],
            'phone' => ['label' => 'Phone', 'type' => 'string'],
            'jobTitle' => ['label' => 'Job Title', 'type' => 'string'],
            // DQL uses MAPPED property names, not getter names — the Contact
            // property is $primaryContact (isPrimaryContact() is only the getter).
            'primaryContact' => ['label' => 'Primary Contact', 'type' => 'boolean'],
            'createdAt' => ['label' => 'Created Date', 'type' => 'datetime'],
            'company.name' => ['label' => 'Company Name', 'type' => 'string', 'relation' => 'company'],
        ],
        'lead' => [
            'id' => ['label' => 'ID', 'type' => 'integer'],
            'companyName' => ['label' => 'Company Name', 'type' => 'string'],
            'legalName' => ['label' => 'Legal Name', 'type' => 'string'],
            'websiteRoot' => ['label' => 'Website', 'type' => 'string'],
            'leadUrl' => ['label' => 'Lead URL', 'type' => 'string'],
            'regionTag' => ['label' => 'Region', 'type' => 'string'],
            'leadScore' => ['label' => 'Lead Score', 'type' => 'integer'],
            'reviewStatus' => ['label' => 'Review Status', 'type' => 'string'],
            'createdAt' => ['label' => 'Created Date', 'type' => 'datetime'],
            'updatedAt' => ['label' => 'Updated Date', 'type' => 'datetime'],
        ],
        'rfq' => [
            'id' => ['label' => 'ID', 'type' => 'integer'],
            'rfqNumber' => ['label' => 'RFQ Number', 'type' => 'string'],
            'type' => ['label' => 'RFQ Type', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'string'],
            'estimatedValue' => ['label' => 'Estimated Value', 'type' => 'decimal'],
            'currency' => ['label' => 'Currency', 'type' => 'string'],
            'rfqDate' => ['label' => 'RFQ Date', 'type' => 'datetime'],
            'sopDate' => ['label' => 'Start of Production', 'type' => 'datetime'],
            'createdAt' => ['label' => 'Created Date', 'type' => 'datetime'],
            'company.name' => ['label' => 'Company Name', 'type' => 'string', 'relation' => 'company'],
        ],
        'quote' => [
            'id' => ['label' => 'ID', 'type' => 'integer'],
            'quoteNumber' => ['label' => 'Quote Number', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'string'],
            'totalCost' => ['label' => 'Total Cost', 'type' => 'decimal'],
            'currency' => ['label' => 'Currency', 'type' => 'string'],
            'issuingCompany' => ['label' => 'Issuing Company', 'type' => 'string'],
            'createdAt' => ['label' => 'Created Date', 'type' => 'datetime'],
            'company.name' => ['label' => 'Company Name', 'type' => 'string', 'relation' => 'company'],
        ],
        'task' => [
            'id' => ['label' => 'ID', 'type' => 'integer'],
            'title' => ['label' => 'Title', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'string'],
            'priority' => ['label' => 'Priority', 'type' => 'string'],
            'type' => ['label' => 'Type', 'type' => 'string'],
            'dueDate' => ['label' => 'Due Date', 'type' => 'datetime'],
            'completedAt' => ['label' => 'Completed Date', 'type' => 'datetime'],
            'createdAt' => ['label' => 'Created Date', 'type' => 'datetime'],
            'assignedTo.email' => ['label' => 'Assigned To', 'type' => 'string', 'relation' => 'assignedTo'],
        ],
        'calendar' => [
            'id' => ['label' => 'ID', 'type' => 'integer'],
            'title' => ['label' => 'Title', 'type' => 'string'],
            'eventType' => ['label' => 'Event Type', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'string'],
            'startAt' => ['label' => 'Start Time', 'type' => 'datetime'],
            'endAt' => ['label' => 'End Time', 'type' => 'datetime'],
            'allDay' => ['label' => 'All Day', 'type' => 'boolean'],
            'createdAt' => ['label' => 'Created Date', 'type' => 'datetime'],
        ],
        'email_campaign' => [
            'id' => ['label' => 'ID', 'type' => 'integer'],
            'name' => ['label' => 'Campaign Name', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'string'],
            'type' => ['label' => 'Type', 'type' => 'string'],
            'touchCount' => ['label' => 'Touch Count', 'type' => 'integer'],
            'active' => ['label' => 'Active', 'type' => 'boolean'],
            'createdAt' => ['label' => 'Created Date', 'type' => 'datetime'],
            'scheduledAt' => ['label' => 'Scheduled Date', 'type' => 'datetime'],
            'sentAt' => ['label' => 'Sent Date', 'type' => 'datetime'],
        ],
        'email_send' => [
            'id' => ['label' => 'ID', 'type' => 'integer'],
            'emailAddress' => ['label' => 'Recipient Email', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'string'],
            'touchNumber' => ['label' => 'Touch Number', 'type' => 'integer'],
            'sentAt' => ['label' => 'Sent Date', 'type' => 'datetime'],
            'openedAt' => ['label' => 'Opened Date', 'type' => 'datetime'],
            'clickedAt' => ['label' => 'Clicked Date', 'type' => 'datetime'],
        ],
    ];
    
    private const ENTITY_MAP = [
        'company' => 'App\Entity\Company',
        'contact' => 'App\Entity\Contact',
        'lead' => 'App\Entity\Lead',
        'rfq' => 'App\Entity\RFQ',
        'quote' => 'App\Entity\Quote',
        'task' => 'App\Entity\Task',
        'calendar' => 'App\Entity\CalendarEvent',
        'email_campaign' => 'App\Entity\EmailCampaign',
        'email_send' => 'App\Entity\EmailSend',
    ];
    
    public function __construct(
        private EntityManagerInterface $em,
        private \Symfony\Bundle\SecurityBundle\Security $security,
        private ?LoggerInterface $logger = null
    ) {}
    
    /**
     * Get available fields for a data source
     *
     * @return array<string, array{label: string, type: string, relation?: string}>
     */
    public function getFieldsForSource(string $dataSource): array
    {
        return self::SOURCE_FIELDS[$dataSource] ?? [];
    }

    /**
     * Get all data sources with their labels
     *
     * @return array<string, string>
     */
    public function getDataSources(): array
    {
        return ReportDefinition::getDataSources();
    }

    /**
     * Execute a report and return results
     *
     * @param array<array-key, mixed> $runtimeFilters
     * @return array{success: bool, error?: string, data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function executeReport(ReportDefinition $report, array $runtimeFilters = []): array
    {
        $dataSourceKey = $report->getDataSource() ?? '';
        $entityClass = self::ENTITY_MAP[$dataSourceKey] ?? null;
        
        if (!$entityClass || !class_exists($entityClass)) {
            return [
                'success' => false,
                'error' => 'Invalid data source: ' . $report->getDataSource(),
                'data' => [],
                'meta' => [],
            ];
        }
        
        $qb = $this->em->createQueryBuilder()
            ->from($entityClass, 'e');

        // BASE DATA SCOPE: reports respect the same visibility contracts as
        // the controllers. Archived business records are hidden (matching the
        // archive-not-delete model), and calendar data is scoped to the
        // current user's own events unless the user is an admin — the report
        // builder must never become a path around either.
        $dataSource = $report->getDataSource() ?? '';
        if (in_array($dataSource, ['company', 'contact', 'rfq', 'quote', 'email_campaign'], true)) {
            $qb->andWhere('e.archivedAt IS NULL');
        }
        if ($dataSource === 'calendar') {
            $user = $this->security->getUser();
            if ($user === null || !$this->security->isGranted('ROLE_ADMIN')) {
                $qb->andWhere('e.organizer = :reportUser')
                   ->setParameter('reportUser', $user);
            }
        }
        
        // Build SELECT based on columns
        $columns = $report->getColumns();
        if (empty($columns)) {
            $columns = ['id', 'createdAt'];
        }
        
        $selectParts = [];
        $joinsMade = [];
        $warnings = [];
        $nonAggregatedSelectExprs = [];
        $nonAggregatedSelectFields = [];
        $hasAggregatedSelect = false;
        
        foreach ($columns as $column) {
            if (is_array($column)) {
                $fieldRaw = $column['field'] ?? '';
                $field = is_scalar($fieldRaw) ? (string) $fieldRaw : '';
                $aliasRaw = $column['alias'] ?? null;
                $alias = is_scalar($aliasRaw) ? (string) $aliasRaw : null;
                $aggregationRaw = $column['aggregation'] ?? null;
                $aggregation = is_scalar($aggregationRaw) ? (string) $aggregationRaw : null;
            } else {
                $field = is_scalar($column) ? (string) $column : '';
                $alias = null;
                $aggregation = null;
            }

            if ($field === '') {
                continue;
            }

            // ── Client-supplied ALIASES are DQL result identifiers too — a
            // whitelisted field does not sanitize the alias. Validate the
            // shape; anything else falls back to the server-generated alias.
            $alias = $this->sanitizeAlias($alias) ?? $this->defaultAlias($field, $aggregation);

            // ── Aggregation functions are whitelisted to a known set ──
            // An EMPTY aggregation means "none" (what the form submits),
            // never an invalid value.
            if ($aggregation !== null && $aggregation === '') {
                $aggregation = null;
            }
            if ($aggregation !== null) {
                $aggregation = strtolower($aggregation);
                if (!in_array($aggregation, ['count', 'sum', 'avg', 'min', 'max'], true)) {
                    $warnings[] = "Skipped invalid aggregation '{$aggregation}' on field '{$field}'";
                    continue;
                }
            }

            $fieldPath = $this->resolveFieldExpression($qb, $field, $dataSourceKey, $joinsMade);
            if ($fieldPath === null) {
                $warnings[] = "Skipped column '{$field}': not a valid field for data source '{$dataSourceKey}'";
                continue;
            }

            if ($aggregation) {
                $hasAggregatedSelect = true;
                $selectParts[] = "{$aggregation}({$fieldPath}) as {$alias}";
            } else {
                $nonAggregatedSelectExprs[] = $fieldPath;
                $nonAggregatedSelectFields[$field] = true;
                $selectParts[] = "{$fieldPath} as {$alias}";
            }
        }

        // ONLY_FULL_GROUP_BY contract: an aggregate column alongside plain
        // columns is invalid without a GROUP BY — group by every plain
        // selected column (the standard "count by X" reporting behavior)
        // instead of letting the query die at execution.
        if ($hasAggregatedSelect && empty($report->getGroupBy()) && $nonAggregatedSelectExprs !== []) {
            foreach ($nonAggregatedSelectExprs as $expr) {
                $qb->addGroupBy($expr);
            }
        }

        if (empty($selectParts)) {
            return [
                'success' => false,
                'error' => 'Report defines no valid columns',
                'data' => [],
                'meta' => ['warnings' => $warnings],
            ];
        }
        
        $qb->select(implode(', ', $selectParts));
        
        // Apply stored filters
        $this->applyFilters($qb, $report->getFilters(), $dataSourceKey);

        // Apply runtime filters
        if (!empty($runtimeFilters)) {
            $this->applyFilters($qb, $runtimeFilters, $dataSourceKey);
        }
        
        // Apply date range
        $this->applyDateRange($qb, $report);
        
        // Apply GROUP BY
        $groupBy = $report->getGroupBy();
        if (!empty($groupBy)) {
            foreach ($groupBy as $groupField) {
                $groupField = is_scalar($groupField) ? (string) $groupField : '';
                $expr = $this->resolveFieldExpression($qb, $groupField, $dataSourceKey, $joinsMade);
                if ($expr === null) {
                    $warnings[] = "Skipped group-by field '{$groupField}': not valid for data source '{$dataSourceKey}'";
                    continue;
                }
                $qb->addGroupBy($expr);
            }
        }
        
        // Apply ORDER BY
        $orderBy = $report->getOrderBy();
        if (!empty($orderBy)) {
            foreach ($orderBy as $order) {
                $fieldRaw = is_array($order) ? ($order['field'] ?? '') : $order;
                $field = is_scalar($fieldRaw) ? (string) $fieldRaw : '';
                // Direction is interpolated into DQL — only ASC|DESC survive,
                // case-normalized.
                $directionRaw = is_array($order) ? ($order['direction'] ?? 'ASC') : 'ASC';
                $direction = strtoupper(is_scalar($directionRaw) ? (string) $directionRaw : 'ASC');
                if (!in_array($direction, ['ASC', 'DESC'], true)) {
                    $direction = 'ASC';
                }

                // ONLY_FULL_GROUP_BY: with aggregate columns and implicit
                // grouping, ordering by a non-grouped column is invalid DQL —
                // restrict to the grouped (plain selected) fields.
                if ($hasAggregatedSelect && empty($report->getGroupBy()) && $nonAggregatedSelectExprs !== []
                    && !isset($nonAggregatedSelectFields[$field])) {
                    $warnings[] = "Skipped order-by field '{$field}': not part of the implicit GROUP BY";
                    continue;
                }

                $expr = $this->resolveFieldExpression($qb, $field, $dataSourceKey, $joinsMade);
                if ($expr === null) {
                    $warnings[] = "Skipped order-by field '{$field}': not valid for data source '{$dataSourceKey}'";
                    continue;
                }
                $qb->addOrderBy($expr, $direction);
            }
        } elseif (empty($groupBy)) {
            // Deterministic default order — but NOT alongside GROUP BY:
            // ordering by a non-grouped column breaks ONLY_FULL_GROUP_BY.
            $qb->orderBy('e.id', 'DESC');
        }
        
        // Apply limit
        if ($report->getRecordLimit()) {
            $qb->setMaxResults($report->getRecordLimit());
        }
        
        try {
            /** @var array<int, array<string, mixed>> $results */
            $results = $qb->getQuery()->getArrayResult();
            
            // Update run statistics
            $report->incrementRunCount();
            $this->em->flush();
            
            return [
                'success' => true,
                'data' => $results,
                'meta' => [
                    'totalRows' => count($results),
                    'columns' => $columns,
                    'executedAt' => new DateTimeImmutable(),
                    'reportType' => $report->getReportType(),
                    'warnings' => $warnings,
                ],
            ];
        } catch (\Throwable $e) {
            // Never leak raw exception details to the caller — log the detail
            // and return a generic message.
            $this->logger?->error('Report execution failed', [
                'report_id' => $report->getId(),
                'data_source' => $report->getDataSource(),
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error' => 'Report execution failed. Please review the report definition.',
                'data' => [],
                'meta' => ['warnings' => $warnings],
            ];
        }
    }

    /**
     * Whitelist check: is $field a declared source field for $dataSource?
     */
    /**
     * Idempotently join a relation alias for the current data source, so
     * filters/groups on relation fields work regardless of which columns
     * are selected.
     */
    private function ensureJoin(QueryBuilder $qb, string $dataSource, string $relation): void
    {
        $allowed = [
            'company' => ['contact', 'rfq', 'quote', 'lead'],
            'assignedTo' => ['task'],
        ];
        $rootAllowed = $allowed[$relation] ?? [];
        if (!in_array($dataSource, $rootAllowed, true)) {
            return; // relation not valid for this source — field whitelist already rejected it
        }

        $joinsByAlias = $qb->getDQLParts()['join'] ?? [];
        foreach (is_array($joinsByAlias) ? $joinsByAlias : [] as $joins) {
            foreach (is_array($joins) ? $joins : [] as $join) {
                if ($join instanceof \Doctrine\ORM\Query\Expr\Join
                    && str_ends_with($join->getAlias(), $relation)) {
                    return; // already joined
                }
            }
        }

        $qb->leftJoin("e.{$relation}", $relation);
    }

    private function isValidField(string $field, string $dataSource): bool
    {
        return isset(self::SOURCE_FIELDS[$dataSource][$field]);
    }

    /**
     * THE single field resolver. Every DQL property path in this service —
     * SELECT, FILTER, GROUP BY, ORDER BY and DATE RANGE — goes through this
     * method: whitelist validation, idempotent relation join, expression
     * build. No other code may construct "e.field" or "relation.field" paths.
     *
     * @param array<int, string> $joinsMade
     * @return string|null the DQL expression, or null when the field is not
     *                     valid for the data source
     */
    private function resolveFieldExpression(QueryBuilder $qb, string $field, string $dataSource, array &$joinsMade): ?string
    {
        if (!$this->isValidField($field, $dataSource)) {
            return null;
        }

        if (str_contains($field, '.')) {
            [$relation, $relField] = explode('.', $field, 2);
            $this->ensureJoin($qb, $dataSource, $relation);
            if (!in_array($relation, $joinsMade, true)) {
                $joinsMade[] = $relation;
            }

            return "{$relation}.{$relField}";
        }

        return "e.{$field}";
    }

    /**
     * Can $field be used as a DATE-RANGE anchor? Must be whitelisted AND of
     * a date/datetime schema type — a string field compared against dates
     * would silently filter nothing.
     */
    public function isValidDateField(string $field, string $dataSource): bool
    {
        $fieldDef = self::SOURCE_FIELDS[$dataSource][$field] ?? null;

        return $fieldDef !== null
            && !str_contains($field, '.')
            && in_array($fieldDef['type'], ['datetime', 'date'], true);
    }

    /**
     * Canonical data-source check — the ONLY source of truth for which
     * sources exist (controllers must not keep a second list).
     */
    public function supportsDataSource(string $dataSource): bool
    {
        return isset(self::ENTITY_MAP[$dataSource]) && class_exists(self::ENTITY_MAP[$dataSource]);
    }
    
    /**
     * Client aliases become DQL result identifiers — only plain identifier
     * shapes are accepted; everything else falls back to the generated one.
     */
    /** DQL/SQL keywords that must never be used as result aliases. */
    private const RESERVED_ALIASES = [
        'count', 'sum', 'avg', 'min', 'max', 'select', 'where', 'from', 'as',
        'order', 'group', 'by', 'and', 'or', 'not', 'in', 'is', 'null', 'between',
        'like', 'join', 'left', 'inner', 'distinct', 'asc', 'desc', 'having',
        'case', 'when', 'then', 'else', 'end', 'update', 'delete', 'insert',
    ];

    /**
     * Weak-mode string coercion for LIKE filter values: scalars stringify as
     * PHP would; arrays/objects (previously "Array"/TypeError) become ''.
     */
    private static function filterValueToString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function sanitizeAlias(mixed $alias): ?string
    {
        if (!is_string($alias) || $alias === '') {
            return null;
        }

        $normalized = strtolower($alias);

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)
            && !in_array($normalized, self::RESERVED_ALIASES, true)
            ? $alias
            : null;
    }

    private function defaultAlias(string $field, ?string $aggregation): string
    {
        $base = str_replace('.', '_', $field);

        return $aggregation !== null ? strtolower((string) $aggregation) . '_' . $base : $base;
    }

    /**
     * Apply filters to query
     *
     * @param array<array-key, mixed> $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters, string $dataSource): void
    {
        $paramIndex = 0;
        
        foreach ($filters as $filter) {
            if (!is_array($filter) || !isset($filter['field'])) {
                continue;
            }
            
            $field = $filter['field'];
            $operator = $filter['operator'] ?? 'equals';
            $value = $filter['value'] ?? null;
            
            // WHITELIST (fix for filter bypass): filter fields resolve through
            // the exact same centralized resolver as SELECT columns — a
            // client-supplied identifier is never interpolated into DQL.
            // Unknown field: skipped (consistent with the rest of the
            // builder), never turned into DQL.
            $joinsMade = [];
            $fieldRaw = $filter['field'];
            $fieldExpr = $this->resolveFieldExpression($qb, is_scalar($fieldRaw) ? (string) $fieldRaw : '', $dataSource, $joinsMade);
            if ($fieldExpr === null) {
                continue;
            }
            
            $paramName = "p{$paramIndex}";
            $paramIndex++;
            
            switch ($operator) {
                case 'equals':
                    $qb->andWhere("{$fieldExpr} = :{$paramName}")
                       ->setParameter($paramName, $value);
                    break;
                    
                case 'not_equals':
                    $qb->andWhere("{$fieldExpr} != :{$paramName}")
                       ->setParameter($paramName, $value);
                    break;
                    
                case 'contains':
                    $qb->andWhere("{$fieldExpr} LIKE :{$paramName}")
                       ->setParameter($paramName, '%' . self::filterValueToString($value) . '%');
                    break;

                case 'not_contains':
                    $qb->andWhere("{$fieldExpr} NOT LIKE :{$paramName}")
                       ->setParameter($paramName, '%' . self::filterValueToString($value) . '%');
                    break;

                case 'starts_with':
                    $qb->andWhere("{$fieldExpr} LIKE :{$paramName}")
                       ->setParameter($paramName, self::filterValueToString($value) . '%');
                    break;

                case 'ends_with':
                    $qb->andWhere("{$fieldExpr} LIKE :{$paramName}")
                       ->setParameter($paramName, '%' . self::filterValueToString($value));
                    break;
                    
                case 'greater_than':
                    $qb->andWhere("{$fieldExpr} > :{$paramName}")
                       ->setParameter($paramName, $value);
                    break;
                    
                case 'less_than':
                    $qb->andWhere("{$fieldExpr} < :{$paramName}")
                       ->setParameter($paramName, $value);
                    break;
                    
                case 'greater_or_equal':
                    $qb->andWhere("{$fieldExpr} >= :{$paramName}")
                       ->setParameter($paramName, $value);
                    break;
                    
                case 'less_or_equal':
                    $qb->andWhere("{$fieldExpr} <= :{$paramName}")
                       ->setParameter($paramName, $value);
                    break;
                    
                case 'is_null':
                    $qb->andWhere("{$fieldExpr} IS NULL");
                    break;
                    
                case 'is_not_null':
                    $qb->andWhere("{$fieldExpr} IS NOT NULL");
                    break;
                    
                case 'in':
                    if (is_array($value)) {
                        $qb->andWhere("{$fieldExpr} IN (:{$paramName})")
                           ->setParameter($paramName, $value);
                    }
                    break;
                    
                case 'not_in':
                    if (is_array($value)) {
                        $qb->andWhere("{$fieldExpr} NOT IN (:{$paramName})")
                           ->setParameter($paramName, $value);
                    }
                    break;
                    
                case 'between':
                    if (is_array($value) && count($value) >= 2) {
                        $qb->andWhere("{$fieldExpr} BETWEEN :{$paramName}_start AND :{$paramName}_end")
                           ->setParameter("{$paramName}_start", $value[0])
                           ->setParameter("{$paramName}_end", $value[1]);
                    }
                    break;
            }
        }
    }
    
    /**
     * Apply date range preset or custom dates
     */
    private function applyDateRange(QueryBuilder $qb, ReportDefinition $report): void
    {
        $dateField = $report->getDateField();
        if (!$dateField) {
            return;
        }
        
        $preset = $report->getDateRangePreset();
        if (!$preset) {
            return;
        }
        
        // DATE RANGE goes through the SAME centralized resolver as every
        // other DQL path (previously it interpolated the stored dateField
        // unvalidated — another identifier-interpolation route), and the
        // field must actually BE a date/datetime field.
        $dataSourceKey = $report->getDataSource() ?? '';
        if (!$this->isValidDateField($dateField, $dataSourceKey)) {
            return;
        }

        $joinsMade = [];
        $fieldExpr = $this->resolveFieldExpression($qb, $dateField, $dataSourceKey, $joinsMade);
        if ($fieldExpr === null) {
            return;
        }
        
        $now = new DateTimeImmutable();
        [$startDate, $endDate] = $this->getDateRangeFromPreset($preset, $now, $report);
        
        if ($startDate) {
            $qb->andWhere("{$fieldExpr} >= :dateStart")
               ->setParameter('dateStart', $startDate);
        }
        
        if ($endDate) {
            $qb->andWhere("{$fieldExpr} <= :dateEnd")
               ->setParameter('dateEnd', $endDate);
        }
    }
    
    /**
     * Get start and end dates from preset
     *
     * @return array{0: \DateTimeImmutable|null, 1: \DateTimeImmutable|null}
     */
    private function getDateRangeFromPreset(string $preset, DateTimeImmutable $now, ReportDefinition $report): array
    {
        return match($preset) {
            ReportDefinition::RANGE_TODAY => [
                $now->setTime(0, 0, 0),
                $now->setTime(23, 59, 59),
            ],
            ReportDefinition::RANGE_YESTERDAY => [
                $now->modify('-1 day')->setTime(0, 0, 0),
                $now->modify('-1 day')->setTime(23, 59, 59),
            ],
            ReportDefinition::RANGE_THIS_WEEK => [
                $now->modify('monday this week')->setTime(0, 0, 0),
                $now->modify('sunday this week')->setTime(23, 59, 59),
            ],
            ReportDefinition::RANGE_LAST_WEEK => [
                $now->modify('monday last week')->setTime(0, 0, 0),
                $now->modify('sunday last week')->setTime(23, 59, 59),
            ],
            ReportDefinition::RANGE_THIS_MONTH => [
                $now->modify('first day of this month')->setTime(0, 0, 0),
                $now->modify('last day of this month')->setTime(23, 59, 59),
            ],
            ReportDefinition::RANGE_LAST_MONTH => [
                $now->modify('first day of last month')->setTime(0, 0, 0),
                $now->modify('last day of last month')->setTime(23, 59, 59),
            ],
            ReportDefinition::RANGE_THIS_QUARTER => $this->getQuarterDates($now, 0),
            ReportDefinition::RANGE_LAST_QUARTER => $this->getQuarterDates($now, -1),
            ReportDefinition::RANGE_THIS_YEAR => [
                $now->modify('first day of January this year')->setTime(0, 0, 0),
                $now->modify('last day of December this year')->setTime(23, 59, 59),
            ],
            ReportDefinition::RANGE_LAST_YEAR => [
                $now->modify('first day of January last year')->setTime(0, 0, 0),
                $now->modify('last day of December last year')->setTime(23, 59, 59),
            ],
            ReportDefinition::RANGE_CUSTOM => [
                $report->getCustomDateStart(),
                $report->getCustomDateEnd(),
            ],
            default => [null, null],
        };
    }
    
    /**
     * Get quarter date range
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function getQuarterDates(DateTimeImmutable $now, int $offset): array
    {
        $month = (int) $now->format('n');
        $year = (int) $now->format('Y');

        $quarter = (int) ceil($month / 3) + $offset;
        
        if ($quarter < 1) {
            $quarter = 4;
            $year--;
        } elseif ($quarter > 4) {
            $quarter = 1;
            $year++;
        }
        
        $startMonth = ($quarter - 1) * 3 + 1;
        $endMonth = $startMonth + 2;
        
        return [
            new DateTimeImmutable("{$year}-{$startMonth}-01 00:00:00"),
            new DateTimeImmutable("{$year}-{$endMonth}-" . cal_days_in_month(CAL_GREGORIAN, $endMonth, $year) . " 23:59:59"),
        ];
    }
    
    /**
     * Format data for chart display
     *
     * @param list<array<string, mixed>> $results
     * @return array{labels: list<string>, datasets: list<array{label: mixed, data: list<float>, backgroundColor: list<string>, borderColor: list<string>, borderWidth: int}>}
     */
    public function formatForChart(array $results, ReportDefinition $report): array
    {
        $chartConfig = $report->getChartConfig() ?? [];
        $labelField = $chartConfig['labelField'] ?? null;
        $valueField = $chartConfig['valueField'] ?? null;

        if (!$labelField || !$valueField) {
            // Try to auto-detect from columns
            $columns = $report->getColumns();
            if (isset($columns[0], $columns[1])) {
                $labelField = is_array($columns[0]) ? ($columns[0]['field'] ?? null) : $columns[0];
                $valueField = is_array($columns[1]) ? ($columns[1]['field'] ?? null) : $columns[1];
            }
        }

        $labels = [];
        $values = [];

        $labelKey = str_replace('.', '_', is_string($labelField) ? $labelField : 'label');
        $valueKey = str_replace('.', '_', is_string($valueField) ? $valueField : 'value');

        foreach ($results as $row) {
            $labelValue = $row[$labelKey] ?? 'Unknown';
            $labels[] = is_scalar($labelValue) ? (string) $labelValue : 'Unknown';
            $rawValue = $row[$valueKey] ?? 0;
            $values[] = is_numeric($rawValue) ? (float) $rawValue : 0.0;
        }
        
        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => $chartConfig['datasetLabel'] ?? 'Data',
                    'data' => $values,
                    'backgroundColor' => $this->generateColors(count($values)),
                    'borderColor' => $this->generateColors(count($values), true),
                    'borderWidth' => 1,
                ],
            ],
        ];
    }
    
    /**
     * Generate chart colors
     *
     * @return list<string>
     */
    private function generateColors(int $count, bool $border = false): array
    {
        $baseColors = [
            'rgba(54, 162, 235, %s)',   // Blue
            'rgba(255, 99, 132, %s)',   // Red
            'rgba(75, 192, 192, %s)',   // Teal
            'rgba(255, 206, 86, %s)',   // Yellow
            'rgba(153, 102, 255, %s)',  // Purple
            'rgba(255, 159, 64, %s)',   // Orange
            'rgba(199, 199, 199, %s)',  // Gray
            'rgba(83, 102, 255, %s)',   // Indigo
            'rgba(255, 99, 255, %s)',   // Pink
            'rgba(99, 255, 132, %s)',   // Green
        ];
        
        $opacity = $border ? '1' : '0.7';
        $colors = [];
        
        for ($i = 0; $i < $count; $i++) {
            $colorTemplate = $baseColors[$i % count($baseColors)];
            $colors[] = sprintf($colorTemplate, $opacity);
        }
        
        return $colors;
    }
    
    /**
     * Export report to CSV
     *
     * @param list<array<string, mixed>> $results
     */
    public function exportToCsv(array $results, ReportDefinition $report): string
    {
        if (empty($results)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            return '';
        }

        // Headers
        $headers = array_keys($results[0]);
        fputcsv($output, $headers, ',', '"', '\\');

        // Data
        foreach ($results as $row) {
            $rowData = [];
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d H:i:s');
                } elseif (is_bool($value)) {
                    $value = $value ? 'Yes' : 'No';
                } elseif (is_array($value)) {
                    $value = json_encode($value) ?: '[]';
                } elseif (!is_scalar($value)) {
                    $value = ''; // objects/previously-crashing payloads export as empty cells
                }
                // OWASP formula-injection guard — the SHARED sanitizer
                // (same one CsvExportService uses), not a third variant.
                $sanitized = \App\Service\CsvExportService::sanitizeCsvCell($value);
                $rowData[] = is_scalar($sanitized) || $sanitized === null ? $sanitized : '';
            }
            fputcsv($output, $rowData, ',', '"', '\\');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv !== false ? $csv : '';
    }
    
    /**
     * Get filter operators for field type
     *
     * @return array<string, string>
     */
    public function getOperatorsForFieldType(string $fieldType): array
    {
        $allOperators = [
            'equals' => 'Equals',
            'not_equals' => 'Not Equals',
            'contains' => 'Contains',
            'not_contains' => 'Not Contains',
            'starts_with' => 'Starts With',
            'ends_with' => 'Ends With',
            'greater_than' => 'Greater Than',
            'less_than' => 'Less Than',
            'greater_or_equal' => 'Greater or Equal',
            'less_or_equal' => 'Less or Equal',
            'is_null' => 'Is Empty',
            'is_not_null' => 'Is Not Empty',
            'in' => 'In List',
            'not_in' => 'Not In List',
            'between' => 'Between',
        ];
        
        return match($fieldType) {
            'string' => array_intersect_key($allOperators, array_flip([
                'equals', 'not_equals', 'contains', 'not_contains', 
                'starts_with', 'ends_with', 'is_null', 'is_not_null', 'in'
            ])),
            'integer', 'decimal' => array_intersect_key($allOperators, array_flip([
                'equals', 'not_equals', 'greater_than', 'less_than',
                'greater_or_equal', 'less_or_equal', 'between', 'is_null', 'is_not_null'
            ])),
            'datetime', 'date' => array_intersect_key($allOperators, array_flip([
                'equals', 'greater_than', 'less_than', 'greater_or_equal',
                'less_or_equal', 'between', 'is_null', 'is_not_null'
            ])),
            'boolean' => array_intersect_key($allOperators, array_flip([
                'equals', 'is_null', 'is_not_null'
            ])),
            default => $allOperators,
        };
    }
    
    /**
     * Get suggested reports for a data source
     *
     * @return list<array<string, mixed>>
     */
    public function getSuggestedReports(string $dataSource): array
    {
        // Every suggested field MUST exist in SOURCE_FIELDS for its source —
        // suggestions that the engine rejects are worse than no suggestions.
        // (Round-8 audit: these previously used industry/status/source/
        // estimatedValue — fields the entities never had.)
        $suggestions = [
            'company' => [
                [
                    'name' => 'Companies by Sector',
                    'type' => ReportDefinition::TYPE_CHART_PIE,
                    'columns' => [
                        ['field' => 'sector', 'aggregation' => null],
                        ['field' => 'id', 'aggregation' => 'count', 'alias' => 'total'],
                    ],
                    'groupBy' => ['sector'],
                ],
                [
                    'name' => 'Companies by Status',
                    'type' => ReportDefinition::TYPE_CHART_BAR,
                    'columns' => [
                        ['field' => 'companyStatus', 'aggregation' => null],
                        ['field' => 'id', 'aggregation' => 'count', 'alias' => 'total'],
                    ],
                    'groupBy' => ['companyStatus'],
                ],
                [
                    'name' => 'New Companies Over Time',
                    'type' => ReportDefinition::TYPE_CHART_LINE,
                    'dateField' => 'createdAt',
                ],
            ],
            'contact' => [
                [
                    'name' => 'Contacts by Company',
                    'type' => ReportDefinition::TYPE_TABLE,
                    'columns' => [
                        ['field' => 'firstName', 'aggregation' => null],
                        ['field' => 'lastName', 'aggregation' => null],
                        ['field' => 'company.name', 'aggregation' => null],
                        ['field' => 'email', 'aggregation' => null],
                    ],
                ],
            ],
            'lead' => [
                [
                    'name' => 'Leads by Review Status',
                    'type' => ReportDefinition::TYPE_CHART_PIE,
                    'columns' => [
                        ['field' => 'reviewStatus', 'aggregation' => null],
                        ['field' => 'id', 'aggregation' => 'count', 'alias' => 'total'],
                    ],
                    'groupBy' => ['reviewStatus'],
                ],
                [
                    'name' => 'Leads by Region',
                    'type' => ReportDefinition::TYPE_CHART_BAR,
                    'columns' => [
                        ['field' => 'regionTag', 'aggregation' => null],
                        ['field' => 'id', 'aggregation' => 'count', 'alias' => 'total'],
                    ],
                    'groupBy' => ['regionTag'],
                ],
                [
                    'name' => 'Average Lead Score by Region',
                    'type' => ReportDefinition::TYPE_CHART_BAR,
                    'columns' => [
                        ['field' => 'regionTag', 'aggregation' => null],
                        ['field' => 'leadScore', 'aggregation' => 'avg', 'alias' => 'avg_score'],
                    ],
                    'groupBy' => ['regionTag'],
                ],
            ],
            'rfq' => [
                [
                    'name' => 'RFQs by Status',
                    'type' => ReportDefinition::TYPE_CHART_BAR,
                    'columns' => [
                        ['field' => 'status', 'aggregation' => null],
                        ['field' => 'id', 'aggregation' => 'sum', 'alias' => 'total'],
                    ],
                    'groupBy' => ['status'],
                ],
                [
                    'name' => 'RFQ Value by Currency',
                    'type' => ReportDefinition::TYPE_TABLE,
                    'columns' => [
                        ['field' => 'rfqNumber', 'aggregation' => null],
                        ['field' => 'estimatedValue', 'aggregation' => null],
                        ['field' => 'currency', 'aggregation' => null],
                    ],
                ],
            ],
            'quote' => [
                [
                    'name' => 'Quotes by Status',
                    'type' => ReportDefinition::TYPE_CHART_DOUGHNUT,
                    'columns' => [
                        ['field' => 'status', 'aggregation' => null],
                        ['field' => 'id', 'aggregation' => 'count', 'alias' => 'total'],
                    ],
                    'groupBy' => ['status'],
                ],
                [
                    // A SUM alongside a raw datetime column is invalid DQL
                    // (ONLY_FULL_GROUP_BY) — over-time value is a row listing.
                    'name' => 'Quote Value Over Time',
                    'type' => ReportDefinition::TYPE_TABLE,
                    'columns' => [
                        ['field' => 'quoteNumber', 'aggregation' => null],
                        ['field' => 'totalCost', 'aggregation' => null],
                        ['field' => 'currency', 'aggregation' => null],
                        ['field' => 'createdAt', 'aggregation' => null],
                    ],
                    'dateField' => 'createdAt',
                ],
            ],
            'calendar' => [
                [
                    'name' => 'Events by Type',
                    'type' => ReportDefinition::TYPE_CHART_PIE,
                    'columns' => [
                        ['field' => 'eventType', 'aggregation' => null],
                        ['field' => 'id', 'aggregation' => 'count', 'alias' => 'total'],
                    ],
                    'groupBy' => ['eventType'],
                ],
            ],
            'email_campaign' => [
                [
                    'name' => 'Campaigns by Status',
                    'type' => ReportDefinition::TYPE_CHART_BAR,
                    'columns' => [
                        ['field' => 'status', 'aggregation' => null],
                        ['field' => 'id', 'aggregation' => 'count', 'alias' => 'total'],
                    ],
                    'groupBy' => ['status'],
                ],
            ],
            'email_send' => [
                [
                    'name' => 'Sends by Status',
                    'type' => ReportDefinition::TYPE_CHART_BAR,
                    'columns' => [
                        ['field' => 'status', 'aggregation' => null],
                        ['field' => 'id', 'aggregation' => 'count', 'alias' => 'total'],
                    ],
                    'groupBy' => ['status'],
                ],
            ],
            'task' => [
                [
                    'name' => 'Tasks by Status',
                    'type' => ReportDefinition::TYPE_CHART_DOUGHNUT,
                    'columns' => [
                        ['field' => 'status', 'aggregation' => null],
                        ['field' => 'id', 'aggregation' => 'count', 'alias' => 'total'],
                    ],
                    'groupBy' => ['status'],
                ],
                [
                    'name' => 'Tasks by Priority',
                    'type' => ReportDefinition::TYPE_CHART_BAR,
                    'columns' => [
                        ['field' => 'priority', 'aggregation' => null],
                        ['field' => 'id', 'aggregation' => 'count', 'alias' => 'total'],
                    ],
                    'groupBy' => ['priority'],
                ],
            ],
        ];
        
        return $suggestions[$dataSource] ?? [];
    }
}
