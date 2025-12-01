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
                
                return [
                    'mpn' => $part['ManufacturerPartNumber'] ?? $partNumber,
                    'manufacturer' => $part['Manufacturer']['Name'] ?? null,
                    'description' => $part['ProductDescription'] ?? null,
                    'datasheet' => $part['DatasheetUrl'] ?? null,
                    'pricing' => $this->parsePricing($part['StandardPricing'] ?? []),
                    'stock' => (int) ($part['QuantityAvailable'] ?? 0),
                    'leadtime_days' => $this->parseLeadTime($part['ManufacturerLeadWeeks'] ?? 0),
                    'digikey_part_number' => $part['DigiKeyPartNumber'] ?? null,
                    'lifecycle' => $part['ProductStatus'] ?? null,
                    'category' => $part['Category']['Name'] ?? null,
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

    private function parsePricing(array $pricing): array
    {
        $result = [];
        
        foreach ($pricing as $tier) {
            $result[] = [
                'quantity' => (int) ($tier['BreakQuantity'] ?? 0),
                'price' => (float) ($tier['UnitPrice'] ?? 0),
                'currency' => 'USD'
            ];
        }
        
        return $result;
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
