<?php

namespace App\Service;

use App\Entity\FxRate;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Live FX Rate Fetcher
 * 
 * Fetches exchange rates from official central bank APIs:
 * - ECB (European Central Bank) - Primary source for EUR pairs
 * - Federal Reserve (US Fed) - US Dollar rates
 * - Bank of England (BOE) - GBP rates
 * - Frankfurter.app - Free API backed by ECB data
 * - ExchangeRate-API - Fallback with free tier
 * 
 * Priority order:
 * 1. ECB Statistical Data Warehouse API (official, free)
 * 2. Frankfurter.app (ECB-backed, free, no key needed)
 * 3. ExchangeRate-API (free tier fallback)
 * 
 * All rates are stored in the database for offline use and audit trail.
 */
class LiveFxRateFetcher
{
    // API Endpoints
    private const ECB_API_URL = 'https://data-api.ecb.europa.eu/service/data/EXR/D.{currency}.EUR.SP00.A?format=jsondata&lastNObservations=1';
    private const FRANKFURTER_API_URL = 'https://api.frankfurter.app/latest?from={base}&to={targets}';
    private const EXCHANGERATE_API_URL = 'https://open.er-api.com/v6/latest/{base}';
    
    // BOE Statistical Interactive Database (SIAD)
    private const BOE_API_URL = 'https://www.bankofengland.co.uk/boeapps/iadb/fromshowcolumns.asp?csv.x=yes&Datefrom=01/Jan/2024&Dateto=now&SeriesCodes=XUDLGBD,XUDLUSS,XUDLERS,XUDLJYS,XUDLCAS,XUDLAUS,XUDLSFS,XUDLHKS,XUDLSGS,XUDLNDS&UsingCodes=Y&CSVF=TN&VPD=Y';
    
    // Cache settings
    private const CACHE_TTL_SECONDS = 3600; // 1 hour cache for API calls
    private const RATE_STALENESS_HOURS = 24;
    
    // Supported currencies by source
    private const ECB_CURRENCIES = ['USD', 'GBP', 'JPY', 'CHF', 'CAD', 'AUD', 'CNY', 'HKD', 'SGD', 'KRW', 'INR', 'TWD', 'MAD'];
    private const FRANKFURTER_CURRENCIES = ['USD', 'GBP', 'JPY', 'CHF', 'CAD', 'AUD', 'CNY', 'HKD', 'SGD', 'KRW', 'INR'];
    
    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager,
        private CacheInterface $cache,
        private LoggerInterface $logger
    ) {}

    /**
     * Fetch and store latest FX rates from all sources
     * 
     * @return array Summary of fetched rates
     */
    public function fetchAllRates(): array
    {
        $results = [
            'success' => [],
            'failed' => [],
            'source' => null,
            'timestamp' => new \DateTime(),
        ];
        
        // Try ECB/Frankfurter first (official data, EUR-based)
        $frankfurterRates = $this->fetchFromFrankfurter();
        if (!empty($frankfurterRates)) {
            $results['source'] = 'frankfurter_ecb';
            foreach ($frankfurterRates as $currency => $rate) {
                try {
                    $this->storeRate('EUR', $currency, $rate, 'ecb_frankfurter');
                    // Also store inverse for USD base
                    if ($currency === 'USD') {
                        $this->storeRate('USD', 'EUR', 1 / $rate, 'ecb_frankfurter_inverse');
                    }
                    $results['success'][] = "EUR/{$currency}";
                } catch (\Exception $e) {
                    $results['failed'][] = "EUR/{$currency}: " . $e->getMessage();
                }
            }
        }
        
        // Fetch USD-based rates from ExchangeRate-API for additional coverage
        $usdRates = $this->fetchFromExchangeRateApi('USD');
        if (!empty($usdRates)) {
            $results['source'] = $results['source'] ? $results['source'] . '+exchangerate' : 'exchangerate';
            foreach ($usdRates as $currency => $rate) {
                // Only add if we don't have it from ECB
                if (!in_array("EUR/{$currency}", $results['success'], true) && $currency !== 'USD') {
                    try {
                        $this->storeRate('USD', $currency, $rate, 'exchangerate_api');
                        $results['success'][] = "USD/{$currency}";
                    } catch (\Exception $e) {
                        $results['failed'][] = "USD/{$currency}: " . $e->getMessage();
                    }
                }
            }
            
            // Store MAD rate specifically (important for this CRM)
            if (isset($usdRates['MAD'])) {
                $this->storeRate('USD', 'MAD', $usdRates['MAD'], 'exchangerate_api');
                // Store inverse
                $this->storeRate('MAD', 'USD', 1 / $usdRates['MAD'], 'exchangerate_api_inverse');
            }
        }
        
        // Try BOE for GBP-specific rates
        $boeRates = $this->fetchFromBOE();
        if (!empty($boeRates)) {
            foreach ($boeRates as $currency => $rate) {
                try {
                    $this->storeRate('GBP', $currency, $rate, 'boe');
                    $results['success'][] = "GBP/{$currency} (BOE)";
                } catch (\Exception $e) {
                    $results['failed'][] = "GBP/{$currency}: " . $e->getMessage();
                }
            }
        }
        
        $this->logger->info('FX rate fetch completed', [
            'source' => $results['source'],
            'success_count' => count($results['success']),
            'failed_count' => count($results['failed']),
        ]);
        
        return $results;
    }

    /**
     * Fetch rates from Frankfurter.app (ECB-backed)
     * 
     * @return array<string, float> Currency code => rate (EUR base)
     */
    public function fetchFromFrankfurter(): array
    {
        $cacheKey = 'fx_frankfurter_latest';
        
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);
                
                $targets = implode(',', self::FRANKFURTER_CURRENCIES);
                $url = str_replace(['{base}', '{targets}'], ['EUR', $targets], self::FRANKFURTER_API_URL);
                
                $response = $this->httpClient->request('GET', $url, [
                    'timeout' => 10,
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'QuoteBuddy-CRM/1.0',
                    ],
                ]);
                
                if ($response->getStatusCode() !== 200) {
                    throw new \RuntimeException('Frankfurter API returned ' . $response->getStatusCode());
                }
                
                $data = $response->toArray();
                
                if (!isset($data['rates'])) {
                    throw new \RuntimeException('Invalid Frankfurter API response');
                }
                
                $this->logger->info('Fetched rates from Frankfurter (ECB)', [
                    'base' => $data['base'] ?? 'EUR',
                    'date' => $data['date'] ?? 'unknown',
                    'currencies' => count($data['rates']),
                ]);
                
                return $data['rates'];
            });
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch from Frankfurter', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Fetch rates from ExchangeRate-API (free tier)
     * 
     * @param string $base Base currency
     * @return array<string, float> Currency code => rate
     */
    public function fetchFromExchangeRateApi(string $base = 'USD'): array
    {
        $cacheKey = 'fx_exchangerate_' . strtolower($base);
        
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($base) {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);
                
                $url = str_replace('{base}', $base, self::EXCHANGERATE_API_URL);
                
                $response = $this->httpClient->request('GET', $url, [
                    'timeout' => 10,
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'QuoteBuddy-CRM/1.0',
                    ],
                ]);
                
                if ($response->getStatusCode() !== 200) {
                    throw new \RuntimeException('ExchangeRate API returned ' . $response->getStatusCode());
                }
                
                $data = $response->toArray();
                
                if (!isset($data['rates'])) {
                    throw new \RuntimeException('Invalid ExchangeRate API response');
                }
                
                $this->logger->info('Fetched rates from ExchangeRate-API', [
                    'base' => $data['base_code'] ?? $base,
                    'currencies' => count($data['rates']),
                ]);
                
                return $data['rates'];
            });
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch from ExchangeRate-API', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Fetch rates from Bank of England Statistical Interactive Database
     * 
     * @return array<string, float> Currency code => rate (GBP base)
     */
    public function fetchFromBOE(): array
    {
        $cacheKey = 'fx_boe_latest';
        
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);
                
                // BOE returns CSV data
                $response = $this->httpClient->request('GET', self::BOE_API_URL, [
                    'timeout' => 15,
                    'headers' => [
                        'User-Agent' => 'QuoteBuddy-CRM/1.0',
                    ],
                ]);
                
                if ($response->getStatusCode() !== 200) {
                    throw new \RuntimeException('BOE API returned ' . $response->getStatusCode());
                }
                
                $csv = $response->getContent();
                $lines = explode("\n", $csv);
                
                // BOE CSV format: Date, XUDLGBD, XUDLUSS, etc.
                // Series codes map to currencies
                $seriesMap = [
                    'XUDLGBD' => 'USD', // US Dollar
                    'XUDLERS' => 'EUR', // Euro
                    'XUDLJYS' => 'JPY', // Japanese Yen
                    'XUDLCAS' => 'CAD', // Canadian Dollar
                    'XUDLAUS' => 'AUD', // Australian Dollar
                    'XUDLSFS' => 'CHF', // Swiss Franc
                    'XUDLHKS' => 'HKD', // Hong Kong Dollar
                    'XUDLSGS' => 'SGD', // Singapore Dollar
                ];
                
                $rates = [];
                
                // Parse header to get column positions
                if (count($lines) >= 2) {
                    $header = str_getcsv($lines[0]);
                    $lastRow = str_getcsv(end(array_filter($lines)));
                    
                    foreach ($header as $idx => $colName) {
                        $colName = trim($colName);
                        if (isset($seriesMap[$colName]) && isset($lastRow[$idx])) {
                            $rate = (float) $lastRow[$idx];
                            if ($rate > 0) {
                                $rates[$seriesMap[$colName]] = $rate;
                            }
                        }
                    }
                }
                
                $this->logger->info('Fetched rates from Bank of England', [
                    'currencies' => count($rates),
                ]);
                
                return $rates;
            });
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch from BOE (non-critical)', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Fetch ECB rates directly from ECB Statistical Data Warehouse
     * 
     * @param string $currency Target currency
     * @return float|null Rate (EUR base) or null if unavailable
     */
    public function fetchFromECB(string $currency): ?float
    {
        if ($currency === 'EUR') {
            return 1.0;
        }
        
        $cacheKey = 'fx_ecb_' . strtolower($currency);
        
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($currency) {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);
                
                $url = str_replace('{currency}', $currency, self::ECB_API_URL);
                
                $response = $this->httpClient->request('GET', $url, [
                    'timeout' => 10,
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'QuoteBuddy-CRM/1.0',
                    ],
                ]);
                
                if ($response->getStatusCode() !== 200) {
                    return null;
                }
                
                $data = $response->toArray();
                
                // ECB JSON structure: dataSets[0].series.0:0:0:0:0.observations
                $observations = $data['dataSets'][0]['series']['0:0:0:0:0']['observations'] ?? null;
                
                if (!$observations) {
                    return null;
                }
                
                // Get latest observation
                $latestKey = max(array_keys($observations));
                $rate = (float) ($observations[$latestKey][0] ?? 0);
                
                $this->logger->info('Fetched rate from ECB SDW', [
                    'currency' => $currency,
                    'rate' => $rate,
                ]);
                
                return $rate > 0 ? $rate : null;
            });
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch from ECB SDW', [
                'currency' => $currency,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get a single live rate with automatic source selection
     * 
     * @param string $from Source currency
     * @param string $to Target currency
     * @return array{rate: float, source: string, timestamp: \DateTime}|null
     */
    public function getLiveRate(string $from, string $to): ?array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        
        if ($from === $to) {
            return ['rate' => 1.0, 'source' => 'identity', 'timestamp' => new \DateTime()];
        }
        
        // Try Frankfurter first (EUR-based)
        $frankfurterRates = $this->fetchFromFrankfurter();
        
        if (!empty($frankfurterRates)) {
            // Direct EUR to target
            if ($from === 'EUR' && isset($frankfurterRates[$to])) {
                return [
                    'rate' => $frankfurterRates[$to],
                    'source' => 'ecb_frankfurter',
                    'timestamp' => new \DateTime(),
                ];
            }
            
            // Target to EUR (inverse)
            if ($to === 'EUR' && isset($frankfurterRates[$from])) {
                return [
                    'rate' => 1 / $frankfurterRates[$from],
                    'source' => 'ecb_frankfurter_inverse',
                    'timestamp' => new \DateTime(),
                ];
            }
            
            // Cross rate via EUR
            if (isset($frankfurterRates[$from]) && isset($frankfurterRates[$to])) {
                return [
                    'rate' => $frankfurterRates[$to] / $frankfurterRates[$from],
                    'source' => 'ecb_frankfurter_cross',
                    'timestamp' => new \DateTime(),
                ];
            }
        }
        
        // Try ExchangeRate-API for USD-based pairs
        $usdRates = $this->fetchFromExchangeRateApi('USD');
        
        if (!empty($usdRates)) {
            // Direct USD to target
            if ($from === 'USD' && isset($usdRates[$to])) {
                return [
                    'rate' => $usdRates[$to],
                    'source' => 'exchangerate_api',
                    'timestamp' => new \DateTime(),
                ];
            }
            
            // Target to USD (inverse)
            if ($to === 'USD' && isset($usdRates[$from])) {
                return [
                    'rate' => 1 / $usdRates[$from],
                    'source' => 'exchangerate_api_inverse',
                    'timestamp' => new \DateTime(),
                ];
            }
            
            // Cross rate via USD
            if (isset($usdRates[$from]) && isset($usdRates[$to])) {
                return [
                    'rate' => $usdRates[$to] / $usdRates[$from],
                    'source' => 'exchangerate_api_cross',
                    'timestamp' => new \DateTime(),
                ];
            }
        }
        
        $this->logger->warning('Could not fetch live rate', ['from' => $from, 'to' => $to]);
        return null;
    }

    /**
     * Store a rate in the database
     */
    private function storeRate(string $from, string $to, float $rate, string $source): void
    {
        // Deactivate old rates for this pair
        $this->entityManager->createQueryBuilder()
            ->update(FxRate::class, 'r')
            ->set('r.isActive', ':inactive')
            ->where('r.fromCurrency = :from')
            ->andWhere('r.toCurrency = :to')
            ->setParameter('inactive', false)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->execute();
        
        // Create new rate
        $fxRate = new FxRate();
        $fxRate->setFromCurrency($from);
        $fxRate->setToCurrency($to);
        $fxRate->setRate((string) $rate);
        $fxRate->setAsof(new \DateTime());
        $fxRate->setIsActive(true);
        $fxRate->setVersionId($source . '_' . time());
        
        $this->entityManager->persist($fxRate);
        $this->entityManager->flush();
    }

    /**
     * Check if stored rates are fresh
     * 
     * @return array<string, array> Currency pairs with staleness info
     */
    public function checkRateFreshness(): array
    {
        $result = [];
        $threshold = new \DateTime('-' . self::RATE_STALENESS_HOURS . ' hours');
        
        $rates = $this->entityManager->getRepository(FxRate::class)->findBy(['isActive' => true]);
        
        foreach ($rates as $rate) {
            $pair = $rate->getFromCurrency() . '/' . $rate->getToCurrency();
            $isStale = $rate->getAsof() < $threshold;
            
            $result[$pair] = [
                'rate' => (float) $rate->getRate(),
                'asof' => $rate->getAsof()->format('Y-m-d H:i:s'),
                'stale' => $isStale,
                'age_hours' => round((time() - $rate->getAsof()->getTimestamp()) / 3600, 1),
            ];
        }
        
        return $result;
    }

    /**
     * Get list of supported currencies from all sources
     */
    public function getSupportedCurrencies(): array
    {
        return [
            'primary' => ['USD', 'EUR', 'GBP', 'JPY', 'CHF', 'CAD', 'AUD'],
            'secondary' => ['CNY', 'HKD', 'SGD', 'KRW', 'INR', 'TWD'],
            'regional' => ['MAD'], // Moroccan Dirham - important for this CRM
            'sources' => [
                'ecb' => self::ECB_CURRENCIES,
                'frankfurter' => self::FRANKFURTER_CURRENCIES,
                'boe' => ['USD', 'EUR', 'JPY', 'CAD', 'AUD', 'CHF', 'HKD', 'SGD'],
            ],
        ];
    }
}
