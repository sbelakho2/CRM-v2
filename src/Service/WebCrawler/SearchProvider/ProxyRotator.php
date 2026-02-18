<?php

namespace App\Service\WebCrawler\SearchProvider;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Proxy rotation service for evading IP-based rate limits.
 *
 * Supports multiple proxy sources:
 *   - Free public proxy lists (lower reliability)
 *   - SOCKS5 proxies
 *   - HTTP/HTTPS proxies
 *   - Paid proxy services via env config
 *
 * Each proxy is health-checked and scored. Failed proxies are
 * temporarily blacklisted with exponential backoff.
 */
final class ProxyRotator
{
    /**
     * Free public proxy list sources (scraped on-demand).
     * These are fallback sources when no paid proxies configured.
     */
    private const FREE_PROXY_SOURCES = [
        'https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/http.txt',
        'https://raw.githubusercontent.com/ShiftyTR/Proxy-List/master/http.txt',
        'https://raw.githubusercontent.com/monosans/proxy-list/main/proxies/http.txt',
        'https://raw.githubusercontent.com/hookzof/socks5_list/master/proxy.txt',
        'https://raw.githubusercontent.com/jetkai/proxy-list/main/online-proxies/txt/proxies-http.txt',
    ];

    /**
     * Built-in list of generally reliable free proxies (may become stale).
     * These are used as bootstrap before fetching fresh lists.
     */
    private const BOOTSTRAP_PROXIES = [
        // These are placeholder examples - real proxies change frequently
        // The system will fetch fresh proxies from GitHub lists
    ];

    private const STATE_FILE = '/tmp/proxy_rotator_state.json';
    private const PROXY_CACHE_FILE = '/tmp/proxy_list_cache.json';
    private const PROXY_CACHE_TTL = 3600; // 1 hour

    private array $proxyHealth = [];
    private array $proxies = [];
    private int $currentIndex = 0;
    private bool $proxyEnabled = false;
    private ?string $paidProxyUrl = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
        $this->loadState();
        $this->loadConfig();
    }

    /**
     * Load configuration from environment via getenv() with safe defaults.
     */
    private function loadConfig(): void
    {
        // Check for paid proxy service (e.g., Bright Data, Oxylabs, etc.)
        $this->paidProxyUrl = getenv('PROXY_SERVICE_URL') ?: null;

        // Enable proxy rotation if configured or if free proxies should be used
        $this->proxyEnabled = filter_var(getenv('ENABLE_PROXY_ROTATION'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get the next healthy proxy for a request.
     *
     * @return string|null Proxy URL (http://host:port) or null if no proxy
     */
    public function getNextProxy(): ?string
    {
        if (!$this->proxyEnabled) {
            return null;
        }

        // If using paid proxy service, return that
        if ($this->paidProxyUrl) {
            return $this->paidProxyUrl;
        }

        // Ensure we have proxies loaded
        if (empty($this->proxies)) {
            $this->refreshProxyList();
        }

        if (empty($this->proxies)) {
            return null;
        }

        // Find a healthy proxy
        $attempts = 0;
        $maxAttempts = min(count($this->proxies), 10);

        while ($attempts < $maxAttempts) {
            $proxy = $this->proxies[$this->currentIndex];
            $this->currentIndex = ($this->currentIndex + 1) % count($this->proxies);
            $attempts++;

            // Check if proxy is in backoff
            $health = $this->proxyHealth[$proxy] ?? ['failures' => 0, 'backoff_until' => 0];
            if (time() < ($health['backoff_until'] ?? 0)) {
                continue;
            }

            return $proxy;
        }

        // All proxies in backoff - force use one anyway
        return $this->proxies[array_rand($this->proxies)];
    }

    /**
     * Report proxy success - improves health score.
     */
    public function reportSuccess(string $proxy): void
    {
        $this->proxyHealth[$proxy] = [
            'failures' => 0,
            'backoff_until' => 0,
            'last_success' => time(),
            'success_count' => ($this->proxyHealth[$proxy]['success_count'] ?? 0) + 1,
        ];
        $this->saveState();
    }

    /**
     * Report proxy failure - triggers backoff.
     */
    public function reportFailure(string $proxy, string $reason = ''): void
    {
        $current = $this->proxyHealth[$proxy] ?? ['failures' => 0];
        $failures = ($current['failures'] ?? 0) + 1;

        // Exponential backoff: 30s, 60s, 120s, 240s, max 10min
        $backoffSeconds = min(600, 30 * pow(2, $failures - 1));

        $this->proxyHealth[$proxy] = [
            'failures' => $failures,
            'backoff_until' => time() + $backoffSeconds,
            'last_failure' => time(),
            'last_error' => $reason,
            'success_count' => $current['success_count'] ?? 0,
        ];

        $this->logger->debug('ProxyRotator: proxy failed', [
            'proxy' => $this->maskProxy($proxy),
            'failures' => $failures,
            'backoff_seconds' => $backoffSeconds,
            'reason' => $reason,
        ]);

        // If too many failures, remove from rotation
        if ($failures >= 5) {
            $this->removeProxy($proxy);
        }

        $this->saveState();
    }

    /**
     * Get proxy configuration for Symfony HttpClient.
     *
     * @return array Options array for HttpClient
     */
    public function getHttpClientOptions(): array
    {
        $proxy = $this->getNextProxy();
        if (!$proxy) {
            return [];
        }

        return [
            'proxy' => $proxy,
            'timeout' => 15, // Shorter timeout for proxied requests
        ];
    }

    /**
     * Refresh the proxy list from sources.
     */
    public function refreshProxyList(): void
    {
        // Check cache first
        if ($this->loadProxyCache()) {
            return;
        }

        $this->logger->info('ProxyRotator: refreshing proxy list from sources');

        $proxies = [];

        foreach (self::FREE_PROXY_SOURCES as $source) {
            try {
                $response = $this->httpClient->request('GET', $source, [
                    'timeout' => 10,
                    'headers' => ['User-Agent' => 'Mozilla/5.0'],
                ]);

                $content = $response->getContent(false);
                $lines = explode("\n", $content);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if (preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}):(\d+)$/', $line)) {
                        $proxies[] = 'http://' . $line;
                    }
                }

                $this->logger->debug('ProxyRotator: fetched proxies from source', [
                    'source' => $source,
                    'count' => count($lines),
                ]);

            } catch (\Throwable $e) {
                $this->logger->warning('ProxyRotator: failed to fetch proxy list', [
                    'source' => $source,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Deduplicate and shuffle
        $proxies = array_unique($proxies);
        shuffle($proxies);

        // Limit to reasonable number
        $this->proxies = array_slice($proxies, 0, 500);

        $this->logger->info('ProxyRotator: loaded proxies', [
            'count' => count($this->proxies),
        ]);

        $this->saveProxyCache();
    }

    /**
     * Test a proxy's connectivity and speed.
     *
     * @return array{working: bool, latency_ms: int, error?: string}
     */
    public function testProxy(string $proxy): array
    {
        $start = microtime(true);

        try {
            $response = $this->httpClient->request('GET', 'https://httpbin.org/ip', [
                'proxy' => $proxy,
                'timeout' => 10,
            ]);

            $data = $response->toArray();
            $latency = (int)((microtime(true) - $start) * 1000);

            return [
                'working' => true,
                'latency_ms' => $latency,
                'ip' => $data['origin'] ?? 'unknown',
            ];

        } catch (\Throwable $e) {
            return [
                'working' => false,
                'latency_ms' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get statistics about proxy pool.
     */
    public function getStats(): array
    {
        $healthy = 0;
        $inBackoff = 0;
        $failed = 0;

        foreach ($this->proxies as $proxy) {
            $health = $this->proxyHealth[$proxy] ?? null;
            if (!$health) {
                $healthy++;
            } elseif (time() < ($health['backoff_until'] ?? 0)) {
                $inBackoff++;
            } elseif (($health['failures'] ?? 0) > 0) {
                $failed++;
            } else {
                $healthy++;
            }
        }

        return [
            'enabled' => $this->proxyEnabled,
            'total' => count($this->proxies),
            'healthy' => $healthy,
            'in_backoff' => $inBackoff,
            'failed' => $failed,
            'using_paid' => (bool)$this->paidProxyUrl,
        ];
    }

    /**
     * Check if proxy rotation is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->proxyEnabled;
    }

    /**
     * Enable proxy rotation programmatically.
     */
    public function enable(): void
    {
        $this->proxyEnabled = true;
        if (empty($this->proxies)) {
            $this->refreshProxyList();
        }
    }

    /**
     * Disable proxy rotation.
     */
    public function disable(): void
    {
        $this->proxyEnabled = false;
    }

    private function removeProxy(string $proxy): void
    {
        $this->proxies = array_values(array_filter(
            $this->proxies,
            fn($p) => $p !== $proxy
        ));
        unset($this->proxyHealth[$proxy]);
    }

    private function maskProxy(string $proxy): string
    {
        // Mask IP for logging (security)
        return preg_replace('/(\d+\.\d+)\.\d+\.\d+/', '$1.xxx.xxx', $proxy);
    }

    private function loadState(): void
    {
        if (file_exists(self::STATE_FILE)) {
            $data = json_decode(file_get_contents(self::STATE_FILE), true);
            $this->proxyHealth = $data['health'] ?? [];
        }
    }

    private function saveState(): void
    {
        file_put_contents(self::STATE_FILE, json_encode([
            'health' => $this->proxyHealth,
            'timestamp' => time(),
        ]));
    }

    private function loadProxyCache(): bool
    {
        if (!file_exists(self::PROXY_CACHE_FILE)) {
            return false;
        }

        $data = json_decode(file_get_contents(self::PROXY_CACHE_FILE), true);
        if (!$data || (time() - ($data['timestamp'] ?? 0)) > self::PROXY_CACHE_TTL) {
            return false;
        }

        $this->proxies = $data['proxies'] ?? [];
        return !empty($this->proxies);
    }

    private function saveProxyCache(): void
    {
        file_put_contents(self::PROXY_CACHE_FILE, json_encode([
            'proxies' => $this->proxies,
            'timestamp' => time(),
        ]));
    }
}
