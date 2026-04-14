<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

/**
 * Manufacturing‐evidence scoring result with family breakdown and veto info.
 */
final class EvidenceScore
{
    /**
     * @param array<string, int> $familyScores per-family score breakdown
     */
    public function __construct(
        private readonly int $totalScore,
        private readonly bool $pass,
        private readonly bool $vetoed,
        private readonly ?string $vetoReason,
        private readonly array $familyScores,
    ) {
    }

    public function getTotalScore(): int { return $this->totalScore; }
    public function isPassed(): bool     { return $this->pass; }
    public function isVetoed(): bool     { return $this->vetoed; }
    public function getVetoReason(): ?string { return $this->vetoReason; }

    /** @return array<string, int> */
    public function getFamilyScores(): array { return $this->familyScores; }
}
