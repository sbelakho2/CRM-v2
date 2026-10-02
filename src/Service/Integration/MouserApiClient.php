<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Service\PartMatchConfidenceCalculator;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
 *
 * @phpstan-type PriceBreak array{quantity: int, price: float, currency: string|null}
 * @phpstan-type MouserApiError array{PropertyName?: string, Message?: string}
 * @phpstan-type MouserSearchResponse array{
 *     SearchResults?: array{Parts?: list<array<string, mixed>>, NumberOfResult?: int},
 *     Errors?: list<MouserApiError>
 * }
 * @phpstan-type MouserPart array{
 *     mpn: string,
 *     manufacturer: string|null,
 *     description: string|null,
 *     datasheet: string|null,
 *     pricing: list<PriceBreak>,
 *     stock: int,
 *     leadtime_days: int,
 *     mouser_part_number: string|null,
 *     lifecycle: string|null,
 *     rohs: string|null,
 *     category: string|null,
 *     image_url: string|null,
 *     product_url: string|null,
 *     _all_matches_count: int,
 *     moq: int,
 *     pack_quantity: int|null,
 *     multiple_quantity: int|null,
 *     confidence?: array{score: int, level: string, reasons: list<string>, warnings: list<string>, requiresReview: bool},
 *     alternatives?: list<array<string, mixed>>,
 *     lifecycle_warning?: string|null
 * }
 */
class MouserApiClient
{
    private const BASE_URL = 'https://api.mouser.com/api/v1';
    private const RATE_LIMIT_DELAY = 1000000; // 1s between requests (1 req/sec) — prevents 403 throttling
    private const MAX_ALTERNATIVES = 3; // Store top 3 alternatives
    private const MAX_VARIANT_ATTEMPTS = 5; // Max MPN variants to try before giving up
    private const RATE_LIMIT_BACKOFF = 5.0; // Seconds to wait after a 403 rate limit
    
    // Lifecycle statuses that require warnings
    public const LIFECYCLE_WARNING = ['nrnd', 'not recommended for new design', 'end of life', 'eol', 'last time buy', 'ltb'];
    public const LIFECYCLE_CRITICAL = ['obsolete', 'discontinued'];
    
    private float $lastRequestTime = 0;
    private bool $apiKeyInvalid = false; // Fail-fast: stop trying after first invalid key error
    private float $rateLimitedUntil = 0; // Timestamp: pause all requests until this time
    private int $consecutiveRateLimits = 0; // Escalating backoff counter

    // Not promoted so the property can be non-nullable: the constructor
    // always falls back to a local calculator when none is injected.
    private PartMatchConfidenceCalculator $confidenceCalculator;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private string $apiKey,
        ?PartMatchConfidenceCalculator $confidenceCalculator = null
    ) {
        $this->confidenceCalculator = $confidenceCalculator ?? new PartMatchConfidenceCalculator();
    }

    /**
     * Check whether the API is currently rate-limited (after a 403).
     */
    public function isRateLimited(): bool
    {
        return $this->rateLimitedUntil > microtime(true);
    }

    /**
     * Check whether the API was rate-limited at the given timestamp snapshot.
     */
    private function isRateLimitedAt(float $rateLimitedUntil): bool
    {
        return $rateLimitedUntil > microtime(true);
    }

    /**
     * Search for a part by manufacturer part number with confidence scoring
     *
     * @param string $partNumber The MPN to search for
     * @param string|null $manufacturer Optional manufacturer name for better matching
     * @param string|null $description Optional description for confidence calculation
     * @param bool $tryVariants Whether to try MPN variants if exact match fails
     *
     * @return MouserPart|null Returns part data with 'confidence' array and 'alternatives' included
     */
    public function searchByPartNumber(
        string $partNumber,
        ?string $manufacturer = null,
        ?string $description = null,
        bool $tryVariants = true
    ): ?array {
        // Fail-fast: if we already know the API key is invalid, don't waste time
        if ($this->apiKeyInvalid) {
            return null;
        }

        $cacheKey = 'mouser_part_v3_' . md5($partNumber . ($manufacturer ?? '') . ($description ?? ''));

        /** @var MouserPart|null */
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($partNumber, $manufacturer, $description, $tryVariants): ?array {
            $item->expiresAfter(86400); // Cache for 24 hours
            
            // Try exact search first
            $searchResult = $this->executePartSearchWithAlternatives($partNumber);

            // Rate-limit snapshot taken before the loop: reading the property
            // directly (instead of calling isRateLimited()) keeps the property
            // state un-narrowed for the in-loop checks below. The in-loop
            // isRateLimited() checks still guard every actual API attempt.
            $rateLimitSnapshot = $this->rateLimitedUntil;

            // If no results, rate-limit check, and variants enabled, try variants
            if ($searchResult === null && $tryVariants && !$this->isRateLimitedAt($rateLimitSnapshot)) {
                $variants = $this->confidenceCalculator->generateMpnVariants($partNumber);
                $attemptCount = 0;
                foreach ($variants as $variant) {
                    if (!is_string($variant) || $variant === $partNumber) continue;
                    if ($attemptCount >= self::MAX_VARIANT_ATTEMPTS) break;
                    if ($this->isRateLimited()) break; // Stop if we hit rate limit
                    
                    $searchResult = $this->executePartSearchWithAlternatives($variant);
                    $attemptCount++;
                    if ($searchResult !== null) {
                        $this->logger->info('Found part using MPN variant', [
                            'original' => $partNumber,
                            'variant' => $variant
                        ]);
                        break;
                    }
                }
            }
            
            // ── Keyword search fallback ──
            // Part number search uses mouserPartNumber field which is very strict.
            // Keyword search is broader and can find parts by description fragments.
            if ($searchResult === null && !$this->isRateLimited()) {
                $searchResult = $this->keywordFallbackSearch($partNumber, $manufacturer, $description);
                if ($searchResult !== null) {
                    $this->logger->info('Found part using keyword fallback search', [
                        'mpn' => $partNumber,
                    ]);
                }
            }
            
            if ($searchResult === null) {
                if ($this->isRateLimited()) {
                    // Don't cache rate-limited nulls — retry immediately on next call
                    $item->expiresAfter(1);
                    $this->logger->info('Not caching rate-limited miss', ['mpn' => $partNumber]);
                } else {
                    $item->expiresAfter(300); // Cache genuine misses only 5 minutes
                }
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
     * Keyword fallback search — used when Part Number Search returns nothing.
     *
     * Strategy:
     * 1. Search the MPN as a keyword (broader match)
     * 2. If manufacturer known, search "manufacturer MPN"
     * 3. Score all results and pick the best match
     *
     * @return array{selected: MouserPart, alternatives: list<MouserPart>}|null
     */
    private function keywordFallbackSearch(string $partNumber, ?string $manufacturer, ?string $description): ?array
    {
        // Property read into a local (instead of isRateLimited()) so the
        // property state stays un-narrowed for the rate-limit check inside
        // the candidate loop below; both checks are evaluated identically.
        $rateLimitSnapshot = $this->rateLimitedUntil;
        if ($this->apiKeyInvalid || $this->isRateLimitedAt($rateLimitSnapshot)) {
            return null;
        }
        
        // Strategy 1: Search MPN as keyword
        $keywords = $this->searchByKeyword($partNumber, 20);
        
        // Strategy 2: If manufacturer known and few/no results, add manufacturer
        if (count($keywords) < 3 && $manufacturer) {
            $mfrKeywords = $this->searchByKeyword($manufacturer . ' ' . $partNumber, 20);
            // Merge without duplicates by MPN
            $existingMpns = array_column($keywords, 'mpn');
            foreach ($mfrKeywords as $k) {
                if (!in_array($k['mpn'], $existingMpns, true)) {
                    $keywords[] = $k;
                }
            }
        }
        
        if (empty($keywords)) {
            return null;
        }
        
        // Now do a proper part search for the best-looking keyword result
        // Score keyword results by MPN similarity to our target
        $normalizedTarget = $this->confidenceCalculator->normalizeMpn($partNumber);
        $scored = [];
        foreach ($keywords as $kw) {
            $normalizedResult = $this->confidenceCalculator->normalizeMpn($kw['mpn'] ?? '');
            $similarity = 0.0;
            similar_text($normalizedTarget, $normalizedResult, $similarity);
            
            // Bonus for exact containment
            if (str_contains($normalizedResult, $normalizedTarget) || str_contains($normalizedTarget, $normalizedResult)) {
                $similarity += 30;
            }
            
            $scored[] = ['kw' => $kw, 'similarity' => $similarity];
        }
        
        usort($scored, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        
        // Try the top 3 candidates via full part search
        $tried = 0;
        foreach ($scored as $candidate) {
            if ($tried >= 3 || $this->isRateLimited()) break;
            $candidateMpn = $candidate['kw']['mpn'] ?? '';
            if (empty($candidateMpn)) continue;
            
            $result = $this->executePartSearchWithAlternatives($candidateMpn);
            if ($result !== null) {
                return $result;
            }
            $tried++;
        }
        
        return null;
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
     * @return array{selected: MouserPart, alternatives: list<MouserPart>}|null
     */
    private function executePartSearchWithAlternatives(string $partNumber): ?array
    {
        // Fail-fast: if API key is invalid, don't even try
        if ($this->apiKeyInvalid) {
            return null;
        }
        
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

            /** @var MouserSearchResponse $data */
            $data = $response->toArray();

            // Successful response — reset rate-limit counter
            $this->consecutiveRateLimits = 0;

            if (isset($data['Errors']) && !empty($data['Errors'])) {
                // Detect invalid API key — fail-fast for all subsequent calls
                foreach ($data['Errors'] as $err) {
                    if (($err['PropertyName'] ?? '') === 'API Key' || str_contains($err['Message'] ?? '', 'Invalid')) {
                        $this->apiKeyInvalid = true;
                        $this->logger->error('Mouser API key is invalid — disabling all further Mouser requests', [
                            'part_number' => $partNumber,
                        ]);
                        return null;
                    }
                }
                
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
            
        } catch (HttpExceptionInterface $e) {
            // HTTP-level failures: 403 is Mouser's rate-limit signal, 401/403
            // with invalid key handling for authentication errors.
            $statusCode = $e->getResponse()->getStatusCode();

            if ($statusCode === 401) {
                $this->apiKeyInvalid = true;
                $this->logger->error('Mouser API key is invalid (HTTP 401) — disabling all further Mouser requests', [
                    'part_number' => $partNumber,
                ]);
            } elseif ($statusCode === 403) {
                $this->consecutiveRateLimits++;
                $backoff = self::RATE_LIMIT_BACKOFF * $this->consecutiveRateLimits;
                $this->rateLimitedUntil = microtime(true) + $backoff;
                $this->logger->warning('Mouser API rate-limited (HTTP 403) — backing off', [
                    'part_number' => $partNumber,
                    'backoff_seconds' => $backoff,
                    'consecutive_403s' => $this->consecutiveRateLimits,
                ]);
            } else {
                $this->logger->error('Mouser API request failed with HTTP status', [
                    'part_number' => $partNumber,
                    'status_code' => $statusCode,
                    'error' => $e->getMessage(),
                ]);
            }
            return null;
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
     *
     * @param array<string, mixed> $part
     * @return MouserPart
     */
    private function formatPartResult(array $part, string $requestedMpn, int $totalCount): array
    {
        /** @var list<array<string, mixed>> $priceBreaks */
        $priceBreaks = $part['PriceBreaks'] ?? [];
        $pricingData = $this->parsePricing($priceBreaks);
        $leadTimeRaw = $part['LeadTime'] ?? '';

        return [
            'mpn' => $this->toNullableString($part['ManufacturerPartNumber'] ?? null) ?? $requestedMpn,
            'manufacturer' => $this->toNullableString($part['Manufacturer'] ?? null),
            'description' => $this->toNullableString($part['Description'] ?? null),
            'datasheet' => $this->toNullableString($part['DataSheetUrl'] ?? null),
            'pricing' => $pricingData['breaks'],
            'stock' => $this->toInt($part['AvailabilityInStock'] ?? 0),
            'leadtime_days' => $this->parseLeadTime(is_string($leadTimeRaw) ? $leadTimeRaw : ''),
            'mouser_part_number' => $this->toNullableString($part['MouserPartNumber'] ?? null),
            'lifecycle' => $this->toNullableString($part['LifecycleStatus'] ?? null),
            'rohs' => $this->toNullableString($part['RohsStatus'] ?? null),
            'category' => $this->toNullableString($part['Category'] ?? null),
            'image_url' => $this->toNullableString($part['ImagePath'] ?? null),
            'product_url' => $this->buildProductUrl($this->toNullableString($part['MouserPartNumber'] ?? null)),
            '_all_matches_count' => $totalCount,
            // MOQ and packaging info for quantity handling
            'moq' => $pricingData['moq'],
            'pack_quantity' => $pricingData['pack_quantity'],
            'multiple_quantity' => $pricingData['multiple_quantity'],
        ];
    }

    /**
     * Normalize a raw API/JSON value to ?string: null passes through, scalars
     * are stringified (mirrors weak-mode coercion), non-scalars degrade to null.
     */
    private function toNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Normalize a raw API/JSON value to int: numeric values are cast
     * (mirrors weak-mode coercion), everything else degrades to 0.
     */
    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
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
     * @param list<array<string, mixed>> $parts
     * @return list<array{score: int, part: array<string, mixed>}>
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
     *
     * @param array<string, mixed> $part
     */
    private function calculatePartScore(array $part, string $normalizedRequested): int
    {
        $score = 0;
        $normalizedMpn = $this->confidenceCalculator->normalizeMpn(
            $this->toNullableString($part['ManufacturerPartNumber'] ?? null) ?? ''
        );

        // Exact MPN match: +100 points
        if ($normalizedMpn === $normalizedRequested) {
            $score += 100;
        }

        // Active lifecycle: +30 points (penalize obsolete/NRND)
        $lifecycle = strtolower($this->toNullableString($part['LifecycleStatus'] ?? null) ?? '');
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
        $stock = $this->toInt($part['AvailabilityInStock'] ?? 0);
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
     *
     * @return list<array{mpn: string|null, manufacturer: string|null, description: string|null, mouser_part_number: string|null}>
     */
    public function searchByKeyword(string $keyword, int $records = 10): array
    {
        if ($this->apiKeyInvalid) {
            return [];
        }
        
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

            /** @var MouserSearchResponse $data */
            $data = $response->toArray();

            // Successful response — reset rate-limit counter
            $this->consecutiveRateLimits = 0;

            $parts = $data['SearchResults']['Parts'] ?? [];

            return array_map(function (array $part): array {
                return [
                    'mpn' => $this->toNullableString($part['ManufacturerPartNumber'] ?? null),
                    'manufacturer' => $this->toNullableString($part['Manufacturer'] ?? null),
                    'description' => $this->toNullableString($part['Description'] ?? null),
                    'mouser_part_number' => $this->toNullableString($part['MouserPartNumber'] ?? null),
                ];
            }, $parts);
            
        } catch (HttpExceptionInterface $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            // Detect HTTP 403 — rate limit
            if ($statusCode === 403) {
                $this->consecutiveRateLimits++;
                $backoff = self::RATE_LIMIT_BACKOFF * $this->consecutiveRateLimits;
                $this->rateLimitedUntil = microtime(true) + $backoff;
                $this->logger->warning('Mouser keyword search rate-limited (HTTP 403) — backing off', [
                    'keyword' => $keyword,
                    'backoff_seconds' => $backoff,
                ]);
            } elseif ($statusCode === 401) {
                $this->apiKeyInvalid = true;
                $this->logger->error('Mouser API key is invalid (HTTP 401) — disabling all further Mouser requests', [
                    'keyword' => $keyword,
                ]);
            } else {
                $this->logger->error('Mouser keyword search failed with HTTP status', [
                    'keyword' => $keyword,
                    'status_code' => $statusCode,
                    'error' => $e->getMessage(),
                ]);
            }
            return [];
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
     * @return array{breaks: list<PriceBreak>, moq: int, pack_quantity: int|null, multiple_quantity: int|null}
     * @param list<array<string, mixed>> $priceBreaks
     */
    private function parsePricing(array $priceBreaks): array
    {
        /** @var list<PriceBreak> $pricing */
        $pricing = [];
        $quantities = [];

        foreach ($priceBreaks as $break) {
            $qtyRaw = $break['Quantity'] ?? 0;
            $qty = is_numeric($qtyRaw) ? (int) $qtyRaw : 0;
            $quantities[] = $qty;

            $priceRaw = $break['Price'] ?? '0';

            $pricing[] = [
                'quantity' => $qty,
                'price' => (float) str_replace(['$', ','], '', is_scalar($priceRaw) ? (string) $priceRaw : '0'),
                'currency' => $this->toNullableString($break['Currency'] ?? null)
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
        
        // If rate-limited from a 403, wait until backoff expires
        if ($now < $this->rateLimitedUntil) {
            $waitSeconds = $this->rateLimitedUntil - $now;
            $this->logger->info('Waiting for Mouser rate-limit backoff', [
                'wait_seconds' => round($waitSeconds, 1),
            ]);
            usleep((int)($waitSeconds * 1000000));
        }
        
        // Normal rate limiting between requests
        $now = microtime(true);
        $timeSinceLastRequest = ($now - $this->lastRequestTime) * 1000000;
        
        if ($timeSinceLastRequest < self::RATE_LIMIT_DELAY) {
            $sleepTime = self::RATE_LIMIT_DELAY - $timeSinceLastRequest;
            usleep((int) $sleepTime);
        }
        
        $this->lastRequestTime = microtime(true);
    }
}
