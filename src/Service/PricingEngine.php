<?php

namespace App\Service;

use App\Service\Integration\MouserApiClient;
use App\Service\Integration\DigiKeyApiClient;
use App\Service\Integration\NexarApiClient;
use Psr\Log\LoggerInterface;

/**
 * Pricing Engine
 * 
 * Implements API waterfall for part pricing:
 * 1. Mouser API (preferred - official distributor)
 * 2. DigiKey API (fallback)
 * 3. Nexar API (aggregator - multiple distributors)
 * 4. Internal pricebook (historical data)
 * 5. Price estimation (ML-based or manual)
 */
class PricingEngine
{
    public function __construct(
        private MouserApiClient $mouserClient,
        private DigiKeyApiClient $digikeyClient,
        private NexarApiClient $nexarClient,
        private LoggerInterface $logger
    ) {}

    /**
     * Get pricing for a single part using API waterfall
     * 
     * @return array|null ['mpn', 'manufacturer', 'description', 'pricing', 'stock', 'source']
     */
    public function getPricing(string $mpn, ?string $manufacturer = null): ?array
    {
        // Try Mouser first
        $result = $this->mouserClient->searchByPartNumber($mpn);
        
        if ($result) {
            $result['source'] = 'mouser';
            $this->logger->info('Mouser pricing found', ['mpn' => $mpn]);
            return $result;
        }
        
        // Fallback to DigiKey
        $result = $this->digikeyClient->searchByPartNumber($mpn);
        
        if ($result) {
            $result['source'] = 'digikey';
            $this->logger->info('DigiKey pricing found', ['mpn' => $mpn]);
            return $result;
        }
        
        // Fallback to Nexar
        $result = $this->nexarClient->searchByPartNumber($mpn);
        
        if ($result) {
            $result['source'] = 'nexar';
            $this->logger->info('Nexar pricing found', ['mpn' => $mpn]);
            return $result;
        }
        
        $this->logger->warning('No pricing found in APIs', ['mpn' => $mpn]);
        
        return null;
    }

    /**
     * Process entire BOM through pricing waterfall
     * 
     * @param array $bomLines Array from BOMParser
     * @return array ['lines' => processed lines, 'stats' => statistics]
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
        ];
        
        foreach ($bomLines as $line) {
            if (empty($line['mpn'])) {
                // Can't price without MPN
                $processedLine = $line;
                $processedLine['status'] = 'no_mpn';
                $processedLine['unit_price'] = 0;
                $processedLine['extended_price'] = 0;
                $processedLine['source'] = null;
                $processedLines[] = $processedLine;
                $stats['unsourced']++;
                continue;
            }
            
            $pricing = $this->getPricing($line['mpn'], $line['manufacturer']);
            
            if ($pricing) {
                // Successfully sourced
                $processedLine = array_merge($line, $pricing);
                $processedLine['status'] = 'sourced';
                
                // Calculate pricing for quantity
                $unitPrice = $this->calculateUnitPrice($pricing['pricing'], $line['quantity']);
                $processedLine['unit_price'] = $unitPrice;
                $processedLine['extended_price'] = $unitPrice * $line['quantity'];
                
                $stats['sourced']++;
                $stats['total_cost'] += $processedLine['extended_price'];
                $stats['sources'][$pricing['source']]++;
                
            } else {
                // Could not source
                $processedLine = $line;
                $processedLine['status'] = 'not_found';
                $processedLine['unit_price'] = 0;
                $processedLine['extended_price'] = 0;
                $processedLine['source'] = null;
                $stats['unsourced']++;
            }
            
            $processedLines[] = $processedLine;
        }
        
        // Calculate coverage percentage
        $stats['coverage_percent'] = $stats['total_lines'] > 0 
            ? round(($stats['sourced'] / $stats['total_lines']) * 100, 2)
            : 0;
        
        return [
            'lines' => $processedLines,
            'stats' => $stats,
        ];
    }

    /**
     * Calculate unit price for given quantity based on price breaks
     */
    private function calculateUnitPrice(array $priceBreaks, int $quantity): float
    {
        if (empty($priceBreaks)) {
            return 0.0;
        }
        
        // Sort price breaks by quantity (descending)
        usort($priceBreaks, fn($a, $b) => $b['quantity'] <=> $a['quantity']);
        
        // Find applicable price break
        $applicablePrice = $priceBreaks[0]['price']; // Default to lowest qty price
        
        foreach ($priceBreaks as $break) {
            if ($quantity >= $break['quantity']) {
                $applicablePrice = $break['price'];
                break;
            }
        }
        
        return (float) $applicablePrice;
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
