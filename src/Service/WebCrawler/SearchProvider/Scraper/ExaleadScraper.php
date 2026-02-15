<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Exalead search scraper.
 *
 * Exalead — French enterprise search engine by Dassault Systèmes.
 *   - Owned by Dassault Systèmes (3DEXPERIENCE)
 *   - Has its own web crawler and index
 *   - Excellent for industrial/B2B company discovery
 *   - Strong European manufacturing coverage
 *   - Returns ~10 results per page
 *
 * CSS selectors (verified Feb 2026):
 *   - Result container: div.result
 *   - Title link:       a.ellipsis
 *   - Snippet:          p.snippet
 */
final class ExaleadScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://www.exalead.com/search/web/results/';

    private const MIN_DELAY_SECONDS = 3.0;
    private const MAX_JITTER_SECONDS = 3.0;

    private float $lastRequestTime = 0.0;

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ];

    /** Map region codes to Exalead language codes */
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

        $this->logger->debug('ExaleadScraper: fetching', ['region' => $region]);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9,fr;q=0.8',
                    'DNT' => '1',
                    'Referer' => 'https://www.exalead.com/',
                ],
                'timeout' => 15,
                'max_redirects' => 3,
            ]);

            $statusCode = $response->getStatusCode();
            $this->lastRequestTime = microtime(true);

            if ($statusCode === 403 || $statusCode === 429) {
                throw new \RuntimeException("Exalead returned {$statusCode} — rate limited");
            }

            if ($statusCode >= 400) {
                throw new \RuntimeException("Exalead returned HTTP {$statusCode}");
            }

            $html = $response->getContent();

            return $this->parseResults($html, $maxResults);

        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Exalead search failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getEngineName(): string
    {
        return 'exalead';
    }

    private function parseResults(string $html, int $maxResults): array
    {
        $crawler = new Crawler($html);
        $results = [];

        $resultItems = $crawler->filter('div.result, article.result, li.result');

        if ($resultItems->count() === 0) {
            $resultItems = $crawler->filter('a.ellipsis, a[href^="http"]')->closest('div');
        }

        $seenUrls = [];

        $resultItems->each(function (Crawler $node) use (&$results, &$seenUrls, $maxResults) {
            if (count($results) >= $maxResults) {
                return;
            }

            try {
                $linkNode = $node->filter('a.ellipsis, h3 a, a[href^="http"]')->first();
                if ($linkNode->count() === 0) {
                    return;
                }

                $url = $linkNode->attr('href');
                if (!$url || !str_starts_with($url, 'http')) {
                    return;
                }

                if (str_contains($url, 'exalead.com') || isset($seenUrls[$url])) {
                    return;
                }
                $seenUrls[$url] = true;

                $title = trim($linkNode->text());
                if (empty($title)) {
                    return;
                }

                $snippetNode = $node->filter('p.snippet, .snippet, p')->first();
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

        $this->logger->debug('ExaleadScraper: parsed results', ['count' => count($results)]);
        return $results;
    }

    private function buildUrl(string $query, ?string $region): string
    {
        $params = ['q' => $query];

        if ($region && isset(self::LANG_MAP[strtoupper($region)])) {
            $params['language'] = self::LANG_MAP[strtoupper($region)];
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
