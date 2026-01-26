<?php

namespace App\Twig;

use App\Service\CurrencyConverter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class CurrencyExtension extends AbstractExtension
{
    public function __construct(private CurrencyConverter $currencyConverter)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('format_currency', [$this, 'formatCurrency']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('display_currency', [$this, 'getDisplayCurrency']),
            new TwigFunction('currency_symbol', [$this, 'getCurrencySymbol']),
        ];
    }

    public function formatCurrency(?float $amount, ?string $fromCurrency = null, ?string $toCurrency = null, int $decimals = 2): string
    {
        return $this->currencyConverter->format($amount, $fromCurrency, $toCurrency, $decimals);
    }

    public function getDisplayCurrency(?string $fallback = null): string
    {
        return $this->currencyConverter->getDisplayCurrency($fallback);
    }

    public function getCurrencySymbol(?string $currency): string
    {
        return $this->currencyConverter->getSymbol($currency);
    }
}
