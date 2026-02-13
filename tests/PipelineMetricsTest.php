<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\WebCrawler\Observability\PipelineMetricsCollector;
use PHPUnit\Framework\TestCase;

class PipelineMetricsTest extends TestCase
{
    private PipelineMetricsCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new PipelineMetricsCollector();
    }

    public function testStartRunGeneratesId(): void
    {
        $this->collector->startRun();
        $this->assertNotEmpty($this->collector->getRunId());
    }

    public function testCustomRunId(): void
    {
        $this->collector->startRun('test-run-42');
        $this->assertEquals('test-run-42', $this->collector->getRunId());
    }

    public function testRecordCandidates(): void
    {
        $this->collector->startRun('r1');
        $this->collector->recordCandidates(50);
        $this->assertEquals(50, $this->collector->getTotalCandidates());

        $this->collector->recordCandidates(10);
        $this->assertEquals(60, $this->collector->getTotalCandidates());
    }

    public function testAcceptRejectCounting(): void
    {
        $this->collector->startRun('r2');
        $this->collector->recordAccept('buyer_evidence');
        $this->collector->recordAccept('buyer_evidence');
        $this->collector->recordReject('buyer_evidence', 'too_few_families');

        $this->assertEquals(2, $this->collector->getAccepted('buyer_evidence'));
        $this->assertEquals(1, $this->collector->getRejected('buyer_evidence'));
    }

    public function testRejectReasons(): void
    {
        $this->collector->startRun('r3');
        $this->collector->recordReject('service_product', 'service_company');
        $this->collector->recordReject('service_product', 'service_company');
        $this->collector->recordReject('service_product', 'consulting');

        $reasons = $this->collector->getRejectReasons('service_product');
        $this->assertEquals(2, $reasons['service_company']);
        $this->assertEquals(1, $reasons['consulting']);
    }

    public function testPassRate(): void
    {
        $this->collector->startRun('r4');
        $this->collector->recordCandidates(100);
        $this->collector->recordOutput(25);

        $this->assertEquals(0.25, $this->collector->getPassRate());
    }

    public function testPassRateZeroCandidates(): void
    {
        $this->collector->startRun('r5');
        $this->assertEquals(0.0, $this->collector->getPassRate());
    }

    public function testTimingAccumulates(): void
    {
        $this->collector->startRun('r6');

        $this->collector->startTimer('buyer_evidence');
        usleep(5000); // 5ms
        $this->collector->stopTimer('buyer_evidence');

        $this->collector->startTimer('buyer_evidence');
        usleep(5000); // another 5ms
        $this->collector->stopTimer('buyer_evidence');

        $time = $this->collector->getStageTiming('buyer_evidence');
        $this->assertGreaterThan(0.008, $time); // at least ~8ms combined
    }

    public function testStageSummary(): void
    {
        $this->collector->startRun('r7');
        $this->collector->recordAccept('buyer_evidence');
        $this->collector->recordAccept('buyer_evidence');
        $this->collector->recordReject('buyer_evidence', 'too_few');
        $this->collector->recordAccept('rule_engine');

        $summary = $this->collector->getStageSummary();

        $this->assertArrayHasKey('buyer_evidence', $summary);
        $this->assertEquals(2, $summary['buyer_evidence']['accepted']);
        $this->assertEquals(1, $summary['buyer_evidence']['rejected']);
        $this->assertArrayHasKey('rule_engine', $summary);

        // Stages with no activity should be excluded
        $this->assertArrayNotHasKey('domain_block', $summary);
    }

    public function testSnapshot(): void
    {
        $this->collector->startRun('snap-1');
        $this->collector->recordCandidates(10);
        $this->collector->recordAccept('buyer_evidence');
        $this->collector->recordReject('buyer_evidence', 'no_evidence');
        $this->collector->recordOutput(5);

        $snap = $this->collector->snapshot();

        $this->assertEquals('snap-1', $snap['run_id']);
        $this->assertEquals(10, $snap['total_candidates']);
        $this->assertEquals(5, $snap['final_output']);
        $this->assertEquals(0.5, $snap['pass_rate']);
        $this->assertArrayHasKey('stages', $snap);
        $this->assertArrayHasKey('reject_reasons', $snap);
    }

    public function testToSummaryString(): void
    {
        $this->collector->startRun('str-1');
        $this->collector->recordCandidates(20);
        $this->collector->recordAccept('buyer_evidence');
        $this->collector->recordReject('buyer_evidence', 'no_evidence');
        $this->collector->recordOutput(10);

        $text = $this->collector->toSummaryString();

        $this->assertStringContainsString('str-1', $text);
        $this->assertStringContainsString('Candidates: 20', $text);
        $this->assertStringContainsString('Output: 10', $text);
        $this->assertStringContainsString('50.0%', $text);
        $this->assertStringContainsString('buyer_evidence', $text);
    }

    public function testReset(): void
    {
        $this->collector->startRun('reset-test');
        $this->collector->recordCandidates(50);
        $this->collector->recordAccept('buyer_evidence');

        $this->collector->reset();

        $this->assertEquals('', $this->collector->getRunId());
        $this->assertEquals(0, $this->collector->getTotalCandidates());
        $this->assertEquals(0, $this->collector->getAccepted('buyer_evidence'));
    }

    public function testStartRunResetsState(): void
    {
        $this->collector->startRun('run-1');
        $this->collector->recordCandidates(50);

        $this->collector->startRun('run-2');
        $this->assertEquals('run-2', $this->collector->getRunId());
        $this->assertEquals(0, $this->collector->getTotalCandidates());
    }

    public function testRecordWarning(): void
    {
        $this->collector->startRun('warn-1');
        $this->collector->recordWarning('rate limited', ['domain' => 'x.com']);

        $snap = $this->collector->snapshot();
        $this->assertCount(1, $snap['warnings']);
        $this->assertEquals('rate limited', $snap['warnings'][0]['message']);
    }

    public function testEndRunSetssDuration(): void
    {
        $this->collector->startRun('dur-1');
        usleep(5000);
        $this->collector->endRun();

        $snap = $this->collector->snapshot();
        $this->assertGreaterThan(0.004, $snap['duration_sec']);
    }

    public function testUnknownGateReturnsZero(): void
    {
        $this->collector->startRun('unk-1');
        $this->assertEquals(0, $this->collector->getAccepted('nonexistent'));
        $this->assertEquals(0, $this->collector->getRejected('nonexistent'));
        $this->assertEquals(0.0, $this->collector->getStageTiming('nonexistent'));
        $this->assertEquals([], $this->collector->getRejectReasons('nonexistent'));
    }
}
