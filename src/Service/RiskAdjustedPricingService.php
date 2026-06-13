<?php

namespace App\Service;

use App\Service\Integration\MouserApiClient;
use App\Service\Integration\DigiKeyApiClient;
use App\Service\Integration\NexarApiClient;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Risk-Adjusted Pricing Service
 * 
 * Implements intelligent distributor selection that balances:
 * - Price (unit cost)
 * - Stock availability (delivery risk)
 * - Lead time (schedule risk)
 * - Supplier reliability (historical performance)
 * - Lifecycle status (obsolescence risk)
 * 
 * The service calculates a "Risk-Adjusted Cost" (RAC) that factors in potential
 * costs of stock-outs, delays, and component obsolescence. A slightly more expensive
 * part with guaranteed stock may have a lower RAC than a cheaper part with low stock.
 * 
 * Formula: RAC = Unit Price + (Risk Factor * Unit Price)
 * 
 * Risk Factor = Stock Risk + Lead Time Risk + Lifecycle Risk + Supplier Risk
 * 
 * Example:
 * - Distributor A: $1.00/unit, 50 in stock, 2-week lead time
 * - Distributor B: $1.05/unit, 5000 in stock, 1-week lead time
 * 
 * Despite being $0.05 more expensive, Distributor B may be selected because
 * the risk of stock-out from Distributor A adds virtual cost.
 */
class RiskAdjustedPricingService
{
    // Risk factor weights (sum = 1.0)
    private const WEIGHT_STOCK = 0.35;
    private const WEIGHT_LEAD_TIME = 0.25;
    private const WEIGHT_LIFECYCLE = 0.25;
    private const WEIGHT_SUPPLIER = 0.15;
    
    // Stock risk thresholds
    private const STOCK_CRITICAL = 10;      // Critical low stock
    private const STOCK_LOW = 100;          // Low stock
    private const STOCK_MEDIUM = 1000;      // Medium stock
    private const STOCK_HEALTHY = 5000;     // Healthy stock
    
    // Lead time risk thresholds (in days)
    private const LEAD_TIME_FAST = 7;       // Fast delivery
    private const LEAD_TIME_NORMAL = 21;    // Normal delivery
    private const LEAD_TIME_SLOW = 56;      // Slow (8 weeks)
    private const LEAD_TIME_CRITICAL = 84;  // Critical (12+ weeks)
    
    private const CACHE_KEY = 'risk_adjusted_pricing.supplier_reliability';
    private const CACHE_TTL = 2592000; // 30 days

    // Supplier reliability scores — loaded from cache, with these defaults
    private array $supplierReliability = [
        'mouser' => 0.95,      // 95% on-time delivery
        'digikey' => 0.93,     // 93% on-time delivery
        'nexar' => 0.85,       // Aggregator - variable
        'arrow' => 0.90,
        'avnet' => 0.88,
        'manual' => 0.70,      // Manual/unknown - higher risk
    ];
    
    public function __construct(
        private LoggerInterface $logger,
        private ?CacheInterface $cache = null
    ) {
        $this->loadSupplierReliability();
    }

    /**
     * Load supplier reliability scores from cache on construction.
     */
    private function loadSupplierReliability(): void
    {
        if ($this->cache === null) {
            return; // No cache configured — use defaults
        }

        try {
            $cached = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) {
                $item->expiresAfter(self::CACHE_TTL);
                // Return defaults as the initial cached value
                return $this->supplierReliability;
            });

            if (is_array($cached) && !empty($cached)) {
                $this->supplierReliability = $cached;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load supplier reliability from cache, using defaults', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persist current reliability scores to cache.
     */
    private function persistSupplierReliability(): void
    {
        if ($this->cache === null) {
            return;
        }

        try {
            $this->cache->delete(self::CACHE_KEY);
            $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) {
                $item->expiresAfter(self::CACHE_TTL);
                return $this->supplierReliability;
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to persist supplier reliability to cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Calculate risk-adjusted cost for a distributor result
     * 
     * @param array $distributorResult Result from distributor API
     * @param int $requiredQuantity Quantity needed for the order
     * @param string $source Distributor source identifier
     * @return array Extended result with risk analysis
     */
    public function calculateRiskAdjustedCost(
        array $distributorResult,
        int $requiredQuantity,
        string $source
    ): array {
        // Extract relevant data
        $unitPrice = $this->extractUnitPrice($distributorResult, $requiredQuantity);
        $stock = $distributorResult['stock'] ?? 0;
        $leadTimeDays = $this->extractLeadTimeDays($distributorResult);
        $lifecycle = strtolower($distributorResult['lifecycle'] ?? 'active');
        
        // Calculate individual risk scores (0.0 = no risk, 1.0 = maximum risk)
        $stockRisk = $this->calculateStockRisk($stock, $requiredQuantity);
        $leadTimeRisk = $this->calculateLeadTimeRisk($leadTimeDays);
        $lifecycleRisk = $this->calculateLifecycleRisk($lifecycle);
        $supplierRisk = $this->calculateSupplierRisk($source);
        
        // Calculate composite risk factor (0.0 - 1.0)
        $compositeRisk = (
            ($stockRisk * self::WEIGHT_STOCK) +
            ($leadTimeRisk * self::WEIGHT_LEAD_TIME) +
            ($lifecycleRisk * self::WEIGHT_LIFECYCLE) +
            ($supplierRisk * self::WEIGHT_SUPPLIER)
        );
        
        // Calculate risk-adjusted cost
        // Higher risk = higher effective cost
        $riskAdjustedCost = $unitPrice * (1 + $compositeRisk);
        
        // Calculate risk grade (A-F)
        $riskGrade = $this->calculateRiskGrade($compositeRisk);
        
        // Generate warnings and recommendations
        $warnings = $this->generateRiskWarnings($stockRisk, $leadTimeRisk, $lifecycleRisk, $supplierRisk, $stock, $requiredQuantity);
        
        return [
            'raw_unit_price' => round($unitPrice, 5),
            'risk_adjusted_cost' => round($riskAdjustedCost, 5),
            'risk_premium' => round($riskAdjustedCost - $unitPrice, 5),
            'risk_premium_percent' => round($compositeRisk * 100, 1),
            'risk_grade' => $riskGrade,
            'risk_breakdown' => [
                'stock_risk' => [
                    'score' => round($stockRisk, 3),
                    'weight' => self::WEIGHT_STOCK,
                    'weighted' => round($stockRisk * self::WEIGHT_STOCK, 3),
                    'available' => $stock,
                    'required' => $requiredQuantity,
                    'coverage' => $stock > 0 ? round(($stock / $requiredQuantity) * 100, 1) : 0,
                ],
                'lead_time_risk' => [
                    'score' => round($leadTimeRisk, 3),
                    'weight' => self::WEIGHT_LEAD_TIME,
                    'weighted' => round($leadTimeRisk * self::WEIGHT_LEAD_TIME, 3),
                    'days' => $leadTimeDays,
                ],
                'lifecycle_risk' => [
                    'score' => round($lifecycleRisk, 3),
                    'weight' => self::WEIGHT_LIFECYCLE,
                    'weighted' => round($lifecycleRisk * self::WEIGHT_LIFECYCLE, 3),
                    'status' => $lifecycle,
                ],
                'supplier_risk' => [
                    'score' => round($supplierRisk, 3),
                    'weight' => self::WEIGHT_SUPPLIER,
                    'weighted' => round($supplierRisk * self::WEIGHT_SUPPLIER, 3),
                    'source' => $source,
                    'reliability' => $this->supplierReliability[$source] ?? 0.70,
                ],
            ],
            'composite_risk' => round($compositeRisk, 3),
            'warnings' => $warnings,
            'recommendation' => $this->generateRecommendation($compositeRisk, $stockRisk, $stock, $requiredQuantity),
        ];
    }
    
    /**
     * Compare multiple distributor options and select the best risk-adjusted choice
     * 
     * @param array $options Array of distributor results with source identifiers
     * @param int $requiredQuantity Quantity needed
     * @return array{selected: array|null, alternatives: array, analysis: array}
     */
    public function selectBestOption(array $options, int $requiredQuantity): array
    {
        if (empty($options)) {
            return [
                'selected' => null,
                'alternatives' => [],
                'analysis' => ['error' => 'No options provided'],
            ];
        }
        
        $analyzedOptions = [];
        
        foreach ($options as $source => $result) {
            if ($result === null) {
                continue;
            }
            
            $riskAnalysis = $this->calculateRiskAdjustedCost($result, $requiredQuantity, $source);
            
            $analyzedOptions[] = [
                'source' => $source,
                'result' => $result,
                'risk_analysis' => $riskAnalysis,
                'sort_key' => $riskAnalysis['risk_adjusted_cost'],
            ];
        }
        
        if (empty($analyzedOptions)) {
            return [
                'selected' => null,
                'alternatives' => [],
                'analysis' => ['error' => 'No valid options after analysis'],
            ];
        }
        
        // Sort by risk-adjusted cost (lowest = best)
        usort($analyzedOptions, fn($a, $b) => $a['sort_key'] <=> $b['sort_key']);
        
        // Best option is first
        $selected = $analyzedOptions[0];
        $alternatives = array_slice($analyzedOptions, 1);
        
        // Calculate savings from risk-adjusted selection
        // Fix C1: array_column() returns indexed array; cannot use string key on it.
        // Extract risk_analysis arrays first, then column for raw_unit_price.
        $riskAnalyses = array_column($analyzedOptions, 'risk_analysis');
        $cheapestRaw = min(array_column($riskAnalyses, 'raw_unit_price') ?: [0]);
        $selectedRaw = $selected['risk_analysis']['raw_unit_price'];
        
        $this->logger->info('Risk-adjusted pricing selection', [
            'required_quantity' => $requiredQuantity,
            'options_count' => count($analyzedOptions),
            'selected_source' => $selected['source'],
            'selected_risk_grade' => $selected['risk_analysis']['risk_grade'],
            'selected_raw_price' => $selectedRaw,
            'selected_risk_adjusted' => $selected['risk_analysis']['risk_adjusted_cost'],
        ]);
        
        return [
            'selected' => [
                'source' => $selected['source'],
                'result' => $selected['result'],
                'risk_analysis' => $selected['risk_analysis'],
            ],
            'alternatives' => array_map(fn($opt) => [
                'source' => $opt['source'],
                'result' => $opt['result'],
                'risk_analysis' => $opt['risk_analysis'],
            ], $alternatives),
            'analysis' => [
                'options_evaluated' => count($analyzedOptions),
                'selection_reason' => $this->explainSelection($selected, $analyzedOptions),
                'risk_adjusted_selection' => $selectedRaw !== $cheapestRaw,
            ],
        ];
    }
    
    /**
     * Calculate stock risk score
     */
    private function calculateStockRisk(int $stock, int $requiredQuantity): float
    {
        if ($stock <= 0) {
            return 1.0; // Maximum risk - no stock
        }
        
        // Calculate coverage ratio
        $coverage = $stock / $requiredQuantity;
        
        if ($coverage < 1.0) {
            // Can't fulfill order - high risk
            return 0.9 - ($coverage * 0.4); // 0.5 to 0.9
        }
        
        if ($stock <= self::STOCK_CRITICAL) {
            return 0.7;
        }
        
        if ($stock <= self::STOCK_LOW) {
            return 0.4;
        }
        
        if ($stock <= self::STOCK_MEDIUM) {
            return 0.2;
        }
        
        if ($stock <= self::STOCK_HEALTHY) {
            return 0.1;
        }
        
        return 0.0; // Excellent stock
    }
    
    /**
     * Calculate lead time risk score
     */
    private function calculateLeadTimeRisk(int $leadTimeDays): float
    {
        if ($leadTimeDays <= self::LEAD_TIME_FAST) {
            return 0.0;
        }
        
        if ($leadTimeDays <= self::LEAD_TIME_NORMAL) {
            return 0.2;
        }
        
        if ($leadTimeDays <= self::LEAD_TIME_SLOW) {
            return 0.5;
        }
        
        if ($leadTimeDays <= self::LEAD_TIME_CRITICAL) {
            return 0.8;
        }
        
        return 1.0; // Very long lead time
    }
    
    /**
     * Calculate lifecycle risk score
     */
    private function calculateLifecycleRisk(string $lifecycle): float
    {
        // Critical lifecycle statuses
        $critical = ['obsolete', 'eol', 'end of life', 'discontinued'];
        foreach ($critical as $term) {
            if (str_contains($lifecycle, $term)) {
                return 1.0;
            }
        }
        
        // Warning lifecycle statuses
        $warning = ['nrnd', 'not recommended', 'last time buy', 'ltb', 'limited'];
        foreach ($warning as $term) {
            if (str_contains($lifecycle, $term)) {
                return 0.6;
            }
        }
        
        // Mature but not at risk
        if (str_contains($lifecycle, 'mature')) {
            return 0.2;
        }
        
        // Active/new - no risk
        if (str_contains($lifecycle, 'active') || str_contains($lifecycle, 'new')) {
            return 0.0;
        }
        
        // Unknown - slight risk
        return 0.15;
    }
    
    /**
     * Calculate supplier risk score based on reliability
     */
    private function calculateSupplierRisk(string $source): float
    {
        $reliability = $this->supplierReliability[strtolower($source)] ?? 0.70;
        
        // Convert reliability (0-1) to risk (0-1)
        // Higher reliability = lower risk
        return 1.0 - $reliability;
    }
    
    /**
     * Calculate overall risk grade (A-F)
     */
    private function calculateRiskGrade(float $compositeRisk): string
    {
        if ($compositeRisk <= 0.1) {
            return 'A';
        }
        if ($compositeRisk <= 0.2) {
            return 'B';
        }
        if ($compositeRisk <= 0.35) {
            return 'C';
        }
        if ($compositeRisk <= 0.5) {
            return 'D';
        }
        return 'F';
    }
    
    /**
     * Extract unit price for given quantity from price breaks
     */
    private function extractUnitPrice(array $result, int $quantity): float
    {
        $pricing = $result['pricing'] ?? [];
        
        if (empty($pricing)) {
            return 0.0;
        }
        
        // Sort by quantity ascending
        usort($pricing, fn($a, $b) => ($a['quantity'] ?? 0) <=> ($b['quantity'] ?? 0));
        
        $applicablePrice = $pricing[0]['price'] ?? 0;
        
        foreach ($pricing as $break) {
            if ($quantity >= ($break['quantity'] ?? 0)) {
                $applicablePrice = $break['price'] ?? $applicablePrice;
            }
        }
        
        return (float) $applicablePrice;
    }
    
    /**
     * Extract lead time in days from distributor result
     */
    private function extractLeadTimeDays(array $result): int
    {
        // Check for explicit lead time field
        if (isset($result['leadtime_days'])) {
            return (int) $result['leadtime_days'];
        }
        
        // Parse lead time string (e.g., "2-3 weeks", "5 days")
        $leadTimeStr = $result['leadtime'] ?? $result['lead_time'] ?? '';
        
        if (preg_match('/(\d+)\s*-?\s*(\d+)?\s*(day|week|month)/i', $leadTimeStr, $matches)) {
            $value = (int) ($matches[2] ?? $matches[1]); // Use higher bound if range
            $unit = strtolower($matches[3]);
            
            return match($unit) {
                'day', 'days' => $value,
                'week', 'weeks' => $value * 7,
                'month', 'months' => $value * 30,
                default => $value,
            };
        }
        
        // Default to 14 days if no lead time info
        return 14;
    }
    
    /**
     * Generate risk warnings based on risk scores
     */
    private function generateRiskWarnings(
        float $stockRisk,
        float $leadTimeRisk,
        float $lifecycleRisk,
        float $supplierRisk,
        int $stock,
        int $requiredQuantity
    ): array {
        $warnings = [];
        
        if ($stock < $requiredQuantity) {
            $warnings[] = [
                'severity' => 'critical',
                'type' => 'stock',
                'message' => sprintf(
                    'Insufficient stock: only %d available, %d required. Partial shipment likely.',
                    $stock,
                    $requiredQuantity
                ),
            ];
        } elseif ($stockRisk > 0.5) {
            $warnings[] = [
                'severity' => 'warning',
                'type' => 'stock',
                'message' => 'Low stock levels may cause fulfillment delays.',
            ];
        }
        
        if ($leadTimeRisk > 0.7) {
            $warnings[] = [
                'severity' => 'critical',
                'type' => 'lead_time',
                'message' => 'Extended lead time (12+ weeks) may impact project schedule.',
            ];
        } elseif ($leadTimeRisk > 0.4) {
            $warnings[] = [
                'severity' => 'warning',
                'type' => 'lead_time',
                'message' => 'Lead time exceeds 6 weeks. Consider safety stock.',
            ];
        }
        
        if ($lifecycleRisk >= 1.0) {
            $warnings[] = [
                'severity' => 'critical',
                'type' => 'lifecycle',
                'message' => 'Part is OBSOLETE or EOL. Find replacement immediately.',
            ];
        } elseif ($lifecycleRisk > 0.5) {
            $warnings[] = [
                'severity' => 'warning',
                'type' => 'lifecycle',
                'message' => 'Part is NRND/Last Time Buy. Plan for replacement.',
            ];
        }
        
        return $warnings;
    }
    
    /**
     * Generate recommendation based on risk analysis
     */
    private function generateRecommendation(
        float $compositeRisk,
        float $stockRisk,
        int $stock,
        int $requiredQuantity
    ): string {
        if ($compositeRisk <= 0.1) {
            return 'Excellent choice. Low risk across all factors.';
        }
        
        if ($stock < $requiredQuantity) {
            return sprintf(
                'Consider splitting order: %d from this source, %d from alternative.',
                $stock,
                $requiredQuantity - $stock
            );
        }
        
        if ($compositeRisk <= 0.25) {
            return 'Good choice with acceptable risk levels.';
        }
        
        if ($stockRisk > 0.5) {
            return 'Consider ordering safety stock to mitigate availability risk.';
        }
        
        if ($compositeRisk <= 0.4) {
            return 'Moderate risk. Review alternatives before confirming.';
        }
        
        return 'High risk. Strongly recommend reviewing alternatives or finding replacement part.';
    }
    
    /**
     * Explain why a particular option was selected
     */
    private function explainSelection(array $selected, array $allOptions): string
    {
        $selectedRisk = $selected['risk_analysis'];
        
        // Find cheapest raw price option
        $cheapestOption = null;
        $cheapestPrice = PHP_FLOAT_MAX;
        
        foreach ($allOptions as $opt) {
            $rawPrice = $opt['risk_analysis']['raw_unit_price'];
            if ($rawPrice < $cheapestPrice && $rawPrice > 0) {
                $cheapestPrice = $rawPrice;
                $cheapestOption = $opt;
            }
        }
        
        // If selected is also cheapest
        if ($cheapestOption && $cheapestOption['source'] === $selected['source']) {
            return sprintf(
                'Selected %s: Best price ($%.4f) with Grade %s risk rating.',
                $selected['source'],
                $selectedRisk['raw_unit_price'],
                $selectedRisk['risk_grade']
            );
        }
        
        // Selected is not cheapest - explain why
        if ($cheapestOption) {
            $priceDiff = $selectedRisk['raw_unit_price'] - $cheapestPrice;
            $riskSavings = $cheapestOption['risk_analysis']['risk_premium'] - $selectedRisk['risk_premium'];
            
            return sprintf(
                'Selected %s over cheaper %s (+$%.4f/unit) due to lower risk: Grade %s vs Grade %s. Risk-adjusted savings: $%.4f/unit.',
                $selected['source'],
                $cheapestOption['source'],
                $priceDiff,
                $selectedRisk['risk_grade'],
                $cheapestOption['risk_analysis']['risk_grade'],
                $riskSavings
            );
        }
        
        return sprintf(
            'Selected %s with Grade %s risk rating.',
            $selected['source'],
            $selectedRisk['risk_grade']
        );
    }
    
    /**
     * Update supplier reliability score based on actual performance.
     * Persists to cache so scores survive between requests.
     */
    public function updateSupplierReliability(string $source, bool $wasOnTime): void
    {
        $source = strtolower($source);
        
        if (!isset($this->supplierReliability[$source])) {
            $this->supplierReliability[$source] = 0.80;
        }
        
        // Exponential moving average update
        $alpha = 0.1; // Learning rate
        $newValue = $wasOnTime ? 1.0 : 0.0;
        
        $this->supplierReliability[$source] =
            ($alpha * $newValue) + ((1 - $alpha) * $this->supplierReliability[$source]);
        
        // Persist updated scores to cache
        $this->persistSupplierReliability();
        
        $this->logger->info('Supplier reliability updated and persisted', [
            'source' => $source,
            'was_on_time' => $wasOnTime,
            'new_reliability' => $this->supplierReliability[$source],
        ]);
    }
    
    /**
     * Get current supplier reliability scores
     */
    public function getSupplierReliabilityScores(): array
    {
        return $this->supplierReliability;
    }
}
