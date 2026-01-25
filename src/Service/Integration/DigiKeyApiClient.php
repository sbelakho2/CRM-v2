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
    private const BASE_URL = 'https://api.digikey.com/v1';
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
                $response = $this->httpClient->request('GET', self::BASE_URL . '/search/' . urlencode($partNumber) . '/productdetails', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'X-DIGIKEY-Client-Id' => $this->clientId,
                        'Accept' => 'application/json',
                    ]
                ]);

                $part = $response->toArray();
                
                $pricingData = $this->parsePricing($part['StandardPricing'] ?? []);
                
                return [
                    'mpn' => $part['ManufacturerPartNumber'] ?? $partNumber,
                    'manufacturer' => $part['Manufacturer']['Name'] ?? null,
                    'description' => $part['ProductDescription'] ?? null,
                    'datasheet' => $part['DatasheetUrl'] ?? null,
                    'pricing' => $pricingData['breaks'],
                    'stock' => (int) ($part['QuantityAvailable'] ?? 0),
                    'leadtime_days' => $this->parseLeadTime($part['ManufacturerLeadWeeks'] ?? 0),
                    'digikey_part_number' => $part['DigiKeyPartNumber'] ?? null,
                    'lifecycle' => $part['ProductStatus'] ?? null,
                    'category' => $part['Category']['Name'] ?? null,
                    // MOQ and packaging info
                    'moq' => (int) ($part['MinimumOrderQuantity'] ?? $pricingData['moq']),
                    'pack_quantity' => $pricingData['pack_quantity'],
                    'multiple_quantity' => (int) ($part['QuantityOnOrder'] ?? null) ?: $pricingData['multiple_quantity'],
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
            
            $result[] = [
                'quantity' => $qty,
                'price' => (float) ($tier['UnitPrice'] ?? 0),
                'currency' => 'USD'
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
