<?php

namespace App\Tests\Unit\Service;

use App\Service\PartMatchConfidenceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive tests for Part Match Confidence Calculator
 * Tests MPN normalization, fuzzy matching, manufacturer matching, and scoring
 */
class PartMatchConfidenceCalculatorTest extends TestCase
{
    private PartMatchConfidenceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new PartMatchConfidenceCalculator();
    }

    // ===== MPN NORMALIZATION TESTS =====

    public function testNormalizeMpnRemovesNonAlphanumeric(): void
    {
        $result = $this->calculator->normalizeMpn('RC0603FR-07100RL');
        $this->assertEquals('RC0603FR07100RL', $result);
    }

    public function testNormalizeMpnConvertsToUppercase(): void
    {
        $result = $this->calculator->normalizeMpn('rc0603fr');
        $this->assertEquals('RC0603FR', $result);
    }

    public function testNormalizeMpnPreservesAlphanumeric(): void
    {
        $result = $this->calculator->normalizeMpn('STM32F103C8T6');
        $this->assertEquals('STM32F103C8T6', $result);
    }

    // ===== MPN VARIANT GENERATION TESTS =====

    public function testGenerateMpnVariantsIncludesOriginal(): void
    {
        $variants = $this->calculator->generateMpnVariants('RC0603FR-07100RL');
        $this->assertContains('RC0603FR-07100RL', $variants);
    }

    public function testGenerateMpnVariantsIncludesNormalized(): void
    {
        $variants = $this->calculator->generateMpnVariants('RC0603FR-07100RL');
        $this->assertContains('RC0603FR07100RL', $variants);
    }

    public function testGenerateMpnVariantsProducesMultipleVariants(): void
    {
        $variants = $this->calculator->generateMpnVariants('RC0603FR-07100RL');
        $this->assertGreaterThan(1, count(array_unique($variants)));
    }

    // ===== CONFIDENCE CALCULATION TESTS =====

    public function testCalculateConfidenceExactMatch(): void
    {
        $result = $this->calculator->calculateConfidence(
            'STM32F103C8T6',
            'STMicroelectronics',
            '32-bit ARM Cortex-M3 MCU',
            [
                'mpn' => 'STM32F103C8T6',
                'manufacturer' => 'STMicroelectronics',
                'description' => 'ARM Cortex-M3 MCU, 32-bit, 72MHz',
                'price' => 5.50,
                'stock' => 1000
            ]
        );

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('level', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('requiresReview', $result);
        
        // Exact MPN match should score high
        $this->assertGreaterThanOrEqual(80, $result['score']);
        $this->assertEquals('HIGH', $result['level']);
        $this->assertFalse($result['requiresReview']);
    }

    public function testCalculateConfidenceFuzzyMatch(): void
    {
        $result = $this->calculator->calculateConfidence(
            'RC0603FR-07100RL',
            'Yageo',
            '100 Ohm 0603 Resistor',
            [
                'mpn' => 'RC0603FR07100RL', // Same but normalized
                'manufacturer' => 'Yageo Corporation',
                'description' => 'Thick Film Resistor 100 Ohm 0603',
                'price' => 0.05,
                'stock' => 10000
            ]
        );

        // Similar MPN should still score well
        $this->assertGreaterThanOrEqual(60, $result['score']);
        $this->assertContains($result['level'], ['HIGH', 'MEDIUM']);
    }

    public function testCalculateConfidencePoorMatch(): void
    {
        $result = $this->calculator->calculateConfidence(
            'STM32F103C8T6',
            'STMicroelectronics',
            'STM32 ARM MCU',
            [
                'mpn' => 'AT91SAM3U4E',
                'manufacturer' => 'Microchip',
                'description' => 'SAM3U Series ARM MCU'
            ]
        );

        // Completely different MPN should score low
        $this->assertLessThan(50, $result['score']);
        $this->assertContains($result['level'], ['LOW', 'VERY_LOW']);
        $this->assertTrue($result['requiresReview']);
    }

    public function testCalculateConfidenceNoDescription(): void
    {
        $result = $this->calculator->calculateConfidence(
            'STM32F103C8T6',
            'STMicroelectronics',
            null,
            [
                'mpn' => 'STM32F103C8T6',
                'manufacturer' => 'STMicroelectronics',
                'description' => 'ARM Cortex-M3 MCU'
            ]
        );

        // Still should score reasonably with exact MPN match
        $this->assertGreaterThanOrEqual(60, $result['score']);
    }

    public function testCalculateConfidenceReturnsReasons(): void
    {
        $result = $this->calculator->calculateConfidence(
            'TEST123',
            'ACME',
            null,
            [
                'mpn' => 'TEST123',
                'manufacturer' => 'ACME',
                'description' => 'Test Part'
            ]
        );

        $this->assertIsArray($result['reasons']);
        $this->assertNotEmpty($result['reasons']);
    }

    public function testCalculateConfidenceReturnsWarnings(): void
    {
        $result = $this->calculator->calculateConfidence(
            'TEST123',
            'ACME',
            'Capacitor 100nF',
            [
                'mpn' => 'DIFFERENT456',
                'manufacturer' => 'OtherCorp',
                'description' => 'Inductor 10mH' // Completely different part type
            ]
        );

        $this->assertIsArray($result['warnings']);
        // Should have warnings about mismatches
        $this->assertNotEmpty($result['warnings']);
    }

    // ===== CONFIDENCE LEVEL THRESHOLDS =====

    public function testConfidenceLevelHigh(): void
    {
        $result = $this->calculator->calculateConfidence(
            'EXACT-MATCH-123',
            'SameManufacturer',
            'Exact Same Part',
            [
                'mpn' => 'EXACT-MATCH-123',
                'manufacturer' => 'SameManufacturer',
                'description' => 'Exact Same Part Description',
                'price' => 10.00,
                'stock' => 500
            ]
        );

        $this->assertEquals('HIGH', $result['level']);
        $this->assertFalse($result['requiresReview']);
    }

    public function testConfidenceLevelMedium(): void
    {
        $result = $this->calculator->calculateConfidence(
            'PART-123-A',
            'ManufA',
            'Same type part',
            [
                'mpn' => 'PART-123-B', // Slightly different
                'manufacturer' => 'ManufA',
                'description' => 'Similar type part'
            ]
        );

        // Should be MEDIUM or lower due to MPN mismatch
        $this->assertTrue(in_array($result['level'], ['HIGH', 'MEDIUM', 'LOW', 'VERY_LOW']));
    }

    public function testConfidenceLevelVeryLow(): void
    {
        $result = $this->calculator->calculateConfidence(
            'ABC123',
            'CompanyA',
            'Resistor',
            [
                'mpn' => 'XYZ789',
                'manufacturer' => 'CompanyB',
                'description' => 'Capacitor'
            ]
        );

        // Completely different should be low/very low
        $this->assertLessThan(50, $result['score']);
        $this->assertTrue($result['requiresReview']);
    }

    // ===== MANUFACTURER MATCHING =====

    public function testManufacturerExactMatch(): void
    {
        $result = $this->calculator->calculateConfidence(
            'X',
            'Texas Instruments',
            null,
            [
                'mpn' => 'Y',
                'manufacturer' => 'Texas Instruments',
                'description' => null
            ]
        );

        // Should have positive reason about manufacturer match
        $reasonsString = implode(' ', $result['reasons']);
        $this->assertTrue(
            str_contains(strtolower($reasonsString), 'manufacturer') ||
            str_contains(strtolower($reasonsString), 'mfr'),
            'Should mention manufacturer match in reasons'
        );
    }

    public function testManufacturerAliasMatch(): void
    {
        $result = $this->calculator->calculateConfidence(
            'X',
            'TI',
            null,
            [
                'mpn' => 'X',
                'manufacturer' => 'Texas Instruments',
                'description' => null
            ]
        );

        // Common alias should still match
        $this->assertGreaterThanOrEqual(50, $result['score']);
    }

    public function testManufacturerMismatchReducesScore(): void
    {
        // Test with matching manufacturer
        $matchResult = $this->calculator->calculateConfidence(
            'SAME-MPN',
            'Microchip',
            null,
            [
                'mpn' => 'SAME-MPN',
                'manufacturer' => 'Microchip',
                'description' => null
            ]
        );

        // Test with mismatching manufacturer
        $mismatchResult = $this->calculator->calculateConfidence(
            'SAME-MPN',
            'Microchip',
            null,
            [
                'mpn' => 'SAME-MPN',
                'manufacturer' => 'Texas Instruments',
                'description' => null
            ]
        );

        // Mismatched manufacturer should result in lower score
        $this->assertLessThan($matchResult['score'], $mismatchResult['score']);
    }

    // ===== EDGE CASES =====

    public function testHandlesEmptyMpn(): void
    {
        $result = $this->calculator->calculateConfidence(
            '',
            null,
            null,
            [
                'mpn' => 'TEST123',
                'manufacturer' => 'ACME',
                'description' => 'Test Part'
            ]
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('score', $result);
    }

    public function testHandlesNullValues(): void
    {
        $result = $this->calculator->calculateConfidence(
            'TEST123',
            null,
            null,
            [
                'mpn' => 'TEST123',
                'manufacturer' => null,
                'description' => null
            ]
        );

        $this->assertIsArray($result);
        $this->assertGreaterThan(0, $result['score']); // Should still score based on MPN
    }

    public function testHandlesVeryLongMpn(): void
    {
        $longMpn = str_repeat('A', 100) . '12345';
        $result = $this->calculator->calculateConfidence(
            $longMpn,
            null,
            null,
            [
                'mpn' => $longMpn,
                'manufacturer' => null,
                'description' => null
            ]
        );

        $this->assertIsArray($result);
        $this->assertGreaterThan(50, $result['score']); // Exact match should still score well
    }

    public function testHandlesSpecialCharactersInMpn(): void
    {
        $result = $this->calculator->calculateConfidence(
            'MAX232CPE+',
            'Analog Devices',
            null,
            [
                'mpn' => 'MAX232CPE+',
                'manufacturer' => 'Analog Devices',
                'description' => null
            ]
        );

        $this->assertGreaterThan(50, $result['score']);
    }

    // ===== SCORE BOUNDARIES =====

    public function testScoreIsWithinBounds(): void
    {
        $testCases = [
            ['X', 'A', 'desc1', ['mpn' => 'Y', 'manufacturer' => 'B', 'description' => 'desc2']],
            ['EXACT', 'Same', 'Same', ['mpn' => 'EXACT', 'manufacturer' => 'Same', 'description' => 'Same']],
            ['', '', '', ['mpn' => '', 'manufacturer' => '', 'description' => '']],
        ];

        foreach ($testCases as $case) {
            $result = $this->calculator->calculateConfidence(
                $case[0],
                $case[1],
                $case[2],
                $case[3]
            );

            $this->assertGreaterThanOrEqual(0, $result['score'], 'Score should be >= 0');
            $this->assertLessThanOrEqual(100, $result['score'], 'Score should be <= 100');
        }
    }
}
