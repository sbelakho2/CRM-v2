<?php

namespace App\Service\CompCrawler;

use App\Entity\Competitor;
use App\Repository\CompetitorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * CompScoringService — Compute ThreatScore, OverlapScore, StrategicRelevanceScore.
 *
 * Each score is 0–100. Weights are loaded from compcrawler_config.yaml.
 * Scores are computed against the Starz reference profile.
 */
class CompScoringService
{
    private array $starzCapabilities;
    private array $starzCertifications;
    private array $starzRegions;
    private array $starzIndustries;
    private array $starzPositioning;

    public function __construct(
        private readonly CompCrawlerConfig $config,
        private readonly CompetitorRepository $competitorRepo,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        $ref = $this->config->getStarzReference();
        $this->starzCapabilities = array_map('strtolower', $ref['capabilities'] ?? []);
        $this->starzCertifications = array_map('strtolower', $ref['certifications'] ?? []);
        $this->starzRegions = array_map('strtolower', $ref['regions'] ?? []);
        $this->starzIndustries = array_map('strtolower', $ref['industries'] ?? []);
        $this->starzPositioning = array_map('strtolower', $ref['positioning'] ?? []);
    }

    /**
     * Score a competitor on all three axes.
     *
     * @return array{threat: int, overlap: int, strategic: int, breakdown: array}
     */
    public function score(Competitor $competitor): array
    {
        $threat = $this->computeThreatScore($competitor);
        $overlap = $this->computeOverlapScore($competitor);
        $strategic = $this->computeStrategicRelevance($competitor);

        // Apply to entity (clamped 0–100 by entity setter)
        $competitor->setThreatScore($threat['score']);
        $competitor->setOverlapScore($overlap['score']);
        $competitor->setStrategicRelevanceScore($strategic['score']);

        $this->logger->info('CompScoring: {domain} → threat={t} overlap={o} strategic={s}', [
            'domain' => $competitor->getCanonicalDomain(),
            't' => $threat['score'],
            'o' => $overlap['score'],
            's' => $strategic['score'],
        ]);

        return [
            'threat' => $threat['score'],
            'overlap' => $overlap['score'],
            'strategic' => $strategic['score'],
            'breakdown' => [
                'threat' => $threat,
                'overlap' => $overlap,
                'strategic' => $strategic,
            ],
        ];
    }

    // ─── Threat Score ──────────────────────────────────────────────────────────

    private function computeThreatScore(Competitor $competitor): array
    {
        $weights = $this->config->get('scoring.threat', []);
        $detail = [];

        // 1. Capability overlap (what % of OUR caps do they also have?)
        $capOverlap = $this->computeCapabilityOverlap($competitor);
        $detail['capability_overlap'] = $capOverlap;

        // 2. Region + Industry overlap
        $regionIndustry = $this->computeRegionIndustryOverlap($competitor);
        $detail['region_industry_overlap'] = $regionIndustry;

        // 3. Certification gap (certs they have that match/exceed ours)
        $certGap = $this->computeCertificationAlignment($competitor);
        $detail['certification_gap'] = $certGap;

        // 4. Proof strength
        $proofStrength = $this->computeProofStrength($competitor);
        $detail['proof_strength'] = $proofStrength;

        // 5. Scale/capacity
        $scaleCapacity = $this->computeScaleCapacity($competitor);
        $detail['scale_capacity'] = $scaleCapacity;

        $score = (int) round(
            ($capOverlap * ($weights['capability_overlap'] ?? 40) / 100) +
            ($regionIndustry * ($weights['region_industry_overlap'] ?? 25) / 100) +
            ($certGap * ($weights['certification_gap'] ?? 15) / 100) +
            ($proofStrength * ($weights['proof_strength'] ?? 10) / 100) +
            ($scaleCapacity * ($weights['scale_capacity'] ?? 10) / 100)
        );

        return ['score' => min(100, max(0, $score)), 'detail' => $detail];
    }

    // ─── Overlap Score ─────────────────────────────────────────────────────────

    private function computeOverlapScore(Competitor $competitor): array
    {
        $weights = $this->config->get('scoring.overlap', []);
        $detail = [];

        // 1. Capability similarity (Jaccard-like)
        $capSim = $this->computeCapabilitySimilarity($competitor);
        $detail['capability_similarity'] = $capSim;

        // 2. Region overlap
        $regionOvl = $this->computeRegionOverlap($competitor);
        $detail['region_overlap'] = $regionOvl;

        // 3. Sector overlap
        $sectorOvl = $this->computeSectorOverlap($competitor);
        $detail['sector_overlap'] = $sectorOvl;

        $score = (int) round(
            ($capSim * ($weights['capability_similarity'] ?? 40) / 100) +
            ($regionOvl * ($weights['region_overlap'] ?? 30) / 100) +
            ($sectorOvl * ($weights['sector_overlap'] ?? 30) / 100)
        );

        return ['score' => min(100, max(0, $score)), 'detail' => $detail];
    }

    // ─── Strategic Relevance ───────────────────────────────────────────────────

    private function computeStrategicRelevance(Competitor $competitor): array
    {
        $weights = $this->config->get('scoring.strategic_relevance', []);
        $detail = [];

        // 1. Partner potential (complementary capabilities)
        $partnerPotential = $this->computePartnerPotential($competitor);
        $detail['partner_potential'] = $partnerPotential;

        // 2. Future threat (growth signals, expansion patterns)
        $futureThreat = $this->computeFutureThreat($competitor);
        $detail['future_threat'] = $futureThreat;

        // 3. Market position (size, proof grade, certs)
        $marketPosition = $this->computeMarketPosition($competitor);
        $detail['market_position'] = $marketPosition;

        // 4. Growth signals
        $growthSignals = $this->computeGrowthSignals($competitor);
        $detail['growth_signals'] = $growthSignals;

        $score = (int) round(
            ($partnerPotential * ($weights['partner_potential'] ?? 30) / 100) +
            ($futureThreat * ($weights['future_threat'] ?? 30) / 100) +
            ($marketPosition * ($weights['market_position'] ?? 20) / 100) +
            ($growthSignals * ($weights['growth_signals'] ?? 20) / 100)
        );

        return ['score' => min(100, max(0, $score)), 'detail' => $detail];
    }

    // ─── Component Computations ────────────────────────────────────────────────

    /**
     * What percentage of Starz capabilities does the competitor also have?
     */
    private function computeCapabilityOverlap(Competitor $competitor): float
    {
        $theirCaps = array_map('strtolower', $competitor->getCapabilities());
        if (empty($this->starzCapabilities)) return 0;

        $matched = 0;
        foreach ($this->starzCapabilities as $ourCap) {
            foreach ($theirCaps as $theirCap) {
                if (str_contains($theirCap, $ourCap) || str_contains($ourCap, $theirCap)) {
                    $matched++;
                    break;
                }
            }
        }

        return ($matched / count($this->starzCapabilities)) * 100;
    }

    /**
     * Jaccard similarity between capability sets.
     */
    private function computeCapabilitySimilarity(Competitor $competitor): float
    {
        $theirCaps = array_map('strtolower', $competitor->getCapabilities());
        if (empty($theirCaps) && empty($this->starzCapabilities)) return 0;

        $union = array_unique(array_merge($this->starzCapabilities, $theirCaps));
        if (empty($union)) return 0;

        $intersection = 0;
        foreach ($this->starzCapabilities as $cap) {
            foreach ($theirCaps as $tc) {
                if (str_contains($tc, $cap) || str_contains($cap, $tc)) {
                    $intersection++;
                    break;
                }
            }
        }

        return ($intersection / count($union)) * 100;
    }

    private function computeRegionIndustryOverlap(Competitor $competitor): float
    {
        $regionScore = $this->computeRegionOverlap($competitor);
        $industryScore = $this->computeSectorOverlap($competitor);
        return ($regionScore + $industryScore) / 2;
    }

    private function computeRegionOverlap(Competitor $competitor): float
    {
        $theirRegions = array_map('strtolower', $competitor->getRegions());
        $theirCountry = strtolower($competitor->getHqCountry() ?? '');

        if (empty($theirRegions) && empty($theirCountry)) return 0;

        // Direct region match
        foreach ($this->starzRegions as $ourRegion) {
            foreach ($theirRegions as $theirRegion) {
                if ($theirRegion === $ourRegion) return 100;
                if (str_contains($theirRegion, $ourRegion) || str_contains($ourRegion, $theirRegion)) return 80;
            }
            if (str_contains($theirCountry, $ourRegion)) return 80;
        }

        // Nearshore proximity scoring
        $nearshoreRegions = ['europe_west', 'europe_south', 'europe_central', 'africa_north', 'middle_east'];
        foreach ($nearshoreRegions as $near) {
            foreach ($theirRegions as $theirRegion) {
                if ($theirRegion === $near) return 50;
            }
        }

        return 10; // Some baseline for having a known region
    }

    private function computeSectorOverlap(Competitor $competitor): float
    {
        $theirIndustries = array_map('strtolower', $competitor->getIndustries());
        if (empty($theirIndustries)) return 0;

        $matched = 0;
        foreach ($this->starzIndustries as $ourIndustry) {
            foreach ($theirIndustries as $theirs) {
                if (str_contains($theirs, $ourIndustry) || str_contains($ourIndustry, $theirs)) {
                    $matched++;
                    break;
                }
            }
        }

        if (empty($this->starzIndustries)) return 0;
        return ($matched / count($this->starzIndustries)) * 100;
    }

    private function computeCertificationAlignment(Competitor $competitor): float
    {
        $theirCerts = array_map('strtolower', $competitor->getCertifications());
        if (empty($theirCerts)) return 0;

        $matched = 0;
        foreach ($this->starzCertifications as $ourCert) {
            foreach ($theirCerts as $theirCert) {
                if (str_contains($theirCert, $ourCert) || str_contains($ourCert, $theirCert)) {
                    $matched++;
                    break;
                }
            }
        }

        // Higher score if they match or exceed our certs
        if (empty($this->starzCertifications)) return 0;
        $matchRatio = $matched / count($this->starzCertifications);

        // Bonus for having MORE certs
        $extraCerts = max(0, count($theirCerts) - count($this->starzCertifications));
        $extraBonus = min(20, $extraCerts * 5);

        return min(100, ($matchRatio * 80) + $extraBonus);
    }

    private function computeProofStrength(Competitor $competitor): float
    {
        $grade = $competitor->getProofGrade();
        return match ($grade) {
            'A' => 100,
            'B' => 75,
            'C' => 50,
            'D' => 25,
            default => 10,
        };
    }

    private function computeScaleCapacity(Competitor $competitor): float
    {
        $employees = $competitor->getEmployeeEstimate();
        if (!$employees) return 30; // Unknown = moderate assumption

        // Scale scoring: larger = more competitive threat
        if ($employees > 5000) return 100;
        if ($employees > 1000) return 80;
        if ($employees > 500) return 65;
        if ($employees > 200) return 50;
        if ($employees > 50) return 35;
        return 20;
    }

    private function computePartnerPotential(Competitor $competitor): float
    {
        $theirCaps = array_map('strtolower', $competitor->getCapabilities());
        if (empty($theirCaps)) return 20;

        // Count capabilities they have that we DON'T
        $complementary = 0;
        foreach ($theirCaps as $cap) {
            $isOurs = false;
            foreach ($this->starzCapabilities as $ourCap) {
                if (str_contains($cap, $ourCap) || str_contains($ourCap, $cap)) {
                    $isOurs = true;
                    break;
                }
            }
            if (!$isOurs) $complementary++;
        }

        // High partner potential = many complementary + some overlapping (trust)
        $overlap = count($theirCaps) - $complementary;
        if ($complementary > 0 && $overlap > 0) {
            return min(100, ($complementary * 15) + ($overlap * 5));
        }

        return min(100, $complementary * 10);
    }

    private function computeFutureThreat(Competitor $competitor): float
    {
        $score = 0;

        // Growing company = higher future threat
        $employees = $competitor->getEmployeeEstimate();
        if ($employees && $employees > 100) $score += 20;

        // Multi-site = expanding (use actual regions + facilities, not capabilities)
        $locations = count($competitor->getRegions()) + count($competitor->getFacilities());
        if ($locations > 3) $score += 15;

        // High proof grade = serious player
        if (in_array($competitor->getProofGrade(), ['A', 'B'])) $score += 20;

        // Many certifications = investing in quality
        if (count($competitor->getCertifications()) >= 3) $score += 20;

        // Active website (recently crawled successfully)
        if ($competitor->getLastCrawledAt() && $competitor->getPagesCrawled() > 5) $score += 10;

        // Direct competitor
        if ($competitor->getDirectness() === 'head_to_head') $score += 15;

        return min(100, $score);
    }

    private function computeMarketPosition(Competitor $competitor): float
    {
        $score = 0;

        $employees = $competitor->getEmployeeEstimate();
        if ($employees > 2000) $score += 40;
        elseif ($employees > 500) $score += 30;
        elseif ($employees > 100) $score += 20;
        elseif ($employees) $score += 10;

        // Certification strength
        $score += min(30, count($competitor->getCertifications()) * 5);

        // Industry breadth
        $score += min(30, count($competitor->getIndustries()) * 5);

        return min(100, $score);
    }

    private function computeGrowthSignals(Competitor $competitor): float
    {
        $score = 0;

        // Has active job listings (career pages found)
        $caps = $competitor->getCapabilities();
        // We'll infer from page data in future; for now use proxy signals

        // Multiple competitor types = diversifying
        if (count($competitor->getCompetitorTypes()) > 1) $score += 25;

        // Recently discovered (newer = might be expanding)
        $discovered = $competitor->getCreatedAt();
        if ($discovered && $discovered > new \DateTime('-90 days')) $score += 20;

        // Multi-region presence
        if (!empty($competitor->getAltDomains())) $score += 15;

        // High page count = substantial web presence
        if ($competitor->getPagesCrawled() > 20) $score += 20;

        // Active change events would be the strongest signal, but that's handled by ChangeDetector
        if ($competitor->getProofGrade() === 'A') $score += 20;

        return min(100, $score);
    }

    /**
     * Batch score all active competitors.
     *
     * @return array{scored: int, avg_threat: float, avg_overlap: float}
     */
    public function scoreAll(): array
    {
        $competitors = $this->competitorRepo->findActive();
        $scored = 0;
        $totalThreat = 0;
        $totalOverlap = 0;

        foreach ($competitors as $competitor) {
            $result = $this->score($competitor);
            $totalThreat += $result['threat'];
            $totalOverlap += $result['overlap'];
            $scored++;
        }

        $this->em->flush();

        $this->logger->info('CompScoring: Batch scored {count} competitors', ['count' => $scored]);

        return [
            'scored' => $scored,
            'avg_threat' => $scored > 0 ? round($totalThreat / $scored, 1) : 0,
            'avg_overlap' => $scored > 0 ? round($totalOverlap / $scored, 1) : 0,
        ];
    }
}
