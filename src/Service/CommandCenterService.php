<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Lead;
use App\Entity\Quote;
use App\Entity\Activity;
use App\Entity\PriceHistory;
use App\Repository\LeadRepository;
use App\Repository\QuoteRepository;
use App\Repository\ActivityRepository;
use App\Repository\PriceHistoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Command Center Dashboard Service
 * 
 * Provides a unified view of critical business metrics for the Starz CRM:
 * 
 * 1. LIVE LEAD INFLOW (LeadBot)
 *    - New leads discovered today/this week
 *    - Leads by region and quality score
 *    - Leads pending review
 *    - High-priority leads requiring immediate action
 * 
 * 2. QUOTES AWAITING ACTION (Quote Buddy)
 *    - Quotes pending approval
 *    - Quotes with customer activity (viewed, requested)
 *    - High-value quotes at risk (expiring soon)
 *    - Auto-published quotes needing review
 * 
 * 3. CRITICAL SUPPLY ALERTS
 *    - Price changes on active quotes (significant increases)
 *    - Stock-out warnings for quoted parts
 *    - Lifecycle warnings (parts going EOL/NRND)
 *    - Lead time extensions
 * 
 * This service aggregates data from multiple sources to provide
 * actionable insights at a glance.
 */
class CommandCenterService
{
    // Alert thresholds
    private const PRICE_CHANGE_ALERT_THRESHOLD = 0.10;  // 10% price increase
    private const STOCK_WARNING_THRESHOLD = 100;        // Units
    private const LEAD_SCORE_HIGH_PRIORITY = 75;        // Score out of 100
    private const QUOTE_EXPIRY_WARNING_DAYS = 7;        // Days before expiration
    
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LeadRepository $leadRepository,
        private QuoteRepository $quoteRepository,
        private ActivityRepository $activityRepository,
        private LoggerInterface $logger,
        private CurrencyConverter $currencyConverter
    ) {}
    
    /**
     * Get complete Command Center data
     * 
     * @return array All dashboard widgets data
     */
    public function getCommandCenterData(): array
    {
        return [
            'timestamp' => (new \DateTime())->format('c'),
            'lead_inflow' => $this->getLeadInflowData(),
            'quotes_status' => $this->getQuotesStatusData(),
            'supply_alerts' => $this->getSupplyAlertsData(),
            'activity_feed' => $this->getRecentActivityFeed(),
            'key_metrics' => $this->getKeyMetrics(),
        ];
    }
    
    /**
     * Get Lead Inflow data (LeadBot section)
     */
    public function getLeadInflowData(): array
    {
        $today = new \DateTime('today');
        $weekAgo = (new \DateTime())->modify('-7 days');
        $monthAgo = (new \DateTime())->modify('-30 days');
        
        // Leads discovered today
        $todayLeads = $this->leadRepository->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.createdAt >= :today')
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleScalarResult();
        
        // Leads this week
        $weekLeads = $this->leadRepository->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.createdAt >= :weekAgo')
            ->setParameter('weekAgo', $weekAgo)
            ->getQuery()
            ->getSingleScalarResult();
        
        // Pending review count
        $pendingReview = $this->leadRepository->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.reviewStatus = :status')
            ->setParameter('status', 'pending')
            ->getQuery()
            ->getSingleScalarResult();
        
        // High-priority leads (high score, pending)
        $highPriorityLeads = $this->leadRepository->createQueryBuilder('l')
            ->where('l.reviewStatus = :status')
            ->andWhere('l.leadScore >= :minScore')
            ->setParameter('status', 'pending')
            ->setParameter('minScore', self::LEAD_SCORE_HIGH_PRIORITY)
            ->orderBy('l.leadScore', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
        
        // Leads by region
        $leadsByRegion = $this->leadRepository->createQueryBuilder('l')
            ->select('l.regionTag, COUNT(l.id) as count')
            ->where('l.createdAt >= :monthAgo')
            ->setParameter('monthAgo', $monthAgo)
            ->groupBy('l.regionTag')
            ->getQuery()
            ->getResult();
        
        // Recent high-value leads
        $recentHighValue = $this->leadRepository->createQueryBuilder('l')
            ->where('l.createdAt >= :weekAgo')
            ->andWhere('l.leadScore >= :minScore')
            ->setParameter('weekAgo', $weekAgo)
            ->setParameter('minScore', 60)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();
        
        return [
            'summary' => [
                'today' => (int) $todayLeads,
                'this_week' => (int) $weekLeads,
                'pending_review' => (int) $pendingReview,
                'high_priority_count' => count($highPriorityLeads),
            ],
            'by_region' => array_column($leadsByRegion, 'count', 'regionTag'),
            'high_priority_leads' => array_map(fn(Lead $l) => [
                'id' => $l->getId(),
                'company_name' => $l->getCompanyName(),
                'score' => $l->getLeadScore(),
                'region' => $l->getRegionTag(),
                'sectors' => $l->getSectorTags() ?? [],
                'created_at' => $l->getCreatedAt()?->format('Y-m-d H:i'),
                'has_contact_info' => $l->hasContactInfo(),
                'defense_flag' => $l->getDefenseFlag(),
            ], $highPriorityLeads),
            'recent_high_value' => array_map(fn(Lead $l) => [
                'id' => $l->getId(),
                'company_name' => $l->getCompanyName(),
                'score' => $l->getLeadScore(),
                'region' => $l->getRegionTag(),
            ], $recentHighValue),
        ];
    }
    
    /**
     * Get Quotes Status data (Quote Buddy section)
     */
    public function getQuotesStatusData(): array
    {
        $now = new \DateTime();
        $weekFromNow = (new \DateTime())->modify('+7 days');
        $displayCurrency = $this->currencyConverter->getDisplayCurrency();
        
        // Quotes by status
        $quotesByStatus = $this->quoteRepository->createQueryBuilder('q')
            ->select('q.status, COUNT(q.id) as count')
            ->groupBy('q.status')
            ->getQuery()
            ->getResult();
        
        // Quotes pending approval
        $pendingApproval = $this->quoteRepository->createQueryBuilder('q')
            ->where('q.status IN (:statuses)')
            ->setParameter('statuses', ['draft', 'pending_review'])
            ->orderBy('q.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
        
        // Interactive quotes with recent activity
        $activeInteractive = $this->quoteRepository->createQueryBuilder('q')
            ->where('q.interactiveEnabled = true')
            ->andWhere('q.lastViewedAt IS NOT NULL')
            ->andWhere('q.lastViewedAt >= :threshold')
            ->setParameter('threshold', (new \DateTime())->modify('-48 hours'))
            ->orderBy('q.lastViewedAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
        
        // Total quote value in pipeline (converted to display currency).
        // Scalar DQL (id, totalCost, currency, status) avoids hydrating all
        // pipeline quotes; only high-value entities are loaded afterwards.
        $pipelineStatuses = ['draft', 'pending_review', 'approved', 'sent'];
        $highValueThreshold = 50000;
        $highValueStatuses = ['sent', 'pending_review', 'approved'];
        $pipelineRows = $this->quoteRepository->createQueryBuilder('q')
            ->select('q.id, q.totalCost, q.currency, q.status')
            ->where('q.status IN (:statuses)')
            ->setParameter('statuses', $pipelineStatuses)
            ->getQuery()
            ->getResult();

        $pipelineValue = 0.0;
        $highValueCandidates = [];
        foreach ($pipelineRows as $row) {
            $amount = (float) $row['totalCost'];
            if ($amount <= 0) {
                continue;
            }
            $sourceCurrency = $row['currency'] ?: $displayCurrency;
            $converted = $this->currencyConverter->convert($amount, $sourceCurrency, $displayCurrency);
            $pipelineValue += $converted;

            if (in_array($row['status'], $highValueStatuses, true) && $converted >= $highValueThreshold) {
                $highValueCandidates[$row['id']] = $converted;
            }
        }

        // Sort by converted value descending and keep only the top 5
        arsort($highValueCandidates);
        $highValueIds = array_slice(array_keys($highValueCandidates), 0, 5);

        $highValueQuotes = [];
        if (!empty($highValueIds)) {
            $found = $this->quoteRepository->createQueryBuilder('q')
                ->where('q.id IN (:ids)')
                ->setParameter('ids', $highValueIds)
                ->getQuery()
                ->getResult();

            $byId = [];
            foreach ($found as $quote) {
                $byId[$quote->getId()] = $quote;
            }
            foreach ($highValueIds as $id) {
                if (isset($byId[$id])) {
                    $highValueQuotes[] = $byId[$id];
                }
            }
        }

        // Safe company name resolver — company row may have been deleted
        $safeCompanyName = function (Quote $q): ?string {
            try {
                return $q->getCompany()?->getName();
            } catch (\Doctrine\ORM\EntityNotFoundException) {
                return null;
            }
        };
        
        return [
            'summary' => [
                'pending_approval' => count($pendingApproval),
                'active_interactive' => count($activeInteractive),
                'high_value_count' => count($highValueQuotes),
                'pipeline_value' => round((float) $pipelineValue, 2),
                'pipeline_currency' => $displayCurrency,
            ],
            'by_status' => array_column($quotesByStatus, 'count', 'status'),
            'pending_approval' => array_map(fn(Quote $q) => [
                'id' => $q->getId(),
                'quote_number' => $q->getQuoteNumber(),
                'company' => $safeCompanyName($q),
                'total_cost' => $q->getTotalCost(),
                'currency' => $q->getCurrency() ?: $displayCurrency,
                'coverage' => $q->getCoveragePercent(),
                'status' => $q->getStatus(),
                'created_at' => $q->getCreatedAt()?->format('Y-m-d'),
            ], $pendingApproval),
            'active_interactive' => array_map(fn(Quote $q) => [
                'id' => $q->getId(),
                'quote_number' => $q->getQuoteNumber(),
                'company' => $safeCompanyName($q),
                'view_count' => $q->getViewCount(),
                'last_viewed' => $q->getLastViewedAt()?->format('Y-m-d H:i'),
                'total_cost' => $q->getTotalCost(),
                'currency' => $q->getCurrency() ?: $displayCurrency,
            ], $activeInteractive),
            'high_value_quotes' => array_map(fn(Quote $q) => [
                'id' => $q->getId(),
                'quote_number' => $q->getQuoteNumber(),
                'company' => $safeCompanyName($q),
                'total_cost' => $q->getTotalCost(),
                'currency' => $q->getCurrency() ?: $displayCurrency,
                'status' => $q->getStatus(),
            ], $highValueQuotes),
        ];
    }
    
    /**
     * Get Supply Alerts data
     */
    public function getSupplyAlertsData(): array
    {
        $alerts = [];
        
        // Get price history changes (significant increases)
        $priceAlerts = $this->getPriceChangeAlerts();
        $alerts = array_merge($alerts, $priceAlerts);
        
        // Get stock warnings from recent quotes
        $stockAlerts = $this->getStockWarningAlerts();
        $alerts = array_merge($alerts, $stockAlerts);
        
        // Get lifecycle warnings
        $lifecycleAlerts = $this->getLifecycleAlerts();
        $alerts = array_merge($alerts, $lifecycleAlerts);
        
        // Sort by severity and time
        usort($alerts, function($a, $b) {
            $severityOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];
            $aSeverity = $severityOrder[$a['severity']] ?? 2;
            $bSeverity = $severityOrder[$b['severity']] ?? 2;
            
            if ($aSeverity !== $bSeverity) {
                return $aSeverity <=> $bSeverity;
            }
            
            return ($b['timestamp'] ?? '') <=> ($a['timestamp'] ?? '');
        });
        
        // Summary counts
        $criticalCount = count(array_filter($alerts, fn($a) => $a['severity'] === 'critical'));
        $warningCount = count(array_filter($alerts, fn($a) => $a['severity'] === 'warning'));
        
        return [
            'summary' => [
                'total_alerts' => count($alerts),
                'critical' => $criticalCount,
                'warning' => $warningCount,
            ],
            'alerts' => array_slice($alerts, 0, 20), // Limit to top 20
        ];
    }
    
    /**
     * Get price change alerts — batch-loads previous prices to avoid N+1 queries
     */
    private function getPriceChangeAlerts(): array
    {
        $alerts = [];
        
        try {
            $priceHistoryRepo = $this->entityManager->getRepository(PriceHistory::class);
            
            $recentChanges = $priceHistoryRepo->createQueryBuilder('ph')
                ->where('ph.recordedAt >= :threshold')
                ->andWhere('ph.unitPrice IS NOT NULL')
                ->setParameter('threshold', (new \DateTime())->modify('-7 days'))
                ->orderBy('ph.recordedAt', 'DESC')
                ->setMaxResults(50)
                ->getQuery()
                ->getResult();
            
            if (empty($recentChanges)) {
                return $alerts;
            }
            
            $mpns = array_unique(array_map(fn($change) => $change->getMpn(), $recentChanges));
            $sources = array_unique(array_map(fn($change) => $change->getSource(), $recentChanges));
            
            $previousRecords = $priceHistoryRepo->createQueryBuilder('ph2')
                ->where('ph2.mpn IN (:mpns)')
                ->andWhere('ph2.source IN (:sources)')
                ->andWhere('ph2.unitPrice IS NOT NULL')
                ->setParameter('mpns', $mpns)
                ->setParameter('sources', $sources)
                ->orderBy('ph2.recordedAt', 'DESC')
                ->getQuery()
                ->getResult();
            
            $indexedByMpnSource = [];
            foreach ($previousRecords as $rec) {
                $key = $rec->getMpn() . '|' . $rec->getSource();
                if (!isset($indexedByMpnSource[$key])) {
                    $indexedByMpnSource[$key] = $rec;
                }
            }
            
            foreach ($recentChanges as $change) {
                $mpn = $change->getMpn();
                $source = $change->getSource();
                $currentDate = $change->getRecordedAt();
                
                $previousRecord = null;
                foreach ($previousRecords as $rec) {
                    if ($rec->getMpn() === $mpn 
                        && $rec->getSource() === $source
                        && $rec->getRecordedAt() < $currentDate) {
                        $previousRecord = $rec;
                        break;
                    }
                }
                
                if (!$previousRecord) {
                    continue;
                }

                $previousPrice = (float) $previousRecord->getUnitPrice();
                $currentPrice = (float) $change->getUnitPrice();
                $currency = $change->getCurrency() ?? 'USD';
                
                if ($previousPrice <= 0) continue;
                
                $percentChange = (($currentPrice - $previousPrice) / $previousPrice);
                
                if ($percentChange >= self::PRICE_CHANGE_ALERT_THRESHOLD) {
                    $previousFormatted = $this->currencyConverter->format($previousPrice, $currency, $currency, 4);
                    $currentFormatted = $this->currencyConverter->format($currentPrice, $currency, $currency, 4);
                    $alerts[] = [
                        'type' => 'price_increase',
                        'severity' => $percentChange >= 0.25 ? 'critical' : 'warning',
                        'mpn' => $mpn,
                        'distributor' => $source,
                        'previous_price' => round($previousPrice, 4),
                        'current_price' => round($currentPrice, 4),
                        'currency' => $currency,
                        'percent_change' => round($percentChange * 100, 1),
                        'message' => sprintf(
                            'Price increased %.1f%% for %s (from %s to %s)',
                            $percentChange * 100,
                            $mpn,
                            $previousFormatted,
                            $currentFormatted
                        ),
                        'timestamp' => $change->getRecordedAt()?->format('c'),
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not fetch price change alerts', [
                'error' => $e->getMessage(),
            ]);
        }
        
        return $alerts;
    }
    
    /**
     * Get stock warning alerts from quotes
     */
    private function getStockWarningAlerts(): array
    {
        $alerts = [];
        
        // Get active quotes with BOM data — bounded to the most recent
        // active quotes so the scan stays cheap.
        $activeQuotes = $this->quoteRepository->createQueryBuilder('q')
            ->where('q.status IN (:statuses)')
            ->setParameter('statuses', ['sent', 'approved', 'pending_review'])
            ->orderBy('q.createdAt', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();
        
        foreach ($activeQuotes as $quote) {
            $bomJson = $quote->getBomDataJson();
            if (!$bomJson) continue;
            
            $bomData = json_decode($bomJson, true);
            if (!is_array($bomData)) { continue; }
            $lines = $bomData['lines'] ?? $bomData ?? [];
            
            foreach ($lines as $line) {
                $stock = $line['stock'] ?? null;
                $mpn = $line['mpn'] ?? 'Unknown';
                
                if ($stock !== null && $stock <= self::STOCK_WARNING_THRESHOLD) {
                    $alerts[] = [
                        'type' => 'low_stock',
                        'severity' => $stock <= 0 ? 'critical' : 'warning',
                        'mpn' => $mpn,
                        'quote_number' => $quote->getQuoteNumber(),
                        'stock_available' => $stock,
                        'message' => sprintf(
                            'Low stock (%d units) for %s on quote %s',
                            $stock,
                            $mpn,
                            $quote->getQuoteNumber()
                        ),
                        'timestamp' => (new \DateTime())->format('c'),
                    ];
                }
            }
        }
        
        // Stop early once we have more than enough candidates
        if (count($alerts) >= 100) {
            $alerts = array_slice($alerts, 0, 100);
        }
        
        // Deduplicate by MPN
        $seen = [];
        $uniqueAlerts = [];
        foreach ($alerts as $alert) {
            $key = $alert['mpn'] . '_' . $alert['type'];
            if (!in_array($key, $seen)) {
                $seen[] = $key;
                $uniqueAlerts[] = $alert;
            }
        }
        
        return array_slice($uniqueAlerts, 0, 10);
    }
    
    /**
     * Get lifecycle alerts (EOL, NRND parts)
     */
    private function getLifecycleAlerts(): array
    {
        $alerts = [];
        
        // Get active quotes and check BOM lifecycle statuses — bounded to
        // the most recent active quotes so the scan stays cheap.
        $activeQuotes = $this->quoteRepository->createQueryBuilder('q')
            ->where('q.status IN (:statuses)')
            ->setParameter('statuses', ['sent', 'approved', 'pending_review'])
            ->orderBy('q.createdAt', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();
        
        $criticalTerms = ['obsolete', 'eol', 'end of life', 'discontinued'];
        $warningTerms = ['nrnd', 'not recommended', 'last time buy', 'ltb'];
        
        foreach ($activeQuotes as $quote) {
            $bomJson = $quote->getBomDataJson();
            if (!$bomJson) continue;
            
            $bomData = json_decode($bomJson, true);
            if (!is_array($bomData)) { continue; }
            $lines = $bomData['lines'] ?? $bomData ?? [];
            
            foreach ($lines as $line) {
                $lifecycle = strtolower($line['lifecycle'] ?? '');
                $mpn = $line['mpn'] ?? 'Unknown';
                
                $severity = null;
                $type = null;
                
                foreach ($criticalTerms as $term) {
                    if (str_contains($lifecycle, $term)) {
                        $severity = 'critical';
                        $type = 'lifecycle_critical';
                        break;
                    }
                }
                
                if (!$severity) {
                    foreach ($warningTerms as $term) {
                        if (str_contains($lifecycle, $term)) {
                            $severity = 'warning';
                            $type = 'lifecycle_warning';
                            break;
                        }
                    }
                }
                
                if ($severity) {
                    $alerts[] = [
                        'type' => $type,
                        'severity' => $severity,
                        'mpn' => $mpn,
                        'lifecycle_status' => $lifecycle,
                        'quote_number' => $quote->getQuoteNumber(),
                        'message' => sprintf(
                            '%s: %s is %s (quote %s)',
                            strtoupper($severity),
                            $mpn,
                            $lifecycle,
                            $quote->getQuoteNumber()
                        ),
                        'timestamp' => (new \DateTime())->format('c'),
                    ];
                }
            }
        }
        
        // Deduplicate by MPN
        $seen = [];
        $uniqueAlerts = [];
        foreach ($alerts as $alert) {
            $key = $alert['mpn'] . '_lifecycle';
            if (!in_array($key, $seen)) {
                $seen[] = $key;
                $uniqueAlerts[] = $alert;
            }
        }
        
        return array_slice($uniqueAlerts, 0, 10);
    }
    
    /**
     * Get recent activity feed
     */
    public function getRecentActivityFeed(): array
    {
        $activities = $this->activityRepository->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(15)
            ->getQuery()
            ->getResult();
        
        $safeActivityCompanyName = function (Activity $a): ?string {
            try {
                return $a->getCompany()?->getName();
            } catch (\Doctrine\ORM\EntityNotFoundException) {
                return null;
            }
        };

        return array_map(fn(Activity $a) => [
            'id' => $a->getId(),
            'type' => $a->getType(),
            'subject' => $a->getDescription(),
            'notes' => mb_substr($a->getNotes() ?? '', 0, 100),
            'company_id' => $a->getCompany()?->getId(),
            'company_name' => $safeActivityCompanyName($a),
            'created_at' => $a->getCreatedAt()?->format('Y-m-d H:i'),
        ], $activities);
    }
    
    /**
     * Get key metrics for the command center
     */
    public function getKeyMetrics(): array
    {
        $today = new \DateTime('today');
        $weekAgo = (new \DateTime())->modify('-7 days');
        $monthAgo = (new \DateTime())->modify('-30 days');
        $displayCurrency = $this->currencyConverter->getDisplayCurrency();
        
        // Leads conversion rate (approved / total pending reviewed)
        $approvedLeads = $this->leadRepository->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.reviewStatus = :status')
            ->andWhere('l.updatedAt >= :monthAgo')
            ->setParameter('status', 'approved')
            ->setParameter('monthAgo', $monthAgo)
            ->getQuery()
            ->getSingleScalarResult();
        
        $totalReviewedLeads = $this->leadRepository->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.reviewStatus IN (:statuses)')
            ->andWhere('l.updatedAt >= :monthAgo')
            ->setParameter('statuses', ['approved', 'denied'])
            ->setParameter('monthAgo', $monthAgo)
            ->getQuery()
            ->getSingleScalarResult();
        
        $leadConversionRate = $totalReviewedLeads > 0 
            ? round(((int)$approvedLeads / (int)$totalReviewedLeads) * 100, 1) 
            : 0;
        
        // Quote win rate
        $acceptedQuotes = $this->quoteRepository->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->where('q.status = :status')
            ->andWhere('q.updatedAt >= :monthAgo')
            ->setParameter('status', 'accepted')
            ->setParameter('monthAgo', $monthAgo)
            ->getQuery()
            ->getSingleScalarResult();
        
        $totalQuotes = $this->quoteRepository->createQueryBuilder('q')
            ->select('COUNT(q.id)')
            ->where('q.status IN (:statuses)')
            ->andWhere('q.createdAt >= :monthAgo')
            ->setParameter('statuses', ['accepted', 'rejected', 'sent'])
            ->setParameter('monthAgo', $monthAgo)
            ->getQuery()
            ->getSingleScalarResult();
        
        $quoteWinRate = $totalQuotes > 0 
            ? round(((int)$acceptedQuotes / (int)$totalQuotes) * 100, 1) 
            : 0;
        
        // Average quote value via aggregate DQL query (avoids loading all entities)
        $avgQuoteValue = 0.0;
        try {
            $aggResult = $this->quoteRepository->createQueryBuilder('q')
                ->select('AVG(q.totalCost) as avgValue, COUNT(q.id) as totalCount')
                ->where('q.createdAt >= :monthAgo')
                ->andWhere('q.totalCost > 0')
                ->setParameter('monthAgo', $monthAgo)
                ->getQuery()
                ->getOneOrNullResult();

            if ($aggResult && $aggResult['totalCount'] > 0) {
                $avgQuoteValue = (float) $aggResult['avgValue'];
            }
        } catch (\Exception $e) {
            $this->logger?->warning('Failed to calculate average quote value via DQL, falling back to null', [
                'exception' => $e,
            ]);
            $avgQuoteValue = 0.0;
        }

        // Pipeline health (converted to display currency) — scalar DQL rows
        // instead of hydrating every pipeline quote.
        $pipelineRows = $this->quoteRepository->createQueryBuilder('q')
            ->select('q.totalCost, q.currency')
            ->where('q.status IN (:statuses)')
            ->setParameter('statuses', ['draft', 'pending_review', 'approved', 'sent'])
            ->getQuery()
            ->getResult();

        $pipelineTotal = 0.0;
        foreach ($pipelineRows as $row) {
            $amount = (float) $row['totalCost'];
            if ($amount <= 0) {
                continue;
            }
            $sourceCurrency = $row['currency'] ?: $displayCurrency;
            $pipelineTotal += $this->currencyConverter->convert($amount, $sourceCurrency, $displayCurrency);
        }
        
        return [
            'lead_conversion_rate' => $leadConversionRate,
            'quote_win_rate' => $quoteWinRate,
            'avg_quote_value' => round((float) $avgQuoteValue, 2),
            'avg_quote_value_currency' => $displayCurrency,
            'pipeline_total' => round((float) $pipelineTotal, 2),
            'pipeline_total_currency' => $displayCurrency,
            'leads_this_week' => (int) $this->leadRepository->createQueryBuilder('l')
                ->select('COUNT(l.id)')
                ->where('l.createdAt >= :weekAgo')
                ->setParameter('weekAgo', $weekAgo)
                ->getQuery()
                ->getSingleScalarResult(),
            'quotes_this_week' => (int) $this->quoteRepository->createQueryBuilder('q')
                ->select('COUNT(q.id)')
                ->where('q.createdAt >= :weekAgo')
                ->setParameter('weekAgo', $weekAgo)
                ->getQuery()
                ->getSingleScalarResult(),
        ];
    }
    
    /**
     * Get alerts count for notification badge
     */
    public function getAlertCount(): int
    {
        $supplyAlerts = $this->getSupplyAlertsData();
        return $supplyAlerts['summary']['critical'] + $supplyAlerts['summary']['warning'];
    }
    
    /**
     * Get action items requiring immediate attention
     */
    public function getActionItems(): array
    {
        $items = [];
        
        // High-priority leads
        $highPriorityLeads = $this->leadRepository->createQueryBuilder('l')
            ->where('l.reviewStatus = :status')
            ->andWhere('l.leadScore >= :minScore')
            ->setParameter('status', 'pending')
            ->setParameter('minScore', self::LEAD_SCORE_HIGH_PRIORITY)
            ->getQuery()
            ->getResult();
        
        foreach ($highPriorityLeads as $lead) {
            $items[] = [
                'type' => 'lead_review',
                'priority' => 'high',
                'title' => 'Review high-scoring lead: ' . $lead->getCompanyName(),
                'description' => sprintf('Lead score: %d, Region: %s', $lead->getLeadScore(), $lead->getRegionTag()),
                'link' => '/lead/' . $lead->getId(),
                'entity_type' => 'lead',
                'entity_id' => $lead->getId(),
            ];
        }
        
        // Quotes with customer activity
        $activeQuotes = $this->quoteRepository->createQueryBuilder('q')
            ->where('q.interactiveEnabled = true')
            ->andWhere('q.lastViewedAt >= :threshold')
            ->andWhere('q.status NOT IN (:excludeStatuses)')
            ->setParameter('threshold', (new \DateTime())->modify('-24 hours'))
            ->setParameter('excludeStatuses', ['accepted', 'rejected'])
            ->getQuery()
            ->getResult();
        
        foreach ($activeQuotes as $quote) {
            $items[] = [
                'type' => 'quote_followup',
                'priority' => 'medium',
                'title' => 'Follow up on viewed quote: ' . $quote->getQuoteNumber(),
                'description' => sprintf('Viewed %d times, last: %s', 
                    $quote->getViewCount(),
                    $quote->getLastViewedAt()?->format('Y-m-d H:i')
                ),
                'link' => '/quote/' . $quote->getId(),
                'entity_type' => 'quote',
                'entity_id' => $quote->getId(),
            ];
        }
        
        // Sort by priority
        usort($items, function($a, $b) {
            $priorityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
            return ($priorityOrder[$a['priority']] ?? 2) <=> ($priorityOrder[$b['priority']] ?? 2);
        });
        
        return array_slice($items, 0, 10);
    }
}
