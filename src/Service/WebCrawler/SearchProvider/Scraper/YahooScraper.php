<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Yahoo search scraper.
 *
 * Yahoo Search — uses Bing's index but different ranking/interface.
 *   - Third largest search engine globally
 *   - Returns 10 results per page
 *   - Good for B2B discovery (different ranking than Bing direct)
 *   - Server-side rendered
 *
 * CSS selectors (verified Feb 2026):
 *   - Result container: div.algo, div.dd
 *   - Title link:       h3 a, a.ac-algo
 *   - Snippet:          p, .compText
 */
final class YahooScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://search.yahoo.com/search';

    private const MIN_DELAY_SECONDS = 3.0;
    private const MAX_JITTER_SECONDS = 3.0;

    private float $lastRequestTime = 0.0;

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
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

        $this->logger->debug('YahooScraper: fetching', ['region' => $region]);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'DNT' => '1',
                ],
                'timeout' => 15,
                'max_redirects' => 5,
            ]);

            $statusCode = $response->getStatusCode();
            $this->lastRequestTime = microtime(true);

            if ($statusCode === 403 || $statusCode === 429) {
                throw new \RuntimeException("Yahoo returned {$statusCode} — rate limited");
            }

            if ($statusCode >= 400) {
                throw new \RuntimeException("Yahoo returned HTTP {$statusCode}");
            }

            $html = $response->getContent();

            if (str_contains($html, 'captcha') || str_contains($html, 'robot')) {
                throw new \RuntimeException('Yahoo CAPTCHA detected');
            }

            return $this->parseResults($html, $maxResults);

        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Yahoo search failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getEngineName(): string
    {
        return 'yahoo';
    }

    private function parseResults(string $html, int $maxResults): array
    {
        $crawler = new Crawler($html);
        $results = [];

        // Yahoo results in div.algo or similar containers
        $resultItems = $crawler->filter('div.algo, div.dd.algo, li.algo');

        if ($resultItems->count() === 0) {
            $resultItems = $crawler->filter('[data-pos] h3 a')->closest('div');
        }

        $seenUrls = [];

        $resultItems->each(function (Crawler $node) use (&$results, &$seenUrls, $maxResults) {
            if (count($results) >= $maxResults) {
                return;
            }

            try {
                $linkNode = $node->filter('h3 a, a.ac-algo, a[href^="http"]')->first();
                if ($linkNode->count() === 0) {
                    return;
                }

                $url = $linkNode->attr('href');
                
                // Yahoo wraps URLs - extract real URL
                if (str_contains($url, 'yahoo.com/') && str_contains($url, 'RU=')) {
                    preg_match('/RU=([^\/]+)/', $url, $matches);
                    if (!empty($matches[1])) {
                        $url = urldecode($matches[1]);
                    }
                }

                if (!$url || !str_starts_with($url, 'http')) {
                    return;
                }

                if (str_contains($url, 'yahoo.com') || isset($seenUrls[$url])) {
                    return;
                }
                $seenUrls[$url] = true;

                $title = trim($linkNode->text());
                if (empty($title)) {
                    return;
                }

                $snippetNode = $node->filter('p, .compText, .fc-falcon')->first();
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

        $this->logger->debug('YahooScraper: parsed results', ['count' => count($results)]);
        return $results;
    }

    private function buildUrl(string $query): string
    {
        return self::BASE_URL . '?' . http_build_query(['p' => $query]);
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
