<?php

namespace App\Service;

use App\Service\Integration\AlibabaApiClient;
use App\Service\Integration\MouserApiClient;
use App\Service\Integration\DigiKeyApiClient;
use App\Service\Integration\NexarApiClient;
use App\Service\Integration\MultiDistributorSourcingService;
use Psr\Log\LoggerInterface;

/**
 * Pricing Engine - Enhanced with Multi-Distributor Waterfall + AI Imputation
 * 
 * Implements intelligent API waterfall for part pricing with:
 * - Confidence scoring for part matches
 * - Multi-distributor comparison (Alibaba, Mouser, DigiKey, Nexar)
 * - Automatic waterfall when confidence < 80% or stock = 0
 * - Alternative parts visibility (top 3 alternatives)
 * - Lifecycle status tracking (NRND, Obsolete warnings)
 * - Direct search URL generation for transparency
 * - AI-powered price imputation when APIs fail
 * - Quote win probability prediction
 * 
 * Waterfall Strategy:
 * 1. Alibaba API (factory-direct pricing, best bulk rates)
 * 2. Mouser API (authorized distributor, reliable stock)
 * 3. DigiKey API (fallback or if Mouser confidence < 80%)
 * 4. Nexar API (aggregator - multiple distributors)
 * 5. Internal pricebook (historical data)
 * 6. AI Price Imputation (ML-based estimation)
 * 7. Manual override required for unmatched parts
 * 
 * The engine now tracks WHY a distributor was chosen and provides
 * alternatives so users can make informed decisions.
 */
class PricingEngine
{
    public function __construct(
        private AlibabaApiClient $alibabaClient,
        private MouserApiClient $mouserClient,
        private DigiKeyApiClient $digikeyClient,
        private NexarApiClient $nexarClient,
        private MultiDistributorSourcingService $multiDistributor,
        private CurrencyConverter $currencyConverter,
        private PriceImputationService $priceImputation,
        private RiskAdjustedPricingService $riskAdjustedPricing,
        private LoggerInterface $logger
    ) {}

    /**
     * Get pricing for a single part using enhanced API waterfall with confidence scoring
     * 
     * Uses MultiDistributorSourcingService for intelligent distributor selection.
     * Returns alternatives and lifecycle warnings along with the best match.
     * 
     * @param string $mpn The manufacturer part number
     * @param string|null $manufacturer The manufacturer name (optional but improves matching)
     * @param string|null $description The part description (optional but improves confidence)
     * @param array $options Options: ['providers' => ['alibaba','mouser','digikey','nexar']]
     * 
     * @return array|null ['mpn', 'manufacturer', 'description', 'pricing', 'stock', 'source', 
     *                     'confidence', 'alternatives', 'lifecycle_warning', 'search_url', 
     *                     'waterfall_info']
     */
    public function getPricing(string $mpn, ?string $manufacturer = null, ?string $description = null, array $options = []): ?array
    {
        $allowedProviders = $options['providers'] ?? [];
        // Nexar disabled by default — Mouser-only mode
        $useNexar = in_array('nexar', $allowedProviders, true);
        
        // Use multi-distributor service for intelligent waterfall
        $multiResult = $this->multiDistributor->searchPart($mpn, $manufacturer, $description, [
            'providers' => $allowedProviders,
        ]);
        
        if ($multiResult && $multiResult['selected']) {
            $result = $multiResult['selected'];
            $result['source'] = $multiResult['source'];
            $result['alternatives'] = $multiResult['alternatives'] ?? [];
            $result['waterfall_info'] = [
                'triggered' => $multiResult['waterfall_triggered'],
                'reason' => $multiResult['waterfall_reason'],
                'sources_checked' => array_keys($multiResult['all_sources']),
            ];
            
            // Normalise URL keys — MultiDistributor uses _source_url (search page)
            // and individual clients use product_url (exact listing).  Expose both
            // under canonical names so downstream code never sees null.
            if (!isset($result['search_url'])) {
                $result['search_url'] = $result['_source_url'] ?? $result['product_url'] ?? null;
            }
            // product_url already set by Alibaba/DigiKey clients; keep as-is
            
            $this->logger->info('Multi-distributor pricing found', [
                'mpn' => $mpn,
                'source' => $multiResult['source'],
                'confidence' => $result['confidence']['level'] ?? 'N/A',
                'score' => $result['confidence']['score'] ?? 0,
                'waterfall_triggered' => $multiResult['waterfall_triggered'],
                'alternatives_count' => count($result['alternatives']),
            ]);
            
            // Annotate confidence warnings when found via smart fallback
            if (!empty($result['_fallback_method'])) {
                $fbMethod = $result['_fallback_method'];
                $fbLabel = match ($fbMethod) {
                    'cleaned_mpn' => 'Cleaned MPN variant',
                    'base_mpn' => 'Base MPN (packaging suffix stripped)',
                    'keyword_search' => 'Keyword search match',
                    'description_search' => 'Description-based search match',
                    'mfr_keyword_search' => 'Manufacturer + MPN keyword match',
                    default => 'Smart fallback (' . $fbMethod . ')',
                };
                $result['confidence']['warnings'] = array_merge(
                    $result['confidence']['warnings'] ?? [],
                    ['SUGGESTED EQUIVALENT: Found via ' . $fbLabel . '. Verify compatibility.']
                );
                $result['confidence']['requiresReview'] = true;
            }
            
            return $result;
        }
        
        // If multi-distributor service didn't find anything, try Nexar as last resort
        if ($useNexar) {
            $result = $this->nexarClient->searchByPartNumber($mpn);
            
            if ($result) {
                $result['source'] = 'nexar';
                $result['alternatives'] = [];
                $result['waterfall_info'] = [
                    'triggered' => true,
                    'reason' => 'Fallback to Nexar aggregator after Alibaba, Mouser and DigiKey failed',
                    'sources_checked' => ['alibaba', 'mouser', 'digikey', 'nexar'],
                ];
                
                $this->logger->info('Nexar fallback pricing found', [
                    'mpn' => $mpn,
                    'confidence' => $result['confidence']['level'] ?? 'N/A'
                ]);
                return $result;
            }
        }
        
        // Last resort: AI-powered price imputation
        // ─── DISABLED ───────────────────────────────────────────────────
        // AI imputation inflates BOM totals with unreliable ceiling prices.
        // All parts must be sourced from live distributor APIs (Alibaba first).
        // If Alibaba fails, the part should surface as "not found" so the
        // operator can investigate, rather than silently accepting a 3x-cap guess.
        // To re-enable, remove the early-return below.
        // ─────────────────────────────────────────────────────────────────
        $this->logger->info('AI imputation SKIPPED (disabled) — returning null', [
            'mpn' => $mpn,
        ]);
        
        // Return null so the BOM report shows this part as unsourced.
        // Previously this block would call $this->priceImputation->imputePrice()
        // and accept any result with confidence >= 0.5.
        /*
        $imputation = $this->priceImputation->imputePrice([
        /*
            'mpn' => $mpn,
            'manufacturer' => $manufacturer ?? '',
            'description' => $description ?? '',
            'quantity' => 1,
        ]);
        
        if ($imputation['confidence'] >= 0.5) {
            $this->logger->info('AI price imputation used', [
                'mpn' => $mpn,
                'imputed_price' => $imputation['price'],
                'confidence' => $imputation['confidence'],
                'method' => $imputation['method'],
            ]);
            
            return [
                'mpn' => $mpn,
                'manufacturer' => $manufacturer,
                'description' => $description,
                'pricing' => [
                    ['quantity' => 1, 'price' => $imputation['price']],
                ],
                'stock' => 0,
                'source' => 'ai_imputation',
                'confidence' => [
                    'score' => (int) ($imputation['confidence'] * 100),
                    'level' => $imputation['confidence'] >= 0.7 ? 'MEDIUM' : 'LOW',
                    'requiresReview' => true,
                    'reasons' => ['AI-estimated price based on component category and package'],
                    'warnings' => ['Price is ML-estimated, not from distributor API'],
                ],
                'alternatives' => [],
                'lifecycle_warning' => null,
                'search_url' => null,
                'waterfall_info' => [
                    'triggered' => true,
                    'reason' => 'All API sources failed - using ML-based price estimation',
                    'sources_checked' => ['alibaba', 'mouser', 'digikey', 'nexar', 'ai_imputation'],
                ],
                'imputation_factors' => $imputation['factors'],
            ];
        }
        */
        
        $this->logger->warning('No pricing found in any API', ['mpn' => $mpn]);
        
        return null;
    }
    
    /**
     * Get pricing from a specific distributor (for alternative selection)
     * 
     * Used when user manually selects an alternative from a different distributor.
     */
    public function getPricingFromSource(string $mpn, string $source, ?string $manufacturer = null): ?array
    {
        $result = match($source) {
            'alibaba' => $this->alibabaClient->searchByPartNumber($mpn, $manufacturer),
            'mouser' => $this->mouserClient->searchByPartNumber($mpn, $manufacturer),
            'digikey' => $this->digikeyClient->searchByPartNumber($mpn, $manufacturer),
            'nexar' => $this->nexarClient->searchByPartNumber($mpn),
            default => null,
        };
        
        if ($result) {
            $result['source'] = $source;
        }
        
        return $result;
    }

    /**
     * Process entire BOM through pricing waterfall with confidence scoring
     * 
     * Enhanced to track:
     * - Lifecycle warnings (NRND, Obsolete parts)
     * - Alternative parts from multiple distributors
     * - Waterfall trigger reasons for transparency
     * - Direct search URLs for verification
     * 
     * @param array $bomLines Array from BOMParser
     * @param array $options Options: ['providers' => ['alibaba','mouser','digikey','nexar']]
     * @return array ['lines' => processed lines, 'stats' => statistics, 'reviewRequired' => bool]
     */
    public function processBOM(array $bomLines, array $options = []): array
    {
        $allowedProviders = $options['providers'] ?? [];
        $processedLines = [];
        $stats = [
            'total_lines' => count($bomLines),
            'sourced' => 0,
            'unsourced' => 0,
            'total_cost' => 0.0,
            'sources' => [
                'alibaba' => 0,
                'mouser' => 0,
                'digikey' => 0,
                'nexar' => 0,
                'ai_imputation' => 0,
                'manual' => 0,
            ],
            'confidence_breakdown' => [
                'HIGH' => 0,
                'MEDIUM' => 0,
                'LOW' => 0,
                'VERY_LOW' => 0,
            ],
            'requires_review_count' => 0,
            // New stats for enhanced features
            'lifecycle_warnings' => [
                'critical' => 0,  // Obsolete, EOL
                'warning' => 0,   // NRND, Last Time Buy
            ],
            'waterfall_triggered_count' => 0,
            'with_alternatives_count' => 0,
            'quantity_adjusted_count' => 0, // MOQ/pack adjustments
        ];
        
        foreach ($bomLines as $line) {
            if (empty($line['mpn'])) {
                // Can't price without MPN
                $processedLine = $line;
                $processedLine['status'] = 'no_mpn';
                $processedLine['unit_price'] = 0;
                $processedLine['extended_price'] = 0;
                $processedLine['source'] = null;
                $processedLine['confidence'] = [
                    'score' => 0,
                    'level' => 'VERY_LOW',
                    'requiresReview' => true,
                    'reasons' => ['No MPN provided - manual entry required'],
                    'warnings' => ['CRITICAL: Cannot auto-price without MPN']
                ];
                $processedLine['lifecycle_warning'] = null;
                $processedLine['alternatives'] = [];
                $processedLine['search_url'] = null;
                $processedLines[] = $processedLine;
                $stats['unsourced']++;
                $stats['requires_review_count']++;
                continue;
            }
            
            // Get pricing with confidence scoring, alternatives, and lifecycle info
            $pricing = $this->getPricing(
                $line['mpn'], 
                $line['manufacturer'] ?? null,
                $line['description'] ?? null,
                ['providers' => $allowedProviders]
            );
            
            // ── Alt-MPN primary fallback ──
            // When the primary MPN is unsourced, try the Remark column's alt MPN
            // as a FULL replacement before giving up.  Many BOM files carry the
            // real MPN in the Remark column (e.g. "107 6k 737" → "TAJC107K006RNJ").
            if (!$pricing) {
                $fallbackAltMpn = $this->extractAltMpn($line['remark'] ?? '', $line['mpn']);
                if ($fallbackAltMpn) {
                    $this->logger->info('Primary MPN unsourced, trying Remark alt-MPN as fallback', [
                        'original_mpn' => $line['mpn'],
                        'alt_mpn' => $fallbackAltMpn,
                    ]);
                    
                    $pricing = $this->getPricing($fallbackAltMpn, null, $line['description'] ?? null, ['providers' => $allowedProviders]);
                    
                    // Also try DigiKey directly for the alt MPN (if digikey explicitly allowed)
                    $useDigikey = in_array('digikey', $allowedProviders, true);
                    if (!$pricing && $useDigikey) {
                        try {
                            $dkFallback = $this->digikeyClient->searchByPartNumber($fallbackAltMpn);
                            if ($dkFallback) {
                                $pricing = $dkFallback;
                                $pricing['source'] = 'digikey';
                                $pricing['search_url'] = 'https://www.digikey.com/en/products/filter?keywords=' . urlencode($fallbackAltMpn);
                            }
                        } catch (\Exception $e) {
                            // DigiKey failed, continue
                        }
                    }
                    
                    if ($pricing) {
                        $pricing['alt_mpn_used'] = $fallbackAltMpn;
                        $this->logger->info('Alt-MPN fallback succeeded', [
                            'original_mpn' => $line['mpn'],
                            'alt_mpn' => $fallbackAltMpn,
                            'source' => $pricing['source'] ?? 'unknown',
                        ]);
                    }
                }
            }
            
            if ($pricing) {
                // Successfully sourced
                $processedLine = array_merge($line, $pricing);
                $processedLine['status'] = 'sourced';
                
                // Use stock_quantity (real order qty from BOM) when available,
                // otherwise fall back to per-board quantity
                $requestedQty = (!empty($line['stock_quantity']) && $line['stock_quantity'] > $line['quantity'])
                    ? $line['stock_quantity']
                    : $line['quantity'];
                $moq = $pricing['moq'] ?? 1;
                $packQty = $pricing['pack_quantity'] ?? null;
                $multipleQty = $pricing['multiple_quantity'] ?? null;
                
                // When stock_quantity or firm_quantity (order-multiple) is specified,
                // don't inflate with vendor MOQ/pack — only warn if there's a mismatch.
                $hasStockQty = !empty($line['stock_quantity']) && $line['stock_quantity'] > $line['quantity'];
                $hasFirmQty = !empty($line['firm_quantity']);
                if ($hasStockQty || $hasFirmQty) {
                    // Customer's order qty is firm — use it directly for extended price
                    $effectiveQty = $requestedQty;
                    $adjusted = false;
                    $reason = null;
                    
                    // Still generate warnings for MOQ/pack issues
                    if ($requestedQty < $moq) {
                        $reason = "Note: Requested qty {$requestedQty} is below vendor MOQ {$moq}. Quote uses requested qty.";
                    }
                    
                    $quantityResult = [
                        'effective_quantity' => $effectiveQty,
                        'adjusted' => $adjusted,
                        'reason' => $reason,
                    ];
                } else {
                    $quantityResult = $this->calculateEffectiveQuantity($requestedQty, $moq, $packQty, $multipleQty);
                }
                $effectiveQty = $quantityResult['effective_quantity'];
                
                // Store quantity adjustment info
                $processedLine['requested_quantity'] = $requestedQty;
                $processedLine['effective_quantity'] = $effectiveQty;
                $processedLine['quantity_adjusted'] = $quantityResult['adjusted'];
                $processedLine['quantity_adjustment_reason'] = $quantityResult['reason'];
                $processedLine['moq'] = $moq;
                $processedLine['pack_quantity'] = $packQty;
                
                // Calculate pricing for effective quantity (not requested)
                $unitPrice = $this->calculateUnitPrice($pricing['pricing'], $effectiveQty);
                
                // ── Qty-aware alternative re-evaluation ──
                // The waterfall selects by confidence score, but at the actual order qty
                // a different source may be significantly cheaper.
                $alternatives = $pricing['alternatives'] ?? [];
                foreach ($alternatives as $alt) {
                    $altBreaks = $alt['pricing'] ?? [];
                    if (empty($altBreaks)) continue;
                    $altPrice = $this->calculateUnitPrice($altBreaks, $effectiveQty);
                    if ($altPrice > 0 && $altPrice < $unitPrice * 0.85) {
                        // Alternative is >15% cheaper at this qty — switch
                        $savings = round((1 - $altPrice / $unitPrice) * 100, 1);
                        $altSource = $alt['_source'] ?? $alt['source'] ?? 'unknown';
                        $this->logger->info('Qty-aware re-eval: alternative cheaper at order qty', [
                            'mpn' => $line['mpn'],
                            'original_source' => $processedLine['source'] ?? 'unknown',
                            'alt_source' => $altSource,
                            'original_price' => $unitPrice,
                            'alt_price' => $altPrice,
                            'qty' => $effectiveQty,
                            'savings_pct' => $savings . '%',
                        ]);
                        // Preserve the full alternatives list from original pricing
                        $fullAlternatives = $pricing['alternatives'] ?? [];
                        $pricing = $alt;
                        $pricing['alternatives'] = $fullAlternatives;
                        $unitPrice = $altPrice;
                        $processedLine['source'] = $altSource;
                        // Only update manufacturer/description when switching to a distributor
                        // (DigiKey/Mouser have clean data; Alibaba alts may have worse SEO spam)
                        if (in_array($altSource, ['digikey', 'mouser', 'nexar'])) {
                            if (isset($alt['manufacturer'])) {
                                $processedLine['manufacturer'] = $alt['manufacturer'];
                            }
                            if (isset($alt['description'])) {
                                $processedLine['description'] = $alt['description'];
                            }
                        }
                        if (isset($alt['confidence'])) {
                            $processedLine['confidence'] = $alt['confidence'];
                        }
                    }
                }
                
                // ── Alt-MPN search: use Remark column's cheaper equivalent ──
                // Fix H3: Skip this section if alt-MPN was already matched during the unsourced
                // fallback (lines 316-349) — the second call would overwrite the earlier match.
                if (empty($pricing['alt_mpn_used'])) {
                    $altMpn = $this->extractAltMpn($line['remark'] ?? '', $line['mpn']);
                    if ($altMpn) {
                        $this->logger->info('Searching alternate MPN from BOM remark', [
                            'original_mpn' => $line['mpn'],
                            'alt_mpn' => $altMpn,
                            'original_unit_price' => $unitPrice,
                        ]);
                        
                        // Strategy A: Full waterfall search for alt MPN
                        $altPricing = $this->getPricing($altMpn, null, $line['description'] ?? null, ['providers' => $allowedProviders]);
                        $altUnitPrice = $altPricing ? $this->calculateUnitPrice($altPricing['pricing'], $effectiveQty) : 0;
                        
                        // Strategy B: Direct DigiKey search for alt MPN (if digikey allowed)
                        // (waterfall may miss DigiKey if Alibaba scores well)
                        $useDigikeyForAlt = empty($allowedProviders) || in_array('digikey', $allowedProviders, true);
                        if ($useDigikeyForAlt) {
                            try {
                            $digiKeyAlt = $this->digikeyClient->searchByPartNumber($altMpn);
                            if ($digiKeyAlt) {
                                $dkAltPrice = $this->calculateUnitPrice($digiKeyAlt['pricing'] ?? [], $effectiveQty);
                                if ($dkAltPrice > 0 && ($altUnitPrice <= 0 || $dkAltPrice < $altUnitPrice)) {
                                    $altPricing = $digiKeyAlt;
                                    $altPricing['source'] = 'digikey';
                                    $altPricing['search_url'] = 'https://www.digikey.com/en/products/filter?keywords=' . urlencode($altMpn);
                                    $altUnitPrice = $dkAltPrice;
                                    $this->logger->info('DigiKey direct search for alt MPN found cheaper price', [
                                        'alt_mpn' => $altMpn, 'dk_price' => $dkAltPrice,
                                    ]);
                                }
                            }
                        } catch (\Exception $e) {
                            // DigiKey direct search failed, continue with waterfall result
                        }
                        } // end if ($useDigikeyForAlt)
                        
                        // Also try DigiKey directly for the PRIMARY MPN if currently using Alibaba
                        if ($useDigikeyForAlt && ($processedLine['source'] ?? '') === 'alibaba' && $unitPrice > 0.01) {
                            try {
                                $dkPrimary = $this->digikeyClient->searchByPartNumber($line['mpn']);
                                if ($dkPrimary) {
                                    $dkPrimaryPrice = $this->calculateUnitPrice($dkPrimary['pricing'] ?? [], $effectiveQty);
                                    if ($dkPrimaryPrice > 0 && $dkPrimaryPrice < $unitPrice) {
                                        $this->logger->info('DigiKey cheaper for primary MPN at order qty', [
                                            'mpn' => $line['mpn'], 'alibaba_price' => $unitPrice, 'dk_price' => $dkPrimaryPrice,
                                        ]);
                                        $dkPrimary['search_url'] = 'https://www.digikey.com/en/products/filter?keywords=' . urlencode($line['mpn']);
                                        $pricing = $dkPrimary;
                                        $unitPrice = $dkPrimaryPrice;
                                        $processedLine['source'] = 'digikey';
                                    }
                                }
                            } catch (\Exception $e) {
                                // continue
                            }
                        }
                        
                        if ($altPricing && $altUnitPrice > 0 && ($unitPrice <= 0 || $altUnitPrice < $unitPrice)) {
                            $savings = $unitPrice > 0 ? round((1 - $altUnitPrice / $unitPrice) * 100, 1) : 0;
                            $this->logger->info('Alt MPN is CHEAPER — switching', [
                                'original_mpn' => $line['mpn'],
                                'alt_mpn' => $altMpn,
                                'original_price' => $unitPrice,
                                'alt_price' => $altUnitPrice,
                                'savings_pct' => $savings . '%',
                            ]);
                            
                            // Swap to the cheaper alternative
                            $pricing = $altPricing;
                            $unitPrice = $altUnitPrice;
                            $processedLine['alt_mpn_used'] = $altMpn;
                            $processedLine['alt_mpn_savings_pct'] = $savings;
                            $processedLine['source'] = $altPricing['source'] ?? $processedLine['source'];
                            
                            if (isset($altPricing['confidence'])) {
                                $processedLine['confidence'] = $altPricing['confidence'];
                                $processedLine['confidence']['reasons'][] = "Used alt MPN {$altMpn} ({$savings}% cheaper)";
                            }
                        } else {
                            $this->logger->debug('Alt MPN not cheaper', [
                                'alt_mpn' => $altMpn,
                                'alt_price' => $altUnitPrice,
                                'original_price' => $unitPrice,
                            ]);
                        }
                    }
                } else {
                    $this->logger->debug('Skipping alt-MPN re-search — already matched during unsourced fallback', [
                        'alt_mpn' => $pricing['alt_mpn_used'],
                    ]);
                }
                
                // ── BOM-embedded price sanity check ──
                // When the BOM carries a verified unit price and the API price is
                // astronomically higher, cap the output.  This covers China-domestic
                // factory-priced commodity passives that no Western API can match.
                $bomEmbeddedPrice = (float)($line['unit_price'] ?? 0);
                if ($bomEmbeddedPrice > 0 && $unitPrice > 0 && $unitPrice > $bomEmbeddedPrice * 3.5) {
                    $isCommodityPassive = $this->isCommodityPassive(
                        $line['mpn'],
                        $line['description'] ?? '',
                        $line['category'] ?? ''
                    );
                    if ($isCommodityPassive) {
                        // Cap at 3x BOM price — acknowledges some API premium but
                        // prevents 50-300x blowups from niche-distributor list prices
                        $cappedPrice = $bomEmbeddedPrice * 3.0;
                        $this->logger->info('BOM-price ceiling applied for commodity passive', [
                            'mpn' => $line['mpn'],
                            'api_price' => $unitPrice,
                            'bom_price' => $bomEmbeddedPrice,
                            'capped_price' => $cappedPrice,
                            'ratio_before' => round($unitPrice / $bomEmbeddedPrice, 1),
                        ]);
                        $unitPrice = $cappedPrice;
                        $processedLine['bom_price_capped'] = true;
                    }
                }
                
                $processedLine['unit_price'] = $unitPrice;
                $processedLine['extended_price'] = $unitPrice * $effectiveQty;
                $processedLine['currency'] = $this->resolveCurrencyFromPriceBreaks($pricing['pricing'] ?? []);
                
                // ── Passive component price sanity check ──
                // Standard passives (CRCW, RC, CL, GRM, C08, C16 etc.) cost $0.001-$0.50.
                // If Alibaba returns >$1/ea for a passive, check alternatives for saner price.
                $passivePriceCap = $this->getPassivePriceCap($line['mpn'], $processedLine['description'] ?? '');
                if ($passivePriceCap !== null && $unitPrice > $passivePriceCap && ($processedLine['source'] ?? '') === 'alibaba') {
                    // Try each alternative for a cheaper, saner result
                    foreach ($processedLine['alternatives'] ?? [] as $alt) {
                        $altBreaks = $alt['pricing'] ?? [];
                        if (empty($altBreaks)) continue;
                        $altPrice = $this->calculateUnitPrice($altBreaks, $effectiveQty);
                        if ($altPrice > 0 && $altPrice <= $passivePriceCap) {
                            $altSource = $alt['_source'] ?? $alt['source'] ?? 'unknown';
                            $this->logger->info('Passive price sanity: switching to cheaper alternative', [
                                'mpn' => $line['mpn'],
                                'alibaba_price' => $unitPrice,
                                'alt_price' => $altPrice,
                                'alt_source' => $altSource,
                                'cap' => $passivePriceCap,
                            ]);
                            $unitPrice = $altPrice;
                            $processedLine['unit_price'] = $unitPrice;
                            $processedLine['extended_price'] = $unitPrice * $effectiveQty;
                            $processedLine['source'] = $altSource;
                            if (isset($alt['manufacturer'])) {
                                $processedLine['manufacturer'] = $alt['manufacturer'];
                            }
                            if (isset($alt['description'])) {
                                $processedLine['description'] = $alt['description'];
                            }
                            $processedLine['confidence']['warnings'][] = sprintf(
                                'Alibaba price $%.2f exceeded passive cap $%.2f; used %s at $%.4f',
                                $processedLine['unit_price'], $passivePriceCap, $altSource, $altPrice
                            );
                            break;
                        }
                    }
                }
                
                // Add warning if quantity was adjusted
                if ($quantityResult['adjusted']) {
                    $processedLine['confidence']['warnings'] = array_merge(
                        $processedLine['confidence']['warnings'] ?? [],
                        [$quantityResult['reason']]
                    );
                }
                
                $stats['sourced']++;
                $stats['total_cost'] += $processedLine['extended_price'];
                // Use processedLine source which is updated through all swap paths
                // (qty-aware re-eval, alt-MPN swap, DigiKey direct override)
                $source = $processedLine['source'] ?? $pricing['source'] ?? 'manual';
                if (!isset($stats['sources'][$source])) {
                    $stats['sources'][$source] = 0;
                }
                $stats['sources'][$source]++;
                
                // Track confidence — use processedLine which reflects all swaps
                $confidenceLevel = $processedLine['confidence']['level'] ?? $pricing['confidence']['level'] ?? 'MEDIUM';
                $stats['confidence_breakdown'][$confidenceLevel]++;
                
                if ($processedLine['confidence']['requiresReview'] ?? $pricing['confidence']['requiresReview'] ?? false) {
                    $stats['requires_review_count']++;
                }
                
                // Track lifecycle warnings
                $lifecycleWarning = $pricing['lifecycle_warning'] ?? null;
                $processedLine['lifecycle_warning'] = $lifecycleWarning;
                if ($lifecycleWarning === 'critical') {
                    $stats['lifecycle_warnings']['critical']++;
                    // Critical lifecycle always requires review
                    $processedLine['confidence']['requiresReview'] = true;
                    $stats['requires_review_count']++;
                } elseif ($lifecycleWarning === 'warning') {
                    $stats['lifecycle_warnings']['warning']++;
                }
                
                // Track alternatives
                $alternatives = $pricing['alternatives'] ?? [];
                $processedLine['alternatives'] = $alternatives;
                if (!empty($alternatives)) {
                    $stats['with_alternatives_count']++;
                }
                
                // Track waterfall triggers
                if ($pricing['waterfall_info']['triggered'] ?? false) {
                    $stats['waterfall_triggered_count']++;
                }
                
                // Track quantity adjustments (MOQ/pack)
                if ($quantityResult['adjusted']) {
                    $stats['quantity_adjusted_count']++;
                }
                
                // Store URLs for transparency
                // search_url  = distributor search page (internal, for quote review UI)
                // product_url = exact product listing   (internal, for sourcing audit)
                $processedLine['search_url'] = $pricing['search_url']
                    ?? $pricing['_source_url']
                    ?? $pricing['product_url']
                    ?? $this->buildGenericSearchUrl($line['mpn']);
                $processedLine['product_url'] = $pricing['product_url'] ?? null;
                
                // ── Alibaba presentation cleanup ──
                // When source is Alibaba, the 'manufacturer' field contains supplier
                // credibility info and 'description' contains SEO-stuffed listing titles.
                // Enrich from alternatives (DigiKey/Mouser) or clean up for presentation.
                $processedLine = $this->enrichAlibabaPresentation($processedLine, $line);

                // ── C5: Risk-adjusted pricing analysis ──
                // Calculate risk factors (stock, lead time, lifecycle, supplier) and annotate
                // the processed line with risk grade, warnings, and adjusted cost.
                try {
                    $riskAnalysis = $this->riskAdjustedPricing->calculateRiskAdjustedCost(
                        $pricing,
                        $effectiveQty,
                        $processedLine['source'] ?? 'unknown'
                    );
                    $processedLine['risk_analysis'] = $riskAnalysis;

                    // If risk grade is D or F, flag the line for manual review
                    $riskGrade = $riskAnalysis['risk_grade'] ?? '';
                    if (in_array($riskGrade, ['D', 'F'], true)) {
                        $processedLine['confidence']['requiresReview'] = true;
                        $processedLine['confidence']['warnings'] = array_merge(
                            $processedLine['confidence']['warnings'] ?? [],
                            $riskAnalysis['warnings'] ?? []
                        );
                        $stats['requires_review_count']++;
                    }
                } catch (\Exception $e) {
                    // Risk analysis is non-critical — log and continue
                    $this->logger->warning('Risk-adjusted pricing analysis failed', [
                        'mpn' => $line['mpn'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }

            } else {
                // Could not source - mark for manual pricing
                $processedLine = $line;
                $processedLine['status'] = 'not_found';
                $processedLine['unit_price'] = null; // null instead of 0 to indicate "needs input"
                $processedLine['extended_price'] = null;
                $processedLine['source'] = null;
                $processedLine['confidence'] = [
                    'score' => 0,
                    'level' => 'VERY_LOW',
                    'requiresReview' => true,
                    'reasons' => ['Part not found in any supplier API'],
                    'warnings' => ['Manual price entry required']
                ];
                $processedLine['lifecycle_warning'] = null;
                $processedLine['alternatives'] = [];
                $processedLine['search_url'] = $this->buildGenericSearchUrl($line['mpn']);
                $stats['unsourced']++;
                $stats['requires_review_count']++;
            }
            
            $processedLines[] = $processedLine;
        }
        
        // Calculate coverage percentage
        $stats['coverage_percent'] = $stats['total_lines'] > 0 
            ? round(($stats['sourced'] / $stats['total_lines']) * 100, 2)
            : 0;
        
        // Calculate high-confidence percentage
        $highConfidence = $stats['confidence_breakdown']['HIGH'] ?? 0;
        $stats['high_confidence_percent'] = $stats['sourced'] > 0
            ? round(($highConfidence / $stats['sourced']) * 100, 2)
            : 0;
            
        // Calculate lifecycle health score
        $stats['lifecycle_health_percent'] = $stats['sourced'] > 0
            ? round((($stats['sourced'] - $stats['lifecycle_warnings']['critical'] - $stats['lifecycle_warnings']['warning']) / $stats['sourced']) * 100, 2)
            : 100;
        
        return [
            'lines' => $processedLines,
            'stats' => $stats,
            'reviewRequired' => $stats['requires_review_count'] > 0,
        ];
    }
    
    /**
     * Build a generic search URL for parts not found in APIs
     */
    private function buildGenericSearchUrl(string $mpn): string
    {
        $encoded = urlencode($mpn);
        return "https://www.findchips.com/search/{$encoded}";
    }

    /**
     * Return a reasonable unit price cap for passive components, or null if not a passive.
     *
     * Standard passives (resistors, ceramic caps, inductors) are commodity parts
     * that cost $0.001-$0.50 at qty 1. Alibaba sometimes returns $1-$10/ea due to
     * SEO-spam listings targeting IC searches, not the actual passive part.
     */
    private function getPassivePriceCap(string $mpn, string $description): ?float
    {
        $upper = strtoupper($mpn);
        $descUpper = strtoupper($description);

        // Resistor MPN prefixes: CRCW, RC, ERJ, MCR, CRSN, RK73, TNPW, WSL, etc.
        $resistorPrefixes = ['CRCW', 'RC0', 'RC1', 'ERJ', 'MCR', 'CSRN', 'RK73', 'TNPW', 'WSL', 'HCJ', 'RES '];
        foreach ($resistorPrefixes as $pfx) {
            if (str_starts_with($upper, $pfx)) {
                return 0.50; // Standard resistor should never exceed $0.50/ea
            }
        }

        // Capacitor MPN prefixes: GRM, CL, C06, C08, C16, C20, C57, VJ, 06035A, etc.
        $capPrefixes = ['GRM', 'CL2', 'CL1', 'C060', 'C080', 'C161', 'C201', 'C575', 'VJ1', '0603'];
        foreach ($capPrefixes as $pfx) {
            if (str_starts_with($upper, $pfx)) {
                return 1.00; // Standard cap should rarely exceed $1.00/ea
            }
        }

        // Description-based detection
        if (preg_match('/\bRES\s+SMD\b|\bRES\s+\d/i', $descUpper)) {
            return 0.50;
        }
        if (preg_match('/\bCAP\s+CER\b|\bMLCC\b/i', $descUpper)) {
            return 1.00;
        }

        return null; // Not a passive — no cap
    }

    /**
     * Enrich Alibaba-sourced lines for better presentation quality.
     *
     * When Alibaba is the selected source the raw data has two problems:
     *   - 'manufacturer' = supplier credibility string (not actual manufacturer)
     *   - 'description'  = SEO-stuffed listing title (keyword spam)
     *
     * We fix both by:
     *   1. Pulling real manufacturer + description from alternative DigiKey/Mouser results
     *   2. If no alternative data exists, attempt to clean the Alibaba description
     */
    private function enrichAlibabaPresentation(array $processedLine, array $originalBomLine): array
    {
        $source = $processedLine['source'] ?? '';
        $manufacturer = $processedLine['manufacturer'] ?? '';

        // Detect whether this line needs Alibaba cleanup:
        // 1. Source is alibaba, OR
        // 2. Manufacturer field contains Alibaba supplier credibility info
        //    (happens when qty-re-eval switches source but keeps old metadata)
        $needsCleanup = ($source === 'alibaba')
            || $this->looksLikeAlibabaSupplierInfo($manufacturer);

        if (!$needsCleanup) {
            return $processedLine;
        }

        $mpn = $processedLine['mpn'] ?? '';

        // ── Step 1: Pull manufacturer/description from DigiKey/Mouser alternatives ──
        $realManufacturer = null;
        $cleanDescription = null;
        foreach ($processedLine['alternatives'] ?? [] as $alt) {
            $altSource = $alt['_source'] ?? $alt['source'] ?? '';
            if (in_array($altSource, ['digikey', 'mouser', 'nexar'])) {
                if (!empty($alt['manufacturer']) && !$realManufacturer) {
                    $realManufacturer = $alt['manufacturer'];
                }
                if (!empty($alt['description']) && !$cleanDescription) {
                    $cleanDescription = $alt['description'];
                }
                if ($realManufacturer && $cleanDescription) {
                    break;
                }
            }
        }

        // ── Step 2: Manufacturer resolution ──
        // Priority: DigiKey/Mouser > MPN prefix lookup > BOM data > sanitized Alibaba
        $processedLine['alibaba_supplier'] = $processedLine['manufacturer'] ?? '';

        if ($realManufacturer) {
            $processedLine['manufacturer'] = $realManufacturer;
        } else {
            // Try MPN prefix lookup
            $prefixMfr = $this->lookupManufacturerByMpn($mpn);
            if ($prefixMfr) {
                $processedLine['manufacturer'] = $prefixMfr;
            } elseif (!empty($originalBomLine['manufacturer'])) {
                $processedLine['manufacturer'] = $originalBomLine['manufacturer'];
            } else {
                // Last resort: show "—" instead of "Verified Supplier, CN, 4 yrs..."
                $processedLine['manufacturer'] = '—';
            }
        }

        // ── Step 3: Description resolution ──
        // Priority: DigiKey/Mouser > sanitized Alibaba > BOM description > MPN
        if ($cleanDescription) {
            $processedLine['alibaba_raw_description'] = $processedLine['description'] ?? '';
            $processedLine['description'] = $cleanDescription;
        } else {
            $sanitized = $this->sanitizeAlibabaDescription(
                $processedLine['description'] ?? '',
                $mpn
            );
            $bomDesc = $originalBomLine['description'] ?? '';

            // If sanitized is still poor quality, prefer BOM description
            if ($this->isDescriptionLowQuality($sanitized, $mpn) && !empty($bomDesc) && strlen($bomDesc) > 3) {
                // Combine BOM description with package info from Alibaba if available
                $processedLine['alibaba_raw_description'] = $processedLine['description'] ?? '';
                $processedLine['description'] = $this->buildBomFallbackDescription($bomDesc, $mpn);
            } else {
                $processedLine['alibaba_raw_description'] = $processedLine['description'] ?? '';
                $processedLine['description'] = $sanitized;
            }
        }

        // ── Step 4: Final cleanup — remove MPN from description (already in MPN column) ──
        $desc = $processedLine['description'];
        if (!empty($mpn) && strlen($mpn) >= 6) {
            $desc = str_ireplace($mpn, '', $desc);
            $desc = preg_replace('/\s{2,}/', ' ', $desc);
            $desc = trim($desc, " \t\n\r\0\x0B,.-;:");
        }
        $processedLine['description'] = $desc;

        return $processedLine;
    }

    /**
     * Look up manufacturer from MPN prefix.
     *
     * Covers major passive/active component naming conventions.
     * Returns null if no match found.
     */
    private function lookupManufacturerByMpn(string $mpn): ?string
    {
        $upper = strtoupper($mpn);

        // Map of (prefix → manufacturer) — most specific first
        $prefixMap = [
            // TDK MLCCs: C + 4-digit metric size
            'C5750' => 'TDK Corporation', 'C4532' => 'TDK Corporation',
            'C3225' => 'TDK Corporation', 'C3216' => 'TDK Corporation',
            'C2012' => 'TDK Corporation', 'C1608' => 'TDK Corporation',
            'C1005' => 'TDK Corporation', 'C0603' => 'TDK Corporation',
            // KEMET MLCCs: C + imperial size + C/X/Y
            'C0805C' => 'KEMET', 'C0402C' => 'KEMET', 'C0603C' => 'KEMET',
            'C1206C' => 'KEMET', 'C1210C' => 'KEMET',
            // Murata
            'GRM' => 'Murata Electronics', 'GCM' => 'Murata Electronics',
            // Samsung
            'CL21' => 'Samsung Electro-Mechanics', 'CL10' => 'Samsung Electro-Mechanics',
            'CL31' => 'Samsung Electro-Mechanics', 'CL05' => 'Samsung Electro-Mechanics',
            // KYOCERA AVX
            '06035A' => 'KYOCERA AVX', '0402YD' => 'KYOCERA AVX',
            '08055A' => 'KYOCERA AVX', '12065A' => 'KYOCERA AVX',
            // Vishay
            'CRCW' => 'Vishay Dale', 'CRMA' => 'Vishay Dale',
            'VJ18' => 'Vishay Vitramon', 'VJ08' => 'Vishay Vitramon',
            'VJ06' => 'Vishay Vitramon', 'VJ12' => 'Vishay Vitramon',
            'IRF' => 'Vishay Siliconix', 'IRFP' => 'Vishay Siliconix',
            'TCMT' => 'Vishay Semiconductor',
            // YAGEO
            'RC0603' => 'YAGEO', 'RC0805' => 'YAGEO', 'RC0402' => 'YAGEO',
            'RC1206' => 'YAGEO', 'RC1210' => 'YAGEO', 'RC2512' => 'YAGEO',
            // Stackpole
            'CSRN' => 'Stackpole Electronics', 'HCJ' => 'Stackpole Electronics',
            'RMCF' => 'Stackpole Electronics',
            // Nippon Chemi-Con (aluminum polymer/electrolytic)
            'EMVY' => 'Nippon Chemi-Con', 'EMVH' => 'Nippon Chemi-Con',
            'EMVE' => 'Nippon Chemi-Con', 'EKZE' => 'Nippon Chemi-Con',
            // Panasonic
            'DB2S' => 'Panasonic', 'EEE' => 'Panasonic',
            // STMicroelectronics
            'SBRD' => 'STMicroelectronics', 'STPS' => 'STMicroelectronics',
            'ST13' => 'STMicroelectronics', 'STM32' => 'STMicroelectronics',
            // Texas Instruments
            'TL28' => 'Texas Instruments', 'LMV' => 'Texas Instruments',
            'LM3' => 'Texas Instruments', 'TPS' => 'Texas Instruments',
            // Würth Elektronik (transformers)
            '7503' => 'Würth Elektronik', '7447' => 'Würth Elektronik',
            // TE Connectivity
            '3521' => 'TE Connectivity',
            // ON Semiconductor
            'MMBT' => 'onsemi', 'NCP' => 'onsemi',
            // Microchip
            'MCP' => 'Microchip Technology', 'PIC' => 'Microchip Technology',
        ];

        foreach ($prefixMap as $prefix => $mfr) {
            if (str_starts_with($upper, strtoupper($prefix))) {
                return $mfr;
            }
        }

        return null;
    }

    /**
     * Check whether a sanitized description is still low quality.
     *
     * Returns true if the description looks like garbage:
     *   - Just the MPN repeated
     *   - Contains other IC part numbers (ASI4UE, STM32F4xx mixed in)
     *   - Very short / generic
     *   - Still contains supplier / spam phrases
     */
    private function isDescriptionLowQuality(string $desc, string $mpn): bool
    {
        // Too short to be useful
        if (strlen($desc) < 10) {
            return true;
        }
        // Just the MPN (nothing else useful)
        if (strcasecmp(trim($desc), trim($mpn)) === 0) {
            return true;
        }
        // Check for variant MPNs that share our prefix but aren't our exact part
        // E.g., "IC TCMT1107 TCMT1109" when part is TCMT1103 → garbage
        $mpnPrefix = strtoupper(substr($mpn, 0, min(4, strlen($mpn))));
        if ($mpnPrefix && preg_match_all('/\b(' . preg_quote($mpnPrefix) . '\w{2,})\b/i', $desc, $variantMatches)) {
            foreach ($variantMatches[1] as $variant) {
                if (strcasecmp($variant, $mpn) !== 0 && strlen($variant) > 5) {
                    // Found a variant MPN that isn't our part → Alibaba mashup
                    return true;
                }
            }
        }
        // Contains other IC part numbers that aren't this MPN
        $otherParts = preg_match_all('/\b[A-Z]{2,5}\d{3,}[A-Z0-9\-]+\b/', $desc, $matches);
        if ($otherParts > 0) {
            $foreignParts = 0;
            foreach ($matches[0] as $found) {
                if (stripos($mpn, substr($found, 0, 6)) === false) {
                    $foreignParts++;
                }
            }
            if ($foreignParts >= 2) {
                return true; // Multiple unrelated part numbers = Alibaba SEO mashup
            }
        }
        // Known bad patterns that survived sanitization
        if (preg_match('/(?:BOM\s+Service|Spare\s+Parts|Electronic\s+Spare)/i', $desc)) {
            return true;
        }
        // Alibaba-style verbose padding
        if (preg_match('/(?:7\s*inch\s+Reel|Thick\s+Film\s+Chip|Smd\s+Smt)/i', $desc)) {
            return true;
        }

        return false;
    }

    /**
     * Build a decent description from BOM data when Alibaba text is garbage.
     *
     * Enhances short BOM descriptions (like "68 uF") with package info from MPN.
     */
    private function buildBomFallbackDescription(string $bomDesc, string $mpn): string
    {
        // Try to extract package size from MPN (e.g., 0603, 0805, 1206, 2512, 2220)
        $package = null;
        if (preg_match('/(0402|0603|0805|1206|1210|1812|2010|2512|2220)/', $mpn, $m)) {
            $package = $m[1];
        }

        $desc = ucfirst(trim($bomDesc));

        // Add package if not already in description
        if ($package && stripos($desc, $package) === false) {
            $desc .= ' ' . $package;
        }

        // Add SMD if it's a surface-mount package and not already mentioned
        if ($package && !preg_match('/\b(?:SMD|SMT|Surface\s+Mount)\b/i', $desc)) {
            $desc .= ' SMD';
        }

        return $desc;
    }

    /**
     * Detect whether a "manufacturer" string looks like Alibaba supplier credibility info
     * rather than a real manufacturer name.
     *
     * Examples that return true:
     *   "Verified Supplier, CN, 4 yrs, 4.9/5.0 (36 reviews)"
     *   "CN, 7 yrs, 4.9/5.0 (51 reviews)"
     *   "United Kingdom, 4 yrs, 4.7/5.0 (8 reviews)"
     */
    private function looksLikeAlibabaSupplierInfo(string $manufacturer): bool
    {
        if (empty($manufacturer)) {
            return false;
        }
        // Alibaba supplier strings contain "yrs," or "reviews)" or "Verified Supplier"
        if (preg_match('/\byrs\b|\breviews?\)|\bVerified\s+Supplier\b/i', $manufacturer)) {
            return true;
        }
        // Country code pattern: "CN, N yrs" or "FR, N yrs"
        if (preg_match('/^[A-Z]{2},\s*\d+\s*yrs/i', $manufacturer)) {
            return true;
        }
        return false;
    }

    /**
     * Sanitize Alibaba product listing titles for customer-facing output.
     *
     * Removes common SEO spam patterns while preserving useful technical info:
     *   Input:  "Hainayu IC electronic component integrated circuit in stock BOM list
     *            ASI4UE-E-G1-SR STM32F401RCT6 EMVY350ADA221MHA0G"
     *   Output: "EMVY350ADA221MHA0G"
     */
    private function sanitizeAlibabaDescription(string $description, string $mpn): string
    {
        if (empty($description)) {
            return $description;
        }

        // ── Remove the MPN itself from description (it's in its own column) ──
        if (!empty($mpn) && strlen($mpn) >= 5) {
            $description = str_ireplace($mpn, '', $description);
        }

        // ── Remove SEO spam patterns ──
        $spamPatterns = [
            '/\b(?:hot\s+sell(?:ing)?|in\s+stock|original|new\s*&?\s*original|one\s+stop\s+service|premium)\b/i',
            '/\b(?:electronic\s+component(?:s)?|integrated\s+circuit(?:s)?|ic\s+chips?)\b/i',
            '/\b(?:BOM\s+(?:list|Service)|authorized\s+distribut(?:or|ion))\b/i',
            '/\b(?:Electronic\s+Spare\s+Parts?|Spare\s+Parts?)\b/i',
            // Alibaba supplier brand names (not real manufacturers)
            '/\b(?:hainayu|jeking|chinook|CZSKU|FYX|Kecheng|YingXinYuan)\b/i',
            '/CZSKU:\w+/i',
            // Generic filler
            '/\b(?:high\s+quality|brand\s+new|factory\s+direct|wholesale|best\s+price)\b/i',
            '/\b(?:100%\s*original|genuine|authentic|professional)\b/i',
            // Alibaba SEO padding phrases
            '/\b(?:Surface\s+Mount|SMD\s+Ceramic)\s+High\s+Voltage\b/i',
            '/\b\d+\s*inch\s+Reel\b/i', // "7 inch Reel"
            '/\bThick\s+Film\s+Chip\s+Resistors?\b/i',
            '/\bThin\s+Film\s+Chip\s+Resistors?\b/i',
            '/\bPassive\s+Components?\b/i',
            '/\bMultilayer\s+Ceramic\s+Capacitors?\b/i',
            '/\bMlcc\s+Smd\s+Smt\b/i',
        ];

        $cleaned = $description;
        foreach ($spamPatterns as $pattern) {
            $cleaned = preg_replace($pattern, '', $cleaned);
        }

        // Remove stray other IC part numbers (Alibaba often mashes multiple MPNs together)
        // Keep words that contain the MPN's first 4 chars as they might be related variants
        $mpnPrefix = strtoupper(substr($mpn, 0, 4));
        $cleaned = preg_replace_callback(
            '/\b[A-Z]{2,5}\d{3,}[A-Z0-9\-]{3,}\b/',
            function ($match) use ($mpnPrefix) {
                $found = strtoupper($match[0]);
                // Keep if it shares prefix with our MPN (related variant)
                if (str_starts_with($found, $mpnPrefix)) {
                    return $match[0];
                }
                return ''; // Remove unrelated part numbers
            },
            $cleaned
        );

        // Clean up whitespace and punctuation
        $cleaned = preg_replace('/\s{2,}/', ' ', $cleaned);
        $cleaned = trim($cleaned, " \t\n\r\0\x0B,.-;:/()");

        // If almost nothing left, fall back to MPN
        if (strlen($cleaned) < 8 && !empty($mpn)) {
            return $mpn;
        }

        return $cleaned;
    }

    /**
     * Apply manual price overrides to processed BOM lines
     * 
     * @param array $processedLines The processed BOM lines
     * @param array $overrides Array of ['line_index' => ['unit_price' => float, 'notes' => string]]
     * @return array Updated lines with overrides applied
     */
    public function applyManualOverrides(array $processedLines, array $overrides): array
    {
        foreach ($overrides as $lineIndex => $override) {
            if (!isset($processedLines[$lineIndex])) {
                continue;
            }
            
            $line = &$processedLines[$lineIndex];
            
            if (isset($override['unit_price']) && $override['unit_price'] > 0) {
                $line['unit_price'] = (float) $override['unit_price'];
                $line['extended_price'] = $line['unit_price'] * ($line['quantity'] ?? 1);
                $line['source'] = 'manual';
                $line['status'] = 'manual_override';
                $line['manual_notes'] = $override['notes'] ?? null;
                
                // Update confidence to reflect manual verification
                $line['confidence'] = [
                    'score' => 100,
                    'level' => 'HIGH',
                    'requiresReview' => false,
                    'reasons' => ['Manually verified and priced'],
                    'warnings' => []
                ];
            }
            
            if (isset($override['verified']) && $override['verified']) {
                // User verified the auto-match is correct
                $line['status'] = 'verified';
                $line['confidence']['requiresReview'] = false;
                $line['confidence']['reasons'][] = 'Manually verified as correct';
            }
        }
        
        return $processedLines;
    }
    
    /**
     * Recalculate totals after manual overrides
     */
    public function recalculateStats(array $processedLines): array
    {
        $stats = [
            'total_lines' => count($processedLines),
            'sourced' => 0,
            'unsourced' => 0,
            'total_cost' => 0.0,
            'sources' => [
                'alibaba' => 0,
                'mouser' => 0,
                'digikey' => 0,
                'nexar' => 0,
                'ai_imputation' => 0,
                'manual' => 0,
            ],
            'requires_review_count' => 0,
        ];
        
        foreach ($processedLines as $line) {
            if (in_array($line['status'], ['sourced', 'manual_override', 'verified'])) {
                $stats['sourced']++;
                $stats['total_cost'] += ($line['extended_price'] ?? 0);
                
                $source = $line['source'] ?? 'manual';
                if (isset($stats['sources'][$source])) {
                    $stats['sources'][$source]++;
                }
            } else {
                $stats['unsourced']++;
            }
            
            if ($line['confidence']['requiresReview'] ?? false) {
                $stats['requires_review_count']++;
            }
        }
        
        $stats['coverage_percent'] = $stats['total_lines'] > 0
            ? round(($stats['sourced'] / $stats['total_lines']) * 100, 2)
            : 0;
        
        return $stats;
    }

    /**
     * Calculate unit price for given quantity based on price breaks
     * 
     * Price breaks are quantity thresholds with corresponding prices.
     * The customer gets the price for the highest quantity break they meet.
     * Example: breaks at 1=$10, 10=$8, 100=$5
     *   - Qty 5 gets $10 (meets 1 break)
     *   - Qty 15 gets $8 (meets 10 break)
     *   - Qty 200 gets $5 (meets 100 break)
     */
    private function calculateUnitPrice(array $priceBreaks, int $quantity): float
    {
        if (empty($priceBreaks)) {
            return 0.0;
        }
        
        // Normalise key variants: accept 'price', 'unit_price', or 'unitPrice'
        $priceBreaks = array_map(function (array $b): array {
            if (!isset($b['price'])) {
                $b['price'] = $b['unit_price'] ?? $b['unitPrice'] ?? 0.0;
            }
            return $b;
        }, $priceBreaks);
        
        // Sort price breaks by quantity (ascending) - lowest qty first
        usort($priceBreaks, fn($a, $b) => ($a['quantity'] ?? 0) <=> ($b['quantity'] ?? 0));
        
        // Default to the smallest quantity break price (most expensive)
        $applicablePrice = (float) ($priceBreaks[0]['price'] ?? 0.0);
        
        // Find the best applicable price break (highest quantity the customer qualifies for)
        foreach ($priceBreaks as $break) {
            if ($quantity >= ($break['quantity'] ?? 0)) {
                // Customer qualifies for this break - use its price
                $applicablePrice = (float) ($break['price'] ?? 0.0);
            } else {
                // Customer doesn't meet this break threshold - stop checking
                // (since breaks are sorted ascending, all remaining breaks require more qty)
                break;
            }
        }
        
        // ── Bulk extrapolation: when qty exceeds highest tier by 2x+, apply volume discount ──
        // This reflects real-world negotiated pricing below last posted break.
        // Uses a log-linear learning curve: each doubling of qty reduces price ~15%.
        // Fix H4: Use array_key_last() instead of end() to avoid mutating internal array pointer.
        $highestBreak = $priceBreaks[array_key_last($priceBreaks)];
        $highestQty = (int)($highestBreak['quantity'] ?? 1);
        $highestPrice = (float)($highestBreak['price'] ?? 0);
        
        if ($quantity > $highestQty * 2 && $highestPrice > 0 && count($priceBreaks) >= 2) {
            // Calculate the learning rate from the existing breaks
            $lowestBreak = reset($priceBreaks);
            $lowestQty = max(1, (int)($lowestBreak['quantity'] ?? 1));
            $lowestPrice = (float)($lowestBreak['price'] ?? 0);
            
            if ($lowestPrice > $highestPrice && $highestQty > $lowestQty) {
                // Natural learning rate from the existing price breaks
                $qtyRatio = log($highestQty / $lowestQty);
                $priceRatio = log($lowestPrice / $highestPrice);
                $learningRate = $qtyRatio > 0 ? min($priceRatio / $qtyRatio, 0.5) : 0.15;
                
                // Extrapolate: how much further beyond highest break?
                $extraRatio = log($quantity / $highestQty);
                $discount = exp(-$learningRate * $extraRatio);
                
                // Cap discount at 40% below the last posted tier (floor = 60% of last price)
                $extrapolatedPrice = $highestPrice * max($discount, 0.60);
                
                if ($extrapolatedPrice < $applicablePrice) {
                    $applicablePrice = round($extrapolatedPrice, 6);
                }
            }
        }
        
        return $applicablePrice;
    }
    
    /**
     * Detect whether a component is a commodity passive (resistor, capacitor, inductor).
     *
     * These parts have near-zero BOM prices at volume ($0.0005/ea) that no Western
     * distributor API can match.  Used to gate BOM-price-ceiling logic.
     */
    private function isCommodityPassive(string $mpn, string $description, string $category): bool
    {
        $text = strtolower($mpn . ' ' . $description . ' ' . $category);
        
        // Category / description keywords
        $passiveKeywords = [
            'resistor', 'capacitor', 'inductor', 'cap ', 'res ', 'ind ',
            'ceramic cap', 'chip resistor', 'chip capacitor', 'mlcc',
            'tantalum', 'ferrite', 'choke',
        ];
        foreach ($passiveKeywords as $kw) {
            if (str_contains($text, $kw)) return true;
        }
        
        // MPN pattern detection for well-known passive series
        $mpnUpper = strtoupper($mpn);
        $passivePatterns = [
            '/^GRM\d/i',            // Murata MLCC
            '/^CL\d{2}[A-Z]/i',     // Samsung MLCC
            '/^C\d{4}C/i',          // KEMET MLCC (C0603C, C0805C…)
            '/^06\d{2}\d?[A-Z]/i',  // AVX 0603/0402 MLCC
            '/^CRGP\d/i',           // TE Connectivity chip resistor
            '/^CPF[\-A]?\d/i',      // TE precision film resistor
            '/^RGT\d/i',            // Susumu thin film resistor
            '/^TNPW\d/i',           // Vishay precision resistor
            '/^WAF\d/i',            // UniOhm thick film resistor
            '/^WGF\d/i',            // UniOhm resistor
            '/^0[0-9]{3}W[A-Z]?[0-9F]/i',  // UniOhm 0603WAF, 0805W8F
            '/^RC\d{4}/i',          // Yageo chip resistor
            '/^ERJ\-/i',            // Panasonic chip resistor
            '/^CRCW\d/i',           // Vishay chip resistor
            '/^PNM\d{4}E/i',       // Panasonic chip resistor
            '/^TAJC?\d/i',          // AVX tantalum
            '/^SPH\d{4}/i',         // Sunlord inductor
            '/^74\d{6,}/i',         // Würth inductor
        ];
        foreach ($passivePatterns as $pattern) {
            if (preg_match($pattern, $mpn)) return true;
        }
        
        return false;
    }
    
    /**
     * Extract an alternate MPN from the BOM's remark/notes column.
     *
     * Many BOMs list the "design" part (e.g. Murata GRM188R60J476ME15D) as the MPN
     * and the actual factory-substituted part (e.g. Samsung CL10A476MQ8QRNC) in a
     * Remark column. This method returns the alt MPN if it looks like a valid part number.
     *
     * @param string $remark  The remark/notes field from the BOM
     * @param string $primaryMpn  The primary MPN to avoid returning the same thing
     * @return string|null The alternate MPN, or null if not found/not useful
     */
    private function extractAltMpn(string $remark, string $primaryMpn): ?string
    {
        $remark = trim($remark);
        if ($remark === '' || strlen($remark) < 4) {
            return null;
        }
        
        // Normalize for comparison
        $normalizedPrimary = strtoupper(preg_replace('/[\s\-]/', '', $primaryMpn));
        $normalizedRemark = strtoupper(preg_replace('/[\s\-]/', '', $remark));
        
        // Skip if remark is the same as the primary MPN
        if ($normalizedRemark === $normalizedPrimary) {
            return null;
        }
        
        // Check if remark looks like a valid MPN (mix of letters + digits, min 5 chars)
        if (strlen($remark) >= 5 && preg_match('/[A-Za-z]/', $remark) && preg_match('/[0-9]/', $remark)) {
            // Reject freetext: MPNs have 0-1 spaces, freetext has many.
            // Use space count (not str_word_count which treats digits as word separators)
            $spaceCount = substr_count($remark, ' ');
            if ($spaceCount <= 1) {
                return $remark;
            }
        }
        
        // Try extracting an MPN-like token from longer remarks
        // e.g., "Use CL10A476MQ8QRNC instead" → "CL10A476MQ8QRNC"
        if (preg_match('/\b([A-Z0-9][A-Z0-9\-]{4,}[A-Z0-9])\b/i', $remark, $m)) {
            $candidate = $m[1];
            $normalizedCandidate = strtoupper(preg_replace('/[\s\-]/', '', $candidate));
            if ($normalizedCandidate !== $normalizedPrimary) {
                return $candidate;
            }
        }
        
        return null;
    }
    
    /**
     * Calculate effective quantity considering MOQ and pack/multiple constraints
     * 
     * Logic:
     * 1. If requested < MOQ, bump up to MOQ
     * 2. If multiple_quantity set, round UP to next multiple
     * 3. If pack_quantity set (for items sold only in packs), round UP to pack boundary
     * 
     * @param int $requestedQty Original quantity requested
     * @param int $moq Minimum Order Quantity (default 1)
     * @param int|null $packQty Pack quantity (if sold in packs only)
     * @param int|null $multipleQty Order multiple (must order in multiples of this)
     * @return array{effective_quantity: int, adjusted: bool, reason: string|null}
     */
    private function calculateEffectiveQuantity(
        int $requestedQty, 
        int $moq = 1, 
        ?int $packQty = null,
        ?int $multipleQty = null
    ): array {
        $effectiveQty = $requestedQty;
        $adjusted = false;
        $reasons = [];
        
        // Step 1: Enforce MOQ
        if ($effectiveQty < $moq) {
            $effectiveQty = $moq;
            $adjusted = true;
            $reasons[] = "Quantity increased from {$requestedQty} to {$moq} (MOQ)";
        }
        
        // Step 2: Enforce pack quantity (if sold in fixed packs)
        if ($packQty !== null && $packQty > 1) {
            $remainder = $effectiveQty % $packQty;
            if ($remainder !== 0) {
                $oldQty = $effectiveQty;
                $effectiveQty = $effectiveQty + ($packQty - $remainder); // Round UP
                $adjusted = true;
                $reasons[] = "Quantity increased from {$oldQty} to {$effectiveQty} (pack size: {$packQty})";
            }
        }
        // Step 3: Enforce order multiple (alternative to pack quantity)
        elseif ($multipleQty !== null && $multipleQty > 1) {
            $remainder = $effectiveQty % $multipleQty;
            if ($remainder !== 0) {
                $oldQty = $effectiveQty;
                $effectiveQty = $effectiveQty + ($multipleQty - $remainder); // Round UP
                $adjusted = true;
                $reasons[] = "Quantity increased from {$oldQty} to {$effectiveQty} (order multiple: {$multipleQty})";
            }
        }
        
        return [
            'effective_quantity' => $effectiveQty,
            'adjusted' => $adjusted,
            'reason' => $adjusted ? implode('; ', $reasons) : null,
        ];
    }
    
    /**
     * Get price break recommendation for a quantity
     * 
     * Suggests if ordering slightly more would result in significant savings.
     * Example: ordering 95 units at $1.00 each vs 100 units at $0.80 each
     * - Cost at 95: $95.00
     * - Cost at 100: $80.00 (SAVE $15 by ordering 5 more!)
     */
    public function getPriceBreakRecommendation(array $priceBreaks, int $quantity): ?array
    {
        if (empty($priceBreaks) || $quantity <= 0) {
            return null;
        }
        
        usort($priceBreaks, fn($a, $b) => $a['quantity'] <=> $b['quantity']);
        
        $currentPrice = $this->calculateUnitPrice($priceBreaks, $quantity);
        $currentCost = $currentPrice * $quantity;
        
        // Find next price break above current quantity
        $nextBreak = null;
        foreach ($priceBreaks as $break) {
            if ($break['quantity'] > $quantity) {
                $nextBreak = $break;
                break;
            }
        }
        
        if (!$nextBreak) {
            return null; // Already at highest break
        }
        
        // Calculate cost at next break
        $nextBreakQty = $nextBreak['quantity'];
        $nextBreakPrice = $nextBreak['price'];
        $nextBreakCost = $nextBreakPrice * $nextBreakQty;
        
        // Only recommend if we'd save money or break even
        $additionalQty = $nextBreakQty - $quantity;
        $additionalCostAtCurrentPrice = $additionalQty * $currentPrice;
        $totalIfBuyMore = $currentCost + $additionalCostAtCurrentPrice;
        
        $savings = $totalIfBuyMore - $nextBreakCost;
        $savingsPercent = ($savings / $currentCost) * 100;
        
        // Only recommend if savings > 5%
        if ($savingsPercent > 5) {
            $currency = $this->resolveCurrencyFromPriceBreaks($priceBreaks);
            $formattedSavings = $this->currencyConverter->format($savings, $currency, $currency, 2);

            return [
                'recommended_quantity' => $nextBreakQty,
                'additional_quantity' => $additionalQty,
                'current_unit_price' => $currentPrice,
                'recommended_unit_price' => $nextBreakPrice,
                'current_total' => round($currentCost, 2),
                'recommended_total' => round($nextBreakCost, 2),
                'savings' => round($savings, 2),
                'savings_percent' => round($savingsPercent, 1),
                'currency' => $currency ?? $this->currencyConverter->getDisplayCurrency(),
                'message' => sprintf(
                    'Order %d more (total %d) and save %s (%.1f%% savings)',
                    $additionalQty,
                    $nextBreakQty,
                    $formattedSavings,
                    $savingsPercent
                ),
            ];
        }
        
        return null;
    }

    /**
     * Calculate quote totals with margins
     */
    public function calculateQuoteTotals(array $processedLines, float $marginPercent = 25.0, ?string $currency = null): array
    {
        $subtotal = 0.0;
        $currency = $currency
            ?? $this->resolveCurrencyFromLines($processedLines)
            ?? $this->currencyConverter->getDisplayCurrency();
        
        foreach ($processedLines as $line) {
            $subtotal += $line['extended_price'] ?? 0;
        }
        
        $margin = $subtotal * ($marginPercent / 100);
        $total = $subtotal + $margin;
        
        return [
            'subtotal' => round($subtotal, 2),
            'margin_percent' => $marginPercent,
            'margin_amount' => round($margin, 2),
            'total' => round($total, 2),
            'currency' => $currency,
        ];
    }

    private function resolveCurrencyFromLines(array $processedLines): ?string
    {
        $counts = [];

        foreach ($processedLines as $line) {
            $currency = $line['currency'] ?? null;

            if (!$currency && isset($line['pricing']) && is_array($line['pricing'])) {
                $currency = $this->resolveCurrencyFromPriceBreaks($line['pricing']);
            }

            if ($currency) {
                $currency = strtoupper($currency);
                $counts[$currency] = ($counts[$currency] ?? 0) + 1;
            }
        }

        if (empty($counts)) {
            return null;
        }

        arsort($counts);

        return array_key_first($counts);
    }

    private function resolveCurrencyFromPriceBreaks(array $priceBreaks): ?string
    {
        foreach ($priceBreaks as $break) {
            if (!empty($break['currency'])) {
                return strtoupper($break['currency']);
            }
        }

        return null;
    }

    /**
     * Check if quote meets auto-publish criteria
     */
    public function canAutoPublish(array $stats, array $processedLines): array
    {
        $checks = [
            'coverage_ok' => $stats['coverage_percent'] >= 90,
            'no_critical_exceptions' => true,
            'reasonable_leadtimes' => true,
            'high_value_sourced' => true,
        ];
        
        // Check for high-value unsourced parts
        foreach ($processedLines as $line) {
            if ($line['status'] !== 'sourced') {
                $estimatedValue = 50; // Assume $50 if no price
                
                if ($estimatedValue * $line['quantity'] > 1000) {
                    $checks['high_value_sourced'] = false;
                    break;
                }
            }
        }
        
        // Check lead times
        foreach ($processedLines as $line) {
            if (isset($line['leadtime_days']) && $line['leadtime_days'] > 84) { // 12 weeks
                $checks['reasonable_leadtimes'] = false;
                break;
            }
        }
        
        $canAutoPublish = !in_array(false, $checks, true);
        
        return [
            'can_publish' => $canAutoPublish,
            'checks' => $checks,
        ];
    }

    /**
     * Optimise BOM sourcing by consolidating to fewer suppliers for bulk discounts.
     *
     * Instead of picking the cheapest supplier per line (which scatters the order
     * across many suppliers, increasing PO overhead, shipping costs, and admin),
     * this method evaluates whether concentrating purchases at a primary supplier
     * yields a lower total cost when factoring in:
     *
     *   1. Per-supplier order overhead (PO processing, shipping, receiving)
     *   2. Quantity-based price breaks per part per supplier
     *   3. Volume rebates for consolidated large orders (3–5%)
     *   4. Price-break upgrade recommendations (buy more to pay less total)
     *
     * @param array $processedLines  Output from processBOM()
     * @param float $orderOverhead   Per-supplier fixed cost (default $12)
     * @return array{
     *     scattered: array{parts_cost: float, supplier_count: int, total_cost: float},
     *     consolidated: array{parts_cost: float, supplier_count: int, total_cost: float, primary: string},
     *     savings: float,
     *     savings_percent: float,
     *     break_recommendations: array,
     *     recommendation: string
     * }
     */
    public function optimizeBOMSourcing(array $processedLines, float $orderOverhead = 12.00): array
    {
        // ── 1. Build multi-source pricing map ──
        // Each sourced line already has alternatives from multi-distributor.
        // We need price breaks for every part from every available source.
        $lineData = [];
        foreach ($processedLines as $idx => $line) {
            if (($line['status'] ?? '') === 'no_mpn' || ($line['status'] ?? '') === 'not_found') {
                continue;
            }

            $sources = [];

            // Primary source pricing
            $primarySource = $line['source'] ?? null;
            if ($primarySource && isset($line['pricing']) && is_array($line['pricing'])) {
                $sources[$primarySource] = $line['pricing'];
            }

            // Alternative source pricing
            foreach (($line['alternatives'] ?? []) as $alt) {
                $altSource = $alt['source'] ?? null;
                if ($altSource && isset($alt['pricing']) && is_array($alt['pricing'])) {
                    $sources[$altSource] = $alt['pricing'];
                }
            }

            if (!empty($sources)) {
                $lineData[$idx] = [
                    'mpn'      => $line['mpn'] ?? '',
                    'quantity' => $line['effective_quantity'] ?? $line['quantity'] ?? 1,
                    'sources'  => $sources,
                ];
            }
        }

        if (empty($lineData)) {
            return [
                'scattered'             => ['parts_cost' => 0, 'supplier_count' => 0, 'total_cost' => 0],
                'consolidated'          => ['parts_cost' => 0, 'supplier_count' => 0, 'total_cost' => 0, 'primary' => 'N/A'],
                'savings'               => 0,
                'savings_percent'       => 0,
                'break_recommendations' => [],
                'recommendation'        => 'No sourceable lines to optimize.',
            ];
        }

        // ── 2. Strategy A: scattered (per-line cheapest) ──
        $scattered = ['parts_cost' => 0.0, 'suppliers' => [], 'allocation' => []];
        foreach ($lineData as $idx => $ld) {
            $bestExt = PHP_FLOAT_MAX;
            $bestSrc = null;
            foreach ($ld['sources'] as $source => $breaks) {
                $unitPx = $this->calculateUnitPrice($breaks, $ld['quantity']);
                $ext    = $unitPx * $ld['quantity'];
                if ($ext < $bestExt) {
                    $bestExt = $ext;
                    $bestSrc = $source;
                }
            }
            if ($bestSrc !== null) {
                $scattered['parts_cost'] += $bestExt;
                $scattered['suppliers'][$bestSrc] = true;
                $scattered['allocation'][$idx] = $bestSrc;
            }
        }
        $scattered['supplier_count'] = count($scattered['suppliers']);
        $scattered['overhead']       = $scattered['supplier_count'] * $orderOverhead;
        $scattered['total_cost']     = $scattered['parts_cost'] + $scattered['overhead'];

        // ── 3. Strategy B: consolidate to primary supplier ──
        $allSuppliers = [];
        foreach ($lineData as $ld) {
            foreach (array_keys($ld['sources']) as $src) {
                $allSuppliers[$src] = true;
            }
        }

        $bestConsolidated = null;
        foreach (array_keys($allSuppliers) as $primarySupplier) {
            $strat = ['parts_cost' => 0.0, 'suppliers' => [], 'allocation' => [], 'rebate' => 0.0];

            foreach ($lineData as $idx => $ld) {
                if (isset($ld['sources'][$primarySupplier])) {
                    $unitPx = $this->calculateUnitPrice($ld['sources'][$primarySupplier], $ld['quantity']);
                    $ext    = $unitPx * $ld['quantity'];
                    $strat['parts_cost'] += $ext;
                    $strat['allocation'][$idx] = $primarySupplier;
                    $strat['suppliers'][$primarySupplier] = true;
                } else {
                    // Fallback to cheapest available
                    $bestExt = PHP_FLOAT_MAX;
                    $bestSrc = null;
                    foreach ($ld['sources'] as $source => $breaks) {
                        $unitPx = $this->calculateUnitPrice($breaks, $ld['quantity']);
                        $ext    = $unitPx * $ld['quantity'];
                        if ($ext < $bestExt) {
                            $bestExt = $ext;
                            $bestSrc = $source;
                        }
                    }
                    if ($bestSrc !== null) {
                        $strat['parts_cost'] += $bestExt;
                        $strat['allocation'][$idx] = $bestSrc;
                        $strat['suppliers'][$bestSrc] = true;
                    }
                }
            }

            // Volume rebate for consolidated spend
            $primarySpend = 0.0;
            $primaryCount = 0;
            foreach ($strat['allocation'] as $src) {
                if ($src === $primarySupplier) {
                    $primaryCount++;
                }
            }
            $lineCount    = count($lineData);
            $primaryRatio = $lineCount > 0 ? $primaryCount / $lineCount : 0;

            if ($primaryRatio >= 0.80) {
                // Recalculate primary spend
                foreach ($lineData as $idx => $ld) {
                    if (($strat['allocation'][$idx] ?? '') === $primarySupplier) {
                        $unitPx = $this->calculateUnitPrice($ld['sources'][$primarySupplier], $ld['quantity']);
                        $primarySpend += $unitPx * $ld['quantity'];
                    }
                }
                if ($primarySpend > 20) {
                    $rebateRate = $primarySpend > 100 ? 0.05 : 0.03;
                    $rebate = round($primarySpend * $rebateRate, 2);
                    $strat['parts_cost'] -= $rebate;
                    $strat['rebate']      = $rebate;
                    $strat['rebate_rate'] = $rebateRate * 100;
                }
            }

            $strat['supplier_count'] = count($strat['suppliers']);
            $strat['overhead']       = $strat['supplier_count'] * $orderOverhead;
            $strat['total_cost']     = $strat['parts_cost'] + $strat['overhead'];
            $strat['primary']        = $primarySupplier;

            if ($bestConsolidated === null || $strat['total_cost'] < $bestConsolidated['total_cost']) {
                $bestConsolidated = $strat;
            }
        }

        // ── 4. Price-break upgrade recommendations ──
        $breakRecs = [];
        foreach ($lineData as $idx => $ld) {
            $src = $bestConsolidated['allocation'][$idx] ?? null;
            if (!$src || !isset($ld['sources'][$src])) continue;

            $rec = $this->getPriceBreakRecommendation($ld['sources'][$src], $ld['quantity']);
            if ($rec) {
                $rec['mpn'] = $ld['mpn'];
                $breakRecs[] = $rec;
            }
        }

        $savings    = $scattered['total_cost'] - ($bestConsolidated['total_cost'] ?? $scattered['total_cost']);
        $savingsPct = $scattered['total_cost'] > 0
            ? round(($savings / $scattered['total_cost']) * 100, 1)
            : 0;

        $recommendation = $savings > 0
            ? sprintf(
                'Consolidate to %s as primary supplier. Save $%.2f (%.1f%%) through volume pricing, fewer POs, and lower shipping.',
                $bestConsolidated['primary'] ?? 'N/A',
                $savings,
                $savingsPct
            )
            : 'Current per-line sourcing is already optimal for this BOM.';

        return [
            'scattered'             => $scattered,
            'consolidated'          => $bestConsolidated ?? $scattered,
            'savings'               => round($savings, 2),
            'savings_percent'       => $savingsPct,
            'break_recommendations' => $breakRecs,
            'recommendation'        => $recommendation,
        ];
    }
}
