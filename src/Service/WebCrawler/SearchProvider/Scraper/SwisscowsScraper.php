<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Swisscows search scraper.
 *
 * Swisscows — Swiss privacy-focused family-safe search engine.
 *   - Based in Switzerland (strong privacy laws)
 *   - Uses Bing index for web results
 *   - Good European coverage
 *   - Returns ~10 results per page
 *   - Server-side rendered
 *
 * CSS selectors (verified Feb 2026):
 *   - Result container: article.web-results__item
 *   - Title link:       a.site-results__title
 *   - Snippet:          p.site-results__description
 */
final class SwisscowsScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://swisscows.com/en/web';

    private const MIN_DELAY_SECONDS = 3.0;
    private const MAX_JITTER_SECONDS = 3.0;

    private float $lastRequestTime = 0.0;

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ];

    /** Map region codes to Swisscows region codes */
    private const REGION_MAP = [
        'DE' => 'de-DE',
        'FR' => 'fr-FR',
        'IT' => 'it-IT',
        'ES' => 'es-ES',
        'GB' => 'en-GB',
        'UK' => 'en-GB',
        'CH' => 'de-CH',
        'AT' => 'de-AT',
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

        $this->logger->debug('SwisscowsScraper: fetching', ['region' => $region]);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9,de;q=0.8',
                    'DNT' => '1',
                    'Referer' => 'https://swisscows.com/',
                ],
                'timeout' => 15,
                'max_redirects' => 3,
            ]);

            $statusCode = $response->getStatusCode();
            $this->lastRequestTime = microtime(true);

            if ($statusCode === 403 || $statusCode === 429) {
                throw new \RuntimeException("Swisscows returned {$statusCode} — rate limited");
            }

            if ($statusCode >= 400) {
                throw new \RuntimeException("Swisscows returned HTTP {$statusCode}");
            }

            $html = $response->getContent();

            return $this->parseResults($html, $maxResults);

        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Swisscows search failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getEngineName(): string
    {
        return 'swisscows';
    }

    private function parseResults(string $html, int $maxResults): array
    {
        $crawler = new Crawler($html);
        $results = [];

        $resultItems = $crawler->filter('article.web-results__item, .web-results article, .item-web');

        if ($resultItems->count() === 0) {
            $resultItems = $crawler->filter('a.site-results__title, a[href^="http"]')->closest('article, div');
        }

        $seenUrls = [];

        $resultItems->each(function (Crawler $node) use (&$results, &$seenUrls, $maxResults) {
            if (count($results) >= $maxResults) {
                return;
            }

            try {
                $linkNode = $node->filter('a.site-results__title, a[href^="http"]')->first();
                if ($linkNode->count() === 0) {
                    return;
                }

                $url = $linkNode->attr('href');
                if (!$url || !str_starts_with($url, 'http')) {
                    return;
                }

                if (str_contains($url, 'swisscows.com') || isset($seenUrls[$url])) {
                    return;
                }
                $seenUrls[$url] = true;

                $title = trim($linkNode->text());
                if (empty($title)) {
                    return;
                }

                $snippetNode = $node->filter('p.site-results__description, p, .description')->first();
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

        $this->logger->debug('SwisscowsScraper: parsed results', ['count' => count($results)]);
        return $results;
    }

    private function buildUrl(string $query, ?string $region): string
    {
        $params = ['query' => $query];

        if ($region && isset(self::REGION_MAP[strtoupper($region)])) {
            $params['region'] = self::REGION_MAP[strtoupper($region)];
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
