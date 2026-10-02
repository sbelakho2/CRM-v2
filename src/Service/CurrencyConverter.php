<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

class CurrencyConverter
{
    private const SYMBOLS = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'JPY' => '¥',
        'CNY' => '¥',
        'CHF' => 'CHF',
        'CAD' => 'CA$',
        'AUD' => 'A$',
        'HKD' => 'HK$',
        'SGD' => 'S$',
        'TWD' => 'NT$',
        'KRW' => '₩',
        'INR' => '₹',
        'MAD' => 'MAD',
        'TND' => 'TND',
        'BRL' => 'R$',
        'MXN' => 'MX$',
        'ZAR' => 'R',
        'SEK' => 'kr',
        'NOK' => 'kr',
        'DKK' => 'kr',
        'PLN' => 'zł',
        'CZK' => 'Kč',
        'THB' => '฿',
        'MYR' => 'RM',
        'PHP' => '₱',
        'IDR' => 'Rp',
        'VND' => '₫',
        'AED' => 'AED',
        'SAR' => 'SAR',
        'QAR' => 'QAR',
        'KWD' => 'KWD',
        'BHD' => 'BHD',
        'OMR' => 'OMR',
        'JOD' => 'JOD',
        'EGP' => 'E£',
        'NGN' => '₦',
        'KES' => 'KSh',
        'TRY' => '₺',
        'RUB' => '₽',
        'NZD' => 'NZ$',
        'ILS' => '₪',
    ];

    /**
     * Currencies where the symbol is placed after the amount (with a space)
     */
    private const SYMBOL_AFTER_AMOUNT = [
        'MAD', 'TND', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK',
        'AED', 'SAR', 'QAR', 'KWD', 'BHD', 'OMR', 'JOD',
    ];

    public function __construct(
        private CurrencyConversionService $conversionService,
        private CurrencyPreferenceService $currencyPreferenceService,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function convert(?float $amount, ?string $fromCurrency, ?string $toCurrency = null): float
    {
        if ($amount === null) {
            return 0.0;
        }

        $displayCurrency = $this->currencyPreferenceService->getDisplayCurrency();
        $fallback = $fromCurrency ?? $toCurrency ?? $displayCurrency; // display currency is non-null
        $from = strtoupper((string) $fallback);
        $to = strtoupper($toCurrency ?? $this->currencyPreferenceService->getDisplayCurrency($from));

        if ($from === $to) {
            return $amount;
        }

        try {
            $result = $this->conversionService->convert((float) $amount, $from, $to);
            return (float) $result['amount'];
        } catch (\RuntimeException $e) {
            // Unknown currency pair — never silently convert 1:1. Log loudly
            // and return the original amount so display code degrades
            // gracefully while the issue is surfaced in the logs.
            $this->logger?->error('Currency conversion failed — returning unconverted amount', [
                'from' => $from,
                'to' => $to,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            return $amount;
        }
    }

    /**
     * Presentation-grade conversion: same as convert() — degrades to the
     * unconverted amount (loudly logged) when the FX pair cannot be
     * resolved. ONLY for display; business calculations (ranking, quoting,
     * landed cost) must use convertOrFail().
     */
    public function convertForDisplay(?float $amount, ?string $fromCurrency, ?string $toCurrency = null): float
    {
        return $this->convert($amount, $fromCurrency, $toCurrency);
    }

    /**
     * Business-grade conversion: FAILS CLOSED. Throws RuntimeException when
     * the FX pair cannot be resolved from any data source. Financial
     * comparisons (route ranking, landed cost, quote totals) must use this —
     * silently comparing €900 to $950 1:1 is exactly the bug this prevents.
     *
     * @throws \RuntimeException when no rate exists for the currency pair
     */
    public function convertOrFail(?float $amount, ?string $fromCurrency, ?string $toCurrency = null): float
    {
        if ($amount === null) {
            return 0.0;
        }

        $displayCurrency = $this->currencyPreferenceService->getDisplayCurrency();
        $fallback = $fromCurrency ?? $toCurrency ?? $displayCurrency; // display currency is non-null
        $from = strtoupper((string) $fallback);
        $to = strtoupper($toCurrency ?? $this->currencyPreferenceService->getDisplayCurrency($from));

        if ($from === $to) {
            return $amount;
        }

        $result = $this->conversionService->convert((float) $amount, $from, $to);

        return (float) $result['amount'];
    }

    public function format(?float $amount, ?string $fromCurrency, ?string $toCurrency = null, int $decimals = 2): string
    {
        if ($amount === null) {
            return '—';
        }

        $displayCurrency = $this->currencyPreferenceService->getDisplayCurrency();
        $fallback = $fromCurrency ?? $toCurrency ?? $displayCurrency; // display currency is non-null
        $from = strtoupper((string) $fallback);
        $to = strtoupper($toCurrency ?? $this->currencyPreferenceService->getDisplayCurrency($from));
        $value = $this->convert($amount, $from, $to);

        $formatted = number_format($value, $decimals, '.', ',');
        $symbol = self::SYMBOLS[$to] ?? $to;

        if (in_array($to, self::SYMBOL_AFTER_AMOUNT, true)) {
            return $formatted . ' ' . $symbol;
        }

        return $symbol . $formatted;
    }

    public function getSymbol(?string $currency): string
    {
        $code = strtoupper($currency ?? $this->currencyPreferenceService->getDisplayCurrency());
        return self::SYMBOLS[$code] ?? $code;
    }

    public function getDisplayCurrency(?string $fallback = null): string
    {
        return $this->currencyPreferenceService->getDisplayCurrency($fallback);
    }
}
