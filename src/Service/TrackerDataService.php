<?php

declare(strict_types=1);

namespace App\Service;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Tracker.xlsx data provider (cached static parse of the supplier sheet).
 *
 * @phpstan-type TrackerSupplier array{id: int, name: mixed, region: mixed, website: mixed, contact_name: mixed, title: mixed, email: mixed, portal_url: mixed, notes: mixed, priority: mixed, owner: mixed, status: mixed, last_touch: mixed, next_step: mixed, verification_status: mixed, compliance_required: mixed, nda_sent: mixed, nda_date: mixed, rfq_number: mixed, rfq_date: mixed, portal_sla_days: mixed}
 * @phpstan-type TrackerData array{suppliers: list<TrackerSupplier>, by_priority: array<string, int>, by_status: array<string, int>, by_region: array<string, int>}
 */
class TrackerDataService
{
    /** @var TrackerData|null */
    private static ?array $cachedData = null;

    /**
     * @return list<TrackerSupplier>
     */
    public function getSuppliers(?int $limit = null, ?int $offset = 0): array
    {
        $data = $this->loadTrackerData();
        $suppliers = $data['suppliers'];
        
        if ($offset) {
            $suppliers = array_slice($suppliers, $offset);
        }
        
        if ($limit) {
            $suppliers = array_slice($suppliers, 0, $limit);
        }
        
        return $suppliers;
    }
    
    /**
     * @return list<array{id: int, name: string, type: string, version: string, records: int, updated_at: string, status: string}>
     */
    public function getDatasets(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Automotive Suppliers Network',
                'type' => 'Supplier Database',
                'version' => '2.3',
                'records' => 1250,
                'updated_at' => '2025-10-22',
                'status' => 'Active'
            ],
            [
                'id' => 2,
                'name' => 'Compliance Requirements Database',
                'type' => 'Compliance Data',
                'version' => '1.8',
                'records' => 450,
                'updated_at' => '2025-10-15',
                'status' => 'Active'
            ],
            [
                'id' => 3,
                'name' => 'Portal Integration Mappings',
                'type' => 'Technical Reference',
                'version' => '3.1',
                'records' => 85,
                'updated_at' => '2025-10-20',
                'status' => 'Active'
            ],
            [
                'id' => 4,
                'name' => 'RFQ Response Templates',
                'type' => 'Templates',
                'version' => '1.5',
                'records' => 32,
                'updated_at' => '2025-10-18',
                'status' => 'Active'
            ],
            [
                'id' => 5,
                'name' => 'Historical Quote Data',
                'type' => 'Analytics',
                'version' => '2.0',
                'records' => 2847,
                'updated_at' => '2025-10-25',
                'status' => 'Active'
            ],
        ];
    }
    
    /**
     * @return array{total_suppliers: int, active_datasets: int, total_records: int|float, last_updated: string, storage_used: string, by_priority: array<string, int>, by_status: array<string, int>, by_region: array<string, int>}
     */
    public function getStatistics(): array
    {
        $data = $this->loadTrackerData();
        $datasets = $this->getDatasets();
        
        $updatedAt = array_column($datasets, 'updated_at'); // non-empty in practice: literal dataset list above

        return [
            'total_suppliers' => count($data['suppliers']),
            'active_datasets' => count($datasets),
            'total_records' => array_sum(array_column($datasets, 'records')),
            'last_updated' => max($updatedAt ?: ['']),
            'storage_used' => '2.4 GB',
            'by_priority' => $data['by_priority'],
            'by_status' => $data['by_status'],
            'by_region' => $data['by_region']
        ];
    }
    
    /**
     * Cell value normalized to scalar|null (spreadsheet cells are never
     * arrays/objects); NULL when the cell is empty.
     */
    /**
     * @param array<int|string, mixed> $row
     */
    private static function cell(array $row, int $index): int|float|string|bool|null
    {
        $value = $row[$index] ?? null;

        return is_scalar($value) ? $value : null;
    }

    /**
     * @return TrackerData
     */
    private function loadTrackerData(): array
    {
        if (self::$cachedData !== null) {
            return self::$cachedData;
        }

        $trackerFile = dirname(__DIR__, 2) . '/Tracker.xlsx';
        
        if (!file_exists($trackerFile)) {
            return [
                'suppliers' => [],
                'by_priority' => [],
                'by_status' => [],
                'by_region' => []
            ];
        }

        try {
            $spreadsheet = IOFactory::load($trackerFile);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            $suppliers = [];
            $byPriority = ['A' => 0, 'B' => 0, 'C' => 0];
            /** @var array<string, int> $byStatus */
            $byStatus = [];
            /** @var array<string, int> $byRegion */
            $byRegion = [];

            /** @var array<int|string, mixed> $row */
            foreach (array_slice($rows, 1) as $row) {
                if (self::cell($row, 1) === null) continue; // Skip empty rows

                $supplier = [
                    'id' => (int) (self::cell($row, 0) ?? 0),
                    'name' => self::cell($row, 1), // non-null: guarded above
                    'region' => self::cell($row, 2) ?? '',
                    'website' => self::cell($row, 3) ?? '',
                    'contact_name' => self::cell($row, 4) ?? '',
                    'title' => self::cell($row, 5) ?? '',
                    'email' => self::cell($row, 6) ?? '',
                    'portal_url' => self::cell($row, 7) ?? '',
                    'notes' => self::cell($row, 8) ?? '',
                    'priority' => self::cell($row, 9) ?? 'C',
                    'owner' => self::cell($row, 10) ?? '',
                    'status' => self::cell($row, 11) ?? 'Pending',
                    'last_touch' => self::cell($row, 12) ?? '',
                    'next_step' => self::cell($row, 13) ?? '',
                    'verification_status' => self::cell($row, 14) ?? '',
                    'compliance_required' => self::cell($row, 15) ?? '',
                    'compliance_provided' => self::cell($row, 16) ?? false,
                    'nda_sent' => self::cell($row, 17) ?? false,
                    'nda_date' => self::cell($row, 18) ?? '',
                    'rfq_number' => self::cell($row, 19) ?? '',
                    'rfq_date' => self::cell($row, 20) ?? '',
                    'portal_sla_days' => self::cell($row, 21)
                ];

                $suppliers[] = $supplier;
                
                // Count by priority
                $priorityKey = (string) $supplier['priority'];
                if (isset($byPriority[$priorityKey])) {
                    $byPriority[$priorityKey]++;
                }

                // Count by status
                $status = (string) $supplier['status'];
                $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

                // Count by region
                $region = trim(explode('/', (string) $supplier['region'])[0]);
                $byRegion[$region] = ($byRegion[$region] ?? 0) + 1;
            }

            self::$cachedData = [
                'suppliers' => $suppliers,
                'by_priority' => $byPriority,
                'by_status' => $byStatus,
                'by_region' => $byRegion
            ];

            return self::$cachedData;

        } catch (\Exception $e) {
            return [
                'suppliers' => [],
                'by_priority' => [],
                'by_status' => [],
                'by_region' => []
            ];
        }
    }
}
