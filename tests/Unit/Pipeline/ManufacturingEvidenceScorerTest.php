<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pipeline;

use App\Service\WebCrawler\Pipeline\CrawledDomain;
use App\Service\WebCrawler\Pipeline\CrawledPage;
use App\Service\WebCrawler\Pipeline\EvidenceScore;
use App\Service\WebCrawler\Pipeline\ManufacturingEvidenceScorer;
use App\Service\WebCrawler\Pipeline\PageClassification;
use PHPUnit\Framework\TestCase;

class ManufacturingEvidenceScorerTest extends TestCase
{
    private ManufacturingEvidenceScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new ManufacturingEvidenceScorer();
    }

    // ───────────────────── helpers ─────────────────────

    private function makeDomain(string $text): CrawledDomain
    {
        return new CrawledDomain('example.com', [
            new CrawledPage(
                'https://example.com',
                '<html><body>' . $text . '</body></html>',
                200,
                'homepage',
            ),
        ], 0.1);
    }

    private function makeClassification(string $category, float $confidence = 0.5): PageClassification
    {
        return new PageClassification($category, $confidence, [$category => 10]);
    }

    // ───────────────────── passing ─────────────────────

    /** @test */
    public function passesManufacturerWithStrongEvidence(): void
    {
        $domain = $this->makeDomain(
            'We are a manufacturer with a production facility featuring CNC machining, '
            . 'stamping, and forging capabilities. Our factory is ISO 9001 and IATF 16949 certified. '
            . 'We design and develop our own products. Founded in 1985, we have 500 employees worldwide.',
        );
        $classification = $this->makeClassification('manufacturer');

        $result = $this->scorer->score($domain, $classification);

        $this->assertTrue($result->isPassed());
        $this->assertFalse($result->isVetoed());
        $this->assertGreaterThan(20, $result->getTotalScore());
    }

    /** @test */
    public function certificationsBoostScore(): void
    {
        $withCerts = $this->makeDomain(
            'Manufacturing company with ISO 9001, IATF 16949, AS9100, and NADCAP certification.',
        );
        $withoutCerts = $this->makeDomain(
            'Manufacturing company with production capabilities.',
        );
        $classification = $this->makeClassification('manufacturer');

        $scoreWith = $this->scorer->score($withCerts, $classification);
        $scoreWithout = $this->scorer->score($withoutCerts, $classification);

        $this->assertGreaterThan($scoreWithout->getTotalScore(), $scoreWith->getTotalScore());
    }

    /** @test */
    public function multipleEvidenceFamiliesStack(): void
    {
        $domain = $this->makeDomain(
            'CNC machining and stamping production. We design our products. '
            . 'Our production facility has a cleanroom. ISO 9001 certified. Founded in 1990.',
        );
        $classification = $this->makeClassification('manufacturer');

        $result = $this->scorer->score($domain, $classification);
        $families = $result->getFamilyScores();

        // Multiple families should have non-zero scores
        $nonZeroFamilies = array_filter($families, fn(int $s) => $s > 0);
        $this->assertGreaterThanOrEqual(3, \count($nonZeroFamilies));
    }

    // ───────────────────── failing ─────────────────────

    /** @test */
    public function failsWithInsufficientEvidence(): void
    {
        $domain = $this->makeDomain('Welcome to our generic website. We sell things.');
        $classification = $this->makeClassification('manufacturer');

        $result = $this->scorer->score($domain, $classification);

        $this->assertFalse($result->isPassed());
    }

    /** @test */
    public function emptyDomainFails(): void
    {
        $domain = new CrawledDomain('ghost.com', [], 0.1);
        $classification = $this->makeClassification('unknown');

        $result = $this->scorer->score($domain, $classification);

        $this->assertFalse($result->isPassed());
        $this->assertSame(0, $result->getTotalScore());
    }

    // ───────────────────── hard vetoes ─────────────────────

    /**
     * @test
     * @dataProvider vetoedCategoryProvider
     */
    public function vetoesNonTargetCategories(string $category): void
    {
        $domain = $this->makeDomain(
            'Manufacturing company with CNC machining, ISO 9001, IATF 16949, and a factory.',
        );
        $classification = $this->makeClassification($category);

        $result = $this->scorer->score($domain, $classification);

        $this->assertTrue($result->isVetoed());
        $this->assertFalse($result->isPassed());
        $this->assertSame(0, $result->getTotalScore());
        $this->assertNotNull($result->getVetoReason());
    }

    public static function vetoedCategoryProvider(): array
    {
        return [
            'directory'      => ['directory'],
            'media'          => ['media'],
            'association'    => ['association'],
            'government'     => ['government'],
            'recruiter'      => ['recruiter'],
            'consultant'     => ['consultant'],
            'competitor_ems' => ['competitor_ems'],
        ];
    }

    /** @test */
    public function doesNotVetoManufacturer(): void
    {
        $domain = $this->makeDomain('CNC machining and stamping. ISO 9001 certified factory.');
        $classification = $this->makeClassification('manufacturer');

        $result = $this->scorer->score($domain, $classification);

        $this->assertFalse($result->isVetoed());
    }

    /** @test */
    public function doesNotVetoOemTier(): void
    {
        $domain = $this->makeDomain('CNC machining and stamping. ISO 9001 certified factory.');
        $classification = $this->makeClassification('oem_tier');

        $result = $this->scorer->score($domain, $classification);

        $this->assertFalse($result->isVetoed());
    }

    /** @test */
    public function doesNotVetoDistributor(): void
    {
        // Distributor is not vetoed — it just gets a low manufacturing score
        $domain = $this->makeDomain('Wholesale distributor of industrial parts. Inventory and logistics.');
        $classification = $this->makeClassification('distributor');

        $result = $this->scorer->score($domain, $classification);

        $this->assertFalse($result->isVetoed());
    }

    /** @test */
    public function doesNotVetoUnknown(): void
    {
        $domain = $this->makeDomain('Some random content.');
        $classification = $this->makeClassification('unknown');

        $result = $this->scorer->score($domain, $classification);

        $this->assertFalse($result->isVetoed());
    }

    // ───────────────────── family score breakdown ─────────────────────

    /** @test */
    public function familyBreakdownIncludesAllFamilies(): void
    {
        $domain = $this->makeDomain('Some content.');
        $classification = $this->makeClassification('manufacturer');

        $result = $this->scorer->score($domain, $classification);

        $families = $result->getFamilyScores();
        $this->assertArrayHasKey('manufacturing_process', $families);
        $this->assertArrayHasKey('product_evidence', $families);
        $this->assertArrayHasKey('facility_signals', $families);
        $this->assertArrayHasKey('certifications', $families);
        $this->assertArrayHasKey('org_footprint', $families);
    }

    /** @test */
    public function familyScoresAreCapped(): void
    {
        // Repeat manufacturing keywords many times
        $domain = $this->makeDomain(
            str_repeat('CNC machining stamping forging casting molding extrusion welding turning milling ', 20),
        );
        $classification = $this->makeClassification('manufacturer');

        $result = $this->scorer->score($domain, $classification);
        $families = $result->getFamilyScores();

        // Each family should be capped (not grow unboundedly)
        foreach ($families as $score) {
            $this->assertLessThanOrEqual(50, $score);
        }
    }

    // ───────────────────── configurable threshold ─────────────────────

    /** @test */
    public function customThresholdChangesPassResult(): void
    {
        $domain = $this->makeDomain('We have a factory with CNC machining.');
        $classification = $this->makeClassification('manufacturer');

        $defaultScorer = new ManufacturingEvidenceScorer();
        $strictScorer = new ManufacturingEvidenceScorer(passThreshold: 100);

        $defaultResult = $defaultScorer->score($domain, $classification);
        $strictResult = $strictScorer->score($domain, $classification);

        // Same score, different pass/fail
        $this->assertSame($defaultResult->getTotalScore(), $strictResult->getTotalScore());
        $this->assertFalse($strictResult->isPassed());
    }

    // ───────────────────── return type ─────────────────────

    /** @test */
    public function scoreReturnsEvidenceScore(): void
    {
        $domain = $this->makeDomain('Content.');
        $classification = $this->makeClassification('manufacturer');

        $result = $this->scorer->score($domain, $classification);

        $this->assertInstanceOf(EvidenceScore::class, $result);
    }
}
