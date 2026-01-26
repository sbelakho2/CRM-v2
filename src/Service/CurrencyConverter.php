<?php

namespace App\Service;

class CurrencyConverter
{
    private const SYMBOLS = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'MAD' => 'MAD',
        'TND' => 'TND',
    ];

    public function __construct(
        private CurrencyConversionService $conversionService,
        private CurrencyPreferenceService $currencyPreferenceService
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

        if (in_array($to, ['MAD', 'TND'], true)) {
            return $symbol . ' ' . $formatted;
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
