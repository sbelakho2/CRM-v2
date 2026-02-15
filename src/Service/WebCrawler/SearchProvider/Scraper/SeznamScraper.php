<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Seznam search scraper.
 *
 * Seznam — Czech Republic's dominant search engine.
 *   - Has its own independent web index
 *   - Excellent coverage of Czech Republic and Eastern Europe
 *   - Returns 10 results per page
 *   - Useful for finding Eastern European manufacturing companies
 *   - Server-side rendered
 *
 * CSS selectors (verified Feb 2026):
 *   - Result container: div.Result
 *   - Title link:       h3 a, a.Result-title
 *   - Snippet:          p.Result-description
 */
final class SeznamScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://search.seznam.cz/';

    private const MIN_DELAY_SECONDS = 3.0;
    private const MAX_JITTER_SECONDS = 3.0;

    private float $lastRequestTime = 0.0;

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function scrapeResults(string $query, ?string $region = null, int $maxResults = 10): array
    {
        $this->respectRateLimit();

        $url = $this->buildUrl($query);
        $userAgent = self::USER_AGENTS[array_rand(self::USER_AGENTS)];

        $this->logger->debug('SeznamScraper: fetching', ['region' => $region]);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'cs-CZ,cs;q=0.9,en;q=0.8',
                    'DNT' => '1',
                    'Referer' => 'https://www.seznam.cz/',
                ],
                'timeout' => 15,
                'max_redirects' => 3,
            ]);

            $statusCode = $response->getStatusCode();
            $this->lastRequestTime = microtime(true);

            if ($statusCode === 403 || $statusCode === 429) {
                throw new \RuntimeException("Seznam returned {$statusCode} — rate limited");
            }

            if ($statusCode >= 400) {
                throw new \RuntimeException("Seznam returned HTTP {$statusCode}");
            }

            $html = $response->getContent();

            if (str_contains($html, 'captcha')) {
                throw new \RuntimeException('Seznam CAPTCHA detected');
            }

            return $this->parseResults($html, $maxResults);

        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Seznam search failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getEngineName(): string
    {
        return 'seznam';
    }

    private function parseResults(string $html, int $maxResults): array
    {
        $crawler = new Crawler($html);
        $results = [];

        $resultItems = $crawler->filter('div.Result, article.Result, .result, [data-dot="result"]');

        if ($resultItems->count() === 0) {
            $resultItems = $crawler->filter('h3 a[href^="http"]')->closest('div');
        }

        $seenUrls = [];

        $resultItems->each(function (Crawler $node) use (&$results, &$seenUrls, $maxResults) {
            if (count($results) >= $maxResults) {
                return;
            }

            try {
                $linkNode = $node->filter('h3 a, a.Result-title, a[href^="http"]')->first();
                if ($linkNode->count() === 0) {
                    return;
                }

                $url = $linkNode->attr('href');
                
                // Seznam may redirect through their tracker
                if (str_contains($url, 'seznam.cz') && str_contains($url, 'url=')) {
                    preg_match('/url=([^&]+)/', $url, $matches);
                    if (!empty($matches[1])) {
                        $url = urldecode($matches[1]);
                    }
                }

                if (!$url || !str_starts_with($url, 'http')) {
                    return;
                }

                if (str_contains($url, 'seznam.cz') || isset($seenUrls[$url])) {
                    return;
                }
                $seenUrls[$url] = true;

                $title = trim($linkNode->text());
                if (empty($title)) {
                    return;
                }

                $snippetNode = $node->filter('p.Result-description, .description, p')->first();
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

        $this->logger->debug('SeznamScraper: parsed results', ['count' => count($results)]);
        return $results;
    }

    private function buildUrl(string $query): string
    {
        return self::BASE_URL . '?' . http_build_query(['q' => $query]);
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
