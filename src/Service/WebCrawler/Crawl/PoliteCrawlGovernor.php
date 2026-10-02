<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Crawl;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enforces polite crawling limits per domain:
 *
 *  - Minimum delay between requests to the same domain
 *  - Maximum requests per domain per session
 *  - Respects Crawl-delay from robots.txt (if provided)
 *  - Exponential backoff on errors (429, 503)
 *
 * Thread-safe for single-process (not multi-process).
 */
final class PoliteCrawlGovernor
{
    /**
     * Default minimum delay between requests to the same domain (ms).
     */
    public const DEFAULT_DELAY_MS = 500;

    /**
     * Maximum delay after backoff (ms).
     */
    public const MAX_DELAY_MS = 10000;

    /**
     * Default maximum requests per domain per session.
     */
    public const DEFAULT_MAX_REQUESTS = 20;

    /**
     * Backoff multiplier on error.
     */
    private const BACKOFF_MULTIPLIER = 2.0;

    /** @var array<string, float> domain → last request timestamp (ms) */
    private array $lastRequestTime = [];

    /** @var array<string, int> domain → request count */
    private array $requestCounts = [];

    /** @var array<string, int> domain → current delay (ms) */
    private array $delays = [];

    /** @var array<string, int> domain → consecutive error count */
    private array $errorCounts = [];

    private LoggerInterface $logger;

    public function __construct(
        private int $defaultDelayMs = self::DEFAULT_DELAY_MS,
        private int $maxRequestsPerDomain = self::DEFAULT_MAX_REQUESTS,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    // ──────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────

    /**
     * Check if we're allowed to make another request to this domain.
     * Returns false if the domain has hit its request limit.
     */
    public function canRequest(string $domain): bool
    {
        $domain = $this->normalizeDomain($domain);
        $count = $this->requestCounts[$domain] ?? 0;

        return $count < $this->maxRequestsPerDomain;
    }

    /**
     * Wait (sleep) the appropriate amount of time before making a request.
     * Also increments the request counter.
     *
     * @return bool True if the request is allowed, false if limit exceeded.
     */
    public function throttle(string $domain): bool
    {
        $domain = $this->normalizeDomain($domain);

        if (!$this->canRequest($domain)) {
            $this->logger->debug('PoliteCrawlGovernor: domain limit reached', [
                'domain' => $domain,
                'count'  => $this->requestCounts[$domain] ?? 0,
                'max'    => $this->maxRequestsPerDomain,
            ]);
            return false;
        }

        $delay = $this->getDelay($domain);
        $now = $this->nowMs();
        $last = $this->lastRequestTime[$domain] ?? 0;
        $elapsed = $now - $last;

        if ($elapsed < $delay) {
            $sleepMs = (int) ($delay - $elapsed);
            $this->logger->debug('PoliteCrawlGovernor: sleeping', [
                'domain'   => $domain,
                'sleep_ms' => $sleepMs,
            ]);
            usleep($sleepMs * 1000);
        }

        // Record this request
        $this->lastRequestTime[$domain] = $this->nowMs();
        $this->requestCounts[$domain] = ($this->requestCounts[$domain] ?? 0) + 1;

        return true;
    }

    /**
     * Report a successful request (resets backoff for this domain).
     */
    public function reportSuccess(string $domain): void
    {
        $domain = $this->normalizeDomain($domain);
        $this->errorCounts[$domain] = 0;
        // Reset delay to default on success
        $this->delays[$domain] = $this->defaultDelayMs;
    }

    /**
     * Report an error/rate-limit for this domain (increases backoff).
     *
     * @param int $httpStatus The HTTP status code (429, 503, etc.)
     */
    public function reportError(string $domain, int $httpStatus = 0): void
    {
        $domain = $this->normalizeDomain($domain);
        $this->errorCounts[$domain] = ($this->errorCounts[$domain] ?? 0) + 1;

        $currentDelay = $this->getDelay($domain);
        $newDelay = (int) min(
            $currentDelay * self::BACKOFF_MULTIPLIER,
            self::MAX_DELAY_MS,
        );
        $this->delays[$domain] = $newDelay;

        $this->logger->info('PoliteCrawlGovernor: backoff increased', [
            'domain'      => $domain,
            'http_status' => $httpStatus,
            'errors'      => $this->errorCounts[$domain],
            'new_delay'   => $newDelay,
        ]);
    }

    /**
     * Set a specific delay for a domain (e.g. from robots.txt Crawl-delay).
     */
    public function setDomainDelay(string $domain, int $delayMs): void
    {
        $domain = $this->normalizeDomain($domain);
        $this->delays[$domain] = max($delayMs, 100); // Floor at 100ms
    }

    /**
     * Get the current delay for a domain.
     */
    public function getDelay(string $domain): int
    {
        $domain = $this->normalizeDomain($domain);
        return $this->delays[$domain] ?? $this->defaultDelayMs;
    }

    /**
     * Get the request count for a domain in this session.
     */
    public function getRequestCount(string $domain): int
    {
        $domain = $this->normalizeDomain($domain);
        return $this->requestCounts[$domain] ?? 0;
    }

    /**
     * Get the error count for a domain in this session.
     */
    public function getErrorCount(string $domain): int
    {
        $domain = $this->normalizeDomain($domain);
        return $this->errorCounts[$domain] ?? 0;
    }

    /**
     * Reset all counters (start a new crawling session).
     */
    public function reset(): void
    {
        $this->lastRequestTime = [];
        $this->requestCounts = [];
        $this->delays = [];
        $this->errorCounts = [];
    }

    /**
     * Get summary statistics for the session.
     *
     * @return array{total_requests: int, domains_crawled: int, domains_with_errors: int}
     */
    public function getStats(): array
    {
        return [
            'total_requests'      => array_sum($this->requestCounts),
            'domains_crawled'     => count($this->requestCounts),
            'domains_with_errors' => count(array_filter($this->errorCounts, fn(int $c) => $c > 0)),
        ];
    }

    // ──────────────────────────────────────────────────
    // Internal
    // ──────────────────────────────────────────────────

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = preg_replace('#/.*$#', '', $domain) ?? $domain;
        $domain = preg_replace('#^www\.#', '', $domain) ?? $domain;

        return $domain;
    }

    private function nowMs(): float
    {
        return microtime(true) * 1000;
    }
}
