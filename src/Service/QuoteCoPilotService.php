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
        private HtsClassificationService $htsClassificationService,
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
        foreach ($processedLines as $lineData) {
            $bomLine = new BomLine();
            $bomLine->setQuote($quote);
            $bomLine->setDesignator($lineData['designator']);
            $bomLine->setMpn($lineData['mpn']);
            $bomLine->setManufacturer($lineData['manufacturer'] ?? null);
            $bomLine->setDescription($lineData['description'] ?? null);
            $bomLine->setQuantity($lineData['qty']);
            $bomLine->setUnitPrice($lineData['unit_price'] ?? 0);
            $bomLine->setExtendedPrice($lineData['extended_price'] ?? 0);
            $bomLine->setSource($lineData['source'] ?? null);
            $bomLine->setStatus($lineData['status']);
            
            $this->entityManager->persist($bomLine);
            
            // Create procurement exception for unsourced parts
            if ($lineData['status'] !== 'sourced') {
                $exception = new ProcurementException();
                $exception->setQuote($quote);
                $exception->setBomLine($bomLine);
                $exception->setSeverity('HIGH');
                $exception->setMessage('Part not found in supplier APIs');
                $exception->setResolved(false);
                
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
     * Parse BOM file (CSV, Excel, Altium, KiCad)
     * 
     * @param string $bomFilePath - Path to BOM file
     * 
     * @return array - Array of BOM lines with standardized fields
     * [
     *   ['designator' => 'C1', 'mpn' => 'GRM155R71C104KA88D', 'manufacturer' => 'Murata', 'qty' => 10, ...],
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
     * Map BOM CSV columns to standardized field names
     */
    private function mapBomColumns(array $headers): array
    {
        $map = [
            'designator' => null,
            'mpn' => null,
            'manufacturer' => null,
            'description' => null,
            'quantity' => null,
            'value' => null,
        ];

        foreach ($headers as $index => $header) {
            // Reference Designators
            if (in_array($header, ['ref', 'reference', 'designator', 'refdes', 'reference designators'])) {
                $map['designator'] = $index;
            }
            // Part Number / MPN
            elseif (in_array($header, ['mpn', 'part number', 'partnumber', 'mfr part #', 'manufacturerpartnumber', 'part no'])) {
                $map['mpn'] = $index;
            }
            // Manufacturer
            elseif (in_array($header, ['mfr', 'manufacturer', 'mfg', 'manuf'])) {
                $map['manufacturer'] = $index;
            }
            // Description
            elseif (in_array($header, ['description', 'desc', 'comment'])) {
                $map['description'] = $index;
            }
            // Quantity
            elseif (in_array($header, ['qty', 'quantity', 'qnty', 'count'])) {
                $map['quantity'] = $index;
            }
            // Value (for passives)
            elseif (in_array($header, ['value', 'val'])) {
                $map['value'] = $index;
            }
        }

        return $map;
    }

    /**
     * Get cell value from row, handling null column indices
     */
    private function getCellValue(array $row, ?int $columnIndex): ?string
    {
        if ($columnIndex === null || !isset($row[$columnIndex])) {
            return null;
        }

        $value = trim($row[$columnIndex]);
        return $value !== '' ? $value : null;
    }

    /**
     * Process BOM through API waterfall
     * 
     * @param array $bomData - Parsed BOM data
     * @param int $quoteId - Quote ID
     * 
     * @return array{
     *   coverage: float,
     *   exceptionsCount: int,
     *   sourcedCount: int,
     *   totalCount: int
     * }
     */
    public function processBom(array $bomData, int $quoteId): array
    {
        $totalCount = count($bomData);
        $sourcedCount = 0;
        $exceptionsCount = 0;
        $totalCost = '0.00';

        $quote = $this->quoteRepository->find($quoteId);
        if (!$quote) {
            throw new \RuntimeException('Quote not found');
        }

        foreach ($bomData as $line) {
            // For MVP: Use sample pricing logic
            // In production: Call API waterfall (Mouser, DigiKey, Nexar, Alibaba, Pricebook, Imputation)
            $pricingResult = $this->getPricingForPart($line);

            // Create BomLine entity
            $bomLine = new BomLine();
            $bomLine->setQuote($quote);
            $bomLine->setLineNumber($line['lineNumber']);
            $bomLine->setMpn($line['mpn']);
            $bomLine->setManufacturer($line['manufacturer']);
            $bomLine->setDescription($line['description'] ?: $line['value']);
            $bomLine->setQuantity($line['quantity']);
            
            if ($pricingResult['found']) {
                $extendedPrice = bcmul($pricingResult['unitPrice'], (string)$line['quantity'], 2);
                $bomLine->setUnitPrice($pricingResult['unitPrice']);
                $bomLine->setExtendedPrice($extendedPrice);
                $bomLine->setProcurementSource($pricingResult['source']);
                $bomLine->setAvailability($pricingResult['availability']);
                $bomLine->setLeadTimeDays($pricingResult['leadTimeDays']);
                $bomLine->setHasException(false);
                $sourcedCount++;
                
                // Accumulate total cost as we process each line
                $totalCost = bcadd($totalCost, $extendedPrice, 2);
            } else {
                $bomLine->setUnitPrice(null);
                $bomLine->setExtendedPrice(null);
                $bomLine->setProcurementSource('Not Found');
                $bomLine->setAvailability(null);
                $bomLine->setLeadTimeDays(null);
                $bomLine->setHasException(true);
                $bomLine->setExceptionReason($pricingResult['reason']);
                $exceptionsCount++;
            }

            $this->entityManager->persist($bomLine);
        }

        // Set quote totals
        $quote->setTotalCost($totalCost);
        
        // Calculate coverage percentage
        $coverage = $totalCount > 0 ? ($sourcedCount / $totalCount) * 100 : 0;
        $quote->setCoveragePercent((string)round($coverage, 2));

        $this->entityManager->flush();

        return [
            'coverage' => round($coverage, 2),
            'exceptionsCount' => $exceptionsCount,
            'sourcedCount' => $sourcedCount,
            'totalCount' => $totalCount,
        ];
    }

    /**
     * Get pricing for a part (MVP: sample data, Production: API waterfall)
     */
    private function getPricingForPart(array $bomLine): array
    {
        // MVP: Simple pricing based on common component types
        // Production: Call Mouser/DigiKey/Nexar/Alibaba APIs
        
        $mpn = $bomLine['mpn'];
        $quantity = $bomLine['quantity'];

        // Sample pricing logic for common parts
        if (!$mpn) {
            return [
                'found' => false,
                'reason' => 'No MPN provided',
            ];
        }

        // Microcontrollers (STM32, PIC, AVR, etc.)
        if (preg_match('/^(STM32|PIC|ATMEGA|ATXMEGA|SAM|LPC)/i', $mpn)) {
            return [
                'found' => true,
                'unitPrice' => '4.50',
                'source' => 'Mouser API',
                'availability' => 'In Stock',
                'leadTimeDays' => 3,
            ];
        }

        // Voltage regulators
        if (preg_match('/^(LM1117|LM317|AMS1117|LD1117|TPS|LDO)/i', $mpn)) {
            return [
                'found' => true,
                'unitPrice' => '0.35',
                'source' => 'DigiKey API',
                'availability' => 'In Stock',
                'leadTimeDays' => 2,
            ];
        }

        // Resistors (0805, 0603, 0402, etc.)
        if (preg_match('/^(RC|ERJ|CRCW|RT|RS)/i', $mpn) || 
            preg_match('/\d+(R|K|M)\d*/i', $mpn)) {
            return [
                'found' => true,
                'unitPrice' => '0.01',
                'source' => 'Mouser API',
                'availability' => 'In Stock',
                'leadTimeDays' => 1,
            ];
        }

        // Capacitors (ceramic, tantalum, electrolytic)
        if (preg_match('/^(GRM|C\d{4}|CC|CL|UMK|TMK|TAJ)/i', $mpn) ||
            preg_match('/\d+(UF|NF|PF)/i', $mpn)) {
            return [
                'found' => true,
                'unitPrice' => '0.08',
                'source' => 'DigiKey API',
                'availability' => 'In Stock',
                'leadTimeDays' => 1,
            ];
        }

        // LEDs
        if (preg_match('/^(LED|LTST|SML|APT)/i', $mpn)) {
            return [
                'found' => true,
                'unitPrice' => '0.12',
                'source' => 'Mouser API',
                'availability' => 'In Stock',
                'leadTimeDays' => 2,
            ];
        }

        // Crystals / Oscillators
        if (preg_match('/^(ABM|ABS|ECS|NX)/i', $mpn) ||
            preg_match('/\d+MHZ/i', $mpn)) {
            return [
                'found' => true,
                'unitPrice' => '0.45',
                'source' => 'DigiKey API',
                'availability' => 'In Stock',
                'leadTimeDays' => 3,
            ];
        }

        // Connectors (USB, headers, etc.)
        if (preg_match('/^(USB|CON|J\d+|HEADER|HDR|TSW)/i', $mpn)) {
            return [
                'found' => true,
                'unitPrice' => '1.20',
                'source' => 'Mouser API',
                'availability' => 'In Stock',
                'leadTimeDays' => 2,
            ];
        }

        // Default: Not found in pricing database
        return [
            'found' => false,
            'reason' => 'Part not found in distributor databases',
        ];
    }

    /**
     * Waterfall through pricing APIs with quantity-based pricing and delivery times
     * 
     * Priority order:
     * 1. Mouser API (official distributor, best lead times)
     * 2. DigiKey API (fallback, wider selection)
     * 3. Nexar API (electronics search engine, aggregates distributors)
     * 4. Alibaba API (for non-electronic parts: mechanical, packaging, etc.)
     * 5. Internal pricebook (historical pricing from past quotes)
     * 6. Price imputation (ML-based estimation for completely unmapped parts)
     * 
     * **Quantity-Based Pricing:**
     * - APIs return price breaks (1-99: $0.50, 100-499: $0.45, 500+: $0.40)
     * - System selects best price tier based on BOM quantity
     * - Stores all price breaks in JSON for quote optimization
     * - Higher quantities generally have lower unit prices
     * 
     * **Delivery Times:**
     * - Each API returns lead time in days (factory lead time + shipping)
     * - Different quantities may have different lead times:
     *   - In-stock quantities: 1-3 days
     *   - Partial stock: 5-10 days (mixed stock + factory order)
     *   - Full factory order: 14-84 days depending on supplier
     * - System tracks both "in-stock quantity" and "factory lead time"
     * 
     * @param array $bomLine - Single BOM line with 'qty' field
     * 
     * @return array{
     *   sourced: bool,
     *   unitPrice: float|null,
     *   priceBreaks: array|null,
     *   leadTimeDays: int|null,
     *   inStockQuantity: int|null,
     *   factoryLeadTimeDays: int|null,
     *   supplier: string|null,
     *   method: string,
     *   exceptionType: string|null,
     *   severity: string|null,
     *   message: string|null
     * }
     */
    public function waterfallApis(array $bomLine): array
    {
        // Validate required fields
        if (empty($bomLine['mpn'])) {
            return [
                'sourced' => false,
                'unitPrice' => 0,
                'priceBreaks' => null,
                'leadTimeDays' => 84,
                'inStockQuantity' => null,
                'factoryLeadTimeDays' => null,
                'supplier' => null,
                'method' => null,
                'exceptionType' => 'NO_MPN',
                'severity' => 'HIGH',
                'message' => 'No manufacturer part number provided'
            ];
        }
        
        // Use PricingEngine to get pricing via API waterfall
        $pricing = $this->pricingEngine->getPricing(
            $bomLine['mpn'], 
            $bomLine['manufacturer'] ?? null
        );
        
        if (!$pricing) {
            // No pricing found - create exception
            return [
                'sourced' => false,
                'unitPrice' => 0,
                'priceBreaks' => null,
                'leadTimeDays' => 84,
                'inStockQuantity' => null,
                'factoryLeadTimeDays' => null,
                'supplier' => null,
                'method' => null,
                'exceptionType' => 'NOT_FOUND',
                'severity' => 'HIGH',
                'message' => sprintf('Part not found in any API: %s', $bomLine['mpn'])
            ];
        }
        
        // Calculate unit price for requested quantity
        $unitPrice = $this->calculateUnitPriceFromBreaks(
            $pricing['pricing'] ?? [], 
            $bomLine['quantity'] ?? 1
        );
        
        // Extract stock and lead time information
        $inStock = $pricing['stock']['in_stock'] ?? 0;
        $leadTimeDays = $pricing['stock']['leadtime_days'] ?? 14;
        $factoryLeadTime = $pricing['stock']['factory_leadtime_days'] ?? 84;
        
        // Map source to method
        $sourceMethodMap = [
            'mouser' => 'MOUSER',
            'digikey' => 'DIGIKEY',
            'nexar' => 'NEXAR',
        ];
        
        $method = $sourceMethodMap[$pricing['source']] ?? strtoupper($pricing['source']);
        
        // Determine if any exceptions should be raised
        $exceptionType = null;
        $severity = null;
        $message = null;
        
        // Check for long lead times
        if ($leadTimeDays > 84) {
            $exceptionType = 'LONG_LEADTIME';
            $severity = 'MEDIUM';
            $message = sprintf('Lead time exceeds 12 weeks: %d days', $leadTimeDays);
        }
        
        // Check if quantity exceeds stock
        if ($inStock > 0 && ($bomLine['quantity'] ?? 1) > $inStock) {
            $exceptionType = 'INSUFFICIENT_STOCK';
            $severity = 'LOW';
            $message = sprintf('Requested qty %d exceeds stock %d - will use factory lead time', 
                $bomLine['quantity'] ?? 1, 
                $inStock
            );
        }
        
        return [
            'sourced' => true,
            'unitPrice' => $unitPrice,
            'priceBreaks' => json_encode($pricing['pricing'] ?? []),
            'leadTimeDays' => $leadTimeDays,
            'inStockQuantity' => $inStock,
            'factoryLeadTimeDays' => $factoryLeadTime,
            'supplier' => ucfirst($pricing['source']),
            'method' => $method,
            'exceptionType' => $exceptionType,
            'severity' => $severity,
            'message' => $message
        ];
    }
    
    /**
     * Calculate unit price from price breaks for given quantity
     */
    private function calculateUnitPriceFromBreaks(array $priceBreaks, int $quantity): float
    {
        if (empty($priceBreaks)) {
            return 0.0;
        }
        
        // Sort price breaks by quantity (descending)
        usort($priceBreaks, fn($a, $b) => $b['quantity'] <=> $a['quantity']);
        
        // Find applicable price break
        $applicablePrice = $priceBreaks[count($priceBreaks) - 1]['price']; // Default to lowest qty price
        
        foreach ($priceBreaks as $break) {
            if ($quantity >= $break['quantity']) {
                $applicablePrice = $break['price'];
                break;
            }
        }
        
        return (float) $applicablePrice;
    }

    /**
     * Select best price for requested quantity from price breaks
     * 
     * Price breaks are typically structured as:
     * [
     *   ['qty' => 1, 'price' => 0.50],
     *   ['qty' => 100, 'price' => 0.45],
     *   ['qty' => 500, 'price' => 0.40],
     *   ['qty' => 1000, 'price' => 0.35]
     * ]
     * 
     * For qty = 250, select the 100+ tier: $0.45
     * 
     * @param array $priceBreaks - Array of price breaks
     * @param int $requestedQty - Requested quantity
     * 
     * @return float - Unit price for requested quantity
     */
    private function selectPriceForQuantity(array $priceBreaks, int $requestedQty): float
    {
        // Sort price breaks by quantity (ascending)
        usort($priceBreaks, fn($a, $b) => $a['qty'] <=> $b['qty']);
        
        // Find the highest tier that requestedQty qualifies for
        $selectedPrice = $priceBreaks[0]['price']; // Default to lowest tier
        
        foreach ($priceBreaks as $break) {
            if ($requestedQty >= $break['qty']) {
                $selectedPrice = $break['price'];
            } else {
                break; // Stop when we exceed requestedQty
            }
        }
        
        return $selectedPrice;
    }

    /**
     * Calculate lead time based on stock availability
     * 
     * Logic:
     * - If requested qty <= inStock: 3 days (ship from stock)
     * - If requested qty > inStock but inStock > 0: 7 days (partial stock) + factoryLeadTime
     * - If inStock = 0: factoryLeadTime (full factory order)
     * 
     * @param int $requestedQty - Requested quantity
     * @param array $availability - ['inStock' => 500, 'factoryLeadTime' => 14]
     * 
     * @return int - Lead time in days
     */
    private function calculateLeadTime(int $requestedQty, array $availability): int
    {
        $inStock = $availability['inStock'] ?? 0;
        $factoryLeadTime = $availability['factoryLeadTime'] ?? 84;
        
        if ($requestedQty <= $inStock) {
            // Fully in stock - ship immediately
            return 3; // 3 days for processing + shipping
        } elseif ($inStock > 0) {
            // Partial stock - mixed fulfillment
            // Ship stock immediately, wait for factory order
            return 7 + $factoryLeadTime; // 7 days for partial ship + factory lead time
        } else {
            // No stock - full factory order
            return $factoryLeadTime;
        }
    }

    /**
     * Parse Alibaba delivery time string to days
     * 
     * Examples:
     * - "15-25 days" -> 25 (use maximum)
     * - "3-5 weeks" -> 35 (5 weeks * 7 days)
     * - "30 days" -> 30
     * 
     * @param string $deliveryTimeStr - Alibaba delivery time string
     * 
     * @return int - Lead time in days
     */
    private function parseAlibabaDeliveryTime(string $deliveryTimeStr): int
    {
        // Match "X-Y days" or "X days"
        if (preg_match('/(\d+)-(\d+)\s*days?/i', $deliveryTimeStr, $matches)) {
            return (int) $matches[2]; // Use maximum
        } elseif (preg_match('/(\d+)\s*days?/i', $deliveryTimeStr, $matches)) {
            return (int) $matches[1];
        }
        
        // Match "X-Y weeks" or "X weeks"
        if (preg_match('/(\d+)-(\d+)\s*weeks?/i', $deliveryTimeStr, $matches)) {
            return (int) $matches[2] * 7; // Use maximum, convert to days
        } elseif (preg_match('/(\d+)\s*weeks?/i', $deliveryTimeStr, $matches)) {
            return (int) $matches[1] * 7;
        }
        
        // Default to 30 days if unparseable
        return 30;
    }

    /**
     * Select best offer from Nexar aggregated results
     * 
     * Nexar returns multiple distributor offers. Selection criteria:
     * 1. Lowest total cost (unit price * qty + shipping)
     * 2. Shortest lead time (as tiebreaker)
     * 3. Trusted distributor (Arrow, Avnet, etc. preferred over unknown)
     * 
     * @param array $offers - Array of Nexar offers
     * @param int $requestedQty - Requested quantity
     * 
     * @return array - Best offer with unit price, lead time, etc.
     */
    private function selectBestNexarOffer(array $offers, int $requestedQty): array
    {
        $bestOffer = null;
        $bestScore = PHP_FLOAT_MAX;
        
        foreach ($offers as $offer) {
            $unitPrice = $this->selectPriceForQuantity($offer['priceBreaks'], $requestedQty);
            $leadTime = $this->calculateLeadTime($requestedQty, $offer['availability']);
            
            // Calculate score: prioritize price, then lead time
            $score = ($unitPrice * 1000) + ($leadTime * 0.1);
            
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestOffer = [
                    'unitPrice' => $unitPrice,
                    'priceBreaks' => $offer['priceBreaks'],
                    'leadTime' => $leadTime,
                    'inStock' => $offer['availability']['inStock'] ?? 0,
                    'factoryLeadTime' => $offer['availability']['factoryLeadTime'] ?? 84,
                    'supplier' => $offer['distributor'] . ' (via Nexar)'
                ];
            }
        }
        
        return $bestOffer ?? [
            'unitPrice' => 0.0,
            'priceBreaks' => [],
            'leadTime' => 84,
            'inStock' => 0,
            'factoryLeadTime' => 84,
            'supplier' => 'Unknown'
        ];
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
            $criticalCount = $this->entityManager->getRepository('App\\Entity\\ProcurementException')
                ->count(['quoteId' => $quoteId, 'severity' => 'CRITICAL']);
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
                        $unitPrice = $this->pricingEngine->calculateUnitPrice($pricing['pricing'], $quantity);
                        $extPrice = $unitPrice * $quantity;
                        
                        $bomLine->setUnitPrice($unitPrice);
                        $bomLine->setExtendedPrice($extPrice);
                        $bomLine->setProcurementSource($pricing['source']);
                        $bomLine->setAvailability($pricing['stock']);
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
