<?php

namespace App\Tests\Unit\Service;

use App\Service\PricingEngine;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Critical-surface coverage: PricingEngine::applyManualOverrides() and
 * recalculateStats() sit on the canonical billing path (every manual price
 * a human confirms flows through them before calculateQuoteTotals bills
 * the customer). Exact-arithmetic expectations — a regression here is
 * money wrong, not just a red build.
 */
class PricingEngineManualOverrideTest extends KernelTestCase
{
    private PricingEngine $engine;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->engine = self::getContainer()->get(PricingEngine::class);
    }

    private function line(float $unitPrice, int $quantity, string $status = 'sourced', string $source = 'mouser'): array
    {
        return [
            'mpn' => 'TEST-1',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'extended_price' => $unitPrice * $quantity,
            'status' => $status,
            'source' => $source,
            'confidence' => ['score' => 80, 'level' => 'MEDIUM', 'requiresReview' => false, 'reasons' => [], 'warnings' => []],
        ];
    }

    public function testManualOverrideRepricesLineAndMarksManualVerified(): void
    {
        $lines = [$this->line(2.0, 500)];

        $result = $this->engine->applyManualOverrides($lines, [
            0 => ['unit_price' => 1.5, 'notes' => 'negotiated with supplier'],
        ]);

        $this->assertSame(1.5, $result[0]['unit_price']);
        $this->assertSame(750.0, $result[0]['extended_price'], 'extended price must be repriced: 1.5 × 500');
        $this->assertSame('manual', $result[0]['source']);
        $this->assertSame('manual_override', $result[0]['status']);
        $this->assertSame('negotiated with supplier', $result[0]['manual_notes']);
        $this->assertSame(100, $result[0]['confidence']['score']);
        $this->assertFalse($result[0]['confidence']['requiresReview']);
    }

    public function testVerifyOnlyOverrideKeepsPriceButClearsReview(): void
    {
        $lines = [$this->line(2.0, 500)];
        $lines[0]['confidence']['requiresReview'] = true;

        $result = $this->engine->applyManualOverrides($lines, [
            0 => ['verified' => true],
        ]);

        $this->assertSame(2.0, $result[0]['unit_price'], 'verify-only must NOT change the price');
        $this->assertSame('verified', $result[0]['status']);
        $this->assertFalse($result[0]['confidence']['requiresReview']);
    }

    public function testOverrideForUnknownLineIndexIsIgnored(): void
    {
        $lines = [$this->line(2.0, 500)];

        $result = $this->engine->applyManualOverrides($lines, [
            7 => ['unit_price' => 0.01],
        ]);

        $this->assertSame(2.0, $result[0]['unit_price'], 'out-of-range override must be dropped, never crash');
        $this->assertSame('sourced', $result[0]['status']);
    }

    public function testNonPositiveOverridePriceIsIgnored(): void
    {
        $lines = [$this->line(2.0, 500)];

        $result = $this->engine->applyManualOverrides($lines, [
            0 => ['unit_price' => 0],
        ]);

        $this->assertSame(2.0, $result[0]['unit_price'], 'a zero/negative manual price must not poison the quote');
    }

    public function testRecalculateStatsCountsSourcedUnsourcedAndCoverage(): void
    {
        $lines = [
            $this->line(2.0, 500),                      // sourced mouser, 1000.00
            $this->line(1.0, 100, 'manual_override', 'manual'), // manual, 100.00
            ['mpn' => 'X', 'status' => 'not_found', 'confidence' => ['requiresReview' => true]],
        ];

        $stats = $this->engine->recalculateStats($lines);

        $this->assertSame(3, $stats['total_lines']);
        $this->assertSame(2, $stats['sourced']);
        $this->assertSame(1, $stats['unsourced']);
        $this->assertSame(1100.0, $stats['total_cost']);
        $this->assertSame(1, $stats['sources']['mouser']);
        $this->assertSame(1, $stats['sources']['manual']);
        $this->assertSame(66.67, $stats['coverage_percent']);
        $this->assertSame(1, $stats['requires_review_count']);
    }

    public function testRecalculateStatsOnEmptyInput(): void
    {
        $stats = $this->engine->recalculateStats([]);
        $this->assertSame(0, $stats['total_lines']);
        $this->assertSame(0, $stats['coverage_percent']);
    }
}
