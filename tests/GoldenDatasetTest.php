<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\WebCrawler\Classifier\CompetitorProximityVeto;
use App\Service\WebCrawler\Classifier\ServiceProductClassifier;
use App\Service\WebCrawler\Evidence\BuyerEvidenceGate;
use App\Service\WebCrawler\QualityGate\GoldenDatasetReport;
use App\Service\WebCrawler\QualityGate\GoldenDatasetRunner;
use App\Service\WebCrawler\Rules\RuleEngine;
use App\Service\WebCrawler\Text\LanguageDetector;
use App\Service\WebCrawler\Text\TextNormalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Regression tests for the golden dataset pipeline.
 *
 * These tests validate that:
 *  1. The GoldenDatasetRunner correctly orchestrates all gates
 *  2. The GoldenDatasetReport computes correct metrics
 *  3. Known-good companies PASS the full pipeline
 *  4. Known-bad companies are REJECTED by at least one gate
 *  5. The YAML golden dataset file loads and all entries classify correctly
 */
class GoldenDatasetTest extends TestCase
{
    private GoldenDatasetRunner $runner;

    protected function setUp(): void
    {
        $logger = new NullLogger();
        $normalizer = new TextNormalizer();
        $projectDir = dirname(__DIR__);

        $this->runner = new GoldenDatasetRunner(
            buyerEvidenceGate: new BuyerEvidenceGate(),
            serviceProductClassifier: new ServiceProductClassifier(),
            competitorProximityVeto: new CompetitorProximityVeto(),
            ruleEngine: new RuleEngine($normalizer, $logger, $projectDir),
            languageDetector: new LanguageDetector(),
        );
    }

    // ═══════════════════════════════════════════════════
    // Report metrics
    // ═══════════════════════════════════════════════════

    public function testReportMetricsWithPerfectData(): void
    {
        $report = new GoldenDatasetReport([
            ['name' => 'A', 'domain' => 'a.com', 'expected' => 'PASS', 'actual' => 'PASS', 'correct' => true, 'category' => '', 'reject_gate' => null, 'gates' => []],
            ['name' => 'B', 'domain' => 'b.com', 'expected' => 'REJECT', 'actual' => 'REJECT', 'correct' => true, 'category' => '', 'reject_gate' => 'rule_engine', 'gates' => []],
        ]);

        $this->assertTrue($report->isPerfect());
        $this->assertEquals(100.0, $report->getAccuracyPercent());
        $this->assertEquals(1.0, $report->getPrecision());
        $this->assertEquals(1.0, $report->getRecall());
        $this->assertEquals(1.0, $report->getF1());
        $this->assertEquals(1, $report->getTruePositives());
        $this->assertEquals(1, $report->getTrueNegatives());
        $this->assertEquals(0, $report->getFalsePositives());
        $this->assertEquals(0, $report->getFalseNegatives());
        $this->assertEmpty($report->getFailures());
    }

    public function testReportMetricsWithFailures(): void
    {
        $report = new GoldenDatasetReport([
            ['name' => 'A', 'domain' => 'a.com', 'expected' => 'PASS', 'actual' => 'PASS', 'correct' => true, 'category' => '', 'reject_gate' => null, 'gates' => []],
            // False negative: good company rejected
            ['name' => 'B', 'domain' => 'b.com', 'expected' => 'PASS', 'actual' => 'REJECT', 'correct' => false, 'category' => '', 'reject_gate' => 'buyer_evidence', 'gates' => []],
            // True negative: bad company rejected
            ['name' => 'C', 'domain' => 'c.com', 'expected' => 'REJECT', 'actual' => 'REJECT', 'correct' => true, 'category' => '', 'reject_gate' => 'service_product', 'gates' => []],
            // False positive: bad company passed
            ['name' => 'D', 'domain' => 'd.com', 'expected' => 'REJECT', 'actual' => 'PASS', 'correct' => false, 'category' => '', 'reject_gate' => null, 'gates' => []],
        ]);

        $this->assertFalse($report->isPerfect());
        $this->assertEquals(50.0, $report->getAccuracyPercent());
        $this->assertEquals(1, $report->getTruePositives());
        $this->assertEquals(1, $report->getFalsePositives());
        $this->assertEquals(1, $report->getTrueNegatives());
        $this->assertEquals(1, $report->getFalseNegatives());
        $this->assertCount(2, $report->getFailures());

        // Precision: TP / (TP + FP) = 1/2
        $this->assertEqualsWithDelta(0.5, $report->getPrecision(), 0.01);
        // Recall: TP / (TP + FN) = 1/2
        $this->assertEqualsWithDelta(0.5, $report->getRecall(), 0.01);
    }

    public function testReportThreshold(): void
    {
        $report = new GoldenDatasetReport([
            ['name' => 'A', 'domain' => 'a.com', 'expected' => 'PASS', 'actual' => 'PASS', 'correct' => true, 'category' => '', 'reject_gate' => null, 'gates' => []],
            ['name' => 'B', 'domain' => 'b.com', 'expected' => 'REJECT', 'actual' => 'REJECT', 'correct' => true, 'category' => '', 'reject_gate' => 'x', 'gates' => []],
        ]);

        $this->assertTrue($report->meetsThreshold(0.90));
        $this->assertTrue($report->meetsThreshold(1.00));
        $this->assertTrue($report->meetsThreshold(0.50));
    }

    public function testReportSummaryString(): void
    {
        $report = new GoldenDatasetReport([
            ['name' => 'A', 'domain' => 'a.com', 'expected' => 'PASS', 'actual' => 'PASS', 'correct' => true, 'category' => 'oem', 'reject_gate' => null, 'gates' => []],
        ]);

        $summary = $report->toSummaryString();
        $this->assertStringContainsString('1/1 correct', $summary);
        $this->assertStringContainsString('100.0%', $summary);
    }

    public function testReportToArray(): void
    {
        $report = new GoldenDatasetReport([
            ['name' => 'A', 'domain' => 'a.com', 'expected' => 'PASS', 'actual' => 'PASS', 'correct' => true, 'category' => '', 'reject_gate' => null, 'gates' => []],
        ]);

        $arr = $report->toArray();
        $this->assertArrayHasKey('total', $arr);
        $this->assertArrayHasKey('accuracy', $arr);
        $this->assertArrayHasKey('precision', $arr);
        $this->assertArrayHasKey('recall', $arr);
        $this->assertArrayHasKey('f1', $arr);
        $this->assertArrayHasKey('failures', $arr);
        $this->assertEquals(1, $arr['total']);
    }

    // ═══════════════════════════════════════════════════
    // Runner validation
    // ═══════════════════════════════════════════════════

    public function testLoadFromArrayAndRunReportsCorrectly(): void
    {
        $this->runner->loadFromArray([
            [
                'expected' => 'PASS',
                'name' => 'Sick AG',
                'domain' => 'sick.com',
                'snippet' => 'SICK develops sensor solutions for factory automation. Our product range includes photoelectric sensors, encoders, and vision systems.',
                'title' => 'SICK AG - Sensor Intelligence',
                'country' => 'DE',
                'sector' => 'Industrial Sensors',
                'category' => 'Sensor manufacturer',
            ],
        ]);

        $report = $this->runner->run();
        $this->assertEquals(1, $report->getTotal());
    }

    public function testMissingRequiredFieldThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("missing required field 'name'");

        $this->runner->loadFromArray([
            [
                'expected' => 'PASS',
                'domain' => 'test.com',
                'snippet' => 'test',
                'title' => 'test',
            ],
        ]);
    }

    public function testInvalidExpectedValueThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("invalid 'expected'");

        $this->runner->loadFromArray([
            [
                'expected' => 'MAYBE',
                'name' => 'Test',
                'domain' => 'test.com',
                'snippet' => 'test',
                'title' => 'test',
            ],
        ]);
    }

    public function testLoadFromFileMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->runner->loadFromFile('/nonexistent/path.yaml');
    }

    // ═══════════════════════════════════════════════════
    // Individual gate tests
    // ═══════════════════════════════════════════════════

    public function testKnownGoodOEMPassesAllGates(): void
    {
        $result = $this->runner->evaluateSingle([
            'expected' => 'PASS',
            'name' => 'Bosch Rexroth',
            'domain' => 'boschrexroth.com',
            'snippet' => 'Bosch Rexroth designs and manufactures hydraulic, electric drives and controls. Our products include servo drives, controllers, and motion systems for industrial automation.',
            'title' => 'Bosch Rexroth - Drive & Control Technology',
            'country' => 'DE',
            'sector' => 'Industrial Automation',
            'category' => 'Large OEM',
        ]);

        $this->assertEquals('PASS', $result['actual'], "Bosch Rexroth should PASS: " . json_encode($result['gates']));
        $this->assertTrue($result['correct']);
    }

    public function testKnownEMSCompetitorIsRejected(): void
    {
        $result = $this->runner->evaluateSingle([
            'expected' => 'REJECT',
            'name' => 'Jabil Inc',
            'domain' => 'jabil.com',
            'snippet' => 'Jabil is a global manufacturing services company providing comprehensive electronics design, production, and product management services.',
            'title' => 'Jabil - Manufacturing Services',
            'country' => 'US',
            'sector' => 'EMS',
            'category' => 'EMS competitor',
        ]);

        $this->assertEquals('REJECT', $result['actual'], "Jabil should be REJECTED: " . json_encode($result['gates']));
        $this->assertTrue($result['correct']);
    }

    public function testKnownConsultingFirmIsRejected(): void
    {
        $result = $this->runner->evaluateSingle([
            'expected' => 'REJECT',
            'name' => 'Deloitte',
            'domain' => 'deloitte.com',
            'snippet' => 'Deloitte provides audit and assurance, consulting, financial advisory, risk advisory, and tax and legal services.',
            'title' => 'Deloitte - Audit, Consulting, Advisory',
            'country' => 'GB',
            'sector' => 'Consulting',
            'category' => 'Consulting firm',
        ]);

        $this->assertEquals('REJECT', $result['actual'], "Deloitte should be REJECTED: " . json_encode($result['gates']));
        $this->assertTrue($result['correct']);
    }

    public function testKnownLogisticsProviderIsRejected(): void
    {
        $result = $this->runner->evaluateSingle([
            'expected' => 'REJECT',
            'name' => 'Kuehne+Nagel',
            'domain' => 'kuehne-nagel.com',
            'snippet' => 'Kuehne+Nagel is a global logistics provider offering sea freight, air freight, contract logistics, and supply chain management services.',
            'title' => 'Kuehne+Nagel - Global Logistics',
            'country' => 'CH',
            'sector' => 'Logistics',
            'category' => 'Logistics provider',
        ]);

        $this->assertEquals('REJECT', $result['actual'], "Kuehne+Nagel should be REJECTED: " . json_encode($result['gates']));
        $this->assertTrue($result['correct']);
    }

    // ═══════════════════════════════════════════════════
    // Full YAML golden dataset regression
    // ═══════════════════════════════════════════════════

    public function testFullGoldenDatasetYAMLRegression(): void
    {
        $yamlPath = dirname(__DIR__) . '/config/golden_dataset_v1.yaml';

        if (!file_exists($yamlPath)) {
            $this->markTestSkipped('Golden dataset YAML not found at: ' . $yamlPath);
        }

        $this->runner->loadFromFile($yamlPath);
        $report = $this->runner->run();

        // Dump failures for debugging
        $failures = $report->getFailures();
        $failMsg = '';
        foreach ($failures as $f) {
            $failMsg .= sprintf(
                "\n  ✗ %s (%s): expected=%s actual=%s gate=%s\n    gates=%s",
                $f['name'],
                $f['domain'],
                $f['expected'],
                $f['actual'],
                $f['reject_gate'] ?? 'none',
                json_encode(array_map(fn($g) => [$g['passed'] ? 'PASS' : 'FAIL', $g['detail']], $f['gates'])),
            );
        }

        $this->assertTrue(
            $report->meetsThreshold(0.90),
            sprintf(
                "Golden dataset accuracy %.1f%% below 90%% threshold (%d/%d correct)%s",
                $report->getAccuracyPercent(),
                $report->getCorrect(),
                $report->getTotal(),
                $failMsg,
            ),
        );

        // Strict: all known-good should pass, all known-bad should be rejected
        $this->assertEmpty(
            $failures,
            sprintf(
                "%d/%d entries misclassified:%s\n\nFull summary:\n%s",
                count($failures),
                $report->getTotal(),
                $failMsg,
                $report->toSummaryString(),
            ),
        );
    }

    public function testGoldenDatasetHasMinimumEntries(): void
    {
        $yamlPath = dirname(__DIR__) . '/config/golden_dataset_v1.yaml';

        if (!file_exists($yamlPath)) {
            $this->markTestSkipped('Golden dataset YAML not found');
        }

        $this->runner->loadFromFile($yamlPath);
        $report = $this->runner->run();

        // Dataset should have a reasonable number of entries
        $this->assertGreaterThanOrEqual(15, $report->getTotal(), 'Golden dataset should have ≥15 entries');

        // Both classes should be represented
        $this->assertGreaterThanOrEqual(5, $report->getTruePositives() + $report->getFalseNegatives(), 'Need ≥5 expected-PASS entries');
        $this->assertGreaterThanOrEqual(5, $report->getTrueNegatives() + $report->getFalsePositives(), 'Need ≥5 expected-REJECT entries');
    }

    // ═══════════════════════════════════════════════════
    // Runner with no gates (null-safe)
    // ═══════════════════════════════════════════════════

    public function testRunnerWithNoGatesPassesEverything(): void
    {
        $emptyRunner = new GoldenDatasetRunner();

        $emptyRunner->loadFromArray([
            [
                'expected' => 'PASS',
                'name' => 'Test Corp',
                'domain' => 'test.com',
                'snippet' => 'We make things',
                'title' => 'Test Corp',
                'country' => 'DE',
                'sector' => 'Test',
                'category' => 'test',
            ],
        ]);

        $report = $emptyRunner->run();
        $this->assertEquals('PASS', $report->getResults()[0]['actual']);
    }
}
