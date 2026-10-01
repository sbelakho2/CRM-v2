<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Observability;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tracks per-stage metrics for the webcrawler lead-discovery pipeline.
 *
 * Each "run" (typically one searchCompanies() call) accumulates:
 *   - Total candidates entering the pipeline
 *   - Per-gate accept / reject counts
 *   - Reason breakdown for rejections
 *   - Timing per stage
 *   - Final output count
 *
 * At the end of a run, call snapshot() to get an immutable copy of
 * all metrics, then reset() for the next run.
 *
 * The service is intentionally in-memory only (no DB, no Prometheus).
 * It emits structured log messages that can be consumed by any log
 * aggregator (ELK, Datadog, CloudWatch).
 */
final class PipelineMetricsCollector
{
    /**
     * Gate names tracked, in pipeline order.
     */
    public const STAGES = [
        'search_provider',
        'domain_block',
        'name_junk',
        'name_domain_mismatch',
        'competitor_type',
        'directory_seed',
        'company_classifier',
        'buyer_evidence',
        'service_product',
        'competitor_veto',
        'rule_engine',
        'language_detect',
        'homepage_verify',
        'subpage_scrape',
        'linkedin_enrich',
        'contact_quality',
    ];

    /** @var array<string, int> gate → accepted count */
    private array $accepted = [];

    /** @var array<string, int> gate → rejected count */
    private array $rejected = [];

    /** @var array<string, array<string, int>> gate → {reason → count} */
    private array $reasons = [];

    /** @var array<string, float> gate → cumulative time (seconds) */
    private array $timing = [];

    /** @var array<string, float> gate → start timestamp */
    private array $timers = [];

    private int $totalCandidates = 0;
    private int $finalOutput = 0;
    private float $runStartTime = 0.0;
    private float $runDuration = 0.0;
    private string $runId = '';

    /** @var list<array{level: string, message: string, context: array}> */
    private array $warnings = [];

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->reset();
    }

    // ──────────────────────────────────────────────────
    // Run lifecycle
    // ──────────────────────────────────────────────────

    /**
     * Start a new pipeline run.
     */
    public function startRun(?string $runId = null): void
    {
        $this->reset();
        $this->runId = $runId ?? bin2hex(random_bytes(8));
        $this->runStartTime = microtime(true);
    }

    /**
     * End the current pipeline run, log summary.
     */
    public function endRun(): void
    {
        $this->runDuration = microtime(true) - $this->runStartTime;

        $this->logger->info('PipelineMetrics: run complete', [
            'run_id'           => $this->runId,
            'total_candidates' => $this->totalCandidates,
            'final_output'     => $this->finalOutput,
            'pass_rate'        => $this->getPassRate(),
            'duration_sec'     => round($this->runDuration, 3),
            'stage_summary'    => $this->getStageSummary(),
        ]);
    }

    // ──────────────────────────────────────────────────
    // Recording
    // ──────────────────────────────────────────────────

    /**
     * Record total candidates entering the pipeline.
     */
    public function recordCandidates(int $count): void
    {
        $this->totalCandidates += $count;
    }

    /**
     * Record that a candidate was accepted by a gate.
     */
    public function recordAccept(string $gate): void
    {
        $this->accepted[$gate] = ($this->accepted[$gate] ?? 0) + 1;
    }

    /**
     * Record that a candidate was rejected by a gate.
     */
    public function recordReject(string $gate, string $reason = 'unspecified'): void
    {
        $this->rejected[$gate] = ($this->rejected[$gate] ?? 0) + 1;
        $this->reasons[$gate][$reason] = ($this->reasons[$gate][$reason] ?? 0) + 1;
    }

    /**
     * Record final output count.
     */
    public function recordOutput(int $count): void
    {
        $this->finalOutput = $count;
    }

    /**
     * Start timing a stage.
     */
    public function startTimer(string $gate): void
    {
        $this->timers[$gate] = microtime(true);
    }

    /**
     * Stop timing a stage.
     */
    public function stopTimer(string $gate): void
    {
        if (isset($this->timers[$gate])) {
            $elapsed = microtime(true) - $this->timers[$gate];
            $this->timing[$gate] = ($this->timing[$gate] ?? 0.0) + $elapsed;
            unset($this->timers[$gate]);
        }
    }

    /**
     * Record a warning (e.g. rate-limited, timeout, unexpected state).
     */
    public function recordWarning(string $message, array $context = []): void
    {
        $this->warnings[] = [
            'level'   => 'warning',
            'message' => $message,
            'context' => $context,
        ];
        $this->logger->warning('PipelineMetrics: ' . $message, $context);
    }

    // ──────────────────────────────────────────────────
    // Querying
    // ──────────────────────────────────────────────────

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function getTotalCandidates(): int
    {
        return $this->totalCandidates;
    }

    public function getFinalOutput(): int
    {
        return $this->finalOutput;
    }

    public function getAccepted(string $gate): int
    {
        return $this->accepted[$gate] ?? 0;
    }

    public function getRejected(string $gate): int
    {
        return $this->rejected[$gate] ?? 0;
    }

    /**
     * @return array<string, int> reason → count
     */
    public function getRejectReasons(string $gate): array
    {
        return $this->reasons[$gate] ?? [];
    }

    public function getStageTiming(string $gate): float
    {
        return $this->timing[$gate] ?? 0.0;
    }

    /**
     * Pass rate: finalOutput / totalCandidates.
     */
    public function getPassRate(): float
    {
        if ($this->totalCandidates === 0) return 0.0;
        return round($this->finalOutput / $this->totalCandidates, 4);
    }

    /**
     * Get rejection funnel: for each stage, how many were rejected.
     *
     * @return array<string, array{accepted: int, rejected: int, time_ms: float}>
     */
    public function getStageSummary(): array
    {
        $summary = [];
        foreach (self::STAGES as $gate) {
            $a = $this->accepted[$gate] ?? 0;
            $r = $this->rejected[$gate] ?? 0;
            if ($a === 0 && $r === 0) continue;

            $summary[$gate] = [
                'accepted' => $a,
                'rejected' => $r,
                'time_ms'  => round(($this->timing[$gate] ?? 0.0) * 1000, 2),
            ];
        }
        return $summary;
    }

    /**
     * Get a full snapshot of the run for serialization/reporting.
     */
    public function snapshot(): array
    {
        return [
            'run_id'           => $this->runId,
            'total_candidates' => $this->totalCandidates,
            'final_output'     => $this->finalOutput,
            'pass_rate'        => $this->getPassRate(),
            'duration_sec'     => round($this->runDuration ?: (microtime(true) - $this->runStartTime), 3),
            'stages'           => $this->getStageSummary(),
            'reject_reasons'   => array_filter($this->reasons),
            'warnings'         => $this->warnings,
        ];
    }

    /**
     * Format snapshot as human-readable text.
     */
    public function toSummaryString(): string
    {
        $snap = $this->snapshot();
        $lines = [];
        $lines[] = sprintf('Pipeline Run: %s', $snap['run_id']);
        $lines[] = sprintf('Candidates: %d → Output: %d (%.1f%% pass)',
            $snap['total_candidates'], $snap['final_output'], $snap['pass_rate'] * 100);
        $lines[] = sprintf('Duration: %.2fs', $snap['duration_sec']);
        $lines[] = '';
        $lines[] = 'Stage Funnel:';
        foreach ($snap['stages'] as $gate => $data) {
            $lines[] = sprintf('  %-25s  ✓ %3d  ✗ %3d  (%5.1fms)',
                $gate, $data['accepted'], $data['rejected'], $data['time_ms']);
        }

        if (!empty($snap['reject_reasons'])) {
            $lines[] = '';
            $lines[] = 'Top Rejection Reasons:';
            foreach ($snap['reject_reasons'] as $gate => $reasons) {
                arsort($reasons);
                foreach (array_slice($reasons, 0, 3, true) as $reason => $count) {
                    $lines[] = sprintf('  [%s] %s: %d', $gate, $reason, $count);
                }
            }
        }

        if (!empty($snap['warnings'])) {
            $lines[] = '';
            $lines[] = sprintf('Warnings: %d', count($snap['warnings']));
        }

        return implode("\n", $lines);
    }

    // ──────────────────────────────────────────────────
    // Reset
    // ──────────────────────────────────────────────────

    public function reset(): void
    {
        $this->accepted = [];
        $this->rejected = [];
        $this->reasons = [];
        $this->timing = [];
        $this->timers = [];
        $this->totalCandidates = 0;
        $this->finalOutput = 0;
        $this->runStartTime = 0.0;
        $this->runDuration = 0.0;
        $this->runId = '';
        $this->warnings = [];
    }
}
