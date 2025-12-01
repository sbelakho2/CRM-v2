<?php

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
        ->getSingleResult();

        $total = (int) $stats['total'];
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
            'deliveryRate' => $sent > 0 ? round(($sent / $total) * 100, 2) : 0,
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
        // Get opens over time
        $opens = $this->entityManager->createQuery(
            "SELECT DATE(es.openedAt) as date, COUNT(es.id) as count 
             FROM App\Entity\EmailSend es 
             WHERE es.campaign = :campaign 
             AND es.openedAt IS NOT NULL 
             GROUP BY DATE(es.openedAt) 
             ORDER BY date ASC"
        )
        ->setParameter('campaign', $campaign)
        ->getResult();

        // Get clicks over time
        $clicks = $this->entityManager->createQuery(
            "SELECT DATE(es.clickedAt) as date, COUNT(es.id) as count 
             FROM App\Entity\EmailSend es 
             WHERE es.campaign = :campaign 
             AND es.clickedAt IS NOT NULL 
             GROUP BY DATE(es.clickedAt) 
             ORDER BY date ASC"
        )
        ->setParameter('campaign', $campaign)
        ->getResult();

        return [
            'opens' => $opens,
            'clicks' => $clicks,
        ];
    }

    /**
     * Analyze A/B test results
     */
    public function analyzeAbTest(EmailCampaign $campaign): array
    {
        $variants = $campaign->getAbTestVariants();
        
        if (!$variants || count($variants) < 2) {
            return [
                'error' => 'Campaign does not have A/B test variants configured',
            ];
        }

        $results = [];
        foreach ($variants as $index => $variant) {
            $variantName = $variant['name'] ?? "Variant " . ($index + 1);
            
            // Query EmailSend records filtered by variant name
            $stats = $this->entityManager->createQuery(
                'SELECT 
                    COUNT(es.id) as sent,
                    SUM(CASE WHEN es.opened = true THEN 1 ELSE 0 END) as opened,
                    SUM(CASE WHEN es.clicked = true THEN 1 ELSE 0 END) as clicked
                 FROM App\Entity\EmailSend es 
                 WHERE es.campaign = :campaign AND es.variant = :variant'
            )
            ->setParameter('campaign', $campaign)
            ->setParameter('variant', $variantName)
            ->getSingleResult();
            
            $sent = (int) $stats['sent'];
            $opened = (int) $stats['opened'];
            $clicked = (int) $stats['clicked'];
            
            $openRate = $sent > 0 ? round(($opened / $sent) * 100, 2) : 0;
            $clickRate = $sent > 0 ? round(($clicked / $sent) * 100, 2) : 0;
            
            $results[$variantName] = [
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
            if ($metrics['clickRate'] > $highestClickRate) {
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
            
            $expected = $metrics['sent'] * $expectedClickRate;
            $observed = $metrics['clicked'];
            
            if ($expected > 0) {
                $chiSquare += pow($observed - $expected, 2) / $expected;
            }
        }

        // Critical value for 95% confidence with 1 degree of freedom is 3.841
        $isSignificant = $chiSquare > 3.841;
        $confidence = min(99.9, 80 + ($chiSquare * 5)); // Simplified confidence calculation

        return [
            'isSignificant' => $isSignificant,
            'confidence' => round($confidence, 1),
            'chiSquare' => round($chiSquare, 4),
        ];
    }

    /**
     * Compare multiple campaigns
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
        // Analyze open rates by hour of day
        $hourlyStats = $this->entityManager->createQuery(
            'SELECT 
                HOUR(es.openedAt) as hour,
                COUNT(es.id) as opens,
                (SELECT COUNT(es2.id) FROM App\Entity\EmailSend es2 WHERE HOUR(es2.sentAt) = HOUR(es.openedAt)) as sent
             FROM App\Entity\EmailSend es 
             WHERE es.openedAt IS NOT NULL 
             GROUP BY HOUR(es.openedAt) 
             ORDER BY hour ASC'
        )
        ->getResult();

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

        // Analyze open rates by day of week
        $dailyStats = $this->entityManager->createQuery(
            'SELECT 
                DAYOFWEEK(es.openedAt) as dayOfWeek,
                COUNT(es.id) as opens,
                (SELECT COUNT(es2.id) FROM App\Entity\EmailSend es2 WHERE DAYOFWEEK(es2.sentAt) = DAYOFWEEK(es.openedAt)) as sent
             FROM App\Entity\EmailSend es 
             WHERE es.openedAt IS NOT NULL 
             GROUP BY DAYOFWEEK(es.openedAt) 
             ORDER BY dayOfWeek ASC'
        )
        ->getResult();

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
        // Get all campaigns and calculate metrics
        $campaigns = $this->entityManager->getRepository('App\Entity\EmailCampaign')
            ->findAll();

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
