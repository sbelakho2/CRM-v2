<?php

namespace App\Service\WebCrawler\SearchProvider;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Intelligent rate limit manager for multi-engine web scraping.
 *
 * Features:
 *   - Per-engine failure tracking with exponential backoff
 *   - Adaptive delay calculation based on recent failures
 *   - Engine health scoring for intelligent ordering
 *   - Persistent cooldown state (survives across requests via APCu/file)
 *   - Jitter to prevent thundering herd
 *
 * Rate Limiting Strategy:
 *   - Base delay: 3-6 seconds between requests to same engine
 *   - Inter-engine delay: 1-3 seconds between different engines
 *   - Backoff multiplier: 2x after each failure, max 300s
 *   - Cooldown: 5-30 minutes after repeated failures
 */
final class RateLimitManager
{
    /** @var array<string, array{failures: int, lastFailure: float, backoff: float, successStreak: int}> */
    private array $engineState = [];

    /** Base delay between requests to same engine (seconds) */
    private const BASE_DELAY = 3.0;

    /** Maximum delay (seconds) */
    private const MAX_DELAY = 60.0;

    /** Maximum backoff cooldown (seconds) */
    private const MAX_COOLDOWN = 1800; // 30 minutes

    /** Failures before entering extended cooldown */
    private const FAILURE_THRESHOLD = 3;

    /** Success streak needed to reset failure count */
    private const SUCCESS_STREAK_RESET = 2;

    /** Jitter range (0.0 to 1.0 = percentage of delay to randomize) */
    private const JITTER_FACTOR = 0.3;

    /** State file path */
    private readonly string $stateFile;

    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ) {
        $this->stateFile = $projectDir . '/var/scraping_rate_limit_state.json';
        $this->loadState();
    }

    /**
     * Record a successful request to an engine.
     */
    public function recordSuccess(string $engine): void
    {
        $state = $this->getEngineState($engine);
        $state['successStreak']++;

        // Reset failure count after consecutive successes
        if ($state['successStreak'] >= self::SUCCESS_STREAK_RESET) {
            $state['failures'] = 0;
            $state['backoff'] = 0;
        }

        $this->engineState[$engine] = $state;
        $this->saveState();
    }

    /**
     * Record a failed request to an engine.
     *
     * @param string $engine Engine name
     * @param string $reason Failure reason (for logging)
     * @param bool $isSoftFailure True for empty results (not rate limit), false for hard errors
     */
    public function recordFailure(string $engine, string $reason, bool $isSoftFailure = false): void
    {
        $state = $this->getEngineState($engine);
        $state['successStreak'] = 0;
        $state['lastFailure'] = microtime(true);

        // Soft failures (empty results) increment slower
        if ($isSoftFailure) {
            $state['failures'] += 0.5;
        } else {
            $state['failures']++;
        }

        // Calculate exponential backoff
        if ($state['failures'] >= self::FAILURE_THRESHOLD) {
            $backoffMultiplier = pow(2, min($state['failures'] - self::FAILURE_THRESHOLD, 6));
            $state['backoff'] = min(self::BASE_DELAY * $backoffMultiplier * 10, self::MAX_COOLDOWN);

            $this->logger->warning("RateLimitManager: {$engine} entering backoff", [
                'failures' => $state['failures'],
                'backoff_seconds' => $state['backoff'],
                'reason' => $reason,
            ]);
        }

        $this->engineState[$engine] = $state;
        $this->saveState();
    }

    /**
     * Check if an engine is available (not in cooldown).
     */
    public function isEngineAvailable(string $engine): bool
    {
        $state = $this->getEngineState($engine);

        if ($state['backoff'] > 0 && $state['lastFailure'] > 0) {
            $elapsed = microtime(true) - $state['lastFailure'];
            if ($elapsed < $state['backoff']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get remaining cooldown time for an engine (seconds).
     */
    public function getCooldownRemaining(string $engine): float
    {
        $state = $this->getEngineState($engine);

        if ($state['backoff'] > 0 && $state['lastFailure'] > 0) {
            $elapsed = microtime(true) - $state['lastFailure'];
            $remaining = $state['backoff'] - $elapsed;
            return max(0, $remaining);
        }

        return 0;
    }

    /**
     * Get recommended delay before next request to this engine.
     */
    public function getRecommendedDelay(string $engine): float
    {
        $state = $this->getEngineState($engine);

        // Base delay with failure-based increase
        $delay = self::BASE_DELAY;

        // Increase delay based on recent failures
        if ($state['failures'] > 0) {
            $delay *= (1 + $state['failures'] * 0.5);
        }

        // Cap at maximum
        $delay = min($delay, self::MAX_DELAY);

        // Add jitter
        $jitter = $delay * self::JITTER_FACTOR * (mt_rand() / mt_getrandmax());
        $delay += $jitter;

        return $delay;
    }

    /**
     * Get health score for an engine (0-100, higher = healthier).
     *
     * Used to prioritize healthier engines when multiple are available.
     */
    public function getEngineHealthScore(string $engine): int
    {
        $state = $this->getEngineState($engine);

        $score = 100;

        // Reduce score based on failures
        $score -= min($state['failures'] * 15, 60);

        // Boost score based on success streak
        $score += min($state['successStreak'] * 10, 20);

        // Reduce if in backoff
        if ($state['backoff'] > 0) {
            $score -= 30;
        }

        return max(0, min(100, (int) $score));
    }

    /**
     * Get all available engines sorted by health (best first).
     *
     * @param string[] $engines List of engine names
     * @return string[] Sorted list of available engines
     */
    public function getAvailableEnginesByHealth(array $engines): array
    {
        $available = array_filter($engines, fn($e) => $this->isEngineAvailable($e));

        usort($available, function ($a, $b) {
            return $this->getEngineHealthScore($b) <=> $this->getEngineHealthScore($a);
        });

        return $available;
    }

    /**
     * Sleep for the recommended inter-engine delay.
     *
     * @param float $minDelay Minimum delay in seconds
     * @param float $maxJitter Maximum additional jitter in seconds
     */
    public function sleepInterEngine(float $minDelay = 1.0, float $maxJitter = 2.0): void
    {
        $delay = $minDelay + (mt_rand() / mt_getrandmax()) * $maxJitter;
        usleep((int)($delay * 1_000_000));
    }

    /**
     * Reset all engine states (for testing or manual recovery).
     */
    public function resetAll(): void
    {
        $this->engineState = [];
        $this->saveState();
    }

    /**
     * Reset state for a specific engine.
     */
    public function resetEngine(string $engine): void
    {
        unset($this->engineState[$engine]);
        $this->saveState();
    }

    /**
     * Get debug info for all engines.
     *
     * @return array<string, array{available: bool, health: int, cooldown: float, failures: int}>
     */
    public function getDebugInfo(): array
    {
        $info = [];
        foreach ($this->engineState as $engine => $state) {
            $info[$engine] = [
                'available' => $this->isEngineAvailable($engine),
                'health' => $this->getEngineHealthScore($engine),
                'cooldown' => round($this->getCooldownRemaining($engine), 1),
                'failures' => $state['failures'],
            ];
        }
        return $info;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────

    private function getEngineState(string $engine): array
    {
        return $this->engineState[$engine] ?? [
            'failures' => 0,
            'lastFailure' => 0.0,
            'backoff' => 0.0,
            'successStreak' => 0,
        ];
    }

    private function loadState(): void
    {
        if (file_exists($this->stateFile)) {
            $data = file_get_contents($this->stateFile);
            if ($data) {
                $decoded = json_decode($data, true);
                if (is_array($decoded)) {
                    // Expire old entries (older than 1 hour)
                    $now = microtime(true);
                    foreach ($decoded as $engine => $state) {
                        if (isset($state['lastFailure']) && $now - $state['lastFailure'] > 3600) {
                            unset($decoded[$engine]);
                        }
                    }
                    $this->engineState = $decoded;
                }
            }
        }
    }

    private function saveState(): void
    {
        $json = json_encode($this->engineState, JSON_PRETTY_PRINT);
        file_put_contents($this->stateFile, $json, LOCK_EX);
    }
}
