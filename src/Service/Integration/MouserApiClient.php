<?php

namespace App\Service\Integration;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Mouser API Client
 * 
 * Documentation: https://www.mouser.com/api-hub/
 */
class MouserApiClient
{
    private const BASE_URL = 'https://api.mouser.com/api/v1';
    private const RATE_LIMIT_DELAY = 100000; // 100ms between requests (10 req/sec)
    
    private float $lastRequestTime = 0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private string $apiKey
    ) {}

    /**
     * Search for a part by manufacturer part number
     */
    public function searchByPartNumber(string $partNumber): ?array
    {
        $cacheKey = 'mouser_part_' . md5($partNumber);
        
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($partNumber) {
            $item->expiresAfter(86400); // Cache for 24 hours
            
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
                
                // Return first matching part
                $part = $parts[0];
                
                return [
                    'mpn' => $part['ManufacturerPartNumber'] ?? $partNumber,
                    'manufacturer' => $part['Manufacturer'] ?? null,
                    'description' => $part['Description'] ?? null,
                    'datasheet' => $part['DataSheetUrl'] ?? null,
                    'pricing' => $this->parsePricing($part['PriceBreaks'] ?? []),
                    'stock' => (int) ($part['AvailabilityInStock'] ?? 0),
                    'leadtime_days' => $this->parseLeadTime($part['LeadTime'] ?? ''),
                    'mouser_part_number' => $part['MouserPartNumber'] ?? null,
                    'lifecycle' => $part['LifecycleStatus'] ?? null,
                    'rohs' => $part['RohsStatus'] ?? null,
                ];
                
            } catch (\Exception $e) {
                $this->logger->error('Mouser API request failed', [
                    'part_number' => $partNumber,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        });
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

    private function parsePricing(array $priceBreaks): array
    {
        $pricing = [];
        
        foreach ($priceBreaks as $break) {
            $pricing[] = [
                'quantity' => (int) ($break['Quantity'] ?? 0),
                'price' => (float) str_replace(['$', ','], '', $break['Price'] ?? '0'),
                'currency' => $break['Currency'] ?? 'USD'
            ];
        }
        
        return $pricing;
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
