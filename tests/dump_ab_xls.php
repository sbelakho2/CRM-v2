<?php
/**
 * Dump all columns from ab.xls to see what pricing data exists
 */
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$file = __DIR__ . '/../ab.xls';
$reader = IOFactory::createReaderForFile($file);
$reader->setReadDataOnly(true);
$spreadsheet = $reader->load($file);
$sheet = $spreadsheet->getActiveSheet();

$rows = $sheet->toArray(null, true, true, true);
echo "Total rows: " . count($rows) . "\n\n";

// Print header
$header = $rows[1] ?? [];
echo "HEADERS:\n";
foreach ($header as $col => $val) {
    echo "  Col {$col}: \"{$val}\"\n";
}
echo "\n";

// Print all data with ALL columns
echo str_pad('Row', 4) . str_pad('Designator', 15) . str_pad('Qty', 6) . str_pad('MPN', 30) 
     . str_pad('QTE STOCK', 12) . str_pad('Unit Price', 12) . str_pad('Total Price', 12) . "\n";
echo str_repeat('─', 95) . "\n";

$grandTotal = 0;
for ($r = 2; $r <= count($rows); $r++) {
    $row = $rows[$r] ?? [];
    if (empty(array_filter($row))) continue;
    
    $desig = $row['B'] ?? '';
    $qty = $row['C'] ?? '';
    $mpn = $row['D'] ?? '';
    $qteStock = $row['I'] ?? '';
    $unitPrice = $row['J'] ?? '';
    $totalPrice = $row['K'] ?? '';
    
    echo str_pad($r-1, 4) 
         . str_pad(substr($desig, 0, 14), 15) 
         . str_pad($qty, 6) 
         . str_pad(substr($mpn, 0, 28), 30) 
         . str_pad($qteStock, 12) 
         . str_pad($unitPrice, 12) 
         . str_pad($totalPrice, 12) 
         . "\n";
    
    if (is_numeric($totalPrice)) {
        $grandTotal += (float)$totalPrice;
    }
}

echo str_repeat('─', 95) . "\n";
echo "Computed Grand Total from 'Total Price USD' column: \$" . number_format($grandTotal, 2) . "\n";
