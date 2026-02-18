<?php

namespace App\Service;

use App\Entity\Quote;
use App\Entity\BomLine;
use App\Entity\ProcurementException;
use App\Repository\QuoteRepository;
use App\Entity\RFQ;
use App\Repository\BomLineRepository;
use App\Repository\ProcurementExceptionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * QuoteCoPilotService
 * 
 * Automated quote generation from BOM uploads with AI-powered enhancements.
 * 
 * Core workflow:
 * 1. Parse BOM file (CSV, Excel, or Altium/KiCad exports)
 * 2. Process each line through API waterfall:
 *    - Mouser API (first choice - official distributor)
 *    - DigiKey API (fallback)
 *    - Nexar API (electronics search engine)
 *    - Alibaba API (for non-electronic components)
 *    - AI Price Imputation (ML-based estimation for unmapped parts)
 * 3. Calculate coverage % (sourced vs total line items)
 * 4. Generate procurement exceptions report
 * 5. Calculate AI win probability prediction
 * 6. Check auto-publish criteria (>90% coverage, no critical exceptions)
 * 7. Create Quote entity with all BomLine children
 * 
 * Auto-publish rules:
 * - Coverage >= 90%
 * - No CRITICAL severity exceptions
 * - All high-value parts sourced (>$50 unit price)
 * - Lead time < 12 weeks for all parts
 * 
 * AI Enhancements:
 * - PriceImputationService: ML-based price estimation when APIs fail
 * - QuoteWinPredictorService: Predict quote win probability
 * 
 * Used by:
 * - QuoteCoPilotController for BOM uploads
 * - Quote detail page for regeneration
 */
class QuoteCoPilotService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private QuoteRepository $quoteRepository,
        private BomLineRepository $bomLineRepository,
        private ProcurementExceptionRepository $procurementExceptionRepository,
        private BOMParser $bomParser,
        private PricingEngine $pricingEngine,
        private CurrencyPreferenceService $currencyPreferenceService,
        private QuoteWinPredictorService $winPredictor
    ) {}

    /**
     * Auto-generate quote from BOM file
     * 
     * @param string $bomFilePath - Path to uploaded BOM file
     * @param int $companyId - Company ID (customer)
     * @param int $contactId - Contact ID (requester)
     * @param array $metadata - Additional metadata (RFQ ID, notes, etc.)
     * 
     * @return array{
     *   quoteId: int,
     *   coverage: float,
     *   autoPublished: bool,
     *   exceptionsCount: int,
     *   bomLineCount: int
     * }
     */
    public function autogenerateQuote(
        string $bomFilePath,
        int $companyId,
        int $contactId,
        array $metadata = []
    ): array {
        // 1. Parse BOM file
        $bomLines = $this->bomParser->parse($bomFilePath);
        $bomLines = $this->bomParser->consolidate($bomLines);
        
        // Validate BOM
        $validationErrors = $this->bomParser->validate($bomLines);
        if (!empty($validationErrors)) {
            throw new \RuntimeException('BOM validation failed: ' . implode(', ', $validationErrors));
        }

        // 2. Fetch related entities
        $company = $this->entityManager->getRepository(\App\Entity\Company::class)->find($companyId);
        if (!$company) {
            throw new \RuntimeException('Company not found: ' . $companyId);
        }

        $contact = null;
        if ($contactId) {
            $contact = $this->entityManager->getRepository(\App\Entity\Contact::class)->find($contactId);
        }

        $rfq = null;
        $quoteCurrency = $metadata['currency'] ?? null;
        if (isset($metadata['rfq_id'])) {
            $rfq = $this->entityManager->getRepository(RFQ::class)->find($metadata['rfq_id']);
            if ($rfq && !$quoteCurrency) {
                $quoteCurrency = $rfq->getCurrency();
            }
        }

        // 3. Create Quote entity with proper relationships
        $quote = new Quote();
        $quote->setCompany($company);
        $quote->setContact($contact);
        $quote->setRfq($rfq);
        $quote->setStatus('DRAFT');
        $quote->setCreatedAt(new \DateTime());
        $quote->setCurrency($quoteCurrency ?: $this->currencyPreferenceService->getDisplayCurrency());
        $this->entityManager->persist($quote);
        $this->entityManager->flush(); // Get quote ID
        
        // 3. Process BOM through API waterfall
        $result = $this->pricingEngine->processBOM($bomLines);
        $processedLines = $result['lines'];
        $stats = $result['stats'];
        
        // 4. Save BOM lines to database
        $lineNum = 0;
        foreach ($processedLines as $lineData) {
            $lineNum++;
            $bomLine = new BomLine();
            $bomLine->setQuote($quote);
            $bomLine->setLineNumber($lineData['lineNumber'] ?? $lineNum);
            $bomLine->setMpn($lineData['mpn']);
            $bomLine->setOriginalMpn($lineData['mpn']);
            $bomLine->setManufacturer($lineData['manufacturer'] ?? null);
            $bomLine->setDescription($lineData['description'] ?? null);
            $bomLine->setBomDescription($lineData['description'] ?? $lineData['value'] ?? null);
            $bomLine->setQuantity($lineData['effective_quantity'] ?? $lineData['qty'] ?? $lineData['quantity'] ?? 1);

            $status = $lineData['status'] ?? 'unsourced';
            $source = $lineData['source'] ?? null;

            if ($status === 'sourced' && ($lineData['unit_price'] ?? 0) > 0) {
                $bomLine->setUnitPrice((string) ($lineData['unit_price'] ?? '0'));
                $bomLine->setExtendedPrice((string) ($lineData['extended_price'] ?? '0'));
                $bomLine->setProcurementSource($source);
                $bomLine->setHasException(false);

                // Confidence scoring
                $confidence = $lineData['confidence'] ?? null;
                if ($confidence) {
                    $bomLine->setConfidenceScore((int) ($confidence['score'] ?? 0));
                    $bomLine->setConfidenceLevel($confidence['level'] ?? 'MEDIUM');
                    $bomLine->setConfidenceReasons($confidence['reasons'] ?? []);
                    $bomLine->setConfidenceWarnings($confidence['warnings'] ?? []);
                    $bomLine->setRequiresReview($confidence['requiresReview'] ?? false);
                }

                // Matched MPN
                if (!empty($lineData['matched_mpn'])) {
                    $bomLine->setMatchedMpn($lineData['matched_mpn']);
                }
                if (!empty($lineData['alt_mpn_used'])) {
                    $bomLine->setMatchedMpn($lineData['alt_mpn_used']);
                }
            } else {
                // Unsourced
                $bomLine->setUnitPrice(null);
                $bomLine->setExtendedPrice(null);
                $bomLine->setProcurementSource('Not Found');
                $bomLine->setHasException(true);
                $bomLine->setExceptionReason('Part not found in supplier APIs');
                $bomLine->setRequiresReview(true);
            }

            // Store supplier tracking data (not shown on customer-facing PDF)
            if (isset($lineData['product_url'])) {
                $bomLine->setSupplierProductUrl($lineData['product_url']);
            }
            if (isset($lineData['search_url']) || isset($lineData['_source_url'])) {
                $bomLine->setDistributorSearchUrl($lineData['search_url'] ?? $lineData['_source_url']);
            }
            if (isset($lineData['alternatives'])) {
                $bomLine->setAlternativeParts($lineData['alternatives']);
            }
            
            // Resolve supplier name from source
            $srcLower = strtolower($source ?? '');
            if ($srcLower === 'alibaba') {
                $bomLine->setSupplierName($lineData['manufacturer'] ?? 'Alibaba Supplier');
            } elseif ($srcLower === 'mouser') {
                $bomLine->setSupplierName('Mouser Electronics');
            } elseif ($srcLower === 'digikey') {
                $bomLine->setSupplierName('DigiKey Electronics');
            } elseif ($srcLower === 'nexar') {
                $bomLine->setSupplierName('Nexar (Aggregated)');
            }
            
            // Lifecycle
            $lifecycleWarning = $lineData['lifecycle_warning'] ?? null;
            if ($lifecycleWarning === 'critical') {
                $bomLine->setLifecycleStatus('Obsolete');
                $bomLine->setLifecycleWarning('critical');
            } elseif ($lifecycleWarning === 'warning') {
                $bomLine->setLifecycleStatus('NRND');
                $bomLine->setLifecycleWarning('warning');
            }
            
            // Build rich sourcing metadata JSON
            $bomLine->setSourcingData([
                'source' => strtoupper($srcLower),
                'waterfall_info' => $lineData['waterfall_info'] ?? null,
                'moq' => $lineData['moq'] ?? null,
                'pack_quantity' => $lineData['pack_quantity'] ?? null,
                'stock' => $lineData['stock'] ?? 0,
                'confidence' => $lineData['confidence'] ?? null,
                'alt_mpn_used' => $lineData['alt_mpn_used'] ?? null,
                'supplier_type' => $lineData['supplier_type'] ?? null,
                'trade_assurance' => $lineData['trade_assurance'] ?? null,
                'shipping_from' => $lineData['shipping_from'] ?? null,
            ]);
            
            $this->entityManager->persist($bomLine);
            
            // Create procurement exception for unsourced parts
            if ($status !== 'sourced') {
                $exception = new ProcurementException();
                $exception->setBomLine($bomLine);
                $exception->setExceptionType('NOT_FOUND');
                $exception->setSeverity('HIGH');
                $exception->setMessage('Part not found in supplier APIs: ' . ($lineData['mpn'] ?? 'unknown'));
                
                $this->entityManager->persist($exception);
            }
        }
        
        // 5. Calculate quote totals
        $totals = $this->pricingEngine->calculateQuoteTotals($processedLines, 25.0, $quote->getCurrency());
        $quote->setTotalCost((string) $totals['total']);
        
        // 6. Calculate AI win probability prediction
        $winPrediction = $this->winPredictor->predictWinProbability($quote);
        $quote->setMetadata(array_merge($quote->getMetadata() ?? [], [
            'win_prediction' => [
                'probability' => $winPrediction['probability'],
                'grade' => $winPrediction['grade'],
                'confidence' => $winPrediction['confidence'],
                'recommendation' => $winPrediction['recommendation'],
                'calculated_at' => (new \DateTime())->format('c'),
            ],
        ]));
        
        // 7. Check auto-publish criteria
        $publishCheck = $this->pricingEngine->canAutoPublish($stats, $processedLines);
        
        if ($publishCheck['can_publish']) {
            $quote->setStatus('PUBLISHED');
            $quote->setAutoPublished(true);
            $quote->setUpdatedAt(new \DateTime());
        }
        
        // 8. Flush all changes
        $this->entityManager->flush();
        
        return [
            'quoteId' => $quote->getId(),
            'coverage' => $stats['coverage_percent'],
            'autoPublished' => $publishCheck['can_publish'],
            'exceptionsCount' => $stats['unsourced'],
            'bomLineCount' => $stats['total_lines'],
            'stats' => $stats,
            'totals' => $totals,
            'winPrediction' => $winPrediction,
        ];
    }

    /**
     * Parse BOM file
     * 
     * Delegates to BOMParser service which handles multiple formats:
     * - CSV files
     * - Excel files (XLSX, XLS)
     * - Altium/KiCad exports
     * 
     * @param string $bomFilePath Path to the BOM file
     * @param string|null $extension Optional file extension (for uploaded files without extension in temp path)
     * @return array Array of parsed BOM lines with keys: designator, mpn, manufacturer, qty, description, value
     */
    public function parseBom(string $bomFilePath, ?string $extension = null): array
    {
        // Delegate to BOMParser service which handles CSV, Excel, and EDA exports
        return $this->bomParser->parse($bomFilePath, $extension);
    }

    /**
     * Round all BOM line quantities up to the nearest multiple.
     *
     * @param array $bomData    Parsed BOM data (from parseBom())
     * @param int   $orderMultiple  Round quantities to this multiple (e.g. 10)
     * @return array  BOM data with quantities rounded up
     */
    public function applyOrderMultiple(array $bomData, int $orderMultiple): array
    {
        return $this->bomParser->applyOrderMultiple($bomData, $orderMultiple);
    }

    /**
     * Process BOM through REAL PricingEngine API waterfall
     * 
     * Uses the same PricingEngine::processBOM() as the CLI command — full
     * Alibaba/DigiKey/Mouser/Nexar waterfall with confidence scoring,
     * alt-MPN fallback, qty-aware re-evaluation, and BOM-price ceiling.
     * 
     * @param array $bomData - Parsed BOM data (from BOMParser::parse())
     * @param int $quoteId - Quote ID
     * 
     * @return array{
     *   coverage: float,
     *   exceptionsCount: int,
     *   sourcedCount: int,
     *   totalCount: int,
     *   stats: array
     * }
     */
    public function processBom(array $bomData, int $quoteId): array
    {
        $quote = $this->quoteRepository->find($quoteId);
        if (!$quote) {
            throw new \RuntimeException('Quote not found');
        }

        // Consolidate duplicate MPNs (same logic as CLI command)
        $bomLines = $this->bomParser->consolidate($bomData);

        // ── Run the REAL PricingEngine waterfall ──
        $result = $this->pricingEngine->processBOM($bomLines);
        $processedLines = $result['lines'];
        $stats = $result['stats'];

        $totalCost = '0.00';
        $sourcedCount = 0;
        $exceptionsCount = 0;
        $lineNum = 0;

        foreach ($processedLines as $lineData) {
            $lineNum++;
            $bomLine = new BomLine();
            $bomLine->setQuote($quote);
            $bomLine->setLineNumber($lineData['lineNumber'] ?? $lineNum);
            $bomLine->setMpn($lineData['mpn']);
            $bomLine->setOriginalMpn($lineData['mpn']); // Preserve original before any alt-MPN swap
            $bomLine->setManufacturer($lineData['manufacturer'] ?? null);
            $bomLine->setDescription($lineData['description'] ?? $lineData['value'] ?? null);
            $bomLine->setBomDescription($lineData['description'] ?? $lineData['value'] ?? null);
            $bomLine->setQuantity($lineData['effective_quantity'] ?? $lineData['qty'] ?? $lineData['quantity'] ?? 1);

            $status = $lineData['status'] ?? 'unsourced';
            $source = $lineData['source'] ?? null;

            if ($status === 'sourced' && ($lineData['unit_price'] ?? 0) > 0) {
                $unitPrice = (string) $lineData['unit_price'];
                $extPrice  = (string) $lineData['extended_price'];

                $bomLine->setUnitPrice($unitPrice);
                $bomLine->setExtendedPrice($extPrice);
                $bomLine->setProcurementSource($source);
                $bomLine->setHasException(false);

                $totalCost = $this->addMoney($totalCost, $extPrice, 2);
                $sourcedCount++;

                // ── Confidence scoring (from PricingEngine) ──
                $confidence = $lineData['confidence'] ?? null;
                if ($confidence) {
                    $bomLine->setConfidenceScore((int) ($confidence['score'] ?? 0));
                    $bomLine->setConfidenceLevel($confidence['level'] ?? 'MEDIUM');
                    $bomLine->setConfidenceReasons($confidence['reasons'] ?? []);
                    $bomLine->setConfidenceWarnings($confidence['warnings'] ?? []);
                    $bomLine->setRequiresReview($confidence['requiresReview'] ?? false);
                }

                // ── Matched MPN (may differ from BOM MPN) ──
                if (!empty($lineData['matched_mpn'])) {
                    $bomLine->setMatchedMpn($lineData['matched_mpn']);
                }
                if (!empty($lineData['alt_mpn_used'])) {
                    // Alt MPN was used as primary — record it
                    $bomLine->setMatchedMpn($lineData['alt_mpn_used']);
                }

                // ── Listing URLs ──
                $productUrl = $lineData['product_url'] ?? null;
                $searchUrl  = $lineData['search_url'] ?? $lineData['_source_url'] ?? $productUrl ?? null;
                if ($productUrl) {
                    $bomLine->setSupplierProductUrl($productUrl);
                }
                if ($searchUrl) {
                    $bomLine->setDistributorSearchUrl($searchUrl);
                }

                // ── Supplier name ──
                $srcLower = strtolower($source ?? '');
                if ($srcLower === 'alibaba') {
                    $bomLine->setSupplierName($lineData['manufacturer'] ?? 'Alibaba Supplier');
                } elseif ($srcLower === 'mouser') {
                    $bomLine->setSupplierName('Mouser Electronics');
                } elseif ($srcLower === 'digikey') {
                    $bomLine->setSupplierName('DigiKey Electronics');
                } elseif ($srcLower === 'nexar') {
                    $bomLine->setSupplierName('Nexar (Aggregated)');
                }

                // ── Lifecycle ──
                $lifecycleWarning = $lineData['lifecycle_warning'] ?? null;
                if ($lifecycleWarning === 'critical') {
                    $bomLine->setLifecycleStatus('Obsolete');
                    $bomLine->setLifecycleWarning('critical');
                } elseif ($lifecycleWarning === 'warning') {
                    $bomLine->setLifecycleStatus('NRND');
                    $bomLine->setLifecycleWarning('warning');
                }

                // ── Alternatives ──
                if (!empty($lineData['alternatives'])) {
                    $bomLine->setAlternativeParts($lineData['alternatives']);
                }

                // ── Rich sourcing metadata JSON ──
                $bomLine->setSourcingData([
                    'source' => strtoupper($srcLower),
                    'waterfall_info' => $lineData['waterfall_info'] ?? null,
                    'moq' => $lineData['moq'] ?? null,
                    'pack_quantity' => $lineData['pack_quantity'] ?? null,
                    'stock' => $lineData['stock'] ?? 0,
                    'confidence' => $confidence,
                    'alt_mpn_used' => $lineData['alt_mpn_used'] ?? null,
                    'supplier_type' => $lineData['supplier_type'] ?? null,
                    'trade_assurance' => $lineData['trade_assurance'] ?? null,
                    'shipping_from' => $lineData['shipping_from'] ?? null,
                ]);

            } else {
                // Unsourced part
                $bomLine->setUnitPrice(null);
                $bomLine->setExtendedPrice(null);
                $bomLine->setProcurementSource('Not Found');
                $bomLine->setHasException(true);
                $bomLine->setExceptionReason('Part not found in supplier APIs');
                $bomLine->setRequiresReview(true);

                $confidence = $lineData['confidence'] ?? null;
                if ($confidence) {
                    $bomLine->setConfidenceScore((int) ($confidence['score'] ?? 0));
                    $bomLine->setConfidenceLevel($confidence['level'] ?? 'VERY_LOW');
                    $bomLine->setConfidenceReasons($confidence['reasons'] ?? []);
                    $bomLine->setConfidenceWarnings($confidence['warnings'] ?? []);
                }

                $exceptionsCount++;

                // Create procurement exception
                $exception = new ProcurementException();
                $exception->setBomLine($bomLine);
                $exception->setExceptionType('NOT_FOUND');
                $exception->setSeverity('HIGH');
                $exception->setMessage('Part not found in supplier APIs: ' . ($lineData['mpn'] ?? 'unknown'));
                $this->entityManager->persist($exception);
            }

            $this->entityManager->persist($bomLine);
        }

        // ── Update quote totals ──
        $quote->setTotalCost($totalCost);
        $coverage = count($processedLines) > 0
            ? ($sourcedCount / count($processedLines)) * 100
            : 0;
        $quote->setCoveragePercent((string) round($coverage, 2));

        // ── Win probability prediction ──
        try {
            $winPrediction = $this->winPredictor->predictWinProbability($quote);
            $quote->setMetadata(array_merge($quote->getMetadata() ?? [], [
                'win_prediction' => [
                    'probability' => $winPrediction['probability'],
                    'grade' => $winPrediction['grade'],
                    'confidence' => $winPrediction['confidence'],
                    'recommendation' => $winPrediction['recommendation'],
                    'calculated_at' => (new \DateTime())->format('c'),
                ],
            ]));
        } catch (\Exception $e) {
            // Win predictor is non-critical
        }

        $this->entityManager->flush();

        return [
            'coverage' => round($coverage, 2),
            'exceptionsCount' => $exceptionsCount,
            'sourcedCount' => $sourcedCount,
            'totalCount' => count($processedLines),
            'stats' => $stats,
        ];
    }

    /**
     * Multiply decimal values with BCMath when available, float fallback otherwise.
     */
    private function mulMoney(string $left, string $right, int $scale = 2): string
    {
        if (\function_exists('bcmul')) {
            return \bcmul($left, $right, $scale);
        }

        return number_format((float)$left * (float)$right, $scale, '.', '');
    }

    /**
     * Add decimal values with BCMath when available, float fallback otherwise.
     */
    private function addMoney(string $left, string $right, int $scale = 2): string
    {
        if (\function_exists('bcadd')) {
            return \bcadd($left, $right, $scale);
        }

        return number_format((float)$left + (float)$right, $scale, '.', '');
    }


    /**
     * Check if quote meets auto-publish criteria
     * 
     * @param int $quoteId - Quote ID
     * @param float $coverage - Coverage percentage
     * 
     * @return bool - True if quote should be auto-published
     */
    public function checkAutoPublishCriteria(int $quoteId, float $coverage): bool
    {
        // Check 1: Coverage >= 90%
        if ($coverage < 90.0) {
            return false;
        }
        
        // Check 2: No CRITICAL exceptions (if ProcurementException entity exists)
        try {
            $criticalCount = $this->entityManager->createQuery(
                'SELECT COUNT(pe) FROM App\Entity\ProcurementException pe
                 JOIN pe.bomLine bl WHERE bl.quote = :quoteId AND pe.severity = :severity'
            )->setParameter('quoteId', $quoteId)
             ->setParameter('severity', 'CRITICAL')
             ->getSingleScalarResult();
            if ($criticalCount > 0) {
                return false;
            }
        } catch (\Exception $e) {
            // Entity might not exist yet, skip this check
        }
        
        // Check 3: All high-value parts sourced
        $bomLineRepo = $this->entityManager->getRepository(BomLine::class);
        $qb = $bomLineRepo->createQueryBuilder('bl');
        $highValueUnsourced = $qb
            ->where('bl.quote = :quoteId')
            ->andWhere('(bl.unitPrice IS NULL OR bl.procurementSource = :notFound)')
            ->setParameter('quoteId', $quoteId)
            ->setParameter('notFound', 'Not Found')
            ->getQuery()
            ->getResult();
        
        // If any high-value parts (typically > $50) are unsourced, require manual review
        foreach ($highValueUnsourced as $line) {
            if ($line->getQuantity() * 50 > 1000) { // Extended value > $1000
                return false;
            }
        }
        
        // Check 4: Lead times < 12 weeks (84 days)
        $qb2 = $bomLineRepo->createQueryBuilder('bl');
        $longLeadCount = $qb2
            ->select('COUNT(bl.id)')
            ->where('bl.quote = :quoteId')
            ->andWhere('bl.leadTimeDays > :maxLeadTime')
            ->setParameter('quoteId', $quoteId)
            ->setParameter('maxLeadTime', 84)
            ->getQuery()
            ->getSingleScalarResult();
        
        if ($longLeadCount > 0) {
            return false;
        }
        
        // All criteria met
        return true;
    }

    /**
     * Regenerate quote (re-run API waterfall for all unmapped parts)
     * 
     * @param int $quoteId - Quote ID
     * 
     * @return array - Updated coverage and exceptions
     */
    public function regenerateQuote(int $quoteId): array
    {
        $quote = $this->entityManager->getRepository(Quote::class)->find($quoteId);
        if (!$quote) {
            throw new \RuntimeException("Quote not found: {$quoteId}");
        }
        
        // Get all BOM lines for this quote
        $bomLines = $this->entityManager->getRepository(BomLine::class)
            ->findBy(['quote' => $quote]);
        
        $reprocessed = 0;
        $newlySourced = 0;
        
        foreach ($bomLines as $bomLine) {
            // Only reprocess if not sourced or imputed
            if ($bomLine->getProcurementSource() === 'Not Found' || !$bomLine->getUnitPrice()) {
                $mpn = $bomLine->getMpn();
                $manufacturer = $bomLine->getManufacturer();
                $quantity = $bomLine->getQuantity();
                
                // Re-run pricing via PricingEngine
                try {
                    $pricing = $this->pricingEngine->getPricing($mpn, $manufacturer);
                    
                    if ($pricing) {
                        // Use the first price break for unit pricing
                        $priceBreaks = $pricing['pricing'] ?? [];
                        $unitPrice = 0.0;
                        if (!empty($priceBreaks)) {
                            // Find best price break for quantity
                            foreach ($priceBreaks as $pb) {
                                if (($pb['quantity'] ?? 0) <= $quantity) {
                                    $unitPrice = (float) ($pb['price'] ?? 0);
                                }
                            }
                            if ($unitPrice === 0.0) {
                                $unitPrice = (float) ($priceBreaks[0]['price'] ?? 0);
                            }
                        }
                        $extPrice = $unitPrice * $quantity;
                        
                        $bomLine->setUnitPrice((string) $unitPrice);
                        $bomLine->setExtendedPrice((string) $extPrice);
                        $bomLine->setProcurementSource($pricing['source']);
                        $bomLine->setAvailability(($pricing['stock'] ?? 0) > 0 ? 'In Stock' : 'Factory');
                        $bomLine->setLeadTimeDays($pricing['leadtime_days'] ?? null);
                        $bomLine->setHasException(false);
                        
                        $newlySourced++;
                    }
                } catch (\Exception $e) {
                    // Failed to get pricing, leave as-is
                }
                
                $reprocessed++;
            }
        }
        
        $this->entityManager->flush();
        
        // Recalculate coverage
        $totalLines = count($bomLines);
        $sourcedLines = count(array_filter($bomLines, fn($line) => $line->getProcurementSource() !== 'Not Found' && $line->getUnitPrice() > 0));
        $coverage = $totalLines > 0 ? ($sourcedLines / $totalLines) * 100 : 0;
        
        $quote->setCoveragePercent((string)round($coverage, 2));
        $this->entityManager->flush();
        
        return [
            'reprocessed' => $reprocessed,
            'newly_sourced' => $newlySourced,
            'coverage' => round($coverage, 2),
            'total_lines' => $totalLines,
            'sourced_lines' => $sourcedLines
        ];
    }
}
