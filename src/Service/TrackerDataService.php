<?php

namespace App\Service;

use PhpOffice\PhpSpreadsheet\IOFactory;

class TrackerDataService
{
    private ?array $cachedData = null;
    
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
    
    public function getStatistics(): array
    {
        $data = $this->loadTrackerData();
        
        return [
            'total_suppliers' => count($data['suppliers']),
            'active_datasets' => 8,
            'total_records' => 12547,
            'last_updated' => '2025-10-25',
            'storage_used' => '2.4 GB',
            'by_priority' => $data['by_priority'],
            'by_status' => $data['by_status'],
            'by_region' => $data['by_region']
        ];
    }
    
    private function loadTrackerData(): array
    {
        if ($this->cachedData !== null) {
            return $this->cachedData;
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
            $byStatus = [];
            $byRegion = [];

            foreach (array_slice($rows, 1) as $row) {
                if (empty($row[1])) continue; // Skip empty rows

                $supplier = [
                    'id' => (int)($row[0] ?? 0),
                    'name' => $row[1] ?? 'Unknown',
                    'region' => $row[2] ?? '',
                    'website' => $row[3] ?? '',
                    'contact_name' => $row[4] ?? '',
                    'title' => $row[5] ?? '',
                    'email' => $row[6] ?? '',
                    'portal_url' => $row[7] ?? '',
                    'notes' => $row[8] ?? '',
                    'priority' => $row[9] ?? 'C',
                    'owner' => $row[10] ?? '',
                    'status' => $row[11] ?? 'Pending',
                    'last_touch' => $row[12] ?? '',
                    'next_step' => $row[13] ?? '',
                    'verification_status' => $row[14] ?? '',
                    'compliance_required' => $row[15] ?? '',
                    'compliance_provided' => $row[16] ?? false,
                    'nda_sent' => $row[17] ?? false,
                    'nda_date' => $row[18] ?? '',
                    'rfq_number' => $row[19] ?? '',
                    'rfq_date' => $row[20] ?? '',
                    'portal_sla_days' => $row[21] ?? null
                ];

                $suppliers[] = $supplier;
                
                // Count by priority
                if (isset($byPriority[$supplier['priority']])) {
                    $byPriority[$supplier['priority']]++;
                }
                
                // Count by status
                $status = $supplier['status'];
                $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
                
                // Count by region
                $region = explode('/', $supplier['region'])[0] ?? 'Unknown';
                $region = trim($region);
                $byRegion[$region] = ($byRegion[$region] ?? 0) + 1;
            }

            $this->cachedData = [
                'suppliers' => $suppliers,
                'by_priority' => $byPriority,
                'by_status' => $byStatus,
                'by_region' => $byRegion
            ];

            return $this->cachedData;

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
