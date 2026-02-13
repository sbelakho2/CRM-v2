<?php

namespace App\Service\Integration;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * DigiKey API Client
 * 
 * Documentation: https://developer.digikey.com/
 * Note: Requires OAuth2 authentication
 */
class DigiKeyApiClient
{
    private const BASE_URL = 'https://api.digikey.com';
    private const RATE_LIMIT_DELAY = 200000; // 200ms between requests (5 req/sec)
    
    private float $lastRequestTime = 0;
    private ?string $accessToken = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private string $clientId,
        private string $clientSecret
    ) {}

    /**
     * Search for a part by part number
     */
    public function searchByPartNumber(string $partNumber): ?array
    {
        $cacheKey = 'digikey_part_' . md5($partNumber);
        
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($partNumber) {
            $item->expiresAfter(86400); // Cache for 24 hours
            
            $token = $this->getAccessToken();
            
            if (!$token) {
                $this->logger->error('DigiKey authentication failed');
                return null;
            }
            
            $this->respectRateLimit();
            
            try {
                // Use Products v4 Keyword Search (POST) — searches by MPN across catalog
                $response = $this->httpClient->request('POST', self::BASE_URL . '/products/v4/search/keyword', [
                    'json' => [
                        'Keywords' => $partNumber,
                        'Limit' => 5,
                        'Offset' => 0,
                        'FilterParametersRequest' => new \stdClass(),
                        'SortOptions' => [
                            'Field' => 'None',
                            'SortOrder' => 'Ascending',
                        ],
                    ],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'X-DIGIKEY-Client-Id' => $this->clientId,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ]
                ]);

                $data = $response->toArray();
                $parts = $data['Products'] ?? $data['ExactManufacturerProducts'] ?? [];
                
                if (empty($parts)) {
                    return null;
                }
                
                // Find the best match — prefer exact MPN match
                $part = null;
                $normalizedSearch = strtolower(str_replace(['-', ' ', '.'], '', $partNumber));
                foreach ($parts as $candidate) {
                    $candidateMpn = strtolower(str_replace(['-', ' ', '.'], '', $candidate['ManufacturerPartNumber'] ?? ''));
                    if ($candidateMpn === $normalizedSearch) {
                        $part = $candidate;
                        break;
                    }
                }
                // Fall back to first result if no exact match
                $part = $part ?? $parts[0];
                
                // Merge pricing from ALL ProductVariations (cut-tape, reel, tray, etc.)
                // Each variation has its own pricing and MOQ. We combine them all
                // and let the pricing engine select the best break for the customer's qty.
                $allPricingBreaks = [];
                $smallestMoq = PHP_INT_MAX;
                $bestPackQty = null;
                $bestMultipleQty = null;
                
                // Try top-level StandardPricing first
                if (!empty($part['StandardPricing'])) {
                    $topPricing = $this->parsePricing($part['StandardPricing']);
                    $allPricingBreaks = array_merge($allPricingBreaks, $topPricing['breaks']);
                    $smallestMoq = min($smallestMoq, $topPricing['moq']);
                }
                
                // Merge pricing from all variations
                foreach ($part['ProductVariations'] ?? [] as $variation) {
                    $varPricing = $this->parsePricing($variation['StandardPricing'] ?? []);
                    if (!empty($varPricing['breaks'])) {
                        $allPricingBreaks = array_merge($allPricingBreaks, $varPricing['breaks']);
                        $smallestMoq = min($smallestMoq, $varPricing['moq']);
                        if ($varPricing['pack_quantity'] !== null) {
                            $bestPackQty = $varPricing['pack_quantity'];
                        }
                        if ($varPricing['multiple_quantity'] !== null) {
                            $bestMultipleQty = $varPricing['multiple_quantity'];
                        }
                    }
                }
                
                // Also check UnitPrice at top level as a fallback price point
                if (isset($part['UnitPrice']) && is_numeric($part['UnitPrice']) && (float) $part['UnitPrice'] > 0) {
                    $allPricingBreaks[] = [
                        'quantity' => 1,
                        'price' => (float) $part['UnitPrice'],
                        'currency' => null,
                    ];
                }
                
                // Deduplicate and sort by quantity
                $uniqueBreaks = [];
                foreach ($allPricingBreaks as $break) {
                    $key = $break['quantity'];
                    if (!isset($uniqueBreaks[$key]) || $break['price'] < $uniqueBreaks[$key]['price']) {
                        $uniqueBreaks[$key] = $break;
                    }
                }
                $allPricingBreaks = array_values($uniqueBreaks);
                usort($allPricingBreaks, fn($a, $b) => $a['quantity'] <=> $b['quantity']);
                
                if ($smallestMoq === PHP_INT_MAX) $smallestMoq = 1;
                
                // Ensure description is a string (v4 may return objects/arrays)
                $description = $part['ProductDescription'] ?? $part['Description'] ?? null;
                if (is_array($description)) {
                    $description = $description['ProductDescription'] ?? $description['DetailedDescription'] ?? json_encode($description);
                }
                
                // Ensure manufacturer is a string
                $manufacturer = $part['Manufacturer']['Name'] ?? $part['ManufacturerName'] ?? null;
                if (is_array($manufacturer)) {
                    $manufacturer = $manufacturer['Name'] ?? json_encode($manufacturer);
                }
                
                return [
                    'mpn' => $part['ManufacturerPartNumber'] ?? $part['ManufacturerProductNumber'] ?? $partNumber,
                    'manufacturer' => $manufacturer,
                    'description' => is_string($description) ? $description : null,
                    'datasheet' => $part['DatasheetUrl'] ?? $part['PrimaryDatasheet'] ?? null,
                    'pricing' => $allPricingBreaks,
                    'stock' => (int) ($part['QuantityAvailable'] ?? $part['QuantityOnHand'] ?? 0),
                    'leadtime_days' => $this->parseLeadTime($part['ManufacturerLeadWeeks'] ?? 0),
                    'digikey_part_number' => $part['DigiKeyPartNumber'] ?? null,
                    'lifecycle' => is_string($part['ProductStatus'] ?? null) ? ($part['ProductStatus'] ?? null) : (is_string($part['ObsolescenceStatus'] ?? null) ? ($part['ObsolescenceStatus'] ?? null) : null),
                    'category' => is_string($part['Category']['Name'] ?? null) ? ($part['Category']['Name'] ?? null) : null,
                    // Direct product listing URL (v4 returns ProductUrl with full path)
                    'product_url' => $part['ProductUrl']
                        ?? ('https://www.digikey.com/en/products/filter?keywords=' . urlencode($part['ManufacturerPartNumber'] ?? $part['ManufacturerProductNumber'] ?? $partNumber)),
                    // MOQ and packaging info
                    'moq' => (int) ($part['MinimumOrderQuantity'] ?? $smallestMoq),
                    'pack_quantity' => $bestPackQty,
                    'multiple_quantity' => $bestMultipleQty,
                ];
                
            } catch (\Exception $e) {
                $this->logger->error('DigiKey API request failed', [
                    'part_number' => $partNumber,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        });
    }

    /**
     * Get OAuth2 access token
     */
    private function getAccessToken(): ?string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }
        
        return $this->cache->get('digikey_access_token', function (ItemInterface $item) {
            $item->expiresAfter(3600); // Token valid for 1 hour
            
            try {
                // DigiKey uses client credentials flow
                $response = $this->httpClient->request('POST', 'https://api.digikey.com/v1/oauth2/token', [
                    'body' => [
                        'client_id' => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'grant_type' => 'client_credentials'
                    ]
                ]);

                $data = $response->toArray();
                $this->accessToken = $data['access_token'] ?? null;
                
                return $this->accessToken;
                
            } catch (\Exception $e) {
                $this->logger->error('DigiKey OAuth failed', [
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        });
    }

    /**
     * Parse pricing with MOQ detection
     * 
     * @return array{breaks: array, moq: int, pack_quantity: int|null, multiple_quantity: int|null}
     */
    private function parsePricing(array $pricing): array
    {
        $result = [];
        $quantities = [];
        
        foreach ($pricing as $tier) {
            $qty = (int) ($tier['BreakQuantity'] ?? 0);
            $quantities[] = $qty;
            
            $currency = $tier['Currency'] ?? $tier['CurrencyCode'] ?? null;

            $result[] = [
                'quantity' => $qty,
                'price' => (float) ($tier['UnitPrice'] ?? 0),
                'currency' => $currency
            ];
        }
        
        // MOQ from first break
        $moq = !empty($quantities) ? min($quantities) : 1;
        
        // Detect pack quantity from increments
        $packQuantity = null;
        $multipleQuantity = null;
        
        if (count($quantities) >= 2) {
            sort($quantities);
            $diffs = [];
            for ($i = 1; $i < count($quantities); $i++) {
                $diff = $quantities[$i] - $quantities[$i - 1];
                if ($diff > 0) {
                    $diffs[] = $diff;
                }
            }
            
            if (!empty($diffs)) {
                $gcd = array_reduce($diffs, fn($a, $b) => $this->gcd($a, $b), $diffs[0]);
                if ($gcd > 1 && $moq % $gcd === 0) {
                    $packQuantity = $gcd;
                    $multipleQuantity = $gcd;
                }
            }
        }
        
        return [
            'breaks' => $result,
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

    private function parseLeadTime(int $weeks): int
    {
        return $weeks * 7; // Convert weeks to days
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
