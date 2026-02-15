<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * MetaGer search scraper.
 *
 * MetaGer — German non-profit privacy-focused metasearch engine.
 *   - Run by SUMA-EV (German non-profit)
 *   - Aggregates results from multiple sources (Bing, Yahoo, others)
 *   - Excellent for German/European B2B discovery
 *   - Strong privacy focus
 *   - Returns ~20 results per query
 *
 * CSS selectors (verified Feb 2026):
 *   - Result container: div.result
 *   - Title link:       a.result-title
 *   - Snippet:          p.result-description
 */
final class MetaGerScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://metager.org/meta/meta.ger3';

    private const MIN_DELAY_SECONDS = 3.0;
    private const MAX_JITTER_SECONDS = 3.0;

    private float $lastRequestTime = 0.0;

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ];

    /** Map region codes to MetaGer focus parameters */
    private const LANG_MAP = [
        'DE' => 'de',
        'FR' => 'fr',
        'IT' => 'it',
        'ES' => 'es',
        'GB' => 'en',
        'UK' => 'en',
        'NL' => 'nl',
        'PL' => 'pl',
        'US' => 'en',
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

        $this->logger->debug('MetaGerScraper: fetching', ['region' => $region]);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7',
                    'DNT' => '1',
                    'Referer' => 'https://metager.org/',
                ],
                'timeout' => 20, // MetaGer aggregates so can be slower
                'max_redirects' => 3,
            ]);

            $statusCode = $response->getStatusCode();
            $this->lastRequestTime = microtime(true);

            if ($statusCode === 403 || $statusCode === 429) {
                throw new \RuntimeException("MetaGer returned {$statusCode} — rate limited");
            }

            if ($statusCode >= 400) {
                throw new \RuntimeException("MetaGer returned HTTP {$statusCode}");
            }

            $html = $response->getContent();

            return $this->parseResults($html, $maxResults);

        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException("MetaGer search failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getEngineName(): string
    {
        return 'metager';
    }

    private function parseResults(string $html, int $maxResults): array
    {
        $crawler = new Crawler($html);
        $results = [];

        $resultItems = $crawler->filter('div.result, article.result, .search-result');

        if ($resultItems->count() === 0) {
            $resultItems = $crawler->filter('a.result-title')->closest('div');
        }

        $seenUrls = [];

        $resultItems->each(function (Crawler $node) use (&$results, &$seenUrls, $maxResults) {
            if (count($results) >= $maxResults) {
                return;
            }

            try {
                $linkNode = $node->filter('a.result-title, h2 a, a[href^="http"]')->first();
                if ($linkNode->count() === 0) {
                    return;
                }

                $url = $linkNode->attr('href');
                
                // MetaGer may proxy URLs
                if (str_contains($url, 'metager.org') && str_contains($url, 'url=')) {
                    preg_match('/url=([^&]+)/', $url, $matches);
                    if (!empty($matches[1])) {
                        $url = urldecode($matches[1]);
                    }
                }

                if (!$url || !str_starts_with($url, 'http')) {
                    return;
                }

                if (str_contains($url, 'metager.org') || str_contains($url, 'metager.de') || isset($seenUrls[$url])) {
                    return;
                }
                $seenUrls[$url] = true;

                $title = trim($linkNode->text());
                if (empty($title)) {
                    return;
                }

                $snippetNode = $node->filter('p.result-description, .description, p')->first();
                $snippet = $snippetNode->count() > 0 ? trim($snippetNode->text()) : '';

                $results[] = [
                    'link' => $url,
                    'title' => $title,
                    'snippet' => $snippet,
                    'displayLink' => $this->extractRootDomain($url),
                ];
            } catch (\Throwable) {
            }
        });

        $this->logger->debug('MetaGerScraper: parsed results', ['count' => count($results)]);
        return $results;
    }

    private function buildUrl(string $query, ?string $region): string
    {
        $params = [
            'eingabe' => $query,
            'focus' => 'web',
        ];

        if ($region && isset(self::LANG_MAP[strtoupper($region)])) {
            $params['spression'] = self::LANG_MAP[strtoupper($region)];
        }

        return self::BASE_URL . '?' . http_build_query($params);
    }

    private function respectRateLimit(): void
    {
        if ($this->lastRequestTime > 0) {
            $elapsed = microtime(true) - $this->lastRequestTime;
            $required = self::MIN_DELAY_SECONDS + (mt_rand() / mt_getrandmax()) * self::MAX_JITTER_SECONDS;
            if ($elapsed < $required) {
                usleep((int)(($required - $elapsed) * 1_000_000));
            }
        }
    }

    private function extractRootDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return $host ? preg_replace('/^www\./', '', strtolower($host)) : '';
    }
}
