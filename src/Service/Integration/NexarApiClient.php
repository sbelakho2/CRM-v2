<?php

namespace App\Service\Integration;

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

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private string $clientId,
        private string $clientSecret
    ) {}

    /**
     * Search for a part by MPN
     */
    public function searchByPartNumber(string $partNumber): ?array
    {
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
      }
      sellers {
        company {
          name
        }
        offers {
          inventoryLevel
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
                $sellers = $result['sellers'] ?? [];
                
                // Find best pricing from all sellers
                $allPricing = [];
                $maxStock = 0;
                
                foreach ($sellers as $seller) {
                    foreach ($seller['offers'] ?? [] as $offer) {
                        $stock = $offer['inventoryLevel'] ?? 0;
                        $maxStock = max($maxStock, $stock);
                        
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
                
                return [
                    'mpn' => $part['mpn'] ?? $partNumber,
                    'manufacturer' => $part['manufacturer']['name'] ?? null,
                    'description' => $part['shortDescription'] ?? null,
                    'datasheet' => $part['bestDatasheet']['url'] ?? null,
                    'pricing' => array_slice($allPricing, 0, 5), // Top 5 price breaks
                    'stock' => $maxStock,
                    'leadtime_days' => 0, // Nexar doesn't provide lead time
                    'specs' => $this->parseSpecs($part['specs'] ?? []),
                ];
                
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
