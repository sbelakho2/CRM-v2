<?php

namespace App\Service;

use App\Entity\EmailCampaign;
use App\Entity\EmailTemplate;
use App\Entity\EmailSend;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for managing A/B testing in email campaigns
 */
class EmailAbTestService
{
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
    public function createAbTest(
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
    public function distributeContactsForTest(EmailCampaign $campaign, array $contacts): array
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

        // Update variant stats
        $abTestVariants = $campaign->getAbTestVariants();
        $testIndex = count($abTestVariants) - 1;
        $abTestVariants[$testIndex]['variants'][$variantId]['sends_count']++;
        
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

        $abTestVariants = $campaign->getAbTestVariants();
        $testIndex = count($abTestVariants) - 1;
        
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
     * @return string Winning variant ID
     */
    public function declareWinner(EmailCampaign $campaign, ?string $metricType = null): string
    {
        $abTestConfig = $this->getCurrentAbTest($campaign);
        if (!$abTestConfig) {
            throw new \RuntimeException("No active A/B test found");
        }

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

        // Calculate metrics for each variant
        $variantMetrics = [];
        foreach ($abTestConfig['variants'] as $variantId => $variantData) {
            $sends = $variantData['sends_count'];
            if ($sends === 0) {
                $variantMetrics[$variantId] = 0;
                continue;
            }

            $variantMetrics[$variantId] = match($metricType) {
                'open_rate' => ($variantData['opens_count'] / $sends) * 100,
                'click_rate' => ($variantData['clicks_count'] / $sends) * 100,
                'reply_rate' => ($variantData['replies_count'] / $sends) * 100,
                default => 0
            };
        }

        // Find winning variant
        arsort($variantMetrics);
        $winningVariantId = array_key_first($variantMetrics);

        // Update test configuration
        $abTestVariants = $campaign->getAbTestVariants();
        $testIndex = count($abTestVariants) - 1;
        $abTestVariants[$testIndex]['winner_declared_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $abTestVariants[$testIndex]['winning_variant'] = $winningVariantId;
        $abTestVariants[$testIndex]['winning_metric'] = $metricType;
        $abTestVariants[$testIndex]['variant_metrics'] = $variantMetrics;
        
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

        // Calculate comprehensive metrics for each variant
        foreach ($abTestConfig['variants'] as $variantId => $variantData) {
            $sends = $variantData['sends_count'];
            $opens = $variantData['opens_count'];
            $clicks = $variantData['clicks_count'];
            $replies = $variantData['replies_count'];

            $results['variants'][$variantId] = [
                'id' => $variantId,
                'configuration' => $variantData['configuration'],
                'sends' => $sends,
                'opens' => $opens,
                'clicks' => $clicks,
                'replies' => $replies,
                'open_rate' => $sends > 0 ? round(($opens / $sends) * 100, 2) : 0,
                'click_rate' => $sends > 0 ? round(($clicks / $sends) * 100, 2) : 0,
                'reply_rate' => $sends > 0 ? round(($replies / $sends) * 100, 2) : 0,
                'ctor' => $opens > 0 ? round(($clicks / $opens) * 100, 2) : 0, // Click-to-open rate
                'is_winner' => $variantId === $abTestConfig['winning_variant'],
            ];
        }

        // Calculate statistical significance if winner declared
        if ($abTestConfig['winning_variant']) {
            $results['statistical_analysis'] = $this->calculateStatisticalSignificance($results['variants']);
        }

        return $results;
    }

    /**
     * Calculate statistical significance between variants
     * 
     * @param array $variants Variant metrics
     * @return array Statistical analysis
     */
    private function calculateStatisticalSignificance(array $variants): array
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

        // Chi-square test for independence
        $n1 = $winningVariant['sends'];
        $n2 = $controlVariant['sends'];
        $p1 = $winningVariant['opens'] / max($n1, 1);
        $p2 = $controlVariant['opens'] / max($n2, 1);

        // Pooled probability
        $p = ($winningVariant['opens'] + $controlVariant['opens']) / ($n1 + $n2);

        // Standard error
        $se = sqrt($p * (1 - $p) * (1/$n1 + 1/$n2));

        if ($se == 0) {
            return ['significant' => false, 'confidence' => 0];
        }

        // Z-score
        $z = abs($p1 - $p2) / $se;

        // Calculate confidence level (approximation)
        $confidence = min(99.9, 50 + ($z * 15)); // Simplified confidence calculation

        // Consider significant if z > 1.96 (95% confidence) or confidence > 95%
        $isSignificant = $z > 1.96 || $confidence > 95;

        return [
            'significant' => $isSignificant,
            'confidence' => round($confidence, 2),
            'z_score' => round($z, 4),
            'improvement' => round((($p1 - $p2) / max($p2, 0.001)) * 100, 2), // % improvement
        ];
    }

    /**
     * Get the current active A/B test configuration
     * 
     * @param EmailCampaign $campaign Campaign
     * @return array|null Test configuration or null
     */
    private function getCurrentAbTest(EmailCampaign $campaign): ?array
    {
        $abTestVariants = $campaign->getAbTestVariants();
        if (empty($abTestVariants)) {
            return null;
        }

        // Return the most recent test
        return end($abTestVariants);
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
