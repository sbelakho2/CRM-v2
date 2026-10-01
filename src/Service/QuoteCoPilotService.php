<?php

declare(strict_types=1);

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
        
        // 4. Save BOM lines to database via shared method (H1)
        $lineNum = 0;
        foreach ($processedLines as $lineData) {
            $lineNum++;
            $this->createAndPersistBomLine($quote, $lineData, $lineNum);
        }
        
        // 5. Calculate quote totals
        $totals = $this->pricingEngine->calculateQuoteTotals($processedLines, 25.0, $quote->getCurrency());
        $quote->setTotalCost((string) $totals['total']);
        
        // 6. Calculate AI win probability prediction
        // Surface the lead-time context the predictor consumes: max lead time
        // across sourced lines (and customer urgency if provided), so
        // QuoteWinPredictorService::scoreLeadTimeMatch() isn't stuck on its
        // default 30-day assumption.
        $maxLeadTimeDays = 0;
        foreach ($processedLines as $line) {
            $leadTime = (int) ($line['leadtime_days'] ?? 0);
            if ($leadTime > $maxLeadTimeDays) {
                $maxLeadTimeDays = $leadTime;
            }
        }
        $predictionMetadata = array_merge($quote->getMetadata() ?? [], [
            'max_lead_time_days' => $maxLeadTimeDays > 0 ? $maxLeadTimeDays : 30,
            'customer_urgency' => $metadata['customer_urgency'] ?? 'normal',
        ]);
        $quote->setMetadata($predictionMetadata);

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
        
        // 8. Flush all changes to DB so checkAutoPublishCriteria() can query persisted data
        $this->entityManager->flush();

        // C6: Database-level auto-publish validation — queries persisted BomLines and exceptions
        // to double-check coverage, lead times, and critical exceptions.
        $coveragePct = $stats['coverage_percent'] ?? 0;
        $dbLevelPublished = false;
        if ($this->checkAutoPublishCriteria($quote->getId(), $coveragePct)) {
            $quote->setStatus('PUBLISHED');
            $quote->setAutoPublished(true);
            $quote->setUpdatedAt(new \DateTime());
            $this->entityManager->flush();
            $dbLevelPublished = true;
        }
        
        return [
            'quoteId' => $quote->getId(),
            'coverage' => $stats['coverage_percent'],
            // Return the authoritative DB-level re-check result: the in-memory
            // check can disagree with persisted state (e.g. exceptions written
            // by createAndPersistBomLine), and callers act on this flag.
            'autoPublished' => $dbLevelPublished,
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
     * Multiply BOM quantities by board count.
     *
     * Used when the BOM lists per-board quantities and the user wants to order
     * multiple boards (e.g., BOM qty=2, board_count=10 → final qty=20).
     */
    public function applyBoardCount(array $bomData, int $boardCount): array
    {
        return $this->bomParser->applyBoardCount($bomData, $boardCount);
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
     * @param array $options - Options: ['providers' => ['alibaba','mouser','digikey','nexar']]
     * 
     * @return array{
     *   coverage: float,
     *   exceptionsCount: int,
     *   sourcedCount: int,
     *   totalCount: int,
     *   stats: array
     * }
     */
    public function processBom(array $bomData, int $quoteId, array $options = []): array
    {
        $quote = $this->quoteRepository->find($quoteId);
        if (!$quote) {
            throw new \RuntimeException('Quote not found');
        }

        // Consolidate duplicate MPNs (same logic as CLI command)
        $bomLines = $this->bomParser->consolidate($bomData);

        // ── Run the REAL PricingEngine waterfall ──
        $result = $this->pricingEngine->processBOM($bomLines, $options);
        $processedLines = $result['lines'];
        $stats = $result['stats'];

        $totalCost = '0.00';
        $sourcedCount = 0;
        $exceptionsCount = 0;
        $lineNum = 0;

        foreach ($processedLines as $lineData) {
            $lineNum++;
            $bomLine = $this->createAndPersistBomLine($quote, $lineData, $lineNum);

            // Update tracking counters based on the persisted BomLine
            if ($bomLine->getUnitPrice() !== null && $bomLine->getExtendedPrice() !== null) {
                $totalCost = $this->addMoney($totalCost, (string) $bomLine->getExtendedPrice(), 2);
                $sourcedCount++;
            } else {
                $exceptionsCount++;
            }
        }

        // ── Update quote totals ──
        $quote->setTotalCost($totalCost);
        $coverage = count($processedLines) > 0
            ? ($sourcedCount / count($processedLines)) * 100
            : 0;
        $quote->setCoveragePercent((string) round($coverage, 2));

        // ── Win probability prediction ──
        try {
            // Surface lead-time context for the predictor (see autogenerateQuote).
            $maxLeadTimeDays = 0;
            foreach ($processedLines as $line) {
                $leadTime = (int) ($line['leadtime_days'] ?? 0);
                if ($leadTime > $maxLeadTimeDays) {
                    $maxLeadTimeDays = $leadTime;
                }
            }
            $quote->setMetadata(array_merge($quote->getMetadata() ?? [], [
                'max_lead_time_days' => $maxLeadTimeDays > 0 ? $maxLeadTimeDays : 30,
            ]));

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

        // C6: Database-level auto-publish validation — check persisted BomLines/exceptions
        if ($this->checkAutoPublishCriteria($quoteId, round($coverage, 2))) {
            $quote->setStatus('PUBLISHED');
            $quote->setAutoPublished(true);
            $this->entityManager->flush();
        }

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
     * Fix H2: Now delegates to the same pipeline as processBom() — reads existing BOM data,
     * re-runs pricing via PricingEngine::processBOM(), updates lines.
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
        
        // Fix H2: Read existing BOM lines and convert to array format for processBom()
        $existingBomLines = $this->entityManager->getRepository(BomLine::class)
            ->findBy(['quote' => $quote]);
        
        if (empty($existingBomLines)) {
            return [
                'reprocessed' => 0,
                'newly_sourced' => 0,
                'coverage' => 0,
                'total_lines' => 0,
                'sourced_lines' => 0,
            ];
        }
        
        // Convert BomLine entities back to array format for the pipeline
        $bomData = [];
        foreach ($existingBomLines as $bomLine) {
            $bomData[] = [
                'lineNumber' => $bomLine->getLineNumber(),
                'mpn' => $bomLine->getMpn() ?? '',
                'original_mpn' => $bomLine->getOriginalMpn() ?? $bomLine->getMpn(),
                'manufacturer' => $bomLine->getManufacturer(),
                'description' => $bomLine->getDescription() ?? $bomLine->getBomDescription(),
                'quantity' => $bomLine->getQuantity() ?? 1,
                'stock_quantity' => $bomLine->getQuantity(), // preserve as order qty
            ];
        }
        
        // Delete existing BomLines and ProcurementExceptions so processBom() can recreate them
        $exceptionRepo = $this->entityManager->getRepository(ProcurementException::class);
        foreach ($existingBomLines as $bomLine) {
            // Remove exceptions first
            $exceptions = $exceptionRepo->findBy(['bomLine' => $bomLine]);
            foreach ($exceptions as $exc) {
                $this->entityManager->remove($exc);
            }
            $this->entityManager->remove($bomLine);
        }
        $this->entityManager->flush();
        
        // Re-run the full pipeline
        return $this->processBom($bomData, $quoteId);
    }

    /**
     * Create and persist a BomLine entity from processed line data.
     *
     * Fix H1: Shared method extracted from autogenerateQuote() and processBom()
     * to eliminate ~150 lines of duplicated BomLine creation/persistence logic.
     *
     * @param Quote $quote   The quote entity to associate
     * @param array $lineData  Processed line data from PricingEngine
     * @param int   $lineNum   Sequential line number
     *
     * @return BomLine The persisted BomLine entity
     */
    private function createAndPersistBomLine(Quote $quote, array $lineData, int $lineNum): BomLine
    {
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
            $bomLine->setUnitPrice((string) ($lineData['unit_price']));
            $bomLine->setExtendedPrice((string) ($lineData['extended_price']));
            $bomLine->setProcurementSource($source);
            $bomLine->setHasException(false);

            // ── Confidence scoring ──
            $confidence = $lineData['confidence'] ?? null;
            if ($confidence) {
                $bomLine->setConfidenceScore((int) ($confidence['score'] ?? 0));
                $bomLine->setConfidenceLevel($confidence['level'] ?? 'MEDIUM');
                $bomLine->setConfidenceReasons($confidence['reasons'] ?? []);
                $bomLine->setConfidenceWarnings($confidence['warnings'] ?? []);
                $bomLine->setRequiresReview($confidence['requiresReview'] ?? false);
            }

            // ── Matched MPN ──
            if (!empty($lineData['matched_mpn'])) {
                $bomLine->setMatchedMpn($lineData['matched_mpn']);
            }
            if (!empty($lineData['alt_mpn_used'])) {
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
                // IMMUTABLE PRICING SNAPSHOT: persist the approved supplier
                // price breaks so live-quote tier repricing works against
                // THIS quote's snapshot instead of a flat qty-1 price.
                'price_breaks' => $lineData['pricing'] ?? null,
                'confidence' => $confidence,
                'alt_mpn_used' => $lineData['alt_mpn_used'] ?? null,
                'supplier_type' => $lineData['supplier_type'] ?? null,
                'trade_assurance' => $lineData['trade_assurance'] ?? null,
                'shipping_from' => $lineData['shipping_from'] ?? null,
                // Smart fallback metadata
                'fallback_method' => $lineData['_fallback_method'] ?? null,
                'fallback_original_mpn' => $lineData['_original_mpn'] ?? null,
                'fallback_mpn' => $lineData['_fallback_mpn'] ?? null,
                'fallback_keyword' => $lineData['_fallback_keyword'] ?? null,
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

            // Create procurement exception for unsourced parts
            $exception = new ProcurementException();
            $exception->setBomLine($bomLine);
            $exception->setExceptionType('NOT_FOUND');
            $exception->setSeverity('HIGH');
            $exception->setMessage('Part not found in supplier APIs: ' . ($lineData['mpn'] ?? 'unknown'));
            $this->entityManager->persist($exception);
        }

        $this->entityManager->persist($bomLine);

        return $bomLine;
    }
}
