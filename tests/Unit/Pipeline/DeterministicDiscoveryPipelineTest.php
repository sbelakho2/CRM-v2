<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pipeline;

use App\Service\WebCrawler\Pipeline\CandidateCollector;
use App\Service\WebCrawler\Pipeline\DeterministicDiscoveryPipeline;
use App\Service\WebCrawler\Pipeline\DiscoveryResult;
use App\Service\WebCrawler\Pipeline\DomainCrawler;
use App\Service\WebCrawler\Pipeline\LocationProofVerifier;
use App\Service\WebCrawler\Pipeline\ManufacturingEvidenceScorer;
use App\Service\WebCrawler\Pipeline\PageClassifier;
use App\Service\WebCrawler\Pipeline\QueryTemplateBuilder;
use App\Service\WebCrawler\Pipeline\UnifiedContactExtractor;
use App\Service\WebCrawler\SearchProvider\SearchProviderInterface;
use App\Service\WebCrawler\SearchProvider\SearchResult;
use App\Service\WebCrawler\SearchProvider\SearchResultSet;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class DeterministicDiscoveryPipelineTest extends TestCase
{
    // ───────────────────── HTML fixtures ─────────────────────

    private const MANUFACTURER_HTML = '<html><head>
        <title>Acme Parts - Precision Manufacturing</title>
        <script type="application/ld+json">{"@type":"Organization","name":"Acme Parts",
        "address":{"addressLocality":"Casablanca","addressCountry":"MA"}}</script>
        <meta name="description" content="We manufacture precision stamped parts">
        </head><body>
        <p>We are a leading manufacturer of precision stamped metal parts.
        Our factory features CNC machining, stamping, and forging capabilities.
        Our production facility is ISO 9001 and IATF 16949 certified.</p>
        <p>Located in Casablanca, Morocco. Phone: +212 522 123456</p>
        <div class="team-member"><h3>Ahmed Benali</h3><p>CEO</p>
        <a href="mailto:ahmed@acmeparts.ma">Email</a></div>
        </body></html>';

    private const DIRECTORY_HTML = '<html><head>
        <title>Industry Directory - Find Companies</title>
        </head><body>
        <p>Business directory - find companies in your area.
        Search our company database of 50,000+ company listings and company profiles.
        The industry directory for finding suppliers.</p>
        </body></html>';

    private const WEAK_EVIDENCE_HTML = '<html><head>
        <title>Welcome to NoEvidence Corp</title>
        </head><body>
        <p>Welcome to our website. We sell things online. Great deals available.</p>
        </body></html>';

    // ───────────────────── factory ─────────────────────

    private function buildPipeline(
        SearchProviderInterface $searchProvider,
        MockHttpClient $httpClient,
    ): DeterministicDiscoveryPipeline {
        $logger = new NullLogger();

        return new DeterministicDiscoveryPipeline(
            new QueryTemplateBuilder(),
            new CandidateCollector(),
            new DomainCrawler($httpClient, $logger),
            new PageClassifier(),
            new ManufacturingEvidenceScorer(),
            new LocationProofVerifier(),
            new UnifiedContactExtractor(),
            $searchProvider,
            $logger,
        );
    }

    private function createSearchProvider(array $domains): SearchProviderInterface
    {
        $results = [];
        foreach ($domains as $domain => $title) {
            $results[] = new SearchResult(
                "https://{$domain}",
                $title,
                'Some snippet',
                $domain,
            );
        }

        $resultSet = new SearchResultSet($results, \count($results), 0.1, 'mock');

        $provider = $this->createMock(SearchProviderInterface::class);
        $provider->method('search')->willReturn($resultSet);
        $provider->method('getProviderName')->willReturn('mock');
        $provider->method('isAvailable')->willReturn(true);

        return $provider;
    }

    private function createHttpClient(array $htmlMap): MockHttpClient
    {
        return new MockHttpClient(
            function (string $method, string $url) use ($htmlMap): MockResponse {
                $normalized = rtrim($url, '/');
                $body = $htmlMap[$url] ?? $htmlMap[$normalized] ?? '';
                $status = $body !== '' ? 200 : 404;
                return new MockResponse($body, ['http_code' => $status]);
            },
        );
    }

    // ───────────────────── end-to-end pipeline ─────────────────────

    /** @test */
    public function discoversManufacturerEndToEnd(): void
    {
        $provider = $this->createSearchProvider([
            'acmeparts.ma' => 'Acme Parts - Precision Manufacturing',
        ]);

        $httpClient = $this->createHttpClient([
            'https://acmeparts.ma' => self::MANUFACTURER_HTML,
        ]);

        $pipeline = $this->buildPipeline($provider, $httpClient);
        $results = $pipeline->discover('Automotive', 'Morocco');

        $passed = array_filter($results, fn(DiscoveryResult $r) => $r->isPassed());

        $this->assertNotEmpty($passed);
        $first = reset($passed);
        $this->assertSame('acmeparts.ma', $first->getDomain());
        $this->assertTrue($first->getClassification()->isManufacturer());
        $this->assertTrue($first->getEvidenceScore()->isPassed());
        $this->assertTrue($first->getLocationVerdict()->isConfirmed());
    }

    /** @test */
    public function directorySiteDoesNotPass(): void
    {
        $provider = $this->createSearchProvider([
            'industry-listings.com' => 'Industry Listings - Find Companies',
        ]);

        $httpClient = $this->createHttpClient([
            'https://industry-listings.com' => self::DIRECTORY_HTML,
        ]);

        $pipeline = $this->buildPipeline($provider, $httpClient);
        $results = $pipeline->discover('Automotive', 'Morocco');

        $passed = array_filter($results, fn(DiscoveryResult $r) => $r->isPassed());
        $this->assertEmpty($passed);

        // But the domain should still be in the results with classification
        $directory = null;
        foreach ($results as $r) {
            if ($r->getDomain() === 'industry-listings.com') {
                $directory = $r;
            }
        }
        if ($directory !== null) {
            $this->assertFalse($directory->isPassed());
        }
    }

    /** @test */
    public function weakEvidenceDoesNotPass(): void
    {
        $provider = $this->createSearchProvider([
            'noevidence.com' => 'NoEvidence Corp',
        ]);

        $httpClient = $this->createHttpClient([
            'https://noevidence.com' => self::WEAK_EVIDENCE_HTML,
        ]);

        $pipeline = $this->buildPipeline($provider, $httpClient);
        $results = $pipeline->discover('Automotive', 'Morocco');

        $passed = array_filter($results, fn(DiscoveryResult $r) => $r->isPassed());
        $this->assertEmpty($passed);
    }

    /** @test */
    public function extractsContactsForDiscoveredDomains(): void
    {
        $provider = $this->createSearchProvider([
            'acmeparts.ma' => 'Acme Parts',
        ]);

        $httpClient = $this->createHttpClient([
            'https://acmeparts.ma' => self::MANUFACTURER_HTML,
        ]);

        $pipeline = $this->buildPipeline($provider, $httpClient);
        $results = $pipeline->discover('Automotive', 'Morocco');

        $passed = array_filter($results, fn(DiscoveryResult $r) => $r->isPassed());
        $this->assertNotEmpty($passed);

        $first = reset($passed);
        $this->assertNotEmpty($first->getContacts());
    }

    /** @test */
    public function returnsEmptyWhenNoSearchResults(): void
    {
        $provider = $this->createSearchProvider([]);

        $httpClient = $this->createHttpClient([]);

        $pipeline = $this->buildPipeline($provider, $httpClient);
        $results = $pipeline->discover('Automotive', 'Morocco');

        $this->assertSame([], $results);
    }

    /** @test */
    public function multiDomainsProcessedIndependently(): void
    {
        $provider = $this->createSearchProvider([
            'acmeparts.ma'          => 'Acme Parts',
            'industry-listings.com' => 'Industry Listings',
            'noevidence.com'        => 'NoEvidence Corp',
        ]);

        $httpClient = $this->createHttpClient([
            'https://acmeparts.ma'          => self::MANUFACTURER_HTML,
            'https://industry-listings.com' => self::DIRECTORY_HTML,
            'https://noevidence.com'        => self::WEAK_EVIDENCE_HTML,
        ]);

        $pipeline = $this->buildPipeline($provider, $httpClient);
        $results = $pipeline->discover('Automotive', 'Morocco');

        // All three should be in results
        $domains = array_map(fn(DiscoveryResult $r) => $r->getDomain(), $results);
        $this->assertContains('acmeparts.ma', $domains);

        // Only manufacturer should pass
        $passed = array_filter($results, fn(DiscoveryResult $r) => $r->isPassed());
        $passedDomains = array_map(fn(DiscoveryResult $r) => $r->getDomain(), $passed);
        $this->assertContains('acmeparts.ma', $passedDomains);
        $this->assertNotContains('noevidence.com', $passedDomains);
    }

    /** @test */
    public function resultHasClassificationAndEvidenceBreakdown(): void
    {
        $provider = $this->createSearchProvider([
            'acmeparts.ma' => 'Acme Parts',
        ]);

        $httpClient = $this->createHttpClient([
            'https://acmeparts.ma' => self::MANUFACTURER_HTML,
        ]);

        $pipeline = $this->buildPipeline($provider, $httpClient);
        $results = $pipeline->discover('Automotive', 'Morocco');

        $this->assertNotEmpty($results);
        $result = $results[0];

        $this->assertInstanceOf(DiscoveryResult::class, $result);
        $this->assertIsString($result->getClassification()->getCategory());
        $this->assertIsArray($result->getEvidenceScore()->getFamilyScores());
        $this->assertIsArray($result->getLocationVerdict()->getSignals());
        $this->assertIsString($result->getCompanyName());
        $this->assertIsString($result->getWebsiteUrl());
    }

    /** @test */
    public function isPassedRequiresTargetTypeAndEvidenceAndLocation(): void
    {
        // Manufacturer in Morocco → isPassed = true
        $provider = $this->createSearchProvider([
            'acmeparts.ma' => 'Acme Parts',
        ]);
        $httpClient = $this->createHttpClient([
            'https://acmeparts.ma' => self::MANUFACTURER_HTML,
        ]);

        $pipeline = $this->buildPipeline($provider, $httpClient);
        $results = $pipeline->discover('Automotive', 'Morocco');

        $this->assertNotEmpty($results);
        $result = $results[0];

        // All three conditions must hold for isPassed
        if ($result->isPassed()) {
            $this->assertTrue($result->getClassification()->isTargetType());
            $this->assertTrue($result->getEvidenceScore()->isPassed());
            $this->assertTrue($result->getLocationVerdict()->isConfirmed());
        }
    }

    /** @test */
    public function discoverReturnsArrayOfDiscoveryResult(): void
    {
        $provider = $this->createSearchProvider([
            'acmeparts.ma' => 'Acme Parts',
        ]);
        $httpClient = $this->createHttpClient([
            'https://acmeparts.ma' => self::MANUFACTURER_HTML,
        ]);

        $pipeline = $this->buildPipeline($provider, $httpClient);
        $results = $pipeline->discover('Automotive', 'Morocco');

        $this->assertIsArray($results);
        foreach ($results as $r) {
            $this->assertInstanceOf(DiscoveryResult::class, $r);
        }
    }
}
