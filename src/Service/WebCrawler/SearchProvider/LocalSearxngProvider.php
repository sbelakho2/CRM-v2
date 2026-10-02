<?php

namespace App\Service\WebCrawler\SearchProvider;

use App\Service\GoogleSearchService;
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
 *
 * Extends GoogleCSEProvider so the interface contract (SearchProviderInterface
 * resolves to a GoogleCSEProvider instance, provider name 'google_cse') is
 * preserved regardless of which engine is active.
 */
final class LocalSearxngProvider extends GoogleCSEProvider
{
    private const DEFAULT_BASE_URL = 'http://127.0.0.1:8888';
    private const REQUEST_TIMEOUT = 20;

    /** Consecutive failure tracking for health check */
    private int $consecutiveFailures = 0;
    private const MAX_FAILURES_BEFORE_UNAVAILABLE = 10;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        LoggerInterface $logger,
        // `default::` without a named fallback resolves to null when the env
        // var is unset, which breaks container compilation (string arg). Use
        // a named parameter fallback equal to the historical default.
        #[Autowire('%env(default:app.searxng_base_url_fallback:SEARXNG_BASE_URL)%')]
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
        parent::__construct(
            new GoogleSearchService(
                $this->httpClient,
                $logger,
                (string) getenv('GOOGLE_API_KEY'),
                (string) getenv('GOOGLE_SEARCH_ENGINE_ID'),
            ),
            $logger,
        );
    }

    public function search(
        string $query,
        ?string $region = null,
        ?string $language = null,
        int $maxResults = 10,
        int $startIndex = 1,
    ): SearchResultSet {
        $startTime = microtime(true);

        // Strip quotes around single words — SearXNG's site: operator breaks
        // when a single word is quoted (e.g. site:kerix.net "automotive" returns 0).
        $query = preg_replace('/"(\w+)"/u', '$1', $query) ?? $query;

        // Map startIndex to SearXNG page number (SearXNG uses pageno=1,2,3...)
        // Our startIndex is 1-based: 1=page1, 21=page2, 41=page3
        $pageNo = max(1, (int) ceil($startIndex / 20));

        $params = [
            'q' => $query,
            'format' => 'json',
            'language' => $this->mapRegionToLanguage($region),
            'safesearch' => '0',
            'pageno' => (string) $pageNo,
            // Only use reliable engines for B2B search.
            // google: SUSPENDED (access denied) — removed until IPRoyal proxy is rotated
            // bing: good for B2B queries, supports site: operator
            // brave: decent general results, supports site: operator
            // yahoo: useful for directory results
            // qwant: SUSPENDED (access denied) — removed
            // mojeek: SUSPENDED (access denied) — removed
            // Intentionally excluded: startpage (returns too much noise/wikipedia/reddit),
            // duckduckgo (CAPTCHA-blocked on Hetzner IPs), yep (low quality)
            'engines' => 'bing,brave,yahoo',
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
            if (!is_array($rawResults)) {
                $rawResults = [];
            }
            $numberOfResultsRaw = $data['number_of_results'] ?? 0;
            $numberOfResults = is_numeric($numberOfResultsRaw) ? (int) $numberOfResultsRaw : 0;

            // Convert SearXNG results to SearchResult objects.
            // NOTE: We do NOT deduplicate by root domain here because:
            // 1. CandidateCollector handles all deduplication (domain-based for normal
            //    domains, URL-based for known directory domains like kerix.net).
            // 2. For site: directory queries, ALL results share the same root domain
            //    (e.g. kerix.net), and we need every one — each is a different company
            //    profile page for seed extraction.
            $results = [];
            foreach ($rawResults as $item) {
                if (count($results) >= $maxResults) {
                    break;
                }
                if (!is_array($item)) {
                    continue;
                }

                $itemUrl = $item['url'] ?? '';
                if (!is_string($itemUrl) || $itemUrl === '') {
                    continue;
                }

                $host = parse_url($itemUrl, PHP_URL_HOST);
                if (!is_string($host) || $host === '') {
                    continue;
                }

                $results[] = new SearchResult(
                    url: $itemUrl,
                    title: isset($item['title']) && is_string($item['title']) ? $item['title'] : '',
                    snippet: isset($item['content']) && is_string($item['content']) ? $item['content'] : '',
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
                'total_results' => $numberOfResults,
            ]);

            return new SearchResultSet(
                results: $results,
                totalResults: $numberOfResults,
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
        // Contractual name: SearchProviderInterface consumers expect 'google_cse'
        // (see SearchProviderTest). The actual engine is reported per-request in
        // SearchResultSet metadata ('engine' => 'local_searxng').
        return 'google_cse';
    }

    public function isAvailable(): bool
    {
        // Health gate: after many consecutive failures, report unavailable.
        // The gate resets on the next successful search() call.
        // No outbound probe here — the provider is considered available until
        // it actually starts failing (search() is the probe).
        return $this->consecutiveFailures < self::MAX_FAILURES_BEFORE_UNAVAILABLE;
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

    /**
     * Map region code to SearXNG language parameter.
     *
     * For North African countries (MA, TN, DZ), we use 'en' instead of 'fr-FR'
     * because B2B manufacturing content targeted by our site: directory queries
     * (kerix.net, charika.ma, pagesjaunes.ma, etc.) is predominantly in English.
     * Using 'fr-FR' with SearXNG's language filter was causing those results to
     * be suppressed (returning 0 results), while 'en' returns the same directory
     * pages with full company listings regardless of the page's display language.
     */
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
            // North Africa — use English for B2B directory search results
            'MA' => 'en',
            'TN' => 'en',
            'DZ' => 'en',
            'SA' => 'ar-SA',
            'AE' => 'ar-AE',
            'JP' => 'ja-JP',
            'KR' => 'ko-KR',
            'CN' => 'zh-CN',
            default => 'en',
        };
    }
}
