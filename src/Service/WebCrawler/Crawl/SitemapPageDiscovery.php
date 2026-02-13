<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Crawl;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Discovers pages from a website's sitemap.xml before falling back
 * to link-following. Sitemaps give a complete, structured view of
 * important pages without needing to spider the site.
 *
 * Priority order:
 *   1. /sitemap.xml (standard location)
 *   2. /sitemap_index.xml (index pointing to sub-sitemaps)
 *   3. robots.txt → Sitemap: directive
 *
 * Filters URLs to only return pages likely relevant for company intel:
 *   - /about, /team, /contact, /products, /careers, /management, /leadership
 *   - Ignores blog posts, news, images, PDFs, etc.
 */
final class SitemapPageDiscovery
{
    /**
     * URL patterns that indicate high-value pages for company intelligence.
     */
    private const VALUABLE_PATH_PATTERNS = [
        '/about',
        '/team',
        '/leadership',
        '/management',
        '/board',
        '/contact',
        '/products',
        '/solutions',
        '/services',
        '/careers',
        '/governance',
        '/investor',
        '/company',
        '/corporate',
        '/who-we-are',
        '/our-team',
        '/impressum',
        '/a-propos',
        '/notre-equipe',
        '/unternehmen',
        '/ansprechpartner',
        '/chi-siamo',
        '/equipo',
        '/sobre-nosotros',
    ];

    /**
     * URL patterns to SKIP (low-value for company intelligence).
     */
    private const SKIP_PATH_PATTERNS = [
        '/blog/', '/news/', '/press/', '/events/', '/webinar/',
        '/wp-content/', '/wp-json/', '/feed/', '/rss/',
        '.pdf', '.jpg', '.png', '.gif', '.svg', '.css', '.js',
        '/tag/', '/category/', '/archive/', '/page/',
        '/cart', '/checkout', '/login', '/register',
        '/privacy', '/cookie', '/legal/', '/terms',
    ];

    /**
     * Maximum number of URLs to extract from a sitemap.
     */
    private const MAX_URLS = 50;

    /**
     * Maximum size of sitemap to download (500KB).
     */
    private const MAX_SITEMAP_SIZE = 512000;

    /**
     * HTTP timeout for sitemap fetches (seconds).
     */
    private const TIMEOUT = 8;

    private LoggerInterface $logger;

    public function __construct(
        private HttpClientInterface $httpClient,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    // ──────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────

    /**
     * Discover valuable pages for a domain.
     *
     * @param string $domain Root domain (e.g. "example.com")
     * @return list<array{url: string, priority: float, section: string}> Sorted by priority descending
     */
    public function discoverPages(string $domain): array
    {
        $domain = $this->normalizeDomain($domain);
        $baseUrl = 'https://' . $domain;

        // 1. Try /sitemap.xml
        $urls = $this->fetchAndParseSitemap($baseUrl . '/sitemap.xml');

        // 2. Try /sitemap_index.xml
        if (empty($urls)) {
            $urls = $this->fetchAndParseSitemap($baseUrl . '/sitemap_index.xml');
        }

        // 3. Try robots.txt → Sitemap: directive
        if (empty($urls)) {
            $sitemapUrl = $this->getSitemapFromRobots($baseUrl . '/robots.txt');
            if ($sitemapUrl !== null) {
                $urls = $this->fetchAndParseSitemap($sitemapUrl);
            }
        }

        if (empty($urls)) {
            $this->logger->debug('SitemapPageDiscovery: no sitemap found', ['domain' => $domain]);
            return [];
        }

        // Filter and score
        $valuable = $this->filterAndScoreUrls($urls, $baseUrl);

        // Sort by priority descending
        usort($valuable, fn(array $a, array $b) => $b['priority'] <=> $a['priority']);

        return array_slice($valuable, 0, self::MAX_URLS);
    }

    /**
     * Check if a domain has any accessible sitemap.
     */
    public function hasSitemap(string $domain): bool
    {
        $domain = $this->normalizeDomain($domain);
        $baseUrl = 'https://' . $domain;

        return $this->urlReturnsXml($baseUrl . '/sitemap.xml')
            || $this->urlReturnsXml($baseUrl . '/sitemap_index.xml')
            || $this->getSitemapFromRobots($baseUrl . '/robots.txt') !== null;
    }

    // ──────────────────────────────────────────────────
    // Internal: Sitemap fetching & parsing
    // ──────────────────────────────────────────────────

    /**
     * Fetch and parse a sitemap (handles both urlset and sitemapindex).
     *
     * @return list<array{loc: string, priority: float}>
     */
    private function fetchAndParseSitemap(string $url): array
    {
        $xml = $this->fetchUrl($url);
        if ($xml === null) {
            return [];
        }

        // Suppress XML errors
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($doc === false) {
            return [];
        }

        // Register namespace (sitemaps use xmlns)
        $doc->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        // Check if this is a sitemap index
        $sitemaps = $doc->xpath('//s:sitemap/s:loc') ?: $doc->xpath('//sitemap/loc') ?: [];
        if (!empty($sitemaps)) {
            return $this->parseSitemapIndex($sitemaps);
        }

        // Parse as urlset
        return $this->parseUrlSet($doc);
    }

    /**
     * Follow a sitemap index (recurse into sub-sitemaps, max 5).
     */
    private function parseSitemapIndex(array $sitemapLocs): array
    {
        $allUrls = [];
        $count = 0;

        foreach ($sitemapLocs as $loc) {
            if ($count >= 5) break; // Limit recursion

            $subUrl = trim((string) $loc);
            if (empty($subUrl)) continue;

            $urls = $this->fetchAndParseSitemap($subUrl);
            $allUrls = array_merge($allUrls, $urls);
            $count++;
        }

        return $allUrls;
    }

    /**
     * Parse a sitemap urlset into a list of URLs with priorities.
     *
     * @return list<array{loc: string, priority: float}>
     */
    private function parseUrlSet(\SimpleXMLElement $doc): array
    {
        $urls = [];
        $ns = 'http://www.sitemaps.org/schemas/sitemap/0.9';

        $entries = $doc->xpath('//s:url') ?: $doc->xpath('//url') ?: [];

        foreach ($entries as $entry) {
            // Register namespace on each child element for xpath to work
            $entry->registerXPathNamespace('s', $ns);
            $loc = $entry->xpath('s:loc') ?: $entry->xpath('loc') ?: [];
            $pri = $entry->xpath('s:priority') ?: $entry->xpath('priority') ?: [];

            $url = !empty($loc) ? trim((string) $loc[0]) : '';
            $priority = !empty($pri) ? (float) (string) $pri[0] : 0.5;

            if (!empty($url) && str_starts_with($url, 'http')) {
                $urls[] = [
                    'loc'      => $url,
                    'priority' => min(1.0, max(0.0, $priority)),
                ];
            }

            // Safety limit
            if (count($urls) >= 500) break;
        }

        return $urls;
    }

    /**
     * Get sitemap URL from robots.txt Sitemap: directive.
     */
    private function getSitemapFromRobots(string $robotsUrl): ?string
    {
        $content = $this->fetchUrl($robotsUrl);
        if ($content === null) {
            return null;
        }

        // Look for "Sitemap: https://..."
        if (preg_match('/^Sitemap:\s*(\S+)/mi', $content, $m)) {
            $sitemapUrl = trim($m[1]);
            if (str_starts_with($sitemapUrl, 'http')) {
                return $sitemapUrl;
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────────
    // Internal: URL filtering & scoring
    // ──────────────────────────────────────────────────

    /**
     * Filter URLs to only valuable pages, and assign scores.
     *
     * @param list<array{loc: string, priority: float}> $urls
     * @return list<array{url: string, priority: float, section: string}>
     */
    private function filterAndScoreUrls(array $urls, string $baseUrl): array
    {
        $valuable = [];

        foreach ($urls as $entry) {
            $url = $entry['loc'];
            $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');

            // Skip low-value pages
            $skip = false;
            foreach (self::SKIP_PATH_PATTERNS as $pattern) {
                if (str_contains($path, $pattern)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            // Score: base from sitemap priority + bonus for valuable paths
            $score = $entry['priority'];
            $section = 'other';

            foreach (self::VALUABLE_PATH_PATTERNS as $vp) {
                if (str_contains($path, $vp)) {
                    $score += 0.3; // Bonus for valuable paths
                    $section = ltrim($vp, '/');
                    break;
                }
            }

            // Homepage bonus
            if ($path === '/' || $path === '' || $path === '/index.html' || $path === '/index.php') {
                $score += 0.2;
                $section = 'homepage';
            }

            $valuable[] = [
                'url'      => $url,
                'priority' => min(1.5, $score),
                'section'  => $section,
            ];
        }

        return $valuable;
    }

    // ──────────────────────────────────────────────────
    // Internal: HTTP helpers
    // ──────────────────────────────────────────────────

    private function fetchUrl(string $url): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout'     => self::TIMEOUT,
                'max_redirects' => 3,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; CRM-Crawler/1.0)',
                    'Accept' => 'text/xml, application/xml, text/html, */*',
                ],
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 400) {
                return null;
            }

            $content = $response->getContent(false);
            if (strlen($content) > self::MAX_SITEMAP_SIZE) {
                $this->logger->debug('SitemapPageDiscovery: sitemap too large', ['url' => $url, 'size' => strlen($content)]);
                return null;
            }

            return $content;
        } catch (\Throwable $e) {
            $this->logger->debug('SitemapPageDiscovery: fetch failed', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function urlReturnsXml(string $url): bool
    {
        try {
            $response = $this->httpClient->request('HEAD', $url, [
                'timeout' => 5,
                'max_redirects' => 2,
            ]);
            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain);
        if (!str_starts_with($domain, 'www.')) {
            // Don't add www — many sites don't use it
        }
        return $domain;
    }
}
