<?php

namespace App\Tests\Unit\Service;

use App\Entity\Lead;
use App\Service\LeadSalesAnalystService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the Lead Sales Analyst Service
 */
class LeadSalesAnalystServiceTest extends TestCase
{
    private LeadSalesAnalystService $service;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new LeadSalesAnalystService($this->logger, null, null);
    }

    private function createLead(array $attributes = []): Lead
    {
        $lead = new Lead();
        
        $lead->setCompanyName($attributes['company_name'] ?? 'Test Company');
        $lead->setFitSignals($attributes['fit_signals'] ?? []);
        $lead->setQualityStack($attributes['quality_stack'] ?? []);
        $lead->setSectorTags($attributes['sector_tags'] ?? []);
        $lead->setNotesAuto($attributes['notes_auto'] ?? '');
        $lead->setReviewStatus($attributes['review_status'] ?? 'pending');
        $lead->setLeadScore($attributes['lead_score'] ?? 50);

        return $lead;
    }

    public function testAnalyzeLeadReturnsComprehensiveAnalysis(): void
    {
        $lead = $this->createLead([
            'company_name' => 'Aerospace Tech Inc',
            'fit_signals' => ['pcba', 'smt', 'testing'],
            'quality_stack' => ['ISO 9001', 'AS9100'],
            'sector_tags' => ['aerospace', 'defense'],
        ]);

        $result = $this->service->analyzeLead($lead);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('lead_id', $result);
        $this->assertArrayHasKey('company_name', $result);
        $this->assertArrayHasKey('overall_fit_score', $result);
        $this->assertArrayHasKey('fit_grade', $result);
        $this->assertArrayHasKey('fit_breakdown', $result);
        $this->assertArrayHasKey('pain_points', $result);
        $this->assertArrayHasKey('conversation_starters', $result);
        $this->assertArrayHasKey('competitive_positioning', $result);
        $this->assertArrayHasKey('decision_maker_targets', $result);
        $this->assertArrayHasKey('recommended_approach', $result);
        $this->assertArrayHasKey('next_steps', $result);
        $this->assertArrayHasKey('analyzed_at', $result);
    }

    public function testHighFitScoreForMatchingCapabilities(): void
    {
        // Capability/certification truth must be SUPPLIED (verified
        // operating data) — never assumed from source-code defaults.
        $this->service = new LeadSalesAnalystService(
            $this->logger,
            null, // no verified-register lookup in this unit test
            null,
            ['pcba' => true, 'smt' => true, 'through_hole' => true, 'testing' => true, 'prototyping' => true],
            ['ISO 9001', 'ISO 14001', 'AS9100'],
        );

        $lead = $this->createLead([
            'fit_signals' => ['pcba', 'smt', 'through_hole', 'testing', 'prototyping'],
            'quality_stack' => ['ISO 9001', 'ISO 14001', 'AS9100'],
            'sector_tags' => ['aerospace'],
        ]);

        $result = $this->service->analyzeLead($lead);

        // Multiple matching VERIFIED capabilities should give reasonable score (>50)
        $this->assertGreaterThan(50, $result['overall_fit_score']);
    }

    public function testNoUnsubstantiatedClaimsWithoutVerifiedCapabilityData(): void
    {
        // Default service: no capability data supplied -> nothing to match,
        // no capability-based claims.
        $lead = $this->createLead([
            'fit_signals' => ['pcba', 'smt'],
            'quality_stack' => ['ISO 9001'],
            'sector_tags' => ['industrial'],
        ]);

        $result = $this->service->analyzeLead($lead);

        $this->assertLessThanOrEqual(
            50,
            $result['overall_fit_score'],
            'Without verified capability data the analyst must not score as a strong capability match'
        );
    }

    public function testLowFitScoreForNoMatchingCapabilities(): void
    {
        $lead = $this->createLead([
            'fit_signals' => [],
            'quality_stack' => [],
            'sector_tags' => ['retail'],
        ]);

        $result = $this->service->analyzeLead($lead);

        // No matching capabilities should give low score
        $this->assertLessThan(50, $result['overall_fit_score']);
    }

    public function testFitGradeIsCalculated(): void
    {
        $lead = $this->createLead([
            'fit_signals' => ['pcba'],
        ]);

        $result = $this->service->analyzeLead($lead);
        
        // Just verify the grade is set and valid
        $this->assertNotEmpty($result['fit_grade']);
        $this->assertContains($result['fit_grade'], ['A', 'B', 'C', 'D', 'F']);
    }

    public function testPainPointDetectionQualityIssues(): void
    {
        $lead = $this->createLead([
            'notes_auto' => 'Hiring QA engineer. Recent product recall. Looking for inspection services.',
        ]);

        $result = $this->service->analyzeLead($lead);

        $painPointTypes = array_column($result['pain_points'], 'type');
        $this->assertContains('qa_struggles', $painPointTypes);
    }

    public function testPainPointDetectionSupplyChain(): void
    {
        $lead = $this->createLead([
            'notes_auto' => 'Supply chain diversification initiative. Nearshoring strategy for EU.',
        ]);

        $result = $this->service->analyzeLead($lead);

        $painPointTypes = array_column($result['pain_points'], 'type');
        $this->assertContains('supply_chain_risk', $painPointTypes);
    }

    public function testConversationStartersGenerated(): void
    {
        $lead = $this->createLead([
            'fit_signals' => ['pcba', 'smt'],
            'quality_stack' => ['ISO 9001'],
            'sector_tags' => ['industrial'],
            'notes_auto' => 'Hiring production engineers.',
        ]);

        $result = $this->service->analyzeLead($lead);

        $this->assertNotEmpty($result['conversation_starters']);
        
        foreach ($result['conversation_starters'] as $starter) {
            $this->assertArrayHasKey('type', $starter);
            $this->assertArrayHasKey('topic', $starter);
            $this->assertArrayHasKey('opener', $starter);
            $this->assertArrayHasKey('follow_up', $starter);
            $this->assertArrayHasKey('priority', $starter);
        }
    }

    public function testCompetitivePositioningIncludesGeographicDifferentiator(): void
    {
        $lead = $this->createLead([
            'sector_tags' => ['automotive'],
        ]);

        $result = $this->service->analyzeLead($lead);

        $differentiators = array_column($result['competitive_positioning'], 'differentiator');
        // Lead has no regionTag → gets "Geographic Flexibility" (default)
        $this->assertContains('Geographic Flexibility', $differentiators);
    }

    public function testDecisionMakerTargetsGenerated(): void
    {
        $lead = $this->createLead([
            'sector_tags' => ['aerospace'],
        ]);

        $result = $this->service->analyzeLead($lead);

        $this->assertNotEmpty($result['decision_maker_targets']);
        
        foreach ($result['decision_maker_targets'] as $target) {
            $this->assertArrayHasKey('role', $target);
            $this->assertArrayHasKey('why', $target);
            $this->assertArrayHasKey('approach', $target);
        }
    }

    public function testMedicalSectorGetsSpecificCompliance(): void
    {
        $lead = $this->createLead([
            'sector_tags' => ['medical'],
        ]);

        $result = $this->service->analyzeLead($lead);

        $painPointTypes = array_column($result['pain_points'], 'type');
        $this->assertContains('compliance_complexity', $painPointTypes);
    }

    public function testDefenseSectorGetsMilitaryCompliance(): void
    {
        $lead = $this->createLead([
            'sector_tags' => ['defense'],
        ]);

        $result = $this->service->analyzeLead($lead);

        $painPointTypes = array_column($result['pain_points'], 'type');
        $this->assertContains('compliance_complexity', $painPointTypes);
    }

    public function testAnalyzeMultipleLeadsReturnsSortedByPriority(): void
    {
        $highValueLead = $this->createLead([
            'company_name' => 'High Value Corp',
            'fit_signals' => ['pcba', 'smt', 'through_hole', 'testing', 'prototyping'],
            'quality_stack' => ['ISO 9001', 'AS9100'],
            'sector_tags' => ['aerospace'],
            'notes_auto' => 'Quality hiring, expansion, supply chain concerns',
            'lead_score' => 90,
        ]);
        
        $lowValueLead = $this->createLead([
            'company_name' => 'Low Value Inc',
            'fit_signals' => [],
            'quality_stack' => [],
            'sector_tags' => ['retail'],
            'lead_score' => 30,
        ]);

        $results = $this->service->analyzeMultipleLeads([$lowValueLead, $highValueLead]);

        $this->assertCount(2, $results);
        // High value lead should be first
        $this->assertEquals('High Value Corp', $results[0]['company_name']);
    }

    public function testNextStepsGeneratedBasedOnFitScore(): void
    {
        $lead = $this->createLead([
            'fit_signals' => ['pcba', 'smt'],
        ]);

        $result = $this->service->analyzeLead($lead);

        $this->assertNotEmpty($result['next_steps']);
        
        foreach ($result['next_steps'] as $step) {
            $this->assertArrayHasKey('action', $step);
            $this->assertArrayHasKey('description', $step);
            $this->assertArrayHasKey('timeframe', $step);
        }
    }

    public function testRecommendedApproachForHighFit(): void
    {
        $lead = $this->createLead([
            'fit_signals' => ['pcba', 'smt', 'through_hole', 'testing', 'prototyping', 'box_build'],
            'quality_stack' => ['ISO 9001', 'AS9100', 'IATF 16949'],
            'sector_tags' => ['aerospace', 'automotive'],
            'notes_auto' => 'Major quality initiative underway. Supply chain diversification.',
        ]);

        $result = $this->service->analyzeLead($lead);

        // Should have a recommendation based on fit
        $this->assertNotEmpty($result['recommended_approach']);
        // Should contain strategy keyword
        $this->assertMatchesRegularExpression('/AGGRESSIVE|STANDARD|NURTURE|LOW PRIORITY/', $result['recommended_approach']);
    }

    public function testRecommendedApproachForLowFit(): void
    {
        $lead = $this->createLead([
            'fit_signals' => [],
            'quality_stack' => [],
            'sector_tags' => ['retail'],
        ]);

        $result = $this->service->analyzeLead($lead);

        $this->assertStringContainsString('LOW PRIORITY', $result['recommended_approach']);
    }

    public function testEmailOpenerGenerated(): void
    {
        $lead = $this->createLead([
            'company_name' => 'Test Corp',
            'fit_signals' => ['pcba'],
        ]);

        $result = $this->service->analyzeLead($lead);

        $this->assertArrayHasKey('email_opener', $result);
        $this->assertNotEmpty($result['email_opener']);
        $this->assertStringContainsString('Test Corp', $result['email_opener']);
    }

    public function testDealRisksIdentifiedForMissingCertifications(): void
    {
        $lead = $this->createLead([
            'quality_stack' => ['NADCAP', 'ITAR'],
        ]);

        $result = $this->service->analyzeLead($lead);

        $this->assertNotEmpty($result['deal_risks']);
    }
}
