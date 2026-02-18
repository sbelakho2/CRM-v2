<?php

namespace App\Command;

use App\Service\BOMParser;
use App\Service\PricingEngine;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-bom-pricing',
    description: 'Test BOM parsing and pricing waterfall',
)]
class TestBomPricingCommand extends Command
{
    public function __construct(
        private BOMParser $bomParser,
        private PricingEngine $pricingEngine
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to BOM file (CSV or XLSX)')
            ->setHelp('Test BOM parsing and pricing APIs without saving to database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath = $input->getArgument('file');

        if (!file_exists($filePath)) {
            $io->error("File not found: {$filePath}");
            return Command::FAILURE;
        }

        $io->title('BOM Pricing Test');

        // Parse BOM
        $io->section('1. Parsing BOM');
        try {
            $bomLines = $this->bomParser->parse($filePath);
            $bomLines = $this->bomParser->consolidate($bomLines);
            
            $io->success(sprintf('Parsed %d unique part numbers', count($bomLines)));
            
            // Show first few lines
            if (count($bomLines) > 0) {
                $sample = array_slice($bomLines, 0, 3);
                $io->table(
                    ['Designator', 'MPN', 'Manufacturer', 'Qty'],
                    array_map(fn($line) => [
                        $line['designator'],
                        $line['mpn'],
                        $line['manufacturer'],
                        $line['quantity']
                    ], $sample)
                );
                
                if (count($bomLines) > 3) {
                    $io->note(sprintf('... and %d more lines', count($bomLines) - 3));
                }
            }
            
        } catch (\Exception $e) {
            $io->error('BOM parsing failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Process through pricing APIs
        $io->section('2. Processing through API Waterfall');
        $io->text('Testing Mouser → Smart Fallback (keyword, cleaned MPN, base MPN)...');
        
        try {
            $result = $this->pricingEngine->processBOM($bomLines);
            $stats = $result['stats'];
            
            $io->success('Pricing complete!');
            
            // Show statistics
            $io->section('3. Results');
            $io->table(
                ['Metric', 'Value'],
                [
                    ['Total Lines', $stats['total_lines']],
                    ['Successfully Sourced', $stats['sourced']],
                    ['Not Found', $stats['unsourced']],
                    ['Coverage', $stats['coverage_percent'] . '%'],
                    ['Total Cost', '$' . number_format($stats['total_cost'], 2)],
                    ['', ''],
                    ['Alibaba (crawler)', $stats['sources']['alibaba'] ?? 0],
                    ['Mouser', $stats['sources']['mouser'] ?? 0],
                    ['DigiKey', $stats['sources']['digikey'] ?? 0],
                    ['Nexar', $stats['sources']['nexar'] ?? 0],
                    ['AI Imputation', $stats['sources']['ai_imputation'] ?? 0],
                    ['Manual', $stats['sources']['manual'] ?? 0],
                    ['', ''],
                    ['High Confidence', $stats['confidence_breakdown']['HIGH'] ?? 0],
                    ['Medium Confidence', $stats['confidence_breakdown']['MEDIUM'] ?? 0],
                    ['Low Confidence', $stats['confidence_breakdown']['LOW'] ?? 0],
                    ['Very Low', $stats['confidence_breakdown']['VERY_LOW'] ?? 0],
                    ['', ''],
                    ['Lifecycle Critical', $stats['lifecycle_warnings']['critical'] ?? 0],
                    ['Lifecycle Warning', $stats['lifecycle_warnings']['warning'] ?? 0],
                    ['Qty Adjusted (MOQ/Pack)', $stats['quantity_adjusted_count'] ?? 0],
                ]
            );
            
            // ── FULL per-line comparison: BOM price vs API price ──
            $io->section('3b. Full Per-Line Pricing Comparison');
            $allLines = $result['lines'];
            $bomTotal = 0;
            $apiTotal = 0;
            $comparisonRows = [];
            
            foreach ($allLines as $line) {
                $mpn = $line['mpn'] ?? 'N/A';
                $qty = $line['effective_quantity'] ?? $line['stock_quantity'] ?? $line['quantity'] ?? 0;
                $bomUnit = $line['bom_unit_price'] ?? $line['unit_price_original'] ?? null;
                $apiUnit = $line['unit_price'] ?? 0;
                $apiExt = $line['extended_price'] ?? 0;
                $source = strtoupper($line['source'] ?? 'N/A');
                if (!empty($line['bom_price_capped'])) {
                    $source .= '+CAP';
                }
                $altMpn = $line['alt_mpn_used'] ?? '';
                
                // Try to get BOM embedded price from the original line data
                $bomEmbed = null;
                foreach ($bomLines as $bl) {
                    if (($bl['mpn'] ?? '') === $mpn && !empty($bl['unit_price'])) {
                        $bomEmbed = (float)$bl['unit_price'];
                        break;
                    }
                }
                
                $bomExt = ($bomEmbed !== null && $qty > 0) ? $bomEmbed * $qty : null;
                if ($bomExt !== null) $bomTotal += $bomExt;
                $apiTotal += $apiExt;
                
                $ratio = ($bomEmbed && $apiUnit > 0) ? round($apiUnit / $bomEmbed, 1) : '-';
                
                $comparisonRows[] = [
                    substr($mpn, 0, 22),
                    $altMpn ? substr($altMpn, 0, 18) : '',
                    $source,
                    number_format($qty),
                    $bomEmbed !== null ? '$' . number_format($bomEmbed, 4) : '-',
                    '$' . number_format($apiUnit, 4),
                    $bomExt !== null ? '$' . number_format($bomExt, 2) : '-',
                    '$' . number_format($apiExt, 2),
                    $ratio . 'x',
                ];
            }
            
            $io->table(
                ['MPN', 'Alt MPN Used', 'Src', 'Qty', 'BOM Unit$', 'API Unit$', 'BOM Ext$', 'API Ext$', 'Ratio'],
                $comparisonRows
            );
            
            $io->text(sprintf(
                '<info>BOM Embedded Total: $%s  |  API Total: $%s  |  Overall Ratio: %.1fx</info>',
                number_format($bomTotal, 2),
                number_format($apiTotal, 2),
                $bomTotal > 0 ? $apiTotal / $bomTotal : 0
            ));
            
            // Show sample pricing
            $sourcedLines = array_filter($result['lines'], fn($line) => $line['status'] === 'sourced');
            
            if (!empty($sourcedLines)) {
                $io->section('Sample Pricing (first 5 sourced)');
                $sample = array_slice($sourcedLines, 0, 5);
                $io->table(
                    ['MPN', 'Source', 'Unit Price', 'Qty', 'Extended', 'Stock'],
                    array_map(fn($line) => [
                        $line['mpn'],
                        strtoupper($line['source'] ?? 'N/A'),
                        '$' . number_format($line['unit_price'] ?? 0, 4),
                        $line['effective_quantity'] ?? $line['quantity'] ?? 0,
                        '$' . number_format($line['extended_price'] ?? 0, 2),
                        $line['stock'] ?? 'N/A'
                    ], $sample)
                );
            }
            
            // Check auto-publish
            $publishCheck = $this->pricingEngine->canAutoPublish($stats, $result['lines']);
            
            $io->section('4. Auto-Publish Check');
            $io->table(
                ['Check', 'Status'],
                [
                    ['Coverage ≥ 90%', $publishCheck['checks']['coverage_ok'] ? '✓' : '✗'],
                    ['No Critical Exceptions', $publishCheck['checks']['no_critical_exceptions'] ? '✓' : '✗'],
                    ['Reasonable Lead Times', $publishCheck['checks']['reasonable_leadtimes'] ? '✓' : '✗'],
                    ['High Value Sourced', $publishCheck['checks']['high_value_sourced'] ? '✓' : '✗'],
                ]
            );
            
            if ($publishCheck['can_publish']) {
                $io->success('✓ Quote would be auto-published!');
            } else {
                $io->warning('✗ Quote requires manual review');
            }
            
            // ── 5. Export full results to Excel with clickable links ──
            $io->section('5. Excel Export');
            try {
                $excelPath = $this->exportToExcel($result, $bomLines, $filePath);
                $io->success('Excel exported: ' . $excelPath);
            } catch (\Exception $excelErr) {
                $io->warning('Excel export failed: ' . $excelErr->getMessage());
            }
            
        } catch (\Exception $e) {
            $io->error('Pricing failed: ' . $e->getMessage());
            $io->text('Stack trace:');
            $io->text($e->getTraceAsString());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
    
    /**
     * Export BOM pricing results to a styled Excel file with clickable listing links.
     *
     * Columns: Line#, MPN, Alt MPN, Source, Qty, BOM Unit$, API Unit$,
     *          BOM Ext$, API Ext$, Ratio, Stock, Confidence, Listing Link
     */
    private function exportToExcel(array $result, array $bomLines, string $inputFile): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('BOM Pricing');
        
        // ── Header row ──
        $headers = [
            'A' => '#',
            'B' => 'MPN',
            'C' => 'Alt MPN Used',
            'D' => 'Source',
            'E' => 'Qty',
            'F' => 'BOM Unit$',
            'G' => 'API Unit$',
            'H' => 'BOM Ext$',
            'I' => 'API Ext$',
            'J' => 'Ratio',
            'K' => 'Stock',
            'L' => 'Confidence',
            'M' => 'Listing Link',
        ];
        
        foreach ($headers as $col => $label) {
            $sheet->setCellValue($col . '1', $label);
        }
        
        // Header style
        $headerStyle = $sheet->getStyle('A1:M1');
        $headerStyle->getFont()->setBold(true)->setSize(11)->setColor(
            new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFF')
        );
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5496');
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $headerStyle->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM);
        
        // ── Data rows ──
        $row = 2;
        $bomTotal = 0;
        $apiTotal = 0;
        
        foreach ($result['lines'] as $idx => $line) {
            $mpn        = $line['mpn'] ?? '';
            $altMpn     = $line['alt_mpn_used'] ?? '';
            $source     = strtoupper($line['source'] ?? 'N/A');
            if (!empty($line['bom_price_capped'])) $source .= '+CAP';
            $qty        = $line['effective_quantity'] ?? $line['stock_quantity'] ?? $line['quantity'] ?? 0;
            $apiUnit    = $line['unit_price'] ?? 0;
            $apiExt     = $line['extended_price'] ?? 0;
            $stock      = $line['stock'] ?? 'N/A';
            $confidence = $line['confidence']['level'] ?? 'N/A';
            $productUrl = $line['product_url'] ?? $line['search_url'] ?? null;
            
            // Find BOM embedded price
            $bomUnit = null;
            foreach ($bomLines as $bl) {
                if (($bl['mpn'] ?? '') === $mpn && !empty($bl['unit_price'])) {
                    $bomUnit = (float) $bl['unit_price'];
                    break;
                }
            }
            $bomExt = ($bomUnit !== null && $qty > 0) ? $bomUnit * $qty : null;
            if ($bomExt !== null) $bomTotal += $bomExt;
            $apiTotal += $apiExt;
            
            $ratio = ($bomUnit && $apiUnit > 0) ? round($apiUnit / $bomUnit, 1) . 'x' : '-';
            
            $sheet->setCellValue("A{$row}", $idx + 1);
            $sheet->setCellValue("B{$row}", $mpn);
            $sheet->setCellValue("C{$row}", $altMpn);
            $sheet->setCellValue("D{$row}", $source);
            $sheet->setCellValue("E{$row}", $qty);
            $sheet->setCellValue("F{$row}", $bomUnit);
            $sheet->setCellValue("G{$row}", $apiUnit);
            $sheet->setCellValue("H{$row}", $bomExt);
            $sheet->setCellValue("I{$row}", $apiExt);
            $sheet->setCellValue("J{$row}", $ratio);
            $sheet->setCellValue("K{$row}", $stock);
            $sheet->setCellValue("L{$row}", $confidence);
            
            // Clickable link to the listing
            if ($productUrl) {
                $sheet->setCellValue("M{$row}", $productUrl);
                $sheet->getCell("M{$row}")->getHyperlink()->setUrl($productUrl);
                $sheet->getStyle("M{$row}")->getFont()
                    ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('0563C1'))
                    ->setUnderline(true);
            } else {
                $sheet->setCellValue("M{$row}", 'N/A');
            }
            
            // Alternate row shading
            if ($row % 2 === 0) {
                $sheet->getStyle("A{$row}:M{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
            }
            
            $row++;
        }
        
        // ── Totals row ──
        $totRow = $row;
        $sheet->setCellValue("A{$totRow}", '');
        $sheet->setCellValue("B{$totRow}", 'TOTALS');
        $sheet->setCellValue("H{$totRow}", $bomTotal);
        $sheet->setCellValue("I{$totRow}", $apiTotal);
        $sheet->setCellValue("J{$totRow}", $bomTotal > 0 ? round($apiTotal / $bomTotal, 1) . 'x' : '-');
        $sheet->getStyle("A{$totRow}:M{$totRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$totRow}:M{$totRow}")->getBorders()
            ->getTop()->setBorderStyle(Border::BORDER_DOUBLE);
        
        // ── Formatting ──
        // Currency columns
        foreach (['F', 'G', 'H', 'I'] as $col) {
            $sheet->getStyle("{$col}2:{$col}{$totRow}")
                ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_CURRENCY_USD_INTEGER);
        }
        // Number columns
        $sheet->getStyle("E2:E{$totRow}")->getNumberFormat()
            ->setFormatCode('#,##0');
        $sheet->getStyle("K2:K{$totRow}")->getNumberFormat()
            ->setFormatCode('#,##0');
        
        // Column widths
        $widths = ['A' => 5, 'B' => 24, 'C' => 20, 'D' => 14, 'E' => 8,
                   'F' => 12, 'G' => 12, 'H' => 12, 'I' => 12, 'J' => 8,
                   'K' => 10, 'L' => 12, 'M' => 60];
        foreach ($widths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        
        // Freeze header row
        $sheet->freezePane('A2');
        
        // Auto-filter
        $sheet->setAutoFilter("A1:M{$totRow}");
        
        // ── Write file ──
        $baseName = pathinfo($inputFile, PATHINFO_FILENAME);
        $outPath  = dirname($inputFile) . '/' . $baseName . '_priced_' . date('Ymd_His') . '.xlsx';
        
        $writer = new Xlsx($spreadsheet);
        $writer->save($outPath);
        
        return $outPath;
    }
}
