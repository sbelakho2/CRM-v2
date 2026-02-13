<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\WebCrawler\Crawl\PoliteCrawlGovernor;
use App\Service\WebCrawler\Crawl\SitemapPageDiscovery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tests for sitemap page discovery and polite crawl governor.
 */
class CrawlUpgradeTest extends TestCase
{
    // ═══════════════════════════════════════════════════
    // SitemapPageDiscovery
    // ═══════════════════════════════════════════════════

    public function testParseStandardSitemap(): void
    {
        $sitemapXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://example.com/</loc><priority>1.0</priority></url>
  <url><loc>https://example.com/about</loc><priority>0.8</priority></url>
  <url><loc>https://example.com/products</loc><priority>0.9</priority></url>
  <url><loc>https://example.com/team</loc><priority>0.7</priority></url>
  <url><loc>https://example.com/contact</loc><priority>0.6</priority></url>
  <url><loc>https://example.com/blog/post-1</loc><priority>0.3</priority></url>
  <url><loc>https://example.com/news/article-1</loc><priority>0.3</priority></url>
  <url><loc>https://example.com/privacy</loc><priority>0.1</priority></url>
</urlset>
XML;

        $client = new MockHttpClient([
            new MockResponse($sitemapXml, ['http_code' => 200]),
        ]);

        $discovery = new SitemapPageDiscovery($client);
        $pages = $discovery->discoverPages('example.com');

        // Should include about, products, team, contact, homepage
        // Should EXCLUDE blog, news, privacy
        $urls = array_column($pages, 'url');

        $this->assertContains('https://example.com/', $urls);
        $this->assertContains('https://example.com/about', $urls);
        $this->assertContains('https://example.com/products', $urls);
        $this->assertContains('https://example.com/team', $urls);
        $this->assertContains('https://example.com/contact', $urls);

        // Blog/news/privacy should be filtered out
        $this->assertNotContains('https://example.com/blog/post-1', $urls);
        $this->assertNotContains('https://example.com/news/article-1', $urls);
        $this->assertNotContains('https://example.com/privacy', $urls);
    }

    public function testParseSitemapIndex(): void
    {
        $indexXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <sitemap><loc>https://example.com/sitemap-pages.xml</loc></sitemap>
</sitemapindex>
XML;

        $pagesXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://example.com/about</loc><priority>0.8</priority></url>
  <url><loc>https://example.com/team</loc><priority>0.7</priority></url>
</urlset>
XML;

        $client = new MockHttpClient([
            new MockResponse($indexXml, ['http_code' => 200]),
            new MockResponse($pagesXml, ['http_code' => 200]),
        ]);

        $discovery = new SitemapPageDiscovery($client);
        $pages = $discovery->discoverPages('example.com');

        $this->assertNotEmpty($pages);
        $urls = array_column($pages, 'url');
        $this->assertContains('https://example.com/about', $urls);
        $this->assertContains('https://example.com/team', $urls);
    }

    public function testRobotsTxtSitemapDirective(): void
    {
        $robotsTxt = "User-agent: *\nDisallow: /admin/\nSitemap: https://example.com/my-sitemap.xml\n";

        $sitemapXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://example.com/products</loc><priority>0.9</priority></url>
</urlset>
XML;

        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 404]),      // /sitemap.xml → 404
            new MockResponse('', ['http_code' => 404]),      // /sitemap_index.xml → 404
            new MockResponse($robotsTxt, ['http_code' => 200]),  // /robots.txt
            new MockResponse($sitemapXml, ['http_code' => 200]), // actual sitemap
        ]);

        $discovery = new SitemapPageDiscovery($client);
        $pages = $discovery->discoverPages('example.com');

        $this->assertNotEmpty($pages);
        $this->assertEquals('https://example.com/products', $pages[0]['url']);
    }

    public function testNoSitemapReturnsEmpty(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 404]),
            new MockResponse('', ['http_code' => 404]),
            new MockResponse('', ['http_code' => 404]),
        ]);

        $discovery = new SitemapPageDiscovery($client);
        $pages = $discovery->discoverPages('nositemap.com');

        $this->assertEmpty($pages);
    }

    public function testValuablePagesHaveHigherPriority(): void
    {
        $sitemapXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://example.com/random-page</loc><priority>0.5</priority></url>
  <url><loc>https://example.com/about</loc><priority>0.5</priority></url>
</urlset>
XML;

        $client = new MockHttpClient([
            new MockResponse($sitemapXml, ['http_code' => 200]),
        ]);

        $discovery = new SitemapPageDiscovery($client);
        $pages = $discovery->discoverPages('example.com');

        // /about should rank higher than random page (gets +0.3 bonus)
        $this->assertCount(2, $pages);
        $this->assertStringContainsString('about', $pages[0]['url']);
    }

    public function testSectionDetection(): void
    {
        $sitemapXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://example.com/</loc><priority>1.0</priority></url>
  <url><loc>https://example.com/about</loc><priority>0.8</priority></url>
  <url><loc>https://example.com/leadership</loc><priority>0.7</priority></url>
  <url><loc>https://example.com/random</loc><priority>0.5</priority></url>
</urlset>
XML;

        $client = new MockHttpClient([
            new MockResponse($sitemapXml, ['http_code' => 200]),
        ]);

        $discovery = new SitemapPageDiscovery($client);
        $pages = $discovery->discoverPages('example.com');

        $sections = [];
        foreach ($pages as $p) {
            $sections[$p['url']] = $p['section'];
        }

        $this->assertEquals('homepage', $sections['https://example.com/']);
        $this->assertEquals('about', $sections['https://example.com/about']);
        $this->assertEquals('leadership', $sections['https://example.com/leadership']);
        $this->assertEquals('other', $sections['https://example.com/random']);
    }

    // ═══════════════════════════════════════════════════
    // PoliteCrawlGovernor
    // ═══════════════════════════════════════════════════

    public function testThrottleAllowsRequests(): void
    {
        $gov = new PoliteCrawlGovernor(10, 5); // 10ms delay, max 5

        $this->assertTrue($gov->canRequest('example.com'));
        $this->assertTrue($gov->throttle('example.com'));
        $this->assertEquals(1, $gov->getRequestCount('example.com'));
    }

    public function testThrottleRejectsAfterMaxRequests(): void
    {
        $gov = new PoliteCrawlGovernor(1, 3); // 1ms delay, max 3

        $this->assertTrue($gov->throttle('example.com'));
        $this->assertTrue($gov->throttle('example.com'));
        $this->assertTrue($gov->throttle('example.com'));
        $this->assertFalse($gov->throttle('example.com'));
        $this->assertEquals(3, $gov->getRequestCount('example.com'));
    }

    public function testDifferentDomainsAreIndependent(): void
    {
        $gov = new PoliteCrawlGovernor(1, 2);

        $this->assertTrue($gov->throttle('a.com'));
        $this->assertTrue($gov->throttle('a.com'));
        $this->assertFalse($gov->canRequest('a.com'));

        // b.com should still be allowed
        $this->assertTrue($gov->canRequest('b.com'));
        $this->assertTrue($gov->throttle('b.com'));
    }

    public function testErrorBackoff(): void
    {
        $gov = new PoliteCrawlGovernor(100, 20);

        $initialDelay = $gov->getDelay('test.com');
        $this->assertEquals(100, $initialDelay);

        $gov->reportError('test.com', 429);
        $afterFirstError = $gov->getDelay('test.com');
        $this->assertEquals(200, $afterFirstError); // 100 * 2

        $gov->reportError('test.com', 429);
        $afterSecondError = $gov->getDelay('test.com');
        $this->assertEquals(400, $afterSecondError); // 200 * 2
    }

    public function testSuccessResetsBackoff(): void
    {
        $gov = new PoliteCrawlGovernor(100, 20);

        $gov->reportError('test.com', 503);
        $this->assertEquals(200, $gov->getDelay('test.com'));

        $gov->reportSuccess('test.com');
        $this->assertEquals(100, $gov->getDelay('test.com'));
        $this->assertEquals(0, $gov->getErrorCount('test.com'));
    }

    public function testSetDomainDelay(): void
    {
        $gov = new PoliteCrawlGovernor(100, 20);

        $gov->setDomainDelay('slow.com', 2000);
        $this->assertEquals(2000, $gov->getDelay('slow.com'));

        // Floor at 100ms
        $gov->setDomainDelay('fast.com', 10);
        $this->assertEquals(100, $gov->getDelay('fast.com'));
    }

    public function testMaxBackoffCap(): void
    {
        $gov = new PoliteCrawlGovernor(5000, 20);

        // Report many errors
        for ($i = 0; $i < 10; $i++) {
            $gov->reportError('test.com', 429);
        }

        $delay = $gov->getDelay('test.com');
        $this->assertLessThanOrEqual(PoliteCrawlGovernor::MAX_DELAY_MS, $delay);
    }

    public function testReset(): void
    {
        $gov = new PoliteCrawlGovernor(1, 5);

        $gov->throttle('a.com');
        $gov->throttle('b.com');
        $this->assertEquals(1, $gov->getRequestCount('a.com'));

        $gov->reset();
        $this->assertEquals(0, $gov->getRequestCount('a.com'));
        $this->assertEquals(0, $gov->getRequestCount('b.com'));
    }

    public function testGetStats(): void
    {
        $gov = new PoliteCrawlGovernor(1, 10);

        $gov->throttle('a.com');
        $gov->throttle('a.com');
        $gov->throttle('b.com');
        $gov->reportError('b.com', 429);

        $stats = $gov->getStats();
        $this->assertEquals(3, $stats['total_requests']);
        $this->assertEquals(2, $stats['domains_crawled']);
        $this->assertEquals(1, $stats['domains_with_errors']);
    }

    public function testDomainNormalization(): void
    {
        $gov = new PoliteCrawlGovernor(1, 5);

        $gov->throttle('https://www.Example.COM/path');
        $this->assertEquals(1, $gov->getRequestCount('example.com'));

        $gov->throttle('http://Example.com');
        $this->assertEquals(2, $gov->getRequestCount('example.com'));
    }
}
