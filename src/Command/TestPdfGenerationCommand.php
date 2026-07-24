<?php

namespace App\Command;

use App\Entity\BomLine;
use App\Entity\Company;
use App\Entity\Quote;
use App\Service\BOMParser;
use App\Service\IssuingCompanyService;
use App\Service\PricingEngine;
use App\Service\UnifiedPdfGeneratorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-pdf-generation',
    description: 'End-to-end test: BOM → pricing waterfall → PDF quote document',
)]
class TestPdfGenerationCommand extends Command
{
    public function __construct(
        private BOMParser $bomParser,
        private PricingEngine $pricingEngine,
        private UnifiedPdfGeneratorService $pdfGenerator,
        private IssuingCompanyService $issuingCompanyService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to BOM file (CSV or XLSX)')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output PDF path', 'test_quote.pdf')
            ->addOption('margin', 'm', InputOption::VALUE_OPTIONAL, 'Margin percentage', '25')
            ->addOption('company', null, InputOption::VALUE_OPTIONAL, 'Company name for quote', 'Acme Electronics Ltd')
            ->addOption('issuer', null, InputOption::VALUE_OPTIONAL, 'Issuing company: starz_morocco, starz_electronics, starz_energies', 'starz_morocco')
            ->addOption('board-count', 'b', InputOption::VALUE_OPTIONAL, 'Number of boards - multiplies BOM quantities (e.g. BOM qty 2 × 10 boards = 20)', '1')
            ->addOption('order-multiple', null, InputOption::VALUE_OPTIONAL, 'Round quantities to this multiple (e.g. 10)', '1')
            ->addOption('providers', 'p', InputOption::VALUE_OPTIONAL, 'Comma-separated list of providers to use (alibaba,mouser,digikey,nexar)', '')
            ->setHelp('Full pipeline test: parse BOM → price via waterfall → generate PDF document');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath = $input->getArgument('file');
        $outputPath = $input->getOption('output');
        $marginPercent = (float) $input->getOption('margin');
        $companyName = $input->getOption('company');
        $issuerKey = $input->getOption('issuer');
        $boardCount = $input->getOption('board-count');
        $orderMultiple = $input->getOption('order-multiple');

        if (!filter_var($boardCount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            $io->error('--board-count must be a positive integer.');
            return Command::FAILURE;
        }
        $boardCount = (int) $boardCount;

        if (!filter_var($orderMultiple, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            $io->error('--order-multiple must be a positive integer.');
            return Command::FAILURE;
        }
        $orderMultiple = (int) $orderMultiple;

        if (!is_numeric($marginPercent) || $marginPercent < 0) {
            $io->error('--margin must be a non-negative number.');
            return Command::FAILURE;
        }
        $marginPercent = (float) $marginPercent;

        // Path traversal check for output
        $outputPath = realpath($outputPath) ?: $outputPath;
        $projectDir = realpath(dirname(__DIR__, 2));
        $resolvedDir = realpath(dirname($outputPath)) ?: dirname($outputPath);
        if (str_starts_with($resolvedDir, '/') && !str_starts_with($resolvedDir, $projectDir)
            && dirname($outputPath) !== '.' && !str_starts_with($resolvedDir, realpath(sys_get_temp_dir()))
        ) {
            $io->error('Output path must be within the project directory.');
            return Command::FAILURE;
        }
        
        // Parse providers option
        $providersStr = $input->getOption('providers');
        $providers = [];
        if (!empty($providersStr)) {
            $validProviders = ['alibaba', 'mouser', 'digikey', 'nexar'];
            $providers = array_filter(array_map('trim', explode(',', strtolower($providersStr))));
            $providers = array_intersect($providers, $validProviders);
        }

        if (!file_exists($filePath)) {
            $io->error("File not found: {$filePath}");
            return Command::FAILURE;
        }

        $io->title('QuoteBuddy End-to-End: BOM → Pricing → PDF');

        // ── Step 1: Parse BOM ──────────────────────────────────────────────
        $io->section('1. BOM Parsing');
        try {
            $bomLines = $this->bomParser->parse($filePath);
            $bomLines = $this->bomParser->consolidate($bomLines);
            if ($boardCount > 1) {
                $bomLines = $this->bomParser->applyBoardCount($bomLines, $boardCount);
                $io->text(sprintf('Board count: %d (BOM quantities multiplied)', $boardCount));
            }
            if ($orderMultiple > 1) {
                $bomLines = $this->bomParser->applyOrderMultiple($bomLines, $orderMultiple);
                $io->text(sprintf('Order multiple: %d (all quantities rounded up)', $orderMultiple));
            }
            $io->success(sprintf('Parsed %d unique part numbers', count($bomLines)));
        } catch (\Exception $e) {
            $io->error('BOM parsing failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // ── Step 2: Price via Waterfall ────────────────────────────────────
        $providerLabel = empty($providers) ? 'All' : implode(', ', array_map('ucfirst', $providers));
        $io->section(sprintf('2. Pricing Waterfall (%s)', $providerLabel));
        try {
            $result = $this->pricingEngine->processBOM($bomLines, ['providers' => $providers]);
            $stats = $result['stats'];
            $io->success(sprintf(
                'Sourced %d/%d parts (%.0f%% coverage) — Total: $%.2f',
                $stats['sourced'],
                $stats['total_lines'],
                $stats['coverage_percent'],
                $stats['total_cost']
            ));

            // Source breakdown
            $sourceBreakdown = [];
            foreach ($stats['sources'] as $src => $count) {
                if ($count > 0) {
                    $sourceBreakdown[] = sprintf('%s: %d', strtoupper($src), $count);
                }
            }
            if (!empty($sourceBreakdown)) {
                $io->text('Sources: ' . implode(' | ', $sourceBreakdown));
            }
        } catch (\Exception $e) {
            $io->error('Pricing waterfall failed: ' . $e->getMessage());
            $io->text($e->getTraceAsString());
            return Command::FAILURE;
        }

        // ── Step 3: Build Quote Entity (in-memory) ─────────────────────────
        $io->section('3. Building Quote Entity');
        try {
            $company = new Company();
            $company->setName($companyName);

            $quote = new Quote();
            $quote->setCompany($company);
            $quote->setStatus('draft');
            $quote->setCurrency('USD');
            $quote->setQuantity($stats['total_lines']);
            $quote->setCoveragePercent((string) $stats['coverage_percent']);
            $quote->setShipToCountry('United States');
            $quote->setIncoterms('DDP');
            $quote->setIssuingCompany($issuerKey);
            $quote->setNotes(sprintf(
                'Auto-generated quote from %s. %d parts sourced via pricing waterfall. Coverage: %.0f%%.',
                basename($filePath),
                $stats['sourced'],
                $stats['coverage_percent']
            ));

            // Calculate totals with margin
            $subtotal = $stats['total_cost'];
            $marginAmount = $subtotal * ($marginPercent / 100);
            $total = $subtotal + $marginAmount;
            $quote->setTotalCost((string) round($total, 2));

            // Add BomLine entities
            $lineNumber = 1;
            foreach ($result['lines'] as $pricedLine) {
                $bomLine = new BomLine();
                $bomLine->setLineNumber($lineNumber);
                $bomLine->setMpn($pricedLine['mpn'] ?? 'N/A');
                $bomLine->setManufacturer($pricedLine['manufacturer'] ?? '—');
                $bomLine->setDescription($pricedLine['description'] ?? '');
                $bomLine->setQuantity($pricedLine['effective_quantity'] ?? $pricedLine['quantity'] ?? 1);
                $bomLine->setUnitPrice((string) ($pricedLine['unit_price'] ?? 0));
                $bomLine->setExtendedPrice((string) ($pricedLine['extended_price'] ?? 0));
                $bomLine->setProcurementSource(strtoupper($pricedLine['source'] ?? 'MANUAL'));
                $bomLine->setConfidenceScore($pricedLine['confidence']['score'] ?? 0);
                $bomLine->setConfidenceLevel($pricedLine['confidence']['level'] ?? 'VERY_LOW');
                $bomLine->setRequiresReview($pricedLine['confidence']['requiresReview'] ?? true);

                if (isset($pricedLine['leadtime_days'])) {
                    $bomLine->setLeadTimeDays($pricedLine['leadtime_days']);
                }
                if (isset($pricedLine['lifecycle_warning'])) {
                    $bomLine->setLifecycleStatus($pricedLine['lifecycle_warning']);
                }
                
                // Store supplier tracking data (not shown on PDF, but persisted for internal use)
                if (isset($pricedLine['product_url'])) {
                    $bomLine->setSupplierProductUrl($pricedLine['product_url']);
                }
                if (isset($pricedLine['search_url'])) {
                    $bomLine->setDistributorSearchUrl($pricedLine['search_url']);
                } elseif (isset($pricedLine['_source_url'])) {
                    $bomLine->setDistributorSearchUrl($pricedLine['_source_url']);
                }
                if (isset($pricedLine['alternatives'])) {
                    $bomLine->setAlternativeParts($pricedLine['alternatives']);
                }
                
                // Build rich sourcing metadata
                $sourcingData = [
                    'source' => strtoupper($pricedLine['source'] ?? 'MANUAL'),
                    'waterfall_info' => $pricedLine['waterfall_info'] ?? null,
                    'moq' => $pricedLine['moq'] ?? null,
                    'pack_quantity' => $pricedLine['pack_quantity'] ?? null,
                    'stock' => $pricedLine['stock'] ?? 0,
                    'confidence' => $pricedLine['confidence'] ?? null,
                ];
                
                // Extract supplier name from source-specific fields
                $source = strtolower($pricedLine['source'] ?? '');
                if ($source === 'alibaba') {
                    // Alibaba puts supplier info in 'manufacturer' field
                    $supplierName = $pricedLine['manufacturer'] ?? null;
                    $bomLine->setSupplierName($supplierName);
                    $sourcingData['supplier_type'] = $pricedLine['supplier_type'] ?? null;
                    $sourcingData['trade_assurance'] = $pricedLine['trade_assurance'] ?? null;
                    $sourcingData['shipping_from'] = $pricedLine['shipping_from'] ?? null;
                    $sourcingData['crawl_data'] = $pricedLine['_crawl_data'] ?? null;
                } elseif ($source === 'mouser') {
                    $bomLine->setSupplierName('Mouser Electronics');
                } elseif ($source === 'digikey') {
                    $bomLine->setSupplierName('DigiKey Electronics');
                } elseif ($source === 'nexar') {
                    $bomLine->setSupplierName('Nexar (Aggregated)');
                } else {
                    $bomLine->setSupplierName($source ?: null);
                }
                
                $bomLine->setSourcingData($sourcingData);

                $quote->addBomLine($bomLine);
                $lineNumber++;
            }

            $io->success(sprintf(
                'Quote %s built: %d BOM lines, subtotal $%.2f + %.0f%% margin = $%.2f',
                $quote->getQuoteNumber(),
                count($result['lines']),
                $subtotal,
                $marginPercent,
                $total
            ));
        } catch (\Exception $e) {
            $io->error('Quote entity building failed: ' . $e->getMessage());
            $io->text($e->getTraceAsString());
            return Command::FAILURE;
        }

        // ── Step 4: Generate PDF ───────────────────────────────────────────
        $io->section('4. PDF Generation (mPDF)');
        try {
            $pdfContent = $this->pdfGenerator->generateQuotePdf($quote);

            $pdfSize = strlen($pdfContent);
            file_put_contents($outputPath, $pdfContent);

            $io->success(sprintf(
                'PDF generated: %s (%s)',
                $outputPath,
                $this->formatBytes($pdfSize)
            ));
        } catch (\Exception $e) {
            $io->error('PDF generation failed: ' . $e->getMessage());
            $io->text($e->getTraceAsString());
            return Command::FAILURE;
        }

        // ── Step 5: Validation ─────────────────────────────────────────────
        $io->section('5. Validation');

        $checks = [
            ['BOM parsed', count($bomLines) > 0],
            ['All parts priced', $stats['unsourced'] === 0],
            ['Coverage 100%', $stats['coverage_percent'] >= 100],
            ['PDF file exists', file_exists($outputPath)],
            ['PDF is valid (starts with %PDF)', str_starts_with($pdfContent, '%PDF')],
            ['PDF size > 5KB', $pdfSize > 5000],
            ['Quote number generated', !empty($quote->getQuoteNumber())],
            ['BOM lines attached', $quote->getBomLines()->count() > 0],
        ];

        $autoPublish = $this->pricingEngine->canAutoPublish($stats, $result['lines']);
        $checks[] = ['Auto-publish eligible', $autoPublish['can_publish']];

        $allPassed = true;
        $rows = [];
        foreach ($checks as [$label, $passed]) {
            $rows[] = [$label, $passed ? '✅ PASS' : '❌ FAIL'];
            if (!$passed) {
                $allPassed = false;
            }
        }

        $io->table(['Check', 'Result'], $rows);

        if ($allPassed) {
            $io->success(sprintf(
                '✅ END-TO-END PIPELINE COMPLETE — %d parts → $%.2f quote → %s PDF',
                count($bomLines),
                $total,
                $this->formatBytes($pdfSize)
            ));
        } else {
            $io->warning('Some checks failed — review output above');
        }

        return $allPassed ? Command::SUCCESS : Command::FAILURE;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
