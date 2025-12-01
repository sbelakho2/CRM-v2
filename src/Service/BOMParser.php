<?php

namespace App\Service;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Psr\Log\LoggerInterface;

/**
 * BOM Parser Service
 * 
 * Parses Bill of Materials files in various formats:
 * - CSV
 * - Excel (XLSX, XLS)
 * - Altium exports
 * - KiCad exports
 */
class BOMParser
{
    public function __construct(
        private LoggerInterface $logger
    ) {}

    /**
     * Parse BOM file and return standardized array
     * 
     * @param string $filePath Path to the BOM file
     * @param string|null $extension Optional file extension (useful for uploaded files without extension in temp path)
     * @return array Array of BOM lines with keys: designator, mpn, manufacturer, qty, description
     */
    public function parse(string $filePath, ?string $extension = null): array
    {
        // Use provided extension or detect from file path
        $extension = $extension ? strtolower($extension) : strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        return match($extension) {
            'csv' => $this->parseCSV($filePath),
            'xlsx', 'xls' => $this->parseExcel($filePath),
            default => throw new \RuntimeException("Unsupported file format: {$extension}")
        };
    }

    /**
     * Parse CSV BOM file
     */
    private function parseCSV(string $filePath): array
    {
        $lines = [];
        $handle = fopen($filePath, 'r');
        
        if (!$handle) {
            throw new \RuntimeException("Cannot open file: {$filePath}");
        }
        
        // Read header row
        $headers = fgetcsv($handle);
        
        if (!$headers) {
            fclose($handle);
            throw new \RuntimeException("Empty BOM file");
        }
        
        // Normalize headers
        $headerMap = $this->mapHeaders($headers);
        
        // Read data rows
        $lineNumber = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row))) {
                continue; // Skip empty rows
            }
            
            $lineNumber++;
            $line = $this->extractLine($row, $headerMap, $lineNumber);
            
            if ($line) {
                $lines[] = $line;
            }
        }
        
        fclose($handle);
        
        return $lines;
    }

    /**
     * Parse Excel BOM file
     */
    private function parseExcel(string $filePath): array
    {
        $lines = [];
        
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            
            $rows = $sheet->toArray();
            
            if (empty($rows)) {
                throw new \RuntimeException("Empty BOM file");
            }
            
            // First row is headers
            $headers = array_shift($rows);
            $headerMap = $this->mapHeaders($headers);
            
            $lineNumber = 0;
            foreach ($rows as $row) {
                if (empty(array_filter($row))) {
                    continue; // Skip empty rows
                }
                
                $lineNumber++;
                $line = $this->extractLine($row, $headerMap, $lineNumber);
                
                if ($line) {
                    $lines[] = $line;
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Excel parsing failed', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException("Failed to parse Excel file: " . $e->getMessage());
        }
        
        return $lines;
    }

    /**
     * Map various header names to standard fields
     */
    private function mapHeaders(array $headers): array
    {
        $map = [];
        
        foreach ($headers as $index => $header) {
            $normalized = strtolower(trim($header));
            $normalized = preg_replace('/[^a-z0-9]/', '', $normalized);
            
            // Map to standard field names
            if (in_array($normalized, ['designator', 'refdes', 'reference', 'ref', 'component'])) {
                $map['designator'] = $index;
            } elseif (in_array($normalized, ['mpn', 'partnumber', 'partno', 'pn', 'manufacturerpartnumber'])) {
                $map['mpn'] = $index;
            } elseif (in_array($normalized, ['manufacturer', 'mfr', 'mfg', 'brand'])) {
                $map['manufacturer'] = $index;
            } elseif (in_array($normalized, ['quantity', 'qty', 'count'])) {
                $map['qty'] = $index;
            } elseif (in_array($normalized, ['description', 'desc', 'comment', 'value'])) {
                $map['description'] = $index;
            } elseif (in_array($normalized, ['package', 'footprint', 'pkg'])) {
                $map['package'] = $index;
            } elseif (in_array($normalized, ['supplier', 'distributor'])) {
                $map['supplier'] = $index;
            } elseif (in_array($normalized, ['supplierpartnumber', 'spn', 'supplierpn'])) {
                $map['supplier_pn'] = $index;
            }
        }
        
        return $map;
    }

    /**
     * Extract BOM line from row data
     */
    private function extractLine(array $row, array $headerMap, int $lineNumber): ?array
    {
        // Must have either MPN or description
        $mpn = isset($headerMap['mpn']) ? trim($row[$headerMap['mpn']] ?? '') : '';
        $description = isset($headerMap['description']) ? trim($row[$headerMap['description']] ?? '') : '';
        
        if (empty($mpn) && empty($description)) {
            return null;
        }
        
        $designator = isset($headerMap['designator']) ? trim($row[$headerMap['designator']] ?? '') : '';
        $manufacturer = isset($headerMap['manufacturer']) ? trim($row[$headerMap['manufacturer']] ?? '') : '';
        $qty = isset($headerMap['qty']) ? (int) ($row[$headerMap['qty']] ?? 1) : 1;
        $package = isset($headerMap['package']) ? trim($row[$headerMap['package']] ?? '') : '';
        
        return [
            'lineNumber' => $lineNumber,
            'designator' => $designator,
            'mpn' => $mpn,
            'manufacturer' => $manufacturer,
            'quantity' => max(1, $qty),
            'description' => $description,
            'value' => $description, // Alias for compatibility
            'package' => $package,
            'supplier' => isset($headerMap['supplier']) ? trim($row[$headerMap['supplier']] ?? '') : '',
            'supplier_pn' => isset($headerMap['supplier_pn']) ? trim($row[$headerMap['supplier_pn']] ?? '') : '',
        ];
    }

    /**
     * Consolidate duplicate lines (same MPN)
     */
    public function consolidate(array $lines): array
    {
        $consolidated = [];
        
        foreach ($lines as $line) {
            $key = $line['mpn'] ?: $line['description'];
            
            if (!$key) {
                continue;
            }
            
            if (isset($consolidated[$key])) {
                // Merge designators and sum quantities
                $existing = $consolidated[$key];
                $existing['designator'] = trim($existing['designator'] . ', ' . $line['designator'], ', ');
                $existing['quantity'] += $line['quantity'];
                $consolidated[$key] = $existing;
            } else {
                $consolidated[$key] = $line;
            }
        }
        
        return array_values($consolidated);
    }

    /**
     * Validate BOM structure
     */
    public function validate(array $lines): array
    {
        $errors = [];
        
        if (empty($lines)) {
            $errors[] = 'BOM file is empty or has no valid data';
            return $errors;
        }
        
        $lineNumber = 1;
        foreach ($lines as $line) {
            if (empty($line['mpn']) && empty($line['description'])) {
                $errors[] = "Line {$lineNumber}: Missing MPN and description";
            }
            
            if ($line['quantity'] <= 0) {
                $errors[] = "Line {$lineNumber}: Invalid quantity ({$line['quantity']})";
            }
            
            $lineNumber++;
        }
        
        return $errors;
    }
}
