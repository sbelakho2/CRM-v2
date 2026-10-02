<?php

namespace App\Service\WebCrawler\Rules;

/**
 * Verdict from the RuleEngine after evaluating all rules.
 */
final class RuleEngineVerdict
{
    /**
     * @param RuleResult[] $firedRules  All rules that matched
     * @param string       $verdict     'PASS' | 'REJECT'
     * @param string       $reason      Human-readable explanation
     * @param int          $totalScore  Sum of all weights
     */
    public function __construct(
        public readonly array  $firedRules,
        public readonly string $verdict,
        public readonly string $reason,
        public readonly int    $totalScore,
    ) {
    }

    public function isRejected(): bool
    {
        return $this->verdict === 'REJECT';
    }

    /**
     * @return array{verdict: string, reason: string, total_score: int, rules_fired: list<array<array-key, mixed>>, evaluated_at: string}
     */
    public function toArray(): array
    {
        /** @var list<array<array-key, mixed>> $rulesFired */
        $rulesFired = array_map(fn(RuleResult $r) => $r->toArray(), $this->firedRules);

        return [
            'verdict'     => $this->verdict,
            'reason'      => $this->reason,
            'total_score' => $this->totalScore,
            'rules_fired' => $rulesFired,
            'evaluated_at' => (new \DateTimeImmutable())->format('c'),
        ];
    }
}
