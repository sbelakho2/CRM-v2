<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for email campaign analytics and reporting
 * 
 * Features:
 * - Engagement metrics (open rate, click rate, reply rate)
 * - A/B test analysis with statistical significance
 * - Campaign performance comparison
 * - Funnel analysis
 * - Best time to send analysis
 */
class EmailAnalyticsService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Get comprehensive campaign metrics
     */
    public function getCampaignMetrics(EmailCampaign $campaign): array
    {
        $stats = $this->entityManager->createQuery(
            'SELECT 
                COUNT(es.id) as total,
                SUM(CASE WHEN es.status IN (:delivered) THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN es.status = :failed THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN es.status = :sent THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN es.opened = true THEN 1 ELSE 0 END) as opened,
                SUM(CASE WHEN es.clicked = true THEN 1 ELSE 0 END) as clicked,
                SUM(CASE WHEN es.replied = true THEN 1 ELSE 0 END) as replied,
                SUM(CASE WHEN es.bounced = true THEN 1 ELSE 0 END) as bounced
             FROM App\Entity\EmailSend es 
             WHERE es.campaign = :campaign'
        )
        ->setParameter('campaign', $campaign)
        ->setParameter('sent', 'sent')
        ->setParameter('delivered', ['sent', 'bounced'])
        ->setParameter('failed', 'failed')
        ->getSingleResult();

        $total = (int) $stats['total'];
        $delivered = (int) ($stats['delivered'] ?? 0);
        $sent = (int) $stats['sent'];
        $opened = (int) $stats['opened'];
        $clicked = (int) $stats['clicked'];
        $replied = (int) $stats['replied'];
        $bounced = (int) $stats['bounced'];

        return [
            'total' => $total,
            'sent' => $sent,
            'opened' => $opened,
            'clicked' => $clicked,
            'replied' => $replied,
            'bounced' => $bounced,
            // Delivered population (sent+bounced) is the honest
            // denominator — queued/cancelled rows are not attempts that
            // could have bounced.
            'deliveryRate' => $total > 0 ? round(($delivered / $total) * 100, 2) : 0,
            'openRate' => $sent > 0 ? round(($opened / $sent) * 100, 2) : 0,
            'clickRate' => $sent > 0 ? round(($clicked / $sent) * 100, 2) : 0,
            'clickToOpenRate' => $opened > 0 ? round(($clicked / $opened) * 100, 2) : 0,
            'replyRate' => $sent > 0 ? round(($replied / $sent) * 100, 2) : 0,
            'bounceRate' => $total > 0 ? round(($bounced / $total) * 100, 2) : 0,
        ];
    }

    /**
     * Get campaign engagement over time
     */
    public function getEngagementTimeline(EmailCampaign $campaign, string $interval = 'day'): array
    {
        $conn = $this->entityManager->getConnection();
        $campaignId = $campaign->getId();

        // Get opens over time — using native SQL for DATE()
        /** @var array<int, array<string, mixed>> $opens */
        $opens = $conn->fetchAllAssociative(
            "SELECT DATE(es.opened_at) AS date, COUNT(es.id) AS count
             FROM email_sends es
             WHERE es.campaign_id = :campaign
             AND es.opened_at IS NOT NULL
             GROUP BY DATE(es.opened_at)
             ORDER BY date ASC",
            ['campaign' => $campaignId]
        );

        // Get clicks over time — using native SQL for DATE()
        /** @var array<int, array<string, mixed>> $clicks */
        $clicks = $conn->fetchAllAssociative(
            "SELECT DATE(es.clicked_at) AS date, COUNT(es.id) AS count
             FROM email_sends es
             WHERE es.campaign_id = :campaign
             AND es.clicked_at IS NOT NULL
             GROUP BY DATE(es.clicked_at)
             ORDER BY date ASC",
            ['campaign' => $campaignId]
        );

        return [
            'opens' => $opens,
            'clicks' => $clicks,
        ];
    }

    /**
     * Analyze A/B test results
     *
     * Reads the canonical A/B test structure produced by EmailAbTestService:
     * campaign.abTestVariants is a list of test configs, each with a
     * 'variants' map keyed by variant ID ('A', 'B', ...). EmailSend.variant
     * stores the variant ID. A legacy flat variant list (entries with 'name'
     * but no 'variants' key) is also accepted for backward compatibility.
     */
    public function analyzeAbTest(EmailCampaign $campaign): array
    {
        $abTestVariants = $campaign->getAbTestVariants();

        if (empty($abTestVariants)) {
            return [
                'error' => 'Campaign does not have A/B test variants configured',
            ];
        }

        // Canonical structure: list of test configs → use the most recent test.
        // Legacy structure: flat variant list → use it as-is.
        $lastEntry = end($abTestVariants);
        if (is_array($lastEntry) && isset($lastEntry['variants'])) {
            $testConfig = $lastEntry;
        } else {
            $testConfig = ['variants' => $abTestVariants];
        }

        $results = [];
        foreach ($testConfig['variants'] as $variantKey => $variant) {
            $variantId = is_array($variant) ? (string) ($variant['id'] ?? $variantKey) : (string) $variantKey;
            $variantName = is_array($variant) ? (string) ($variant['name'] ?? $variantId) : $variantId;

            // Query EmailSend records filtered by variant ID (canonical matching key)
            $stats = $this->entityManager->createQuery(
                'SELECT 
                    COUNT(es.id) as sent,
                    SUM(CASE WHEN es.status IN (:deliveredStates) THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN es.opened = true THEN 1 ELSE 0 END) as opened,
                    SUM(CASE WHEN es.clicked = true THEN 1 ELSE 0 END) as clicked
                 FROM App\Entity\EmailSend es 
                 WHERE es.campaign = :campaign AND es.variant = :variant'
            )
            ->setParameter('campaign', $campaign)
            ->setParameter('variant', $variantId)
            ->setParameter('deliveredStates', ['sent', 'bounced'])
            ->getSingleResult();

            $sent = (int) $stats['sent'];
            $delivered = (int) ($stats['delivered'] ?? 0);
            $opened = (int) $stats['opened'];
            $clicked = (int) $stats['clicked'];

            // Engagement rates over the DELIVERED variant population —
            // consistent with campaign metrics everywhere else.
            $openRate = $delivered > 0 ? round(($opened / $delivered) * 100, 2) : 0;
            $clickRate = $delivered > 0 ? round(($clicked / $delivered) * 100, 2) : 0;

            $results[$variantId] = [
                'id' => $variantId,
                'name' => $variantName,
                'sent' => $sent,
                'opened' => $opened,
                'clicked' => $clicked,
                'openRate' => $openRate,
                'clickRate' => $clickRate,
            ];
        }

        // Calculate winner based on click rate
        $winner = null;
        $highestClickRate = 0;

        foreach ($results as $name => $metrics) {
            if ($metrics['sent'] > 0 && $metrics['clickRate'] > $highestClickRate) {
                $highestClickRate = $metrics['clickRate'];
                $winner = $name;
            }
        }

        // Calculate statistical significance (Chi-square test)
        $significance = $this->calculateStatisticalSignificance($results);

        return [
            'variants' => $results,
            'winner' => $winner,
            'confidence' => $significance['confidence'] ?? 0,
            'isSignificant' => $significance['isSignificant'] ?? false,
        ];
    }

    /**
     * Calculate statistical significance using Chi-square test
      * @param array<string|int, mixed> $variants
     */
    private function calculateStatisticalSignificance(array $variants): array
    {
        // Simplified chi-square calculation
        // For production, use a proper statistical library
        
        if (count($variants) < 2) {
            return ['isSignificant' => false, 'confidence' => 0];
        }

        $totalSent = 0;
        $totalClicked = 0;

        foreach ($variants as $metrics) {
            $totalSent += $metrics['sent'];
            $totalClicked += $metrics['clicked'];
        }

        if ($totalSent === 0) {
            return ['isSignificant' => false, 'confidence' => 0];
        }

        $expectedClickRate = $totalClicked / $totalSent;
        $chiSquare = 0;

        foreach ($variants as $metrics) {
            if ($metrics['sent'] === 0) continue;
            
            // Must include BOTH clicked and not-clicked cells for correct chi-square
            $expectedClicked = $metrics['sent'] * $expectedClickRate;
            $expectedNotClicked = $metrics['sent'] * (1 - $expectedClickRate);
            $observedClicked = $metrics['clicked'];
            $observedNotClicked = $metrics['sent'] - $metrics['clicked'];
            
            if ($expectedClicked > 0) {
                $chiSquare += pow($observedClicked - $expectedClicked, 2) / $expectedClicked;
            }
            if ($expectedNotClicked > 0) {
                $chiSquare += pow($observedNotClicked - $expectedNotClicked, 2) / $expectedNotClicked;
            }
        }

        // Degrees of freedom = (numVariants - 1) for a goodness-of-fit test
        $df = max(1, count($variants) - 1);
        // Chi-square critical values at 95% confidence by df
        $criticalValues = [1 => 3.841, 2 => 5.991, 3 => 7.815, 4 => 9.488, 5 => 11.070];
        $criticalValue = $criticalValues[$df] ?? 3.841;
        $isSignificant = $chiSquare > $criticalValue;

        // Map chi-square to confidence using lookup table for correct df
        $confidence = $this->chiSquareToConfidence($chiSquare, $df);

        return [
            'isSignificant' => $isSignificant,
            'confidence' => round($confidence, 1),
            'chiSquare' => round($chiSquare, 4),
        ];
    }

    /**
     * Convert chi-square statistic to confidence percentage via lookup table.
     * Returns the highest confidence level whose critical value is exceeded.
     */
    private function chiSquareToConfidence(float $chiSquare, int $df): float
    {
        // Chi-square critical values: [df => [[criticalValue, confidence%], ...]]
        $table = [
            1 => [[0.455, 50], [1.323, 75], [2.706, 90], [3.841, 95], [5.024, 97.5], [6.635, 99], [10.828, 99.9]],
            2 => [[1.386, 50], [2.773, 75], [4.605, 90], [5.991, 95], [7.378, 97.5], [9.210, 99], [13.816, 99.9]],
            3 => [[2.366, 50], [4.108, 75], [6.251, 90], [7.815, 95], [9.348, 97.5], [11.345, 99], [16.266, 99.9]],
            4 => [[3.357, 50], [5.385, 75], [7.779, 90], [9.488, 95], [11.143, 97.5], [13.277, 99], [18.467, 99.9]],
        ];

        $row = $table[$df] ?? $table[1];
        $confidence = 0.0;
        foreach ($row as [$threshold, $conf]) {
            if ($chiSquare >= $threshold) {
                $confidence = $conf;
            }
        }
        return $confidence;
    }

    /**
     * Compare multiple campaigns
      * @param array<string|int, mixed> $campaignIds
     */
    public function compareCampaigns(array $campaignIds): array
    {
        $comparison = [];

        foreach ($campaignIds as $campaignId) {
            $campaign = $this->entityManager->getRepository('App\Entity\EmailCampaign')->find($campaignId);
            
            if (!$campaign) {
                continue;
            }

            $metrics = $this->getCampaignMetrics($campaign);
            $comparison[] = [
                'id' => $campaign->getId(),
                'name' => $campaign->getName(),
                'metrics' => $metrics,
            ];
        }

        // Calculate averages
        $totalCampaigns = count($comparison);
        $averages = [
            'openRate' => 0,
            'clickRate' => 0,
            'replyRate' => 0,
        ];

        foreach ($comparison as $campaign) {
            $averages['openRate'] += $campaign['metrics']['openRate'];
            $averages['clickRate'] += $campaign['metrics']['clickRate'];
            $averages['replyRate'] += $campaign['metrics']['replyRate'];
        }

        if ($totalCampaigns > 0) {
            $averages['openRate'] = round($averages['openRate'] / $totalCampaigns, 2);
            $averages['clickRate'] = round($averages['clickRate'] / $totalCampaigns, 2);
            $averages['replyRate'] = round($averages['replyRate'] / $totalCampaigns, 2);
        }

        return [
            'campaigns' => $comparison,
            'averages' => $averages,
        ];
    }

    /**
     * Get best time to send analysis
     */
    public function getBestTimeToSend(): array
    {
        // Native SQL: HOUR()/DAYOFWEEK() are not registered DQL functions —
        // the DQL variant failed at parse time. Delivered population only.
        /** @var array<int, array<string, mixed>> $hourlyStats */
        $hourlyStats = $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT
                HOUR(opened_at) AS `hour`,
                COUNT(id) AS opens,
                (SELECT COUNT(es2.id) FROM email_sends es2
                  WHERE HOUR(es2.sent_at) = HOUR(es.opened_at)
                    AND es2.status IN ('sent', 'bounced')) AS sent
             FROM email_sends es
             WHERE opened_at IS NOT NULL AND es.status IN ('sent', 'bounced')
             GROUP BY HOUR(opened_at)
             ORDER BY `hour` ASC"
        );

        $hourlyOpenRates = [];
        foreach ($hourlyStats as $stat) {
            $hour = (int) $stat['hour'];
            $openRate = $stat['sent'] > 0 ? ($stat['opens'] / $stat['sent']) * 100 : 0;
            
            $hourlyOpenRates[$hour] = [
                'hour' => $hour,
                'opens' => (int) $stat['opens'],
                'sent' => (int) $stat['sent'],
                'openRate' => round($openRate, 2),
            ];
        }

        // Native SQL (same reason as the hourly query above).
        /** @var array<int, array<string, mixed>> $dailyStats */
        $dailyStats = $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT
                DAYOFWEEK(opened_at) AS dayOfWeek,
                COUNT(id) AS opens,
                (SELECT COUNT(es2.id) FROM email_sends es2
                  WHERE DAYOFWEEK(es2.sent_at) = DAYOFWEEK(es.opened_at)
                    AND es2.status IN ('sent', 'bounced')) AS sent
             FROM email_sends es
             WHERE opened_at IS NOT NULL AND es.status IN ('sent', 'bounced')
             GROUP BY DAYOFWEEK(opened_at)
             ORDER BY dayOfWeek ASC"
        );

        $dailyOpenRates = [];
        $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        
        foreach ($dailyStats as $stat) {
            $dayOfWeek = (int) $stat['dayOfWeek'];
            $openRate = $stat['sent'] > 0 ? ($stat['opens'] / $stat['sent']) * 100 : 0;
            
            $dailyOpenRates[$dayOfWeek] = [
                'dayOfWeek' => $dayOfWeek,
                'dayName' => $dayNames[$dayOfWeek - 1] ?? 'Unknown',
                'opens' => (int) $stat['opens'],
                'sent' => (int) $stat['sent'],
                'openRate' => round($openRate, 2),
            ];
        }

        // Find best hour
        $bestHour = null;
        $bestHourRate = 0;
        foreach ($hourlyOpenRates as $hour => $data) {
            if ($data['openRate'] > $bestHourRate) {
                $bestHourRate = $data['openRate'];
                $bestHour = $hour;
            }
        }

        // Find best day
        $bestDay = null;
        $bestDayRate = 0;
        foreach ($dailyOpenRates as $day => $data) {
            if ($data['openRate'] > $bestDayRate) {
                $bestDayRate = $data['openRate'];
                $bestDay = $data['dayName'];
            }
        }

        return [
            'hourly' => array_values($hourlyOpenRates),
            'daily' => array_values($dailyOpenRates),
            'recommendations' => [
                'bestHour' => $bestHour,
                'bestHourRate' => round($bestHourRate, 2),
                'bestDay' => $bestDay,
                'bestDayRate' => round($bestDayRate, 2),
            ],
        ];
    }

    /**
     * Get funnel analysis (sent -> opened -> clicked -> replied)
     */
    public function getFunnelAnalysis(EmailCampaign $campaign): array
    {
        $metrics = $this->getCampaignMetrics($campaign);

        $funnel = [
            ['stage' => 'Sent', 'count' => $metrics['sent'], 'percentage' => 100],
            ['stage' => 'Opened', 'count' => $metrics['opened'], 'percentage' => $metrics['openRate']],
            ['stage' => 'Clicked', 'count' => $metrics['clicked'], 'percentage' => $metrics['clickRate']],
            ['stage' => 'Replied', 'count' => $metrics['replied'], 'percentage' => $metrics['replyRate']],
        ];

        // Calculate drop-off rates
        $dropOff = [
            'sentToOpened' => round(100 - $metrics['openRate'], 2),
            'openedToClicked' => round(100 - $metrics['clickToOpenRate'], 2),
            'clickedToReplied' => $metrics['clicked'] > 0 ? round(100 - (($metrics['replied'] / $metrics['clicked']) * 100), 2) : 0,
        ];

        return [
            'funnel' => $funnel,
            'dropOff' => $dropOff,
        ];
    }

    /**
     * Export campaign metrics to CSV format
     */
    public function exportCampaignMetrics(EmailCampaign $campaign): string
    {
        $metrics = $this->getCampaignMetrics($campaign);
        
        $csv = "Metric,Value\n";
        foreach ($metrics as $key => $value) {
            $csv .= ucfirst($key) . "," . $value . "\n";
        }

        return $csv;
    }

    /**
     * Get top performing campaigns
     */
    public function getTopPerformingCampaigns(int $limit = 10, string $metric = 'openRate'): array
    {
        // Archived campaigns are retired from rankings.
        $campaigns = $this->entityManager->createQuery(
            'SELECT c FROM App\Entity\EmailCampaign c WHERE c.archivedAt IS NULL ORDER BY c.createdAt DESC'
        )
        ->setMaxResults($limit * 2)
        ->getResult();

        $performance = [];
        foreach ($campaigns as $campaign) {
            $metrics = $this->getCampaignMetrics($campaign);
            $performance[] = [
                'id' => $campaign->getId(),
                'name' => $campaign->getName(),
                'metric' => $metrics[$metric] ?? 0,
                'metrics' => $metrics,
            ];
        }

        // Sort by metric
        usort($performance, function ($a, $b) use ($metric) {
            return $b['metric'] <=> $a['metric'];
        });

        return array_slice($performance, 0, $limit);
    }
}
