<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\RFQ;
use App\Repository\CompanyRepository;
use App\Repository\RFQRepository;
use Psr\Log\LoggerInterface;

/**
 * Weighted Pipeline Forecasting Service
 * 
 * Provides revenue forecasting based on:
 * - Pipeline stage probabilities
 * - Historical conversion rates
 * - Deal size and quantity
 * - Time-based decay factors
 * - Win/loss analysis
 */
class PipelineForecastingService
{
    // Default stage probabilities (customizable)
    private const DEFAULT_STAGE_PROBABILITIES = [
        Company::STAGE_PROSPECT => 0.05,   // 5% probability
        Company::STAGE_MQL => 0.10,        // 10% probability
        Company::STAGE_SQL => 0.25,        // 25% probability
        Company::STAGE_SQO => 0.50,        // 50% probability
        Company::STAGE_PROPOSAL => 0.75,   // 75% probability
        Company::STAGE_AWARD => 1.00,      // 100% probability (won)
    ];
    
    // Time decay factors (deals get less likely over time)
    private const TIME_DECAY_RATES = [
        30 => 1.00,    // 0-30 days: no decay
        60 => 0.90,    // 31-60 days: 10% decay
        90 => 0.75,    // 61-90 days: 25% decay
        120 => 0.50,   // 91-120 days: 50% decay
        180 => 0.25,   // 121-180 days: 75% decay
    ];
    
    // Account tier multipliers
    private const TIER_MULTIPLIERS = [
        Company::TIER_A => 1.20,  // 20% boost for Tier A accounts
        Company::TIER_B => 1.00,  // Neutral for Tier B
        Company::TIER_C => 0.80,  // 20% reduction for Tier C
    ];
    
    private CompanyRepository $companyRepository;
    private RFQRepository $rfqRepository;

    /**
     * Not read yet; kept for future failure diagnostics and for subclasses.
     */
    protected LoggerInterface $logger;

    /** @var array<string, float> */
    private array $customProbabilities = [];
    
    public function __construct(
        CompanyRepository $companyRepository,
        RFQRepository $rfqRepository,
        LoggerInterface $logger
    ) {
        $this->companyRepository = $companyRepository;
        $this->rfqRepository = $rfqRepository;
        $this->logger = $logger;
    }
    
    /**
     * Set custom stage probabilities based on historical data
     *
     * @param array<string, float> $probabilities
     */
    public function setCustomProbabilities(array $probabilities): void
    {
        foreach ($probabilities as $stage => $probability) {
            if ($probability < 0.0 || $probability > 1.0) {
                throw new \InvalidArgumentException(
                    "Probability for stage '{$stage}' must be between 0.0 and 1.0, got {$probability}"
                );
            }
        }
        $this->customProbabilities = $probabilities;
    }
    
    /**
     * Get stage probability
     */
    public function getStageProbability(string $stage): float
    {
        return $this->customProbabilities[$stage] 
            ?? self::DEFAULT_STAGE_PROBABILITIES[$stage] 
            ?? 0.0;
    }
    
    /**
     * Calculate weighted pipeline value for all deals
     *
     * @return array{
     *     total_unweighted: int|float,
     *     total_weighted: int|float,
     *     by_stage: array<string, array{count: int, unweighted: int|float, weighted: int|float, probability: float}>,
     *     by_quarter: array<string, array{count: int, unweighted: int|float, weighted: int|float}>,
     *     by_rep: array<string, array{count: int, unweighted: int|float, weighted: int|float}>,
     *     by_sector: array<string, array{count: int, unweighted: int|float, weighted: int|float}>,
     *     deals: list<array{company_id: int, company_name: string|null, stage: string, deal_value: float, base_probability: float, time_decay: float, tier_multiplier: float, adjusted_probability: float, weighted_value: int|float}>
     * }
     */
    public function calculateWeightedPipeline(): array
    {
        $companyRows = $this->getPipelineCompanyRows();
        
        $pipeline = [
            'total_unweighted' => 0,
            'total_weighted' => 0,
            'by_stage' => [],
            'by_quarter' => [],
            'by_rep' => [],
            'by_sector' => [],
            'deals' => [],
        ];
        
        // Initialize stages
        foreach (Company::VALID_STAGES as $stage) {
            $pipeline['by_stage'][$stage] = [
                'count' => 0,
                'unweighted' => 0,
                'weighted' => 0,
                'probability' => $this->getStageProbability($stage),
            ];
        }
        
        foreach ($companyRows as $company) {
            $dealValue = $this->estimateDealValue($company);
            $stage = $company['pipelineStage'] ?? Company::STAGE_PROSPECT;
            
            if ($dealValue <= 0) {
                continue;
            }
            
            // Skip won deals (Award stage) from forecast
            if ($stage === Company::STAGE_AWARD) {
                continue;
            }
            
            $probability = $this->getStageProbability($stage);
            $timeDecay = $this->calculateTimeDecay($company);
            $tierMultiplier = $this->getTierMultiplier($company);
            
            // Calculate adjusted probability (clamped to [0, 1] — cannot exceed 100%)
            $adjustedProbability = min(1.0, $probability * $timeDecay * $tierMultiplier);
            $weightedValue = $dealValue * $adjustedProbability;
            
            // Update totals
            $pipeline['total_unweighted'] += $dealValue;
            $pipeline['total_weighted'] += $weightedValue;
            
            // Update by stage
            $stageStats = $pipeline['by_stage'][$stage];
            $stageStats['count']++;
            $stageStats['unweighted'] += $dealValue;
            $stageStats['weighted'] += $weightedValue;
            $pipeline['by_stage'][$stage] = $stageStats;
            
            // Update by quarter (expected close)
            $quarter = $this->determineExpectedCloseQuarter($company);
            $quarterStats = $pipeline['by_quarter'][$quarter] ?? ['count' => 0, 'unweighted' => 0, 'weighted' => 0];
            $quarterStats['count']++;
            $quarterStats['unweighted'] += $dealValue;
            $quarterStats['weighted'] += $weightedValue;
            $pipeline['by_quarter'][$quarter] = $quarterStats;

            // Update by rep (Company has no ownerRep; default to Unassigned)
            $rep = 'Unassigned';
            $repStats = $pipeline['by_rep'][$rep] ?? ['count' => 0, 'unweighted' => 0, 'weighted' => 0];
            $repStats['count']++;
            $repStats['unweighted'] += $dealValue;
            $repStats['weighted'] += $weightedValue;
            $pipeline['by_rep'][$rep] = $repStats;

            // Update by sector
            $sector = $company['sector'] ?? 'Other';
            $sectorStats = $pipeline['by_sector'][$sector] ?? ['count' => 0, 'unweighted' => 0, 'weighted' => 0];
            $sectorStats['count']++;
            $sectorStats['unweighted'] += $dealValue;
            $sectorStats['weighted'] += $weightedValue;
            $pipeline['by_sector'][$sector] = $sectorStats;
            
            // Store individual deal info
            $pipeline['deals'][] = [
                'company_id' => (int) $company['id'],
                'company_name' => $company['name'],
                'stage' => $stage,
                'deal_value' => $dealValue,
                'base_probability' => $probability,
                'time_decay' => $timeDecay,
                'tier_multiplier' => $tierMultiplier,
                'adjusted_probability' => $adjustedProbability,
                'weighted_value' => $weightedValue,
            ];
        }
        
        // Sort deals by weighted value descending
        usort($pipeline['deals'], fn($a, $b) => $b['weighted_value'] <=> $a['weighted_value']);
        
        // Sort quarters
        ksort($pipeline['by_quarter']);
        
        return $pipeline;
    }
    
    /**
     * Forecast revenue for specific time period
     *
     * @return array{period_months: int, end_date: string, best_case: int|float, expected: int|float, worst_case: int|float, committed: int|float, confidence_range: array{low: int|float, mid: int|float, high: int|float}}
     */
    public function forecastRevenue(int $months = 3): array
    {
        $endDate = (new \DateTime())->modify("+{$months} months");
        $pipeline = $this->calculateWeightedPipeline();
        
        $forecast = [
            'period_months' => $months,
            'end_date' => $endDate->format('Y-m-d'),
            'best_case' => 0,         // All deals close
            'expected' => 0,           // Weighted probability
            'worst_case' => 0,         // Only high-probability deals
            'committed' => 0,          // Proposal stage and beyond
        ];
        
        foreach ($pipeline['deals'] as $deal) {
            $forecast['best_case'] += $deal['deal_value'];
            $forecast['expected'] += $deal['weighted_value'];
            
            // Worst case: only count deals with >70% probability
            if ($deal['adjusted_probability'] >= 0.70) {
                $forecast['worst_case'] += $deal['deal_value'] * $deal['adjusted_probability'];
            }
            
            // Committed: deals at Proposal or SQO stage
            if (in_array($deal['stage'], [Company::STAGE_PROPOSAL, Company::STAGE_SQO])) {
                $forecast['committed'] += $deal['weighted_value'];
            }
        }
        
        // Add confidence range
        $forecast['confidence_range'] = [
            'low' => $forecast['worst_case'],
            'mid' => $forecast['expected'],
            'high' => $forecast['best_case'],
        ];
        
        return $forecast;
    }
    
    /**
     * Calculate historical conversion rates by stage.
     *
     * Uses closed RFQs (won/lost) within the period, grouped by the
     * company's current pipeline stage as a proxy for the stage at close
     * (stage-at-close history is not tracked). Stages without any closed
     * RFQs fall back to the default probabilities.
     */
    /**
     * @return array{period_start: string, rates: array<string, float>, based_on: array{won: int, closed: int}, note: string}
     */
    public function calculateConversionRates(?\DateTime $since = null): array
    {
        $since ??= (new \DateTime())->modify('-90 days');

        /** @var array<int, array{pipelineStage: string|null, status: string, total: int|string}> $closed */
        $closed = $this->rfqRepository->createQueryBuilder('r')
            ->select('c.pipelineStage, r.status, COUNT(r.id) AS total')
            ->join('r.company', 'c')
            ->where('r.status IN (:statuses)')
            ->andWhere('COALESCE(r.decisionDate, r.rfqDate) >= :since')
            ->setParameter('statuses', [RFQ::STATUS_WON, RFQ::STATUS_LOST])
            ->setParameter('since', $since)
            ->groupBy('c.pipelineStage, r.status')
            ->getQuery()
            ->getResult();

        $perStage = [];
        $totalWon = 0;
        $totalClosed = 0;

        foreach ($closed as $row) {
            $stage = $row['pipelineStage'] ?? Company::STAGE_PROSPECT;
            $count = (int) $row['total'];
            $totalClosed += $count;

            if (!isset($perStage[$stage])) {
                $perStage[$stage] = ['won' => 0, 'total' => 0];
            }
            $perStage[$stage]['total'] += $count;
            if ($row['status'] === RFQ::STATUS_WON) {
                $perStage[$stage]['won'] += $count;
                $totalWon += $count;
            }
        }

        $rates = [];
        $dataStages = 0;
        foreach (Company::VALID_STAGES as $stage) {
            if (isset($perStage[$stage]) && $perStage[$stage]['total'] > 0) {
                $rates[$stage] = round($perStage[$stage]['won'] / $perStage[$stage]['total'], 4);
                $dataStages++;
            } else {
                $rates[$stage] = self::DEFAULT_STAGE_PROBABILITIES[$stage];
            }
        }

        return [
            'period_start' => $since->format('Y-m-d'),
            'rates' => $rates,
            'based_on' => [
                'won' => $totalWon,
                'closed' => $totalClosed,
            ],
            'note' => $dataStages === 0
                ? 'No closed RFQs in the period — using default probabilities.'
                : 'Computed from RFQ win/loss data grouped by company pipeline stage.',
        ];
    }
    
    /**
     * Get pipeline velocity metrics
     *
     * @return array{average_deal_value: int|float, average_cycle_days: int, win_rate: int|float, deals_in_pipeline: int, monthly_velocity: int|float}
     */
    public function getPipelineVelocity(): array
    {
        $companyRows = $this->getPipelineCompanyRows();
        
        $velocity = [
            'average_deal_value' => 0,
            'average_cycle_days' => 0,
            'win_rate' => 0,
            'deals_in_pipeline' => 0,
            'monthly_velocity' => 0,
        ];
        
        $totalValue = 0;
        $dealCount = 0;
        $wonDeals = 0;
        $closedDeals = 0;
        
        foreach ($companyRows as $company) {
            $stage = $company['pipelineStage'];
            $value = $this->estimateDealValue($company);
            
            if ($value > 0) {
                $totalValue += $value;
                $dealCount++;
            }
            
            if ($stage === Company::STAGE_AWARD) {
                $wonDeals++;
                $closedDeals++;
            } elseif ($stage !== null) {
                // Any non-Award company with stale activity (>90 days) counts as a lost deal
                // This includes SQL, SQO, and Proposal stages — not just Prospect/MQL
                $lastUpdate = $company['updatedAt'];
                if ($lastUpdate && (new \DateTime())->diff($lastUpdate)->days > 90) {
                    $closedDeals++;
                }
            }
        }
        
        if ($dealCount > 0) {
            $velocity['average_deal_value'] = $totalValue / $dealCount;
            $velocity['deals_in_pipeline'] = $dealCount - $wonDeals;
        }
        
        if ($closedDeals > 0) {
            $velocity['win_rate'] = ($wonDeals / $closedDeals) * 100;
        }
        
        // Average cycle length computed from closed RFQs (decision date minus
        // creation date), with a 45-day fallback when no data exists.
        $velocity['average_cycle_days'] = $this->calculateAverageCycleDays();
        
        // Monthly velocity = (# deals × avg value × win rate) / cycle time
        if ($velocity['average_cycle_days'] > 0) {
            $velocity['monthly_velocity'] = (
                $velocity['deals_in_pipeline'] 
                * $velocity['average_deal_value'] 
                * ($velocity['win_rate'] / 100)
            ) / ($velocity['average_cycle_days'] / 30);
        }
        
        return $velocity;
    }

    /**
     * Average sales cycle in days from closed RFQs: decisionDate - created
     * (falling back to rfqDate). Returns 45 when no usable data exists.
     */
    private function calculateAverageCycleDays(): int
    {
        /** @var array<int, array{decisionDate: \DateTimeInterface|null, createdAt: \DateTimeInterface|null, rfqDate: \DateTimeInterface|null}> $closedRfqs */
        $closedRfqs = $this->rfqRepository->createQueryBuilder('r')
            ->select('r.decisionDate, r.createdAt, r.rfqDate')
            ->where('r.status IN (:statuses)')
            ->andWhere('r.decisionDate IS NOT NULL')
            ->setParameter('statuses', [RFQ::STATUS_WON, RFQ::STATUS_LOST])
            ->getQuery()
            ->getResult();

        $cycleDays = [];
        foreach ($closedRfqs as $row) {
            $decisionDate = $row['decisionDate'];
            $startDate = $row['createdAt'] ?? $row['rfqDate'];
            if ($decisionDate && $startDate) {
                $days = $decisionDate->diff($startDate)->days;
                if ($days >= 0) {
                    $cycleDays[] = $days;
                }
            }
        }

        if (empty($cycleDays)) {
            return 45; // Default assumption when no closed RFQ data exists
        }

        return (int) round(array_sum($cycleDays) / count($cycleDays));
    }
    
    /**
     * Get at-risk deals (stalled or decaying)
     *
     * @return list<array{company: Company|null, company_name: string|null, stage: string|null, deal_value: float, time_decay: float, risk_level: string, days_in_stage: int, recommendation: string}>
     */
    public function getAtRiskDeals(int $limit = 20): array
    {
        $atRiskRows = [];
        $companyRows = $this->getPipelineCompanyRows();
        
        foreach ($companyRows as $company) {
            $stage = $company['pipelineStage'];
            
            // Skip won or prospect stages
            if (in_array($stage, [Company::STAGE_AWARD, Company::STAGE_PROSPECT])) {
                continue;
            }
            
            $timeDecay = $this->calculateTimeDecay($company);
            $dealValue = $this->estimateDealValue($company);
            
            if ($dealValue <= 0) {
                continue;
            }
            
            // Flag as at-risk if significant time decay
            if ($timeDecay < 0.80) {
                $atRiskRows[] = [
                    'row' => $company,
                    'stage' => $stage,
                    'deal_value' => $dealValue,
                    'time_decay' => $timeDecay,
                    'risk_level' => $this->calculateRiskLevel($timeDecay),
                    'days_in_stage' => $this->getDaysInCurrentStage($company),
                    'recommendation' => $this->getRiskRecommendation($timeDecay, $stage ?? ''),
                ];
            }
        }
        
        // Sort by risk level (highest first)
        usort($atRiskRows, fn($a, $b) => $b['deal_value'] * (1 - $b['time_decay']) <=> $a['deal_value'] * (1 - $a['time_decay']));
        
        $atRiskRows = array_slice($atRiskRows, 0, $limit);
        
        // Hydrate only the at-risk companies (bounded by $limit)
        $companyIds = array_map(fn(array $r): int => (int) $r['row']['id'], $atRiskRows);
        $entitiesById = [];
        if (!empty($companyIds)) {
            /** @var list<Company> $entities */
            $entities = $this->companyRepository->createQueryBuilder('c')
                ->where('c.id IN (:ids)')
                ->setParameter('ids', $companyIds)
                ->getQuery()
                ->getResult();
            foreach ($entities as $entity) {
                $entitiesById[$entity->getId() ?? 0] = $entity;
            }
        }
        
        $atRisk = [];
        foreach ($atRiskRows as $r) {
            $atRisk[] = [
                'company' => $entitiesById[(int) $r['row']['id']] ?? null,
                'company_name' => $r['row']['name'],
                'stage' => $r['stage'],
                'deal_value' => $r['deal_value'],
                'time_decay' => $r['time_decay'],
                'risk_level' => $r['risk_level'],
                'days_in_stage' => $r['days_in_stage'],
                'recommendation' => $r['recommendation'],
            ];
        }
        
        return $atRisk;
    }
    
    /**
     * Generate forecast summary for reporting
     *
     * @return array{generated_at: string, pipeline_summary: array{total_deals: int, total_unweighted: int|float, total_weighted: int|float}, forecast_summary: array{period_months: int, end_date: string, best_case: int|float, expected: int|float, worst_case: int|float, committed: int|float, confidence_range: array{low: int|float, mid: int|float, high: int|float}}, velocity_metrics: array{average_deal_value: int|float, average_cycle_days: int, win_rate: int|float, deals_in_pipeline: int, monthly_velocity: int|float}, at_risk_count: int, top_at_risk: list<array{company: string|null, value: int|float, risk: string}>, stage_breakdown: array<string, array{count: int, unweighted: int|float, weighted: int|float, probability: float}>}
     */
    public function generateForecastSummary(): array
    {
        $pipeline = $this->calculateWeightedPipeline();
        $forecast = $this->forecastRevenue(3);
        $velocity = $this->getPipelineVelocity();
        $atRisk = $this->getAtRiskDeals(10);
        
        return [
            'generated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            'pipeline_summary' => [
                'total_deals' => count($pipeline['deals']),
                'total_unweighted' => $pipeline['total_unweighted'],
                'total_weighted' => $pipeline['total_weighted'],
            ],
            'forecast_summary' => $forecast,
            'velocity_metrics' => $velocity,
            'at_risk_count' => count($atRisk),
            'top_at_risk' => array_slice(array_map(fn($r) => [
                'company' => $r['company']?->getName() ?? $r['company_name'],
                'value' => $r['deal_value'],
                'risk' => $r['risk_level'],
            ], $atRisk), 0, 5),
            'stage_breakdown' => $pipeline['by_stage'],
        ];
    }
    
    // Private helpers

    /**
     * Fetch only the pipeline-relevant company fields as scalar rows,
     * avoiding hydration of full Company entities.
     *
     * @return array<int, array{id: int, name: string|null, pipelineStage: string|null, accountTier: string|null, sector: string|null, updatedAt: \DateTimeInterface|null}>
     */
    private function getPipelineCompanyRows(): array
    {
        /** @var array<int, array{id: int, name: string|null, pipelineStage: string|null, accountTier: string|null, sector: string|null, updatedAt: \DateTimeInterface|null}> $rows */
        $rows = $this->companyRepository->createQueryBuilder('c')
            ->select('c.id, c.name, c.pipelineStage, c.accountTier, c.sector, c.updatedAt')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Estimate deal value for a company row (from its latest RFQ, falling
     * back to account tier defaults).
     *
     * @param array{id: int, accountTier: string|null, ...} $companyRow
     */
    private function estimateDealValue(array $companyRow): float
    {
        // Check for RFQs
        $rfqs = $this->rfqRepository->findBy(['company' => (int) $companyRow['id']], ['createdAt' => 'DESC'], 1);
        
        if (!empty($rfqs)) {
            $rfq = $rfqs[0];
            $value = $rfq->getEstimatedValue() ?? 0;
            if ($value > 0) {
                return (float) $value;
            }
        }
        
        // Default based on account tier
        $tier = $companyRow['accountTier'];
        return match ($tier) {
            Company::TIER_A => 100000,
            Company::TIER_B => 50000,
            Company::TIER_C => 25000,
            default => 35000,
        };
    }

    /**
     * @param array{updatedAt: \DateTimeInterface|null, ...} $companyRow
     */
    private function calculateTimeDecay(array $companyRow): float
    {
        $updatedAt = $companyRow['updatedAt'];
        if (!$updatedAt) {
            return 1.0; // No decay if no timestamp
        }
        
        $days = (new \DateTime())->diff($updatedAt)->days;
        
        foreach (self::TIME_DECAY_RATES as $threshold => $rate) {
            if ($days <= $threshold) {
                return $rate;
            }
        }
        
        return 0.10; // Severe decay after 180 days
    }

    /**
     * @param array{accountTier: string|null, ...} $companyRow
     */
    private function getTierMultiplier(array $companyRow): float
    {
        $tier = $companyRow['accountTier'];
        return self::TIER_MULTIPLIERS[$tier ?? ''] ?? 1.0;
    }

    /**
     * @param array{pipelineStage: string|null, ...} $companyRow
     */
    private function determineExpectedCloseQuarter(array $companyRow): string
    {
        $stage = $companyRow['pipelineStage'];
        
        // Estimate months to close based on stage
        $monthsToClose = match ($stage) {
            Company::STAGE_PROPOSAL => 1,
            Company::STAGE_SQO => 2,
            Company::STAGE_SQL => 3,
            Company::STAGE_MQL => 4,
            default => 6,
        };
        
        $closeDate = (new \DateTime())->modify("+{$monthsToClose} months");
        $quarter = ceil($closeDate->format('n') / 3);
        $year = $closeDate->format('Y');
        
        return "Q{$quarter} {$year}";
    }

    /**
     * @param array{updatedAt: \DateTimeInterface|null, ...} $companyRow
     */
    private function getDaysInCurrentStage(array $companyRow): int
    {
        $updatedAt = $companyRow['updatedAt'];
        if (!$updatedAt) {
            return 0;
        }
        return (new \DateTime())->diff($updatedAt)->days;
    }
    
    private function calculateRiskLevel(float $timeDecay): string
    {
        if ($timeDecay < 0.30) return 'critical';
        if ($timeDecay < 0.50) return 'high';
        if ($timeDecay < 0.75) return 'medium';
        return 'low';
    }
    
    private function getRiskRecommendation(float $timeDecay, string $stage): string
    {
        if ($timeDecay < 0.30) {
            return 'Urgent: Deal is stalled. Schedule executive engagement or consider closing as lost.';
        }
        if ($timeDecay < 0.50) {
            return 'High priority: Re-engage immediately. Schedule call to reassess timeline and blockers.';
        }
        
        return match ($stage) {
            Company::STAGE_SQL => 'Progress to opportunity: Schedule technical deep-dive or site visit.',
            Company::STAGE_SQO => 'Push for proposal: Gather final requirements and prepare quote.',
            Company::STAGE_PROPOSAL => 'Follow up on proposal: Address any concerns and push for decision.',
            default => 'Re-engage with value proposition update.',
        };
    }
}
