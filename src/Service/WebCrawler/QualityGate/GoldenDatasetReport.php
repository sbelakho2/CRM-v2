<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\QualityGate;

/**
 * Immutable report produced by GoldenDatasetRunner.
 */
final class GoldenDatasetReport
{
/** @var list<array{name: string, domain: string, expected: string, actual: string, correct: bool, category: string, reject_gate: string|null, gates: array<string, array{passed: bool, detail: string}>}> */
    private array $results;
    private int $total;
    private int $correct;
    private int $truePositives;
    private int $falsePositives;
    private int $trueNegatives;
    private int $falseNegatives;

    /**
     * @param list<array{
     *   name: string,
     *   domain: string,
     *   expected: string,
     *   actual: string,
     *   correct: bool,
     *   category: string,
     *   reject_gate: ?string,
     *   gates: array<string, array{passed: bool, detail: string}>,
     * }> $results
     */
    public function __construct(array $results)
    {
        $this->results = $results;
        $this->total   = count($results);
        $this->correct = 0;
        $this->truePositives  = 0;
        $this->falsePositives = 0;
        $this->trueNegatives  = 0;
        $this->falseNegatives = 0;

        foreach ($results as $r) {
            if ($r['correct']) {
                $this->correct++;
            }

            // "Positive" = pipeline says PASS (accepted as buyer)
            // "Negative" = pipeline says REJECT
            $actualPass   = ($r['actual'] === 'PASS');
            $expectedPass = ($r['expected'] === 'PASS');

            if ($actualPass && $expectedPass) {
                $this->truePositives++;
            } elseif ($actualPass) {
                $this->falsePositives++;  // accepted but expected a reject
            } elseif (!$expectedPass) {
                $this->trueNegatives++;
            } else {
                $this->falseNegatives++;  // rejected but expected a pass
            }
        }
    }

    // ── Accessors ─────────────────────────────────────

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getCorrect(): int
    {
        return $this->correct;
    }

    public function getAccuracy(): float
    {
        return $this->total > 0 ? $this->correct / $this->total : 0.0;
    }

    public function getAccuracyPercent(): float
    {
        return round($this->getAccuracy() * 100, 1);
    }

    public function getTruePositives(): int
    {
        return $this->truePositives;
    }

    public function getFalsePositives(): int
    {
        return $this->falsePositives;
    }

    public function getTrueNegatives(): int
    {
        return $this->trueNegatives;
    }

    public function getFalseNegatives(): int
    {
        return $this->falseNegatives;
    }

    /**
     * Precision = TP / (TP + FP).
     * "Of everything the pipeline accepted, how many were truly good?"
     */
    public function getPrecision(): float
    {
        $denom = $this->truePositives + $this->falsePositives;
        return $denom > 0 ? $this->truePositives / $denom : 0.0;
    }

    /**
     * Recall = TP / (TP + FN).
     * "Of all the truly good companies, how many did the pipeline accept?"
     */
    public function getRecall(): float
    {
        $denom = $this->truePositives + $this->falseNegatives;
        return $denom > 0 ? $this->truePositives / $denom : 0.0;
    }

    /**
     * F1 = harmonic mean of precision and recall.
     */
    public function getF1(): float
    {
        $p = $this->getPrecision();
        $r = $this->getRecall();
        return ($p + $r) > 0 ? 2 * $p * $r / ($p + $r) : 0.0;
    }

    /**
     * True if every single entry was classified correctly.
     */
    public function isPerfect(): bool
    {
        return $this->correct === $this->total;
    }

    /**
     * True if accuracy >= threshold.
     */
    public function meetsThreshold(float $threshold = 0.90): bool
    {
        return $this->getAccuracy() >= $threshold;
    }

    // ── Failures ──────────────────────────────────────

    /**
     * @return list<array{name: string, domain: string, expected: string, actual: string, correct: bool, category: string, reject_gate: string|null, gates: array<string, array{passed: bool, detail: string}>}> Only entries where actual ≠ expected.
     */
    public function getFailures(): array
    {
        return array_values(array_filter($this->results, fn(array $r) => !$r['correct']));
    }

    /**
     * @return list<array{name: string, domain: string, expected: string, actual: string, correct: bool, category: string, reject_gate: string|null, gates: array<string, array{passed: bool, detail: string}>}> All results.
     */
    public function getResults(): array
    {
        return $this->results;
    }

    // ── Rendering ─────────────────────────────────────

    /**
     * Produce a human-readable summary string.
     */
    public function toSummaryString(): string
    {
        $lines = [];
        $lines[] = sprintf(
            'Golden Dataset Report: %d/%d correct (%.1f%%)',
            $this->correct,
            $this->total,
            $this->getAccuracyPercent(),
        );
        $lines[] = sprintf(
            '  Precision=%.2f  Recall=%.2f  F1=%.2f',
            $this->getPrecision(),
            $this->getRecall(),
            $this->getF1(),
        );
        $lines[] = sprintf(
            '  TP=%d  FP=%d  TN=%d  FN=%d',
            $this->truePositives,
            $this->falsePositives,
            $this->trueNegatives,
            $this->falseNegatives,
        );

        $failures = $this->getFailures();
        if (count($failures) > 0) {
            $lines[] = '';
            $lines[] = 'FAILURES:';
            foreach ($failures as $f) {
                $lines[] = sprintf(
                    '  ✗ %s (%s) — expected %s, got %s [%s] gate=%s',
                    $f['name'],
                    $f['domain'],
                    $f['expected'],
                    $f['actual'],
                    $f['category'],
                    $f['reject_gate'] ?? 'none',
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{total: int, correct: int, accuracy: float, precision: float, recall: float, f1: float, tp: int, fp: int, tn: int, fn: int, failures: list<array{name: string, domain: string, expected: string, actual: string, correct: bool, category: string, reject_gate: string|null, gates: array<string, array{passed: bool, detail: string}>}>} Serializable summary.
     */
    public function toArray(): array
    {
        return [
            'total'      => $this->total,
            'correct'    => $this->correct,
            'accuracy'   => $this->getAccuracyPercent(),
            'precision'  => round($this->getPrecision(), 3),
            'recall'     => round($this->getRecall(), 3),
            'f1'         => round($this->getF1(), 3),
            'tp'         => $this->truePositives,
            'fp'         => $this->falsePositives,
            'tn'         => $this->trueNegatives,
            'fn'         => $this->falseNegatives,
            'failures'   => $this->getFailures(),
        ];
    }
}
