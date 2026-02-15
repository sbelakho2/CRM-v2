<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Qwant search scraper.
 *
 * Qwant is a French privacy-focused search engine.
 *   - Returns 10 results per page
 *   - Good European coverage, especially France/Germany
 *   - Privacy-first (no user tracking)
 *   - Used Bing's index but has been building its own
 *
 * Note: Qwant uses a mix of SSR and client-side rendering.
 * We use their lite/HTML endpoint when possible.
 *
 * CSS selectors (Feb 2026):
 *   - Result container: div[data-testid="webResult"], .result
 *   - Title link:       a[data-testid="webResultTitle"], h2 a
 *   - Snippet:          p[data-testid="webResultDescription"], .description
 */
final class QwantScraper implements SearchEngineScraper
{
    // Using Qwant Lite for cleaner HTML
    private const BASE_URL = 'https://lite.qwant.com/';

    private const MIN_DELAY_SECONDS = 3.0;
    private const MAX_JITTER_SECONDS = 3.0;

    private float $lastRequestTime = 0.0;

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ];

    /** Map region codes to Qwant region codes */
    private const REGION_MAP = [
        'DE' => 'de_DE',
        'FR' => 'fr_FR',
        'IT' => 'it_IT',
        'ES' => 'es_ES',
        'GB' => 'en_GB',
        'UK' => 'en_GB',
        'NL' => 'nl_NL',
        'PL' => 'pl_PL',
        'AT' => 'de_AT',
        'CH' => 'de_CH',
        'BE' => 'fr_BE',
        'PT' => 'pt_PT',
        'US' => 'en_US',
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

        $this->logger->debug('QwantScraper: fetching', [
            'region' => $region,
        ]);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9,fr;q=0.8,de;q=0.7',
                    'DNT' => '1',
                    'Referer' => 'https://lite.qwant.com/',
                ],
                'timeout' => 15,
                'max_redirects' => 3,
            ]);

            $statusCode = $response->getStatusCode();
            $this->lastRequestTime = microtime(true);

            if ($statusCode === 403 || $statusCode === 429) {
                throw new \RuntimeException("Qwant returned {$statusCode} — rate limited");
            }

            if ($statusCode >= 400) {
                throw new \RuntimeException("Qwant returned HTTP {$statusCode}");
            }

            $html = $response->getContent();

            // Check for rate limiting page
            if (str_contains($html, 'too many requests') || str_contains($html, 'rate limit')) {
                throw new \RuntimeException('Qwant rate limit detected');
            }

            return $this->parseResults($html, $maxResults);

        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Qwant search failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getEngineName(): string
    {
        return 'qwant';
    }

    // ──────────────────────────────────────────────────────────────────────
    // HTML Parsing
    // ──────────────────────────────────────────────────────────────────────

    private function parseResults(string $html, int $maxResults): array
    {
        $crawler = new Crawler($html);
        $results = [];

        // Qwant Lite has simpler HTML structure
        // Results are typically in div or article elements with links
        $resultItems = $crawler->filter('div.result, article.result, .web-result, div[data-testid="webResult"]');

        if ($resultItems->count() === 0) {
            // Fallback: look for result links directly
            $resultItems = $crawler->filter('a[href^="http"]:not([href*="qwant"])')->closest('div');
        }

        $seenUrls = [];

        $resultItems->each(function (Crawler $node) use (&$results, &$seenUrls, $maxResults) {
            if (count($results) >= $maxResults) {
                return;
            }

            try {
                // Find the main link
                $linkNode = $node->filter('a[href^="http"]')->first();
                if ($linkNode->count() === 0) {
                    return;
                }

                $url = $linkNode->attr('href');
                if (!$url || !str_starts_with($url, 'http')) {
                    return;
                }

                // Skip Qwant's own URLs and duplicates
                if (str_contains($url, 'qwant.com') || isset($seenUrls[$url])) {
                    return;
                }
                $seenUrls[$url] = true;

                // Get title from the link or nearest heading
                $title = trim($linkNode->text());
                if (empty($title)) {
                    $headingNode = $node->filter('h2, h3, .title')->first();
                    $title = $headingNode->count() > 0 ? trim($headingNode->text()) : '';
                }

                if (empty($title)) {
                    return;
                }

                // Extract snippet
                $snippetNode = $node->filter('p, .description, .snippet, span.desc')->first();
                $snippet = $snippetNode->count() > 0 ? trim($snippetNode->text()) : '';

                // Clean up - remove title from snippet if it starts with it
                if (!empty($snippet) && str_starts_with($snippet, $title)) {
                    $snippet = trim(substr($snippet, strlen($title)));
                }

                $displayLink = $this->extractRootDomain($url);

                $results[] = [
                    'link' => $url,
                    'title' => $title,
                    'snippet' => $snippet,
                    'displayLink' => $displayLink,
                ];

            } catch (\Throwable) {
                // Skip malformed results
            }
        });

        $this->logger->debug('QwantScraper: parsed results', [
            'count' => count($results),
        ]);

        return $results;
    }

    // ──────────────────────────────────────────────────────────────────────
    // URL Building
    // ──────────────────────────────────────────────────────────────────────

    private function buildUrl(string $query, ?string $region): string
    {
        $params = [
            'q' => $query,
            't' => 'web',  // Web search
        ];

        // Add region if available
        if ($region && isset(self::REGION_MAP[strtoupper($region)])) {
            $params['r'] = self::REGION_MAP[strtoupper($region)];
        } else {
            $params['r'] = 'en_US';
        }

        return self::BASE_URL . '?' . http_build_query($params);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Rate Limiting
    // ──────────────────────────────────────────────────────────────────────

    private function respectRateLimit(): void
    {
        if ($this->lastRequestTime > 0) {
            $elapsed = microtime(true) - $this->lastRequestTime;
            $required = self::MIN_DELAY_SECONDS + (mt_rand() / mt_getrandmax()) * self::MAX_JITTER_SECONDS;

            if ($elapsed < $required) {
                $sleepMs = (int) (($required - $elapsed) * 1_000_000);
                usleep($sleepMs);
            }
        }
    }

    private function extractRootDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return '';
        }
        return preg_replace('/^www\./', '', strtolower($host));
    }
}
