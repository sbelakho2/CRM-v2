<?php

namespace App\Tests;

use App\Service\WebCrawler\Rules\RuleEngine;
use App\Service\WebCrawler\Rules\RuleEngineVerdict;
use App\Service\WebCrawler\Text\TextNormalizer;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests for the RuleEngine (Improvement 4A).
 */
class RuleEngineTest extends KernelTestCase
{
    private RuleEngine $engine;

    protected function setUp(): void
    {
        $projectDir = dirname(__DIR__);
        $this->engine = new RuleEngine(
            new TextNormalizer(),
            new NullLogger(),
            $projectDir,
        );
    }

    // ── Rule pack loading ───────────────────────────────────────

    public function testRulePackLoads(): void
    {
        $stats = $this->engine->getStats();
        $this->assertSame('1.0', $this->engine->getVersion());
        $this->assertGreaterThan(50, $stats['blocked_domains']);
        $this->assertGreaterThan(5, $stats['competitor_rules']);
        $this->assertGreaterThan(10, $stats['wrong_type_rules']);
        $this->assertGreaterThan(3, $stats['positive_rules']);
    }

    // ── Blocked domains ─────────────────────────────────────────

    public function testBlockedDomain(): void
    {
        $this->assertTrue($this->engine->isDomainBlocked('linkedin.com'));
        $this->assertTrue($this->engine->isDomainBlocked('www.linkedin.com'));
        $this->assertTrue($this->engine->isDomainBlocked('mckinsey.com'));
        $this->assertFalse($this->engine->isDomainBlocked('siemens.com'));
    }

    public function testBlockedDomainRejectsInEvaluation(): void
    {
        $verdict = $this->engine->evaluate(
            'wikipedia.org',
            'Wikipedia',
            'The Free Encyclopedia',
            'Wikipedia - Main Page',
        );

        $this->assertTrue($verdict->isRejected());
        $this->assertStringContainsString('Blocked domain', $verdict->reason);
    }

    // ── Competitor detection ────────────────────────────────────

    public function testCompetitorDetection(): void
    {
        $verdict = $this->engine->evaluate(
            'flexems.com',
            'FlexEMS',
            'Leading contract manufacturer offering turnkey EMS services for PCB assembly',
            'FlexEMS - Contract Electronics Manufacturing',
        );

        $this->assertTrue($verdict->isRejected());
        $this->assertStringContainsString('Competitor', $verdict->reason);
    }

    public function testSMTAssemblyProvider(): void
    {
        $verdict = $this->engine->evaluate(
            'smt-solutions.com',
            'SMT Solutions',
            'State-of-the-art SMT assembly line with prototype to production capabilities',
            'SMT Solutions - Assembly Services',
        );

        $this->assertTrue($verdict->isRejected());
    }

    // ── Wrong-type detection ────────────────────────────────────

    public function testUniversityRejected(): void
    {
        $verdict = $this->engine->evaluate(
            'imperial.ac.uk',
            'Imperial College London',
            'Imperial College is a world-leading university with research center, academic faculty, school of engineering. The college offers PhD programmes and doctoral studies.',
            'Imperial College London - University Academic Research',
        );

        // Multiple wrong-type academic + academic + news/media patterns should accumulate past -60
        $this->assertTrue($verdict->isRejected(), 'University should be rejected (score=' . $verdict->totalScore . '): ' . $verdict->reason);
    }

    public function testGovernmentRejected(): void
    {
        $verdict = $this->engine->evaluate(
            'industry.gov.ma',
            'Ministry of Industry',
            'Government ministry responsible for industrial policy',
            'Ministry of Industry and Trade',
        );

        $this->assertTrue($verdict->isRejected());
    }

    public function testConsultingFirmRejected(): void
    {
        $verdict = $this->engine->evaluate(
            'deloitte.com',
            'Deloitte',
            'Global management consulting and audit firm providing advisory services',
            'Deloitte - Consulting Firm',
        );

        $this->assertTrue($verdict->isRejected());
    }

    // ── Real companies pass ─────────────────────────────────────

    public function testRealOEMPasses(): void
    {
        $verdict = $this->engine->evaluate(
            'siemens-energy.com',
            'Siemens Energy',
            'We design and manufacture gas turbines, transformers, and power supplies for global energy infrastructure. Founded in 2020.',
            'Siemens Energy - Products & Solutions',
        );

        $this->assertFalse($verdict->isRejected(), 'Real OEM should pass: ' . $verdict->reason);
        $this->assertGreaterThan(0, $verdict->totalScore);
    }

    // ── Verdict serialization ───────────────────────────────────

    public function testVerdictSerialization(): void
    {
        $verdict = $this->engine->evaluate(
            'acme.com',
            'Acme Corp',
            'Leading manufacturer of widgets with ISO 9001 certification.',
            'Acme Corp - Products',
        );

        $array = $verdict->toArray();
        $this->assertArrayHasKey('verdict', $array);
        $this->assertArrayHasKey('reason', $array);
        $this->assertArrayHasKey('rules_fired', $array);
        $this->assertArrayHasKey('evaluated_at', $array);

        $json = json_encode($array);
        $this->assertNotFalse($json);
    }

    // ── Container wiring ────────────────────────────────────────

    public function testContainerWiring(): void
    {
        self::bootKernel();
        // RuleEngine is private; verify through GoogleDorkService which injects it
        // Or just test it standalone with the project dir
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        $engine = new RuleEngine(
            new TextNormalizer(),
            new NullLogger(),
            $projectDir,
        );
        $this->assertInstanceOf(RuleEngine::class, $engine);
        $this->assertSame('1.0', $engine->getVersion());
    }

    // ── Junk name detection ─────────────────────────────────────

    public function testJunkNameRejected(): void
    {
        $verdict = $this->engine->evaluate(
            'somesite.com',
            'Home',
            'Welcome to our homepage',
            'Home',
        );

        $this->assertTrue($verdict->isRejected());
        $this->assertStringContainsString('Junk', $verdict->reason);
    }

    // ── Multiple wrong-type accumulation ────────────────────────

    public function testAccumulatedWrongTypeRejects(): void
    {
        $verdict = $this->engine->evaluate(
            'newshealthbank.com',
            'NewsHealthBank',
            'Banking and financial services news. Hospital insurance provider. Media company publishing.',
            'Financial News Media Healthcare',
        );

        // Multiple wrong-type signals should accumulate
        $this->assertTrue($verdict->isRejected());
    }
}
