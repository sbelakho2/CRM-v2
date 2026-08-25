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
            'id' => ['label' => 'ID', 'type' => 'integer'],
            'name' => ['label' => 'Company Name', 'type' => 'string'],
            'website' => ['label' => 'Website', 'type' => 'string'],
            'phone' => ['label' => 'Phone', 'type' => 'string'],
            'email' => ['label' => 'Email', 'type' => 'string'],
            'industry' => ['label' => 'Industry', 'type' => 'string'],
            'size' => ['label' => 'Company Size', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'string'],
            'city' => ['label' => 'City', 'type' => 'string'],
            'state' => ['label' => 'State', 'type' => 'string'],
            'country' => ['label' => 'Country', 'type' => 'string'],
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
            'department' => ['label' => 'Department', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'string'],
            'isPrimary' => ['label' => 'Primary Contact', 'type' => 'boolean'],
            'createdAt' => ['label' => 'Created Date', 'type' => 'datetime'],
            'company.name' => ['label' => 'Company Name', 'type' => 'string', 'relation' => 'company'],
        ],
        'lead' => [
            'id' => ['label' => 'ID', 'type' => 'integer'],
            'companyName' => ['label' => 'Company Name', 'type' => 'string'],
            'contactName' => ['label' => 'Contact Name', 'type' => 'string'],
            'email' => ['label' => 'Email', 'type' => 'string'],
            'phone' => ['label' => 'Phone', 'type' => 'string'],
            'source' => ['label' => 'Lead Source', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'string'],
            'score' => ['label' => 'Lead Score', 'type' => 'integer'],
            'estimatedValue' => ['label' => 'Estimated Value', 'type' => 'decimal'],
            'createdAt' => ['label' => 'Created Date', 'type' => 'datetime'],
            'updatedAt' => ['label' => 'Updated Date', 'type' => 'datetime'],
            'convertedAt' => ['label' => 'Converted Date', 'type' => 'datetime'],
        ],
        'rfq' => [
            'id' => ['label' => 'ID', 'type' => 'integer'],
            'rfqNumber' => ['label' => 'RFQ Number', 'type' => 'string'],
            'title' => ['label' => 'Title', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'string'],
            'priority' => ['label' => 'Priority', 'type' => 'string'],
            'estimatedValue' => ['label' => 'Estimated Value', 'type' => 'decimal'],
            'receivedDate' => ['label' => 'Received Date', 'type' => 'datetime'],
            'dueDate' => ['label' => 'Due Date', 'type' => 'datetime'],
            'createdAt' => ['label' => 'Created Date', 'type' => 'datetime'],
            'company.name' => ['label' => 'Company Name', 'type' => 'string', 'relation' => 'company'],
        ],
        'quote' => [
            'id' => ['label' => 'ID', 'type' => 'integer'],
            'quoteNumber' => ['label' => 'Quote Number', 'type' => 'string'],
            'title' => ['label' => 'Title', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'string'],
            'totalAmount' => ['label' => 'Total Amount', 'type' => 'decimal'],
            'validUntil' => ['label' => 'Valid Until', 'type' => 'datetime'],
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
            'type' => ['label' => 'Event Type', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'string'],
            'startTime' => ['label' => 'Start Time', 'type' => 'datetime'],
            'endTime' => ['label' => 'End Time', 'type' => 'datetime'],
            'isAllDay' => ['label' => 'All Day', 'type' => 'boolean'],
            'createdAt' => ['label' => 'Created Date', 'type' => 'datetime'],
        ],
        'email_campaign' => [
            'id' => ['label' => 'ID', 'type' => 'integer'],
            'name' => ['label' => 'Campaign Name', 'type' => 'string'],
            'subject' => ['label' => 'Subject', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'string'],
            'sentCount' => ['label' => 'Sent Count', 'type' => 'integer'],
            'openCount' => ['label' => 'Open Count', 'type' => 'integer'],
            'clickCount' => ['label' => 'Click Count', 'type' => 'integer'],
            'createdAt' => ['label' => 'Created Date', 'type' => 'datetime'],
            'scheduledAt' => ['label' => 'Scheduled Date', 'type' => 'datetime'],
        ],
        'email_send' => [
            'id' => ['label' => 'ID', 'type' => 'integer'],
            'recipientEmail' => ['label' => 'Recipient Email', 'type' => 'string'],
            'status' => ['label' => 'Status', 'type' => 'string'],
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
        private ?LoggerInterface $logger = null
    ) {}
    
    /**
     * Get available fields for a data source
     */
    public function getFieldsForSource(string $dataSource): array
    {
        return self::SOURCE_FIELDS[$dataSource] ?? [];
    }
    
    /**
     * Get all data sources with their labels
     */
    public function getDataSources(): array
    {
        return ReportDefinition::getDataSources();
    }
    
    /**
     * Execute a report and return results
     */
    public function executeReport(ReportDefinition $report, array $runtimeFilters = []): array
    {
        $entityClass = self::ENTITY_MAP[$report->getDataSource()] ?? null;
        
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
        
        // Build SELECT based on columns
        $columns = $report->getColumns();
        if (empty($columns)) {
            $columns = ['id', 'createdAt'];
        }
        
        $selectParts = [];
        $joinsMade = [];
        $warnings = [];
        
        foreach ($columns as $column) {
            if (is_array($column)) {
                $field = $column['field'] ?? $column;
                $alias = $column['alias'] ?? str_replace('.', '_', $field);
                $aggregation = $column['aggregation'] ?? null;
            } else {
                $field = $column;
                $alias = str_replace('.', '_', $field);
                $aggregation = null;
            }
            
            // ── Defense in depth: field names come from ReportDefinition
            // (admin-created), but they are interpolated into raw DQL — reject
            // anything not whitelisted in SOURCE_FIELDS for this data source.
            if (!$this->isValidField($field, $report->getDataSource())) {
                $warnings[] = "Skipped column '{$field}': not a valid field for data source '{$report->getDataSource()}'";
                continue;
            }
            
            // ── Aggregation functions are whitelisted to a known set ──
            if ($aggregation !== null) {
                $aggregation = strtolower((string) $aggregation);
                if (!in_array($aggregation, ['count', 'sum', 'avg', 'min', 'max'], true)) {
                    $warnings[] = "Skipped invalid aggregation '{$aggregation}' on field '{$field}'";
                    continue;
                }
            }
            
            // Handle relation fields
            if (str_contains($field, '.')) {
                [$relation, $relField] = explode('.', $field, 2);
                if (!in_array($relation, $joinsMade)) {
                    $qb->leftJoin("e.{$relation}", $relation);
                    $joinsMade[] = $relation;
                }
                $fieldPath = "{$relation}.{$relField}";
            } else {
                $fieldPath = "e.{$field}";
            }
            
            if ($aggregation) {
                $selectParts[] = "{$aggregation}({$fieldPath}) as {$alias}";
            } else {
                $selectParts[] = "{$fieldPath} as {$alias}";
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
        $this->applyFilters($qb, $report->getFilters(), $report->getDataSource());
        
        // Apply runtime filters
        if (!empty($runtimeFilters)) {
            $this->applyFilters($qb, $runtimeFilters, $report->getDataSource());
        }
        
        // Apply date range
        $this->applyDateRange($qb, $report);
        
        // Apply GROUP BY
        $groupBy = $report->getGroupBy();
        if (!empty($groupBy)) {
            foreach ($groupBy as $groupField) {
                if (!$this->isValidField($groupField, $report->getDataSource())) {
                    $warnings[] = "Skipped group-by field '{$groupField}': not valid for data source '{$report->getDataSource()}'";
                    continue;
                }
                if (str_contains($groupField, '.')) {
                    [$relation, $relField] = explode('.', $groupField, 2);
                    if (!in_array($relation, $joinsMade)) {
                        $qb->leftJoin("e.{$relation}", $relation);
                        $joinsMade[] = $relation;
                    }
                    $qb->addGroupBy("{$relation}.{$relField}");
                } else {
                    $qb->addGroupBy("e.{$groupField}");
                }
            }
        }
        
        // Apply ORDER BY
        $orderBy = $report->getOrderBy();
        if (!empty($orderBy)) {
            foreach ($orderBy as $order) {
                $field = is_array($order) ? $order['field'] : $order;
                $direction = is_array($order) ? ($order['direction'] ?? 'ASC') : 'ASC';
                
                if (!$this->isValidField($field, $report->getDataSource())) {
                    $warnings[] = "Skipped order-by field '{$field}': not valid for data source '{$report->getDataSource()}'";
                    continue;
                }
                
                if (str_contains($field, '.')) {
                    [$relation, $relField] = explode('.', $field, 2);
                    $qb->addOrderBy("{$relation}.{$relField}", $direction);
                } else {
                    $qb->addOrderBy("e.{$field}", $direction);
                }
            }
        } else {
            $qb->orderBy('e.id', 'DESC');
        }
        
        // Apply limit
        if ($report->getRecordLimit()) {
            $qb->setMaxResults($report->getRecordLimit());
        }
        
        try {
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
    private function isValidField(string $field, string $dataSource): bool
    {
        return isset(self::SOURCE_FIELDS[$dataSource][$field]);
    }
    
    /**
     * Apply filters to query
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
            
            // Handle relation fields
            if (str_contains($field, '.')) {
                $fieldPath = str_replace('.', '_', $field);
                [$relation, $relField] = explode('.', $field, 2);
                $fieldExpr = "{$relation}.{$relField}";
            } else {
                $fieldPath = $field;
                $fieldExpr = "e.{$field}";
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
                       ->setParameter($paramName, "%{$value}%");
                    break;
                    
                case 'not_contains':
                    $qb->andWhere("{$fieldExpr} NOT LIKE :{$paramName}")
                       ->setParameter($paramName, "%{$value}%");
                    break;
                    
                case 'starts_with':
                    $qb->andWhere("{$fieldExpr} LIKE :{$paramName}")
                       ->setParameter($paramName, "{$value}%");
                    break;
                    
                case 'ends_with':
                    $qb->andWhere("{$fieldExpr} LIKE :{$paramName}")
                       ->setParameter($paramName, "%{$value}");
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
        
        // Handle relation fields
        if (str_contains($dateField, '.')) {
            [$relation, $relField] = explode('.', $dateField, 2);
            $fieldExpr = "{$relation}.{$relField}";
        } else {
            $fieldExpr = "e.{$dateField}";
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
     */
    private function getQuarterDates(DateTimeImmutable $now, int $offset): array
    {
        $month = (int) $now->format('n');
        $year = (int) $now->format('Y');
        
        $quarter = ceil($month / 3) + $offset;
        
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
     */
    public function formatForChart(array $results, ReportDefinition $report): array
    {
        $chartConfig = $report->getChartConfig() ?? [];
        $labelField = $chartConfig['labelField'] ?? null;
        $valueField = $chartConfig['valueField'] ?? null;
        
        if (!$labelField || !$valueField) {
            // Try to auto-detect from columns
            $columns = $report->getColumns();
            if (count($columns) >= 2) {
                $labelField = is_array($columns[0]) ? $columns[0]['field'] : $columns[0];
                $valueField = is_array($columns[1]) ? $columns[1]['field'] : $columns[1];
            }
        }
        
        $labels = [];
        $values = [];
        
        $labelKey = str_replace('.', '_', $labelField ?? 'label');
        $valueKey = str_replace('.', '_', $valueField ?? 'value');
        
        foreach ($results as $row) {
            $labels[] = $row[$labelKey] ?? 'Unknown';
            $values[] = (float) ($row[$valueKey] ?? 0);
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
     */
    public function exportToCsv(array $results, ReportDefinition $report): string
    {
        if (empty($results)) {
            return '';
        }
        
        $output = fopen('php://temp', 'r+');
        
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
                    $value = json_encode($value);
                }
                $rowData[] = $value;
            }
            fputcsv($output, $rowData, ',', '"', '\\');
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
    
    /**
     * Get filter operators for field type
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
     */
    public function getSuggestedReports(string $dataSource): array
    {
        $suggestions = [
            'company' => [
                [
                    'name' => 'Companies by Industry',
                    'type' => ReportDefinition::TYPE_CHART_PIE,
                    'columns' => [
                        ['field' => 'industry', 'aggregation' => null],
                        ['field' => 'id', 'aggregation' => 'count', 'alias' => 'count'],
                    ],
                    'groupBy' => ['industry'],
                ],
                [
                    'name' => 'Companies by Status',
                    'type' => ReportDefinition::TYPE_CHART_BAR,
                    'columns' => [
                        ['field' => 'status', 'aggregation' => null],
                        ['field' => 'id', 'aggregation' => 'count', 'alias' => 'count'],
                    ],
                    'groupBy' => ['status'],
                ],
                [
                    'name' => 'New Companies Over Time',
                    'type' => ReportDefinition::TYPE_CHART_LINE,
                    'dateField' => 'createdAt',
                ],
            ],
            'lead' => [
                [
                    'name' => 'Leads by Source',
                    'type' => ReportDefinition::TYPE_CHART_PIE,
                    'columns' => [
                        ['field' => 'source', 'aggregation' => null],
                        ['field' => 'id', 'aggregation' => 'count', 'alias' => 'count'],
                    ],
                    'groupBy' => ['source'],
                ],
                [
                    'name' => 'Lead Conversion Funnel',
                    'type' => ReportDefinition::TYPE_CHART_BAR,
                    'columns' => [
                        ['field' => 'status', 'aggregation' => null],
                        ['field' => 'id', 'aggregation' => 'count', 'alias' => 'count'],
                    ],
                    'groupBy' => ['status'],
                ],
                [
                    'name' => 'Lead Value by Source',
                    'type' => ReportDefinition::TYPE_CHART_BAR,
                    'columns' => [
                        ['field' => 'source', 'aggregation' => null],
                        ['field' => 'estimatedValue', 'aggregation' => 'sum', 'alias' => 'total_value'],
                    ],
                    'groupBy' => ['source'],
                ],
            ],
            'task' => [
                [
                    'name' => 'Tasks by Status',
                    'type' => ReportDefinition::TYPE_CHART_DOUGHNUT,
                    'columns' => [
                        ['field' => 'status', 'aggregation' => null],
                        ['field' => 'id', 'aggregation' => 'count', 'alias' => 'count'],
                    ],
                    'groupBy' => ['status'],
                ],
                [
                    'name' => 'Tasks by Priority',
                    'type' => ReportDefinition::TYPE_CHART_BAR,
                    'columns' => [
                        ['field' => 'priority', 'aggregation' => null],
                        ['field' => 'id', 'aggregation' => 'count', 'alias' => 'count'],
                    ],
                    'groupBy' => ['priority'],
                ],
            ],
        ];
        
        return $suggestions[$dataSource] ?? [];
    }
}
