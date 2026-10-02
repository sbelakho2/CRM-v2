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
    private const HIT_CACHE_TTL = 86400;     // Successful lookups: 24 hours
    private const MISS_CACHE_TTL = 300;      // Null/failure results: 5 minutes (avoid cache poisoning)
    
    private float $lastRequestTime = 0;
    private ?string $accessToken = null;
    private bool $quotaExceeded = false; // Fail-fast: stop trying after quota error

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private string $clientId,
        private string $clientSecret,
        /** @var PartMatchConfidenceCalculator never null after the constructor seeds it */
        private ?PartMatchConfidenceCalculator $confidenceCalculator = null
    ) {
        $this->confidenceCalculator ??= new PartMatchConfidenceCalculator();
    }

    /**
     * Search for a part by MPN
     *
     * @return array<string, mixed>|null
     */
    public function searchByPartNumber(string $partNumber): ?array
    {
        // Fail-fast: if we already exceeded the quota, don't waste time
        if ($this->quotaExceeded) {
            return null;
        }
        
        $cacheKey = 'nexar_part_' . md5($partNumber);
        
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($partNumber) {
            $item->expiresAfter(self::HIT_CACHE_TTL);
            
            $token = $this->getAccessToken();
            
            if (!$token) {
                $this->logger->error('Nexar authentication failed');
                $item->expiresAfter(self::MISS_CACHE_TTL); // Don't poison the cache with failures
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

                /** @var array<string, mixed> $data */
                $data = $response->toArray();

                $errors = self::arrayOf($data, 'errors');
                if ($errors !== []) {
                    // Detect quota exceeded — fail-fast for all subsequent calls
                    foreach ($errors as $err) {
                        $message = is_array($err) ? ($err['message'] ?? '') : '';
                        if (is_string($message) && str_contains($message, 'exceeded your part limit')) {
                            $this->quotaExceeded = true;
                            $this->logger->error('Nexar API quota exceeded — disabling all further Nexar requests', [
                                'part_number' => $partNumber,
                            ]);
                            $item->expiresAfter(self::MISS_CACHE_TTL);
                            return null;
                        }
                    }

                    $this->logger->warning('Nexar API error', [
                        'part_number' => $partNumber,
                        'errors' => $errors
                    ]);
                    $item->expiresAfter(self::MISS_CACHE_TTL);
                    return null;
                }

                $supSearch = self::arrayOf(self::arrayOf($data, 'data'), 'supSearchMpn');
                $results = self::arrayOf($supSearch, 'results');

                if ($results === []) {
                    $item->expiresAfter(self::MISS_CACHE_TTL); // Genuine miss: short TTL only
                    return null;
                }

                $firstResult = $results[0] ?? null;
                $part = is_array($firstResult) ? self::arrayOf($firstResult, 'part') : [];
                $sellers = self::arrayOf($part, 'sellers');

                // Find best pricing from all sellers
                /** @var list<array{quantity: int, price: float, currency: string}> $allPricing */
                $allPricing = [];
                $maxStock = 0;
                $minMoq = PHP_INT_MAX;

                foreach ($sellers as $seller) {
                    if (!is_array($seller)) {
                        continue;
                    }
                    foreach (self::arrayOf($seller, 'offers') as $offer) {
                        if (!is_array($offer)) {
                            continue;
                        }
                        $stock = $offer['inventoryLevel'] ?? 0;
                        if (is_numeric($stock)) {
                            $maxStock = max($maxStock, (int) $stock);
                        }

                        $offerMoq = $offer['moq'] ?? 1;
                        if (is_numeric($offerMoq) && $offerMoq > 0) {
                            $minMoq = min($minMoq, (int) $offerMoq);
                        }

                        foreach (self::arrayOf($offer, 'prices') as $price) {
                            if (!is_array($price)) {
                                continue;
                            }
                            $quantity = $price['quantity'] ?? 0;
                            $priceValue = $price['price'] ?? 0;
                            $currency = $price['currency'] ?? 'USD';
                            $allPricing[] = [
                                'quantity' => is_numeric($quantity) ? (int) $quantity : 0,
                                'price' => is_numeric($priceValue) ? (float) $priceValue : 0.0,
                                'currency' => is_scalar($currency) ? (string) $currency : 'USD',
                            ];
                        }
                    }
                }

                // Sort pricing by quantity
                usort($allPricing, fn($a, $b) => $a['quantity'] <=> $b['quantity']);

                $manufacturer = self::stringOf(self::arrayOf($part, 'manufacturer'), 'name');
                $description = self::stringOf($part, 'shortDescription');

                /** @var array<string, mixed> $apiResult */
                $apiResult = [
                    'mpn' => self::stringOf($part, 'mpn') ?? $partNumber,
                    'manufacturer' => $manufacturer,
                    'description' => $description,
                    'datasheet' => self::stringOf(self::arrayOf($part, 'bestDatasheet'), 'url'),
                    'pricing' => array_slice($allPricing, 0, 5), // Top 5 price breaks
                    'stock' => $maxStock,
                    'moq' => ($minMoq === PHP_INT_MAX) ? 1 : $minMoq,
                    'leadtime_days' => 0, // Nexar doesn't provide lead time
                    'specs' => $this->parseSpecs(self::arrayOf($part, 'specs')),
                ];

                $confidenceCalculator = $this->confidenceCalculator;
                if ($confidenceCalculator !== null) {
                    $confidence = $confidenceCalculator->calculateConfidence(
                        $partNumber,
                        $manufacturer,
                        $description,
                        $apiResult
                    );
                    $apiResult['confidence_score'] = $confidence['score'];
                    $apiResult['confidence_level'] = $confidence['level'];
                    $apiResult['confidence_reasons'] = $confidence['reasons'];
                    $apiResult['confidence_warnings'] = $confidence['warnings'];
                }

                return $apiResult;
                
            } catch (\Exception $e) {
                $this->logger->error('Nexar API request failed', [
                    'part_number' => $partNumber,
                    'error' => $e->getMessage()
                ]);
                $item->expiresAfter(self::MISS_CACHE_TTL); // Failure: short TTL so we retry soon
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

                /** @var array<string, mixed> $data */
                $data = $response->toArray();
                $tokenValue = $data['access_token'] ?? null;
                $this->accessToken = is_string($tokenValue) ? $tokenValue : null;

                return $this->accessToken;
                
            } catch (\Exception $e) {
                $this->logger->error('Nexar OAuth failed', [
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        });
    }

    /**
     * @param array<array-key, mixed> $specs
     * @return array<string, mixed>
     */
    private function parseSpecs(array $specs): array
    {
        $result = [];

        foreach ($specs as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $attribute = is_array($spec['attribute'] ?? null) ? $spec['attribute'] : [];
            $name = self::stringOf($attribute, 'name');
            $value = self::stringOf($spec, 'displayValue');

            if ($name !== null && $name !== '' && $value !== null && $value !== '') {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $array
     * @return array<array-key, mixed>
     */
    private static function arrayOf(array $array, string $key): array
    {
        $value = $array[$key] ?? null;
        return is_array($value) ? $value : [];
    }

    /**
     * @param array<array-key, mixed> $array
     */
    private static function stringOf(array $array, string $key): ?string
    {
        $value = $array[$key] ?? null;
        return is_scalar($value) ? (string) $value : null;
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
