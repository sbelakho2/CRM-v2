<?php

namespace App\Service\Integration;

use App\Service\PartMatchConfidenceCalculator;
use Psr\Log\LoggerInterface;

/**
 * Multi-Distributor Sourcing Service
 * 
 * Implements a "waterfall" approach to part sourcing:
 * 1. Try Alibaba first (factory-direct, best bulk pricing)
 * 2. Try Mouser (authorized distributor, reliable stock)
 * 3. If confidence < threshold OR stock = 0, try DigiKey
 * 4. Aggregate results and select best overall match
 * 
 * This prevents single-distributor dependency and improves sourcing success rate.
 */
class MultiDistributorSourcingService
{
    public const SOURCE_ALIBABA = 'alibaba';
    public const SOURCE_MOUSER = 'mouser';
    public const SOURCE_DIGIKEY = 'digikey';
    
    // Thresholds for waterfall logic
    private const CONFIDENCE_THRESHOLD = 80; // Below this, try next distributor
    private const MIN_STOCK_THRESHOLD = 0;   // If stock <= this, try next distributor
    private const MIN_CONFIDENCE_FLOOR = 40; // Reject any result below this confidence
    
    public function __construct(
        private AlibabaApiClient $alibabaClient,
        private MouserApiClient $mouserClient,
        private DigiKeyApiClient $digiKeyClient,
        private PartMatchConfidenceCalculator $confidenceCalculator,
        private LoggerInterface $logger
    ) {}
    
    /**
     * Search for a part across multiple distributors using waterfall logic
     * 
     * @param string $partNumber The MPN to search for
     * @param string|null $manufacturer Optional manufacturer name
     * @param string|null $description Optional description
     * @param array $options Options: ['skip_waterfall' => false, 'force_all' => false]
     * 
     * @return array{
     *   selected: array|null,
     *   source: string|null,
     *   alternatives: array,
     *   all_sources: array,
     *   waterfall_triggered: bool,
     *   waterfall_reason: string|null
     * }
     */
    public function searchPart(
        string $partNumber,
        ?string $manufacturer = null,
        ?string $description = null,
        array $options = []
    ): array {
        $skipWaterfall = $options['skip_waterfall'] ?? false;
        $forceAll = $options['force_all'] ?? false;
        
        $result = [
            'selected' => null,
            'source' => null,
            'alternatives' => [],
            'all_sources' => [],
            'waterfall_triggered' => false,
            'waterfall_reason' => null,
        ];
        
        // Step 1: Try Alibaba first (factory-direct pricing)
        $alibabaResult = $this->tryAlibaba($partNumber, $manufacturer, $description);
        
        if ($alibabaResult) {
            $result['all_sources'][self::SOURCE_ALIBABA] = $alibabaResult;
        }
        
        // Step 2: Try Mouser (authorized distributor)
        $mouserResult = $this->tryMouser($partNumber, $manufacturer, $description);
        
        if ($mouserResult) {
            $result['all_sources'][self::SOURCE_MOUSER] = $mouserResult;
            
            // Check if waterfall should be triggered to also try DigiKey
            $shouldWaterfall = false;
            $waterfallReason = null;
            
            if (!$skipWaterfall) {
                $confidence = $mouserResult['confidence']['score'] ?? 0;
                $stock = $mouserResult['stock'] ?? 0;
                
                if ($confidence < self::CONFIDENCE_THRESHOLD) {
                    $shouldWaterfall = true;
                    $waterfallReason = sprintf('Low confidence (%d%% < %d%%)', $confidence, self::CONFIDENCE_THRESHOLD);
                } elseif ($stock <= self::MIN_STOCK_THRESHOLD) {
                    $shouldWaterfall = true;
                    $waterfallReason = 'Out of stock at Mouser';
                }
            }
            
            // Force all distributors if requested
            if ($forceAll) {
                $shouldWaterfall = true;
                $waterfallReason = 'Forced multi-distributor search';
            }
            
            $result['waterfall_triggered'] = $shouldWaterfall;
            $result['waterfall_reason'] = $waterfallReason;
        } else {
            // No result from Mouser, definitely try DigiKey
            $result['waterfall_triggered'] = true;
            $result['waterfall_reason'] = 'No result from Mouser - continuing waterfall';
        }
        
        // Step 3: Try DigiKey if waterfall triggered
        if ($result['waterfall_triggered']) {
            $digiKeyResult = $this->tryDigiKey($partNumber, $manufacturer, $description);
            
            if ($digiKeyResult) {
                $result['all_sources'][self::SOURCE_DIGIKEY] = $digiKeyResult;
            }
        }
        
        // Step 3: Select best overall result
        $selection = $this->selectBestOverall($result['all_sources'], $partNumber);
        $result['selected'] = $selection['part'];
        $result['source'] = $selection['source'];
        
        // Step 4: Build alternatives list (from all sources)
        $result['alternatives'] = $this->buildAlternativesList($result['all_sources'], $result['selected'], $result['source']);
        
        $this->logger->info('Multi-distributor search completed', [
            'mpn' => $partNumber,
            'sources_checked' => array_keys($result['all_sources']),
            'selected_source' => $result['source'],
            'waterfall_triggered' => $result['waterfall_triggered'],
            'waterfall_reason' => $result['waterfall_reason'],
            'alternatives_count' => count($result['alternatives']),
        ]);
        
        return $result;
    }
    
    /**
     * Try Alibaba API (factory-direct pricing)
     */
    private function tryAlibaba(string $partNumber, ?string $manufacturer, ?string $description): ?array
    {
        try {
            $result = $this->alibabaClient->searchByPartNumber($partNumber, $manufacturer, $description);
            
            if ($result) {
                $result['_source'] = self::SOURCE_ALIBABA;
                $result['_source_url'] = $this->alibabaClient->buildSearchUrl($partNumber);
            }
            
            return $result;
        } catch (\Exception $e) {
            $this->logger->warning('Alibaba search failed', [
                'mpn' => $partNumber,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Try Mouser API
     */
    private function tryMouser(string $partNumber, ?string $manufacturer, ?string $description): ?array
    {
        try {
            $result = $this->mouserClient->searchByPartNumber($partNumber, $manufacturer, $description);
            
            if ($result) {
                $result['_source'] = self::SOURCE_MOUSER;
                $result['_source_url'] = $this->mouserClient->buildSearchUrl($partNumber);
            }
            
            return $result;
        } catch (\Exception $e) {
            $this->logger->warning('Mouser search failed', [
                'mpn' => $partNumber,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Try DigiKey API
     */
    private function tryDigiKey(string $partNumber, ?string $manufacturer, ?string $description): ?array
    {
        try {
            $result = $this->digiKeyClient->searchByPartNumber($partNumber);
            
            if ($result) {
                // Add confidence scoring (DigiKey client doesn't have it built-in)
                $result['confidence'] = $this->confidenceCalculator->calculateConfidence(
                    $partNumber,
                    $manufacturer,
                    $description,
                    $result
                );
                $result['_source'] = self::SOURCE_DIGIKEY;
                $result['_source_url'] = $this->buildDigiKeySearchUrl($partNumber);
            }
            
            return $result;
        } catch (\Exception $e) {
            $this->logger->warning('DigiKey search failed', [
                'mpn' => $partNumber,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Build DigiKey search URL
     */
    private function buildDigiKeySearchUrl(string $partNumber): string
    {
        return 'https://www.digikey.com/en/products/filter?keywords=' . urlencode($partNumber);
    }
    
    /**
     * Select the best overall result from all sources
     * 
     * @return array{part: array|null, source: string|null}
     */
    private function selectBestOverall(array $allSources, string $requestedMpn): array
    {
        if (empty($allSources)) {
            return ['part' => null, 'source' => null];
        }
        
        $candidates = [];
        
        foreach ($allSources as $source => $result) {
            // Reject results with unacceptably low confidence (garbage matches)
            $confidence = $result['confidence']['score'] ?? 0;
            if ($confidence < self::MIN_CONFIDENCE_FLOOR) {
                $this->logger->info('Rejecting low-confidence result', [
                    'source' => $source,
                    'confidence' => $confidence,
                    'min_required' => self::MIN_CONFIDENCE_FLOOR,
                ]);
                continue;
            }
            
            $score = $this->calculateOverallScore($result, $requestedMpn);
            $candidates[] = [
                'source' => $source,
                'part' => $result,
                'score' => $score,
                'lowest_price' => $this->getLowestUnitPrice($result),
            ];
        }
        
        if (empty($candidates)) {
            return ['part' => null, 'source' => null];
        }
        
        // Price-aware selection: when multiple candidates have comparable scores,
        // the cheapest one wins. This prevents Alibaba from winning at $0.88
        // when DigiKey has the same part at $0.09.
        if (count($candidates) > 1) {
            $candidates = $this->applyPriceCompetitiveness($candidates);
        }
        
        // Sort by score descending
        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
        
        $best = $candidates[0];
        
        $this->logger->debug('Best overall selected', [
            'mpn' => $requestedMpn,
            'source' => $best['source'],
            'score' => $best['score'],
            'price' => $best['lowest_price'],
            'all_candidates' => array_map(fn($c) => [
                'source' => $c['source'],
                'score' => $c['score'],
                'price' => $c['lowest_price'],
            ], $candidates),
        ]);
        
        return [
            'part' => $best['part'],
            'source' => $best['source'],
        ];
    }
    
    /**
     * Get the lowest unit price from a result's pricing breaks
     */
    private function getLowestUnitPrice(array $result): ?float
    {
        $pricing = $result['pricing'] ?? [];
        if (empty($pricing)) {
            return null;
        }
        $lowest = PHP_FLOAT_MAX;
        foreach ($pricing as $break) {
            $p = $break['price'] ?? PHP_FLOAT_MAX;
            if ($p > 0 && $p < $lowest) {
                $lowest = $p;
            }
        }
        return $lowest < PHP_FLOAT_MAX ? $lowest : null;
    }
    
    /**
     * Apply price competitiveness adjustments to candidate scores.
     * 
     * When two sources both match the same MPN with reasonable confidence,
     * the cheaper one should win. This gives a bonus to the cheapest candidate
     * and penalizes expensive ones proportionally.
     * 
     * Example: Alibaba at $0.88 vs DigiKey at $0.09 for the same part —
     * DigiKey should get a huge bonus because it's 10x cheaper.
     */
    private function applyPriceCompetitiveness(array $candidates): array
    {
        // Find candidates with valid pricing
        $priced = array_filter($candidates, fn($c) => $c['lowest_price'] !== null && $c['lowest_price'] > 0);
        if (count($priced) < 2) {
            return $candidates; // Nothing to compare
        }
        
        // Find the cheapest price across all candidates
        $cheapest = min(array_column($priced, 'lowest_price'));
        
        // Apply price ratio bonus/penalty
        foreach ($candidates as &$candidate) {
            $price = $candidate['lowest_price'];
            if ($price === null || $price <= 0 || $cheapest <= 0) {
                continue;
            }
            
            $ratio = $price / $cheapest; // 1.0 = cheapest, 2.0 = 2x more expensive
            
            if ($ratio <= 1.2) {
                // Within 20% of cheapest — bonus for being price-competitive
                $candidate['score'] += 15;
            } elseif ($ratio <= 2.0) {
                // Up to 2x more expensive — small penalty
                $candidate['score'] -= 5;
            } elseif ($ratio <= 5.0) {
                // 2x-5x more expensive — moderate penalty
                $candidate['score'] -= 15;
            } else {
                // More than 5x more expensive — heavy penalty
                $candidate['score'] -= 30;
            }
        }
        unset($candidate);
        
        return $candidates;
    }
    
    /**
     * Calculate overall score for a part result
     * 
     * Scoring aims for best VALUE: price × confidence × availability.
     * Alibaba gets a source preference bonus because it provides factory-direct
     * pricing with better bulk rates.
     */
    private function calculateOverallScore(array $result, string $requestedMpn): int
    {
        $score = 0;
        
        // Confidence score contributes directly (0-100)
        $confidence = $result['confidence']['score'] ?? 0;
        $score += $confidence;
        
        // Source preference: Alibaba first (factory-direct = best bulk pricing)
        $source = $result['_source'] ?? '';
        if ($source === self::SOURCE_ALIBABA) {
            $score += 15; // Strong preference for factory-direct pricing
        }
        
        // Stock bonus (up to +30)
        // Alibaba estimated stock is flagged — give partial credit
        $stock = $result['stock'] ?? 0;
        $isEstimatedStock = $result['_stock_estimated'] ?? false;
        if ($stock > 0) {
            if ($isEstimatedStock) {
                // Alibaba estimated stock: partial credit (supplier can deliver)
                $score += 10;
                if ($stock > 1000) $score += 3;
                if ($stock > 5000) $score += 3;
            } else {
                // Real stock from DigiKey/Mouser
                $score += 15;
                if ($stock > 100) $score += 5;
                if ($stock > 1000) $score += 5;
                if ($stock > 10000) $score += 5;
            }
        }
        
        // Price competitiveness bonus: lower prices get a bonus
        // This helps Alibaba compete when it has much lower unit prices
        $pricing = $result['pricing'] ?? [];
        if (!empty($pricing)) {
            $score += 10;
            
            // Extra bonus for very low unit prices (bulk pricing advantage)
            $lowestPrice = PHP_FLOAT_MAX;
            foreach ($pricing as $break) {
                $lowestPrice = min($lowestPrice, $break['price'] ?? PHP_FLOAT_MAX);
            }
            if ($lowestPrice < 1.0) {
                $score += 5; // Sub-$1 parts are well-priced
            }
            if ($lowestPrice < 0.10) {
                $score += 5; // Sub-$0.10 is excellent bulk pricing
            }
        }
        
        // Lifecycle penalty
        $lifecycleRaw = $result['lifecycle'] ?? '';
        $lifecycle = strtolower(is_string($lifecycleRaw) ? $lifecycleRaw : '');
        foreach (MouserApiClient::LIFECYCLE_CRITICAL as $term) {
            if (str_contains($lifecycle, $term)) {
                $score -= 30;
                break;
            }
        }
        foreach (MouserApiClient::LIFECYCLE_WARNING as $term) {
            if (str_contains($lifecycle, $term)) {
                $score -= 10;
                break;
            }
        }
        
        return max(0, $score);
    }
    
    /**
     * Build alternatives list from all sources
     */
    private function buildAlternativesList(array $allSources, ?array $selected, ?string $selectedSource): array
    {
        $alternatives = [];
        
        foreach ($allSources as $source => $result) {
            // Skip the selected result
            if ($source === $selectedSource && $result === $selected) {
                // But include its internal alternatives
                if (isset($result['alternatives'])) {
                    foreach ($result['alternatives'] as $alt) {
                        $alt['_source'] = $source;
                        $alternatives[] = $alt;
                    }
                }
                continue;
            }
            
            // Add this source as an alternative
            $result['_source'] = $source;
            $alternatives[] = $result;
            
            // Add its internal alternatives too
            if (isset($result['alternatives'])) {
                foreach ($result['alternatives'] as $alt) {
                    $alt['_source'] = $source;
                    $alternatives[] = $alt;
                }
            }
        }
        
        // Sort by confidence score
        usort($alternatives, function($a, $b) {
            $aScore = $a['confidence']['score'] ?? 0;
            $bScore = $b['confidence']['score'] ?? 0;
            return $bScore <=> $aScore;
        });
        
        // Limit to top 5 alternatives
        return array_slice($alternatives, 0, 5);
    }
}
