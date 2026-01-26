<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\RFQ;
use App\Repository\CompanyRepository;
use App\Repository\RfqRepository;
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
    private RfqRepository $rfqRepository;
    private LoggerInterface $logger;
    
    private array $customProbabilities = [];
    
    public function __construct(
        CompanyRepository $companyRepository,
        RfqRepository $rfqRepository,
        LoggerInterface $logger
    ) {
        $this->companyRepository = $companyRepository;
        $this->rfqRepository = $rfqRepository;
        $this->logger = $logger;
    }
    
    /**
     * Set custom stage probabilities based on historical data
     */
    public function setCustomProbabilities(array $probabilities): void
    {
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
     */
    public function calculateWeightedPipeline(): array
    {
        $companies = $this->companyRepository->findAll();
        
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
        
        foreach ($companies as $company) {
            $dealValue = $this->estimateDealValue($company);
            $stage = $company->getPipelineStage() ?? Company::STAGE_PROSPECT;
            
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
            
            // Calculate adjusted probability
            $adjustedProbability = $probability * $timeDecay * $tierMultiplier;
            $weightedValue = $dealValue * $adjustedProbability;
            
            // Update totals
            $pipeline['total_unweighted'] += $dealValue;
            $pipeline['total_weighted'] += $weightedValue;
            
            // Update by stage
            $pipeline['by_stage'][$stage]['count']++;
            $pipeline['by_stage'][$stage]['unweighted'] += $dealValue;
            $pipeline['by_stage'][$stage]['weighted'] += $weightedValue;
            
            // Update by quarter (expected close)
            $quarter = $this->determineExpectedCloseQuarter($company);
            if (!isset($pipeline['by_quarter'][$quarter])) {
                $pipeline['by_quarter'][$quarter] = ['count' => 0, 'unweighted' => 0, 'weighted' => 0];
            }
            $pipeline['by_quarter'][$quarter]['count']++;
            $pipeline['by_quarter'][$quarter]['unweighted'] += $dealValue;
            $pipeline['by_quarter'][$quarter]['weighted'] += $weightedValue;
            
            // Update by rep
            $rep = $company->getOwnerRep() ?? 'Unassigned';
            if (!isset($pipeline['by_rep'][$rep])) {
                $pipeline['by_rep'][$rep] = ['count' => 0, 'unweighted' => 0, 'weighted' => 0];
            }
            $pipeline['by_rep'][$rep]['count']++;
            $pipeline['by_rep'][$rep]['unweighted'] += $dealValue;
            $pipeline['by_rep'][$rep]['weighted'] += $weightedValue;
            
            // Update by sector
            $sector = $company->getSector() ?? 'Other';
            if (!isset($pipeline['by_sector'][$sector])) {
                $pipeline['by_sector'][$sector] = ['count' => 0, 'unweighted' => 0, 'weighted' => 0];
            }
            $pipeline['by_sector'][$sector]['count']++;
            $pipeline['by_sector'][$sector]['unweighted'] += $dealValue;
            $pipeline['by_sector'][$sector]['weighted'] += $weightedValue;
            
            // Store individual deal info
            $pipeline['deals'][] = [
                'company_id' => $company->getId(),
                'company_name' => $company->getName(),
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
     * Calculate historical conversion rates by stage
     */
    public function calculateConversionRates(\DateTime $since = null): array
    {
        if (!$since) {
            $since = (new \DateTime())->modify('-12 months');
        }
        
        // This would ideally query historical data
        // For now, return defaults with explanation
        return [
            'period_start' => $since->format('Y-m-d'),
            'rates' => self::DEFAULT_STAGE_PROBABILITIES,
            'note' => 'Using default probabilities. Enable conversion tracking for historical analysis.',
        ];
    }
    
    /**
     * Get pipeline velocity metrics
     */
    public function getPipelineVelocity(): array
    {
        $companies = $this->companyRepository->findAll();
        
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
        
        foreach ($companies as $company) {
            $stage = $company->getPipelineStage();
            $value = $this->estimateDealValue($company);
            
            if ($value > 0) {
                $totalValue += $value;
                $dealCount++;
            }
            
            if ($stage === Company::STAGE_AWARD) {
                $wonDeals++;
                $closedDeals++;
            }
            // Note: Lost deals would need a separate status field
        }
        
        if ($dealCount > 0) {
            $velocity['average_deal_value'] = $totalValue / $dealCount;
            $velocity['deals_in_pipeline'] = $dealCount - $wonDeals;
        }
        
        if ($closedDeals > 0) {
            $velocity['win_rate'] = ($wonDeals / $closedDeals) * 100;
        }
        
        // Simplified cycle calculation (would need deal creation dates)
        $velocity['average_cycle_days'] = 45; // Default assumption
        
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
     * Get at-risk deals (stalled or decaying)
     */
    public function getAtRiskDeals(int $limit = 20): array
    {
        $atRisk = [];
        $companies = $this->companyRepository->findAll();
        
        foreach ($companies as $company) {
            $stage = $company->getPipelineStage();
            
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
                $atRisk[] = [
                    'company' => $company,
                    'stage' => $stage,
                    'deal_value' => $dealValue,
                    'time_decay' => $timeDecay,
                    'risk_level' => $this->calculateRiskLevel($timeDecay),
                    'days_in_stage' => $this->getDaysInCurrentStage($company),
                    'recommendation' => $this->getRiskRecommendation($timeDecay, $stage),
                ];
            }
        }
        
        // Sort by risk level (highest first)
        usort($atRisk, fn($a, $b) => $b['deal_value'] * (1 - $b['time_decay']) <=> $a['deal_value'] * (1 - $a['time_decay']));
        
        return array_slice($atRisk, 0, $limit);
    }
    
    /**
     * Generate forecast summary for reporting
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
                'company' => $r['company']->getName(),
                'value' => $r['deal_value'],
                'risk' => $r['risk_level'],
            ], $atRisk), 0, 5),
            'stage_breakdown' => $pipeline['by_stage'],
        ];
    }
    
    // Private helpers
    
    private function estimateDealValue(Company $company): float
    {
        // Check for RFQs
        $rfqs = $this->rfqRepository->findBy(['company' => $company], ['createdAt' => 'DESC'], 1);
        
        if (!empty($rfqs)) {
            $rfq = $rfqs[0];
            $value = $rfq->getEstimatedValue() ?? $rfq->getTotalValue() ?? 0;
            if ($value > 0) {
                return (float) $value;
            }
        }
        
        // Default based on account tier
        $tier = $company->getAccountTier();
        return match ($tier) {
            Company::TIER_A => 100000,
            Company::TIER_B => 50000,
            Company::TIER_C => 25000,
            default => 35000,
        };
    }
    
    private function calculateTimeDecay(Company $company): float
    {
        $updatedAt = $company->getUpdatedAt();
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
    
    private function getTierMultiplier(Company $company): float
    {
        $tier = $company->getAccountTier();
        return self::TIER_MULTIPLIERS[$tier] ?? 1.0;
    }
    
    private function determineExpectedCloseQuarter(Company $company): string
    {
        $stage = $company->getPipelineStage();
        
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
    
    private function getDaysInCurrentStage(Company $company): int
    {
        $updatedAt = $company->getUpdatedAt();
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
