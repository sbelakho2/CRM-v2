<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Mojeek search scraper.
 *
 * ULTIMATE FALLBACK engine — most permissive to scraping.
 *
 * Mojeek is an independent search engine (not Google/Bing-based) that:
 *   - Has its own index (different results, but still valuable)
 *   - Is extremely tolerant of automated access
 *   - Returns 10 results per page with clean HTML structure
 *   - Server-side rendered (no JS required)
 *
 * Verified working Feb 2026 from Tunisia IP when Brave/DDG/Startpage all blocked.
 *
 * CSS selectors (verified):
 *   - Result container: li.r1, li.r2, etc. or <ul id="results"><li>
 *   - Title link:       a.title[href]
 *   - Snippet:          p.s
 */
final class MojeekScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://www.mojeek.com/search';

    /** Mojeek is tolerant but we're polite anyway */
    private const MIN_DELAY_SECONDS = 2.0;
    private const MAX_JITTER_SECONDS = 2.0;

    private float $lastRequestTime = 0.0;

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function scrapeResults(string $query, ?string $region = null, int $maxResults = 10): array
    {
        $this->respectRateLimit();

        $url = $this->buildUrl($query, $region);
        $userAgent = self::USER_AGENTS[array_rand(self::USER_AGENTS)];

        $this->logger->debug('MojeekScraper: fetching', [
            'region' => $region,
        ]);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'DNT' => '1',
                ],
                'timeout' => 15,
                'max_redirects' => 3,
            ]);

            $statusCode = $response->getStatusCode();
            $this->lastRequestTime = microtime(true);

            if ($statusCode >= 400) {
                throw new \RuntimeException("Mojeek returned HTTP {$statusCode}");
            }

            $html = $response->getContent();

            return $this->parseResults($html, $maxResults);

        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Mojeek search failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getEngineName(): string
    {
        return 'mojeek';
    }

    // ──────────────────────────────────────────────────────────────────────
    // HTML Parsing
    // ──────────────────────────────────────────────────────────────────────

    private function parseResults(string $html, int $maxResults): array
    {
        $crawler = new Crawler($html);
        $results = [];

        // Mojeek lists results in <li> elements within <ul id="results-standard">
        // Each result has: <h2><a class="title" href="...">Title</a></h2><p class="s">snippet</p>
        $resultItems = $crawler->filter('ul#results-standard li, ul.results-standard li, #results li');

        if ($resultItems->count() === 0) {
            // Fallback to broader selector
            $resultItems = $crawler->filter('li.r1, li.r2, li.r3, li.r4, li.r5, li.r6, li.r7, li.r8, li.r9, li.r10');
        }

        if ($resultItems->count() === 0) {
            // Try even broader
            $resultItems = $crawler->filter('a.title')->closest('li');
        }

        $resultItems->each(function (Crawler $node) use (&$results, $maxResults) {
            if (count($results) >= $maxResults) {
                return;
            }

            try {
                // Find title link
                $titleLink = $node->filter('a.title');
                if ($titleLink->count() === 0) {
                    $titleLink = $node->filter('h2 a');
                }

                if ($titleLink->count() === 0) {
                    return;
                }

                $url = $titleLink->attr('href') ?? '';
                $title = trim($titleLink->text(''));

                if (empty($url) || empty($title)) {
                    return;
                }

                // Skip internal links
                if (str_starts_with($url, '/') || str_contains($url, 'mojeek.com')) {
                    return;
                }

                // Find snippet
                $snippetNode = $node->filter('p.s');
                $snippet = $snippetNode->count() > 0 ? trim($snippetNode->text('')) : '';

                // Extract display link
                $displayLink = parse_url($url, PHP_URL_HOST) ?? '';
                if (empty($displayLink)) {
                    return;
                }

                $results[] = [
                    'link' => $url,
                    'title' => $title,
                    'snippet' => $snippet,
                    'displayLink' => $displayLink,
                    'formattedUrl' => $url,
                ];
            } catch (\Throwable) {
                // Skip malformed results
            }
        });

        $this->logger->debug('MojeekScraper: parsed', [
            'count' => count($results),
        ]);

        return $results;
    }

    // ──────────────────────────────────────────────────────────────────────
    // URL Building and Rate Limiting
    // ──────────────────────────────────────────────────────────────────────

    private function buildUrl(string $query, ?string $region = null): string
    {
        $params = ['q' => $query];

        // Mojeek region parameter (lb = local bias)
        if ($region !== null) {
            $regionMap = [
                'DE' => 'de', 'FR' => 'fr', 'IT' => 'it', 'ES' => 'es',
                'GB' => 'gb', 'US' => 'us', 'NL' => 'nl', 'BE' => 'be',
                'AT' => 'at', 'CH' => 'ch', 'SE' => 'se', 'PL' => 'pl',
            ];

            $lb = $regionMap[strtoupper($region)] ?? null;
            if ($lb !== null) {
                $params['lb'] = $lb;
            }
        }

        return self::BASE_URL . '?' . http_build_query($params);
    }

    private function respectRateLimit(): void
    {
        if ($this->lastRequestTime > 0) {
            $elapsed = microtime(true) - $this->lastRequestTime;
            $delay = self::MIN_DELAY_SECONDS + (mt_rand(0, (int)(self::MAX_JITTER_SECONDS * 1000)) / 1000);

            if ($elapsed < $delay) {
                $sleepUs = (int)(($delay - $elapsed) * 1_000_000);
                usleep($sleepUs);
            }
        }
    }
}
