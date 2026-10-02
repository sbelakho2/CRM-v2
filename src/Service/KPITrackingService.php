<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CompanyRepository;
use App\Repository\QuoteRepository;
use App\Repository\RFQRepository;
use App\Repository\ActivityRepository;
use App\Repository\SupplierPortalRepository;
use App\Repository\WebinarRepository;
use App\Entity\Quote;

class KPITrackingService
{
    public function __construct(
        private CompanyRepository $companyRepository,
        private RFQRepository $rfqRepository,
        private ActivityRepository $activityRepository,
        private WebinarRepository $webinarRepository,
        private SupplierPortalRepository $supplierPortalRepository,
        private QuoteRepository $quoteRepository,
        private CurrencyConverter $currencyConverter
    ) {}

    /**
     * Get 90-day KPIs dashboard data
     *
     * @return array{pipeline_value: float, pipeline_value_currency: string, rfq_count: int, npi_awards: int, framework_agreements: int, portal_signups: int, webinar_attendees: int}
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
        return $this->rfqRepository->countSubmittedBetween($start, $end);
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
     * Get portal signups in date range: count of supplier portals with
     * registered = true and a registration date within the range.
     */
    private function getPortalSignups(\DateTime $start, \DateTime $end): int
    {
        $count = $this->supplierPortalRepository->createQueryBuilder('sp')
            ->select('COUNT(sp.id)')
            ->where('sp.registered = :registered')
            ->andWhere('sp.registrationDate >= :start')
            ->andWhere('sp.registrationDate <= :end')
            ->setParameter('registered', true)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
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
     *
     * @return array<string, array{company_count: mixed, active_rfqs: mixed, pipeline_value: float}>
     */
    public function getSectorBreakdown(): array
    {
        $sectors = ['Automotive', 'Industrial', 'Aerospace', 'Rail', 'Renewables', 'Power Electronics'];
        
        // Single query for all company counts by sector
        $companyCounts = $this->companyRepository->getCompanyCountsBySector();
        
        // Single query for all active RFQ counts by sector
        $rfqCounts = $this->rfqRepository->getActiveRfqCountsBySector();

        // Pipeline value per sector: sum of totalCost for quotes whose
        // company sector matches, limited to sent/approved/accepted quotes.
        /** @var array<int, array{sector: string|null, totalCost: string|null, currency: string|null}> $quoteRows */
        $quoteRows = $this->quoteRepository->createQueryBuilder('q')
            ->select('c.sector, q.totalCost, q.currency')
            ->join('q.company', 'c')
            ->where('q.status IN (:statuses)')
            ->andWhere('c.sector IS NOT NULL')
            ->setParameter('statuses', [Quote::STATUS_SENT, Quote::STATUS_APPROVED, Quote::STATUS_ACCEPTED])
            ->getQuery()
            ->getResult();

        $displayCurrency = $this->currencyConverter->getDisplayCurrency();
        $pipelineValues = [];
        foreach ($quoteRows as $row) {
            $sector = $row['sector'];
            if ($sector === null || $sector === '') {
                continue;
            }
            $amount = (float) ($row['totalCost'] ?? '0');
            if ($amount <= 0) {
                continue;
            }
            $sourceCurrency = $row['currency'] !== null && $row['currency'] !== '' ? $row['currency'] : $displayCurrency;
            $pipelineValues[$sector] = ($pipelineValues[$sector] ?? 0.0)
                + $this->currencyConverter->convert($amount, $sourceCurrency, $displayCurrency);
        }
        
        $breakdown = [];
        foreach ($sectors as $sector) {
            $breakdown[$sector] = [
                'company_count' => $companyCounts[$sector] ?? 0,
                'active_rfqs' => $rfqCounts[$sector] ?? 0,
                'pipeline_value' => round($pipelineValues[$sector] ?? 0.0, 2),
            ];
        }

        return $breakdown;
    }

    /**
     * Get pipeline stage distribution
     * Optimized: uses 1 aggregate query instead of 6 separate queries
     *
     * @return array<string, mixed>
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
     *
     * @return array{calls_made: mixed, emails_sent: mixed, meetings_held: mixed, portal_signups: mixed}
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
     * Get team performance metrics: activity volume per user over the last
     * 90 days, classified by role where possible.
     *
     * @return array{field_reps: list<array{user_id: int, name: string, activities_90d: int}>, digital_reps: list<array{user_id: int, name: string, activities_90d: int}>}
     */
    public function getTeamPerformance(): array
    {
        $start = new \DateTime('-90 days');

        /** @var array<int, array{id: int|string, firstName: string|null, lastName: string|null, roles: list<mixed>|null, cnt: int|string}> $rows */
        $rows = $this->activityRepository->createQueryBuilder('a')
            ->select('u.id, u.firstName, u.lastName, u.roles, COUNT(a.id) as cnt')
            ->join('a.user', 'u')
            ->where('a.activityDate >= :start')
            ->setParameter('start', $start)
            ->groupBy('u.id')
            ->orderBy('cnt', 'DESC')
            ->getQuery()
            ->getResult();

        $fieldReps = [];
        $digitalReps = [];

        foreach ($rows as $row) {
            $roles = $row['roles'] ?? [];
            $entry = [
                'user_id' => (int) $row['id'],
                'name' => trim(($row['firstName'] ?? '') . ' ' . ($row['lastName'] ?? '')),
                'activities_90d' => (int) $row['cnt'],
            ];

            $isDigital = false;
            foreach ($roles as $role) {
                if (is_string($role) && str_contains(strtoupper($role), 'DIGITAL')) {
                    $isDigital = true;
                    break;
                }
            }

            if ($isDigital) {
                $digitalReps[] = $entry;
            } else {
                $fieldReps[] = $entry;
            }
        }

        return [
            'field_reps' => $fieldReps,
            'digital_reps' => $digitalReps,
        ];
    }
}
