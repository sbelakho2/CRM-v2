<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        ['path' => '/about',      'type' => 'about'],
        ['path' => '/about-us',   'type' => 'about'],
        ['path' => '/contact',    'type' => 'contact'],
        ['path' => '/contact-us', 'type' => 'contact'],
        ['path' => '/products',   'type' => 'products'],
        ['path' => '/quality',    'type' => 'quality'],
        ['path' => '/team',       'type' => 'team'],
        ['path' => '/suppliers',  'type' => 'supplier'],
    ];

    private const REQUEST_TIMEOUT = 10.0;
    private const ROBOTS_TIMEOUT  = 5.0;
    private const MAX_REDIRECTS   = 5;
    private const USER_AGENT      = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
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

        // Phase 2: Build subpage URL map → {url: {domain, type}}
        $subpageUrlMap = [];
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
                $responses[$url] = $this->httpClient->request('GET', $url, [
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
     * @param string[] $domains
     * @return array<string, array{allowed: bool, disallowed_paths: string[]}>
     */
    private function fetchRobotsPolicies(array $domains): array
    {
        $responses = [];
        $policies = [];

        foreach ($domains as $domain) {
            $robotsUrl = 'https://' . $domain . '/robots.txt';

            try {
                $responses[$domain] = $this->httpClient->request('GET', $robotsUrl, [
                    'timeout' => self::ROBOTS_TIMEOUT,
                    'max_redirects' => 2,
                    'headers' => [
                        'User-Agent' => self::USER_AGENT,
                        'Accept' => 'text/plain,*/*;q=0.1',
                    ],
                ]);
            } catch (\Throwable) {
                $policies[$domain] = ['allowed' => true, 'disallowed_paths' => []];
            }
        }

        foreach ($responses as $domain => $response) {
            try {
                $statusCode = $response->getStatusCode();
                if ($statusCode >= 400) {
                    $policies[$domain] = ['allowed' => true, 'disallowed_paths' => []];
                    continue;
                }

                $policies[$domain] = $this->parseRobotsPolicy($response->getContent(false));
            } catch (\Throwable) {
                $policies[$domain] = ['allowed' => true, 'disallowed_paths' => []];
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
