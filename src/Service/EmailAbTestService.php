<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailCampaign;
use App\Entity\EmailTemplate;
use App\Entity\EmailSend;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for managing A/B testing in email campaigns
 *
 * Canonical variant structure (written by createAbTest, read by both
 * EmailAbTestService and EmailAnalyticsService):
 *   'id'          => 'A' | 'B' | 'C' | 'D'
 *   'name'        => human-readable label
 *   'percentage'  => audience share
 *   'configuration'=> subject/content configuration
 *   'sends_count' | 'opens_count' | 'clicks_count' | 'replies_count'
 *
 * EmailSend.variant stores the variant ID ('A', 'B', ...) so analytics can
 * filter by it.
 */
class EmailAbTestService
{
    /** Minimum sends required per variant before a winner can be declared */
    private const MIN_SENDS_PER_VARIANT = 1;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailAnalyticsService $analyticsService
    ) {}

    /**
     * Create A/B test variants for a campaign
     * 
     * @param EmailCampaign $campaign Base campaign
     * @param string $testType Type of test (subject_line, send_time, content, from_name)
     * @param array $variants Array of variant configurations
     * @param float $testPercentage Percentage of audience to test (0-100)
     * @param int $testDuration Duration in hours before declaring winner
     * @return array Array of test configuration
     */
    public /**
 * @param array<string|int, mixed> $variants
 */
function createAbTest(
        EmailCampaign $campaign,
        string $testType,
        array $variants,
        float $testPercentage = 20.0,
        int $testDuration = 24
    ): array {
        // Validate test type
        $validTypes = ['subject_line', 'send_time', 'content', 'from_name'];
        if (!in_array($testType, $validTypes)) {
            throw new \InvalidArgumentException("Invalid test type: $testType");
        }

        // Validate percentage
        if ($testPercentage < 5.0 || $testPercentage > 50.0) {
            throw new \InvalidArgumentException("Test percentage must be between 5% and 50%");
        }

        // Validate variants count
        if (count($variants) < 2 || count($variants) > 4) {
            throw new \InvalidArgumentException("Must have 2-4 variants");
        }

        // Create test configuration
        $testConfig = [
            'test_type' => $testType,
            'test_percentage' => $testPercentage,
            'test_duration_hours' => $testDuration,
            'variants' => [],
            'winner_declared_at' => null,
            'winning_variant' => null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];

        // Configure each variant
        $percentagePerVariant = $testPercentage / count($variants);
        foreach ($variants as $index => $variantData) {
            $variantId = chr(65 + $index); // A, B, C, D

            $testConfig['variants'][$variantId] = [
                'id' => $variantId,
                'name' => is_array($variantData)
                    ? (string) ($variantData['name'] ?? $variantId)
                    : (is_string($variantData) ? $variantData : $variantId),
                'percentage' => $percentagePerVariant,
                'configuration' => $variantData,
                'sends_count' => 0,
                'opens_count' => 0,
                'clicks_count' => 0,
                'replies_count' => 0,
            ];
        }

        // Save to campaign
        $existingConfig = $campaign->getAbTestVariants() ?: [];
        $existingConfig[] = $testConfig;
        $campaign->setAbTestVariants($existingConfig);
        
        $this->entityManager->flush();

        return $testConfig;
    }

    /**
     * Distribute contacts for A/B testing
     * 
     * @param EmailCampaign $campaign Campaign with A/B test
     * @param array $contacts All contacts to send to
     * @return array Distribution map [variant_id => [contact_ids]]
     */
    public /**
 * @param array<string|int, mixed> $contacts
 */
function distributeContactsForTest(EmailCampaign $campaign, array $contacts): array
    {
        $abTestConfig = $this->getCurrentAbTest($campaign);
        if (!$abTestConfig) {
            return ['control' => array_map(fn($c) => $c->getId(), $contacts)];
        }

        $totalContacts = count($contacts);
        $distribution = [];
        
        // Shuffle contacts for random distribution
        shuffle($contacts);
        
        $offset = 0;
        
        // Distribute test variants
        foreach ($abTestConfig['variants'] as $variantId => $variantConfig) {
            $count = (int) ceil($totalContacts * ($variantConfig['percentage'] / 100));
            $variantContacts = array_slice($contacts, $offset, $count);
            $distribution[$variantId] = array_map(fn($c) => $c->getId(), $variantContacts);
            $offset += $count;
        }
        
        // Remaining contacts get control (winning variant after test)
        if ($offset < $totalContacts) {
            $controlContacts = array_slice($contacts, $offset);
            $distribution['control'] = array_map(fn($c) => $c->getId(), $controlContacts);
        }

        return $distribution;
    }

    /**
     * Get configuration for sending a specific variant
     * 
     * @param EmailCampaign $campaign Campaign
     * @param string $variantId Variant ID (A, B, C, D, or control)
     * @return array Configuration to apply
     */
    public function getVariantConfiguration(EmailCampaign $campaign, string $variantId): array
    {
        $abTestConfig = $this->getCurrentAbTest($campaign);
        if (!$abTestConfig || $variantId === 'control') {
            return []; // Use campaign defaults
        }

        if (!isset($abTestConfig['variants'][$variantId])) {
            throw new \InvalidArgumentException("Invalid variant ID: $variantId");
        }

        return $abTestConfig['variants'][$variantId]['configuration'];
    }

    /**
     * Record send for A/B test variant
     * 
     * @param EmailCampaign $campaign Campaign
     * @param string $variantId Variant ID
     * @param EmailSend $emailSend Email send record
     */
    public function recordVariantSend(EmailCampaign $campaign, string $variantId, EmailSend $emailSend): void
    {
        $abTestConfig = $this->getCurrentAbTest($campaign);
        if (!$abTestConfig || !isset($abTestConfig['variants'][$variantId])) {
            return;
        }

        // Update variant stats — target the test that actually owns this variant,
        // not blindly the last test in the list.
        $testIndex = $this->findTestIndexForVariant($campaign, $variantId);
        if ($testIndex === null) {
            return;
        }

        $abTestVariants = $campaign->getAbTestVariants();
        $abTestVariants[$testIndex]['variants'][$variantId]['sends_count']++;

        // Keep EmailSend.variant in sync so analytics can filter by variant ID.
        $emailSend->setVariant($variantId);

        $campaign->setAbTestVariants($abTestVariants);
        $this->entityManager->flush();
    }

    /**
     * Update variant stats based on engagement
     * 
     * @param EmailCampaign $campaign Campaign
     * @param string $variantId Variant ID
     * @param string $engagementType Type (open, click, reply)
     */
    public function recordVariantEngagement(EmailCampaign $campaign, string $variantId, string $engagementType): void
    {
        $abTestConfig = $this->getCurrentAbTest($campaign);
        if (!$abTestConfig || !isset($abTestConfig['variants'][$variantId])) {
            return;
        }

        $testIndex = $this->findTestIndexForVariant($campaign, $variantId);
        if ($testIndex === null) {
            return;
        }

        $abTestVariants = $campaign->getAbTestVariants();

        $statKey = $engagementType . 's_count'; // opens_count, clicks_count, replies_count
        if (isset($abTestVariants[$testIndex]['variants'][$variantId][$statKey])) {
            $abTestVariants[$testIndex]['variants'][$variantId][$statKey]++;
        }

        $campaign->setAbTestVariants($abTestVariants);
        $this->entityManager->flush();
    }

    /**
     * Check if test is complete and analyze results
     * 
     * @param EmailCampaign $campaign Campaign with A/B test
     * @return bool True if test is complete
     */
    public function checkTestCompletion(EmailCampaign $campaign): bool
    {
        $abTestConfig = $this->getCurrentAbTest($campaign);
        if (!$abTestConfig) {
            return false;
        }

        // Check if winner already declared
        if ($abTestConfig['winner_declared_at']) {
            return true;
        }

        // Check if test duration has passed
        $createdAt = new \DateTimeImmutable($abTestConfig['created_at']);
        $testEndTime = $createdAt->modify("+{$abTestConfig['test_duration_hours']} hours");
        
        if (new \DateTimeImmutable() < $testEndTime) {
            return false; // Test still running
        }

        // Test is complete, declare winner
        $this->declareWinner($campaign);
        
        return true;
    }

    /**
     * Declare the winning variant based on performance
     * 
     * @param EmailCampaign $campaign Campaign
     * @param string|null $metricType Metric to use (open_rate, click_rate, reply_rate)
     * @return string Winning variant ID, or '' when there is insufficient data
     */
    /**
     * Canonical per-variant statistics derived from EmailSend rows:
     * delivered (sent+bounced) population + engagement flags. This is the
     * single decision source for winners and results views — JSON counters
     * are display-only.
     *
     * @return array<string, array{delivered: int, opened: int, clicked: int, replied: int}>
     */
    private function deriveVariantStats(EmailCampaign $campaign): array
    {
        $rows = $this->entityManager->createQuery(
            'SELECT es.variant AS variant,
                    SUM(CASE WHEN es.status IN (:delivered) THEN 1 ELSE 0 END) AS delivered,
                    SUM(CASE WHEN es.opened = true THEN 1 ELSE 0 END) AS opened,
                    SUM(CASE WHEN es.clicked = true THEN 1 ELSE 0 END) AS clicked,
                    SUM(CASE WHEN es.replied = true THEN 1 ELSE 0 END) AS replied
             FROM App\\Entity\\EmailSend es
             WHERE es.campaign = :campaign AND es.variant IS NOT NULL
             GROUP BY es.variant'
        )
        ->setParameter('campaign', $campaign)
        ->setParameter('delivered', ['sent', 'bounced'])
        ->getArrayResult();

        $stats = [];
        foreach ($rows as $row) {
            $stats[(string) $row['variant']] = [
                'delivered' => (int) $row['delivered'],
                'opened' => (int) $row['opened'],
                'clicked' => (int) $row['clicked'],
                'replied' => (int) $row['replied'],
            ];
        }

        return $stats;
    }

    public function declareWinner(EmailCampaign $campaign, ?string $metricType = null): string
    {
        $abTestConfig = $this->getCurrentAbTest($campaign);
        if (!$abTestConfig) {
            throw new \RuntimeException("No active A/B test found");
        }

        // Variant statistics are DERIVED from canonical EmailSend rows
        // (delivered population + engagement flags) — the JSON counters are
        // display-only and never the decision source.
        $liveStats = $this->deriveVariantStats($campaign);

        // Default metric based on test type
        if (!$metricType) {
            $metricType = match($abTestConfig['test_type']) {
                'subject_line' => 'open_rate',
                'send_time' => 'open_rate',
                'content' => 'click_rate',
                'from_name' => 'open_rate',
                default => 'click_rate'
            };
        }

        // Minimum-sample guard: a variant with zero sends must never win.
        // Without enough data per variant the test result is meaningless.
        foreach (array_keys($abTestConfig['variants']) as $variantId) {
            if (($liveStats[$variantId]['delivered'] ?? 0) < self::MIN_SENDS_PER_VARIANT) {
                $abTestVariants = $campaign->getAbTestVariants();
                $testIndex = $this->findTestIndex($campaign, $abTestConfig) ?? (count($abTestVariants) - 1);
                $abTestVariants[$testIndex]['winner_declared_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                $abTestVariants[$testIndex]['winning_variant'] = null;
                $abTestVariants[$testIndex]['winning_metric'] = $metricType;
                $abTestVariants[$testIndex]['result'] = 'insufficient_data';

                $campaign->setAbTestVariants($abTestVariants);
                $this->entityManager->flush();

                return '';
            }
        }

        // Calculate metrics for each variant from LIVE stats
        $variantMetrics = [];
        foreach (array_keys($abTestConfig['variants']) as $variantId) {
            $delivered = $liveStats[$variantId]['delivered'] ?? 0;
            if ($delivered === 0) {
                $variantMetrics[$variantId] = 0;
                continue;
            }

            $variantMetrics[$variantId] = match($metricType) {
                'open_rate' => ($liveStats[$variantId]['opened'] / $delivered) * 100,
                'click_rate' => ($liveStats[$variantId]['clicked'] / $delivered) * 100,
                'reply_rate' => ($liveStats[$variantId]['replied'] / $delivered) * 100,
                default => 0
            };
        }

        // Find winning variant
        arsort($variantMetrics);
        $winningVariantId = array_key_first($variantMetrics);

        // Update test configuration
        $abTestVariants = $campaign->getAbTestVariants();
        $testIndex = $this->findTestIndex($campaign, $abTestConfig) ?? (count($abTestVariants) - 1);
        $abTestVariants[$testIndex]['winner_declared_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $abTestVariants[$testIndex]['winning_variant'] = $winningVariantId;
        $abTestVariants[$testIndex]['winning_metric'] = $metricType;
        $abTestVariants[$testIndex]['variant_metrics'] = $variantMetrics;
        $abTestVariants[$testIndex]['result'] = 'winner_declared';
        
        $campaign->setAbTestVariants($abTestVariants);
        $this->entityManager->flush();

        return $winningVariantId;
    }

    /**
     * Get detailed A/B test results with statistical analysis
     * 
     * @param EmailCampaign $campaign Campaign
     * @return array Test results with statistics
     */
    public function getTestResults(EmailCampaign $campaign): array
    {
        $abTestConfig = $this->getCurrentAbTest($campaign);
        if (!$abTestConfig) {
            return [];
        }

        $results = [
            'test_type' => $abTestConfig['test_type'],
            'test_percentage' => $abTestConfig['test_percentage'],
            'test_duration_hours' => $abTestConfig['test_duration_hours'],
            'created_at' => $abTestConfig['created_at'],
            'winner_declared_at' => $abTestConfig['winner_declared_at'],
            'winning_variant' => $abTestConfig['winning_variant'],
            'variants' => [],
        ];

        // Comprehensive metrics DERIVED from canonical EmailSend rows —
        // engagement is computed where opens/clicks/replies are recorded,
        // so the results view can never diverge from reality.
        $liveStats = $this->deriveVariantStats($campaign);
        foreach ($abTestConfig['variants'] as $variantId => $variantData) {
            $sends = $liveStats[$variantId]['delivered'] ?? 0;
            $opens = $liveStats[$variantId]['opened'] ?? 0;
            $clicks = $liveStats[$variantId]['clicked'] ?? 0;
            $replies = $liveStats[$variantId]['replied'] ?? 0;

            $results['variants'][$variantId] = [
                'id' => $variantId,
                'name' => $variantData['name'] ?? $variantId,
                'configuration' => $variantData['configuration'],
                'sends' => $sends,
                'opens' => $opens,
                'clicks' => $clicks,
                'replies' => $replies,
                'open_rate' => $sends > 0 ? round(($opens / $sends) * 100, 2) : 0,
                'click_rate' => $sends > 0 ? round(($clicks / $sends) * 100, 2) : 0,
                'reply_rate' => $sends > 0 ? round(($replies / $sends) * 100, 2) : 0,
                'ctor' => $opens > 0 ? round(($clicks / $opens) * 100, 2) : 0, // Click-to-open rate
                'is_winner' => ($abTestConfig['winning_variant'] ?? null) !== null && $variantId === $abTestConfig['winning_variant'],
            ];
        }

        // Calculate statistical significance if winner declared
        if ($abTestConfig['winning_variant'] ?? null) {
            $metricType = $abTestConfig['winning_metric'] ?? $this->defaultMetricForTestType($abTestConfig['test_type'] ?? '');
            $results['statistical_analysis'] = $this->calculateStatisticalSignificance($results['variants'], $metricType);
        }

        return $results;
    }

    /**
     * Calculate statistical significance between variants
     * 
     * Uses the metric the test actually measures: open_rate compares opens,
     * click_rate compares clicks, reply_rate compares replies.
     * 
     * @param array $variants Variant metrics
     * @param string $metricType Metric the test measures (open_rate, click_rate, reply_rate)
     * @return array Statistical analysis
     */
    private /**
 * @param array<string|int, mixed> $variants
 */
function calculateStatisticalSignificance(array $variants, string $metricType = 'open_rate'): array
    {
        if (count($variants) < 2) {
            return ['significant' => false, 'confidence' => 0];
        }

        // Get winning and control variants
        $winningVariant = null;
        $controlVariant = null;
        
        foreach ($variants as $variant) {
            if ($variant['is_winner']) {
                $winningVariant = $variant;
            } else {
                $controlVariant = $variant;
            }
        }

        if (!$winningVariant || !$controlVariant) {
            return ['significant' => false, 'confidence' => 0];
        }

        // Select the metric the test type declares — NOT hardcoded opens.
        $metricKey = match ($metricType) {
            'click_rate' => 'clicks',
            'reply_rate' => 'replies',
            default => 'opens',
        };

        $n1 = $winningVariant['sends'];
        $n2 = $controlVariant['sends'];
        $m1 = $winningVariant[$metricKey];
        $m2 = $controlVariant[$metricKey];
        $p1 = $m1 / max($n1, 1);
        $p2 = $m2 / max($n2, 1);

        // Guard against both variants having zero sends
        if ($n1 === 0 || $n2 === 0) {
            return ['significant' => false, 'confidence' => 0];
        }

        // Pooled probability
        $p = ($m1 + $m2) / ($n1 + $n2);

        // Standard error
        $se = sqrt($p * (1 - $p) * (1/$n1 + 1/$n2));

        if ($se == 0) {
            return ['significant' => false, 'confidence' => 0];
        }

        // Z-score (two-proportion z-test)
        $z = abs($p1 - $p2) / $se;

        // Convert z-score to confidence via Abramowitz & Stegun normal CDF approximation
        $confidence = $this->zScoreToConfidence($z);

        // Significant at the 95% level (two-tailed: z > 1.96)
        $isSignificant = $z > 1.96;

        return [
            'significant' => $isSignificant,
            'confidence' => round($confidence, 2),
            'z_score' => round($z, 4),
            'improvement' => round((($p1 - $p2) / max($p2, 0.001)) * 100, 2), // % improvement
        ];
    }

    /**
     * Map a test type to its default success metric
     */
    private function defaultMetricForTestType(string $testType): string
    {
        return match ($testType) {
            'subject_line', 'send_time', 'from_name' => 'open_rate',
            'content' => 'click_rate',
            default => 'click_rate',
        };
    }

    /**
     * Convert z-score to confidence percentage using Abramowitz & Stegun
     * normal CDF approximation (two-tailed).
     * 
     * For z = 1.96 → 95%, z = 2.576 → 99%, z = 3.29 → 99.9%
     */
    private function zScoreToConfidence(float $z): float
    {
        if ($z <= 0) {
            return 0.0;
        }
        // Abramowitz & Stegun approximation of upper tail probability
        $t = 1.0 / (1.0 + 0.2316419 * $z);
        $d = 0.3989422804014327; // 1 / sqrt(2π)
        $prob = $d * exp(-$z * $z / 2.0) * $t * (0.3193815 + $t * (-0.3565638 + $t * (1.781478 + $t * (-1.8212560 + $t * 1.3302744))));
        $pValue = 2.0 * $prob; // two-tailed
        return min(99.9, round((1.0 - $pValue) * 100, 2));
    }

    /**
     * Get the current active A/B test configuration
     * 
     * @param EmailCampaign $campaign Campaign
     * @return array|null Test configuration or null
     */
    public function getCurrentAbTest(EmailCampaign $campaign): ?array
    {
        $abTestVariants = $campaign->getAbTestVariants();
        if (empty($abTestVariants)) {
            return null;
        }

        // Return the most recent test
        return end($abTestVariants);
    }

    /**
     * Find the index of the test that owns the given variant ID.
     * Searches from the most recent test backwards so a variant belonging to
     * an earlier test still updates the correct test.
     */
    private function findTestIndexForVariant(EmailCampaign $campaign, string $variantId): ?int
    {
        $abTestVariants = $campaign->getAbTestVariants();
        for ($i = count($abTestVariants) - 1; $i >= 0; $i--) {
            if (isset($abTestVariants[$i]['variants'][$variantId])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Find the index of a specific test config inside the campaign's list.
     * @param array<string|int, mixed> $testConfig
     */
    private /**
 * @param array<string|int, mixed> $testConfig
 */
function findTestIndex(EmailCampaign $campaign, array $testConfig): ?int
    {
        $abTestVariants = $campaign->getAbTestVariants();
        foreach ($abTestVariants as $index => $config) {
            if ($config === $testConfig) {
                return $index;
            }
        }

        // Fall back to a structural match on created_at when the arrays differ
        // by reference (e.g. re-loaded entities).
        $createdAt = $testConfig['created_at'] ?? null;
        if ($createdAt !== null) {
            foreach ($abTestVariants as $index => $config) {
                if (($config['created_at'] ?? null) === $createdAt) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * Get A/B test recommendations based on campaign history
     * 
     * @param EmailCampaign $campaign Campaign
     * @return array Test recommendations
     */
    public function getTestRecommendations(EmailCampaign $campaign): array
    {
        $recommendations = [];

        // Analyze past campaigns for insights
        $qb = $this->entityManager->createQueryBuilder();
        $pastCampaigns = $qb->select('c')
            ->from(EmailCampaign::class, 'c')
            ->where('c.id != :current_id')
            ->andWhere('c.sentAt IS NOT NULL')
            ->setParameter('current_id', $campaign->getId())
            ->orderBy('c.sentAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        if (empty($pastCampaigns)) {
            return [
                'subject_line' => 'Test different subject line styles (question vs statement)',
                'send_time' => 'Test optimal send time (morning vs afternoon)',
                'content' => 'Test different content approaches (brief vs detailed)',
            ];
        }

        // Analyze subject lines
        $subjectLengths = array_map(fn($c) => strlen($c->getName()), $pastCampaigns);
        $avgLength = array_sum($subjectLengths) / count($subjectLengths);
        
        $recommendations['subject_line'] = $avgLength > 50 
            ? 'Try shorter subject lines (under 50 characters)' 
            : 'Try longer, more descriptive subject lines';

        // Analyze send times
        $recommendations['send_time'] = 'Test morning (9 AM) vs afternoon (2 PM) send times';

        // Content recommendation
        $recommendations['content'] = 'Test personalized content vs generic messaging';

        return $recommendations;
    }
}
