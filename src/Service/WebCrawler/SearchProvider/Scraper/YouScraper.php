<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * You.com search scraper.
 *
 * You.com — AI-enhanced search engine.
 *   - Modern search engine with its own index + AI features
 *   - Returns quality results with good B2B coverage
 *   - Privacy-focused
 *   - Server-side rendered option available
 *
 * CSS selectors (verified Feb 2026):
 *   - Result container: div[data-testid="web-result"]
 *   - Title link:       a[data-testid="result-title"]
 *   - Snippet:          span[data-testid="result-snippet"]
 */
final class YouScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://you.com/search';

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

        $this->logger->debug('YouScraper: fetching', ['region' => $region]);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'DNT' => '1',
                    'Referer' => 'https://you.com/',
                ],
                'timeout' => 15,
                'max_redirects' => 3,
            ]);

            $statusCode = $response->getStatusCode();
            $this->lastRequestTime = microtime(true);

            if ($statusCode === 403 || $statusCode === 429) {
                throw new \RuntimeException("You.com returned {$statusCode} — rate limited");
            }

            if ($statusCode >= 400) {
                throw new \RuntimeException("You.com returned HTTP {$statusCode}");
            }

            $html = $response->getContent();

            return $this->parseResults($html, $maxResults);

        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException("You.com search failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getEngineName(): string
    {
        return 'you';
    }

    private function parseResults(string $html, int $maxResults): array
    {
        $crawler = new Crawler($html);
        $results = [];

        // You.com results in data-testid elements
        $resultItems = $crawler->filter('[data-testid="web-result"], div.search-result, .web-result');

        if ($resultItems->count() === 0) {
            // Fallback to links within result containers
            $resultItems = $crawler->filter('a[href^="http"]:not([href*="you.com"])')->closest('div');
        }

        $seenUrls = [];

        $resultItems->each(function (Crawler $node) use (&$results, &$seenUrls, $maxResults) {
            if (count($results) >= $maxResults) {
                return;
            }

            try {
                $linkNode = $node->filter('a[data-testid="result-title"], a[href^="http"]')->first();
                if ($linkNode->count() === 0) {
                    return;
                }

                $url = $linkNode->attr('href');
                if (!$url || !str_starts_with($url, 'http')) {
                    return;
                }

                if (str_contains($url, 'you.com') || isset($seenUrls[$url])) {
                    return;
                }
                $seenUrls[$url] = true;

                $title = trim($linkNode->text());
                if (empty($title) || strlen($title) < 3) {
                    return;
                }

                $snippetNode = $node->filter('[data-testid="result-snippet"], p, .snippet')->first();
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

        $this->logger->debug('YouScraper: parsed results', ['count' => count($results)]);
        return $results;
    }

    private function buildUrl(string $query): string
    {
        return self::BASE_URL . '?' . http_build_query([
            'q' => $query,
            'tbm' => 'web', // Web results only
        ]);
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
