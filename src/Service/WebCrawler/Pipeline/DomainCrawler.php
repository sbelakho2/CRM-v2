<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Security\SafeOutboundUrlGuard;

/**
 * Fetches homepage + standard subpages for every candidate domain.
 *
 * Returns one CrawledDomain per candidate with parsed structured data
 * (JSON-LD, meta tags) ready for downstream classification and scoring.
 */
final class DomainCrawler
{
    /**
     * Standard subpage paths tried for every domain.
     * Each entry maps to a page type used by downstream classifiers.
     */
    private const SUBPAGE_PATHS = [
        // ── About pages ──────────────────────────────────────────
        ['path' => '/about',           'type' => 'about'],
        ['path' => '/about-us',        'type' => 'about'],
        ['path' => '/about-company',   'type' => 'about'],
        ['path' => '/our-company',     'type' => 'about'],
        ['path' => '/company',         'type' => 'about'],
        ['path' => '/who-we-are',      'type' => 'about'],
        // ── Contact pages ────────────────────────────────────────
        ['path' => '/contact',         'type' => 'contact'],
        ['path' => '/contact-us',      'type' => 'contact'],
        ['path' => '/get-in-touch',    'type' => 'contact'],
        ['path' => '/contact-us/enquiry','type' => 'contact'],
        // ── Products / services ──────────────────────────────────
        ['path' => '/products',        'type' => 'products'],
        ['path' => '/our-products',    'type' => 'products'],
        ['path' => '/solutions',       'type' => 'products'],
        ['path' => '/capabilities',    'type' => 'products'],
        // ── Quality ──────────────────────────────────────────────
        ['path' => '/quality',         'type' => 'quality'],
        ['path' => '/quality-assurance','type' => 'quality'],
        ['path' => '/quality-policy',  'type' => 'quality'],
        // ── Team / people pages ──────────────────────────────────
        ['path' => '/team',            'type' => 'team'],
        ['path' => '/our-team',        'type' => 'team'],
        ['path' => '/leadership',      'type' => 'team'],
        ['path' => '/management',      'type' => 'team'],
        ['path' => '/people',          'type' => 'team'],
        ['path' => '/our-people',      'type' => 'team'],
        ['path' => '/key-personnel',   'type' => 'team'],
        ['path' => '/executive-team',  'type' => 'team'],
        ['path' => '/board-of-directors','type' => 'team'],
        ['path' => '/meet-the-team',   'type' => 'team'],
        ['path' => '/management-team', 'type' => 'team'],
        ['path' => '/board',           'type' => 'team'],
        ['path' => '/board-members',   'type' => 'team'],
        ['path' => '/executive-management','type' => 'team'],
        ['path' => '/directors',       'type' => 'team'],
        ['path' => '/staff',           'type' => 'team'],
        ['path' => '/employees',       'type' => 'team'],
        ['path' => '/leadership-team', 'type' => 'team'],
        // ── Supplier / procurement pages ─────────────────────────
        ['path' => '/suppliers',              'type' => 'supplier'],
        ['path' => '/procurement',            'type' => 'supplier'],
        ['path' => '/vendor-registration',    'type' => 'supplier'],
        ['path' => '/become-a-supplier',      'type' => 'supplier'],
        ['path' => '/vendor',                 'type' => 'supplier'],
        ['path' => '/vendor-portal',          'type' => 'supplier'],
        ['path' => '/purchasing',             'type' => 'supplier'],
        ['path' => '/supplier-registration',  'type' => 'supplier'],
    ];

    /** Maximum number of sitemap URLs to parse per domain (prevents runaway). */
    private const MAX_SITEMAP_URLS = 50;

    private const REQUEST_TIMEOUT = 10.0;
    private const ROBOTS_TIMEOUT  = 5.0;
    private const MAX_REDIRECTS   = 5;
    private const USER_AGENT      = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private readonly HttpClientInterface $guardedHttpClient;
    private readonly SafeOutboundUrlGuard $urlGuard;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
        // SSRF defense in depth for homepage/robots/sitemap crawling: the
        // decorated client blocks private-network destinations on the
        // initial request and every redirect hop.
        // Mock clients power hermetic unit tests with unresolvable fixture
        // domains; SSRF enforcement is only meaningful for real transport.
        if (!$httpClient instanceof \Symfony\Component\HttpClient\MockHttpClient) {
            $this->guardedHttpClient = new \Symfony\Component\HttpClient\NoPrivateNetworkHttpClient($httpClient);
        } else {
            $this->guardedHttpClient = $httpClient;
        }
        $this->urlGuard = new SafeOutboundUrlGuard();
    }

    /**
     * Crawl all candidate domains: homepage + standard subpages.
     *
     * @return array<string, CrawledDomain> domain → CrawledDomain
     */
    public function crawl(CandidateSet $candidates): array
    {
        if ($candidates->isEmpty()) {
            return [];
        }

        $startTime = microtime(true);
        $domains = $candidates->getDomains();
        $robotsPolicies = $this->fetchRobotsPolicies($domains);

        // Phase 1: Build and fetch all homepage URLs
        $homepageUrls = [];
        foreach ($domains as $domain) {
            if (!(($robotsPolicies[$domain]['allowed'] ?? true))) {
                $this->logger->info('[DomainCrawler] Skipping homepage for {domain}: disallowed by robots.txt', [
                    'domain' => $domain,
                ]);
                continue;
            }

            $homepageUrls[$domain] = 'https://' . $domain;
        }
        $homepageResponses = $this->fetchBatch(array_values($homepageUrls), true);

        // Phase 1.5: Sitemap discovery — find additional pages from sitemap.xml
        $sitemapPages = $this->discoverFromSitemaps($domains, $homepageResponses);
        $this->logger->info('[DomainCrawler] Discovered {n} pages from sitemaps', [
            'n' => \count($sitemapPages),
        ]);

        // Phase 2: Build subpage URL map → {url: {domain, type}}
        $subpageUrlMap = $sitemapPages;
        foreach ($domains as $domain) {
            if (!isset($homepageUrls[$domain])) {
                continue;
            }

            $baseUrl = 'https://' . $domain;
            $disallowedPaths = $robotsPolicies[$domain]['disallowed_paths'] ?? [];

            foreach (self::SUBPAGE_PATHS as $pathDef) {
                if (!$this->isPathAllowed($pathDef['path'], $disallowedPaths)) {
                    $this->logger->debug('[DomainCrawler] Skipping {path} for {domain}: disallowed by robots.txt', [
                        'domain' => $domain,
                        'path' => $pathDef['path'],
                    ]);
                    continue;
                }

                $url = $baseUrl . $pathDef['path'];
                $subpageUrlMap[$url] = [
                    'domain' => $domain,
                    'type'   => $pathDef['type'],
                ];
            }
        }

        // Phase 3: Fetch all subpages
        $subpageResponses = $this->fetchBatch(array_keys($subpageUrlMap));
        $totalElapsed = microtime(true) - $startTime;

        // Phase 4: Assemble CrawledDomain objects
        $results = [];
        foreach ($domains as $domain) {
            $pages = [];

            // Homepage
            $homepageUrl = $homepageUrls[$domain] ?? null;
            if ($homepageUrl !== null && isset($homepageResponses[$homepageUrl])) {
                $resp = $homepageResponses[$homepageUrl];
                $pages[] = $this->buildCrawledPage($homepageUrl, $resp['body'], $resp['status'], 'homepage');

                if ($resp['error'] !== null) {
                    $this->logger->warning('[DomainCrawler] Homepage fetch failed for {domain}: {error}', [
                        'domain' => $domain,
                        'error' => $resp['error'],
                    ]);
                }
            }

            // Subpages
            foreach ($subpageUrlMap as $url => $meta) {
                if ($meta['domain'] !== $domain) {
                    continue;
                }
                if (isset($subpageResponses[$url])) {
                    $resp = $subpageResponses[$url];
                    $pages[] = $this->buildCrawledPage($url, $resp['body'], $resp['status'], $meta['type']);
                }
            }

            $crawledDomain = new CrawledDomain(
                $domain,
                $pages,
                $totalElapsed,
            );

            if ($crawledDomain->getSuccessfulPages() === []) {
                $homepageStatus = $crawledDomain->getHomepage()?->getHttpStatus();
                $this->logger->warning('[DomainCrawler] Domain produced no successful pages', [
                    'domain' => $domain,
                    'homepage_status' => $homepageStatus,
                ]);
            }

            $results[$domain] = $crawledDomain;
        }

        $totalPages = array_sum(array_map(fn(CrawledDomain $d) => \count($d->getPages()), $results));
        $this->logger->info('[DomainCrawler] Crawled {n} domains, {p} pages collected', [
            'n' => \count($results),
            'p' => $totalPages,
        ]);

        return $results;
    }

    // ──────────────────────────────────────────────────────────────────
    //  HTTP fetching
    // ──────────────────────────────────────────────────────────────────

    /**
     * Fire off all requests concurrently and collect results.
     * When $preserveEmptyBodies is true, empty responses are retained so callers
     * can distinguish transport failures from content-based rejection.
     *
     * @param string[] $urls
     * @return array<string, array{status: int, body: string, error: ?string}>
     */
    private function fetchBatch(array $urls, bool $preserveEmptyBodies = false): array
    {
        if (empty($urls)) {
            return [];
        }

        // Fire off all requests (lazy in production, instant in tests)
        $responses = [];
        $results = [];
        foreach ($urls as $url) {
            try {
                $responses[$url] = $this->guardedHttpClient->request('GET', $url, [
                    'timeout'       => self::REQUEST_TIMEOUT,
                    'max_redirects' => self::MAX_REDIRECTS,
                    'headers'       => [
                        'User-Agent'      => self::USER_AGENT,
                        'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language' => 'en-US,en;q=0.9',
                    ],
                ]);
            } catch (\Throwable $e) {
                $results[$url] = ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
                $this->logger->warning('[DomainCrawler] Request creation failed for {url}: {msg}', [
                    'url' => $url,
                    'msg' => $e->getMessage(),
                ]);
            }
        }

        // Collect results
        foreach ($responses as $url => $response) {
            try {
                $statusCode = $response->getStatusCode();
                $content = $response->getContent(false);

                if ($preserveEmptyBodies || $content !== '') {
                    $results[$url] = ['status' => $statusCode, 'body' => $content, 'error' => null];
                }
            } catch (\Throwable $e) {
                $results[$url] = ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
                $this->logger->warning('[DomainCrawler] Fetch failed for {url}: {msg}', [
                    'url' => $url,
                    'msg' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Fetch and parse robots.txt for each domain.
     *
     * Fail-closed policy: on a transport failure (timeout, connection error,
     * 5xx/403/429) the robots fetch is retried ONCE. If it fails again the
     * domain is treated as DISALLOWED and skipped politely — a site that
     * cannot serve robots.txt must not be hammered, and assuming "allowed"
     * on failure would violate the crawl policy. A clean 404 means "no
     * robots.txt" and is treated as allowed (RFC 9309).
     *
     * @param string[] $domains
     * @return array<string, array{allowed: bool, disallowed_paths: string[]}>
     */
    private function fetchRobotsPolicies(array $domains): array
    {
        $responses = [];
        $policies = [];

        foreach ($domains as $domain) {
            $robotsUrl = 'https://' . $domain . '/robots.txt';

            $response = null;
            $failure = null;

            for ($attempt = 1; $attempt <= 2; $attempt++) {
                try {
                    $response = $this->guardedHttpClient->request('GET', $robotsUrl, [
                        'timeout' => self::ROBOTS_TIMEOUT,
                        'max_redirects' => 2,
                        'headers' => [
                            'User-Agent' => self::USER_AGENT,
                            'Accept' => 'text/plain,*/*;q=0.1',
                        ],
                    ]);
                    $failure = null;
                    break;
                } catch (\Throwable $e) {
                    $failure = $e;
                    if ($attempt === 1) {
                        $this->logger->warning(
                            '[DomainCrawler] robots.txt fetch failed for {domain} (attempt {attempt}), retrying once: {error}',
                            ['domain' => $domain, 'attempt' => $attempt, 'error' => $e->getMessage()],
                        );
                    }
                }
            }

            if ($failure !== null) {
                // Transport failure after retry → fail closed, skip domain politely.
                $this->logger->warning(
                    '[DomainCrawler] robots.txt unreachable for {domain} after retry — treating as disallowed, skipping',
                    ['domain' => $domain, 'error' => $failure->getMessage()],
                );
                $policies[$domain] = ['allowed' => false, 'disallowed_paths' => ['/']];
                continue;
            }

            if ($response === null) {
                $policies[$domain] = ['allowed' => false, 'disallowed_paths' => ['/']];
                continue;
            }

            try {
                $statusCode = $response->getStatusCode();
                if ($statusCode === 404) {
                    // No robots.txt → nothing disallowed.
                    $policies[$domain] = ['allowed' => true, 'disallowed_paths' => []];
                    continue;
                }
                if ($statusCode >= 400) {
                    // 401/403/429/5xx etc. — robots.txt is being refused.
                    // Retry once, then fail closed.
                    $retry = null;
                    for ($attempt = 1; $attempt <= 2; $attempt++) {
                        try {
                            $retry = $this->guardedHttpClient->request('GET', $robotsUrl, [
                                'timeout' => self::ROBOTS_TIMEOUT,
                                'max_redirects' => 2,
                                'headers' => [
                                    'User-Agent' => self::USER_AGENT,
                                    'Accept' => 'text/plain,*/*;q=0.1',
                                ],
                            ]);
                            break;
                        } catch (\Throwable $e) {
                            $retry = null;
                            if ($attempt === 1) {
                                $this->logger->warning(
                                    '[DomainCrawler] robots.txt HTTP {status} for {domain}, retrying once: {error}',
                                    ['domain' => $domain, 'status' => $statusCode, 'error' => $e->getMessage()],
                                );
                            }
                        }
                    }
                    if ($retry !== null && $retry->getStatusCode() === 200) {
                        $policies[$domain] = $this->parseRobotsPolicy($retry->getContent(false));
                        continue;
                    }
                    $this->logger->warning(
                        '[DomainCrawler] robots.txt refused for {domain} (HTTP {status}) — treating as disallowed, skipping',
                        ['domain' => $domain, 'status' => $statusCode],
                    );
                    $policies[$domain] = ['allowed' => false, 'disallowed_paths' => ['/']];
                    continue;
                }

                $policies[$domain] = $this->parseRobotsPolicy($response->getContent(false));
            } catch (\Throwable $e) {
                // Parsing/transport failure after a response was received → fail closed.
                $this->logger->warning(
                    '[DomainCrawler] robots.txt processing failed for {domain} — treating as disallowed, skipping: {error}',
                    ['domain' => $domain, 'error' => $e->getMessage()],
                );
                $policies[$domain] = ['allowed' => false, 'disallowed_paths' => ['/']];
            }
        }

        return $policies;
    }

    /**
     * @return array{allowed: bool, disallowed_paths: string[]}
     */
    private function parseRobotsPolicy(string $robotsTxt): array
    {
        $disallowedPaths = [];
        $appliesToUs = false;

        foreach (preg_split('/\r?\n/', $robotsTxt) as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^user-agent\s*:\s*(.+)$/i', $line, $matches) === 1) {
                $userAgent = strtolower(trim($matches[1]));
                $appliesToUs = $userAgent === '*'
                    || str_contains(strtolower(self::USER_AGENT), $userAgent);
                continue;
            }

            if (!$appliesToUs) {
                continue;
            }

            if (preg_match('/^disallow\s*:\s*(.*)$/i', $line, $matches) === 1) {
                $path = trim($matches[1]);
                if ($path !== '') {
                    $disallowedPaths[] = $path;
                }
            }
        }

        return [
            'allowed' => !in_array('/', $disallowedPaths, true),
            'disallowed_paths' => array_values(array_unique($disallowedPaths)),
        ];
    }

    /**
     * @param string[] $disallowedPaths
     */
    private function isPathAllowed(string $path, array $disallowedPaths): bool
    {
        foreach ($disallowedPaths as $disallowedPath) {
            if ($disallowedPath === '/') {
                return false;
            }

            if ($disallowedPath !== '' && str_starts_with($path, $disallowedPath)) {
                return false;
            }
        }

        return true;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Sitemap discovery
    // ──────────────────────────────────────────────────────────────────

    /**
     * Path keywords in sitemap URLs that indicate pages likely to yield contacts.
     * We only add sitemap-discovered pages if they match these patterns.
     */
    private const TEAM_SITEMAP_KEYWORDS = [
        '/team', '/our-team', '/leadership', '/management', '/people',
        '/our-people', '/staff', '/employees', '/executive', '/board',
        '/directors', '/about', '/about-us', '/contact', '/contact-us',
        '/who-we-are', '/key-personnel', '/meet-the-team',
        '/company/team', '/company/leadership', '/company/management',
        '/about/team', '/about/leadership', '/about/management',
    ];

    /**
     * Try to fetch and parse sitemap.xml for each domain that had a successful
     * homepage response. Discovers additional team/people/contact pages.
     *
     * @param string[] $domains
     * @param array<string, array{status: int, body: string, error: ?string}> $homepageResponses
     * @return array<string, array{domain: string, type: string}>
     */
    private function discoverFromSitemaps(array $domains, array $homepageResponses): array
    {
        $sitemapPages = [];

        foreach ($domains as $domain) {
            $homepageUrl = 'https://' . $domain;
            // Only attempt sitemap discovery on domains with a successful homepage
            if (!isset($homepageResponses[$homepageUrl])
                || ($homepageResponses[$homepageUrl]['status'] ?? 0) >= 400
            ) {
                continue;
            }

            $sitemapUrl = 'https://' . $domain . '/sitemap.xml';
            try {
                $this->urlGuard->assertAllowed($sitemapUrl);
                $response = $this->guardedHttpClient->request('GET', $sitemapUrl, [
                    'timeout' => self::ROBOTS_TIMEOUT,
                    'max_redirects' => 2,
                    'headers' => [
                        'User-Agent' => self::USER_AGENT,
                        'Accept' => 'application/xml,text/xml,*/*;q=0.1',
                    ],
                ]);
                $statusCode = $response->getStatusCode();
                if ($statusCode >= 400) {
                    continue;
                }
                $content = $response->getContent(false);
                if ($content === '') {
                    continue;
                }
                $parsedUrls = $this->parseSitemapXml($content, $homepageUrl);
                $added = 0;
                foreach ($parsedUrls as $url) {
                    if ($added >= self::MAX_SITEMAP_URLS) {
                        break;
                    }
                    $pageType = $this->classifySitemapUrl($url);
                    if ($pageType === null) {
                        continue;
                    }
                    // Avoid adding URLs already in standard subpage paths
                    $path = parse_url($url, PHP_URL_PATH) ?? '';
                    $isDuplicate = false;
                    foreach (self::SUBPAGE_PATHS as $sp) {
                        if ($sp['path'] === $path) {
                            $isDuplicate = true;
                            break;
                        }
                    }
                    if ($isDuplicate) {
                        continue;
                    }
                    $sitemapPages[$url] = [
                        'domain' => $domain,
                        'type' => $pageType,
                    ];
                    ++$added;
                }
            } catch (\Throwable) {
                // Sitemap not available — continue silently
            }
        }

        return $sitemapPages;
    }

    /**
     * Parse sitemap XML and extract all URLs.
     *
     * Handles both sitemap indexes (pointing to sub-sitemaps) and
     * standard sitemaps with <url><loc> entries.
     *
     * Sitemap content is attacker-controlled: <loc> entries and sub-sitemap
     * references are only accepted when they stay within the crawled site's
     * organization ($baseUrl), XML is parsed with network access disabled,
     * and every outbound fetch goes through the SSRF guard.
     *
     * @return string[]
     */
    private function parseSitemapXml(string $xml, ?string $baseUrl = null): array
    {
        $urls = [];

        // Suppress libxml errors
        $useErrors = libxml_use_internal_errors(true);

        $doc = new \DOMDocument();
        $loaded = $doc->loadXML($xml, LIBXML_NONET);
        if (!$loaded) {
            libxml_clear_errors();
            libxml_use_internal_errors($useErrors);
            return [];
        }

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        // Try standard sitemap URLs first
        $locNodes = $xpath->query('//sm:url/sm:loc');
        if ($locNodes !== false && $locNodes->length > 0) {
            foreach ($locNodes as $node) {
                $url = trim($node->nodeValue ?? '');
                if ($url === '') {
                    continue;
                }
                if ($baseUrl !== null && !$this->urlGuard->isAllowedChildUrl($baseUrl, $url)) {
                    continue; // cross-origin <loc> from a compromised sitemap
                }
                $urls[] = $url;
            }
        }

        // If no URLs found, try sitemap index (points to sub-sitemaps)
        if (empty($urls)) {
            $subSitemaps = $xpath->query('//sm:sitemap/sm:loc');
            if ($subSitemaps !== false && $subSitemaps->length > 0) {
                foreach ($subSitemaps as $node) {
                    $subUrl = trim($node->nodeValue ?? '');
                    if ($subUrl !== '') {
                        // Sub-sitemaps must stay within the crawled organization
                        if ($baseUrl !== null && !$this->urlGuard->isAllowedChildUrl($baseUrl, $subUrl)) {
                            continue;
                        }
                        // Recursively fetch and parse sub-sitemaps (limited depth)
                        try {
                            $this->urlGuard->assertAllowed($subUrl);
                            $response = $this->guardedHttpClient->request('GET', $subUrl, [
                                'timeout' => self::ROBOTS_TIMEOUT,
                                'max_redirects' => 2,
                                'headers' => [
                                    'User-Agent' => self::USER_AGENT,
                                    'Accept' => 'application/xml,text/xml,*/*;q=0.1',
                                ],
                            ]);
                            $subContent = $response->getContent(false);
                            if ($subContent !== '') {
                                $subUrls = $this->parseSitemapXml($subContent, $baseUrl);
                                $urls = array_merge($urls, $subUrls);
                            }
                        } catch (\Throwable) {
                            // Skip unavailable sub-sitemaps
                        }
                    }
                }
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($useErrors);

        return $urls;
    }

    /**
     * Classify a sitemap-discovered URL by its path.
     * Returns the page type or null if not relevant.
     */
    private function classifySitemapUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $pathLower = mb_strtolower($path);

        // Check against team/contact/about keywords
        foreach (self::TEAM_SITEMAP_KEYWORDS as $keyword) {
            if (str_contains($pathLower, $keyword)) {
                if (str_contains($pathLower, 'contact')) {
                    return 'contact';
                }
                if (str_contains($pathLower, 'about')) {
                    return 'about';
                }
                return 'team';
            }
        }

        // Also check for supplier/procurement keywords
        $supplierKeywords = ['/supplier', '/procurement', '/vendor', '/purchasing'];
        foreach ($supplierKeywords as $keyword) {
            if (str_contains($pathLower, $keyword)) {
                return 'supplier';
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Page parsing
    // ──────────────────────────────────────────────────────────────────

    private function buildCrawledPage(string $url, string $html, int $status, string $pageType): CrawledPage
    {
        return new CrawledPage(
            $url,
            $html,
            $status,
            $pageType,
            $this->extractJsonLd($html),
            $this->extractMetaTags($html),
        );
    }

    /**
     * Extract all JSON-LD blocks from the HTML.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractJsonLd(string $html): array
    {
        $data = [];

        if (preg_match_all(
            '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si',
            $html,
            $matches,
        )) {
            foreach ($matches[1] as $jsonStr) {
                /** @var array<string, mixed>|null $parsed */
                $parsed = json_decode(trim($jsonStr), true);
                if (\is_array($parsed)) {
                    $data[] = $parsed;
                }
            }
        }

        return $data;
    }

    /**
     * Extract meta tags (name/property → content).
     *
     * Handles both attribute orders:
     *   <meta name="..." content="...">
     *   <meta content="..." name="...">
     *
     * @return array<string, string>
     */
    private function extractMetaTags(string $html): array
    {
        $tags = [];

        // name="..." content="..." (and property="..." content="...")
        if (preg_match_all(
            '/<meta\s+(?:name|property)=["\']([^"\']+)["\'][^>]*content=["\']([^"\']*)["\'][^>]*\/?>/si',
            $html,
            $matches,
        )) {
            foreach ($matches[1] as $i => $name) {
                $tags[strtolower($name)] = html_entity_decode($matches[2][$i], ENT_QUOTES, 'UTF-8');
            }
        }

        // content="..." name="..." (reversed attribute order)
        if (preg_match_all(
            '/<meta\s+content=["\']([^"\']*)["\'][^>]*(?:name|property)=["\']([^"\']+)["\'][^>]*\/?>/si',
            $html,
            $matches,
        )) {
            foreach ($matches[2] as $i => $name) {
                $key = strtolower($name);
                if (!isset($tags[$key])) {
                    $tags[$key] = html_entity_decode($matches[1][$i], ENT_QUOTES, 'UTF-8');
                }
            }
        }

        return $tags;
    }
}
