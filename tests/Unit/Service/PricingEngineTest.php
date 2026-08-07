<?php

namespace App\Tests\Unit\Service;

use App\Service\PricingEngine;
use App\Service\PriceImputationService;
use App\Service\RiskAdjustedPricingService;
use App\Service\CurrencyConverter;
use App\Service\Integration\AlibabaApiClient;
use App\Service\Integration\MouserApiClient;
use App\Service\Integration\DigiKeyApiClient;
use App\Service\Integration\NexarApiClient;
use App\Service\Integration\MultiDistributorSourcingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the PricingEngine — API waterfall, confidence scoring, auto-publish.
 */
class PricingEngineTest extends TestCase
{
    private PricingEngine $engine;
    private AlibabaApiClient $alibabaClient;
    private MouserApiClient $mouserClient;
    private DigiKeyApiClient $digikeyClient;
    private NexarApiClient $nexarClient;
    private MultiDistributorSourcingService $multiDistributor;
    private CurrencyConverter $currencyConverter;
    private PriceImputationService $priceImputation;
    private RiskAdjustedPricingService $riskAdjustedPricing;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->alibabaClient = $this->createMock(AlibabaApiClient::class);
        $this->mouserClient = $this->createMock(MouserApiClient::class);
        $this->digikeyClient = $this->createMock(DigiKeyApiClient::class);
        $this->nexarClient = $this->createMock(NexarApiClient::class);
        $this->multiDistributor = $this->createMock(MultiDistributorSourcingService::class);
        $this->currencyConverter = $this->createMock(CurrencyConverter::class);
        $this->priceImputation = $this->createMock(PriceImputationService::class);
        $this->riskAdjustedPricing = $this->createMock(RiskAdjustedPricingService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->engine = new PricingEngine(
            $this->alibabaClient,
            $this->mouserClient,
            $this->digikeyClient,
            $this->nexarClient,
            $this->multiDistributor,
            $this->currencyConverter,
            $this->priceImputation,
            $this->riskAdjustedPricing,
            $this->logger
        );
    }

    // ── getPricing() tests ──
    // getPricing() delegates to MultiDistributorSourcingService::searchPart()

    public function testGetPricingReturnsNullWhenAllSourcesFail(): void
    {
        // MultiDistributor returns empty (all sources failed)
        $this->multiDistributor->method('searchPart')->willReturn([
            'selected' => null,
            'source' => null,
            'alternatives' => [],
            'waterfall_triggered' => true,
            'waterfall_reason' => 'All sources failed',
            'all_sources' => [],
        ]);

        $result = $this->engine->getPricing('UNKNOWN-123');

        $this->assertNull($result);
    }

    public function testGetPricingReturnsMultiDistributorResult(): void
    {
        $multiResult = [
            'selected' => [
                'mpn' => 'STM32F103',
                'manufacturer' => 'STMicroelectronics',
                'description' => 'ARM MCU',
                'unit_price' => 5.00,
                'stock' => 5000,
                'confidence' => ['level' => 'HIGH', 'score' => 95],
            ],
            'source' => 'mouser',
            'alternatives' => [],
            'waterfall_triggered' => false,
            'waterfall_reason' => null,
            'all_sources' => ['mouser' => true],
        ];

        $this->multiDistributor->method('searchPart')->willReturn($multiResult);

        $result = $this->engine->getPricing('STM32F103');

        $this->assertNotNull($result);
        $this->assertEquals('mouser', $result['source']);
        $this->assertEquals('STM32F103', $result['mpn']);
    }

    public function testGetPricingPassesProvidersOption(): void
    {
        $this->multiDistributor->expects($this->once())
            ->method('searchPart')
            ->with(
                $this->equalTo('PART-1'),
                $this->equalTo(null),
                $this->equalTo(null),
                $this->callback(function ($opts) {
                    return isset($opts['providers']) && $opts['providers'] === ['mouser', 'digikey'];
                })
            )
            ->willReturn([
                'selected' => ['mpn' => 'PART-1', 'confidence' => ['level' => 'HIGH', 'score' => 90]],
                'source' => 'mouser',
                'alternatives' => [],
                'waterfall_triggered' => false,
                'waterfall_reason' => null,
                'all_sources' => ['mouser' => true],
            ]);

        $result = $this->engine->getPricing('PART-1', null, null, [
            'providers' => ['mouser', 'digikey'],
        ]);

        $this->assertNotNull($result);
    }

    public function testGetPricingMemoizesIdenticalInput(): void
    {
        $multiResult = [
            'selected' => ['mpn' => 'MEMO-1', 'unit_price' => 5.00, 'confidence' => ['level' => 'HIGH', 'score' => 95]],
            'source' => 'mouser',
            'alternatives' => [],
            'waterfall_triggered' => false,
            'waterfall_reason' => null,
            'all_sources' => ['mouser' => true],
        ];

        // The underlying source must be hit exactly once for identical input
        $this->multiDistributor->expects($this->once())
            ->method('searchPart')
            ->willReturn($multiResult);

        $first = $this->engine->getPricing('MEMO-1');
        $second = $this->engine->getPricing('MEMO-1');

        $this->assertNotNull($first);
        $this->assertSame($first['source'], $second['source']);
        $this->assertSame($first['mpn'], $second['mpn']);
    }

    public function testGetPricingMemoRespectsOptions(): void
    {
        $multiResult = [
            'selected' => ['mpn' => 'MEMO-2', 'unit_price' => 5.00, 'confidence' => ['level' => 'HIGH', 'score' => 95]],
            'source' => 'mouser',
            'alternatives' => [],
            'waterfall_triggered' => false,
            'waterfall_reason' => null,
            'all_sources' => ['mouser' => true],
        ];

        $this->multiDistributor->expects($this->exactly(2))
            ->method('searchPart')
            ->willReturn($multiResult);

        // Different providers option = different input = no memo hit
        $this->engine->getPricing('MEMO-2', null, null, ['providers' => ['mouser']]);
        $this->engine->getPricing('MEMO-2', null, null, ['providers' => ['digikey']]);
    }

    public function testGetPricingMemoIsPerPartNumber(): void
    {
        $this->multiDistributor->expects($this->exactly(2))
            ->method('searchPart')
            ->willReturn([
                'selected' => ['mpn' => 'X', 'unit_price' => 1.0, 'confidence' => ['level' => 'HIGH', 'score' => 90]],
                'source' => 'mouser',
                'alternatives' => [],
                'waterfall_triggered' => false,
                'waterfall_reason' => null,
                'all_sources' => ['mouser' => true],
            ]);

        $this->engine->getPricing('PART-A');
        $this->engine->getPricing('PART-B');
    }

    public function testGetPricingFromSourceMemoizes(): void
    {
        $expected = ['mpn' => 'SRC-1', 'source' => 'mouser'];
        $this->mouserClient->expects($this->once())
            ->method('searchByPartNumber')
            ->willReturn($expected);

        $this->engine->getPricingFromSource('SRC-1', 'mouser');
        $this->engine->getPricingFromSource('SRC-1', 'mouser');
    }

    // ── getPricingFromSource() tests ──
    // getPricingFromSource() uses match() to call individual client searchByPartNumber()

    public function testGetPricingFromSourceAlibaba(): void
    {
        $expected = ['mpn' => 'ALI-PART', 'source' => 'alibaba'];
        $this->alibabaClient->method('searchByPartNumber')->willReturn($expected);

        $result = $this->engine->getPricingFromSource('ALI-PART', 'alibaba');
        $this->assertEquals($expected, $result);
    }

    public function testGetPricingFromSourceMouser(): void
    {
        $expected = ['mpn' => 'MOUSER-PART', 'source' => 'mouser'];
        $this->mouserClient->method('searchByPartNumber')->willReturn($expected);

        $result = $this->engine->getPricingFromSource('MOUSER-PART', 'mouser');
        $this->assertEquals($expected, $result);
    }

    // ── canAutoPublish() tests ──
    // stats array uses snake_case keys: coverage_percent

    public function testCanAutoPublishReturnsArrayWithCanPublishKey(): void
    {
        $stats = [
            'totalLines' => 100,
            'sourcedLines' => 95,
            'coverage_percent' => 95.0,
        ];

        $processedLines = array_fill(0, 100, [
            'unit_price' => 1.00,
            'source' => 'mouser',
            'status' => 'sourced',
            'quantity' => 10,
        ]);

        $result = $this->engine->canAutoPublish($stats, $processedLines);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('can_publish', $result);
    }

    public function testCanAutoPublishReturnsFalseWhenCoverageLow(): void
    {
        $stats = [
            'totalLines' => 100,
            'sourcedLines' => 40,
            'coverage_percent' => 40.0,
        ];

        $processedLines = array_fill(0, 100, [
            'unit_price' => 1.00,
            'status' => 'unsourced',
            'quantity' => 10,
        ]);

        $result = $this->engine->canAutoPublish($stats, $processedLines);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('can_publish', $result);
        $this->assertFalse($result['can_publish']);
    }

    // ── calculateQuoteTotals() tests ──
    // Returns: subtotal, margin_percent, margin_amount, total, currency

    public function testCalculateQuoteTotalsReturnsCorrectStructure(): void
    {
        $processedLines = [
            ['extended_price' => 1000.00, 'currency' => 'USD'],
            ['extended_price' => 500.00, 'currency' => 'USD'],
        ];

        $result = $this->engine->calculateQuoteTotals($processedLines, 25.0, 'USD');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('subtotal', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('margin_amount', $result);
        $this->assertArrayHasKey('margin_percent', $result);
        $this->assertArrayHasKey('currency', $result);

        // subtotal = 1500, margin = 375 (25%), total = 1875
        $this->assertEquals(1500.0, $result['subtotal']);
        $this->assertEquals(375.0, $result['margin_amount']);
        $this->assertEquals(1875.0, $result['total']);
    }

    public function testCalculateQuoteTotalsWithZeroMargin(): void
    {
        $processedLines = [
            ['extended_price' => 100.00, 'currency' => 'USD'],
        ];

        $result = $this->engine->calculateQuoteTotals($processedLines, 0.0, 'USD');

        $this->assertEquals(100.0, $result['subtotal']);
        $this->assertEquals(0.0, $result['margin_amount']);
        $this->assertEquals(100.0, $result['total']);
    }

    // ── processBOM() tests ──

    public function testProcessBomReturnsExpectedStructure(): void
    {
        $bomLines = [
            ['mpn' => 'PART-1', 'quantity' => 10, 'ref' => 'R1'],
        ];

        // Mock searchPart to return data with a 'pricing' key containing price breaks
        $this->multiDistributor->method('searchPart')->willReturn([
            'selected' => [
                'mpn' => 'PART-1',
                'unit_price' => 1.00,
                'stock' => 1000,
                'source' => 'mouser',
                'pricing' => [
                    ['quantity' => 1, 'price' => 1.00],
                    ['quantity' => 10, 'price' => 0.80],
                ],
                'confidence' => ['level' => 'HIGH', 'score' => 90],
                'moq' => 1,
            ],
            'source' => 'mouser',
            'alternatives' => [],
            'waterfall_triggered' => false,
            'waterfall_reason' => null,
            'all_sources' => ['mouser' => true],
        ]);

        $result = $this->engine->processBOM($bomLines);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('lines', $result);
        $this->assertArrayHasKey('stats', $result);
        $this->assertArrayHasKey('reviewRequired', $result);
    }

    public function testProcessBomHandlesEmptyBom(): void
    {
        $result = $this->engine->processBOM([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('lines', $result);
        $this->assertEmpty($result['lines']);
    }

    // ── getPriceBreakRecommendation() tests ──
    // Price breaks use 'quantity' key (not 'qty')

    public function testGetPriceBreakRecommendationReturnsNullWhenNoBreaks(): void
    {
        $result = $this->engine->getPriceBreakRecommendation([], 100);
        $this->assertNull($result);
    }

    public function testGetPriceBreakRecommendationReturnsBestBreak(): void
    {
        $priceBreaks = [
            ['quantity' => 1, 'price' => 10.00],
            ['quantity' => 100, 'price' => 8.00],
            ['quantity' => 1000, 'price' => 5.00],
        ];

        $result = $this->engine->getPriceBreakRecommendation($priceBreaks, 250);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('recommended_quantity', $result);
        $this->assertArrayHasKey('current_unit_price', $result);
    }

    public function testGetPriceBreakRecommendationSelectsNextTierAboveCurrent(): void
    {
        $priceBreaks = [
            ['quantity' => 1, 'price' => 10.00],
            ['quantity' => 100, 'price' => 8.00],
            ['quantity' => 1000, 'price' => 5.00],
        ];

        // Request exactly 100 — current price is 8.00 (at 100-tier)
        // Next tier above 100 is 1000 at 5.00
        $result = $this->engine->getPriceBreakRecommendation($priceBreaks, 100);

        $this->assertNotNull($result);
        // recommended_quantity should be the next tier above current quantity
        $this->assertEquals(1000, $result['recommended_quantity']);
        $this->assertEquals(5.00, $result['recommended_unit_price']);
    }

    public function testGetPriceBreakRecommendationReturnsNullForInvalidQuantity(): void
    {
        $result = $this->engine->getPriceBreakRecommendation(
            [['quantity' => 1, 'price' => 10.00]],
            0 // Invalid quantity
        );
        $this->assertNull($result);
    }
}
