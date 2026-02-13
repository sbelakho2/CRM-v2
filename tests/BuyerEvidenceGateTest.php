<?php

namespace App\Tests;

use App\Service\WebCrawler\Evidence\BuyerEvidenceGate;
use App\Service\WebCrawler\Evidence\BuyerEvidenceResult;
use App\Service\WebCrawler\Evidence\EvidenceItem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests for the Buyer Evidence Gate (Improvement 2A).
 */
class BuyerEvidenceGateTest extends KernelTestCase
{
    private BuyerEvidenceGate $gate;

    protected function setUp(): void
    {
        $this->gate = new BuyerEvidenceGate();
    }

    public function testRealOEMPasses(): void
    {
        // A real OEM company should pass: has product, manufacturing, org footprint
        $result = $this->gate->evaluate(
            'Siemens Energy AG',
            'Siemens Energy is a global leader in energy technology. We design and manufacture gas turbines, transformers, and power supplies. Founded in 2020, headquartered in Munich with 91,000 employees worldwide.',
            'Siemens Energy - Products & Solutions',
            'siemens-energy.com',
        );

        $this->assertTrue($result->passed(), 'Real OEM should pass: ' . $result->getReason());
        $this->assertGreaterThanOrEqual(2, $result->getDistinctPositiveFamilyCount());
        $this->assertArrayHasKey('PRODUCT_PORTFOLIO', $result->getPositiveFamilies());
    }

    public function testGovernmentFails(): void
    {
        $result = $this->gate->evaluate(
            'Ministry of Industry',
            'The Ministry of Industry and Trade oversees government regulations for manufacturing sector development.',
            'Ministry of Industry and Trade - Government Portal',
            'industry.gov.ma',
        );

        $this->assertFalse($result->passed(), 'Government should fail');
        $this->assertNotEmpty($result->getAntiFamilies());
    }

    public function testConsultingFirmFails(): void
    {
        $result = $this->gate->evaluate(
            'McKinsey & Company',
            'McKinsey is a global management consulting firm serving organizations across the private, public, and social sectors.',
            'McKinsey & Company - Advisory Firm',
            'mckinsey.com',
        );

        $this->assertFalse($result->passed(), 'Consulting firm should fail');
    }

    public function testUniversityFails(): void
    {
        $result = $this->gate->evaluate(
            'Imperial College London',
            'Imperial College London is a world-leading university in science, engineering, medicine, and business.',
            'Imperial College London',
            'imperial.ac.uk',
        );

        $this->assertFalse($result->passed(), 'University should fail');
    }

    public function testRealAerospaceOEMPasses(): void
    {
        $result = $this->gate->evaluate(
            'Leonardo SpA',
            'Leonardo is a global aerospace, defense and security company. We design and manufacture electronics systems, avionics, radar and sensor systems. Production facility in Italy. ISO 9001 and AS9100 certified. Since 1948, over 50,000 employees.',
            'Leonardo - Aerospace, Defence and Security',
            'leonardocompany.com',
        );

        $this->assertTrue($result->passed(), 'Aerospace OEM should pass: ' . $result->getReason());
        $this->assertGreaterThanOrEqual(2, $result->getDistinctPositiveFamilyCount());
    }

    public function testTradeShowFails(): void
    {
        $result = $this->gate->evaluate(
            'Dubai Airshow',
            'Dubai Airshow is the premier aerospace exhibition in the Middle East. Book your booth now for the 2025 exhibition.',
            'Dubai Airshow 2025 - Exhibitor Registration',
            'dubaiairshow.aero',
        );

        $this->assertFalse($result->passed(), 'Trade show should fail: ' . $result->getReason());
    }

    public function testNoEvidenceFails(): void
    {
        // Completely unknown domain with no positive signals at all
        $result = $this->gate->evaluate(
            'RandomSite',
            'Welcome to our website. Check out our latest content.',
            'RandomSite - Home',
            'randomsite.xyz',
        );

        $this->assertFalse($result->passed(), 'No evidence should fail');
    }

    public function testResultSerialization(): void
    {
        $result = $this->gate->evaluate(
            'Test Corp',
            'We design and manufacture power supplies and inverters. Our production facility has ISO 9001 certification. Founded in 1995.',
            'Test Corp - Power Electronics',
            'testcorp.com',
        );

        $array = $result->toArray();
        
        $this->assertArrayHasKey('verdict', $array);
        $this->assertArrayHasKey('reason', $array);
        $this->assertArrayHasKey('positive_families', $array);
        $this->assertArrayHasKey('anti_families', $array);
        $this->assertArrayHasKey('evidence', $array);
        $this->assertArrayHasKey('evaluated_at', $array);
        
        // Should be JSON-serializable
        $json = json_encode($array);
        $this->assertNotFalse($json);
        $this->assertGreaterThan(50, strlen($json));
    }

    public function testContainerWiring(): void
    {
        self::bootKernel();
        $gate = static::getContainer()->get(BuyerEvidenceGate::class);
        $this->assertInstanceOf(BuyerEvidenceGate::class, $gate);
    }
}
