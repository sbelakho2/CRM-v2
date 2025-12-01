<?php

namespace App\Service;

use App\Entity\Quote;
use App\Entity\BomLine;
use App\Entity\ProcurementException;
use App\Repository\QuoteRepository;
use App\Repository\BomLineRepository;
use App\Repository\ProcurementExceptionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * QuoteCoPilotService
 * 
 * Automated quote generation from BOM uploads.
 * 
 * Core workflow:
 * 1. Parse BOM file (CSV, Excel, or Altium/KiCad exports)
 * 2. Process each line through API waterfall:
 *    - Mouser API (first choice - official distributor)
 *    - DigiKey API (fallback)
 *    - Nexar API (electronics search engine)
 *    - Alibaba API (for non-electronic components)
 *    - Internal pricebook (historical pricing)
 *    - Price imputation (ML-based estimation for unmapped parts)
 * 3. Calculate coverage % (sourced vs total line items)
 * 4. Generate procurement exceptions report
 * 5. Check auto-publish criteria (>90% coverage, no critical exceptions)
 * 6. Create Quote entity with all BomLine children
 * 
 * Auto-publish rules:
 * - Coverage >= 90%
 * - No CRITICAL severity exceptions
 * - All high-value parts sourced (>$50 unit price)
 * - Lead time < 12 weeks for all parts
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
        private HtsClassificationService $htsClassificationService
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
        // TODO: Implement auto-quote generation
        // 
        // Steps:
        // 1. Parse BOM file:
        //    $bomData = $this->parseBom($bomFilePath);
        // 
        // 2. Create Quote entity:
        //    $quote = new Quote();
        //    $quote->setCompanyId($companyId);
        //    $quote->setContactId($contactId);
        //    $quote->setStatus('DRAFT');
        //    $quote->setCreatedAt(new \DateTime());
        //    $quote->setRfqId($metadata['rfq_id'] ?? null);
        //    $this->entityManager->persist($quote);
        //    $this->entityManager->flush(); // Get quote ID
        // 
        // 3. Process BOM through API waterfall:
        //    $results = $this->processBom($bomData, $quote->getId());
        // 
        // 4. Calculate coverage:
        //    $coverage = $results['coverage'];
        // 
        // 5. Check auto-publish criteria:
        //    $autoPublish = $this->checkAutoPublishCriteria($quote->getId(), $coverage);
        //    if ($autoPublish) {
        //        $quote->setStatus('PUBLISHED');
        //        $quote->setPublishedAt(new \DateTime());
        //    }
        // 
        // 6. Flush changes:
        //    $this->entityManager->flush();
        // 
        // 7. Return summary:
        //    return [
        //        'quoteId' => $quote->getId(),
        //        'coverage' => round($coverage, 2),
        //        'autoPublished' => $autoPublish,
        //        'exceptionsCount' => $results['exceptionsCount'],
        //        'bomLineCount' => count($bomData)
        //    ];

        throw new \RuntimeException('Feature not yet implemented');
    }

    /**
     * Parse BOM file (CSV, Excel, Altium, KiCad)
     * 
     * @param string $bomFilePath - Path to BOM file
     * 
     * @return array - Array of BOM lines with standardized fields
     * [
     *   ['designator' => 'C1', 'mpn' => 'GRM155R71C104KA88D', 'manufacturer' => 'Murata', 'qty' => 10, ...],
     *   ['designator' => 'R1', 'mpn' => 'RC0402FR-0710KL', 'manufacturer' => 'Yageo', 'qty' => 5, ...],
     * ]
     */
    public function parseBom(string $bomFilePath): array
    {
        // Check file extension (case-insensitive)
        $ext = strtolower(pathinfo($bomFilePath, PATHINFO_EXTENSION));
        
        // Also check MIME type for uploaded files
        $mimeType = '';
        if (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($bomFilePath);
        }
        
        // Accept CSV files by extension or MIME type
        $isValidCsv = ($ext === 'csv') || 
                      (strpos($mimeType, 'text/') === 0) || 
                      (strpos($mimeType, 'text/csv') !== false) ||
                      (strpos($mimeType, 'text/plain') !== false);
        
        if (!$isValidCsv) {
            throw new \RuntimeException(sprintf(
                'Only CSV format is currently supported. Excel/Altium/KiCad support coming soon. (Detected: ext=%s, mime=%s)',
                $ext,
                $mimeType
            ));
        }

        $bomLines = [];
        $handle = fopen($bomFilePath, 'r');
        
        if ($handle === false) {
            throw new \RuntimeException('Could not open BOM file');
        }

        // Read header row
        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            throw new \RuntimeException('BOM file is empty or invalid');
        }

        // Normalize headers to lowercase for case-insensitive matching
        $headers = array_map('strtolower', $headers);
        $headers = array_map('trim', $headers);

        // Map column indices
        $columnMap = $this->mapBomColumns($headers);

        // Read data rows
        $lineNumber = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;
            
            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            $bomLine = [
                'lineNumber' => $lineNumber,
                'designator' => $this->getCellValue($row, $columnMap['designator']),
                'mpn' => $this->getCellValue($row, $columnMap['mpn']),
                'manufacturer' => $this->getCellValue($row, $columnMap['manufacturer']),
                'description' => $this->getCellValue($row, $columnMap['description']),
                'quantity' => (int)($this->getCellValue($row, $columnMap['quantity']) ?: 1),
                'value' => $this->getCellValue($row, $columnMap['value']),
            ];

            // Normalize MPN (uppercase, trim)
            if ($bomLine['mpn']) {
                $bomLine['mpn'] = strtoupper(trim($bomLine['mpn']));
            }

            $bomLines[] = $bomLine;
        }

        fclose($handle);

        if (empty($bomLines)) {
            throw new \RuntimeException('No valid BOM lines found in file');
        }

        return $bomLines;
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
        // TODO: Implement API waterfall with quantity-based pricing and delivery times
        // 
        // Steps:
        // 1. Try Mouser API:
        //    $mouseResult = $this->callMouserApi($bomLine['mpn'], $bomLine['qty']);
        //    if ($mouseResult['found']) {
        //        // Mouser returns:
        //        // - priceBreaks: [['qty' => 1, 'price' => 0.50], ['qty' => 100, 'price' => 0.45], ...]
        //        // - availability: ['inStock' => 500, 'factoryLeadTime' => 14]
        //        
        //        $selectedPrice = $this->selectPriceForQuantity($mouseResult['priceBreaks'], $bomLine['qty']);
        //        $leadTime = $this->calculateLeadTime($bomLine['qty'], $mouseResult['availability']);
        //        
        //        return [
        //            'sourced' => true,
        //            'unitPrice' => $selectedPrice,
        //            'priceBreaks' => json_encode($mouseResult['priceBreaks']),
        //            'leadTimeDays' => $leadTime,
        //            'inStockQuantity' => $mouseResult['availability']['inStock'],
        //            'factoryLeadTimeDays' => $mouseResult['availability']['factoryLeadTime'],
        //            'supplier' => 'Mouser',
        //            'method' => 'MOUSER',
        //            'exceptionType' => null,
        //            'severity' => null,
        //            'message' => null
        //        ];
        //    }
        // 
        // 2. Try DigiKey API:
        //    $digikeyResult = $this->callDigikeyApi($bomLine['mpn'], $bomLine['qty']);
        //    if ($digikeyResult['found']) {
        //        // DigiKey returns similar structure:
        //        // - pricing: [['breakQuantity' => 1, 'unitPrice' => 0.52], ['breakQuantity' => 100, 'unitPrice' => 0.47], ...]
        //        // - quantityAvailable: 1200
        //        // - manufacturer: {leadTime: "12 weeks", standardLeadTime: 84}
        //        
        //        $selectedPrice = $this->selectPriceForQuantity(
        //            array_map(fn($p) => ['qty' => $p['breakQuantity'], 'price' => $p['unitPrice']], $digikeyResult['pricing']),
        //            $bomLine['qty']
        //        );
        //        
        //        $inStock = $digikeyResult['quantityAvailable'] ?? 0;
        //        $factoryLead = $digikeyResult['manufacturer']['standardLeadTime'] ?? 84;
        //        $leadTime = $this->calculateLeadTime($bomLine['qty'], ['inStock' => $inStock, 'factoryLeadTime' => $factoryLead]);
        //        
        //        return [
        //            'sourced' => true,
        //            'unitPrice' => $selectedPrice,
        //            'priceBreaks' => json_encode($digikeyResult['pricing']),
        //            'leadTimeDays' => $leadTime,
        //            'inStockQuantity' => $inStock,
        //            'factoryLeadTimeDays' => $factoryLead,
        //            'supplier' => 'DigiKey',
        //            'method' => 'DIGIKEY',
        //            'exceptionType' => null,
        //            'severity' => null,
        //            'message' => null
        //        ];
        //    }
        // 
        // 3. Try Nexar API:
        //    $nexarResult = $this->callNexarApi($bomLine['mpn'], $bomLine['qty']);
        //    if ($nexarResult['found']) {
        //        // Nexar aggregates multiple distributors, pick best option
        //        $bestOffer = $this->selectBestNexarOffer($nexarResult['offers'], $bomLine['qty']);
        //        return [
        //            'sourced' => true,
        //            'unitPrice' => $bestOffer['unitPrice'],
        //            'priceBreaks' => json_encode($bestOffer['priceBreaks']),
        //            'leadTimeDays' => $bestOffer['leadTime'],
        //            'inStockQuantity' => $bestOffer['inStock'],
        //            'factoryLeadTimeDays' => $bestOffer['factoryLeadTime'],
        //            'supplier' => $bestOffer['supplier'], // e.g., "Arrow (via Nexar)"
        //            'method' => 'NEXAR',
        //            'exceptionType' => null,
        //            'severity' => null,
        //            'message' => null
        //        ];
        //    }
        // 
        // 4. Try Alibaba API:
        //    $alibabaResult = $this->callAlibabaApi($bomLine['description'], $bomLine['qty']);
        //    if ($alibabaResult['found']) {
        //        // Alibaba pricing structure:
        //        // - priceRanges: [['minQty' => 100, 'maxQty' => 999, 'price' => 0.35], ['minQty' => 1000, 'price' => 0.30]]
        //        // - deliveryTime: "15-25 days"
        //        
        //        $selectedPrice = $this->selectPriceForQuantity(
        //            array_map(fn($p) => ['qty' => $p['minQty'], 'price' => $p['price']], $alibabaResult['priceRanges']),
        //            $bomLine['qty']
        //        );
        //        
        //        // Parse delivery time (convert "15-25 days" to numeric)
        //        $leadTime = $this->parseAlibabaDeliveryTime($alibabaResult['deliveryTime']);
        //        
        //        return [
        //            'sourced' => true,
        //            'unitPrice' => $selectedPrice,
        //            'priceBreaks' => json_encode($alibabaResult['priceRanges']),
        //            'leadTimeDays' => $leadTime,
        //            'inStockQuantity' => null, // Alibaba typically doesn't report stock
        //            'factoryLeadTimeDays' => $leadTime,
        //            'supplier' => 'Alibaba',
        //            'method' => 'ALIBABA',
        //            'exceptionType' => 'ALIBABA_SOURCE',
        //            'severity' => 'MEDIUM',
        //            'message' => "Part sourced from Alibaba (non-official channel, verify quality)"
        //        ];
        //    }
        // 
        // 5. Try internal pricebook:
        //    $pricebookResult = $this->queryPricebook($bomLine['mpn'], $bomLine['qty']);
        //    if ($pricebookResult['found']) {
        //        return [
        //            'sourced' => true,
        //            'unitPrice' => $pricebookResult['price'],
        //            'priceBreaks' => json_encode($pricebookResult['priceBreaks']),
        //            'leadTimeDays' => $pricebookResult['historicalLeadTime'],
        //            'inStockQuantity' => null,
        //            'factoryLeadTimeDays' => null,
        //            'supplier' => 'Pricebook',
        //            'method' => 'PRICEBOOK',
        //            'exceptionType' => 'HISTORICAL_PRICING',
        //            'severity' => 'LOW',
        //            'message' => "Using historical pricing from past quotes (verify current availability)"
        //        ];
        //    }
        // 
        // 6. Use price imputation (ML estimation):
        //    $imputedPrice = $this->imputePrice($bomLine);
        //    return [
        //        'sourced' => true,
        //        'unitPrice' => $imputedPrice,
        //        'priceBreaks' => null,
        //        'leadTimeDays' => 84, // 12 weeks default for unmapped
        //        'inStockQuantity' => null,
        //        'factoryLeadTimeDays' => 84,
        //        'supplier' => 'Imputed',
        //        'method' => 'IMPUTED',
        //        'exceptionType' => 'IMPUTED_PRICE',
        //        'severity' => 'HIGH',
        //        'message' => "Price imputed (not found in any API - requires manual verification)"
        //    ];

        throw new \RuntimeException('Feature not yet implemented');
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
        // TODO: Implement auto-publish criteria check
        // 
        // Steps:
        // 1. Check coverage >= 90%:
        //    if ($coverage < 90.0) {
        //        return false;
        //    }
        // 
        // 2. Check no CRITICAL exceptions:
        //    $criticalCount = $this->procurementExceptionRepository->count([
        //        'quoteId' => $quoteId,
        //        'severity' => 'CRITICAL'
        //    ]);
        //    if ($criticalCount > 0) {
        //        return false;
        //    }
        // 
        // 3. Check all high-value parts sourced (>$50 unit price):
        //    $qb = $this->bomLineRepository->createQueryBuilder('bl');
        //    $highValueUnsourced = $qb
        //        ->where('bl.quoteId = :quoteId')
        //        ->andWhere('bl.unitPrice IS NULL OR bl.unitPrice > 50.00')
        //        ->andWhere('bl.sourceMethod = :imputed')
        //        ->setParameter('quoteId', $quoteId)
        //        ->setParameter('imputed', 'IMPUTED')
        //        ->getQuery()
        //        ->getResult();
        //    if (count($highValueUnsourced) > 0) {
        //        return false;
        //    }
        // 
        // 4. Check lead time < 12 weeks (84 days):
        //    $longLeadCount = $this->bomLineRepository->count([
        //        'quoteId' => $quoteId,
        //        // leadTimeDays > 84
        //    ]);
        //    // TODO: Add Doctrine query for leadTimeDays > 84
        //    if ($longLeadCount > 0) {
        //        return false;
        //    }
        // 
        // 5. All criteria met:
        //    return true;

        throw new \RuntimeException('Feature not yet implemented');
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
        // TODO: Implement quote regeneration
        // 
        // Steps:
        // 1. Get all BOM lines for quote
        // 2. Filter for unmapped/imputed parts
        // 3. Re-run API waterfall
        // 4. Update BomLine entities
        // 5. Recalculate coverage
        // 6. Return updated stats

        throw new \RuntimeException('Feature not yet implemented');
    }
}
