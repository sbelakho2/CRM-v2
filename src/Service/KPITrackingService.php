<?php

namespace App\Service;

use App\Repository\CompanyRepository;
use App\Repository\RFQRepository;
use App\Repository\ActivityRepository;
use App\Repository\WebinarRepository;

class KPITrackingService
{
    public function __construct(
        private CompanyRepository $companyRepository,
        private RFQRepository $rfqRepository,
        private ActivityRepository $activityRepository,
        private WebinarRepository $webinarRepository,
        private CurrencyConverter $currencyConverter
    ) {}

    /**
     * Get 90-day KPIs dashboard data
     */
    public function get90DayKPIs(): array
    {
        $start = new \DateTime('-90 days');
        $end = new \DateTime();
        $displayCurrency = $this->currencyConverter->getDisplayCurrency();

        return [
            'pipeline_value' => $this->calculatePipelineValue($start, $end, $displayCurrency),
            'pipeline_value_currency' => $displayCurrency,
            'rfq_count' => $this->getRFQCount($start, $end),
            'npi_awards' => $this->getNPIAwards($start, $end),
            'framework_agreements' => $this->getFrameworkAgreements($start, $end),
            'portal_signups' => $this->getPortalSignups($start, $end),
            'webinar_attendees' => $this->getWebinarAttendees($start, $end),
        ];
    }

    /**
     * Calculate total pipeline value for active opportunities
     * Optimized: fetches only values/currencies, not full entities
     */
    private function calculatePipelineValue(\DateTime $start, \DateTime $end, string $displayCurrency): float
    {
        $pipelineValues = $this->rfqRepository->getActivePipelineValues();

        $total = 0.0;
        foreach ($pipelineValues as $row) {
            $amount = (float) $row['value'];
            if ($amount <= 0) {
                continue;
            }
            $sourceCurrency = $row['currency'] ?: $displayCurrency;
            $total += $this->currencyConverter->convert($amount, $sourceCurrency, $displayCurrency);
        }

        return round($total, 2);
    }

    /**
     * Get RFQ count in date range
     */
    private function getRFQCount(\DateTime $start, \DateTime $end): int
    {
        return $this->rfqRepository->countBetweenDates($start, $end);
    }

    /**
     * Get NPI awards count
     */
    private function getNPIAwards(\DateTime $start, \DateTime $end): int
    {
        return $this->rfqRepository->countNPIAwards($start, $end);
    }

    /**
     * Get Framework agreements count
     */
    private function getFrameworkAgreements(\DateTime $start, \DateTime $end): int
    {
        return $this->rfqRepository->countFrameworkAgreements($start, $end);
    }

    /**
     * Get portal signups in date range
     */
    private function getPortalSignups(\DateTime $start, \DateTime $end): int
    {
        // Will count supplier portal registrations
        return 0; // To be implemented with SupplierPortalRepository
    }

    /**
     * Get webinar attendees count
     */
    private function getWebinarAttendees(\DateTime $start, \DateTime $end): int
    {
        return $this->webinarRepository->countAttendeesBetween($start, $end);
    }

    /**
     * Get sector breakdown statistics
     * Optimized: uses 2 aggregate queries instead of N queries per sector
     */
    public function getSectorBreakdown(): array
    {
        $sectors = ['Automotive', 'Industrial', 'Aerospace', 'Rail', 'Renewables', 'Power Electronics'];
        
        // Single query for all company counts by sector
        $companyCounts = $this->companyRepository->getCompanyCountsBySector();
        
        // Single query for all active RFQ counts by sector
        $rfqCounts = $this->rfqRepository->getActiveRfqCountsBySector();
        
        $breakdown = [];
        foreach ($sectors as $sector) {
            $breakdown[$sector] = [
                'company_count' => $companyCounts[$sector] ?? 0,
                'active_rfqs' => $rfqCounts[$sector] ?? 0,
                'pipeline_value' => 0, // To be calculated
            ];
        }

        return $breakdown;
    }

    /**
     * Get pipeline stage distribution
     * Optimized: uses 1 aggregate query instead of 6 separate queries
     */
    public function getPipelineStageDistribution(): array
    {
        $stages = ['Prospect', 'MQL', 'SQL', 'SQO', 'Proposal', 'Award'];
        
        // Single query for all stage counts
        $stageCounts = $this->companyRepository->getCompanyCountsByPipelineStage();
        
        $distribution = [];
        foreach ($stages as $stage) {
            $distribution[$stage] = $stageCounts[$stage] ?? 0;
        }

        return $distribution;
    }

    /**
     * Get weekly activity metrics
     * Optimized: uses 1 aggregate query instead of 4 separate queries
     */
    public function getWeeklyMetrics(): array
    {
        $weekStart = new \DateTime('monday this week');
        $weekEnd = new \DateTime('sunday this week');

        $types = ['Call', 'Email', 'Meeting', 'Portal Signup'];
        $counts = $this->activityRepository->countByTypesBetween($types, $weekStart, $weekEnd);

        return [
            'calls_made' => $counts['Call'] ?? 0,
            'emails_sent' => $counts['Email'] ?? 0,
            'meetings_held' => $counts['Meeting'] ?? 0,
            'portal_signups' => $counts['Portal Signup'] ?? 0,
        ];
    }

    /**
     * Get team performance metrics
     */
    public function getTeamPerformance(): array
    {
        // Will be implemented with User repository to track per-rep metrics
        return [
            'field_reps' => [],
            'digital_reps' => [],
        ];
    }
}
