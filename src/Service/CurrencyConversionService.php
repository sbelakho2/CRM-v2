<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FxRate;
use App\Repository\FxRateRepository;
use Psr\Log\LoggerInterface;

/**
 * Currency Conversion Service
 * 
 * Provides FX rate management and currency conversion for pricing.
 * 
 * Features:
 * - Real-time FX rate lookup from database
 * - Live rate fetching from ECB, BOE, Fed when DB rates are stale
 * - Fallback to hardcoded rates if APIs unavailable
 * - Multi-hop conversion (e.g., JPY → USD → MAD)
 * - Rate staleness detection (warn if rates > 24h old)
 * - Automatic live fetch when rates are stale
 */
class CurrencyConversionService
{
    // Default rates when database AND APIs are unavailable
    // These are last-resort fallbacks only
    // Last verified: 2026-02-07
    private const FALLBACK_RATES_TO_USD = [
        'USD' => 1.0,
        'EUR' => 1.04,       // 1 EUR = 1.04 USD
        'GBP' => 1.25,       // 1 GBP = 1.25 USD
        'MAD' => 0.098,      // 1 MAD = 0.098 USD
        'TND' => 0.32,       // 1 TND = 0.32 USD
        'CNY' => 0.137,      // 1 CNY = 0.137 USD
        'JPY' => 0.0065,     // 1 JPY = 0.0065 USD
        'CAD' => 0.72,       // 1 CAD = 0.72 USD
        'AUD' => 0.63,       // 1 AUD = 0.63 USD
        'CHF' => 1.11,       // 1 CHF = 1.11 USD
        'HKD' => 0.128,      // 1 HKD = 0.128 USD
        'SGD' => 0.74,       // 1 SGD = 0.74 USD
        'TWD' => 0.031,      // 1 TWD = 0.031 USD
        'KRW' => 0.00071,    // 1 KRW = 0.00071 USD
        'INR' => 0.0116,     // 1 INR = 0.0116 USD
        'BRL' => 0.17,       // 1 BRL = 0.17 USD
        'MXN' => 0.049,      // 1 MXN = 0.049 USD
        'ZAR' => 0.054,      // 1 ZAR = 0.054 USD
        'SEK' => 0.093,      // 1 SEK = 0.093 USD
        'NOK' => 0.091,      // 1 NOK = 0.091 USD
        'DKK' => 0.14,       // 1 DKK = 0.14 USD
        'PLN' => 0.245,      // 1 PLN = 0.245 USD
        'CZK' => 0.042,      // 1 CZK = 0.042 USD
        'THB' => 0.029,      // 1 THB = 0.029 USD
        'MYR' => 0.224,      // 1 MYR = 0.224 USD
        'PHP' => 0.017,      // 1 PHP = 0.017 USD
        'IDR' => 0.000062,   // 1 IDR = 0.000062 USD
        'VND' => 0.000039,   // 1 VND = 0.000039 USD
        'AED' => 0.272,      // 1 AED = 0.272 USD
        'SAR' => 0.267,      // 1 SAR = 0.267 USD
        'TRY' => 0.028,      // 1 TRY = 0.028 USD
        'NZD' => 0.57,       // 1 NZD = 0.57 USD
        'EGP' => 0.020,      // 1 EGP = 0.020 USD
    ];
    
    // Stale rate threshold (24 hours)
    private const STALE_THRESHOLD_SECONDS = 86400;
    
    // Whether to auto-fetch live rates when DB rates are stale
    private bool $autoFetchLiveRates = true;
    
    public function __construct(
        private FxRateRepository $fxRateRepository,
        private LoggerInterface $logger,
        private ?LiveFxRateFetcher $liveFxRateFetcher = null
    ) {}

    /**
     * Convert an amount from one currency to another
     * 
     * @param float $amount The amount to convert
     * @param string $fromCurrency Source currency code (e.g., 'EUR')
     * @param string $toCurrency Target currency code (e.g., 'USD')
     * @return array{amount: float, rate: float, source: string, stale: bool, warning: ?string}
     * @throws \RuntimeException when the currency pair is unknown (no rate can
     *         be resolved from DB, live APIs, or the hardcoded fallback table)
     */
    public function convert(float $amount, string $fromCurrency, string $toCurrency): array
    {
        $fromCurrency = strtoupper(trim($fromCurrency));
        $toCurrency = strtoupper(trim($toCurrency));
        
        // Same currency - no conversion needed
        if ($fromCurrency === $toCurrency) {
            return [
                'amount' => $amount,
                'rate' => 1.0,
                'source' => 'identity',
                'stale' => false,
                'warning' => null,
            ];
        }
        
        // Try to get rate from database
        $rateInfo = $this->getRate($fromCurrency, $toCurrency);
        
        // An 'unknown' source means the pair exists in no data source at all.
        // Previously this silently converted 1:1 (converting typos without
        // error); now it surfaces loudly so callers can flag the issue.
        if (($rateInfo['source'] ?? '') === 'unknown') {
            throw new \RuntimeException(sprintf(
                'No exchange rate available for unknown currency pair: %s/%s',
                $fromCurrency,
                $toCurrency
            ));
        }
        
        $convertedAmount = $amount * $rateInfo['rate'];
        
        return [
            'amount' => round($convertedAmount, 4),
            'rate' => $rateInfo['rate'],
            'source' => $rateInfo['source'],
            'stale' => $rateInfo['stale'],
            'warning' => $rateInfo['warning'],
        ];
    }

    /**
     * Convert price data to target currency
     * 
     * @param array $pricing Pricing array with 'price' and 'currency' keys
     * @param string $targetCurrency Target currency code
     * @return array Updated pricing array with converted values
     */
    public function convertPricingToUsd(array $pricing, string $targetCurrency = 'USD'): array
    {
        $converted = [];
        
        foreach ($pricing as $priceBreak) {
            $sourceCurrency = $priceBreak['currency'] ?? 'USD';
            $price = (float) ($priceBreak['price'] ?? 0);
            
            if ($sourceCurrency !== $targetCurrency) {
                $conversion = $this->convert($price, $sourceCurrency, $targetCurrency);
                
                $converted[] = [
                    'quantity' => $priceBreak['quantity'] ?? 0,
                    'price' => $conversion['amount'],
                    'currency' => $targetCurrency,
                    'original_price' => $price,
                    'original_currency' => $sourceCurrency,
                    'fx_rate' => $conversion['rate'],
                    'fx_warning' => $conversion['warning'],
                ];
            } else {
                $converted[] = $priceBreak;
            }
        }
        
        return $converted;
    }

    /**
     * Get exchange rate between two currencies
     * 
     * Priority:
     * 1. Database (fresh rates < 24h old)
     * 2. Live API fetch (always attempted when DB rates are stale or missing)
     * 3. Database (stale rates > 24h old — still usable with warning)
     * 4. Multi-hop via USD (combining two rates)
     * 5. Hardcoded fallback rates (last resort, marked stale)
     * 
     * Live rates fetched via API are automatically stored in the database
     * for future use and audit trail.
     * 
     * @return array{rate: float, source: string, stale: bool, warning: ?string}
     */
    public function getRate(string $fromCurrency, string $toCurrency, array &$visitedCurrencies = []): array
    {
        if (in_array($fromCurrency, $visitedCurrencies, true)) {
            throw new \RuntimeException('Currency conversion cycle detected');
        }
        $visitedCurrencies[] = $fromCurrency;

        // 1. Try direct rate from database (fresh)
        $fxRate = $this->fxRateRepository->findOneBy([
            'fromCurrency' => $fromCurrency,
            'toCurrency' => $toCurrency,
            'isActive' => true,
        ], ['asof' => 'DESC']);
        
        if ($fxRate && !$this->isStale($fxRate->getAsof())) {
            return $this->formatRateResult($fxRate, 'database_direct');
        }
        
        // 1b. Try inverse rate from database (fresh)
        $inverseRate = $this->fxRateRepository->findOneBy([
            'fromCurrency' => $toCurrency,
            'toCurrency' => $fromCurrency,
            'isActive' => true,
        ], ['asof' => 'DESC']);
        
        if ($inverseRate && !$this->isStale($inverseRate->getAsof())) {
            $rate = 1.0 / (float) $inverseRate->getRate();
            return [
                'rate' => round($rate, 6),
                'source' => 'database_inverse',
                'stale' => false,
                'warning' => null,
            ];
        }
        
        // 2. Database rates are stale or missing — try live fetch from central bank APIs
        if ($this->autoFetchLiveRates && $this->liveFxRateFetcher) {
            $liveRate = $this->liveFxRateFetcher->getLiveRate($fromCurrency, $toCurrency);
            
            if ($liveRate) {
                $this->logger->info('Using live FX rate from central bank API', [
                    'from' => $fromCurrency,
                    'to' => $toCurrency,
                    'rate' => $liveRate['rate'],
                    'source' => $liveRate['source'],
                ]);
                
                return [
                    'rate' => round($liveRate['rate'], 6),
                    'source' => 'live_' . $liveRate['source'],
                    'stale' => false,
                    'warning' => null,
                ];
            }
        }
        
        // 3. Use stale database rate if available (better than fallback)
        if ($fxRate) {
            $this->logger->warning('Using stale FX rate from database', [
                'from' => $fromCurrency,
                'to' => $toCurrency,
                'age_hours' => round((time() - $fxRate->getAsof()->getTimestamp()) / 3600, 1),
            ]);
            return $this->formatRateResult($fxRate, 'database_direct_stale');
        }
        
        if ($inverseRate) {
            $rate = 1.0 / (float) $inverseRate->getRate();
            return [
                'rate' => round($rate, 6),
                'source' => 'database_inverse_stale',
                'stale' => true,
                'warning' => 'FX rate is older than 24 hours',
            ];
        }
        
        // 4. Try via USD (multi-hop) with any available rates
        if ($fromCurrency !== 'USD' && $toCurrency !== 'USD') {
            // Each leg resolves with its own visited set: re-entering this
            // call's entry currency through the first leg is legitimate,
            // while the set still guards against genuine cross-pair cycles.
            $legVisited = [];
            $fromToUsd = $this->getRate($fromCurrency, 'USD', $legVisited);
            $usdToTarget = $this->getRate('USD', $toCurrency, $legVisited);
            
            if ($fromToUsd['source'] !== 'unknown' && $usdToTarget['source'] !== 'unknown') {
                $combinedRate = $fromToUsd['rate'] * $usdToTarget['rate'];
                $isStale = $fromToUsd['stale'] || $usdToTarget['stale'];
                return [
                    'rate' => round($combinedRate, 6),
                    'source' => 'via_usd',
                    'stale' => $isStale,
                    'warning' => $isStale 
                        ? 'FX rate may be stale (multi-hop via USD)' 
                        : null,
                ];
            }
        }
        
        // 5. Last resort: hardcoded fallback rates (always marked stale)
        return $this->getFallbackRate($fromCurrency, $toCurrency);
    }

    /**
     * Enable or disable automatic live rate fetching
     */
    public function setAutoFetchLiveRates(bool $enabled): self
    {
        $this->autoFetchLiveRates = $enabled;
        return $this;
    }

    /**
     * Get conversion with full audit trail
     * 
     * Returns detailed info about conversion including source APIs used
     */
    public function convertWithAudit(float $amount, string $from, string $to): array
    {
        $result = $this->convert($amount, $from, $to);
        
        return array_merge($result, [
            'audit' => [
                'original_amount' => $amount,
                'from_currency' => $from,
                'to_currency' => $to,
                'timestamp' => (new \DateTime())->format('c'),
                'live_fetch_enabled' => $this->autoFetchLiveRates,
                'live_fetcher_available' => $this->liveFxRateFetcher !== null,
            ],
        ]);
    }

    /**
     * Check if all required currencies have fresh rates
      * @param array<string|int, mixed> $currencies
     */
    public function validateRateFreshness(array $currencies): array
    {
        $issues = [];
        
        foreach ($currencies as $currency) {
            if ($currency === 'USD') continue;
            
            $rateInfo = $this->getRate($currency, 'USD');
            
            if ($rateInfo['source'] === 'fallback' || $rateInfo['source'] === 'unknown') {
                $issues[] = [
                    'currency' => $currency,
                    'issue' => 'no_database_rate',
                    'message' => "No database FX rate for {$currency}/USD - using fallback",
                ];
            } elseif ($rateInfo['stale']) {
                $issues[] = [
                    'currency' => $currency,
                    'issue' => 'stale_rate',
                    'message' => "FX rate for {$currency}/USD is older than 24 hours",
                ];
            }
        }
        
        return $issues;
    }

    /**
     * Format rate result from FxRate entity
     */
    private function formatRateResult(FxRate $fxRate, string $source): array
    {
        $stale = $this->isStale($fxRate->getAsof());
        
        return [
            'rate' => (float) $fxRate->getRate(),
            'source' => $source,
            'stale' => $stale,
            'warning' => $stale ? 'FX rate is older than 24 hours' : null,
        ];
    }

    /**
     * Get fallback rate from hardcoded values
     *
     * The fallback table is keyed by KNOWN currency codes and is only used as
     * the last resort for those known pairs — never an identity 1:1 guess for
     * unknown codes. Unknown pairs are reported via the 'unknown' source so
     * convert() can surface them as errors instead of silently converting.
     */
    private function getFallbackRate(string $fromCurrency, string $toCurrency): array
    {
        $fromToUsd = self::FALLBACK_RATES_TO_USD[$fromCurrency] ?? null;
        $toToUsd = self::FALLBACK_RATES_TO_USD[$toCurrency] ?? null;
        
        if ($fromToUsd === null || $toToUsd === null) {
            $this->logger->warning('Unknown currency for conversion', [
                'from' => $fromCurrency,
                'to' => $toCurrency,
            ]);
            
            return [
                'rate' => 1.0, // never used for conversion — see convert()
                'source' => 'unknown',
                'stale' => true,
                'warning' => "Unknown currency pair: {$fromCurrency}/{$toCurrency}",
            ];
        }
        
        // Convert via USD
        $rate = $fromToUsd / $toToUsd;
        
        $this->logger->info('Using fallback FX rate', [
            'from' => $fromCurrency,
            'to' => $toCurrency,
            'rate' => $rate,
        ]);
        
        return [
            'rate' => round($rate, 6),
            'source' => 'fallback',
            'stale' => true,
            'warning' => "Using hardcoded fallback rate for {$fromCurrency}/{$toCurrency} — live API unavailable",
        ];
    }

    /**
     * Check if a rate timestamp is stale
     */
    private function isStale(?\DateTimeInterface $asof): bool
    {
        if ($asof === null) {
            return true;
        }
        
        $now = new \DateTime();
        $diff = $now->getTimestamp() - $asof->getTimestamp();
        
        return $diff > self::STALE_THRESHOLD_SECONDS;
    }
}
