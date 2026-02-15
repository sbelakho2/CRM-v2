<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Ecosia search scraper.
 *
 * Ecosia is a German eco-friendly search engine that plants trees.
 *   - Uses Bing's index for search results
 *   - Returns 10 results per page
 *   - Good European coverage
 *   - Server-side rendered with clean HTML
 *
 * CSS selectors (verified Feb 2026):
 *   - Result container: div.result, article.result
 *   - Title link:       a.result-title, h2 a
 *   - Snippet:          p.result-snippet, .result-body
 *   - URL:              .result-url, cite
 */
final class EcosiaScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://www.ecosia.org/search';

    private const MIN_DELAY_SECONDS = 3.0;
    private const MAX_JITTER_SECONDS = 3.0;

    private float $lastRequestTime = 0.0;

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ];

    /** Map region codes to Ecosia market codes */
    private const REGION_MAP = [
        'DE' => 'de-DE',
        'FR' => 'fr-FR',
        'IT' => 'it-IT',
        'ES' => 'es-ES',
        'GB' => 'en-GB',
        'UK' => 'en-GB',
        'NL' => 'nl-NL',
        'PL' => 'pl-PL',
        'AT' => 'de-AT',
        'CH' => 'de-CH',
        'BE' => 'fr-BE',
        'SE' => 'sv-SE',
        'NO' => 'nb-NO',
        'DK' => 'da-DK',
        'FI' => 'fi-FI',
        'PT' => 'pt-PT',
        'CZ' => 'cs-CZ',
        'US' => 'en-US',
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

        $this->logger->debug('EcosiaScraper: fetching', [
            'region' => $region,
        ]);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9,de;q=0.8',
                    'DNT' => '1',
                    'Referer' => 'https://www.ecosia.org/',
                ],
                'timeout' => 15,
                'max_redirects' => 3,
            ]);

            $statusCode = $response->getStatusCode();
            $this->lastRequestTime = microtime(true);

            if ($statusCode === 403 || $statusCode === 429) {
                throw new \RuntimeException("Ecosia returned {$statusCode} — rate limited");
            }

            if ($statusCode >= 400) {
                throw new \RuntimeException("Ecosia returned HTTP {$statusCode}");
            }

            $html = $response->getContent();

            // Check for CAPTCHA or blocking
            if (str_contains($html, 'captcha') || str_contains($html, 'blocked')) {
                throw new \RuntimeException('Ecosia CAPTCHA or block detected');
            }

            return $this->parseResults($html, $maxResults);

        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Ecosia search failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getEngineName(): string
    {
        return 'ecosia';
    }

    // ──────────────────────────────────────────────────────────────────────
    // HTML Parsing
    // ──────────────────────────────────────────────────────────────────────

    private function parseResults(string $html, int $maxResults): array
    {
        $crawler = new Crawler($html);
        $results = [];

        // Ecosia results are in article or div elements with class 'result'
        $resultItems = $crawler->filter('article.result, div.result, [data-test-id="mainline-result-web"]');

        if ($resultItems->count() === 0) {
            // Fallback: look for result links
            $resultItems = $crawler->filter('a.result-title, a.result-url')->closest('article, div');
        }

        $seenUrls = [];

        $resultItems->each(function (Crawler $node) use (&$results, &$seenUrls, $maxResults) {
            if (count($results) >= $maxResults) {
                return;
            }

            try {
                // Find the main title link
                $linkNode = $node->filter('a.result-title, a.result__title, h2 a, a[href^="http"]')->first();
                if ($linkNode->count() === 0) {
                    return;
                }

                $url = $linkNode->attr('href');
                if (!$url || !str_starts_with($url, 'http')) {
                    return;
                }

                // Skip Ecosia's own URLs and duplicates
                if (str_contains($url, 'ecosia.org') || isset($seenUrls[$url])) {
                    return;
                }
                $seenUrls[$url] = true;

                // Get title
                $title = trim($linkNode->text());
                if (empty($title)) {
                    return;
                }

                // Extract snippet
                $snippetNode = $node->filter('p.result-snippet, .result-body, .result__snippet, p')->first();
                $snippet = $snippetNode->count() > 0 ? trim($snippetNode->text()) : '';

                // Extract display URL
                $urlNode = $node->filter('.result-url, cite, .result__url')->first();
                $displayLink = '';
                if ($urlNode->count() > 0) {
                    $displayLink = trim($urlNode->text());
                    // Clean up display link - sometimes contains full path
                    $displayLink = preg_replace('/\/.*$/', '', $displayLink);
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

        $this->logger->debug('EcosiaScraper: parsed results', [
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
            'method' => 'index',
        ];

        // Add market/region parameter
        if ($region && isset(self::REGION_MAP[strtoupper($region)])) {
            $params['mkt'] = self::REGION_MAP[strtoupper($region)];
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
