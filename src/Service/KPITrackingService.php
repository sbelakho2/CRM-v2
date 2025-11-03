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
        private WebinarRepository $webinarRepository
    ) {}

    /**
     * Get 90-day KPIs dashboard data
     */
    public function get90DayKPIs(): array
    {
        $start = new \DateTime('-90 days');
        $end = new \DateTime();

        return [
            'pipeline_value' => $this->calculatePipelineValue($start, $end),
            'rfq_count' => $this->getRFQCount($start, $end),
            'npi_awards' => $this->getNPIAwards($start, $end),
            'framework_agreements' => $this->getFrameworkAgreements($start, $end),
            'portal_signups' => $this->getPortalSignups($start, $end),
            'webinar_attendees' => $this->getWebinarAttendees($start, $end),
            'target_pipeline' => 3500000, // $3.5M
            'target_rfqs' => 12,
            'target_npis' => 2,
            'target_frameworks' => 1,
        ];
    }

    /**
     * Calculate total pipeline value for active opportunities
     */
    private function calculatePipelineValue(\DateTime $start, \DateTime $end): float
    {
        // In real implementation, sum estimatedValue from RFQs in pipeline stages
        // For now, return mock data structure
        return 0; // Will be calculated from RFQ repository
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
     */
    public function getSectorBreakdown(): array
    {
        $sectors = ['Automotive', 'Industrial', 'Aerospace', 'Rail', 'Renewables', 'Power Electronics'];
        $breakdown = [];

        foreach ($sectors as $sector) {
            $breakdown[$sector] = [
                'company_count' => $this->companyRepository->countBySector($sector),
                'active_rfqs' => $this->rfqRepository->countActiveBySector($sector),
                'pipeline_value' => 0, // To be calculated
            ];
        }

        return $breakdown;
    }

    /**
     * Get pipeline stage distribution
     */
    public function getPipelineStageDistribution(): array
    {
        $stages = ['Prospect', 'MQL', 'SQL', 'SQO', 'Proposal', 'Award'];
        $distribution = [];

        foreach ($stages as $stage) {
            $distribution[$stage] = $this->companyRepository->countByPipelineStage($stage);
        }

        return $distribution;
    }

    /**
     * Get weekly activity metrics
     */
    public function getWeeklyMetrics(): array
    {
        $weekStart = new \DateTime('monday this week');
        $weekEnd = new \DateTime('sunday this week');

        return [
            'calls_made' => $this->activityRepository->countByTypeBetween('Call', $weekStart, $weekEnd),
            'emails_sent' => $this->activityRepository->countByTypeBetween('Email', $weekStart, $weekEnd),
            'meetings_held' => $this->activityRepository->countByTypeBetween('Meeting', $weekStart, $weekEnd),
            'portal_signups' => $this->activityRepository->countByTypeBetween('Portal Signup', $weekStart, $weekEnd),
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
