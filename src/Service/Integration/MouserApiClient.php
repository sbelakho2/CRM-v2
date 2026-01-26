<?php

namespace App\Service\Integration;

use App\Service\PartMatchConfidenceCalculator;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Mouser API Client
 * 
 * Documentation: https://www.mouser.com/api-hub/
 * 
 * Enhanced with:
 * - Confidence scoring for part matches
 * - Fuzzy MPN matching with variants
 * - Best-match selection from multiple results
 * - Alternative candidates storage (top 3)
 * - Lifecycle status indicators
 */
class MouserApiClient
{
    private const BASE_URL = 'https://api.mouser.com/api/v1';
    private const RATE_LIMIT_DELAY = 100000; // 100ms between requests (10 req/sec)
    private const MAX_ALTERNATIVES = 3; // Store top 3 alternatives
    
    // Lifecycle statuses that require warnings
    public const LIFECYCLE_WARNING = ['nrnd', 'not recommended for new design', 'end of life', 'eol', 'last time buy', 'ltb'];
    public const LIFECYCLE_CRITICAL = ['obsolete', 'discontinued'];
    
    private float $lastRequestTime = 0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private string $apiKey,
        private ?PartMatchConfidenceCalculator $confidenceCalculator = null
    ) {
        $this->confidenceCalculator ??= new PartMatchConfidenceCalculator();
    }

    /**
     * Search for a part by manufacturer part number with confidence scoring
     * 
     * @param string $partNumber The MPN to search for
     * @param string|null $manufacturer Optional manufacturer name for better matching
     * @param string|null $description Optional description for confidence calculation
     * @param bool $tryVariants Whether to try MPN variants if exact match fails
     * 
     * @return array|null Returns part data with 'confidence' array and 'alternatives' included
     */
    public function searchByPartNumber(
        string $partNumber,
        ?string $manufacturer = null,
        ?string $description = null,
        bool $tryVariants = true
    ): ?array {
        $cacheKey = 'mouser_part_v2_' . md5($partNumber . ($manufacturer ?? ''));
        
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($partNumber, $manufacturer, $description, $tryVariants) {
            $item->expiresAfter(86400); // Cache for 24 hours
            
            // Try exact search first
            $searchResult = $this->executePartSearchWithAlternatives($partNumber);
            
            // If no results and variants enabled, try variants
            if ($searchResult === null && $tryVariants) {
                $variants = $this->confidenceCalculator->generateMpnVariants($partNumber);
                foreach ($variants as $variant) {
                    if ($variant === $partNumber) continue;
                    
                    $searchResult = $this->executePartSearchWithAlternatives($variant);
                    if ($searchResult !== null) {
                        $this->logger->info('Found part using MPN variant', [
                            'original' => $partNumber,
                            'variant' => $variant
                        ]);
                        break;
                    }
                }
            }
            
            if ($searchResult === null) {
                return null;
            }
            
            $result = $searchResult['selected'];
            $alternatives = $searchResult['alternatives'];
            
            // Calculate confidence score for selected part
            $confidence = $this->confidenceCalculator->calculateConfidence(
                $partNumber,
                $manufacturer,
                $description,
                $result
            );
            
            $result['confidence'] = $confidence;
            
            // Calculate confidence for alternatives
            foreach ($alternatives as $i => $alt) {
                $alternatives[$i]['confidence'] = $this->confidenceCalculator->calculateConfidence(
                    $partNumber,
                    $manufacturer,
                    $description,
                    $alt
                );
            }
            $result['alternatives'] = $alternatives;
            
            // Add lifecycle warning flags
            $result['lifecycle_warning'] = $this->getLifecycleWarning($result['lifecycle']);
            
            // Log warnings for low confidence matches
            if ($confidence['requiresReview']) {
                $this->logger->warning('Part match requires review', [
                    'requested_mpn' => $partNumber,
                    'matched_mpn' => $result['mpn'],
                    'confidence_score' => $confidence['score'],
                    'confidence_level' => $confidence['level'],
                    'warnings' => $confidence['warnings'],
                    'alternatives_count' => count($alternatives)
                ]);
            }
            
            return $result;
        });
    }
    
    /**
     * Get lifecycle warning level
     */
    private function getLifecycleWarning(?string $lifecycle): ?string
    {
        if (!$lifecycle) {
            return null;
        }
        
        $lifecycleLower = strtolower($lifecycle);
        
        foreach (self::LIFECYCLE_CRITICAL as $term) {
            if (str_contains($lifecycleLower, $term)) {
                return 'critical'; // Red flag
            }
        }
        
        foreach (self::LIFECYCLE_WARNING as $term) {
            if (str_contains($lifecycleLower, $term)) {
                return 'warning'; // Yellow flag
            }
        }
        
        return null;
    }
    
    /**
     * Execute the actual API search and return selected + alternatives
     * 
     * @return array|null ['selected' => array, 'alternatives' => array[]]
     */
    private function executePartSearchWithAlternatives(string $partNumber): ?array
    {
        $this->respectRateLimit();
        
        try {
            $response = $this->httpClient->request('POST', self::BASE_URL . '/search/partnumber', [
                'json' => [
                    'SearchByPartRequest' => [
                        'mouserPartNumber' => $partNumber,
                        'partSearchOptions' => 'string'
                    ]
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'query' => [
                    'apiKey' => $this->apiKey
                ]
            ]);

            $data = $response->toArray();
            
            if (isset($data['Errors']) && !empty($data['Errors'])) {
                $this->logger->warning('Mouser API error', [
                    'part_number' => $partNumber,
                    'errors' => $data['Errors']
                ]);
                return null;
            }
            
            $parts = $data['SearchResults']['Parts'] ?? [];
            
            if (empty($parts)) {
                return null;
            }
            
            // Score and sort all parts
            $scoredParts = $this->scoreAndSortParts($parts, $partNumber);
            
            // Select the best matching part
            $selectedPart = $scoredParts[0]['part'];
            
            // Get alternatives (next best matches, excluding selected)
            $alternatives = [];
            for ($i = 1; $i < min(count($scoredParts), self::MAX_ALTERNATIVES + 1); $i++) {
                $altPart = $scoredParts[$i]['part'];
                $alternatives[] = $this->formatPartResult($altPart, $partNumber, count($parts));
            }
            
            return [
                'selected' => $this->formatPartResult($selectedPart, $partNumber, count($parts)),
                'alternatives' => $alternatives,
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Mouser API request failed', [
                'part_number' => $partNumber,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Format a part result into standard structure
     */
    private function formatPartResult(array $part, string $requestedMpn, int $totalCount): array
    {
        $pricingData = $this->parsePricing($part['PriceBreaks'] ?? []);
        
        return [
            'mpn' => $part['ManufacturerPartNumber'] ?? $requestedMpn,
            'manufacturer' => $part['Manufacturer'] ?? null,
            'description' => $part['Description'] ?? null,
            'datasheet' => $part['DataSheetUrl'] ?? null,
            'pricing' => $pricingData['breaks'],
            'stock' => (int) ($part['AvailabilityInStock'] ?? 0),
            'leadtime_days' => $this->parseLeadTime($part['LeadTime'] ?? ''),
            'mouser_part_number' => $part['MouserPartNumber'] ?? null,
            'lifecycle' => $part['LifecycleStatus'] ?? null,
            'rohs' => $part['RohsStatus'] ?? null,
            'category' => $part['Category'] ?? null,
            'image_url' => $part['ImagePath'] ?? null,
            'product_url' => $this->buildProductUrl($part['MouserPartNumber'] ?? null),
            '_all_matches_count' => $totalCount,
            // MOQ and packaging info for quantity handling
            'moq' => $pricingData['moq'],
            'pack_quantity' => $pricingData['pack_quantity'],
            'multiple_quantity' => $pricingData['multiple_quantity'],
        ];
    }
    
    /**
     * Build Mouser product URL for direct linking
     */
    private function buildProductUrl(?string $mouserPartNumber): ?string
    {
        if (!$mouserPartNumber) {
            return null;
        }
        return 'https://www.mouser.com/ProductDetail/' . urlencode($mouserPartNumber);
    }
    
    /**
     * Build Mouser search URL for a part number
     */
    public function buildSearchUrl(string $partNumber): string
    {
        return 'https://www.mouser.com/Search/Refine?Keyword=' . urlencode($partNumber);
    }
    
    /**
     * Score and sort all parts by quality criteria
     * 
     * @return array<int, array{score: int, part: array}>
     */
    private function scoreAndSortParts(array $parts, string $requestedMpn): array
    {
        $normalizedRequested = $this->confidenceCalculator->normalizeMpn($requestedMpn);
        
        $scored = [];
        foreach ($parts as $part) {
            $score = $this->calculatePartScore($part, $normalizedRequested);
            $scored[] = ['score' => $score, 'part' => $part];
        }
        
        // Sort by score descending
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        
        return $scored;
    }
    
    /**
     * Calculate score for a single part
     */
    private function calculatePartScore(array $part, string $normalizedRequested): int
    {
        $score = 0;
        $normalizedMpn = $this->confidenceCalculator->normalizeMpn($part['ManufacturerPartNumber'] ?? '');
        
        // Exact MPN match: +100 points
        if ($normalizedMpn === $normalizedRequested) {
            $score += 100;
        }
        
        // Active lifecycle: +30 points (penalize obsolete/NRND)
        $lifecycle = strtolower($part['LifecycleStatus'] ?? '');
        $isCritical = false;
        $isWarning = false;
        
        foreach (self::LIFECYCLE_CRITICAL as $term) {
            if (str_contains($lifecycle, $term)) {
                $isCritical = true;
                break;
            }
        }
        
        if (!$isCritical) {
            foreach (self::LIFECYCLE_WARNING as $term) {
                if (str_contains($lifecycle, $term)) {
                    $isWarning = true;
                    break;
                }
            }
        }
        
        if ($isCritical) {
            $score -= 50; // Heavy penalty for obsolete
        } elseif ($isWarning) {
            $score += 10; // Reduced bonus for NRND
        } else {
            $score += 30; // Full bonus for active
        }
        
        // In stock: +25 points
        $stock = (int) ($part['AvailabilityInStock'] ?? 0);
        if ($stock > 0) {
            $score += 25;
            // Bonus for high stock
            if ($stock > 1000) $score += 5;
            if ($stock > 10000) $score += 5;
        }
        
        // Has pricing: +15 points
        if (!empty($part['PriceBreaks'])) {
            $score += 15;
        }
        
        // Has datasheet: +5 points
        if (!empty($part['DataSheetUrl'])) {
            $score += 5;
        }
        
        return $score;
    }

    /**
     * Search for keyword (manufacturer name, category, etc.)
     */
    public function searchByKeyword(string $keyword, int $records = 10): array
    {
        $this->respectRateLimit();
        
        try {
            $response = $this->httpClient->request('POST', self::BASE_URL . '/search/keyword', [
                'json' => [
                    'SearchByKeywordRequest' => [
                        'keyword' => $keyword,
                        'records' => $records,
                        'startingRecord' => 0
                    ]
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'query' => [
                    'apiKey' => $this->apiKey
                ]
            ]);

            $data = $response->toArray();
            $parts = $data['SearchResults']['Parts'] ?? [];
            
            return array_map(function($part) {
                return [
                    'mpn' => $part['ManufacturerPartNumber'] ?? null,
                    'manufacturer' => $part['Manufacturer'] ?? null,
                    'description' => $part['Description'] ?? null,
                    'mouser_part_number' => $part['MouserPartNumber'] ?? null,
                ];
            }, $parts);
            
        } catch (\Exception $e) {
            $this->logger->error('Mouser keyword search failed', [
                'keyword' => $keyword,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Parse pricing from API response with MOQ and pack quantity detection
     * 
     * Mouser price breaks are formatted as:
     * - Quantity: minimum quantity for this price tier
     * - Price: unit price at this tier
     * - Currency: currency code
     * 
     * MOQ is derived from the first price break quantity.
     * Pack/Multiple quantity is inferred from the quantity increments.
     * 
     * @return array{breaks: array, moq: int, pack_quantity: int|null, multiple_quantity: int|null}
     */
    private function parsePricing(array $priceBreaks): array
    {
        $pricing = [];
        $quantities = [];
        
        foreach ($priceBreaks as $break) {
            $qty = (int) ($break['Quantity'] ?? 0);
            $quantities[] = $qty;
            
            $pricing[] = [
                'quantity' => $qty,
                'price' => (float) str_replace(['$', ','], '', $break['Price'] ?? '0'),
                'currency' => $break['Currency'] ?? null
            ];
        }
        
        // MOQ is the first price break quantity (minimum you can order)
        $moq = !empty($quantities) ? min($quantities) : 1;
        
        // Detect pack/multiple quantity from quantity increments
        $packQuantity = null;
        $multipleQuantity = null;
        
        if (count($quantities) >= 2) {
            sort($quantities);
            
            // Calculate GCD of all quantity differences to find the multiple
            $diffs = [];
            for ($i = 1; $i < count($quantities); $i++) {
                $diff = $quantities[$i] - $quantities[$i - 1];
                if ($diff > 0) {
                    $diffs[] = $diff;
                }
            }
            
            if (!empty($diffs)) {
                $gcd = array_reduce($diffs, fn($a, $b) => $this->gcd($a, $b), $diffs[0]);
                
                // If GCD > 1 and divides the MOQ evenly, it's likely the pack quantity
                if ($gcd > 1 && $moq % $gcd === 0) {
                    $packQuantity = $gcd;
                    $multipleQuantity = $gcd;
                }
            }
            
            // Check if MOQ itself suggests a pack quantity (common: 5, 10, 25, 50, 100)
            $commonPacks = [5, 10, 25, 50, 100, 250, 500, 1000];
            if ($packQuantity === null && in_array($moq, $commonPacks)) {
                $packQuantity = $moq;
                $multipleQuantity = $moq;
            }
        }
        
        return [
            'breaks' => $pricing,
            'moq' => $moq,
            'pack_quantity' => $packQuantity,
            'multiple_quantity' => $multipleQuantity,
        ];
    }
    
    /**
     * Calculate Greatest Common Divisor
     */
    private function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            $t = $b;
            $b = $a % $b;
            $a = $t;
        }
        return $a;
    }

    private function parseLeadTime(string $leadTime): int
    {
        // Parse strings like "10 weeks", "5 days", etc.
        if (preg_match('/(\d+)\s*(week|day)/i', $leadTime, $matches)) {
            $value = (int) $matches[1];
            $unit = strtolower($matches[2]);
            
            return $unit === 'week' ? $value * 7 : $value;
        }
        
        return 0;
    }

    private function respectRateLimit(): void
    {
        $now = microtime(true);
        $timeSinceLastRequest = ($now - $this->lastRequestTime) * 1000000;
        
        if ($timeSinceLastRequest < self::RATE_LIMIT_DELAY) {
            $sleepTime = self::RATE_LIMIT_DELAY - $timeSinceLastRequest;
            usleep((int) $sleepTime);
        }
        
        $this->lastRequestTime = microtime(true);
    }
}
