<?php

namespace App\Tests\Unit\Service\WebCrawler;

use App\Service\WebCrawler\LeadScoringService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LeadScoringServiceTest extends TestCase
{
    private LeadScoringService $service;
    private LoggerInterface $logger;
    private string $testConfigPath;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        
        // Create a test config file
        $this->testConfigPath = sys_get_temp_dir() . '/test_crawler_config_' . uniqid() . '.yaml';
        $this->createTestConfig();
        
        $this->service = new LeadScoringService($this->logger, $this->testConfigPath);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testConfigPath)) {
            unlink($this->testConfigPath);
        }
    }

    private function createTestConfig(): void
    {
        $config = <<<YAML
weights:
  geo: 20
  mfg_fit: 20
  procurement: 18
  sector: 12
  morocco_evidence: 15
  contactability: 8
  freshness: 7

thresholds:
  recommend: 55
  drop: 30

zones:
  morocco_freezones:
    - "Tanger Free Zone"
    - "Tanger Automotive City"
    - "Atlantic Free Zone Kenitra"
    - "Casablanca"
    - "Bouskoura"
    - "Nouaceur"

keywords:
  manufacturing:
    - "PCBA"
    - "SMT"
    - "EMS"
    - "electronics assembly"
    - "contract manufacturing"
  procurement:
    - "supplier portal"
    - "vendor registration"
    - "RFQ"
    - "RFP"
  sectors:
    - "Automotive"
    - "Aerospace"
    - "Industrial"
    - "Rail"
YAML;

        file_put_contents($this->testConfigPath, $config);
    }

    public function testScoreLeadWithHighScore(): void
    {
        $lead = [
            'page_content' => 'PCBA manufacturing, SMT assembly, supplier portal, RFQ process, Automotive sector',
            'address' => 'Tanger Free Zone, Morocco',
            'morocco_facility' => true,
            'contact_emails_public' => ['procurement@example.com'],
            'supplier_portal_url' => 'https://example.com/portal',
            'content_last_modified' => (new \DateTime('-6 months'))->format('Y-m-d')
        ];

        $result = $this->service->scoreLead($lead);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('breakdown', $result);
        $this->assertArrayHasKey('recommendation', $result);
        $this->assertArrayHasKey('reason', $result);
        
        // Should score high (geo + mfg + procurement + sector + morocco + contact + fresh)
        $this->assertGreaterThanOrEqual(55, $result['score']);
        $this->assertEquals('approve', $result['recommendation']);
    }

    public function testScoreLeadWithLowScore(): void
    {
        $lead = [
            'page_content' => 'General information about our company',
            'address' => 'Unknown location',
        ];

        $result = $this->service->scoreLead($lead);

        $this->assertLessThan(30, $result['score']);
        $this->assertEquals('drop', $result['recommendation']);
    }

    public function testScoreLeadWithMediumScore(): void
    {
        $lead = [
            'page_content' => 'PCBA manufacturing, SMT services, supplier portal, vendor registration, Automotive and Aerospace sectors',
            'address' => 'Germany',
            'contact_form_url' => 'https://example.com/contact',
        ];

        $result = $this->service->scoreLead($lead);

        $this->assertGreaterThanOrEqual(30, $result['score']);
        $this->assertLessThan(55, $result['score']);
        $this->assertEquals('review', $result['recommendation']);
    }

    public function testGeoScoringWithMoroccoFreeZone(): void
    {
        $lead = [
            'page_content' => 'Located in Tanger Automotive City',
            'address' => '',
        ];

        $result = $this->service->scoreLead($lead);
        
        $this->assertArrayHasKey('geo', $result['breakdown']);
        $this->assertEquals(20, $result['breakdown']['geo']['score']);
    }

    public function testGeoScoringWithoutMorocco(): void
    {
        $lead = [
            'page_content' => 'Located in Germany',
            'address' => 'Berlin, Germany',
        ];

        $result = $this->service->scoreLead($lead);
        
        $this->assertEquals(0, $result['breakdown']['geo']['score']);
    }

    public function testManufacturingFitScoring(): void
    {
        $lead = [
            'page_content' => 'We provide PCBA, SMT, EMS, and electronics assembly services',
            'address' => '',
        ];

        $result = $this->service->scoreLead($lead);
        
        $this->assertArrayHasKey('manufacturing', $result['breakdown']);
        $this->assertEquals(20, $result['breakdown']['manufacturing']['score']); // 4 terms * 5 = 20
    }

    public function testProcurementScoring(): void
    {
        $lead = [
            'page_content' => 'supplier portal available, vendor registration required, RFQ and RFP processes',
            'address' => '',
        ];

        $result = $this->service->scoreLead($lead);
        
        $this->assertArrayHasKey('procurement', $result['breakdown']);
        // 4 markers * 6 = 24, but max is 18
        $this->assertEquals(18, $result['breakdown']['procurement']['score']);
    }

    public function testSectorScoring(): void
    {
        $lead = [
            'page_content' => 'We serve Automotive, Aerospace, and Industrial sectors',
            'address' => '',
        ];

        $result = $this->service->scoreLead($lead);
        
        $this->assertArrayHasKey('sector', $result['breakdown']);
        $this->assertEquals(9, $result['breakdown']['sector']['score']); // 3 sectors * 3 = 9
    }

    public function testMoroccoEvidenceScoring(): void
    {
        $lead = [
            'page_content' => '',
            'address' => '',
            'morocco_facility' => true,
            'morocco_jobs' => true,
            'morocco_news' => true,
        ];

        $result = $this->service->scoreLead($lead);
        
        $this->assertArrayHasKey('morocco_evidence', $result['breakdown']);
        $this->assertEquals(15, $result['breakdown']['morocco_evidence']['score']); // 7 + 5 + 3 = 15
    }

    public function testContactabilityScoring(): void
    {
        $lead = [
            'page_content' => '',
            'address' => '',
            'contact_emails_public' => ['procurement@example.com'],
            'contact_form_url' => 'https://example.com/contact',
            'supplier_portal_url' => 'https://example.com/portal',
        ];

        $result = $this->service->scoreLead($lead);
        
        $this->assertArrayHasKey('contactability', $result['breakdown']);
        $this->assertEquals(8, $result['breakdown']['contactability']['score']); // 4 + 2 + 2 = 8
    }

    public function testFreshnessScoring(): void
    {
        $recentDate = (new \DateTime('-6 months'))->format('Y-m-d');
        $lead = [
            'page_content' => '',
            'address' => '',
            'content_last_modified' => $recentDate,
        ];

        $result = $this->service->scoreLead($lead);
        
        $this->assertArrayHasKey('freshness', $result['breakdown']);
        $this->assertEquals(7, $result['breakdown']['freshness']['score']);
    }

    public function testFreshnessScoringOldContent(): void
    {
        $oldDate = (new \DateTime('-3 years'))->format('Y-m-d');
        $lead = [
            'page_content' => '',
            'address' => '',
            'content_last_modified' => $oldDate,
        ];

        $result = $this->service->scoreLead($lead);
        
        $this->assertEquals(0, $result['breakdown']['freshness']['score']);
    }

    public function testScoreLeadsInBatch(): void
    {
        $leads = [
            [
                'lead_id' => 1,
                'page_content' => 'PCBA, supplier portal, Automotive',
                'address' => 'Tanger Free Zone',
            ],
            [
                'lead_id' => 2,
                'page_content' => 'General company info',
                'address' => 'Unknown',
            ],
            [
                'lead_id' => 3,
                'page_content' => 'SMT assembly, RFQ, Aerospace',
                'address' => 'Germany',
            ],
        ];

        $scored = $this->service->scoreLeads($leads);

        $this->assertCount(3, $scored);
        
        // Should be sorted by score descending
        $this->assertGreaterThanOrEqual($scored[1]['lead_score'], $scored[0]['lead_score']);
        $this->assertGreaterThanOrEqual($scored[2]['lead_score'], $scored[1]['lead_score']);
        
        // Each should have scoring fields
        foreach ($scored as $lead) {
            $this->assertArrayHasKey('lead_score', $lead);
            $this->assertArrayHasKey('score_breakdown', $lead);
            $this->assertArrayHasKey('recommendation', $lead);
            $this->assertArrayHasKey('score_reason', $lead);
        }
    }

    public function testCalculatePrecision(): void
    {
        $scoredLeads = [
            ['lead_id' => 1, 'lead_score' => 80],
            ['lead_id' => 2, 'lead_score' => 70],
            ['lead_id' => 3, 'lead_score' => 60],
            ['lead_id' => 4, 'lead_score' => 50],
            ['lead_id' => 5, 'lead_score' => 40],
        ];

        // Leads 1, 2, 3 are approved
        $approvedLeadIds = [1, 2, 3];

        $precision = $this->service->calculatePrecision($scoredLeads, $approvedLeadIds, 5);

        // 3 approved out of 5 = 0.6
        $this->assertEquals(0.6, $precision);
    }

    public function testCalculatePrecisionTopN(): void
    {
        $scoredLeads = [
            ['lead_id' => 1, 'lead_score' => 80],
            ['lead_id' => 2, 'lead_score' => 70],
            ['lead_id' => 3, 'lead_score' => 60],
            ['lead_id' => 4, 'lead_score' => 50],
            ['lead_id' => 5, 'lead_score' => 40],
        ];

        // Only lead 1 and 2 are approved
        $approvedLeadIds = [1, 2];

        $precision = $this->service->calculatePrecision($scoredLeads, $approvedLeadIds, 2);

        // 2 approved out of top 2 = 1.0
        $this->assertEquals(1.0, $precision);
    }

    public function testScoreIsCappedAt100(): void
    {
        $lead = [
            'page_content' => 'PCBA SMT EMS electronics assembly supplier portal vendor registration RFQ RFP Automotive Aerospace Industrial Rail',
            'address' => 'Tanger Free Zone, Tanger Automotive City',
            'morocco_facility' => true,
            'morocco_jobs' => true,
            'morocco_news' => true,
            'contact_emails_public' => ['procurement@example.com'],
            'contact_form_url' => 'https://example.com/contact',
            'supplier_portal_url' => 'https://example.com/portal',
            'content_last_modified' => (new \DateTime('-6 months'))->format('Y-m-d'),
        ];

        $result = $this->service->scoreLead($lead);

        $this->assertLessThanOrEqual(100, $result['score']);
    }

    public function testConfigFileNotFoundThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config file not found');

        new LeadScoringService($this->logger, '/nonexistent/path/config.yaml');
    }
}
