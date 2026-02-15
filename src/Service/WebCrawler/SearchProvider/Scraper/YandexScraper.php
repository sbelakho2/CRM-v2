<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Yandex search scraper.
 *
 * Yandex is Russia's dominant search engine with strong European coverage.
 *   - Returns 10 results per page
 *   - Server-side rendered HTML (no JS required for basic results)
 *   - Supports language/region parameters
 *   - Good coverage of European B2B/industrial sites
 *
 * CSS selectors (verified Feb 2026):
 *   - Result container: li.serp-item
 *   - Title link:       a.OrganicTitle-Link, a.Link
 *   - Snippet:          span.OrganicTextContentSpan, div.text-container
 */
final class YandexScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://yandex.com/search/';

    private const MIN_DELAY_SECONDS = 3.0;
    private const MAX_JITTER_SECONDS = 3.0;

    private float $lastRequestTime = 0.0;

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ];

    /** Map region codes to Yandex lr (location region) codes */
    private const REGION_MAP = [
        'DE' => 96,   // Germany
        'FR' => 124,  // France
        'IT' => 205,  // Italy
        'ES' => 204,  // Spain
        'GB' => 102,  // United Kingdom
        'UK' => 102,
        'NL' => 118,  // Netherlands
        'PL' => 120,  // Poland
        'AT' => 113,  // Austria
        'CH' => 126,  // Switzerland
        'BE' => 114,  // Belgium
        'CZ' => 125,  // Czech Republic
        'SE' => 127,  // Sweden
        'NO' => 119,  // Norway
        'DK' => 203,  // Denmark
        'FI' => 123,  // Finland
        'PT' => 121,  // Portugal
        'RU' => 225,  // Russia
        'US' => 84,   // United States
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

        $this->logger->debug('YandexScraper: fetching', [
            'region' => $region,
        ]);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9,de;q=0.8',
                    'DNT' => '1',
                    'Referer' => 'https://yandex.com/',
                ],
                'timeout' => 15,
                'max_redirects' => 3,
            ]);

            $statusCode = $response->getStatusCode();
            $this->lastRequestTime = microtime(true);

            if ($statusCode === 403 || $statusCode === 429) {
                throw new \RuntimeException("Yandex returned {$statusCode} — rate limited or blocked");
            }

            if ($statusCode >= 400) {
                throw new \RuntimeException("Yandex returned HTTP {$statusCode}");
            }

            $html = $response->getContent();

            // Check for CAPTCHA
            if (str_contains($html, 'captcha') || str_contains($html, 'SmartCaptcha')) {
                throw new \RuntimeException('Yandex CAPTCHA detected');
            }

            return $this->parseResults($html, $maxResults);

        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Yandex search failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getEngineName(): string
    {
        return 'yandex';
    }

    // ──────────────────────────────────────────────────────────────────────
    // HTML Parsing
    // ──────────────────────────────────────────────────────────────────────

    private function parseResults(string $html, int $maxResults): array
    {
        $crawler = new Crawler($html);
        $results = [];

        // Yandex organic results are in li.serp-item elements
        $resultItems = $crawler->filter('li.serp-item');

        if ($resultItems->count() === 0) {
            // Fallback selector for different layouts
            $resultItems = $crawler->filter('div[data-cid] a.OrganicTitle-Link')->closest('div[data-cid]');
        }

        $resultItems->each(function (Crawler $node) use (&$results, $maxResults) {
            if (count($results) >= $maxResults) {
                return;
            }

            try {
                // Try multiple selectors for the title link
                $linkNode = $node->filter('a.OrganicTitle-Link, a.Link.Link_theme_normal, h2 a')->first();
                if ($linkNode->count() === 0) {
                    return;
                }

                $url = $linkNode->attr('href');
                if (!$url || !str_starts_with($url, 'http')) {
                    return;
                }

                // Skip Yandex's own services
                if (str_contains($url, 'yandex.') || str_contains($url, 'ya.ru')) {
                    return;
                }

                $title = trim($linkNode->text());
                if (empty($title)) {
                    return;
                }

                // Extract snippet
                $snippetNode = $node->filter('.OrganicTextContentSpan, .text-container, .Organic-ContentWrapper span')->first();
                $snippet = $snippetNode->count() > 0 ? trim($snippetNode->text()) : '';

                // Extract display link (visible URL)
                $displayNode = $node->filter('.Path-Item, .Organic-Path, .OrganicTitle-Path')->first();
                $displayLink = '';
                if ($displayNode->count() > 0) {
                    $displayLink = trim($displayNode->text());
                }
                if (empty($displayLink)) {
                    $displayLink = $this->extractRootDomain($url);
                }

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

        $this->logger->debug('YandexScraper: parsed results', [
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
            'text' => $query,
            'lang' => 'en',
        ];

        // Add region code if available
        if ($region && isset(self::REGION_MAP[strtoupper($region)])) {
            $params['lr'] = self::REGION_MAP[strtoupper($region)];
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
