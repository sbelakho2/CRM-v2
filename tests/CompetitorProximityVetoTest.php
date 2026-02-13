<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\WebCrawler\Classifier\CompetitorProximityVeto;
use PHPUnit\Framework\TestCase;

class CompetitorProximityVetoTest extends TestCase
{
    private CompetitorProximityVeto $veto;

    protected function setUp(): void
    {
        $this->veto = new CompetitorProximityVeto();
    }

    // ──────────────────────────────────────────────────────────
    // Mode 1: Hard match — company IS a competitor
    // ──────────────────────────────────────────────────────────

    public function testHardMatchByExactName(): void
    {
        $r = $this->veto->evaluate('Jabil', 'We are a global company.', 'Jabil Inc', 'jabil.com');
        $this->assertTrue($r['vetoed'], 'Jabil should be vetoed as known competitor');
        $this->assertSame('jabil', $r['matchedCompetitor']);
    }

    public function testHardMatchByNameContaining(): void
    {
        $r = $this->veto->evaluate(
            'Celestica International',
            'Leading technology solutions.',
            'Celestica',
            'celestica.com',
        );
        $this->assertTrue($r['vetoed']);
        $this->assertSame('celestica', $r['matchedCompetitor']);
    }

    public function testShortBrandNotMatchedByContains(): void
    {
        // "GPV" is a short brand — should only match if exact company name
        $r = $this->veto->evaluate(
            'GPV Group International',
            'Electronics manufacturing.',
            'GPV Group',
            'gpv.com',
        );
        // Should match via the longer "gpv group" brand
        $this->assertTrue($r['vetoed']);
    }

    // ──────────────────────────────────────────────────────────
    // Mode 2: Proximity — competitor + service language
    // ──────────────────────────────────────────────────────────

    public function testCompetitorMentionWithServiceLanguageVetoes(): void
    {
        $r = $this->veto->evaluate(
            'TechReport Magazine',
            'Foxconn, Flex, and Jabil are the top 3 EMS providers in the global contract manufacturing market.',
            'Top EMS Companies 2024',
            'techreport.com',
        );
        $this->assertTrue($r['vetoed'], 'Should veto: competitor names + EMS service language');
        $this->assertNotNull($r['matchedCompetitor']);
        $this->assertNotNull($r['matchedService']);
        $this->assertFalse($r['overridden']);
    }

    public function testCompetitorMentionWithoutServiceLanguagePasses(): void
    {
        $r = $this->veto->evaluate(
            'AutoParts GmbH',
            'We supply components to major OEMs. Our partner Jabil handles the assembly.',
            'AutoParts GmbH - Automotive Components',
            'autoparts.de',
        );
        // Jabil is mentioned but no clear service-market language
        // (just "handles the assembly" which doesn't match our patterns)
        $this->assertFalse($r['vetoed'], 'Should pass: competitor mentioned but no service language');
    }

    public function testCompetitorMentionWithBuyerOverridePasses(): void
    {
        $r = $this->veto->evaluate(
            'SensorDev Corp',
            'We design and develop our products in-house. We outsource manufacturing to Celestica as our EMS partner for PCB assembly.',
            'SensorDev - Smart Sensor Products',
            'sensordev.com',
        );
        $this->assertFalse($r['vetoed'], 'Should pass: has buyer override language');
        $this->assertTrue($r['overridden']);
    }

    // ──────────────────────────────────────────────────────────
    // No competitor mentioned — always passes
    // ──────────────────────────────────────────────────────────

    public function testNoCompetitorMentionedPasses(): void
    {
        $r = $this->veto->evaluate(
            'Bosch Automotive',
            'Bosch is a global leader in automotive electronics. We design ECUs and sensor systems.',
            'Bosch - Automotive Technology',
            'bosch-automotive.com',
        );
        $this->assertFalse($r['vetoed']);
        $this->assertNull($r['matchedCompetitor']);
    }

    public function testUnrelatedCompanyPasses(): void
    {
        $r = $this->veto->evaluate(
            'Alpine Electronics',
            'Alpine develops infotainment systems, inverters, and power electronics for the automotive industry.',
            'Alpine Electronics - Car Audio & Navigation',
            'alpine-electronics.com',
        );
        $this->assertFalse($r['vetoed']);
    }

    // ──────────────────────────────────────────────────────────
    // Edge cases
    // ──────────────────────────────────────────────────────────

    public function testEmsMarketReportVetoed(): void
    {
        $r = $this->veto->evaluate(
            'MarketScope Research',
            'The global EMS market featuring players like Foxconn, Jabil, Celestica and Flex is expected to grow at 6.2% CAGR. Leading EMS companies are expanding in Morocco and Eastern Europe.',
            'EMS Market Report 2024',
            'marketscope.com',
        );
        $this->assertTrue($r['vetoed'], 'EMS market report mentioning competitors should be vetoed');
    }

    public function testCaseInsensitive(): void
    {
        $r = $this->veto->evaluate(
            'SANMINA CORP',
            'We provide manufacturing services.',
            'Sanmina Corporation',
            'sanmina.com',
        );
        $this->assertTrue($r['vetoed'], 'Should match case-insensitively');
    }

    public function testResultStructure(): void
    {
        $r = $this->veto->evaluate('Test Co', 'Test snippet', 'Test title', 'test.com');
        $this->assertArrayHasKey('vetoed', $r);
        $this->assertArrayHasKey('reason', $r);
        $this->assertArrayHasKey('matchedCompetitor', $r);
        $this->assertArrayHasKey('matchedService', $r);
        $this->assertArrayHasKey('overridden', $r);
    }
}
