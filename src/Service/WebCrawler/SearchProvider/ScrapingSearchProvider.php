<?php

namespace App\Service\WebCrawler\SearchProvider;

use App\Service\WebCrawler\SearchProvider\Scraper\AlexandriaScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\AolScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\AskScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\BaiduScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\BingScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\BraveSearchScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\DogpileScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\DuckDuckGoScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\EcosiaScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\ExaleadScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\GigablastScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\InfoScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\LycosScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\MarginaliaScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\MetaGerScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\MojeekScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\NaverScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\PresearchScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\QwantScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\RightDaoScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\SearchEngineScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\SearxngSearchEngineScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\SeznamScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\StartpageScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\SwisscowsScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\YahooScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\YandexScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\YepScraper;
use App\Service\WebCrawler\SearchProvider\Scraper\YouScraper;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Multi-engine web scraping search provider — zero-cost drop-in replacement
 * for GoogleCSEProvider, with optional paid API fallback.
 *
 * Architecture (26 free engines + SearXNG 100+ instances + 1 paid fallback):
 *
 *   === TIER 1: HIGH-QUALITY INDEPENDENT INDEXES ===
 *   - Brave Search   (20 results/page, own index, excellent quality)
 *   - SearXNG        (meta-search via 100+ public instances - best anti-rate-limit)
 *   - Mojeek         (independent index, most scraping-tolerant)
 *   - Yep            (Ahrefs' engine, massive independent index)
 *
 *   === TIER 2: MAJOR ENGINES (BING/GOOGLE POWERED) ===
 *   - Bing           (massive index, second largest)
 *   - DuckDuckGo     (Bing-based, privacy-focused)
 *   - Yahoo           (Bing-powered, different ranking)
 *   - Startpage      (Google results proxy)
 *   - AOL            (Bing-powered, separate rate limits)
 *   - Ask.com        (Bing-powered, separate rate limits)
 *   - Dogpile        (meta: Google+Bing+Yahoo)
 *
 *   === TIER 3: EUROPEAN & PRIVACY ENGINES ===
 *   - Qwant          (French, strong EU coverage)
 *   - Ecosia         (German, eco-friendly, Bing index)
 *   - Swisscows      (Swiss privacy, family-safe)
 *   - MetaGer        (German non-profit, aggregates multiple sources)
 *   - Seznam         (Czech, Eastern European coverage)
 *
 *   === TIER 4: SPECIALIST & ALTERNATIVE ENGINES ===
 *   - You.com        (AI-enhanced, modern index)
 *   - Yandex         (Russian, strong European B2B)
 *   - Naver          (Korean, major independent index)
 *   - Baidu          (Chinese, massive independent index)
 *   - Marginalia     (independent, non-commercial focus)
 *   - Presearch      (decentralized, community nodes)
 *   - Alexandria     (independent, Common Crawl based)
 *   - RightDao       (independent, minimal anti-bot)
 *
 *   === TIER 5: LEGACY META-SEARCH ===
 *   - Exalead        (French/Dassault, industrial B2B)
 *   - Gigablast      (independent US index)
 *   - Info.com       (meta-search aggregator)
 *   - Lycos          (Bing-powered, separate rate limits)
 *
 *   === PAID LAST RESORT ===
 *   - Google CSE API ($0.005/query, optional)
 *
 * Anti-rate-limit strategy:
 *   - 27 unique domains = 27 independent rate limit buckets
 *   - SearXNG alone provides 100+ unique IPs via instance rotation
 *   - HeaderRandomizer: random User-Agent, Accept-Language, Sec-CH-UA
 *   - RateLimitManager: per-engine exponential backoff with health scoring
 *   - ProxyRotator: optional proxy rotation for IP diversity
 *   - QueryOptimizer: only strips -site: exclusions, preserves all search syntax
 *
 * @see SearchProviderInterface
 */
final class ScrapingSearchProvider implements SearchProviderInterface
{
    /** @var SearchEngineScraper[] Ordered by priority (primary first) */
    private array $engines;

    /** @var array<string, int> Per-engine consecutive failure count */
    private array $engineFailures = [];

    /** @var array<string, float> Per-engine last failure time */
    private array $engineCooldownUntil = [];

    /** Engine cooldown after repeated failures (seconds) */
    private const ENGINE_COOLDOWN_SECONDS = 300; // 5 minutes

    /** Failures before engine cooldown triggers */
    private const ENGINE_FAILURE_THRESHOLD = 3;

    public function __construct(
        private readonly BraveSearchScraper $braveSearchScraper,
        private readonly SearxngSearchEngineScraper $searxngScraper,
        private readonly MojeekScraper $mojeekScraper,
        private readonly YepScraper $yepScraper,
        private readonly BingScraper $bingScraper,
        private readonly DuckDuckGoScraper $duckDuckGoScraper,
        private readonly YahooScraper $yahooScraper,
        private readonly StartpageScraper $startpageScraper,
        private readonly AolScraper $aolScraper,
        private readonly AskScraper $askScraper,
        private readonly DogpileScraper $dogpileScraper,
        private readonly QwantScraper $qwantScraper,
        private readonly EcosiaScraper $ecosiaScraper,
        private readonly SwisscowsScraper $swisscowsScraper,
        private readonly MetaGerScraper $metaGerScraper,
        private readonly SeznamScraper $seznamScraper,
        private readonly YouScraper $youScraper,
        private readonly YandexScraper $yandexScraper,
        private readonly NaverScraper $naverScraper,
        private readonly BaiduScraper $baiduScraper,
        private readonly MarginaliaScraper $marginaliaScraper,
        private readonly PresearchScraper $presearchScraper,
        private readonly AlexandriaScraper $alexandriaScraper,
        private readonly RightDaoScraper $rightDaoScraper,
        private readonly ExaleadScraper $exaleadScraper,
        private readonly GigablastScraper $gigablastScraper,
        private readonly InfoScraper $infoScraper,
        private readonly LycosScraper $lycosScraper,
        private readonly GoogleCSEProvider $googleCSEProvider,
        private readonly QueryOptimizer $queryOptimizer,
        private readonly RateLimitManager $rateLimitManager,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:ENABLE_GOOGLE_API_FALLBACK)%')]
        private readonly bool $enableGoogleApiFallback = true,
    ) {
        // 27 free engines ordered by tier (quality + reliability + independence)
        // SearXNG alone provides 100+ unique IPs via instance rotation
        // Google CSE API is handled separately after all free engines fail
        $this->engines = [
            // Tier 1: High-quality independent indexes
            $this->braveSearchScraper,     // Primary: 20 results, direct URLs, own index
            $this->searxngScraper,         // 100+ instances, aggregates Google/Bing/DDG
            $this->mojeekScraper,          // Independent index, most scraping-tolerant
            $this->yepScraper,             // Ahrefs index, independent

            // Tier 2: Major engines (Bing/Google powered, separate rate limits)
            $this->bingScraper,            // Massive index, second largest
            $this->duckDuckGoScraper,      // Privacy-focused, Bing-based
            $this->yahooScraper,           // Bing index, different ranking
            $this->startpageScraper,       // Google results via proxy
            $this->aolScraper,             // Bing-powered, separate domain/rate limit
            $this->askScraper,             // Bing-powered, separate domain/rate limit
            $this->dogpileScraper,         // Meta: Google+Bing+Yahoo aggregator

            // Tier 3: European & privacy engines
            $this->qwantScraper,           // French, strong EU coverage
            $this->ecosiaScraper,          // German, eco-friendly
            $this->swisscowsScraper,       // Swiss privacy
            $this->metaGerScraper,         // German non-profit metasearch
            $this->seznamScraper,          // Czech, Eastern European

            // Tier 4: Specialist & alternative engines
            $this->youScraper,             // AI-enhanced, modern
            $this->yandexScraper,          // Russian, strong European B2B
            $this->naverScraper,           // Korean, major independent index
            $this->baiduScraper,           // Chinese, massive independent index
            $this->marginaliaScraper,      // Independent, non-commercial focus
            $this->presearchScraper,       // Decentralized search
            $this->alexandriaScraper,      // Independent, Common Crawl
            $this->rightDaoScraper,        // Independent, minimal anti-bot

            // Tier 5: Legacy meta-search
            $this->exaleadScraper,         // French/Dassault, industrial
            $this->gigablastScraper,       // Independent US index
            $this->infoScraper,            // Meta-search aggregator
            $this->lycosScraper,           // Bing-powered legacy
        ];
    }

    public function search(
        string $query,
        ?string $region = null,
        ?string $language = null,
        int $maxResults = 10,
        int $startIndex = 1,
    ): SearchResultSet {
        $startTime = microtime(true);
        $originalQuery = $query;

        // ══════════════════════════════════════════════════════════════════
        // QUERY OPTIMIZATION: Strip verbose exclusion lists to avoid:
        //   - HTTP 413 (Payload Too Large)
        //   - URL length rejections
        //   - Bot detection from unusual patterns
        // ══════════════════════════════════════════════════════════════════
        $queryNeedsOptimization = $this->queryOptimizer->needsOptimization($query);
        if ($queryNeedsOptimization) {
            $this->logger->debug('ScrapingSearchProvider: optimizing long query', [
                'original_length' => strlen($query),
            ]);
        }

        $availableEngines = $this->getAvailableEngines();

        $this->logger->debug('ScrapingSearchProvider: search', [
            'query' => mb_substr($query, 0, 80),
            'region' => $region,
            'maxResults' => $maxResults,
            'engines_available' => count($availableEngines),
            'query_optimized' => $queryNeedsOptimization,
        ]);

        // If no engines available (all in cooldown), skip directly to Google CSE fallback
        if (empty($availableEngines)) {
            $this->logger->warning('ScrapingSearchProvider: no engines available (all in cooldown), skipping to fallback', [
                'query' => mb_substr($query, 0, 80),
                'total_engines' => count($this->engines),
            ]);
            // Jump directly to Google CSE fallback below
            $lastError = null;
            goto googleCseFallback;
        }

        // Try each engine in priority order
        $lastError = null;
        $attemptCount = 0;

        foreach ($availableEngines as $engine) {
            $engineName = $engine->getEngineName();
            $attemptCount++;

            // Optimize query for this specific engine's URL length limits
            $optimizedQuery = $queryNeedsOptimization
                ? $this->queryOptimizer->optimizeQuery($query, $engineName)
                : $query;

            try {
                $results = $engine->scrapeResults($optimizedQuery, $region, $maxResults);

                if (!empty($results)) {
                    // Post-filter results to remove blocked domains/keywords
                    $filteredResults = $this->queryOptimizer->filterResults($results);

                    if (!empty($filteredResults)) {
                        $this->recordEngineSuccess($engineName);
                        $this->rateLimitManager->recordSuccess($engineName);
                        $elapsed = microtime(true) - $startTime;

                        $this->logger->info('ScrapingSearchProvider: success', [
                            'engine' => $engineName,
                            'results_raw' => count($results),
                            'results_filtered' => count($filteredResults),
                            'elapsed' => round($elapsed, 3),
                            'query' => mb_substr($originalQuery, 0, 80),
                            'attempt' => $attemptCount,
                        ]);

                        return $this->buildResultSet($filteredResults, $originalQuery, $engineName, $elapsed);
                    }
                }

                // Empty results — soft failure, try next engine
                $this->rateLimitManager->recordFailure($engineName, 'empty_results', isSoftFailure: true);

                $this->logger->notice('ScrapingSearchProvider: empty results from engine', [
                    'engine' => $engineName,
                    'query' => mb_substr($originalQuery, 0, 80),
                ]);

                // Brief inter-engine delay
                $this->rateLimitManager->sleepInterEngine(1.0, 2.0);

            } catch (\Throwable $e) {
                $lastError = $e;
                $errorMsg = $e->getMessage();

                // Classify error type for smarter backoff
                $isRateLimit = str_contains($errorMsg, '429')
                    || str_contains($errorMsg, '403')
                    || str_contains($errorMsg, 'rate limit')
                    || str_contains($errorMsg, 'CAPTCHA');

                $this->recordEngineFailure($engineName, $errorMsg);
                $this->rateLimitManager->recordFailure($engineName, $errorMsg, isSoftFailure: !$isRateLimit);

                $this->logger->warning('ScrapingSearchProvider: engine failed, trying next', [
                    'engine' => $engineName,
                    'error' => $errorMsg,
                    'query' => mb_substr($originalQuery, 0, 80),
                    'is_rate_limit' => $isRateLimit,
                    'attempt' => $attemptCount,
                ]);

                // Adaptive delay based on error type
                $delay = $isRateLimit
                    ? $this->rateLimitManager->getRecommendedDelay($engineName)
                    : 2.0;
                usleep((int)($delay * 1_000_000));
            }
        }

        // All free scraping engines exhausted — try Google CSE API as paid fallback
        googleCseFallback:
        if ($this->enableGoogleApiFallback && $this->googleCSEProvider->isAvailable()) {
            $this->logger->notice('ScrapingSearchProvider: all free engines exhausted, falling back to Google CSE API (paid)', [
                'query' => mb_substr($query, 0, 80),
            ]);

            try {
                $googleResult = $this->googleCSEProvider->search($query, $region, $language, $maxResults, $startIndex);

                if (!empty($googleResult->getResults())) {
                    $elapsed = microtime(true) - $startTime;

                    $this->logger->info('ScrapingSearchProvider: Google CSE fallback succeeded', [
                        'results' => count($googleResult->getResults()),
                        'elapsed' => round($elapsed, 3),
                        'query' => mb_substr($query, 0, 80),
                    ]);

                    // Wrap with metadata indicating fallback was used
                    return new SearchResultSet(
                        results: $googleResult->getResults(),
                        totalResults: $googleResult->getTotalResults(),
                        searchTimeSeconds: $elapsed,
                        providerName: $this->getProviderName(),
                        query: $query,
                        metadata: [
                            'engine' => 'google_cse_fallback',
                            'fallback_reason' => 'all_free_engines_exhausted',
                            'cost_incurred' => true,
                        ],
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->error('ScrapingSearchProvider: Google CSE fallback also failed', [
                    'error' => $e->getMessage(),
                    'query' => mb_substr($query, 0, 80),
                ]);
            }
        }

        // All engines exhausted (including Google CSE if enabled)
        $elapsed = microtime(true) - $startTime;
        $this->logger->warning('ScrapingSearchProvider: all engines exhausted — returning empty', [
            'query' => mb_substr($query, 0, 80),
            'lastError' => $lastError?->getMessage(),
            'elapsed' => round($elapsed, 3),
            'google_fallback_enabled' => $this->enableGoogleApiFallback,
            'engines_tried' => $attemptCount ?? 0,
        ]);

        return new SearchResultSet(
            results: [],
            totalResults: 0,
            searchTimeSeconds: $elapsed,
            providerName: $this->getProviderName(),
            query: $query,
            metadata: ['error' => 'All engines exhausted (including Google API fallback)', 'lastError' => $lastError?->getMessage()],
        );
    }

    public function getProviderName(): string
    {
        return 'scraping_multi_engine';
    }

    public function isAvailable(): bool
    {
        return !empty($this->getAvailableEngines());
    }

    /**
     * Merge results from multiple engines for maximum coverage.
     *
     * Call this method directly when you want to query ALL engines and merge
     * their results (e.g., for discovery queries where coverage matters more
     * than speed).
     *
     * @return SearchResultSet Deduplicated, merged results from all engines
     */
    public function searchAllEngines(
        string $query,
        ?string $region = null,
        int $maxResults = 10,
    ): SearchResultSet {
        $startTime = microtime(true);
        $allResults = [];
        $seenDomains = [];

        foreach ($this->getAvailableEngines() as $engine) {
            try {
                $results = $engine->scrapeResults($query, $region, $maxResults);
                foreach ($results as $result) {
                    $domain = $this->extractRootDomain($result['link'] ?? '');
                    if ($domain && !isset($seenDomains[$domain])) {
                        $seenDomains[$domain] = true;
                        $allResults[] = $result;
                    }
                }
                $this->recordEngineSuccess($engine->getEngineName());
            } catch (\Throwable $e) {
                $this->recordEngineFailure($engine->getEngineName(), $e->getMessage());
                $this->logger->warning('ScrapingSearchProvider: engine failed in multi-search', [
                    'engine' => $engine->getEngineName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $elapsed = microtime(true) - $startTime;
        return $this->buildResultSet($allResults, $query, 'multi_engine_merged', $elapsed);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Build a SearchResultSet from raw scraped result arrays.
     *
     * @param array[] $rawResults Each element: {link, title, snippet, displayLink}
     */
    private function buildResultSet(array $rawResults, string $query, string $engine, float $elapsed): SearchResultSet
    {
        $searchResults = [];
        foreach ($rawResults as $raw) {
            $url = $raw['link'] ?? '';
            $displayLink = $raw['displayLink'] ?? $this->extractRootDomain($url);

            $searchResults[] = new SearchResult(
                url: $url,
                title: $raw['title'] ?? '',
                snippet: $raw['snippet'] ?? '',
                displayLink: $displayLink,
                formattedUrl: $url,
                metadata: [
                    'scraping_engine' => $engine,
                    'position' => count($searchResults) + 1,
                ],
            );
        }

        return new SearchResultSet(
            results: $searchResults,
            totalResults: count($searchResults),
            searchTimeSeconds: $elapsed,
            providerName: $this->getProviderName(),
            query: $query,
            metadata: ['engine' => $engine],
        );
    }

    /**
     * Extract root domain from URL (e.g., "https://www.example.com/page" → "example.com").
     * This produces the `displayLink` field — the pipeline's primary dedup key.
     */
    private function extractRootDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return '';
        }
        // Strip www.
        return preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * Get engines that are not in cooldown, sorted by health (best first).
     *
     * Consults BOTH in-memory cooldowns (this process) AND persistent
     * RateLimitManager state (cross-process, exponential backoff).
     *
     * @return SearchEngineScraper[]
     */
    private function getAvailableEngines(): array
    {
        $now = microtime(true);
        $available = [];

        foreach ($this->engines as $engine) {
            $name = $engine->getEngineName();

            // Check in-memory engine-level cooldown (this process)
            if (isset($this->engineCooldownUntil[$name]) && $now < $this->engineCooldownUntil[$name]) {
                $remaining = round($this->engineCooldownUntil[$name] - $now);
                $this->logger->debug("ScrapingSearchProvider: engine {$name} in memory cooldown ({$remaining}s remaining)");
                continue;
            }

            // Check persistent RateLimitManager cooldown (cross-process, backoff)
            if (!$this->rateLimitManager->isEngineAvailable($name)) {
                $remaining = round($this->rateLimitManager->getCooldownRemaining($name));
                $this->logger->debug("ScrapingSearchProvider: engine {$name} in rate-limit cooldown ({$remaining}s remaining)");
                continue;
            }

            $available[] = $engine;
        }

        // Sort by health score (healthiest first) while preserving tier order as tiebreaker
        usort($available, function (SearchEngineScraper $a, SearchEngineScraper $b) {
            $healthA = $this->rateLimitManager->getEngineHealthScore($a->getEngineName());
            $healthB = $this->rateLimitManager->getEngineHealthScore($b->getEngineName());

            // Only re-order if health difference is significant (>20 points)
            // This preserves tier ordering for similarly-healthy engines
            if (abs($healthA - $healthB) > 20) {
                return $healthB <=> $healthA; // Higher health first
            }

            return 0; // Preserve original tier order
        });

        return $available;
    }

    private function recordEngineSuccess(string $engineName): void
    {
        $this->engineFailures[$engineName] = 0;
        unset($this->engineCooldownUntil[$engineName]);
    }

    private function recordEngineFailure(string $engineName, string $reason): void
    {
        $count = ($this->engineFailures[$engineName] ?? 0) + 1;
        $this->engineFailures[$engineName] = $count;

        if ($count >= self::ENGINE_FAILURE_THRESHOLD) {
            $this->engineCooldownUntil[$engineName] = microtime(true) + self::ENGINE_COOLDOWN_SECONDS;
            $this->logger->warning("ScrapingSearchProvider: engine {$engineName} entering cooldown after {$count} failures", [
                'reason' => $reason,
                'cooldown_seconds' => self::ENGINE_COOLDOWN_SECONDS,
            ]);
        }
    }
}
