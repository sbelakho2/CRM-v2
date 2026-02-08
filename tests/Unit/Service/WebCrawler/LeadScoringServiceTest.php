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
  geo_morocco: 20
  geo_us: 16
  geo_eu: 14
  geo_uk: 12
  geo: 20
  mfg_fit: 20
  procurement: 18
  sector: 12
  region_evidence: 15
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
  morocco_cities:
    - "Tangier"
    - "Tanger"
    - "Kenitra"
    - "Casablanca"
    - "Rabat"

regions:
  usa:
    east_coast_states:
      - "MA"
      - "NY"
      - "NJ"
      - "PA"
      - "VA"
      - "NC"
      - "GA"
      - "FL"
    texas_metros:
      - "Dallas"
      - "Houston"
      - "Austin"
      - "San Antonio"
  eu:
    core_countries:
      - "DE"
      - "FR"
      - "IT"
      - "ES"
      - "NL"
      - "BE"
    nordics:
      - "SE"
      - "FI"
      - "DK"
    cee:
      - "PL"
      - "CZ"
      - "HU"
    tlds:
      - ".de"
      - ".fr"
      - ".it"
      - ".nl"
      - ".pl"
  uk:
    countries:
      - "England"
      - "Scotland"
      - "Wales"
    tlds:
      - ".uk"
      - ".co.uk"

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
            'page_content' => 'PCBA manufacturing and SMT assembly services. We offer a comprehensive supplier portal and RFQ process for the Automotive sector. Our electronics assembly and contract manufacturing capabilities include EMS solutions for global OEMs in Tanger Free Zone.',
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
            'page_content' => 'We provide PCBA manufacturing and SMT services. Our supplier portal and vendor registration system supports the Automotive and Aerospace sectors with quality electronics assembly and procurement processes.',
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
            'page_content' => 'Our company is located in Tanger Automotive City, one of the premier industrial zones in Morocco. We manufacture electronic assemblies for international clients.',
            'address' => '',
        ];

        $result = $this->service->scoreLead($lead);
        
        $this->assertArrayHasKey('geo', $result['breakdown']);
        $this->assertEquals(20, $result['breakdown']['geo']['score']);
    }

    public function testGeoScoringNoTargetRegion(): void
    {
        $lead = [
            'page_content' => 'Our company is located in a remote island and we provide industrial manufacturing services for a small local market. We have been operational for over twenty years serving diverse clients.',
            'address' => 'Fiji Islands',
        ];

        $result = $this->service->scoreLead($lead);
        
        $this->assertArrayHasKey('geo', $result['breakdown']);
        $this->assertEquals(0, $result['breakdown']['geo']['score']);
    }

    public function testManufacturingFitScoring(): void
    {
        $lead = [
            'page_content' => 'We provide PCBA and SMT services along with full EMS and electronics assembly capabilities. Our state-of-the-art facility handles contract manufacturing for clients worldwide with precision.',
            'address' => '',
        ];

        $result = $this->service->scoreLead($lead);
        
        $this->assertArrayHasKey('manufacturing', $result['breakdown']);
        // 5 keyword matches (PCBA, SMT, EMS, electronics assembly, contract manufacturing) * 5 = 25, capped at 20
        $this->assertEquals(20, $result['breakdown']['manufacturing']['score']);
    }

    public function testProcurementScoring(): void
    {
        $lead = [
            'page_content' => 'Our supplier portal is available for all potential vendors. Vendor registration is required before participation. We accept RFQ and RFP processes through our secure online procurement system for all projects.',
            'address' => '',
        ];

        $result = $this->service->scoreLead($lead);
        
        $this->assertArrayHasKey('procurement', $result['breakdown']);
        // 4 markers (supplier portal, vendor registration, RFQ, RFP) * 6 = 24, capped at 18
        $this->assertEquals(18, $result['breakdown']['procurement']['score']);
    }

    public function testSectorScoring(): void
    {
        $lead = [
            'page_content' => 'We proudly serve the Automotive, Aerospace, and Industrial sectors with cutting-edge electronic manufacturing services, providing comprehensive solutions for demanding industries worldwide.',
            'address' => '',
        ];

        $result = $this->service->scoreLead($lead);
        
        $this->assertArrayHasKey('sector', $result['breakdown']);
        // 3 sectors * 3 = 9
        $this->assertEquals(9, $result['breakdown']['sector']['score']);
    }

    public function testRegionEvidenceWithLegacyMoroccoFields(): void
    {
        $lead = [
            'page_content' => 'We are an international electronics manufacturer with operations in multiple countries. Our extensive network of facilities enables us to serve clients across Europe, Africa, and the Middle East efficiently.',
            'address' => '',
            'morocco_facility' => true,
            'morocco_jobs' => true,
            'morocco_news' => true,
        ];

        $result = $this->service->scoreLead($lead);
        
        $this->assertArrayHasKey('region_evidence', $result['breakdown']);
        // facility=7 + jobs=5 + news=3 = 15
        $this->assertEquals(15, $result['breakdown']['region_evidence']['score']);
    }

    public function testContactabilityScoring(): void
    {
        $lead = [
            'page_content' => 'Welcome to our company website. We provide various electronic manufacturing and assembly services for the global market. Contact us for more information about partnerships and opportunities.',
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
            'page_content' => 'We are a leading electronics manufacturer providing PCB assembly, testing, and box build services. Our facility has been recently upgraded with new equipment for high volume production.',
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
            'page_content' => 'Our company has been providing electronic manufacturing services for the global market for many years. We focus on quality and reliability in every product we deliver to our customers worldwide.',
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

    // --- Multi-region geo tests ---

    public function testGeoScoringWithUSEastCoast(): void
    {
        $lead = [
            'page_content' => 'Our headquarters are in NY with full PCBA and electronics assembly capabilities for the domestic market. We serve clients across the Northeastern corridor with turnkey solutions.',
            'address' => 'Buffalo, NY',
        ];

        $result = $this->service->scoreLead($lead);

        $this->assertArrayHasKey('geo', $result['breakdown']);
        $this->assertEquals(16, $result['breakdown']['geo']['score']);
    }

    public function testGeoScoringWithTexasMetro(): void
    {
        $lead = [
            'page_content' => 'Located in Dallas, we are a leading contract manufacturing company providing electronics assembly and PCB solutions for various industries across the Southern United States region.',
            'address' => 'Dallas, TX',
        ];

        $result = $this->service->scoreLead($lead);

        $this->assertArrayHasKey('geo', $result['breakdown']);
        $this->assertEquals(16, $result['breakdown']['geo']['score']);
    }

    public function testGeoScoringWithEUCountry(): void
    {
        $lead = [
            'page_content' => 'Headquartered in DE, we provide state-of-the-art electronics manufacturing services including PCBA and SMT assembly. We are certified to the highest European quality standards.',
            'address' => 'Munich, DE',
        ];

        $result = $this->service->scoreLead($lead);

        $this->assertArrayHasKey('geo', $result['breakdown']);
        $this->assertEquals(14, $result['breakdown']['geo']['score']);
    }

    public function testGeoScoringWithEUTld(): void
    {
        $lead = [
            'page_content' => 'We offer comprehensive electronics manufacturing capabilities including surface mount technology and through-hole assembly for automotive and industrial clients across Europe.',
            'address' => '',
            'website_root' => 'https://electronics-company.de/about',
        ];

        $result = $this->service->scoreLead($lead);

        $this->assertArrayHasKey('geo', $result['breakdown']);
        $this->assertEquals(14, $result['breakdown']['geo']['score']);
    }

    public function testGeoScoringWithUK(): void
    {
        $lead = [
            'page_content' => 'Based in England, our facility provides full electronics manufacturing services including design, prototyping, and volume production for defence, aerospace, and telecommunications sectors.',
            'address' => 'Manchester, England',
        ];

        $result = $this->service->scoreLead($lead);

        $this->assertArrayHasKey('geo', $result['breakdown']);
        $this->assertEquals(12, $result['breakdown']['geo']['score']);
    }

    public function testGeoScoringWithUKTld(): void
    {
        $lead = [
            'page_content' => 'We are a premier electronics manufacturing services provider with decades of experience in contract manufacturing, offering complete turnkey solutions from prototype to full production.',
            'address' => '',
            'website_root' => 'https://company.co.uk',
        ];

        $result = $this->service->scoreLead($lead);

        $this->assertArrayHasKey('geo', $result['breakdown']);
        $this->assertEquals(12, $result['breakdown']['geo']['score']);
    }

    public function testGeoScoringMoroccoTakesPriorityOverEU(): void
    {
        $lead = [
            'page_content' => 'Our operations span Tanger Automotive City in Morocco and we also have offices in DE. We deliver premium electronics manufacturing across both continents with unmatched quality and speed.',
            'address' => 'Tangier, Morocco',
        ];

        $result = $this->service->scoreLead($lead);

        // Morocco (20) should win over EU (14)
        $this->assertEquals(20, $result['breakdown']['geo']['score']);
    }

    public function testRegionEvidenceWithGenericFields(): void
    {
        $lead = [
            'page_content' => 'We are a major electronics manufacturing company with global operations, including a new production facility in Texas and hiring for key positions across all our manufacturing sites.',
            'address' => 'Dallas, TX',
            'facility_evidence' => true,
            'jobs_evidence' => true,
            'news_evidence' => true,
        ];

        $result = $this->service->scoreLead($lead);

        $this->assertArrayHasKey('region_evidence', $result['breakdown']);
        // facility=7 + jobs=5 + news=3 = 15
        $this->assertEquals(15, $result['breakdown']['region_evidence']['score']);
    }
}
