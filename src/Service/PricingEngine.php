<?php

namespace App\Service;

use App\Service\Integration\MouserApiClient;
use App\Service\Integration\DigiKeyApiClient;
use App\Service\Integration\NexarApiClient;
use App\Service\Integration\MultiDistributorSourcingService;
use Psr\Log\LoggerInterface;

/**
 * Pricing Engine - Enhanced with Multi-Distributor Waterfall
 * 
 * Implements intelligent API waterfall for part pricing with:
 * - Confidence scoring for part matches
 * - Multi-distributor comparison (Mouser, DigiKey, Nexar)
 * - Automatic waterfall when confidence < 80% or stock = 0
 * - Alternative parts visibility (top 3 alternatives)
 * - Lifecycle status tracking (NRND, Obsolete warnings)
 * - Direct search URL generation for transparency
 * 
 * Waterfall Strategy:
 * 1. Mouser API (preferred - official distributor)
 * 2. DigiKey API (fallback or if Mouser confidence < 80%)
 * 3. Nexar API (aggregator - multiple distributors)
 * 4. Internal pricebook (historical data)
 * 5. Manual override required for unmatched parts
 * 
 * The engine now tracks WHY a distributor was chosen and provides
 * alternatives so users can make informed decisions.
 */
class PricingEngine
{
    public function __construct(
        private MouserApiClient $mouserClient,
        private DigiKeyApiClient $digikeyClient,
        private NexarApiClient $nexarClient,
        private MultiDistributorSourcingService $multiDistributor,
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
     * 
     * @return array|null ['mpn', 'manufacturer', 'description', 'pricing', 'stock', 'source', 
     *                     'confidence', 'alternatives', 'lifecycle_warning', 'search_url', 
     *                     'waterfall_info']
     */
    public function getPricing(string $mpn, ?string $manufacturer = null, ?string $description = null): ?array
    {
        // Use multi-distributor service for intelligent waterfall
        $multiResult = $this->multiDistributor->searchPart($mpn, $manufacturer, $description);
        
        if ($multiResult && $multiResult['selected']) {
            $result = $multiResult['selected'];
            $result['source'] = $multiResult['source'];
            $result['alternatives'] = $multiResult['alternatives'] ?? [];
            $result['waterfall_info'] = [
                'triggered' => $multiResult['waterfall_triggered'],
                'reason' => $multiResult['waterfall_reason'],
                'sources_checked' => array_keys($multiResult['all_sources']),
            ];
            
            $this->logger->info('Multi-distributor pricing found', [
                'mpn' => $mpn,
                'source' => $multiResult['source'],
                'confidence' => $result['confidence']['level'] ?? 'N/A',
                'score' => $result['confidence']['score'] ?? 0,
                'waterfall_triggered' => $multiResult['waterfall_triggered'],
                'alternatives_count' => count($result['alternatives']),
            ]);
            
            return $result;
        }
        
        // If multi-distributor service didn't find anything, try Nexar as last resort
        $result = $this->nexarClient->searchByPartNumber($mpn, $manufacturer, $description);
        
        if ($result) {
            $result['source'] = 'nexar';
            $result['alternatives'] = [];
            $result['waterfall_info'] = [
                'triggered' => true,
                'reason' => 'Fallback to Nexar aggregator after Mouser and DigiKey failed',
                'sources_checked' => ['mouser', 'digikey', 'nexar'],
            ];
            
            $this->logger->info('Nexar fallback pricing found', [
                'mpn' => $mpn,
                'confidence' => $result['confidence']['level'] ?? 'N/A'
            ]);
            return $result;
        }
        
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
            'mouser' => $this->mouserClient->searchByPartNumber($mpn, $manufacturer),
            'digikey' => $this->digikeyClient->searchByPartNumber($mpn, $manufacturer),
            'nexar' => $this->nexarClient->searchByPartNumber($mpn, $manufacturer),
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
     * @return array ['lines' => processed lines, 'stats' => statistics, 'reviewRequired' => bool]
     */
    public function processBOM(array $bomLines): array
    {
        $processedLines = [];
        $stats = [
            'total_lines' => count($bomLines),
            'sourced' => 0,
            'unsourced' => 0,
            'total_cost' => 0.0,
            'sources' => [
                'mouser' => 0,
                'digikey' => 0,
                'nexar' => 0,
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
                $line['description'] ?? null
            );
            
            if ($pricing) {
                // Successfully sourced
                $processedLine = array_merge($line, $pricing);
                $processedLine['status'] = 'sourced';
                
                // Calculate effective quantity considering MOQ and pack quantity
                $requestedQty = $line['quantity'];
                $moq = $pricing['moq'] ?? 1;
                $packQty = $pricing['pack_quantity'] ?? null;
                $multipleQty = $pricing['multiple_quantity'] ?? null;
                
                $quantityResult = $this->calculateEffectiveQuantity($requestedQty, $moq, $packQty, $multipleQty);
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
                $processedLine['unit_price'] = $unitPrice;
                $processedLine['extended_price'] = $unitPrice * $effectiveQty;
                
                // Add warning if quantity was adjusted
                if ($quantityResult['adjusted']) {
                    $processedLine['confidence']['warnings'] = array_merge(
                        $processedLine['confidence']['warnings'] ?? [],
                        [$quantityResult['reason']]
                    );
                }
                
                $stats['sourced']++;
                $stats['total_cost'] += $processedLine['extended_price'];
                $stats['sources'][$pricing['source']]++;
                
                // Track confidence
                $confidenceLevel = $pricing['confidence']['level'] ?? 'MEDIUM';
                $stats['confidence_breakdown'][$confidenceLevel]++;
                
                if ($pricing['confidence']['requiresReview'] ?? false) {
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
                
                // Store search URL for transparency
                $processedLine['search_url'] = $pricing['search_url'] ?? null;
                
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
                'mouser' => 0,
                'digikey' => 0,
                'nexar' => 0,
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
        
        // Sort price breaks by quantity (ascending) - lowest qty first
        usort($priceBreaks, fn($a, $b) => $a['quantity'] <=> $b['quantity']);
        
        // Default to the smallest quantity break price (most expensive)
        $applicablePrice = $priceBreaks[0]['price'];
        
        // Find the best applicable price break (highest quantity the customer qualifies for)
        foreach ($priceBreaks as $break) {
            if ($quantity >= $break['quantity']) {
                // Customer qualifies for this break - use its price
                $applicablePrice = $break['price'];
            } else {
                // Customer doesn't meet this break threshold - stop checking
                // (since breaks are sorted ascending, all remaining breaks require more qty)
                break;
            }
        }
        
        return (float) $applicablePrice;
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
            return [
                'recommended_quantity' => $nextBreakQty,
                'additional_quantity' => $additionalQty,
                'current_unit_price' => $currentPrice,
                'recommended_unit_price' => $nextBreakPrice,
                'current_total' => round($currentCost, 2),
                'recommended_total' => round($nextBreakCost, 2),
                'savings' => round($savings, 2),
                'savings_percent' => round($savingsPercent, 1),
                'message' => sprintf(
                    'Order %d more (total %d) and save $%.2f (%.1f%% savings)',
                    $additionalQty,
                    $nextBreakQty,
                    $savings,
                    $savingsPercent
                ),
            ];
        }
        
        return null;
    }

    /**
     * Calculate quote totals with margins
     */
    public function calculateQuoteTotals(array $processedLines, float $marginPercent = 25.0): array
    {
        $subtotal = 0.0;
        
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
            'currency' => 'USD',
        ];
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
}
