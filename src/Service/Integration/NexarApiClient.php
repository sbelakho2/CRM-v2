<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Service\PartMatchConfidenceCalculator;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Nexar API Client
 *
 * Nexar is an electronics search engine that aggregates multiple distributors
 * Documentation: https://nexar.com/api
 */
class NexarApiClient
{
    private const BASE_URL = 'https://api.nexar.com/graphql';
    private const RATE_LIMIT_DELAY = 100000; // 100ms between requests
    
    private float $lastRequestTime = 0;
    private ?string $accessToken = null;
    private bool $quotaExceeded = false; // Fail-fast: stop trying after quota error

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private string $clientId,
        private string $clientSecret,
        private ?PartMatchConfidenceCalculator $confidenceCalculator = null
    ) {
        $this->confidenceCalculator ??= new PartMatchConfidenceCalculator();
    }

    /**
     * Search for a part by MPN
     */
    public function searchByPartNumber(string $partNumber): ?array
    {
        // Fail-fast: if we already exceeded the quota, don't waste time
        if ($this->quotaExceeded) {
            return null;
        }
        
        $cacheKey = 'nexar_part_' . md5($partNumber);
        
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($partNumber) {
            $item->expiresAfter(86400); // Cache for 24 hours
            
            $token = $this->getAccessToken();
            
            if (!$token) {
                $this->logger->error('Nexar authentication failed');
                return null;
            }
            
            $this->respectRateLimit();
            
            $query = <<<'GRAPHQL'
query SearchPart($mpn: String!) {
  supSearchMpn(q: $mpn, limit: 1) {
    results {
      part {
        mpn
        manufacturer {
          name
        }
        shortDescription
        specs {
          attribute {
            name
          }
          displayValue
        }
        bestDatasheet {
          url
        }
        sellers(authorizedOnly: false) {
          company {
            name
          }
          offers {
            inventoryLevel
            moq
            packaging
            prices {
              quantity
              price
              currency
            }
            clickUrl
            sku
          }
        }
      }
    }
  }
}
GRAPHQL;

            try {
                $response = $this->httpClient->request('POST', self::BASE_URL, [
                    'json' => [
                        'query' => $query,
                        'variables' => [
                            'mpn' => $partNumber
                        ]
                    ],
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json',
                    ]
                ]);

                $data = $response->toArray();
                
                if (isset($data['errors'])) {
                    // Detect quota exceeded — fail-fast for all subsequent calls
                    foreach ($data['errors'] as $err) {
                        if (str_contains($err['message'] ?? '', 'exceeded your part limit')) {
                            $this->quotaExceeded = true;
                            $this->logger->error('Nexar API quota exceeded — disabling all further Nexar requests', [
                                'part_number' => $partNumber,
                            ]);
                            return null;
                        }
                    }
                    
                    $this->logger->warning('Nexar API error', [
                        'part_number' => $partNumber,
                        'errors' => $data['errors']
                    ]);
                    return null;
                }
                
                $results = $data['data']['supSearchMpn']['results'] ?? [];
                
                if (empty($results)) {
                    return null;
                }
                
                $result = $results[0];
                $part = $result['part'] ?? [];
                $sellers = $part['sellers'] ?? [];
                
                // Find best pricing from all sellers
                $allPricing = [];
                $maxStock = 0;
                $minMoq = PHP_INT_MAX;
                
                foreach ($sellers as $seller) {
                    foreach ($seller['offers'] ?? [] as $offer) {
                        $stock = $offer['inventoryLevel'] ?? 0;
                        if (is_numeric($stock)) {
                            $maxStock = max($maxStock, (int) $stock);
                        }
                        
                        $offerMoq = $offer['moq'] ?? 1;
                        if (is_numeric($offerMoq) && $offerMoq > 0) {
                            $minMoq = min($minMoq, (int) $offerMoq);
                        }
                        
                        foreach ($offer['prices'] ?? [] as $price) {
                            $allPricing[] = [
                                'quantity' => $price['quantity'] ?? 0,
                                'price' => (float) ($price['price'] ?? 0),
                                'currency' => $price['currency'] ?? 'USD'
                            ];
                        }
                    }
                }
                
                // Sort pricing by quantity
                usort($allPricing, fn($a, $b) => $a['quantity'] <=> $b['quantity']);
                
                $apiResult = [
                    'mpn' => $part['mpn'] ?? $partNumber,
                    'manufacturer' => $part['manufacturer']['name'] ?? null,
                    'description' => $part['shortDescription'] ?? null,
                    'datasheet' => $part['bestDatasheet']['url'] ?? null,
                    'pricing' => array_slice($allPricing, 0, 5), // Top 5 price breaks
                    'stock' => $maxStock,
                    'moq' => ($minMoq === PHP_INT_MAX) ? 1 : $minMoq,
                    'leadtime_days' => 0, // Nexar doesn't provide lead time
                    'specs' => $this->parseSpecs($part['specs'] ?? []),
                ];

                $confidence = $this->confidenceCalculator->calculateConfidence(
                    $partNumber,
                    $part['manufacturer']['name'] ?? null,
                    $part['shortDescription'] ?? null,
                    $apiResult
                );
                $apiResult['confidence_score'] = $confidence['score'];
                $apiResult['confidence_level'] = $confidence['level'];
                $apiResult['confidence_reasons'] = $confidence['reasons'];
                $apiResult['confidence_warnings'] = $confidence['warnings'];

                return $apiResult;
                
            } catch (\Exception $e) {
                $this->logger->error('Nexar API request failed', [
                    'part_number' => $partNumber,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        });
    }

    private function getAccessToken(): ?string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }
        
        return $this->cache->get('nexar_access_token', function (ItemInterface $item) {
            $item->expiresAfter(3600); // Token valid for 1 hour
            
            try {
                $response = $this->httpClient->request('POST', 'https://identity.nexar.com/connect/token', [
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
                $this->logger->error('Nexar OAuth failed', [
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        });
    }

    private function parseSpecs(array $specs): array
    {
        $result = [];
        
        foreach ($specs as $spec) {
            $name = $spec['attribute']['name'] ?? null;
            $value = $spec['displayValue'] ?? null;
            
            if ($name && $value) {
                $result[$name] = $value;
            }
        }
        
        return $result;
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
