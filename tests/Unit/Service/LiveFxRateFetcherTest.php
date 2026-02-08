<?php

namespace App\Tests\Unit\Service;

use App\Entity\FxRate;
use App\Service\LiveFxRateFetcher;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Unit tests for LiveFxRateFetcher
 *
 * Validates:
 *   - Frankfurter.app parsing + cross-rate logic
 *   - ExchangeRate-API parsing
 *   - getLiveRate() source-selection waterfall
 *   - Auto-persistence via persistLiveRate()
 *   - Graceful failure when APIs are down
 */
class LiveFxRateFetcherTest extends TestCase
{
    private EntityManagerInterface $em;
    private CacheInterface $cache;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // By default, cache->get() executes the callback immediately (no caching)
        $this->cache->method('get')->willReturnCallback(
            function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);
                return $callback($item);
            }
        );
    }

    /**
     * Stub the EntityManager so storeRate() calls don't fail.
     */
    private function stubEntityManagerForStore(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(AbstractQuery::class);

        $qb->method('update')->willReturnSelf();
        $qb->method('set')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $query->method('execute')->willReturn(0);

        $this->em->method('createQueryBuilder')->willReturn($qb);
        $this->em->method('persist')->willReturn(null);
        $this->em->method('flush')->willReturn(null);
    }

    private function createFetcher(MockHttpClient $httpClient): LiveFxRateFetcher
    {
        return new LiveFxRateFetcher(
            $httpClient,
            $this->em,
            $this->cache,
            $this->logger,
        );
    }

    // ── fetchFromFrankfurter() ──────────────────────────────────

    public function testFetchFromFrankfurterParsesRates(): void
    {
        $body = json_encode([
            'base' => 'EUR',
            'date' => '2026-02-07',
            'rates' => [
                'USD' => 1.04,
                'GBP' => 0.83,
                'JPY' => 156.2,
            ],
        ]);

        $httpClient = new MockHttpClient([
            new MockResponse($body, ['http_code' => 200]),
        ]);

        $fetcher = $this->createFetcher($httpClient);
        $rates = $fetcher->fetchFromFrankfurter();

        $this->assertArrayHasKey('USD', $rates);
        $this->assertSame(1.04, $rates['USD']);
        $this->assertSame(0.83, $rates['GBP']);
    }

    public function testFetchFromFrankfurterReturnsEmptyOnError(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
        ]);

        $fetcher = $this->createFetcher($httpClient);
        $rates = $fetcher->fetchFromFrankfurter();

        $this->assertEmpty($rates);
    }

    // ── fetchFromExchangeRateApi() ──────────────────────────────

    public function testFetchFromExchangeRateApiParsesRates(): void
    {
        $body = json_encode([
            'result' => 'success',
            'base_code' => 'USD',
            'rates' => [
                'EUR' => 0.96,
                'MAD' => 10.05,
                'TND' => 3.13,
            ],
        ]);

        $httpClient = new MockHttpClient([
            new MockResponse($body, ['http_code' => 200]),
        ]);

        $fetcher = $this->createFetcher($httpClient);
        $rates = $fetcher->fetchFromExchangeRateApi('USD');

        $this->assertArrayHasKey('MAD', $rates);
        $this->assertSame(10.05, $rates['MAD']);
    }

    // ── getLiveRate() waterfall ──────────────────────────────────

    public function testGetLiveRateIdentityReturnsOne(): void
    {
        $httpClient = new MockHttpClient([]);
        $fetcher = $this->createFetcher($httpClient);
        $result = $fetcher->getLiveRate('USD', 'USD');

        $this->assertSame(1.0, $result['rate']);
        $this->assertSame('identity', $result['source']);
    }

    public function testGetLiveRateDirectEurToTarget(): void
    {
        $this->stubEntityManagerForStore();

        $frankfurterResponse = json_encode([
            'base' => 'EUR',
            'date' => '2026-02-07',
            'rates' => ['USD' => 1.04, 'GBP' => 0.83],
        ]);

        $httpClient = new MockHttpClient([
            new MockResponse($frankfurterResponse, ['http_code' => 200]),
        ]);

        $fetcher = $this->createFetcher($httpClient);
        $result = $fetcher->getLiveRate('EUR', 'USD');

        $this->assertNotNull($result);
        $this->assertSame(1.04, $result['rate']);
        $this->assertSame('ecb_frankfurter', $result['source']);
    }

    public function testGetLiveRateInverseTargetToEur(): void
    {
        $this->stubEntityManagerForStore();

        $frankfurterResponse = json_encode([
            'base' => 'EUR',
            'date' => '2026-02-07',
            'rates' => ['USD' => 1.04, 'GBP' => 0.83],
        ]);

        $httpClient = new MockHttpClient([
            new MockResponse($frankfurterResponse, ['http_code' => 200]),
        ]);

        $fetcher = $this->createFetcher($httpClient);
        $result = $fetcher->getLiveRate('USD', 'EUR');

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(1 / 1.04, $result['rate'], 0.001);
        $this->assertSame('ecb_frankfurter_inverse', $result['source']);
    }

    public function testGetLiveRateCrossViaEur(): void
    {
        $this->stubEntityManagerForStore();

        $frankfurterResponse = json_encode([
            'base' => 'EUR',
            'date' => '2026-02-07',
            'rates' => ['USD' => 1.04, 'GBP' => 0.83],
        ]);

        $httpClient = new MockHttpClient([
            new MockResponse($frankfurterResponse, ['http_code' => 200]),
        ]);

        $fetcher = $this->createFetcher($httpClient);
        $result = $fetcher->getLiveRate('USD', 'GBP');

        $this->assertNotNull($result);
        $expectedCross = 0.83 / 1.04;
        $this->assertEqualsWithDelta($expectedCross, $result['rate'], 0.001);
        $this->assertSame('ecb_frankfurter_cross', $result['source']);
    }

    public function testGetLiveRateFallsToExchangeRateApi(): void
    {
        $this->stubEntityManagerForStore();

        // Frankfurter fails
        $frankfurterFail = new MockResponse('', ['http_code' => 500]);

        // ExchangeRate-API succeeds
        $exchangeRateResponse = json_encode([
            'result' => 'success',
            'base_code' => 'USD',
            'rates' => ['MAD' => 10.05, 'TND' => 3.13],
        ]);

        $httpClient = new MockHttpClient([
            $frankfurterFail,
            new MockResponse($exchangeRateResponse, ['http_code' => 200]),
        ]);

        $fetcher = $this->createFetcher($httpClient);
        $result = $fetcher->getLiveRate('USD', 'MAD');

        $this->assertNotNull($result);
        $this->assertSame(10.05, $result['rate']);
        $this->assertSame('exchangerate_api', $result['source']);
    }

    public function testGetLiveRateReturnsNullWhenAllApiFail(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
            new MockResponse('', ['http_code' => 500]),
        ]);

        $fetcher = $this->createFetcher($httpClient);
        $result = $fetcher->getLiveRate('EUR', 'MAD');

        $this->assertNull($result);
    }

    // ── persistLiveRate() via getLiveRate() ──────────────────────

    public function testGetLiveRatePersistsToDatabase(): void
    {
        // Expect persist + flush to be called (auto-persistence)
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');
        $this->stubEntityManagerForStore();

        $frankfurterResponse = json_encode([
            'base' => 'EUR',
            'date' => '2026-02-07',
            'rates' => ['USD' => 1.04],
        ]);

        $httpClient = new MockHttpClient([
            new MockResponse($frankfurterResponse, ['http_code' => 200]),
        ]);

        $fetcher = $this->createFetcher($httpClient);
        $result = $fetcher->getLiveRate('EUR', 'USD');

        $this->assertNotNull($result);
        $this->assertSame(1.04, $result['rate']);
    }

    public function testGetLiveRateStillReturnsRateWhenPersistFails(): void
    {
        // storeRate() will throw — but the rate should still be returned
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('update')->willReturnSelf();
        $qb->method('set')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willThrowException(new \RuntimeException('DB down'));

        $this->em->method('createQueryBuilder')->willReturn($qb);

        $frankfurterResponse = json_encode([
            'base' => 'EUR',
            'date' => '2026-02-07',
            'rates' => ['USD' => 1.04],
        ]);

        $httpClient = new MockHttpClient([
            new MockResponse($frankfurterResponse, ['http_code' => 200]),
        ]);

        $fetcher = $this->createFetcher($httpClient);
        $result = $fetcher->getLiveRate('EUR', 'USD');

        // Rate should still be returned even though DB persist failed
        $this->assertNotNull($result);
        $this->assertSame(1.04, $result['rate']);
    }

    // ── getSupportedCurrencies() ────────────────────────────────

    public function testGetSupportedCurrenciesReturnsExpectedStructure(): void
    {
        $httpClient = new MockHttpClient([]);
        $fetcher = $this->createFetcher($httpClient);
        $currencies = $fetcher->getSupportedCurrencies();

        $this->assertArrayHasKey('primary', $currencies);
        $this->assertArrayHasKey('secondary', $currencies);
        $this->assertArrayHasKey('regional', $currencies);
        $this->assertArrayHasKey('sources', $currencies);
        $this->assertContains('USD', $currencies['primary']);
        $this->assertContains('EUR', $currencies['primary']);
        $this->assertContains('TND', $currencies['primary']);
    }

    // ── checkRateFreshness() ────────────────────────────────────

    public function testCheckRateFreshnessDetectsStaleRates(): void
    {
        $staleRate = new FxRate();
        $staleRate->setFromCurrency('EUR');
        $staleRate->setToCurrency('USD');
        $staleRate->setRate('1.04');
        $staleRate->setAsof(new \DateTime('-48 hours'));
        $staleRate->setIsActive(true);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn([$staleRate]);
        $this->em->method('getRepository')->willReturn($repo);

        $httpClient = new MockHttpClient([]);
        $fetcher = $this->createFetcher($httpClient);
        $freshness = $fetcher->checkRateFreshness();

        $this->assertArrayHasKey('EUR/USD', $freshness);
        $this->assertTrue($freshness['EUR/USD']['stale']);
    }

    public function testCheckRateFreshnessReportsFreshRates(): void
    {
        $freshRate = new FxRate();
        $freshRate->setFromCurrency('EUR');
        $freshRate->setToCurrency('USD');
        $freshRate->setRate('1.04');
        $freshRate->setAsof(new \DateTime('-1 hour'));
        $freshRate->setIsActive(true);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn([$freshRate]);
        $this->em->method('getRepository')->willReturn($repo);

        $httpClient = new MockHttpClient([]);
        $fetcher = $this->createFetcher($httpClient);
        $freshness = $fetcher->checkRateFreshness();

        $this->assertArrayHasKey('EUR/USD', $freshness);
        $this->assertFalse($freshness['EUR/USD']['stale']);
    }
}
