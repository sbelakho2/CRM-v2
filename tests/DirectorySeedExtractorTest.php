<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\WebCrawler\Seed\DirectorySeed;
use App\Service\WebCrawler\Seed\DirectorySeedExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class DirectorySeedExtractorTest extends TestCase
{
    private DirectorySeedExtractor $extractor;

    protected function setUp(): void
    {
        $httpClient = new MockHttpClient();
        $this->extractor = new DirectorySeedExtractor($httpClient);
    }

    // ──────────────────────────────────────────────────────────
    // Directory detection
    // ──────────────────────────────────────────────────────────

    public function testKnownDirectoriesDetected(): void
    {
        $this->assertTrue($this->extractor->isDirectoryDomain('www.kompass.com'));
        $this->assertTrue($this->extractor->isDirectoryDomain('europages.de'));
        $this->assertTrue($this->extractor->isDirectoryDomain('thomasnet.com'));
        $this->assertTrue($this->extractor->isDirectoryDomain('wlw.de'));
        $this->assertTrue($this->extractor->isDirectoryDomain('www.alibaba.com'));
        $this->assertTrue($this->extractor->isDirectoryDomain('kerix.net'));
        $this->assertTrue($this->extractor->isDirectoryDomain('www.crunchbase.com'));
        $this->assertTrue($this->extractor->isDirectoryDomain('www.zoominfo.com'));
    }

    public function testRegularDomainsNotDirectories(): void
    {
        $this->assertFalse($this->extractor->isDirectoryDomain('bosch.com'));
        $this->assertFalse($this->extractor->isDirectoryDomain('siemens.de'));
        $this->assertFalse($this->extractor->isDirectoryDomain('thalesgroup.com'));
        $this->assertFalse($this->extractor->isDirectoryDomain('sensortech.com'));
    }

    public function testDirectoryNameResolution(): void
    {
        $this->assertSame('Kompass', $this->extractor->getDirectoryName('www.kompass.com'));
        $this->assertSame('Europages DE', $this->extractor->getDirectoryName('www.europages.de'));
        $this->assertSame('ThomasNet', $this->extractor->getDirectoryName('thomasnet.com'));
        $this->assertNull($this->extractor->getDirectoryName('bosch.com'));
    }

    // ──────────────────────────────────────────────────────────
    // Snippet-based seed extraction
    // ──────────────────────────────────────────────────────────

    public function testExtractSeedFromTitlePipeSeparated(): void
    {
        $seeds = $this->extractor->extractSeedsFromSnippet(
            'Leading manufacturer of sensors and control systems in Germany.',
            'SensorTech GmbH | Europages',
            'https://www.europages.com/company/sensortech-gmbh',
            'europages.com',
            'DE',
            'Automotive',
        );
        $this->assertNotEmpty($seeds);
        $this->assertStringContainsString('sensortech', strtolower($seeds[0]->companyName));
        $this->assertSame('Europages', $seeds[0]->sourceDirectory);
        $this->assertSame('DE', $seeds[0]->country);
    }

    public function testExtractSeedFromTitleDashSeparated(): void
    {
        $seeds = $this->extractor->extractSeedsFromSnippet(
            'Electronic components manufacturing.',
            'Alpine Electronics Co - Kompass',
            'https://www.kompass.com/company/alpine-electronics',
            'kompass.com',
            'FR',
        );
        $this->assertNotEmpty($seeds);
        $this->assertSame('Kompass', $seeds[0]->sourceDirectory);
    }

    public function testEmptySnippetNoSeeds(): void
    {
        $seeds = $this->extractor->extractSeedsFromSnippet(
            '',
            '',
            'https://www.kompass.com/search',
            'kompass.com',
        );
        $this->assertEmpty($seeds);
    }

    // ──────────────────────────────────────────────────────────
    // Value object
    // ──────────────────────────────────────────────────────────

    public function testDirectorySeedToArray(): void
    {
        $seed = new DirectorySeed(
            companyName: 'Bosch GmbH',
            domain: 'bosch.com',
            country: 'DE',
            sourceDirectory: 'Kompass',
            sourceUrl: 'https://kompass.com/company/bosch',
            sector: 'Automotive',
            snippet: 'Global technology company.',
        );

        $arr = $seed->toArray();
        $this->assertSame('Bosch GmbH', $arr['company_name']);
        $this->assertSame('bosch.com', $arr['domain']);
        $this->assertSame('DE', $arr['country']);
        $this->assertSame('Kompass', $arr['source_directory']);
        $this->assertSame('Automotive', $arr['sector']);
    }

    // ──────────────────────────────────────────────────────────
    // HTML-based extraction (mock)
    // ──────────────────────────────────────────────────────────

    public function testExtractSeedsFromHtmlWithJsonLd(): void
    {
        $html = <<<'HTML'
        <html><body>
        <script type="application/ld+json">
        {"@type":"Organization","name":"Precision Motors GmbH","url":"https://precision-motors.de"}
        </script>
        </body></html>
        HTML;

        $mockResponse = new MockResponse($html, ['http_code' => 200]);
        $httpClient = new MockHttpClient([$mockResponse]);
        $extractor = new DirectorySeedExtractor($httpClient);

        $seeds = $extractor->extractSeedsFromPage(
            'https://www.europages.com/company/precision-motors',
            'europages.com',
            'DE',
            'Automotive',
        );

        $this->assertNotEmpty($seeds);
        $this->assertStringContainsString('precision motors', strtolower($seeds[0]->companyName));
        $this->assertSame('precision-motors.de', $seeds[0]->domain);
    }

    public function testNonDirectoryDomainReturnsEmpty(): void
    {
        $httpClient = new MockHttpClient();
        $extractor = new DirectorySeedExtractor($httpClient);

        $seeds = $extractor->extractSeedsFromPage(
            'https://bosch.com/about',
            'bosch.com',
        );
        $this->assertEmpty($seeds);
    }
}
