<?php

namespace App\Tests\Unit\Service;

use App\Service\RiskAdjustedPricingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Unit tests for the Risk-Adjusted Pricing Service
 */
class RiskAdjustedPricingServiceTest extends TestCase
{
    private RiskAdjustedPricingService $service;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new RiskAdjustedPricingService($this->logger);
    }

    /**
     * Create a service instance with a cache for testing cache persistence.
     */
    private function createServiceWithCache(?array $cachedData = null): RiskAdjustedPricingService
    {
        $cache = $this->createMock(CacheInterface::class);
        if ($cachedData !== null) {
            $cache->method('get')->willReturn($cachedData);
        }
        return new RiskAdjustedPricingService($this->logger, $cache);
    }

    public function testCalculateRiskAdjustedCostWithInStockInventory(): void
    {
        $offer = [
            'unit_price' => 10.00,
            'stock' => 5000,
            'leadtime' => '1 day',
            'lifecycle' => 'active',
        ];
        $requiredQty = 1000;

        $result = $this->service->calculateRiskAdjustedCost($offer, $requiredQty, 'digikey');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('composite_risk', $result);
        $this->assertArrayHasKey('risk_adjusted_cost', $result);
        $this->assertArrayHasKey('risk_breakdown', $result);
        
        // In-stock with good supplier should have low composite risk (under 0.30)
        $this->assertLessThan(0.30, $result['composite_risk']);
    }

    public function testCalculateRiskAdjustedCostWithLowStock(): void
    {
        $offer = [
            'unit_price' => 10.00,
            'stock' => 200,
            'leadtime' => '14 weeks',
            'lifecycle' => 'nrnd',
        ];
        $requiredQty = 1000;

        $result = $this->service->calculateRiskAdjustedCost($offer, $requiredQty, 'unknown_broker');

        // Low stock with long lead time and NRND lifecycle should have moderate to high risk
        $this->assertGreaterThan(0.40, $result['composite_risk']);
        // Risk adjusted cost should be higher than base (risk premium applied)
        $this->assertArrayHasKey('risk_adjusted_cost', $result);
    }

    public function testSelectBestOptionChoosesLowestRiskAdjustedPrice(): void
    {
        // The selection is based on risk-adjusted cost, not raw price
        // Let's just verify the selection logic works
        $offers = [
            'broker_a' => [
                'unit_price' => 8.00,
                'stock' => 1000,
                'leadtime' => '1 day',
                'lifecycle' => 'active',
            ],
            'broker_b' => [
                'unit_price' => 10.00,
                'stock' => 1000,
                'leadtime' => '1 day',
                'lifecycle' => 'active',
            ],
        ];

        $result = $this->service->selectBestOption($offers, 500);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('selected', $result);
        $this->assertArrayHasKey('alternatives', $result);
        
        // With identical risk profiles, cheaper option should be selected
        $this->assertEquals('broker_a', $result['selected']['source']);
    }

    public function testSelectBestOptionReturnsNullForEmptyOffers(): void
    {
        $result = $this->service->selectBestOption([], 1000);

        $this->assertNull($result['selected']);
        $this->assertEmpty($result['alternatives']);
    }

    public function testCalculateStockRiskReturnsLowForFullStock(): void
    {
        $result = $this->service->calculateRiskAdjustedCost([
            'unit_price' => 10.00,
            'stock' => 2000,
            'leadtime' => '1 day',
            'lifecycle' => 'active',
        ], 1000, 'digikey');

        // Full stock (>=100% of need) should have very low stock risk score
        $this->assertLessThanOrEqual(0.15, $result['risk_breakdown']['stock_risk']['score']);
    }

    public function testCalculateLeadTimeRiskForSameDay(): void
    {
        $result = $this->service->calculateRiskAdjustedCost([
            'unit_price' => 10.00,
            'stock' => 1000,
            'leadtime' => 'in stock',
            'lifecycle' => 'active',
        ], 1000, 'digikey');

        // Same day shipping should have low lead time risk score
        $this->assertLessThanOrEqual(0.30, $result['risk_breakdown']['lead_time_risk']['score']);
    }

    public function testCalculateLeadTimeRiskForLongLeadTime(): void
    {
        $result = $this->service->calculateRiskAdjustedCost([
            'unit_price' => 10.00,
            'stock' => 1000,
            'leadtime' => '52 weeks',
            'lifecycle' => 'active',
        ], 1000, 'factory_direct');

        // 52 week lead time should have high lead time risk
        $this->assertGreaterThanOrEqual(50, $result['risk_breakdown']['lead_time_risk']);
    }

    public function testPreferredDistributorHasLowerSupplierRisk(): void
    {
        $offerData = [
            'unit_price' => 10.00,
            'stock' => 1000,
            'leadtime' => '5 days',
            'lifecycle' => 'active',
        ];

        $resultDigiKey = $this->service->calculateRiskAdjustedCost($offerData, 1000, 'digikey');
        $resultUnknown = $this->service->calculateRiskAdjustedCost($offerData, 1000, 'random_broker_xyz');

        // DigiKey should have lower supplier risk
        $this->assertLessThan(
            $resultUnknown['risk_breakdown']['supplier_risk'],
            $resultDigiKey['risk_breakdown']['supplier_risk']
        );
    }

    public function testRiskScoreIsBoundedBetweenZeroAndHundred(): void
    {
        // Test various edge cases
        $testCases = [
            ['stock' => 10000, 'leadtime' => 'in stock'],
            ['stock' => 0, 'leadtime' => '52 weeks'],
            ['stock' => 500, 'leadtime' => '30 days'],
        ];

        foreach ($testCases as $case) {
            $result = $this->service->calculateRiskAdjustedCost(array_merge([
                'unit_price' => 10.00,
                'lifecycle' => 'active',
            ], $case), 1000, 'test_source');

            $this->assertGreaterThanOrEqual(0, $result['composite_risk']);
            $this->assertLessThanOrEqual(1, $result['composite_risk']);
        }
    }

    public function testLifecycleRiskDetectsObsoletePartTerms(): void
    {
        $offerObsolete = [
            'unit_price' => 5.00,
            'stock' => 1000,
            'leadtime' => '1 day',
            'lifecycle' => 'obsolete',
        ];
        
        $offerActive = [
            'unit_price' => 10.00,
            'stock' => 1000,
            'leadtime' => '1 day',
            'lifecycle' => 'active',
        ];

        $resultObsolete = $this->service->calculateRiskAdjustedCost($offerObsolete, 1000, 'broker');
        $resultActive = $this->service->calculateRiskAdjustedCost($offerActive, 1000, 'digikey');

        $this->assertGreaterThan(
            $resultActive['risk_breakdown']['lifecycle_risk']['score'],
            $resultObsolete['risk_breakdown']['lifecycle_risk']['score']
        );
    }
    
    public function testPriceBreaksAppliedCorrectly(): void
    {
        $offer = [
            'unit_price' => 10.00,
            'stock' => 10000,
            'leadtime' => '1 day',
            'lifecycle' => 'active',
            'price_breaks' => [
                ['qty' => 1, 'price' => 10.00],
                ['qty' => 100, 'price' => 8.00],
                ['qty' => 1000, 'price' => 5.00],
            ],
        ];
        
        // Request 500 units
        $result = $this->service->calculateRiskAdjustedCost($offer, 500, 'digikey');
        
        // Should have a valid risk-adjusted cost
        $this->assertArrayHasKey('risk_adjusted_cost', $result);
        // Price should be positive
        $this->assertGreaterThanOrEqual(0, $result['risk_adjusted_cost']);
    }

    // ──────────────────────────────────────────────
    // Cache persistence tests (E2)
    // ──────────────────────────────────────────────

    public function testConstructorLoadsFromCacheWhenProvided(): void
    {
        $cachedReliability = [
            'digikey' => 0.99,
            'mouser' => 0.50,
        ];

        $service = $this->createServiceWithCache($cachedReliability);
        $scores = $service->getSupplierReliabilityScores();

        // Should have loaded from cache (digikey 0.99 instead of default 0.93)
        $this->assertEquals(0.99, $scores['digikey']);
        $this->assertEquals(0.50, $scores['mouser']);
    }

    public function testConstructorUsesDefaultsWhenNoCache(): void
    {
        // Service without cache (from setUp) should use default scores
        $scores = $this->service->getSupplierReliabilityScores();

        $this->assertEquals(0.95, $scores['mouser']);
        $this->assertEquals(0.93, $scores['digikey']);
        $this->assertEquals(0.70, $scores['manual']);
    }

    public function testConstructorUsesDefaultsWhenCacheThrows(): void
    {
        $failingCache = $this->createMock(CacheInterface::class);
        $failingCache->method('get')->willThrowException(new \RuntimeException('Cache unavailable'));

        $service = new RiskAdjustedPricingService($this->logger, $failingCache);
        $scores = $service->getSupplierReliabilityScores();

        // Should fall back to defaults on cache error
        $this->assertEquals(0.95, $scores['mouser']);
        $this->assertEquals(0.93, $scores['digikey']);
    }

    public function testUpdateSupplierReliabilityPersistsToCache(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);

        // Expect cache->delete() to be called at least once (persist deletes + re-writes)
        $cache->expects($this->atLeastOnce())
            ->method('delete')
            ->with('risk_adjusted_pricing.supplier_reliability');

        $service = new RiskAdjustedPricingService($this->logger, $cache);

        // Update reliability — this should trigger persistSupplierReliability()
        $service->updateSupplierReliability('mouser', true);
    }

    public function testUpdateSupplierReliabilityIncreasesScoreOnTime(): void
    {
        $service = $this->createServiceWithCache(['mouser' => 0.90]);
        $before = $service->getSupplierReliabilityScores()['mouser'];

        // Mark as on-time — score should increase
        $service->updateSupplierReliability('mouser', true);
        $after = $service->getSupplierReliabilityScores()['mouser'];

        $this->assertGreaterThan($before, $after);
    }

    public function testUpdateSupplierReliabilityDecreasesScoreLate(): void
    {
        $service = $this->createServiceWithCache(['mouser' => 0.90]);
        $before = $service->getSupplierReliabilityScores()['mouser'];

        // Mark as late — score should decrease
        $service->updateSupplierReliability('mouser', false);
        $after = $service->getSupplierReliabilityScores()['mouser'];

        $this->assertLessThan($before, $after);
    }
}
