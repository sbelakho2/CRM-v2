<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Proxy Rotation Service
 * 
 * Provides rotating proxy support for scraping operations to avoid IP bans.
 * 
 * Supports:
 * - Environment-configured proxy lists
 * - Rotation strategies (round-robin, random, weighted)
 * - Proxy health tracking and blacklisting
 * - Rate limiting per proxy
 * - Proxy pool refresh
 * 
 * Configuration via environment variables:
 * - PROXY_LIST: Comma-separated list of proxies (format: protocol://user:pass@host:port)
 * - PROXY_STRATEGY: 'round_robin', 'random', 'weighted' (default: round_robin)
 * - PROXY_ENABLED: 'true'/'false' to enable/disable globally
 */
class ProxyRotationService
{
    // Strategies for selecting next proxy
    public const STRATEGY_ROUND_ROBIN = 'round_robin';
    public const STRATEGY_RANDOM = 'random';
    public const STRATEGY_WEIGHTED = 'weighted'; // Favors faster/healthier proxies
    
    // Default settings
    private const DEFAULT_TIMEOUT = 10;
    private const MAX_CONSECUTIVE_FAILURES = 3;
    private const BLACKLIST_DURATION_SECONDS = 600; // 10 minutes
    private const RATE_LIMIT_REQUESTS_PER_MINUTE = 20;
    
    /** @var list<string> */
    private array $proxies = [];

    /** @var array<string, array{successes: int, failures: int, consecutive_failures: int}> */
    private array $proxyHealth = []; // Track success/failure per proxy

    /** @var array<string, array{since: int, reason: string}> */
    private array $blacklist = []; // Temporarily disabled proxies

    /** @var array<string, list<int>> */
    private array $requestTimes = []; // For rate limiting
    private int $currentIndex = 0;
    private string $strategy;
    private bool $enabled;
    
    public function __construct(
        private LoggerInterface $logger,
        ?string $proxyList = null,
        ?string $strategy = null,
        ?bool $enabled = null
    ) {
        // Load from environment if not provided
        if ($proxyList === null) {
            $envList = $_ENV['PROXY_LIST'] ?? null;
            $proxyList = is_string($envList) ? $envList : '';
        }
        if ($strategy === null) {
            $envStrategy = $_ENV['PROXY_STRATEGY'] ?? null;
            $strategy = is_string($envStrategy) ? $envStrategy : self::STRATEGY_ROUND_ROBIN;
        }
        $this->strategy = $strategy;
        if ($enabled === null) {
            $envEnabled = $_ENV['PROXY_ENABLED'] ?? null;
            $enabled = $envEnabled === 'true';
        }
        $this->enabled = $enabled;
        
        $this->parseProxyList($proxyList);
        
        if ($this->enabled && empty($this->proxies)) {
            $this->logger->warning('Proxy rotation enabled but no proxies configured');
            $this->enabled = false;
        }
    }

    /**
     * Check if proxy rotation is enabled and available
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->getAvailableProxies());
    }

    /**
     * Get the next proxy URL to use
     * 
     * @return string|null Proxy URL or null if none available/disabled
     */
    public function getNextProxy(): ?string
    {
        if (!$this->enabled) {
            return null;
        }
        
        $this->cleanupBlacklist();
        $available = $this->getAvailableProxies();
        
        if (empty($available)) {
            $this->logger->error('No available proxies - all blacklisted or exhausted');
            return null;
        }
        
        // Try each available proxy up to the total count to avoid infinite recursion
        $attempts = count($available);
        for ($i = 0; $i < $attempts; $i++) {
            $proxy = match ($this->strategy) {
                self::STRATEGY_RANDOM => $this->selectRandom($available),
                self::STRATEGY_WEIGHTED => $this->selectWeighted($available),
                default => $this->selectRoundRobin($available),
            };
            
            if ($this->checkRateLimit($proxy)) {
                $this->recordRequest($proxy);
                return $proxy;
            }
            
            $this->logger->debug('Proxy rate limited, trying another', ['proxy' => $this->maskProxy($proxy)]);
            // Remove this proxy from available list for next iteration
            $available = array_values(array_filter($available, fn($p) => $p !== $proxy));
            if (empty($available)) {
                break;
            }
        }
        
        $this->logger->warning('All available proxies are rate limited');
        return null;
    }

    /**
     * Report a successful request through a proxy
     */
    public function reportSuccess(string $proxy): void
    {
        if (!isset($this->proxyHealth[$proxy])) {
            $this->proxyHealth[$proxy] = ['successes' => 0, 'failures' => 0, 'consecutive_failures' => 0];
        }

        $this->proxyHealth[$proxy]['successes']++;
        $this->proxyHealth[$proxy]['consecutive_failures'] = 0;
        
        // Remove from blacklist if it was there
        unset($this->blacklist[$proxy]);
    }

    /**
     * Report a failed request through a proxy
     * 
     * @param string $proxy The proxy URL
     * @param string $reason Failure reason for logging
     * @param bool $is403 True if failure was HTTP 403 (triggers faster blacklist)
     */
    public function reportFailure(string $proxy, string $reason = '', bool $is403 = false): void
    {
        if (!isset($this->proxyHealth[$proxy])) {
            $this->proxyHealth[$proxy] = ['successes' => 0, 'failures' => 0, 'consecutive_failures' => 0];
        }
        
        $this->proxyHealth[$proxy]['failures']++;
        $this->proxyHealth[$proxy]['consecutive_failures']++;
        
        $this->logger->warning('Proxy request failed', [
            'proxy' => $this->maskProxy($proxy),
            'reason' => $reason,
            'consecutive_failures' => $this->proxyHealth[$proxy]['consecutive_failures'],
        ]);
        
        // Blacklist if too many consecutive failures (immediate for 403s)
        $threshold = $is403 ? 1 : self::MAX_CONSECUTIVE_FAILURES;
        
        if ($this->proxyHealth[$proxy]['consecutive_failures'] >= $threshold) {
            $this->blacklistProxy($proxy, $reason);
        }
    }

    /**
     * Get HTTP client options for using a proxy
     *
     * @param string $proxy Proxy URL
     * @return array{proxy: string, timeout: int, verify_peer: bool, verify_host: bool} Options suitable for Symfony HttpClient
     */
    public function getHttpClientOptions(string $proxy): array
    {
        return [
            'proxy' => $proxy,
            'timeout' => self::DEFAULT_TIMEOUT,
            'verify_peer' => true,
            'verify_host' => true,
        ];
    }

    /**
     * Get proxy statistics
     *
     * @return array{total_proxies: int, available_proxies: int, blacklisted_proxies: int, enabled: bool, strategy: string, health: list<array{proxy: string, successes: int, failures: int, success_rate: float|null, blacklisted: bool}>}
     */
    public function getStats(): array
    {
        return [
            'total_proxies' => count($this->proxies),
            'available_proxies' => count($this->getAvailableProxies()),
            'blacklisted_proxies' => count($this->blacklist),
            'enabled' => $this->enabled,
            'strategy' => $this->strategy,
            'health' => array_map(function(string $proxy) {
                $health = $this->proxyHealth[$proxy] ?? ['successes' => 0, 'failures' => 0, 'consecutive_failures' => 0];
                return [
                    'proxy' => $this->maskProxy($proxy),
                    'successes' => $health['successes'],
                    'failures' => $health['failures'],
                    'success_rate' => $health['successes'] + $health['failures'] > 0
                        ? round($health['successes'] / ($health['successes'] + $health['failures']) * 100, 1)
                        : null,
                    'blacklisted' => isset($this->blacklist[$proxy]),
                ];
            }, $this->proxies),
        ];
    }

    /**
     * Manually add a proxy to the pool
     */
    public function addProxy(string $proxy): void
    {
        if (!in_array($proxy, $this->proxies, true)) {
            $this->proxies[] = $proxy;
            $this->proxyHealth[$proxy] = ['successes' => 0, 'failures' => 0, 'consecutive_failures' => 0];
        }
    }

    /**
     * Reset all proxy health data
     */
    public function resetHealth(): void
    {
        $this->proxyHealth = [];
        $this->blacklist = [];
        $this->requestTimes = [];
        $this->currentIndex = 0;
    }

    // ==================== Private Methods ====================

    private function parseProxyList(string $proxyList): void
    {
        if (empty($proxyList)) {
            return;
        }
        
        $proxies = array_filter(array_map('trim', explode(',', $proxyList)));
        
        foreach ($proxies as $proxy) {
            // Validate proxy format
            if (preg_match('/^(https?|socks[45]?):\/\//', $proxy)) {
                $this->proxies[] = $proxy;
                $this->proxyHealth[$proxy] = ['successes' => 0, 'failures' => 0, 'consecutive_failures' => 0];
            } else {
                $this->logger->warning('Invalid proxy format, skipping', ['proxy' => $this->maskProxy($proxy)]);
            }
        }
        
        $this->logger->info('Loaded proxy pool', ['count' => count($this->proxies)]);
    }

    /**
     * @return list<string>
     */
    private function getAvailableProxies(): array
    {
        $this->cleanupBlacklist();

        return array_values(array_filter($this->proxies, fn(string $proxy): bool => !isset($this->blacklist[$proxy])));
    }

    /**
     * @param list<string> $available
     */
    private function selectRoundRobin(array $available): string
    {
        $this->currentIndex = ($this->currentIndex + 1) % count($available);
        return $available[$this->currentIndex];
    }

    /**
     * @param list<string> $available
     */
    private function selectRandom(array $available): string
    {
        return $available[(int) array_rand($available)];
    }

    /**
     * @param list<string> $available
     */
    private function selectWeighted(array $available): string
    {
        // Weight by success rate (higher = more likely to be selected)
        $weights = [];
        
        foreach ($available as $proxy) {
            $health = $this->proxyHealth[$proxy] ?? ['successes' => 0, 'failures' => 0, 'consecutive_failures' => 0];
            $total = $health['successes'] + $health['failures'];
            
            // New proxies get neutral weight
            if ($total === 0) {
                $weights[$proxy] = 50;
            } else {
                $weights[$proxy] = ($health['successes'] / $total) * 100;
            }
        }
        
        // Weighted random selection
        $totalWeight = array_sum($weights);
        $random = mt_rand(0, (int) $totalWeight);
        
        $cumulative = 0;
        foreach ($weights as $proxy => $weight) {
            $cumulative += $weight;
            if ($random <= $cumulative) {
                return $proxy;
            }
        }
        
        // Fallback
        $firstKey = array_key_first($available);

        return $firstKey !== null ? $available[$firstKey] : '';
    }

    private function blacklistProxy(string $proxy, string $reason = ''): void
    {
        $this->blacklist[$proxy] = [
            'since' => time(),
            'reason' => $reason,
        ];
        
        $this->logger->warning('Proxy blacklisted', [
            'proxy' => $this->maskProxy($proxy),
            'reason' => $reason,
            'duration' => self::BLACKLIST_DURATION_SECONDS . 's',
        ]);
    }

    private function cleanupBlacklist(): void
    {
        $now = time();
        
        foreach ($this->blacklist as $proxy => $data) {
            if ($now - $data['since'] >= self::BLACKLIST_DURATION_SECONDS) {
                unset($this->blacklist[$proxy]);
                
                // Reset consecutive failures when unblacklisting
                if (isset($this->proxyHealth[$proxy])) {
                    $this->proxyHealth[$proxy]['consecutive_failures'] = 0;
                }
                
                $this->logger->info('Proxy removed from blacklist', ['proxy' => $this->maskProxy($proxy)]);
            }
        }
    }

    private function checkRateLimit(string $proxy): bool
    {
        if (!isset($this->requestTimes[$proxy])) {
            return true;
        }
        
        $oneMinuteAgo = time() - 60;
        $recentRequests = array_filter($this->requestTimes[$proxy], fn($t) => $t >= $oneMinuteAgo);
        
        return count($recentRequests) < self::RATE_LIMIT_REQUESTS_PER_MINUTE;
    }

    private function recordRequest(string $proxy): void
    {
        if (!isset($this->requestTimes[$proxy])) {
            $this->requestTimes[$proxy] = [];
        }
        
        $this->requestTimes[$proxy][] = time();
        
        // Cleanup old timestamps
        $oneMinuteAgo = time() - 60;
        $this->requestTimes[$proxy] = array_values(array_filter($this->requestTimes[$proxy], fn(int $t): bool => $t >= $oneMinuteAgo));
    }

    /**
     * Mask proxy URL for logging (hide credentials)
     */
    private function maskProxy(string $proxy): string
    {
        return preg_replace('/\/\/([^:]+):([^@]+)@/', '//***:***@', $proxy) ?? $proxy;
    }
}
