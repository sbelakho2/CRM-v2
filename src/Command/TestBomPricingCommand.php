<?php

namespace App\Command;

use App\Service\BOMParser;
use App\Service\PricingEngine;
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
        $io->text('Testing Alibaba → Mouser → DigiKey → Nexar → AI Imputation...');
        
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
            
        } catch (\Exception $e) {
            $io->error('Pricing failed: ' . $e->getMessage());
            $io->text('Stack trace:');
            $io->text($e->getTraceAsString());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
