<?php

namespace App\Tests\Unit\Service;

use App\Service\CurrencyConverter;
use App\Service\CurrencyConversionService;
use App\Service\CurrencyPreferenceService;
use PHPUnit\Framework\TestCase;

class CurrencyConverterTest extends TestCase
{
    private CurrencyConverter $converter;
    private CurrencyConversionService $conversionService;
    private CurrencyPreferenceService $preferenceService;

    protected function setUp(): void
    {
        $this->conversionService = $this->createMock(CurrencyConversionService::class);
        $this->preferenceService = $this->createMock(CurrencyPreferenceService::class);

        $this->preferenceService->method('getDisplayCurrency')
            ->willReturn('USD');

        $this->converter = new CurrencyConverter(
            $this->conversionService,
            $this->preferenceService
        );
    }

    // ── convert() ───────────────────────────────────────────────

    public function testConvertNullReturnsZero(): void
    {
        $this->assertSame(0.0, $this->converter->convert(null, 'USD', 'EUR'));
    }

    public function testConvertSameCurrencyReturnsAmount(): void
    {
        $this->assertSame(100.0, $this->converter->convert(100.0, 'USD', 'USD'));
    }

    public function testConvertDelegatesToConversionService(): void
    {
        $this->conversionService->method('convert')
            ->with(100.0, 'EUR', 'USD')
            ->willReturn([
                'amount' => 104.0,
                'rate' => 1.04,
                'source' => 'database_direct',
                'stale' => false,
                'warning' => null,
            ]);

        $result = $this->converter->convert(100.0, 'EUR', 'USD');
        $this->assertSame(104.0, $result);
    }

    public function testConvertUsesDisplayCurrencyAsFallbackTarget(): void
    {
        $this->conversionService->expects($this->once())
            ->method('convert')
            ->with(50.0, 'EUR', 'USD')
            ->willReturn(['amount' => 52.0]);

        $result = $this->converter->convert(50.0, 'EUR');
        $this->assertSame(52.0, $result);
    }

    public function testConvertCaseInsensitive(): void
    {
        $this->conversionService->method('convert')
            ->with(100.0, 'EUR', 'USD')
            ->willReturn(['amount' => 104.0]);

        $result = $this->converter->convert(100.0, 'eur', 'usd');
        $this->assertSame(104.0, $result);
    }

    // ── format() ────────────────────────────────────────────────

    public function testFormatNullReturnsDash(): void
    {
        $this->assertSame('—', $this->converter->format(null, 'USD'));
    }

    public function testFormatUsdSymbolBefore(): void
    {
        $result = $this->converter->format(1250.50, 'USD', 'USD');
        $this->assertSame('$1,250.50', $result);
    }

    public function testFormatEurSymbolBefore(): void
    {
        $result = $this->converter->format(999.0, 'EUR', 'EUR');
        $this->assertSame('€999.00', $result);
    }

    public function testFormatGbpSymbolBefore(): void
    {
        $result = $this->converter->format(500.0, 'GBP', 'GBP');
        $this->assertSame('£500.00', $result);
    }

    public function testFormatMadSymbolAfter(): void
    {
        $result = $this->converter->format(1000.0, 'MAD', 'MAD');
        $this->assertSame('1,000.00 MAD', $result);
    }

    public function testFormatTndSymbolAfter(): void
    {
        $result = $this->converter->format(500.0, 'TND', 'TND');
        $this->assertSame('500.00 TND', $result);
    }

    public function testFormatChfSymbolAfter(): void
    {
        $result = $this->converter->format(750.0, 'CHF', 'CHF');
        $this->assertSame('750.00 CHF', $result);
    }

    public function testFormatJpyUsesYenSymbol(): void
    {
        $result = $this->converter->format(10000.0, 'JPY', 'JPY', 0);
        $this->assertSame('¥10,000', $result);
    }

    public function testFormatCustomDecimals(): void
    {
        $result = $this->converter->format(1.23456, 'USD', 'USD', 4);
        $this->assertSame('$1.2346', $result);
    }

    public function testFormatZeroDecimals(): void
    {
        $result = $this->converter->format(1250.99, 'USD', 'USD', 0);
        $this->assertSame('$1,251', $result);
    }

    public function testFormatUnknownCurrencyUsesCodeAsSymbol(): void
    {
        $result = $this->converter->format(100.0, 'XYZ', 'XYZ');
        $this->assertSame('XYZ100.00', $result);
    }

    public function testFormatSekSymbolAfter(): void
    {
        $result = $this->converter->format(100.0, 'SEK', 'SEK');
        $this->assertSame('100.00 kr', $result);
    }

    public function testFormatHkdPrefixed(): void
    {
        $result = $this->converter->format(100.0, 'HKD', 'HKD');
        $this->assertSame('HK$100.00', $result);
    }

    public function testFormatInrUsesRupeeSymbol(): void
    {
        $result = $this->converter->format(50000.0, 'INR', 'INR', 0);
        $this->assertSame('₹50,000', $result);
    }

    // ── getSymbol() ─────────────────────────────────────────────

    public function testGetSymbolKnownCurrency(): void
    {
        $this->assertSame('$', $this->converter->getSymbol('USD'));
        $this->assertSame('€', $this->converter->getSymbol('EUR'));
        $this->assertSame('£', $this->converter->getSymbol('GBP'));
        $this->assertSame('¥', $this->converter->getSymbol('JPY'));
        $this->assertSame('₹', $this->converter->getSymbol('INR'));
        $this->assertSame('MAD', $this->converter->getSymbol('MAD'));
    }

    public function testGetSymbolUnknownReturnsCode(): void
    {
        $this->assertSame('XYZ', $this->converter->getSymbol('XYZ'));
    }

    public function testGetSymbolNullUsesDisplayCurrency(): void
    {
        $symbol = $this->converter->getSymbol(null);
        $this->assertSame('$', $symbol);
    }

    public function testGetSymbolCaseInsensitive(): void
    {
        $this->assertSame('€', $this->converter->getSymbol('eur'));
    }

    // ── getDisplayCurrency() ────────────────────────────────────

    public function testGetDisplayCurrencyDelegatesToPreference(): void
    {
        $this->assertSame('USD', $this->converter->getDisplayCurrency());
    }

    public function testGetDisplayCurrencyWithFallback(): void
    {
        $pref = $this->createMock(CurrencyPreferenceService::class);
        $pref->method('getDisplayCurrency')
            ->with('EUR')
            ->willReturn('EUR');

        $converter = new CurrencyConverter($this->conversionService, $pref);
        $this->assertSame('EUR', $converter->getDisplayCurrency('EUR'));
    }
}
