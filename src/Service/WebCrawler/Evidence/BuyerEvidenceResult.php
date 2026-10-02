<?php

namespace App\Service\WebCrawler\Evidence;

/**
 * Result of the Buyer Evidence Gate evaluation.
 *
 * Encapsulates:
 *   - Positive evidence grouped by family (capped and raw)
 *   - Anti-evidence grouped by family
 *   - Effective anti-families after contextual suppression
 *   - Verdict + human-readable reason
 *   - Diagnostics/confidence for persistence and tuning
 */
final class BuyerEvidenceResult
{
    private readonly bool $passed;
    private readonly string $reason;

    /** @var array<string,int> */
    private readonly array $positiveFamilies;

    /** @var array<string,int> */
    private readonly array $rawPositiveFamilies;

    /** @var array<string,int> */
    private readonly array $antiFamilies;

    /** @var string[] */
    private readonly array $effectiveAntiFamilies;

    /** @var string[] */
    private readonly array $suppressedAntiFamilies;

    private readonly int $totalPositiveScore;
    private readonly int $rawTotalPositiveScore;
    private readonly int $totalAntiScore;
    private readonly float $netScore;
    private readonly float $antiPressure;
    private readonly string $confidence;

    /** @var array<string,mixed> */
    private readonly array $diagnostics;

    /**
     * @param EvidenceItem[] $evidence
     * @param EvidenceItem[] $antiEvidence
     * @param array<string,mixed> $context
     */
    public function __construct(
        private readonly array $evidence,
        private readonly array $antiEvidence,
        private readonly string $companyName,
        private readonly string $domain,
        private readonly array $context = [],
    ) {
        // ─────────────────────────────────────────────────────────────
        // Aggregate POSITIVE evidence (raw)
        // ─────────────────────────────────────────────────────────────
        $rawPosFamilies = [];
        $rawTotalPos = 0;
        $posSourcesByFamily = [];
        $posSignalsByFamily = [];

        foreach ($this->evidence as $item) {
            $rawPosFamilies[$item->family] = ($rawPosFamilies[$item->family] ?? 0) + max(0, (int) $item->weight);
            $rawTotalPos += max(0, (int) $item->weight);

            $sourceSegment = $this->extractSourceSegment($item->source);
            $posSourcesByFamily[$item->family][$sourceSegment] = true;
            $posSignalsByFamily[$item->family][$item->signal] = true;
        }

        // Apply per-family caps (prevents inflation from many generic matches)
        $cappedPosFamilies = [];
        $cappedTotalPos = 0;
        $caps = defined(BuyerEvidenceGate::class . '::FAMILY_SCORE_CAPS')
            ? BuyerEvidenceGate::FAMILY_SCORE_CAPS
            : [];

        foreach ($rawPosFamilies as $family => $score) {
            $cap = $caps[$family] ?? $score;
            $capped = min($score, $cap);
            $cappedPosFamilies[$family] = $capped;
            $cappedTotalPos += $capped;
        }

        // ─────────────────────────────────────────────────────────────
        // Aggregate ANTI evidence
        // ─────────────────────────────────────────────────────────────
        $antFamilies = [];
        $antiTotal = 0;
        $antiSourcesByFamily = [];
        $antiSignalsByFamily = [];

        foreach ($this->antiEvidence as $item) {
            $w = abs((int) $item->weight);
            $antFamilies[$item->family] = ($antFamilies[$item->family] ?? 0) + $w;
            $antiTotal += $w;

            $sourceSegment = $this->extractSourceSegment($item->source);
            $antiSourcesByFamily[$item->family][$sourceSegment] = true;
            $antiSignalsByFamily[$item->family][$item->signal] = true;
        }

        $this->rawPositiveFamilies = $rawPosFamilies;
        $this->positiveFamilies = $cappedPosFamilies;
        $this->antiFamilies = $antFamilies;
        $this->rawTotalPositiveScore = $rawTotalPos;
        $this->totalPositiveScore = $cappedTotalPos;
        $this->totalAntiScore = $antiTotal;

        $distinctPosFamilies = count($cappedPosFamilies);
        $distinctAntiFamilies = count($antFamilies);

        $coreFamilies = array_values(array_intersect(
            array_keys($cappedPosFamilies),
            BuyerEvidenceGate::CORE_FAMILIES,
        ));

        $hardVetoAntiFamilies = array_values(array_intersect(
            array_keys($antFamilies),
            BuyerEvidenceGate::HARD_VETO_ANTI_FAMILIES,
        ));

        $softAntiFamilies = array_values(array_intersect(
            array_keys($antFamilies),
            BuyerEvidenceGate::SOFT_ANTI_FAMILIES,
        ));

        // Any anti family not explicitly soft is countable unless hard-veto triggers earlier.
        $otherCountableAntiFamilies = array_values(array_diff(
            array_keys($antFamilies),
            $hardVetoAntiFamilies,
            $softAntiFamilies,
        ));

        // ─────────────────────────────────────────────────────────────
        // Context-aware anti suppression (real OEMs often have:
        // distribution arms, news pages, spare parts shop, logistics pages)
        // ─────────────────────────────────────────────────────────────
        $mfgScore = $cappedPosFamilies['MANUFACTURING_OEM'] ?? 0;
        $productScore = $cappedPosFamilies['PRODUCT_PORTFOLIO'] ?? 0;
        $procureScore = $cappedPosFamilies['BUYER_PROCUREMENT'] ?? 0;
        $footprintScore = $cappedPosFamilies['ORG_FOOTPRINT'] ?? 0;
        $sectorScore = $cappedPosFamilies['SECTOR_ALIGNMENT'] ?? 0;
        $compositeScore = $cappedPosFamilies['BUYER_INTENT_COMPOSITE'] ?? 0;

        $hasStrongMfgEvidence =
            $mfgScore >= 16
            || ($mfgScore >= 10 && $productScore >= 12)
            || ($mfgScore + $productScore) >= 26;

        $hasStructuredSectorSignals =
            isset($cappedPosFamilies['ORG_FOOTPRINT'], $cappedPosFamilies['SECTOR_ALIGNMENT'])
            && $cappedTotalPos >= BuyerEvidenceGate::MIN_TOTAL_POSITIVE_SCORE;

        $hasIndustrialProfile =
            ($hasStrongMfgEvidence && ($footprintScore >= 6 || $sectorScore >= 8))
            || (($productScore >= 14 || $procureScore >= 12) && $sectorScore >= 8)
            || $compositeScore >= 8;

        $suppressedSoft = [];
        $effectiveSoftAnti = $softAntiFamilies;

        if ($hasIndustrialProfile && empty($hardVetoAntiFamilies)) {
            // Suppress only the soft anti families that most often appear on real OEM sites
            $suppressible = ['DISTRIBUTOR_RESELLER', 'LOGISTICS', 'RECYCLING', 'E_COMMERCE', 'NO_SECTOR_RELEVANCE'];
            $suppressedSoft = array_values(array_intersect($softAntiFamilies, $suppressible));

            $effectiveSoftAnti = array_values(array_diff($softAntiFamilies, $suppressedSoft));
        }

        $effectiveAntiFamilies = array_values(array_unique(array_merge(
            $hardVetoAntiFamilies,
            $otherCountableAntiFamilies,
            $effectiveSoftAnti,
        )));

        $distinctEffectiveAnti = count($effectiveAntiFamilies);

        // Dynamic anti tolerance (only for non-hard-veto cases)
        $dynamicMaxAntiFamilies = BuyerEvidenceGate::MAX_ANTI_FAMILIES;
        if (
            $cappedTotalPos >= 20
            && $distinctPosFamilies >= 3
            && count($coreFamilies) >= 1
            && $hasIndustrialProfile
        ) {
            $dynamicMaxAntiFamilies += 1;
        }

        // Net score (anti pressure matters, but positives dominate if strong/diverse)
        $antiPressureFactor = 0.35;
        if (!$hasIndustrialProfile && $distinctEffectiveAnti > 0) {
            $antiPressureFactor = 0.50;
        } elseif ($hasIndustrialProfile && $distinctEffectiveAnti > 0) {
            $antiPressureFactor = 0.25;
        }

        $netScore = (float) $cappedTotalPos - ((float) $antiTotal * $antiPressureFactor);
        $antiPressure = $cappedTotalPos > 0 ? ($antiTotal / max(1, $cappedTotalPos)) : (float) $antiTotal;

        $this->effectiveAntiFamilies = $effectiveAntiFamilies;
        $this->suppressedAntiFamilies = $suppressedSoft;
        $this->netScore = round($netScore, 2);
        $this->antiPressure = round($antiPressure, 2);

        // Generic-only pass prevention:
        // footprint-only companies with no buyer/manufacturing/product signals should fail.
        $onlyGenericFamilies = !empty($cappedPosFamilies) && count(array_diff(
            array_keys($cappedPosFamilies),
            ['ORG_FOOTPRINT', 'SECTOR_ALIGNMENT']
        )) === 0;

        // Source diversity helps confidence and pass robustness
        $sourceSegments = [];
        foreach ($this->evidence as $item) {
            $sourceSegments[$this->extractSourceSegment($item->source)] = true;
        }
        $positiveSourceDiversity = count($sourceSegments);

        // ─────────────────────────────────────────────────────────────
        // Decision logic
        // ─────────────────────────────────────────────────────────────
        if (!empty($hardVetoAntiFamilies)) {
            $this->passed = false;
            $this->reason = sprintf(
                'Hard-veto anti-evidence detected (%s)',
                implode(', ', $hardVetoAntiFamilies),
            );
        } elseif ($onlyGenericFamilies && count($coreFamilies) === 0) {
            $this->passed = false;
            $this->reason = 'Only generic footprint/sector evidence without buyer-intent signals';
        } elseif (count($coreFamilies) === 0 && !$hasStructuredSectorSignals && !$hasIndustrialProfile) {
            $this->passed = false;
            $this->reason = sprintf(
                'No core buyer-intent family (%s) and no strong structured/industrial profile',
                implode(', ', BuyerEvidenceGate::CORE_FAMILIES),
            );
        } elseif ($distinctPosFamilies < BuyerEvidenceGate::MIN_FAMILIES) {
            $this->passed = false;
            $this->reason = sprintf(
                'Only %d positive families (%s), need %d',
                $distinctPosFamilies,
                'none', // unreachable with >0: this branch requires zero positive families
                BuyerEvidenceGate::MIN_FAMILIES,
            );
        } elseif ($cappedTotalPos < BuyerEvidenceGate::MIN_TOTAL_POSITIVE_SCORE) {
            $this->passed = false;
            $this->reason = sprintf(
                'Positive score %d below minimum %d (raw=%d)',
                $cappedTotalPos,
                BuyerEvidenceGate::MIN_TOTAL_POSITIVE_SCORE,
                $rawTotalPos,
            );
        } elseif ($distinctEffectiveAnti > $dynamicMaxAntiFamilies) {
            $this->passed = false;
            $this->reason = sprintf(
                'Effective anti-evidence in %d families (%s) exceeds max %d',
                $distinctEffectiveAnti,
                implode(', ', $effectiveAntiFamilies),
                $dynamicMaxAntiFamilies,
            );
        } elseif ($netScore < (BuyerEvidenceGate::MIN_TOTAL_POSITIVE_SCORE - 1) && !$hasIndustrialProfile) {
            $this->passed = false;
            $this->reason = sprintf(
                'Net score %.2f too low after anti-evidence pressure (pos=%d, anti=%d)',
                $netScore,
                $cappedTotalPos,
                $antiTotal,
            );
        } else {
            $this->passed = true;

            if (count($coreFamilies) === 0 && ($hasStructuredSectorSignals || $hasIndustrialProfile)) {
                $this->reason = sprintf(
                    'Passed via structured/industrial profile (%d families, score=%d, net=%.2f)',
                    $distinctPosFamilies,
                    $cappedTotalPos,
                    $netScore,
                );
            } else {
                $this->reason = sprintf(
                    'Passed with %d families (%s), score=%d, net=%.2f',
                    $distinctPosFamilies,
                    implode(', ', array_keys($cappedPosFamilies)),
                    $cappedTotalPos,
                    $netScore,
                );
            }
        }

        // Confidence rating
        if (
            $this->passed
            && $cappedTotalPos >= 22
            && $distinctPosFamilies >= 3
            && $positiveSourceDiversity >= 2
            && $distinctEffectiveAnti === 0
        ) {
            $confidence = 'HIGH';
        } elseif (
            $this->passed
            && $cappedTotalPos >= 12
            && ($distinctPosFamilies >= 2 || $hasIndustrialProfile)
        ) {
            $confidence = 'MEDIUM';
        } elseif ($this->passed) {
            $confidence = 'LOW';
        } else {
            $confidence = 'REJECT';
        }

        $this->confidence = $confidence;

        $this->diagnostics = [
            'core_families' => $coreFamilies,
            'hard_veto_anti_families' => $hardVetoAntiFamilies,
            'soft_anti_families' => $softAntiFamilies,
            'suppressed_soft_anti_families' => $suppressedSoft,
            'other_countable_anti_families' => $otherCountableAntiFamilies,
            'effective_anti_families' => $effectiveAntiFamilies,
            'dynamic_max_anti_families' => $dynamicMaxAntiFamilies,
            'has_strong_mfg_evidence' => $hasStrongMfgEvidence,
            'has_structured_sector_signals' => $hasStructuredSectorSignals,
            'has_industrial_profile' => $hasIndustrialProfile,
            'positive_source_diversity' => $positiveSourceDiversity,
            'anti_pressure_factor' => $antiPressureFactor,
            'positive_sources_by_family' => $this->boolMapKeys($posSourcesByFamily),
            'anti_sources_by_family' => $this->boolMapKeys($antiSourcesByFamily),
            'positive_signal_counts_by_family' => array_map('count', $posSignalsByFamily),
            'anti_signal_counts_by_family' => array_map('count', $antiSignalsByFamily),
            'context' => $this->context,
        ];
    }

    public function passed(): bool
    {
        return $this->passed;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Capped positive family totals (used for decisioning)
     *
     * @return array<string,int>
     */
    public function getPositiveFamilies(): array
    {
        return $this->positiveFamilies;
    }

    /**
     * Raw positive family totals before caps (useful for tuning/debug)
     *
     * @return array<string,int>
     */
    public function getRawPositiveFamilies(): array
    {
        return $this->rawPositiveFamilies;
    }

    /**
     * @return array<string,int>
     */
    public function getAntiFamilies(): array
    {
        return $this->antiFamilies;
    }

    /**
     * @return string[]
     */
    public function getEffectiveAntiFamilies(): array
    {
        return $this->effectiveAntiFamilies;
    }

    /**
     * @return string[]
     */
    public function getSuppressedAntiFamilies(): array
    {
        return $this->suppressedAntiFamilies;
    }

    public function getDistinctPositiveFamilyCount(): int
    {
        return count($this->positiveFamilies);
    }

    public function getTotalPositiveScore(): int
    {
        return $this->totalPositiveScore;
    }

    public function getRawTotalPositiveScore(): int
    {
        return $this->rawTotalPositiveScore;
    }

    public function getTotalAntiScore(): int
    {
        return $this->totalAntiScore;
    }

    public function getNetScore(): float
    {
        return $this->netScore;
    }

    public function getAntiPressure(): float
    {
        return $this->antiPressure;
    }

    public function getConfidence(): string
    {
        return $this->confidence;
    }

    /**
     * @return array<string,mixed>
     */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * Serialize full trace for JSON persistence.
     *
     * Stored on Lead::qualityStack['buyer_evidence_gate'].
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'verdict'                  => $this->passed ? 'PASS' : 'FAIL',
            'confidence'               => $this->confidence,
            'reason'                   => $this->reason,
            'company'                  => $this->companyName,
            'domain'                   => $this->domain,

            // Positive scoring
            'positive_families'        => $this->positiveFamilies,       // capped
            'raw_positive_families'    => $this->rawPositiveFamilies,    // raw
            'distinct_positive'        => count($this->positiveFamilies),
            'total_positive_score'     => $this->totalPositiveScore,     // capped
            'raw_total_positive_score' => $this->rawTotalPositiveScore,  // raw

            // Anti scoring
            'anti_families'            => $this->antiFamilies,
            'distinct_anti'            => count($this->antiFamilies),
            'effective_anti_families'  => $this->effectiveAntiFamilies,
            'distinct_effective_anti'  => count($this->effectiveAntiFamilies),
            'suppressed_anti_families' => $this->suppressedAntiFamilies,
            'total_anti_score'         => $this->totalAntiScore,

            // Composite diagnostics
            'net_score'                => $this->netScore,
            'anti_pressure'            => $this->antiPressure,
            'diagnostics'              => $this->diagnostics,

            // Raw traces
            'evidence'                 => array_map(fn(EvidenceItem $e) => $e->toArray(), $this->evidence),
            'anti_evidence'            => array_map(fn(EvidenceItem $e) => $e->toArray(), $this->antiEvidence),

            'evaluated_at'             => (new \DateTimeImmutable())->format('c'),
        ];
    }

    private function extractSourceSegment(string $source): string
    {
        $parts = explode(':', $source, 2);
        return $parts[0] !== '' ? $parts[0] : $source;
    }

    /**
     * @param array<string,array<string,bool>> $map
     * @return array<string,string[]>
     */
    private function boolMapKeys(array $map): array
    {
        $out = [];
        foreach ($map as $family => $bools) {
            $out[$family] = array_keys($bools);
        }
        return $out;
    }
}