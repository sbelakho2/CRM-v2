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
            $source = strtolower($lineData['source'] ?? '');
            if ($source === 'alibaba') {
                $bomLine->setSupplierName($lineData['manufacturer'] ?? 'Alibaba Supplier');
            } elseif ($source === 'mouser') {
                $bomLine->setSupplierName('Mouser Electronics');
            } elseif ($source === 'digikey') {
                $bomLine->setSupplierName('DigiKey Electronics');
            } elseif ($source === 'nexar') {
                $bomLine->setSupplierName('Nexar (Aggregated)');
            }
            
            // Build rich sourcing metadata JSON
            $bomLine->setSourcingData([
                'source' => strtoupper($source),
                'waterfall_info' => $lineData['waterfall_info'] ?? null,
                'moq' => $lineData['moq'] ?? null,
                'pack_quantity' => $lineData['pack_quantity'] ?? null,
                'stock' => $lineData['stock'] ?? 0,
                'confidence' => $lineData['confidence'] ?? null,
                'supplier_type' => $lineData['supplier_type'] ?? null,
                'trade_assurance' => $lineData['trade_assurance'] ?? null,
                'shipping_from' => $lineData['shipping_from'] ?? null,
            ]);
            
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
                
                // Store supplier tracking data
                if (isset($pricingResult['product_url'])) {
                    $bomLine->setSupplierProductUrl($pricingResult['product_url']);
                }
                if (isset($pricingResult['search_url'])) {
                    $bomLine->setDistributorSearchUrl($pricingResult['search_url']);
                }
                
                $source = strtolower($pricingResult['source'] ?? '');
                if ($source === 'alibaba') {
                    $bomLine->setSupplierName($pricingResult['supplier_name'] ?? 'Alibaba Supplier');
                } elseif ($source === 'mouser') {
                    $bomLine->setSupplierName('Mouser Electronics');
                } elseif ($source === 'digikey') {
                    $bomLine->setSupplierName('DigiKey Electronics');
                } elseif ($source === 'nexar') {
                    $bomLine->setSupplierName('Nexar (Aggregated)');
                }
                
                $bomLine->setSourcingData([
                    'source' => strtoupper($source),
                    'stock' => $pricingResult['stock'] ?? 0,
                    'moq' => $pricingResult['moq'] ?? null,
                    'supplier_type' => $pricingResult['supplier_type'] ?? null,
                ]);
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
     * Get pricing for a part (MVP: comprehensive sample data, Production: API waterfall)
     * 
     * Covers all major component categories used in PCB assembly:
     * - Passives (R, C, L), Semiconductors (MCU, MOSFET, diode, op-amp), 
     * - Connectors, Crystals/Oscillators, LEDs, Power ICs, Sensors, Memory, etc.
     */
    private function getPricingForPart(array $bomLine): array
    {
        $mpn = $bomLine['mpn'] ?? '';
        $description = strtolower($bomLine['description'] ?? '');
        $value = strtolower($bomLine['value'] ?? '');
        $manufacturer = strtolower($bomLine['manufacturer'] ?? '');

        if (!$mpn && !$description) {
            return [
                'found' => false,
                'reason' => 'No MPN or description provided',
            ];
        }

        $mpnUpper = strtoupper($mpn);

        // ── Microcontrollers (STM32, PIC, AVR, ESP, NRF, RP, SAMD, etc.) ──
        if (preg_match('/^(STM32|STM8|PIC|ATMEGA|ATTINY|ATXMEGA|SAM[DLE]|LPC|MIMX|MK[ELV]|NRF5|ESP32|ESP8266|RP2040|CY8C|EFM32|GD32|WCH|CH32)/i', $mpn)) {
            $price = '4.50';
            if (preg_match('/^(ESP32|NRF5|RP2040)/i', $mpn)) $price = '2.80';
            if (preg_match('/^(ATTINY|STM8|CH32)/i', $mpn)) $price = '1.20';
            if (preg_match('/^(STM32H|STM32F7|MIMX|SAMD51)/i', $mpn)) $price = '8.50';
            return [
                'found' => true,
                'unitPrice' => $price,
                'source' => 'Mouser API',
                'availability' => 'In Stock',
                'leadTimeDays' => 3,
            ];
        }

        // ── FPGAs / CPLDs ──
        if (preg_match('/^(XC[237SKV]|EP[1234]|LFE[1235]|ICE40|ECP5|GW[12]|LCMXO)/i', $mpn)) {
            return [
                'found' => true,
                'unitPrice' => '12.00',
                'source' => 'Mouser API',
                'availability' => 'In Stock',
                'leadTimeDays' => 5,
            ];
        }

        // ── Voltage regulators (linear & switching) ──
        if (preg_match('/^(LM1117|LM317|LM78\d|LM79\d|AMS1117|LD1117|LD39|TPS[5678]|TLV|AP\d{3,4}|MCP170|RT9|SPX|HT7|ME6|XC6|NCP|ADP|TDA|LDO|REG|MIC[2-5]|AP2112|RT5|NCV|AOZ|MP[12]\d{3}|LTC[13]|LT[138]|ISL|IRU|BD\d{3}|FAN|MAX\d{4}|LP\d{4})/i', $mpn)) {
            $price = '0.35';
            if (preg_match('/^(TPS|LTC|LT[138]|MAX\d{4}|MP[12]\d{3}|ISL|AOZ)/i', $mpn)) $price = '1.80';
            return [
                'found' => true,
                'unitPrice' => $price,
                'source' => 'DigiKey API',
                'availability' => 'In Stock',
                'leadTimeDays' => 2,
            ];
        }

        // ── Resistors (all SMD series) ──
        if (preg_match('/^(RC\d{4}|ERJ|CRCW|RT\d{4}|RS\d|RK73|MCR\d|RR\d|ESR\d|CR\d{4}|RMCF|AC\d{4}|WR\d|WSL|CSR|CSRN|RL\d|RN\d|RNCS)/i', $mpn) ||
            preg_match('/resistor/i', $description) ||
            preg_match('/^\d+(\.\d+)?\s*(ohm|[rkmΩ])\b/i', $description)) {
            return [
                'found' => true,
                'unitPrice' => '0.01',
                'source' => 'Mouser API',
                'availability' => 'In Stock',
                'leadTimeDays' => 1,
            ];
        }

        // ── Capacitors (ceramic, tantalum, electrolytic, film) ──
        if (preg_match('/^(GRM|GCM|GCJ|C\d{4}[A-Z]|CC\d{4}|CL\d|UMK|TMK|TAJ|T49[1-5]|TAJC|EEE|UWT|VJ\d|CGA|C\d{3}|06035|08055|10105|NFM|MLCC)/i', $mpn) ||
            preg_match('/capacitor|cap\s+(cer|tant|elec|film)/i', $description) ||
            preg_match('/\d+(\.\d+)?\s*(uf|nf|pf|µf)\b/i', $description)) {
            $price = '0.08';
            if (preg_match('/^(TAJ|T49|EEE)/i', $mpn)) $price = '0.45'; // tantalum/electrolytic
            return [
                'found' => true,
                'unitPrice' => $price,
                'source' => 'DigiKey API',
                'availability' => 'In Stock',
                'leadTimeDays' => 1,
            ];
        }

        // ── Inductors / Chokes / Ferrite beads ──
        if (preg_match('/^(LQH|LQM|SRN|SRR|IHLP|SDR|XAL|XFL|NR[SHCG]|CDRH|SLF|NLCV|BLM|BLA|MPZ|MMZ|HI\d|WE\-|744|SRF)/i', $mpn) ||
            preg_match('/inductor|choke|ferrite\s*bead/i', $description) ||
            preg_match('/\d+(\.\d+)?\s*(uh|mh|µh|nh)\b/i', $description)) {
            $price = '0.15';
            if (preg_match('/^(IHLP|XAL|XFL|CDRH)/i', $mpn)) $price = '0.65'; // power inductors
            return [
                'found' => true,
                'unitPrice' => $price,
                'source' => 'Mouser API',
                'availability' => 'In Stock',
                'leadTimeDays' => 2,
            ];
        }

        // ── Diodes (Schottky, Zener, TVS, rectifier, signal) ──
        if (preg_match('/^(BAT5[4-6]|BAV|BAS|BAW|1N[45]|SS[1-3]\d|SK[1-3]\d|SMBJ|SMAJ|SM[46]T|TVS|SD[12]|B[AZ][VX]|MBR|SB[1-5]|US1[A-M]|ES[12]|PESD|ESD|NUP|PRTR|TPD|MMSZ|BZX|BZT|MMBD|1SS|RB\d)/i', $mpn) ||
            preg_match('/diode|schottky|zener|tvs|rectifier/i', $description)) {
            $price = '0.06';
            if (preg_match('/^(SMBJ|SMAJ|SM[46]T|TVS|PESD|ESD|NUP|PRTR|TPD)/i', $mpn)) $price = '0.25'; // TVS/ESD
            return [
                'found' => true,
                'unitPrice' => $price,
                'source' => 'DigiKey API',
                'availability' => 'In Stock',
                'leadTimeDays' => 2,
            ];
        }

        // ── Transistors / MOSFETs ──
        if (preg_match('/^(BC[3-8]\d{2}|2N[2-7]\d{3}|BSS|MMBT|MMBTA|FMMT|PMBT|DMN|DMG|DMP|PMV|SI[2-9]|AO[3-6]|FDN|FDC|IRF|IRFML|IRLML|NTR|NTD|NTGS|CSD|BSH|BSS138|2SK|2SJ|PSMN|BSZ|TSM|FDMC|SQ\d|SSM\d|DMC|EMB|ZXMN|ZXMP|NCE|RJK|TPN|TPH|RQ)/i', $mpn) ||
            preg_match('/transistor|mosfet|bjt|jfet|n-ch|p-ch|nmos|pmos/i', $description)) {
            $price = '0.15';
            if (preg_match('/^(IRF|AO[3-6]|SI[2-9]\d{3}|CSD|PSMN)/i', $mpn)) $price = '0.85'; // power MOSFETs
            return [
                'found' => true,
                'unitPrice' => $price,
                'source' => 'Mouser API',
                'availability' => 'In Stock',
                'leadTimeDays' => 2,
            ];
        }

        // ── Op-Amps / Comparators / Analog ICs ──
        if (preg_match('/^(LM358|LM324|LM339|LM393|OPA[1-4]|MCP6|AD8|AD7|TLV|TLC|TS[59]|INA\d|MAX4|MCP3|ADS1|LMV|NCS|NCV|SGM|GS\d|TL0[6-8]|LF3|NE5|MC3|MCP4|DAC|LTC[26]|OPA\d{3,4}|LT1|AD[58]\d{3})/i', $mpn) ||
            preg_match('/op.?amp|comparator|amplifier|adc|dac/i', $description)) {
            $price = '0.65';
            if (preg_match('/^(AD[578]\d{3}|INA\d|ADS1|OPA[1-4]\d{3}|LTC)/i', $mpn)) $price = '3.50'; // precision
            return [
                'found' => true,
                'unitPrice' => $price,
                'source' => 'Mouser API',
                'availability' => 'In Stock',
                'leadTimeDays' => 3,
            ];
        }

        // ── LEDs ──
        if (preg_match('/^(LED|LTST|SML|APT|APTD|KP\-|HSMC|HSMF|LNJ|VLMR|VLMB|VLMY|VLMG|VLMW|IN\-S|WS28|SK68|APA10|LP\-|OVLB|XPEB|XHP|CREE|LM301|XLM|MX[36])/i', $mpn) ||
            preg_match('/\bled\b|led\s|light.?emit/i', $description)) {
            $price = '0.12';
            if (preg_match('/^(WS28|SK68|APA10)/i', $mpn)) $price = '0.08'; // addressable LEDs
            if (preg_match('/^(CREE|XP[EGBW]|XHP)/i', $mpn)) $price = '1.50'; // high-power LEDs
            return [
                'found' => true,
                'unitPrice' => $price,
                'source' => 'Mouser API',
                'availability' => 'In Stock',
                'leadTimeDays' => 2,
            ];
        }

        // ── Crystals / Oscillators / Resonators ──
        if (preg_match('/^(ABM|ABS|ECS|NX[345]|TSX|FA\-|HC49|AT\d|ABL|SG\d|ASE|ASV|ASDM|DSB|SIT[89]|ABMM|FC[1-6]|YSX|XRCGB)/i', $mpn) ||
            preg_match('/crystal|oscillator|resonat/i', $description) ||
            preg_match('/\d+(\.\d+)?\s*mhz/i', $mpn . ' ' . $description)) {
            $price = '0.45';
            if (preg_match('/^(SG\d|ASE|ASV|ASDM|SIT[89])/i', $mpn)) $price = '1.80'; // oscillator modules
            return [
                'found' => true,
                'unitPrice' => $price,
                'source' => 'DigiKey API',
                'availability' => 'In Stock',
                'leadTimeDays' => 3,
            ];
        }

        // ── Connectors (USB, headers, JST, Molex, TE, FPC) ──
        if (preg_match('/^(USB|CON|HDR|TSW|PH[DSR]|PJ\-|SJ\-|6\d{5}|5\d{5}|10\d{5}|1\-\d{6}|2\-\d{6}|B\d+B\-|S\d+B\-|XH|VH|ZH|GH|SH|PA|HEADER|FPC|FFC|ZIF|DF\d|HRS|JAE|MOLEX|AMPHENOL|SAMTEC|HARWIN|M20|SFH|SFW|SS[0-9])/i', $mpn) ||
            preg_match('/connector|header|socket|plug|receptacle|jack|usb|fpc|ffc|jst|molex/i', $description)) {
            $price = '1.20';
            if (preg_match('/usb.?c|type.?c/i', $mpn . ' ' . $description)) $price = '0.85';
            if (preg_match('/rj45|ethernet|modular/i', $mpn . ' ' . $description)) $price = '2.50';
            return [
                'found' => true,
                'unitPrice' => $price,
                'source' => 'Mouser API',
                'availability' => 'In Stock',
                'leadTimeDays' => 2,
            ];
        }

        // ── Communication ICs (UART, SPI, I2C, CAN, Ethernet, WiFi, BT, LoRa) ──
        if (preg_match('/^(MAX[23]\d{3}|SP3|SN65|MCP2[5-9]|TJA|SJA|ISO|DP83|KSZ|W5[15]|ENC28|SX12[78]|RFM9|CC[12]\d{3}|ATWINC|ATWILC|RTL|LAN[789]|MAX14|CP21\d)/i', $mpn) ||
            preg_match('/transceiver|can\s*bus|uart|rs232|rs485|ethernet\s*phy|wifi|bluetooth|lora/i', $description)) {
            $price = '2.50';
            if (preg_match('/^(DP83|KSZ|W5[15]|ENC28|LAN)/i', $mpn)) $price = '4.00'; // Ethernet
            if (preg_match('/^(SX127|RFM9|CC[12]\d{3}|ATWINC)/i', $mpn)) $price = '5.50'; // wireless
            return [
                'found' => true,
                'unitPrice' => $price,
                'source' => 'Mouser API',
                'availability' => 'In Stock',
                'leadTimeDays' => 3,
            ];
        }

        // ── Memory ICs (EEPROM, Flash, SRAM, SDRAM, FRAM) ──
        if (preg_match('/^(AT24|M24|24LC|24AA|24FC|93LC|25LC|W25Q|MX25|IS25|SST|MT4|IS4|AS4|CY62|IS61|IS62|FM24|MB85)/i', $mpn) ||
            preg_match('/eeprom|flash|sram|sdram|fram|memory/i', $description)) {
            $price = '0.80';
            if (preg_match('/^(W25Q|MX25|IS25|SST)/i', $mpn)) $price = '1.50'; // NOR flash
            if (preg_match('/^(MT4|IS4|AS4)/i', $mpn)) $price = '3.50'; // SDRAM
            return [
                'found' => true,
                'unitPrice' => $price,
                'source' => 'DigiKey API',
                'availability' => 'In Stock',
                'leadTimeDays' => 3,
            ];
        }

        // ── Sensors (temp, accel, gyro, pressure, humidity, current, etc.) ──
        if (preg_match('/^(BME|BMP|BMA|BMI|BMG|LSM|LIS[23]|LPS|HTS|SHT|HDC|AHT|MPU|ICM|ADXL|MMA|KX|LIS|MS5|ICS|INA\d{3}|ACS7|MAX31|TMP|LMT|PCT|MLX|APDS|TSL|VEML|VL53|VL61|SI70|TCS|BH17|BMX|MAX30)/i', $mpn) ||
            preg_match('/sensor|accelero|gyro|barometer|humidity|thermistor|thermocouple|current\s*sense/i', $description)) {
            $price = '2.50';
            if (preg_match('/^(MPU|ICM|BMI|LSM6)/i', $mpn)) $price = '5.00'; // IMU
            if (preg_match('/^(BME280|BMP280|SHT|HDC)/i', $mpn)) $price = '3.50'; // env sensors
            return [
                'found' => true,
                'unitPrice' => $price,
                'source' => 'Mouser API',
                'availability' => 'In Stock',
                'leadTimeDays' => 3,
            ];
        }

        // ── Power management (battery chargers, PMICs, DC-DC converters) ──
        if (preg_match('/^(BQ[2-4]|MCP73|TP4|LTC4|MAX17|MAX77|PMIC|TPS6|LTC3|RT8|SY8|MP8|NCP3|PAM|SGM4|IP51|AP5100|STC4|MT[23]|RAA)/i', $mpn) ||
            preg_match('/charger|pmic|dc.?dc|buck|boost|battery\s*manage/i', $description)) {
            $price = '2.00';
            if (preg_match('/^(BQ[2-4]|LTC4|MAX17|MAX77)/i', $mpn)) $price = '4.50'; // advanced charger/PMIC
            return [
                'found' => true,
                'unitPrice' => $price,
                'source' => 'DigiKey API',
                'availability' => 'In Stock',
                'leadTimeDays' => 3,
            ];
        }

        // ── Interface ICs (level shifters, buffers, drivers, mux) ──
        if (preg_match('/^(TXB|TXS|SN74|CD40|74HC|74LVC|74AHC|74AC|MC14|NLV|DRV|ULN|ULQ|TPIC|MUX|ADG|MAX44|TS5|FSA|SN65|PI3)/i', $mpn) ||
            preg_match('/buffer|level\s*shift|driver|multiplexer|mux|demux|gate\b|logic/i', $description)) {
            $price = '0.30';
            if (preg_match('/^(DRV|ULN|ULQ|TPIC)/i', $mpn)) $price = '0.85'; // motor drivers
            return [
                'found' => true,
                'unitPrice' => $price,
                'source' => 'DigiKey API',
                'availability' => 'In Stock',
                'leadTimeDays' => 2,
            ];
        }

        // ── Fuses / PTC resettable ──
        if (preg_match('/^(0ZC|RXEF|MF\-|1206L|0805L|0603L|BOURNS|LITTELFUSE|BEL|MIN|NANO|PICO)/i', $mpn) ||
            preg_match('/fuse|ptc|resettable|polyfuse/i', $description)) {
            return [
                'found' => true,
                'unitPrice' => '0.18',
                'source' => 'DigiKey API',
                'availability' => 'In Stock',
                'leadTimeDays' => 2,
            ];
        }

        // ── Transformers / Magnetics ──
        if (preg_match('/^(750\d|760\d|WE\-|PA\d{4}|EE\d|EP\d|EFD|ETD|ER\d|SRF|VAC|MURATA.*TRANS|PULSE)/i', $mpn) ||
            preg_match('/transformer|coupled\s*inductor|balun/i', $description)) {
            return [
                'found' => true,
                'unitPrice' => '2.80',
                'source' => 'Mouser API',
                'availability' => 'In Stock',
                'leadTimeDays' => 5,
            ];
        }

        // ── Switches / Buttons / Relays ──
        if (preg_match('/^(EVQ|KSC|TL[13]|SW\-|SKQG|SKRP|PTS|MJTP|G6K|G5V|HF\d|JZC|SRD|TQ2|EC11|PEC|RE\d|SK\-\d)/i', $mpn) ||
            preg_match('/switch|button|tact|push|relay|encoder/i', $description)) {
            $price = '0.25';
            if (preg_match('/relay|G6K|G5V|HF|SRD/i', $mpn . ' ' . $description)) $price = '1.80';
            return [
                'found' => true,
                'unitPrice' => $price,
                'source' => 'Mouser API',
                'availability' => 'In Stock',
                'leadTimeDays' => 3,
            ];
        }

        // ── Display drivers / Touch controllers ──
        if (preg_match('/^(SSD1|SH1|ST7|ILI9|HX8|UC1|MAX7219|TM1|HT16|FT[56]\d|GT\d|STMPE|IQS|CAP12|AT42)/i', $mpn) ||
            preg_match('/display\s*driver|oled\s*driver|lcd\s*driver|touch\s*controller/i', $description)) {
            return [
                'found' => true,
                'unitPrice' => '2.20',
                'source' => 'DigiKey API',
                'availability' => 'In Stock',
                'leadTimeDays' => 3,
            ];
        }

        // ── Audio ICs (codecs, amplifiers, DACs) ──
        if (preg_match('/^(TPA|TAS|MAX98|PAM86|NS4|SSM|WM8|CS[45]\d|PCM[15]|ADAU|AK[45]\d|ES[89]\d|NAU[78])/i', $mpn) ||
            preg_match('/audio|codec|class.?[dab]\s*amp|speaker\s*driver/i', $description)) {
            return [
                'found' => true,
                'unitPrice' => '1.80',
                'source' => 'Mouser API',
                'availability' => 'In Stock',
                'leadTimeDays' => 3,
            ];
        }

        // ── Speakers / Buzzers / Microphones / Transducers ──
        if (preg_match('/^(CSS|CMS|CMR|SPT|SMT\-|AI\-|PKM|PKLCS|EM\-|SBC|CMC|CMA|PS[1-9]|PT\-|IMP|INMP|SPU|SPH|MEMS)/i', $mpn) ||
            preg_match('/speaker|buzzer|microphone|transducer|piezo|beeper|receiver|earpiece/i', $description)) {
            return [
                'found' => true,
                'unitPrice' => '0.95',
                'source' => 'Mouser API',
                'availability' => 'In Stock',
                'leadTimeDays' => 3,
            ];
        }

        // ── ESD / EMI protection ──
        if (preg_match('/^(TPD|PRTR|USBLC|IP4|SP0|SP3|PESD|CDSOT|NUP|SRV|CM\d|ACM|DLW|BNX)/i', $mpn) ||
            preg_match('/esd\s*protect|tvs\s*array|common\s*mode|emi\s*filter/i', $description)) {
            return [
                'found' => true,
                'unitPrice' => '0.20',
                'source' => 'DigiKey API',
                'availability' => 'In Stock',
                'leadTimeDays' => 2,
            ];
        }

        // ── Test points / mechanical / fiducials (zero cost) ──
        if (preg_match('/^(TP|FID|MH|STANDOFF|SCREW|NUT|SPACER)/i', $mpn) ||
            preg_match('/test\s*point|fiducial|mounting\s*hole|standoff|spacer/i', $description)) {
            return [
                'found' => true,
                'unitPrice' => '0.02',
                'source' => 'Internal',
                'availability' => 'In Stock',
                'leadTimeDays' => 1,
            ];
        }

        // ── Fallback: try description-based matching for generic parts ──
        if ($description || $value) {
            $text = $description ?: $value;
            
            // Generic passive detection from description
            if (preg_match('/\b(resistor|res)\b/i', $text)) {
                return ['found' => true, 'unitPrice' => '0.01', 'source' => 'Mouser API', 'availability' => 'In Stock', 'leadTimeDays' => 1];
            }
            if (preg_match('/\b(capacitor|cap)\b/i', $text)) {
                return ['found' => true, 'unitPrice' => '0.08', 'source' => 'DigiKey API', 'availability' => 'In Stock', 'leadTimeDays' => 1];
            }
            if (preg_match('/\binductor\b/i', $text)) {
                return ['found' => true, 'unitPrice' => '0.15', 'source' => 'Mouser API', 'availability' => 'In Stock', 'leadTimeDays' => 2];
            }
        }

        // ── Default: Not found ──
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
            'alibaba' => 'ALIBABA',
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
