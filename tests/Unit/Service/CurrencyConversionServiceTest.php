<?php

namespace App\Tests\Unit\Service;

use App\Entity\FxRate;
use App\Repository\FxRateRepository;
use App\Service\CurrencyConversionService;
use App\Service\LiveFxRateFetcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for CurrencyConversionService
 *
 * Validates the rate resolution waterfall:
 *   1. Fresh DB rate → 2. Live API → 3. Stale DB → 4. Multi-hop via USD → 5. Fallback
 */
class CurrencyConversionServiceTest extends TestCase
{
    private FxRateRepository $fxRateRepo;
    private LoggerInterface $logger;
    private LiveFxRateFetcher $liveFetcher;

    protected function setUp(): void
    {
        $this->fxRateRepo = $this->createMock(FxRateRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->liveFetcher = $this->createMock(LiveFxRateFetcher::class);
    }

    private function createService(?LiveFxRateFetcher $fetcher = null): CurrencyConversionService
    {
        return new CurrencyConversionService(
            $this->fxRateRepo,
            $this->logger,
            $fetcher ?? $this->liveFetcher
        );
    }

    private function createFreshFxRate(string $from, string $to, float $rate): FxRate
    {
        $fxRate = new FxRate();
        $fxRate->setFromCurrency($from);
        $fxRate->setToCurrency($to);
        $fxRate->setRate((string) $rate);
        $fxRate->setAsof(new \DateTime('-1 hour')); // fresh
        $fxRate->setIsActive(true);
        return $fxRate;
    }

    private function createStaleFxRate(string $from, string $to, float $rate): FxRate
    {
        $fxRate = new FxRate();
        $fxRate->setFromCurrency($from);
        $fxRate->setToCurrency($to);
        $fxRate->setRate((string) $rate);
        $fxRate->setAsof(new \DateTime('-48 hours')); // stale
        $fxRate->setIsActive(true);
        return $fxRate;
    }

    // ── Identity conversion ─────────────────────────────────────

    public function testConvertSameCurrencyReturnsIdentity(): void
    {
        $service = $this->createService();
        $result = $service->convert(100.0, 'USD', 'USD');

        $this->assertSame(100.0, $result['amount']);
        $this->assertSame(1.0, $result['rate']);
        $this->assertSame('identity', $result['source']);
        $this->assertFalse($result['stale']);
        $this->assertNull($result['warning']);
    }

    public function testConvertSameCurrencyCaseInsensitive(): void
    {
        $service = $this->createService();
        $result = $service->convert(50.0, 'usd', 'USD');

        $this->assertSame(50.0, $result['amount']);
        $this->assertSame('identity', $result['source']);
    }

    // ── Priority 1: Fresh database rate ─────────────────────────

    public function testGetRateUsesFreshDatabaseDirect(): void
    {
        $fxRate = $this->createFreshFxRate('EUR', 'USD', 1.04);

        $this->fxRateRepo->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($fxRate) {
                if ($criteria['fromCurrency'] === 'EUR' && $criteria['toCurrency'] === 'USD') {
                    return $fxRate;
                }
                return null;
            });

        $service = $this->createService();
        $result = $service->getRate('EUR', 'USD');

        $this->assertSame(1.04, $result['rate']);
        $this->assertSame('database_direct', $result['source']);
        $this->assertFalse($result['stale']);
        $this->assertNull($result['warning']);
    }

    public function testGetRateUsesFreshDatabaseInverse(): void
    {
        $fxRate = $this->createFreshFxRate('USD', 'EUR', 0.9615);

        $this->fxRateRepo->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($fxRate) {
                // No direct EUR→USD, but USD→EUR exists (inverse)
                if ($criteria['fromCurrency'] === 'USD' && $criteria['toCurrency'] === 'EUR') {
                    return $fxRate;
                }
                return null;
            });

        $service = $this->createService();
        $result = $service->getRate('EUR', 'USD');

        $this->assertEqualsWithDelta(1 / 0.9615, $result['rate'], 0.001);
        $this->assertSame('database_inverse', $result['source']);
        $this->assertFalse($result['stale']);
    }

    // ── Priority 2: Live API when DB is empty/stale ─────────────

    public function testGetRateUsesLiveApiWhenNoDbRate(): void
    {
        $this->fxRateRepo->method('findOneBy')
            ->willReturn(null);

        $this->liveFetcher->expects($this->once())
            ->method('getLiveRate')
            ->with('EUR', 'MAD')
            ->willReturn([
                'rate' => 10.85,
                'source' => 'ecb_frankfurter_cross',
                'timestamp' => new \DateTime(),
            ]);

        $service = $this->createService();
        $result = $service->getRate('EUR', 'MAD');

        $this->assertEqualsWithDelta(10.85, $result['rate'], 0.01);
        $this->assertStringStartsWith('live_', $result['source']);
        $this->assertFalse($result['stale']);
    }

    public function testGetRateUsesLiveApiWhenDbRateIsStale(): void
    {
        $staleRate = $this->createStaleFxRate('EUR', 'USD', 1.02);

        $this->fxRateRepo->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($staleRate) {
                if ($criteria['fromCurrency'] === 'EUR' && $criteria['toCurrency'] === 'USD') {
                    return $staleRate;
                }
                return null;
            });

        $this->liveFetcher->expects($this->once())
            ->method('getLiveRate')
            ->with('EUR', 'USD')
            ->willReturn([
                'rate' => 1.045,
                'source' => 'ecb_frankfurter',
                'timestamp' => new \DateTime(),
            ]);

        $service = $this->createService();
        $result = $service->getRate('EUR', 'USD');

        $this->assertEqualsWithDelta(1.045, $result['rate'], 0.001);
        $this->assertSame('live_ecb_frankfurter', $result['source']);
        $this->assertFalse($result['stale']);
    }

    // ── Priority 3: Stale database rate ─────────────────────────

    public function testGetRateUsesStaleDbWhenLiveApiFails(): void
    {
        $staleRate = $this->createStaleFxRate('EUR', 'USD', 1.02);

        $this->fxRateRepo->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($staleRate) {
                if ($criteria['fromCurrency'] === 'EUR' && $criteria['toCurrency'] === 'USD') {
                    return $staleRate;
                }
                return null;
            });

        $this->liveFetcher->method('getLiveRate')
            ->willReturn(null);

        $service = $this->createService();
        $result = $service->getRate('EUR', 'USD');

        $this->assertSame(1.02, $result['rate']);
        $this->assertSame('database_direct_stale', $result['source']);
        $this->assertTrue($result['stale']);
        $this->assertNotNull($result['warning']);
    }

    // ── Priority 5: Hardcoded fallback ──────────────────────────

    public function testGetRateUsesFallbackAsLastResort(): void
    {
        $this->fxRateRepo->method('findOneBy')
            ->willReturn(null);

        $this->liveFetcher->method('getLiveRate')
            ->willReturn(null);

        $service = $this->createService();
        $result = $service->getRate('EUR', 'USD');

        $this->assertGreaterThan(0.0, $result['rate']);
        $this->assertSame('fallback', $result['source']);
        $this->assertTrue($result['stale'], 'Fallback rates must be marked stale');
        $this->assertStringContainsString('hardcoded fallback', $result['warning']);
    }

    public function testGetRateFallbackForUnknownCurrency(): void
    {
        $this->fxRateRepo->method('findOneBy')
            ->willReturn(null);

        $this->liveFetcher->method('getLiveRate')
            ->willReturn(null);

        $service = $this->createService();
        $result = $service->getRate('XYZ', 'ABC');

        $this->assertSame(1.0, $result['rate']);
        $this->assertSame('unknown', $result['source']);
        $this->assertTrue($result['stale']);
    }

    // ── Auto-fetch disabled ─────────────────────────────────────

    public function testGetRateSkipsLiveWhenAutoFetchDisabled(): void
    {
        $this->fxRateRepo->method('findOneBy')
            ->willReturn(null);

        $this->liveFetcher->expects($this->never())
            ->method('getLiveRate');

        $service = $this->createService();
        $service->setAutoFetchLiveRates(false);
        $result = $service->getRate('EUR', 'USD');

        $this->assertSame('fallback', $result['source']);
    }

    // ── No LiveFxRateFetcher injected ───────────────────────────

    public function testGetRateWorksWithoutLiveFetcher(): void
    {
        $this->fxRateRepo->method('findOneBy')
            ->willReturn(null);

        $service = new CurrencyConversionService(
            $this->fxRateRepo,
            $this->logger,
            null  // no live fetcher
        );
        $result = $service->getRate('EUR', 'USD');

        $this->assertSame('fallback', $result['source']);
        $this->assertTrue($result['stale']);
    }

    // ── convert() end-to-end ────────────────────────────────────

    public function testConvertAppliesRate(): void
    {
        $fxRate = $this->createFreshFxRate('EUR', 'USD', 1.04);

        $this->fxRateRepo->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($fxRate) {
                if ($criteria['fromCurrency'] === 'EUR' && $criteria['toCurrency'] === 'USD') {
                    return $fxRate;
                }
                return null;
            });

        $service = $this->createService();
        $result = $service->convert(100.0, 'EUR', 'USD');

        $this->assertEqualsWithDelta(104.0, $result['amount'], 0.01);
        $this->assertSame(1.04, $result['rate']);
    }

    public function testConvertZeroAmountReturnsZero(): void
    {
        $service = $this->createService();
        $result = $service->convert(0.0, 'EUR', 'EUR');

        $this->assertSame(0.0, $result['amount']);
    }

    // ── convertWithAudit() ──────────────────────────────────────

    public function testConvertWithAuditIncludesMetadata(): void
    {
        $service = $this->createService();
        $result = $service->convertWithAudit(100.0, 'USD', 'USD');

        $this->assertArrayHasKey('audit', $result);
        $this->assertSame(100.0, $result['audit']['original_amount']);
        $this->assertSame('USD', $result['audit']['from_currency']);
        $this->assertSame('USD', $result['audit']['to_currency']);
        $this->assertTrue($result['audit']['live_fetch_enabled']);
        $this->assertTrue($result['audit']['live_fetcher_available']);
    }

    // ── validateRateFreshness() ─────────────────────────────────

    public function testValidateRateFreshnessReportsStaleRates(): void
    {
        $this->fxRateRepo->method('findOneBy')
            ->willReturn(null);

        $this->liveFetcher->method('getLiveRate')
            ->willReturn(null);

        $service = $this->createService();
        $issues = $service->validateRateFreshness(['EUR', 'GBP', 'USD']);

        // USD is skipped, EUR and GBP should report issues
        $this->assertCount(2, $issues);
        $currencies = array_column($issues, 'currency');
        $this->assertContains('EUR', $currencies);
        $this->assertContains('GBP', $currencies);
    }

    public function testValidateRateFreshnessReturnsEmptyWhenFresh(): void
    {
        $eurRate = $this->createFreshFxRate('EUR', 'USD', 1.04);
        $gbpRate = $this->createFreshFxRate('GBP', 'USD', 1.25);

        $this->fxRateRepo->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($eurRate, $gbpRate) {
                if ($criteria['fromCurrency'] === 'EUR' && $criteria['toCurrency'] === 'USD') {
                    return $eurRate;
                }
                if ($criteria['fromCurrency'] === 'GBP' && $criteria['toCurrency'] === 'USD') {
                    return $gbpRate;
                }
                return null;
            });

        $service = $this->createService();
        $issues = $service->validateRateFreshness(['EUR', 'GBP', 'USD']);

        $this->assertEmpty($issues);
    }

    // ── convertPricingToUsd() ───────────────────────────────────

    public function testConvertPricingToUsdConvertsMultiplePriceBreaks(): void
    {
        $fxRate = $this->createFreshFxRate('EUR', 'USD', 1.04);

        $this->fxRateRepo->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($fxRate) {
                if ($criteria['fromCurrency'] === 'EUR' && $criteria['toCurrency'] === 'USD') {
                    return $fxRate;
                }
                return null;
            });

        $pricing = [
            ['quantity' => 100, 'price' => 10.0, 'currency' => 'EUR'],
            ['quantity' => 500, 'price' => 8.0, 'currency' => 'USD'], // already USD
        ];

        $service = $this->createService();
        $result = $service->convertPricingToUsd($pricing);

        $this->assertCount(2, $result);

        // EUR→USD converted
        $this->assertSame('USD', $result[0]['currency']);
        $this->assertEqualsWithDelta(10.4, $result[0]['price'], 0.01);
        $this->assertSame(10.0, $result[0]['original_price']);
        $this->assertSame('EUR', $result[0]['original_currency']);

        // USD already — pass-through
        $this->assertSame(8.0, $result[1]['price']);
    }
}
