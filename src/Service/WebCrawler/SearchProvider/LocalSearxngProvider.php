<?php

namespace App\Service\WebCrawler\SearchProvider;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Self-hosted SearXNG search provider.
 *
 * Replaces both Google CSE (paid) and the 28-engine ScrapingSearchProvider
 * with a single self-hosted SearXNG instance that aggregates Google, Bing,
 * DuckDuckGo, Brave, Mojeek, Startpage, Yahoo, and Qwant — all for free
 * with no rate limits.
 *
 * SearXNG runs on localhost:8888 as a systemd service, so:
 *   - No proxy needed (SearXNG makes its own outbound requests)
 *   - No API keys needed
 *   - No rate limits (it's our own instance)
 *   - ~40-50 results per query (aggregated + deduplicated)
 *   - Each upstream engine sees the server IP, not a scraper pattern
 */
final class LocalSearxngProvider implements SearchProviderInterface
{
    private const DEFAULT_BASE_URL = 'http://127.0.0.1:8888';
    private const REQUEST_TIMEOUT = 20;

    /** Consecutive failure tracking for health check */
    private int $consecutiveFailures = 0;
    private const MAX_FAILURES_BEFORE_UNAVAILABLE = 10;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::SEARXNG_BASE_URL)%')]
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
    }

    public function search(
        string $query,
        ?string $region = null,
        ?string $language = null,
        int $maxResults = 10,
        int $startIndex = 1,
    ): SearchResultSet {
        $startTime = microtime(true);

        // Map startIndex to SearXNG page number (SearXNG uses pageno=1,2,3...)
        // Our startIndex is 1-based: 1=page1, 21=page2, 41=page3
        $pageNo = max(1, (int) ceil($startIndex / 20));

        $params = [
            'q' => $query,
            'format' => 'json',
            'language' => $this->mapRegionToLanguage($region),
            'safesearch' => '0',
            'pageno' => (string) $pageNo,
            // Don't restrict engines here — use SearXNG's settings.yml config
        ];

        $url = rtrim($this->baseUrl, '/') . '/search';

        try {
            $response = $this->httpClient->request('GET', $url, [
                'query' => $params,
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->consecutiveFailures++;
                $this->logger->warning('LocalSearxngProvider: non-200 response', [
                    'status' => $statusCode,
                    'query' => mb_substr($query, 0, 80),
                ]);
                return $this->emptyResult($query, $startTime);
            }

            $data = $response->toArray();
            $rawResults = $data['results'] ?? [];

            // Convert SearXNG results to SearchResult objects
            $results = [];
            $seen = []; // Deduplicate by root domain
            foreach ($rawResults as $item) {
                if (count($results) >= $maxResults) {
                    break;
                }

                $itemUrl = $item['url'] ?? '';
                if (empty($itemUrl)) {
                    continue;
                }

                $host = parse_url($itemUrl, PHP_URL_HOST);
                if (!$host) {
                    continue;
                }

                // Deduplicate by root domain
                $rootDomain = strtolower(preg_replace('/^www\./', '', $host));
                if (isset($seen[$rootDomain])) {
                    continue;
                }
                $seen[$rootDomain] = true;

                $results[] = new SearchResult(
                    url: $itemUrl,
                    title: $item['title'] ?? '',
                    snippet: $item['content'] ?? '',
                    displayLink: $host,
                    metadata: [
                        'engines' => $item['engines'] ?? [],
                        'score' => $item['score'] ?? 0,
                    ],
                );
            }

            $elapsed = microtime(true) - $startTime;
            $this->consecutiveFailures = 0;

            $this->logger->info('LocalSearxngProvider: success', [
                'query' => mb_substr($query, 0, 80),
                'results' => count($results),
                'raw_results' => count($rawResults),
                'elapsed' => round($elapsed, 3),
                'total_results' => $data['number_of_results'] ?? 0,
            ]);

            return new SearchResultSet(
                results: $results,
                totalResults: (int) ($data['number_of_results'] ?? 0),
                searchTimeSeconds: $elapsed,
                providerName: $this->getProviderName(),
                query: $query,
                metadata: [
                    'engine' => 'local_searxng',
                    'page' => $pageNo,
                ],
            );

        } catch (\Throwable $e) {
            $this->consecutiveFailures++;
            $elapsed = microtime(true) - $startTime;

            $this->logger->error('LocalSearxngProvider: request failed', [
                'error' => $e->getMessage(),
                'query' => mb_substr($query, 0, 80),
                'elapsed' => round($elapsed, 3),
                'consecutive_failures' => $this->consecutiveFailures,
            ]);

            return $this->emptyResult($query, $startTime);
        }
    }

    public function getProviderName(): string
    {
        return 'local_searxng';
    }

    public function isAvailable(): bool
    {
        // After many consecutive failures, report unavailable
        if ($this->consecutiveFailures >= self::MAX_FAILURES_BEFORE_UNAVAILABLE) {
            return false;
        }

        // Quick health check: try to reach the SearXNG instance
        try {
            $response = $this->httpClient->request('GET', rtrim($this->baseUrl, '/') . '/', [
                'timeout' => 3,
            ]);
            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    private function emptyResult(string $query, float $startTime): SearchResultSet
    {
        return new SearchResultSet(
            results: [],
            totalResults: 0,
            searchTimeSeconds: microtime(true) - $startTime,
            providerName: $this->getProviderName(),
            query: $query,
        );
    }

    private function mapRegionToLanguage(?string $region): string
    {
        return match ($region) {
            'DE' => 'de-DE',
            'FR' => 'fr-FR',
            'ES' => 'es-ES',
            'IT' => 'it-IT',
            'PT' => 'pt-PT',
            'NL' => 'nl-NL',
            'PL' => 'pl-PL',
            'CZ' => 'cs-CZ',
            'RO' => 'ro-RO',
            'HU' => 'hu-HU',
            'SE' => 'sv-SE',
            'NO' => 'nb-NO',
            'DK' => 'da-DK',
            'FI' => 'fi-FI',
            'GR' => 'el-GR',
            'TR' => 'tr-TR',
            'EG' => 'ar-EG',
            'MA' => 'fr-FR',
            'TN' => 'fr-FR',
            'DZ' => 'fr-FR',
            'SA' => 'ar-SA',
            'AE' => 'ar-AE',
            'JP' => 'ja-JP',
            'KR' => 'ko-KR',
            'CN' => 'zh-CN',
            default => 'en',
        };
    }
}
