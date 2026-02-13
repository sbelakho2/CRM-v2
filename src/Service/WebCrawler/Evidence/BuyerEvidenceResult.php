<?php

namespace App\Service\WebCrawler\Evidence;

/**
 * Result of the Buyer Evidence Gate evaluation.
 *
 * Encapsulates:
 *   - All positive evidence items grouped by family
 *   - All anti-evidence items grouped by family
 *   - The verdict (PASS / FAIL) with a human-readable reason
 *   - A complete trace suitable for persisting as JSON in qualityStack
 */
final class BuyerEvidenceResult
{
    private readonly bool $passed;
    private readonly string $reason;
    private readonly array $positiveFamilies;
    private readonly array $antiFamilies;
    private readonly int $totalPositiveScore;
    private readonly int $totalAntiScore;

    /**
     * @param EvidenceItem[] $evidence
     * @param EvidenceItem[] $antiEvidence
     */
    public function __construct(
        private readonly array $evidence,
        private readonly array $antiEvidence,
        private readonly string $companyName,
        private readonly string $domain,
    ) {
        // Group evidence by family
        $posFamilies = [];
        $totalPos = 0;
        foreach ($this->evidence as $item) {
            $posFamilies[$item->family] = ($posFamilies[$item->family] ?? 0) + $item->weight;
            $totalPos += $item->weight;
        }

        $antFamilies = [];
        $totalAnti = 0;
        foreach ($this->antiEvidence as $item) {
            $antFamilies[$item->family] = ($antFamilies[$item->family] ?? 0) + abs($item->weight);
            $totalAnti += abs($item->weight);
        }

        $this->positiveFamilies = $posFamilies;
        $this->antiFamilies = $antFamilies;
        $this->totalPositiveScore = $totalPos;
        $this->totalAntiScore = $totalAnti;

        $distinctPosFamilies = count($posFamilies);
        $distinctAntiFamilies = count($antFamilies);

        // Decision logic
        if ($distinctAntiFamilies > BuyerEvidenceGate::MAX_ANTI_FAMILIES) {
            $this->passed = false;
            $this->reason = sprintf(
                'Anti-evidence in %d families (%s) exceeds max %d',
                $distinctAntiFamilies,
                implode(', ', array_keys($antFamilies)),
                BuyerEvidenceGate::MAX_ANTI_FAMILIES,
            );
        } elseif ($distinctPosFamilies < BuyerEvidenceGate::MIN_FAMILIES) {
            $this->passed = false;
            $this->reason = sprintf(
                'Only %d positive families (%s), need %d',
                $distinctPosFamilies,
                $distinctPosFamilies > 0 ? implode(', ', array_keys($posFamilies)) : 'none',
                BuyerEvidenceGate::MIN_FAMILIES,
            );
        } else {
            $this->passed = true;
            $this->reason = sprintf(
                'Passed with %d families (%s)',
                $distinctPosFamilies,
                implode(', ', array_keys($posFamilies)),
            );
        }
    }

    public function passed(): bool
    {
        return $this->passed;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getPositiveFamilies(): array
    {
        return $this->positiveFamilies;
    }

    public function getAntiFamilies(): array
    {
        return $this->antiFamilies;
    }

    public function getDistinctPositiveFamilyCount(): int
    {
        return count($this->positiveFamilies);
    }

    public function getTotalPositiveScore(): int
    {
        return $this->totalPositiveScore;
    }

    public function getTotalAntiScore(): int
    {
        return $this->totalAntiScore;
    }

    /**
     * Serialize the full evidence trace for JSON persistence.
     *
     * Stored on Lead::qualityStack['buyer_evidence_gate'].
     */
    public function toArray(): array
    {
        return [
            'verdict'            => $this->passed ? 'PASS' : 'FAIL',
            'reason'             => $this->reason,
            'company'            => $this->companyName,
            'domain'             => $this->domain,
            'positive_families'  => $this->positiveFamilies,
            'anti_families'      => $this->antiFamilies,
            'distinct_positive'  => count($this->positiveFamilies),
            'distinct_anti'      => count($this->antiFamilies),
            'total_positive_score' => $this->totalPositiveScore,
            'total_anti_score'   => $this->totalAntiScore,
            'evidence'           => array_map(fn(EvidenceItem $e) => $e->toArray(), $this->evidence),
            'anti_evidence'      => array_map(fn(EvidenceItem $e) => $e->toArray(), $this->antiEvidence),
            'evaluated_at'       => (new \DateTimeImmutable())->format('c'),
        ];
    }
}
