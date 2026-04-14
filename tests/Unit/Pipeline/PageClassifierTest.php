<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pipeline;

use App\Service\WebCrawler\Pipeline\CrawledDomain;
use App\Service\WebCrawler\Pipeline\CrawledPage;
use App\Service\WebCrawler\Pipeline\PageClassification;
use App\Service\WebCrawler\Pipeline\PageClassifier;
use PHPUnit\Framework\TestCase;

class PageClassifierTest extends TestCase
{
    private PageClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new PageClassifier();
    }

    // ───────────────────── helpers ─────────────────────

    /**
     * Build a CrawledDomain from one or more text blocks and optional structured data.
     *
     * @param string|string[]                     $texts          Text body or bodies (one per page)
     * @param array<int, array<string, mixed>>     $structuredData JSON-LD items (merged into first page)
     * @param array<string, string>                $metaTags       Meta tags (merged into first page)
     */
    private function makeDomain(
        string|array $texts,
        array $structuredData = [],
        array $metaTags = [],
    ): CrawledDomain {
        if (\is_string($texts)) {
            $texts = [$texts];
        }

        $pages = [];
        foreach ($texts as $i => $text) {
            $pages[] = new CrawledPage(
                'https://example.com' . ($i === 0 ? '' : '/page' . $i),
                '<html><body>' . $text . '</body></html>',
                200,
                $i === 0 ? 'homepage' : 'about',
                $i === 0 ? $structuredData : [],
                $i === 0 ? $metaTags : [],
            );
        }

        return new CrawledDomain('example.com', $pages, 0.1);
    }

    // ───────────────────── manufacturer ─────────────────────

    /** @test */
    public function classifiesManufacturerWithStrongEvidence(): void
    {
        $domain = $this->makeDomain(
            'We are a leading manufacturer of precision stamped metal parts. '
            . 'Our factory features CNC machining centers, stamping presses, and injection molding equipment. '
            . 'We provide fabrication, heat treatment, and surface treatment services. '
            . 'Our production facility is ISO 9001 and IATF 16949 certified.',
        );

        $result = $this->classifier->classify($domain);

        $this->assertTrue($result->isManufacturer());
        $this->assertGreaterThan(0.3, $result->getConfidence());
    }

    /** @test */
    public function certificationsBoostManufacturerScore(): void
    {
        $withCerts = $this->makeDomain(
            'Manufacturing company. ISO 9001 certified. IATF 16949 certified. AS9100 certified. NADCAP approved.',
        );
        $withoutCerts = $this->makeDomain(
            'Manufacturing company. We produce parts.',
        );

        $resultWithCerts = $this->classifier->classify($withCerts);
        $resultWithoutCerts = $this->classifier->classify($withoutCerts);

        $this->assertTrue($resultWithCerts->isManufacturer());
        $this->assertGreaterThan(
            $resultWithoutCerts->getScores()['manufacturer'],
            $resultWithCerts->getScores()['manufacturer'],
        );
    }

    /** @test */
    public function schemaOrgManufacturerBoostsClassification(): void
    {
        $domain = $this->makeDomain(
            'We produce components.',
            [['@type' => 'Manufacturer', 'name' => 'Acme Parts']],
        );

        $result = $this->classifier->classify($domain);

        $this->assertTrue($result->isManufacturer());
    }

    // ───────────────────── oem_tier ─────────────────────

    /** @test */
    public function classifiesOemTierSupplier(): void
    {
        $domain = $this->makeDomain(
            'We are a tier 1 supplier to the automotive industry. '
            . 'As an OEM supplier and system integrator, we deliver original equipment to major car manufacturers.',
        );

        $result = $this->classifier->classify($domain);

        $this->assertTrue($result->isOemTier());
    }

    // ───────────────────── distributor ─────────────────────

    /** @test */
    public function classifiesDistributor(): void
    {
        $domain = $this->makeDomain(
            'As an authorized distributor, we provide wholesale distribution of industrial components. '
            . 'We are a leading reseller and stockist with over 10,000 products in inventory. '
            . 'Fast delivery from our distribution warehouse network.',
        );

        $result = $this->classifier->classify($domain);

        $this->assertTrue($result->isDistributor());
        $this->assertFalse($result->isTargetType());
    }

    // ───────────────────── directory ─────────────────────

    /** @test */
    public function classifiesDirectory(): void
    {
        $domain = $this->makeDomain(
            'Business directory - find companies in your area. '
            . 'Search our company database of 50,000+ company listings and company profiles. '
            . 'The industry directory for finding suppliers.',
        );

        $result = $this->classifier->classify($domain);

        $this->assertSame('directory', $result->getCategory());
    }

    // ───────────────────── media ─────────────────────

    /** @test */
    public function classifiesMedia(): void
    {
        $domain = $this->makeDomain(
            'Industry News & Analysis. Subscribe to our newsletter for the latest press releases. '
            . 'Our editorial team of journalists and editors delivers daily news articles and publications.',
        );

        $result = $this->classifier->classify($domain);

        $this->assertSame('media', $result->getCategory());
    }

    // ───────────────────── association ─────────────────────

    /** @test */
    public function classifiesAssociation(): void
    {
        $domain = $this->makeDomain(
            'The European Manufacturing Industry Association. '
            . 'Join our membership network of 500+ member companies. '
            . 'Annual conference and trade show for the industry.',
        );

        $result = $this->classifier->classify($domain);

        $this->assertSame('association', $result->getCategory());
    }

    // ───────────────────── recruiter ─────────────────────

    /** @test */
    public function classifiesRecruiter(): void
    {
        $domain = $this->makeDomain(
            'Leading recruitment agency specializing in manufacturing talent acquisition. '
            . 'Our staffing agency provides headhunting and job placement services. '
            . 'Employment agency for engineering roles.',
        );

        $result = $this->classifier->classify($domain);

        $this->assertSame('recruiter', $result->getCategory());
    }

    // ───────────────────── consultant ─────────────────────

    /** @test */
    public function classifiesConsultant(): void
    {
        $domain = $this->makeDomain(
            'Management consulting firm specializing in manufacturing strategy. '
            . 'Our consultancy provides advisory services and professional services '
            . 'to help optimize your operations.',
        );

        $result = $this->classifier->classify($domain);

        $this->assertSame('consultant', $result->getCategory());
    }

    // ───────────────────── government ─────────────────────

    /** @test */
    public function classifiesGovernment(): void
    {
        $domain = $this->makeDomain(
            'Ministry of Industry and Trade. Government agency for public administration. '
            . 'Department of Economic Development. Public sector policy and regulation.',
        );

        $result = $this->classifier->classify($domain);

        $this->assertSame('government', $result->getCategory());
    }

    // ───────────────────── competitor_ems ─────────────────────

    /** @test */
    public function classifiesCompetitorEms(): void
    {
        $domain = $this->makeDomain(
            'Full-service electronics manufacturing services provider. '
            . 'Our EMS capabilities include PCB assembly, SMT assembly, and box build. '
            . 'Contract electronics manufacturer offering prototype to production services.',
        );

        $result = $this->classifier->classify($domain);

        $this->assertSame('competitor_ems', $result->getCategory());
    }

    // ───────────────────── unknown / edge cases ─────────────────────

    /** @test */
    public function returnsUnknownForEmptyDomain(): void
    {
        $domain = new CrawledDomain('ghost.com', [], 0.1);

        $result = $this->classifier->classify($domain);

        $this->assertSame('unknown', $result->getCategory());
        $this->assertSame(0.0, $result->getConfidence());
    }

    /** @test */
    public function returnsUnknownForMinimalContent(): void
    {
        $domain = $this->makeDomain('Welcome to our website.');

        $result = $this->classifier->classify($domain);

        $this->assertSame('unknown', $result->getCategory());
    }

    /** @test */
    public function confidenceHigherWithClearUnambiguousSignals(): void
    {
        $clear = $this->makeDomain(
            'We are a manufacturer. Our factory has CNC machining, stamping, and forging. '
            . 'ISO 9001 and IATF 16949 certified production facility. We manufacture precision parts.',
        );

        $ambiguous = $this->makeDomain(
            'We are a manufacturer but also distribute products wholesale as a dealer. '
            . 'News about our consulting services.',
        );

        $clearResult = $this->classifier->classify($clear);
        $ambiguousResult = $this->classifier->classify($ambiguous);

        $this->assertGreaterThan($ambiguousResult->getConfidence(), $clearResult->getConfidence());
    }

    // ───────────────────── multi-page aggregation ─────────────────────

    /** @test */
    public function multiplePagesCombineEvidence(): void
    {
        $domain = $this->makeDomain([
            'Welcome to our company.',
            'Our production facility features CNC machining and stamping. '
            . 'We are a manufacturer of precision metal parts with ISO 9001 certification.',
        ]);

        $result = $this->classifier->classify($domain);

        $this->assertTrue($result->isManufacturer());
    }

    // ───────────────────── target type helper ─────────────────────

    /** @test */
    public function isTargetTypeReturnsTrueForManufacturer(): void
    {
        $domain = $this->makeDomain(
            'We are a manufacturer with a factory and CNC machining. ISO 9001 certified production facility.',
        );

        $result = $this->classifier->classify($domain);

        $this->assertTrue($result->isTargetType());
    }

    /** @test */
    public function isTargetTypeReturnsTrueForOemTier(): void
    {
        $domain = $this->makeDomain(
            'Tier 1 supplier to the automotive industry. OEM supplier and system integrator. '
            . 'Original equipment manufacturer for major automotive OEMs.',
        );

        $result = $this->classifier->classify($domain);

        $this->assertTrue($result->isTargetType());
    }

    /** @test */
    public function isTargetTypeReturnsFalseForNonTarget(): void
    {
        $domain = $this->makeDomain(
            'Business directory - find companies. Company listings and company profiles. '
            . 'Search our company database and industry directory.',
        );

        $result = $this->classifier->classify($domain);

        $this->assertFalse($result->isTargetType());
    }

    // ───────────────────── return type ─────────────────────

    /** @test */
    public function classifyReturnsPageClassification(): void
    {
        $domain = $this->makeDomain('Some content.');

        $result = $this->classifier->classify($domain);

        $this->assertInstanceOf(PageClassification::class, $result);
        $this->assertIsString($result->getCategory());
        $this->assertIsFloat($result->getConfidence());
        $this->assertIsArray($result->getScores());
    }
}
