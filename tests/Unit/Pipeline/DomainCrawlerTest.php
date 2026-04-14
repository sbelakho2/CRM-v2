<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pipeline;

use App\Service\WebCrawler\Pipeline\CandidateSet;
use App\Service\WebCrawler\Pipeline\CrawledDomain;
use App\Service\WebCrawler\Pipeline\CrawledPage;
use App\Service\WebCrawler\Pipeline\DomainCrawler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class DomainCrawlerTest extends TestCase
{
    // ───────────────────── helpers ─────────────────────

    /**
     * Create a MockHttpClient that returns controlled responses keyed by URL.
     * Handles Symfony's URL normalization (trailing slash on bare domains).
     *
     * @param array<string, string|array{body: string, status: int}> $responseMap
     */
    private function createMockClient(array $responseMap): MockHttpClient
    {
        return new MockHttpClient(
            function (string $method, string $url) use ($responseMap): MockResponse {
                $normalized = rtrim($url, '/');
                $entry = $responseMap[$url] ?? $responseMap[$normalized] ?? null;

                if ($entry !== null) {
                    if (\is_array($entry)) {
                        return new MockResponse($entry['body'] ?? '', [
                            'http_code' => $entry['status'] ?? 200,
                        ]);
                    }
                    return new MockResponse($entry, ['http_code' => 200]);
                }
                // Default: 404 with empty body
                return new MockResponse('', ['http_code' => 404]);
            },
        );
    }

    /**
     * Build a CandidateSet from a list of domain strings.
     */
    private function makeCandidateSet(array $domains): CandidateSet
    {
        $candidates = [];
        foreach ($domains as $domain) {
            $candidates[$domain] = [
                'domain'     => $domain,
                'name'       => ucfirst(explode('.', $domain)[0]),
                'url'        => "https://{$domain}",
                'title'      => ucfirst(explode('.', $domain)[0]) . ' Company',
                'snippet'    => 'A company',
                'query_type' => 'sector',
            ];
        }
        return new CandidateSet($candidates);
    }

    private function makeCrawler(MockHttpClient $client): DomainCrawler
    {
        return new DomainCrawler($client, new NullLogger());
    }

    // ───────────────────── empty / edge cases ─────────────────────

    /** @test */
    public function returnsEmptyArrayForEmptyCandidateSet(): void
    {
        $crawler = $this->makeCrawler($this->createMockClient([]));
        $result = $crawler->crawl(new CandidateSet([]));

        $this->assertSame([], $result);
    }

    // ───────────────────── homepage crawling ─────────────────────

    /** @test */
    public function crawlsHomepageForEachCandidate(): void
    {
        $requestedUrls = [];
        $client = new MockHttpClient(
            function (string $method, string $url) use (&$requestedUrls): MockResponse {
                $requestedUrls[] = $url;
                return new MockResponse('<html><body>OK</body></html>');
            },
        );

        $crawler = $this->makeCrawler($client);
        $result = $crawler->crawl($this->makeCandidateSet(['alpha.com', 'beta.com']));

        $normalizedUrls = array_map(fn(string $u) => rtrim($u, '/'), $requestedUrls);
        $this->assertContains('https://alpha.com', $normalizedUrls);
        $this->assertContains('https://beta.com', $normalizedUrls);
        $this->assertArrayHasKey('alpha.com', $result);
        $this->assertArrayHasKey('beta.com', $result);
    }

    /** @test */
    public function homepagePageTypeIsHomepage(): void
    {
        $client = $this->createMockClient([
            'https://example.com' => '<html><body>Home</body></html>',
        ]);

        $crawler = $this->makeCrawler($client);
        $result = $crawler->crawl($this->makeCandidateSet(['example.com']));

        $homepage = $result['example.com']->getHomepage();
        $this->assertNotNull($homepage);
        $this->assertSame('homepage', $homepage->getPageType());
        $this->assertTrue($homepage->isSuccess());
    }

    /** @test */
    public function buildHomepageUrlFromDomainNotCandidateUrl(): void
    {
        $candidates = new CandidateSet([
            'example.com' => [
                'domain'     => 'example.com',
                'name'       => 'Example',
                'url'        => 'example.com', // No scheme — DomainCrawler must derive from domain
                'title'      => 'Example',
                'snippet'    => '',
                'query_type' => 'sector',
            ],
        ]);

        $requestedUrls = [];
        $client = new MockHttpClient(
            function (string $method, string $url) use (&$requestedUrls): MockResponse {
                $requestedUrls[] = $url;
                return new MockResponse('<html><body>OK</body></html>');
            },
        );

        $crawler = $this->makeCrawler($client);
        $crawler->crawl($candidates);

        $normalizedUrls = array_map(fn(string $u) => rtrim($u, '/'), $requestedUrls);
        $this->assertContains('https://example.com', $normalizedUrls);
    }

    // ───────────────────── subpage crawling ─────────────────────

    /** @test */
    public function attemptsStandardSubpages(): void
    {
        $requestedUrls = [];
        $client = new MockHttpClient(
            function (string $method, string $url) use (&$requestedUrls): MockResponse {
                $requestedUrls[] = $url;
                return new MockResponse('<html><body>Page</body></html>');
            },
        );

        $crawler = $this->makeCrawler($client);
        $crawler->crawl($this->makeCandidateSet(['example.com']));

        $normalizedUrls = array_map(fn(string $u) => rtrim($u, '/'), $requestedUrls);
        // Must attempt at least about and contact subpages
        $this->assertContains('https://example.com/about', $normalizedUrls);
        $this->assertContains('https://example.com/contact', $normalizedUrls);
        $this->assertContains('https://example.com/products', $normalizedUrls);
        $this->assertContains('https://example.com/quality', $normalizedUrls);
        $this->assertContains('https://example.com/team', $normalizedUrls);
        $this->assertContains('https://example.com/suppliers', $normalizedUrls);
    }

    /** @test */
    public function subpagesHaveCorrectPageType(): void
    {
        $client = $this->createMockClient([
            'https://example.com'          => '<html><body>Home</body></html>',
            'https://example.com/about'    => '<html><body>About us</body></html>',
            'https://example.com/contact'  => '<html><body>Contact</body></html>',
            'https://example.com/products' => '<html><body>Products</body></html>',
        ]);

        $crawler = $this->makeCrawler($client);
        $result = $crawler->crawl($this->makeCandidateSet(['example.com']));
        $domain = $result['example.com'];

        $this->assertNotEmpty($domain->getPagesByType('about'));
        $this->assertNotEmpty($domain->getPagesByType('contact'));
        $this->assertNotEmpty($domain->getPagesByType('products'));
    }

    /** @test */
    public function excludesPagesWithEmptyBody(): void
    {
        $client = $this->createMockClient([
            'https://example.com'       => '<html><body>Home</body></html>',
            'https://example.com/about' => '<html><body>About</body></html>',
            // All other subpages return 404 with empty body → excluded
        ]);

        $crawler = $this->makeCrawler($client);
        $result = $crawler->crawl($this->makeCandidateSet(['example.com']));
        $domain = $result['example.com'];

        // Only homepage + /about should appear (non-empty body)
        $this->assertCount(2, $domain->getPages());
    }

    // ───────────────────── HTTP error handling ─────────────────────

    /** @test */
    public function handlesHttpErrorsGracefully(): void
    {
        $client = $this->createMockClient([
            'https://example.com'       => '<html><body>Home</body></html>',
            'https://example.com/about' => ['body' => 'Server Error', 'status' => 500],
        ]);

        $crawler = $this->makeCrawler($client);
        $result = $crawler->crawl($this->makeCandidateSet(['example.com']));

        // Domain result still exists
        $this->assertArrayHasKey('example.com', $result);

        // Homepage is successful
        $this->assertTrue($result['example.com']->getHomepage()->isSuccess());

        // 500 page is included (non-empty body) but not successful
        $errorPages = array_filter(
            $result['example.com']->getPages(),
            fn(CrawledPage $p) => !$p->isSuccess(),
        );
        $this->assertNotEmpty($errorPages);
    }

    // ───────────────────── structured data extraction ─────────────────────

    /** @test */
    public function extractsJsonLdStructuredData(): void
    {
        $html = '<html><head>'
            . '<script type="application/ld+json">{"@type":"Organization","name":"Test Co"}</script>'
            . '</head><body>Content</body></html>';

        $client = $this->createMockClient(['https://example.com' => $html]);
        $crawler = $this->makeCrawler($client);
        $result = $crawler->crawl($this->makeCandidateSet(['example.com']));

        $sd = $result['example.com']->getHomepage()->getStructuredData();
        $this->assertCount(1, $sd);
        $this->assertSame('Organization', $sd[0]['@type']);
        $this->assertSame('Test Co', $sd[0]['name']);
    }

    /** @test */
    public function extractsMultipleJsonLdBlocks(): void
    {
        $html = '<html><head>'
            . '<script type="application/ld+json">{"@type":"Organization","name":"Acme"}</script>'
            . '<script type="application/ld+json">{"@type":"LocalBusiness","address":"123 St"}</script>'
            . '</head><body>Content</body></html>';

        $client = $this->createMockClient(['https://example.com' => $html]);
        $crawler = $this->makeCrawler($client);
        $result = $crawler->crawl($this->makeCandidateSet(['example.com']));

        $sd = $result['example.com']->getHomepage()->getStructuredData();
        $this->assertCount(2, $sd);
    }

    /** @test */
    public function extractsMetaTags(): void
    {
        $html = '<html><head>'
            . '<meta name="description" content="We manufacture precision parts">'
            . '<meta property="og:title" content="Acme Manufacturing">'
            . '</head><body>Content</body></html>';

        $client = $this->createMockClient(['https://example.com' => $html]);
        $crawler = $this->makeCrawler($client);
        $result = $crawler->crawl($this->makeCandidateSet(['example.com']));

        $meta = $result['example.com']->getHomepage()->getMetaTags();
        $this->assertSame('We manufacture precision parts', $meta['description']);
        $this->assertSame('Acme Manufacturing', $meta['og:title']);
    }

    /** @test */
    public function handlesMetaTagsInBothAttributeOrders(): void
    {
        $html = '<html><head>'
            . '<meta name="description" content="First form">'
            . '<meta content="Second form" name="keywords">'
            . '</head><body>Content</body></html>';

        $client = $this->createMockClient(['https://example.com' => $html]);
        $crawler = $this->makeCrawler($client);
        $result = $crawler->crawl($this->makeCandidateSet(['example.com']));

        $meta = $result['example.com']->getHomepage()->getMetaTags();
        $this->assertSame('First form', $meta['description']);
        $this->assertSame('Second form', $meta['keywords']);
    }

    // ───────────────────── CrawledDomain aggregation ─────────────────────

    /** @test */
    public function crawledDomainProvidesAllText(): void
    {
        $client = $this->createMockClient([
            'https://example.com'       => '<html><body><p>We manufacture steel parts</p></body></html>',
            'https://example.com/about' => '<html><body><p>Founded in 1990</p></body></html>',
        ]);

        $crawler = $this->makeCrawler($client);
        $result = $crawler->crawl($this->makeCandidateSet(['example.com']));

        $allText = $result['example.com']->getAllText();
        $this->assertStringContainsString('manufacture steel parts', $allText);
        $this->assertStringContainsString('Founded in 1990', $allText);
    }

    /** @test */
    public function crawledDomainProvidesAllStructuredData(): void
    {
        $client = $this->createMockClient([
            'https://example.com' => '<html><head>'
                . '<script type="application/ld+json">{"@type":"Organization","name":"A"}</script>'
                . '</head><body>Home</body></html>',
            'https://example.com/about' => '<html><head>'
                . '<script type="application/ld+json">{"@type":"AboutPage","name":"B"}</script>'
                . '</head><body>About</body></html>',
        ]);

        $crawler = $this->makeCrawler($client);
        $result = $crawler->crawl($this->makeCandidateSet(['example.com']));

        $allSD = $result['example.com']->getAllStructuredData();
        $this->assertCount(2, $allSD);
    }

    /** @test */
    public function getSuccessfulPagesExcludesErrors(): void
    {
        $client = $this->createMockClient([
            'https://example.com'       => '<html><body>Home</body></html>',
            'https://example.com/about' => ['body' => 'Error page text', 'status' => 500],
        ]);

        $crawler = $this->makeCrawler($client);
        $result = $crawler->crawl($this->makeCandidateSet(['example.com']));
        $domain = $result['example.com'];

        // Both pages included (non-empty bodies)
        $allPages = $domain->getPages();
        $successfulPages = $domain->getSuccessfulPages();

        $this->assertGreaterThan(count($successfulPages), count($allPages));
        foreach ($successfulPages as $page) {
            $this->assertTrue($page->isSuccess());
        }
    }

    // ───────────────────── CrawledPage value object ─────────────────────

    /** @test */
    public function crawledPageTextContentStripsHtml(): void
    {
        $page = new CrawledPage(
            'https://example.com',
            '<html><body><p>Hello   <strong>world</strong></p><script>var x;</script></body></html>',
            200,
            'homepage',
        );

        $text = $page->getTextContent();
        $this->assertStringContainsString('Hello', $text);
        $this->assertStringContainsString('world', $text);
        $this->assertStringNotContainsString('<p>', $text);
        $this->assertStringNotContainsString('<strong>', $text);
    }

    /**
     * @test
     * @dataProvider successStatusProvider
     */
    public function crawledPageReportsSuccessStatus(int $status, bool $expected): void
    {
        $page = new CrawledPage('https://example.com', '', $status, 'homepage');
        $this->assertSame($expected, $page->isSuccess());
    }

    public static function successStatusProvider(): array
    {
        return [
            'OK'               => [200, true],
            'Created'          => [201, true],
            'Moved'            => [301, true],
            'Found'            => [302, true],
            'Bad Request'      => [400, false],
            'Not Found'        => [404, false],
            'Server Error'     => [500, false],
            'Connection Error' => [0, false],
        ];
    }

    // ───────────────────── multi-domain isolation ─────────────────────

    /** @test */
    public function multipleCandidatesProduceIndependentResults(): void
    {
        $client = $this->createMockClient([
            'https://alpha.com' => '<html><body>Alpha content</body></html>',
            'https://beta.com'  => '<html><body>Beta content</body></html>',
        ]);

        $crawler = $this->makeCrawler($client);
        $result = $crawler->crawl($this->makeCandidateSet(['alpha.com', 'beta.com']));

        $this->assertCount(2, $result);

        $alphaText = $result['alpha.com']->getAllText();
        $betaText = $result['beta.com']->getAllText();

        $this->assertStringContainsString('Alpha content', $alphaText);
        $this->assertStringNotContainsString('Beta content', $alphaText);
        $this->assertStringContainsString('Beta content', $betaText);
        $this->assertStringNotContainsString('Alpha content', $betaText);
    }

    // ───────────────────── domain metadata ─────────────────────

    /** @test */
    public function crawledDomainReportsCorrectDomain(): void
    {
        $client = $this->createMockClient([
            'https://example.com' => '<html><body>Content</body></html>',
        ]);

        $crawler = $this->makeCrawler($client);
        $result = $crawler->crawl($this->makeCandidateSet(['example.com']));

        $this->assertSame('example.com', $result['example.com']->getDomain());
    }

    /** @test */
    public function crawledDomainTracksTotalTime(): void
    {
        $client = $this->createMockClient([
            'https://example.com' => '<html><body>Content</body></html>',
        ]);

        $crawler = $this->makeCrawler($client);
        $result = $crawler->crawl($this->makeCandidateSet(['example.com']));

        $this->assertIsFloat($result['example.com']->getTotalTime());
        $this->assertGreaterThanOrEqual(0.0, $result['example.com']->getTotalTime());
    }

    /** @test */
    public function crawledDomainReturnedForEveryCandidate(): void
    {
        // Even if homepage 404s with empty body, the domain should still appear
        // and retain the failed homepage fetch instead of flattening it away.
        $client = $this->createMockClient([]); // Everything 404s with empty body

        $crawler = $this->makeCrawler($client);
        $result = $crawler->crawl($this->makeCandidateSet(['ghost.com']));

        $this->assertArrayHasKey('ghost.com', $result);
        $this->assertInstanceOf(CrawledDomain::class, $result['ghost.com']);
        $this->assertCount(1, $result['ghost.com']->getPages());
        $this->assertFalse($result['ghost.com']->getHomepage()?->isSuccess());
    }
}
