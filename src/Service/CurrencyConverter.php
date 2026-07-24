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
        $fallback = $fromCurrency ?? $toCurrency ?? $displayCurrency;
        $from = strtoupper($fallback);
        $to = strtoupper($toCurrency ?? $this->currencyPreferenceService->getDisplayCurrency($from));

        if ($from === $to) {
            return $amount;
        }

        $result = $this->conversionService->convert((float) $amount, $from, $to);

        return (float) ($result['amount'] ?? $amount);
    }

    public function format(?float $amount, ?string $fromCurrency, ?string $toCurrency = null, int $decimals = 2): string
    {
        if ($amount === null) {
            return '—';
        }

        $displayCurrency = $this->currencyPreferenceService->getDisplayCurrency();
        $fallback = $fromCurrency ?? $toCurrency ?? $displayCurrency;
        $from = strtoupper($fallback);
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
