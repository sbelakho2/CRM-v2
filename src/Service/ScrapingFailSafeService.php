<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Scraping Fail-Safe Service
 * 
 * Monitors scraping operations and automatically pauses when detecting issues:
 * - Consecutive failures per domain
 * - 403 spikes indicating IP blocks
 * - Overall failure rate thresholds
 * 
 * Provides:
 * - Automatic circuit breaker pattern
 * - Domain-specific cooldown periods
 * - Alert generation for admin review
 * - Manual resume capabilities
 *
 * @phpstan-type FailureEntry array{time: int, code: int, reason: string}
 * @phpstan-type DomainStats array{successes: list<int>, failures: list<FailureEntry>, consecutive_failures: int, paused: bool, pause_reason: string|null, paused_at: int|null, cooldown_until: int|null, '403_count': int, last_success: int|null, last_failure: int|null}
 * @phpstan-type Alert array{domain: string, type: string, message: string, timestamp: int, datetime: string}
 */
class ScrapingFailSafeService
{
    // Configuration constants
    private const CONSECUTIVE_FAILURE_THRESHOLD = 3;
    private const FAILURE_RATE_THRESHOLD = 0.5; // 50% failure rate
    private const COOLDOWN_DURATION_SECONDS = 1800; // 30 minutes
    private const RATE_WINDOW_SECONDS = 300; // 5 minute window for rate calculation
    private const CACHE_PREFIX = 'scraping_failsafe_';
    
    // Alert types
    public const ALERT_CONSECUTIVE_FAILURES = 'consecutive_failures';
    public const ALERT_HIGH_FAILURE_RATE = 'high_failure_rate';
    public const ALERT_403_SPIKE = '403_spike';
    
    /** @var array<string, DomainStats> */
    private array $domainStats = []; // In-memory tracking

    /** @var list<Alert> */
    private array $alerts = [];

    public function __construct(
        private LoggerInterface $logger,
        private CacheInterface $cache,

        /**
         * Not read yet; kept for future persistence of fail-safe state.
         */
        protected ?EntityManagerInterface $entityManager = null
    ) {}

    /**
     * Check if scraping is allowed for a domain
     * 
     * @param string $domain Domain to check (e.g., 'example.com')
     * @return bool True if scraping is allowed
     */
    public function canScrape(string $domain): bool
    {
        $domain = $this->normalizeDomain($domain);
        $stats = $this->getStats($domain);
        
        // Check if in cooldown
        if ($stats['cooldown_until'] && time() < $stats['cooldown_until']) {
            $this->logger->debug('Domain in cooldown', [
                'domain' => $domain,
                'remaining' => $stats['cooldown_until'] - time(),
            ]);
            return false;
        }
        
        // Check if manually paused
        if ($stats['paused']) {
            $this->logger->debug('Domain manually paused', ['domain' => $domain]);
            return false;
        }
        
        return true;
    }

    /**
     * Record a successful scrape
     */
    public function recordSuccess(string $domain): void
    {
        $domain = $this->normalizeDomain($domain);
        $stats = $this->getStats($domain);
        
        $stats['successes'][] = time();
        $stats['consecutive_failures'] = 0;
        $stats['last_success'] = time();
        
        // Clean old data
        $stats = $this->cleanOldData($stats);
        
        $this->saveStats($domain, $stats);
        
        $this->logger->debug('Scrape success recorded', [
            'domain' => $domain,
            'recent_success_count' => count($stats['successes']),
        ]);
    }

    /**
     * Record a failed scrape
     * 
     * @param string $domain Domain that was scraped
     * @param int $httpCode HTTP status code (0 for non-HTTP errors)
     * @param string $reason Human-readable failure reason
     * @return bool False if domain should now be paused
     */
    public function recordFailure(string $domain, int $httpCode = 0, string $reason = ''): bool
    {
        $domain = $this->normalizeDomain($domain);
        $stats = $this->getStats($domain);
        
        $stats['failures'][] = [
            'time' => time(),
            'code' => $httpCode,
            'reason' => $reason,
        ];
        $stats['consecutive_failures']++;
        $stats['last_failure'] = time();
        
        // Track 403s specifically
        if ($httpCode === 403) {
            $stats['403_count']++;
        }
        
        // Clean old data
        $stats = $this->cleanOldData($stats);
        
        $this->saveStats($domain, $stats);
        
        $this->logger->warning('Scrape failure recorded', [
            'domain' => $domain,
            'consecutive' => $stats['consecutive_failures'],
            'http_code' => $httpCode,
            'reason' => $reason,
        ]);
        
        // Check if we need to trigger circuit breaker
        return $this->checkCircuitBreaker($domain, $stats, $httpCode);
    }

    /**
     * Manually pause scraping for a domain
     */
    public function pauseDomain(string $domain, string $reason = 'Manual pause'): void
    {
        $domain = $this->normalizeDomain($domain);
        $stats = $this->getStats($domain);
        
        $stats['paused'] = true;
        $stats['pause_reason'] = $reason;
        $stats['paused_at'] = time();
        
        $this->saveStats($domain, $stats);
        
        $this->logger->info('Domain scraping paused', [
            'domain' => $domain,
            'reason' => $reason,
        ]);
        
        $this->createAlert($domain, 'manual_pause', $reason);
    }

    /**
     * Resume scraping for a domain
     */
    public function resumeDomain(string $domain): void
    {
        $domain = $this->normalizeDomain($domain);
        $stats = $this->getStats($domain);
        
        $stats['paused'] = false;
        $stats['cooldown_until'] = null;
        $stats['consecutive_failures'] = 0;
        
        $this->saveStats($domain, $stats);
        
        $this->logger->info('Domain scraping resumed', ['domain' => $domain]);
    }

    /**
     * Get status for a domain
     *
     * @return array{domain: string, can_scrape: bool, paused: bool, pause_reason: string|null, cooldown_until: int|null, cooldown_remaining: int|null, consecutive_failures: int, recent_successes: int, recent_failures: int, success_rate: float|null, '403_count': int, last_success: int|null, last_failure: int|null}
     */
    public function getDomainStatus(string $domain): array
    {
        $domain = $this->normalizeDomain($domain);
        $stats = $this->getStats($domain);
        
        $recentSuccesses = count($stats['successes']);
        $recentFailures = count($stats['failures']);
        $total = $recentSuccesses + $recentFailures;
        
        return [
            'domain' => $domain,
            'can_scrape' => $this->canScrape($domain),
            'paused' => $stats['paused'],
            'pause_reason' => $stats['pause_reason'],
            'cooldown_until' => $stats['cooldown_until'],
            'cooldown_remaining' => $stats['cooldown_until'] !== null
                ? max(0, $stats['cooldown_until'] - time())
                : null,
            'consecutive_failures' => $stats['consecutive_failures'],
            'recent_successes' => $recentSuccesses,
            'recent_failures' => $recentFailures,
            'success_rate' => $total > 0 ? round($recentSuccesses / $total * 100, 1) : null,
            '403_count' => $stats['403_count'],
            'last_success' => $stats['last_success'],
            'last_failure' => $stats['last_failure'],
        ];
    }

    /**
     * Get status for all tracked domains
     *
     * @return array<string, array{domain: string, can_scrape: bool, paused: bool, pause_reason: string|null, cooldown_until: int|null, cooldown_remaining: int|null, consecutive_failures: int, recent_successes: int, recent_failures: int, success_rate: float|null, '403_count': int, last_success: int|null, last_failure: int|null}>
     */
    public function getAllDomainsStatus(): array
    {
        $result = [];
        
        // Get from cache - we store a list of all domains
        try {
            /** @var list<string> $domains */
            $domains = $this->cache->get(self::CACHE_PREFIX . 'domain_list', function(ItemInterface $item) {
                $item->expiresAfter(3600);
                return [];
            });
        } catch (\Exception $e) {
            $domains = array_keys($this->domainStats);
        }
        
        foreach ($domains as $domain) {
            $result[$domain] = $this->getDomainStatus($domain);
        }
        
        return $result;
    }

    /**
     * Get pending alerts
     *
     * @return list<Alert>
     */
    public function getAlerts(): array
    {
        try {
            /** @var list<Alert> $alerts */
            $alerts = $this->cache->get(self::CACHE_PREFIX . 'alerts', function(ItemInterface $item) {
                $item->expiresAfter(3600);
                return [];
            });

            return $alerts;
        } catch (\Exception $e) {
            return $this->alerts;
        }
    }

    /**
     * Clear alerts (after admin review)
     */
    public function clearAlerts(): void
    {
        $this->alerts = [];
        try {
            $this->cache->delete(self::CACHE_PREFIX . 'alerts');
        } catch (\Exception $e) {
            // Ignore cache errors
        }
    }

    /**
     * Reset all stats (for testing or fresh start)
     */
    public function resetAll(): void
    {
        $this->domainStats = [];
        $this->alerts = [];
        
        try {
            /** @var list<string> $domains */
            $domains = $this->cache->get(self::CACHE_PREFIX . 'domain_list', fn(): array => []);
            foreach ($domains as $domain) {
                $this->cache->delete(self::CACHE_PREFIX . 'stats_' . $domain);
            }
            $this->cache->delete(self::CACHE_PREFIX . 'domain_list');
            $this->cache->delete(self::CACHE_PREFIX . 'alerts');
        } catch (\Exception $e) {
            // Ignore cache errors
        }
    }

    // ==================== Private Methods ====================

    private function normalizeDomain(string $domain): string
    {
        // Extract domain from URL if full URL provided
        if (preg_match('/^https?:\/\//', $domain)) {
            $parsed = parse_url($domain);
            $host = $parsed['host'] ?? null;
            $domain = is_string($host) && $host !== '' ? $host : $domain;
        }
        
        // Remove www prefix
        return preg_replace('/^www\./', '', strtolower($domain)) ?? $domain;
    }

    /**
     * @return DomainStats
     */
    private function getStats(string $domain): array
    {
        // Try memory first
        if (isset($this->domainStats[$domain])) {
            return $this->domainStats[$domain];
        }
        
        // Try cache
        try {
            /** @var DomainStats $stats */
            $stats = $this->cache->get(
                self::CACHE_PREFIX . 'stats_' . $domain,
                function(ItemInterface $item) {
                    $item->expiresAfter(3600);
                    return $this->getDefaultStats();
                }
            );
            $this->domainStats[$domain] = $stats;
            return $stats;
        } catch (\Exception $e) {
            return $this->getDefaultStats();
        }
    }

    /**
     * @param DomainStats $stats
     */
    private function saveStats(string $domain, array $stats): void
    {
        $this->domainStats[$domain] = $stats;
        
        try {
            // Save stats
            $this->cache->delete(self::CACHE_PREFIX . 'stats_' . $domain);
            $this->cache->get(
                self::CACHE_PREFIX . 'stats_' . $domain,
                function(ItemInterface $item) use ($stats) {
                    $item->expiresAfter(3600);
                    return $stats;
                }
            );
            
            // Track domain in list
            /** @var list<string> $domains */
            $domains = $this->cache->get(self::CACHE_PREFIX . 'domain_list', fn(): array => []);
            if (!in_array($domain, $domains, true)) {
                $domains[] = $domain;
                $this->cache->delete(self::CACHE_PREFIX . 'domain_list');
                $this->cache->get(
                    self::CACHE_PREFIX . 'domain_list',
                    function(ItemInterface $item) use ($domains) {
                        $item->expiresAfter(3600);
                        return $domains;
                    }
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to save failsafe stats to cache', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return DomainStats
     */
    private function getDefaultStats(): array
    {
        return [
            'successes' => [],
            'failures' => [],
            'consecutive_failures' => 0,
            'paused' => false,
            'pause_reason' => null,
            'paused_at' => null,
            'cooldown_until' => null,
            '403_count' => 0,
            'last_success' => null,
            'last_failure' => null,
        ];
    }

    /**
     * @param DomainStats $stats
     * @return DomainStats
     */
    private function cleanOldData(array $stats): array
    {
        $cutoff = time() - self::RATE_WINDOW_SECONDS;
        
        // Clean successes
        $stats['successes'] = array_values(array_filter($stats['successes'], fn(int $t): bool => $t >= $cutoff));
        
        // Clean failures
        $stats['failures'] = array_values(array_filter($stats['failures'], fn(array $f): bool => $f['time'] >= $cutoff));
        
        return $stats;
    }

    /**
     * @param DomainStats $stats
     */
    private function checkCircuitBreaker(string $domain, array $stats, int $httpCode): bool
    {
        // Check 1: Consecutive failures
        if ($stats['consecutive_failures'] >= self::CONSECUTIVE_FAILURE_THRESHOLD) {
            $this->triggerCooldown($domain, self::ALERT_CONSECUTIVE_FAILURES, 
                "Consecutive failures: {$stats['consecutive_failures']}");
            return false;
        }
        
        // Check 2: 403 spike (immediate trigger on 403)
        if ($httpCode === 403) {
            // Count recent 403s
            $recent403s = count(array_filter($stats['failures'], fn(array $f): bool => $f['code'] === 403));
            if ($recent403s >= 2) {
                $this->triggerCooldown($domain, self::ALERT_403_SPIKE, 
                    "403 spike: {$recent403s} in last 5 minutes");
                return false;
            }
        }
        
        // Check 3: High failure rate
        $recentSuccesses = count($stats['successes']);
        $recentFailures = count($stats['failures']);
        $total = $recentSuccesses + $recentFailures;
        
        if ($total >= 5) { // Need minimum sample size
            $failureRate = $recentFailures / $total;
            if ($failureRate >= self::FAILURE_RATE_THRESHOLD) {
                $this->triggerCooldown($domain, self::ALERT_HIGH_FAILURE_RATE,
                    sprintf('Failure rate: %.1f%% (%d/%d)', $failureRate * 100, $recentFailures, $total));
                return false;
            }
        }
        
        return true; // Continue scraping
    }

    private function triggerCooldown(string $domain, string $alertType, string $reason): void
    {
        $stats = $this->getStats($domain);
        $stats['cooldown_until'] = time() + self::COOLDOWN_DURATION_SECONDS;
        $this->saveStats($domain, $stats);
        
        $this->logger->error('Circuit breaker triggered - domain in cooldown', [
            'domain' => $domain,
            'alert_type' => $alertType,
            'reason' => $reason,
            'cooldown_minutes' => self::COOLDOWN_DURATION_SECONDS / 60,
        ]);
        
        $this->createAlert($domain, $alertType, $reason);
    }

    private function createAlert(string $domain, string $type, string $message): void
    {
        $alert = [
            'domain' => $domain,
            'type' => $type,
            'message' => $message,
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
        ];
        
        $this->alerts[] = $alert;
        
        try {
            $alerts = $this->cache->get(self::CACHE_PREFIX . 'alerts', fn() => []);
            $alerts[] = $alert;
            $this->cache->delete(self::CACHE_PREFIX . 'alerts');
            $this->cache->get(
                self::CACHE_PREFIX . 'alerts',
                function(ItemInterface $item) use ($alerts) {
                    $item->expiresAfter(3600);
                    return $alerts;
                }
            );
        } catch (\Exception $e) {
            // Ignore cache errors
        }
    }
}
