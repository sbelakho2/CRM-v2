<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use App\Service\WebCrawler\SearchProvider\HeaderRandomizer;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * SearXNG Meta-Search Scraper.
 *
 * SearXNG is a privacy-focused meta-search engine with 100+ public instances.
 * Each instance aggregates results from multiple search engines (Google, Bing,
 * DuckDuckGo, etc.) and has its own IP address - making this incredibly
 * powerful for evading rate limits.
 *
 * Strategy:
 *   - Maintain a list of 100+ public SearXNG instances
 *   - Rotate between instances to distribute load
 *   - Track instance health and skip failing ones
 *   - Fall back through instances until one succeeds
 *
 * This single scraper effectively gives us access to 100+ unique IPs
 * all capable of searching Google/Bing without rate limits.
 */
final class SearxngScraper
{
    /**
     * Public SearXNG instances (curated Feb 2026).
     * Source: https://searx.space/
     *
     * IMPORTANT: Only instances verified to support JSON API format.
     * Dead/broken instances waste ~2s each and poison rate limit state.
     * Better to have 20 working instances than 100 dead ones.
     *
     * Each instance has its own IP and rate limits independent of others.
     * Instances are shuffled at startup for load distribution.
     */
    private const INSTANCES = [
        // ── Tier 1: Well-established, reliable instances ──────────────
        'https://searx.be',
        'https://priv.au',
        'https://opnxng.com',
        'https://searxng.ch',
        'https://paulgo.io',
        'https://etsi.me',
        'https://search.bus-hit.me',
        'https://search.sapti.me',

        // ── Tier 2: European instances (good for DE searches) ─────────
        'https://searx.tiekoetter.com',
        'https://search.mdosch.de',
        'https://search.nerdvpn.de',
        'https://search.rowie.at',
        'https://searx.fi',
        'https://searxng.site',
        'https://search.inetol.net',
        'https://searx.dresden.network',

        // ── Tier 3: Additional working instances ──────────────────────
        'https://search.disroot.org',
        'https://searx.prvcy.eu',
        'https://search.projectsegfau.lt',
        'https://searx.fmac.xyz',
        'https://northboot.xyz',
        'https://search.gcomm.ch',
        'https://searx.nobulart.com',
        'https://search.datura.network',
    ];

    private const STATE_FILE = '/tmp/searxng_instance_state.json';

    private array $instanceHealth = [];
    private int $currentIndex = 0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly HeaderRandomizer $headerRandomizer,
    ) {
        $this->loadState();
        // Randomize starting position
        $this->currentIndex = random_int(0, count(self::INSTANCES) - 1);
    }

    /**
     * Search using SearXNG instances.
     *
     * @return array[] Search results
     */
    public function search(string $query, int $maxResults = 10, ?string $region = null): array
    {
        $attempts = 0;
        $maxAttempts = min(count(self::INSTANCES), 8); // Try up to 8 instances (curated list)

        while ($attempts < $maxAttempts) {
            $instance = $this->getNextHealthyInstance();
            $attempts++;

            try {
                $results = $this->searchInstance($instance, $query, $maxResults, $region);

                if (!empty($results)) {
                    $this->reportSuccess($instance);
                    return $results;
                }

                // Empty results - minor penalty
                $this->reportEmptyResults($instance);

            } catch (\Throwable $e) {
                $this->reportFailure($instance, $e->getMessage());
                $this->logger->debug('SearxngScraper: instance failed', [
                    'instance' => $instance,
                    'error' => $e->getMessage(),
                ]);
            }

            // Small delay between instance attempts
            usleep(random_int(200000, 500000)); // 200-500ms
        }

        throw new \RuntimeException('All SearXNG instances failed after ' . $attempts . ' attempts');
    }

    /**
     * Search a specific SearXNG instance.
     */
    private function searchInstance(string $instance, string $query, int $maxResults, ?string $region): array
    {
        // SearXNG supports JSON output format
        $url = rtrim($instance, '/') . '/search';

        $params = [
            'q' => $query,
            'format' => 'json',
            'engines' => 'google,bing,duckduckgo,brave,qwant',
            'language' => $this->mapRegionToLanguage($region),
            'safesearch' => '0',
            'pageno' => '1',
        ];

        $response = $this->httpClient->request('GET', $url, [
            'query' => $params,
            'headers' => $this->headerRandomizer->getRandomHeaders($region, $url),
            'timeout' => 15,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException("SearXNG returned HTTP {$statusCode}");
        }

        $data = $response->toArray();
        $results = [];

        foreach (($data['results'] ?? []) as $item) {
            if (count($results) >= $maxResults) {
                break;
            }

            if (empty($item['url'])) {
                continue;
            }

            $results[] = [
                'title' => $item['title'] ?? '',
                'link' => $item['url'],
                'snippet' => $item['content'] ?? '',
                'displayLink' => parse_url($item['url'], PHP_URL_HOST) ?: '',
            ];
        }

        return $results;
    }

    /**
     * Get the next healthy instance to try.
     */
    private function getNextHealthyInstance(): string
    {
        $checked = 0;
        $totalInstances = count(self::INSTANCES);

        while ($checked < $totalInstances) {
            $instance = self::INSTANCES[$this->currentIndex];
            $this->currentIndex = ($this->currentIndex + 1) % $totalInstances;
            $checked++;

            // Check if instance is in backoff
            $health = $this->instanceHealth[$instance] ?? null;
            if ($health && time() < ($health['backoff_until'] ?? 0)) {
                continue;
            }

            return $instance;
        }

        // All instances in backoff - return random one anyway
        return self::INSTANCES[array_rand(self::INSTANCES)];
    }

    private function reportSuccess(string $instance): void
    {
        $this->instanceHealth[$instance] = [
            'failures' => 0,
            'empty_count' => 0,
            'backoff_until' => 0,
            'last_success' => time(),
        ];
        $this->saveState();
    }

    private function reportEmptyResults(string $instance): void
    {
        $current = $this->instanceHealth[$instance] ?? ['empty_count' => 0];
        $emptyCount = ($current['empty_count'] ?? 0) + 1;

        // Only backoff after 3 consecutive empty results
        if ($emptyCount >= 3) {
            $this->instanceHealth[$instance] = [
                'failures' => 0,
                'empty_count' => $emptyCount,
                'backoff_until' => time() + 60, // 1 minute backoff
                'last_empty' => time(),
            ];
        } else {
            $this->instanceHealth[$instance] = array_merge(
                $current,
                ['empty_count' => $emptyCount]
            );
        }

        $this->saveState();
    }

    private function reportFailure(string $instance, string $reason): void
    {
        $current = $this->instanceHealth[$instance] ?? ['failures' => 0];
        $failures = ($current['failures'] ?? 0) + 1;

        // Exponential backoff: 30s, 60s, 120s, 240s, max 10min
        $backoffSeconds = min(600, 30 * pow(2, $failures - 1));

        $this->instanceHealth[$instance] = [
            'failures' => $failures,
            'empty_count' => 0,
            'backoff_until' => time() + $backoffSeconds,
            'last_error' => $reason,
        ];

        $this->saveState();
    }

    private function mapRegionToLanguage(?string $region): string
    {
        return match ($region) {
            'DE' => 'de-DE',
            'FR' => 'fr-FR',
            'ES' => 'es-ES',
            'IT' => 'it-IT',
            'NL' => 'nl-NL',
            'PL' => 'pl-PL',
            'CZ' => 'cs-CZ',
            'RU' => 'ru-RU',
            'JP' => 'ja-JP',
            'CN' => 'zh-CN',
            'KR' => 'ko-KR',
            'UK', 'GB' => 'en-GB',
            'US' => 'en-US',
            default => 'en-US',
        };
    }

    /**
     * Get instance statistics.
     */
    public function getStats(): array
    {
        $healthy = 0;
        $inBackoff = 0;

        foreach (self::INSTANCES as $instance) {
            $health = $this->instanceHealth[$instance] ?? null;
            if (!$health || time() >= ($health['backoff_until'] ?? 0)) {
                $healthy++;
            } else {
                $inBackoff++;
            }
        }

        return [
            'total_instances' => count(self::INSTANCES),
            'healthy' => $healthy,
            'in_backoff' => $inBackoff,
        ];
    }

    /**
     * Get list of healthy instances.
     */
    public function getHealthyInstances(): array
    {
        $healthy = [];
        foreach (self::INSTANCES as $instance) {
            $health = $this->instanceHealth[$instance] ?? null;
            if (!$health || time() >= ($health['backoff_until'] ?? 0)) {
                $healthy[] = $instance;
            }
        }
        return $healthy;
    }

    private function loadState(): void
    {
        if (file_exists(self::STATE_FILE)) {
            $data = json_decode(file_get_contents(self::STATE_FILE), true);
            $this->instanceHealth = $data['health'] ?? [];
        }
    }

    private function saveState(): void
    {
        file_put_contents(self::STATE_FILE, json_encode([
            'health' => $this->instanceHealth,
            'timestamp' => time(),
        ]));
    }
}
