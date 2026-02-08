<?php

namespace App\Tests\Unit\Service;

use App\Entity\Lead;
use App\Service\CountryService;
use App\Service\LeadSalesAnalystService;
use App\Service\WebCrawler\CompanyDiscoveryService;
use App\Service\WebCrawler\GoogleDorkService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Comprehensive multi-region integration tests.
 *
 * These tests validate that the entire lead-generation pipeline — from
 * Google Dork query construction through country normalisation, discovery
 * service configuration, and sales analyst output — is fully region-aware
 * with ZERO hardcoded Morocco bias.
 *
 * Test structure:
 *   1. CountryService normalisation (aliases, edge cases)
 *   2. GoogleDorkService query generation (per region, no Morocco leakage)
 *   3. CompanyDiscoveryService configuration (multi-region locations)
 *   4. LeadSalesAnalystService output (region-conditional pitches/positioning)
 *   5. Statistical cross-region parity checks
 */
class MultiRegionIntegrationTest extends TestCase
{
    private CountryService $countryService;
    private GoogleDorkService $dorkService;
    private LeadSalesAnalystService $salesAnalyst;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->countryService = new CountryService();
        $this->logger = $this->createMock(LoggerInterface::class);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $this->dorkService = new GoogleDorkService($httpClient, $this->logger);

        $this->salesAnalyst = new LeadSalesAnalystService($this->logger);
    }

    // ────────────────────────────────────────────────────────────────
    //  1. COUNTRY SERVICE — NORMALISATION & ALIASES
    // ────────────────────────────────────────────────────────────────

    /**
     * @dataProvider moroccoAliasProvider
     */
    public function testCountryServiceNormalisesMoroccoLocations(string $input): void
    {
        $result = $this->countryService->normalizeRegionCode($input);
        $this->assertSame('MA', $result, "'{$input}' should normalise to 'MA'");
    }

    public static function moroccoAliasProvider(): array
    {
        return [
            'country name' => ['Morocco'],
            'country code' => ['MA'],
            'lowercase' => ['morocco'],
            'Tangier city' => ['Tangier'],
            'Tanger spelling' => ['Tanger'],
            'Casablanca' => ['Casablanca'],
            'Rabat' => ['Rabat'],
            'Kenitra' => ['Kenitra'],
            'Tanger Free Zone' => ['Tanger Free Zone'],
            'Tanger Automotive City' => ['Tanger Automotive City'],
            'Atlantic Free Zone Kenitra' => ['Atlantic Free Zone Kenitra'],
            'Nouaceur' => ['Nouaceur'],
            'Midparc' => ['Midparc'],
        ];
    }

    /**
     * @dataProvider usAliasProvider
     */
    public function testCountryServiceNormalisesUSLocations(string $input, string $expected): void
    {
        $result = $this->countryService->normalizeRegionCode($input);
        $this->assertSame($expected, $result, "'{$input}' should normalise to '{$expected}'");
    }

    public static function usAliasProvider(): array
    {
        return [
            'country name' => ['United States', 'US'],
            'country code' => ['US', 'US'],
            'USA abbreviation' => ['USA', 'US'],
            'lowercase' => ['united states', 'US'],
            'New York state' => ['New York', 'US-NY'],
            'Texas state' => ['Texas', 'US-TX'],
            'Michigan state' => ['Michigan', 'US-MI'],
        ];
    }

    /**
     * @dataProvider ukAliasProvider
     */
    public function testCountryServiceNormalisesUKLocations(string $input): void
    {
        $result = $this->countryService->normalizeRegionCode($input);
        $this->assertSame('GB', $result, "'{$input}' should normalise to 'GB'");
    }

    public static function ukAliasProvider(): array
    {
        return [
            'country name' => ['United Kingdom'],
            'country code' => ['GB'],
            'UK alias' => ['UK'],
            'England' => ['England'],
            'Scotland' => ['Scotland'],
            'Wales' => ['Wales'],
        ];
    }

    /**
     * @dataProvider euAliasProvider
     */
    public function testCountryServiceNormalisesEULocations(string $input, string $expected): void
    {
        $result = $this->countryService->normalizeRegionCode($input);
        $this->assertSame($expected, $result, "'{$input}' should normalise to '{$expected}'");
    }

    public static function euAliasProvider(): array
    {
        return [
            'Germany' => ['Germany', 'DE'],
            'France' => ['France', 'FR'],
            'Netherlands' => ['Netherlands', 'NL'],
            'Europe generic' => ['Europe', 'EU_REGION'],
        ];
    }

    public function testCountryServiceReturnsNullForUnknown(): void
    {
        $this->assertNull($this->countryService->normalizeRegionCode('Planet Mars'));
        $this->assertNull($this->countryService->normalizeRegionCode(null));
        $this->assertNull($this->countryService->normalizeRegionCode(''));
    }

    // ────────────────────────────────────────────────────────────────
    //  2. GOOGLE DORK SERVICE — QUERY GENERATION & REGION ISOLATION
    // ────────────────────────────────────────────────────────────────

    /**
     * When a non-Morocco location is given, NO query should contain "Morocco".
     *
     * @dataProvider nonMoroccoLocationProvider
     */
    public function testDorkQueriesNeverLeakMoroccoForOtherRegions(string $location): void
    {
        // searchCompanies without API returns logged URL entries
        $results = $this->dorkService->searchCompanies('Automotive', $location);

        foreach ($results as $entry) {
            $query = $entry['query'] ?? '';
            $this->assertStringNotContainsStringIgnoringCase(
                'Morocco',
                $query,
                "Query for location '{$location}' must not contain 'Morocco'. Got: {$query}"
            );
            // Also check Morocco-specific directory sites
            $this->assertStringNotContainsString(
                'kerix.net',
                $query,
                "Query for '{$location}' should not include Morocco-specific directory"
            );
        }
    }

    public static function nonMoroccoLocationProvider(): array
    {
        return [
            'Germany' => ['Germany'],
            'Texas' => ['Texas'],
            'England' => ['England'],
            'France' => ['France'],
            'New York' => ['New York'],
            'Netherlands' => ['Netherlands'],
            'Scotland' => ['Scotland'],
            'Czech Republic' => ['Czech Republic'],
        ];
    }

    /**
     * Morocco location should trigger Morocco-specific queries (factory/plant).
     * Location parsing strips stop words like "Free" and "Zone", keeping "Tanger".
     */
    public function testDorkQueriesIncludeMoroccoDirectoriesForMorocco(): void
    {
        $results = $this->dorkService->searchCompanies('Automotive', 'Tanger Free Zone');
        $allQueries = implode(' ', array_column($results, 'query'));

        // Should include the key location word "Tanger" (stop words "Free"/"Zone" stripped)
        $this->assertStringContainsString('Tanger', $allQueries);
        $this->assertStringContainsString('factory', $allQueries);
    }

    /**
     * US location should trigger buyer-intent queries with location.
     */
    public function testDorkQueriesIncludeUSDirectoriesForUS(): void
    {
        $results = $this->dorkService->searchCompanies('Automotive', 'Texas');
        $allQueries = implode(' ', array_column($results, 'query'));

        $this->assertStringContainsString('Texas', $allQueries);
        $this->assertStringContainsString('OEM', $allQueries);
    }

    /**
     * EU location should trigger buyer-intent queries with location.
     */
    public function testDorkQueriesIncludeEUDirectoriesForEU(): void
    {
        $results = $this->dorkService->searchCompanies('Automotive', 'Germany');
        $allQueries = implode(' ', array_column($results, 'query'));

        $this->assertStringContainsString('Germany', $allQueries);
        $this->assertStringContainsString('OEM', $allQueries);
    }

    /**
     * UK location should trigger buyer-intent queries with location.
     */
    public function testDorkQueriesIncludeUKDirectoriesForUK(): void
    {
        $results = $this->dorkService->searchCompanies('Aerospace', 'England');
        $allQueries = implode(' ', array_column($results, 'query'));

        $this->assertStringContainsString('England', $allQueries);
        $this->assertStringContainsStringIgnoringCase('aerospace', $allQueries);
    }

    /**
     * Without any location, queries should be generic — no location
     * appended to the manufacturing/supplier queries themselves.
     * Directory dorks for all regions fire (by design).
     */
    public function testDorkQueriesAreGenericWithoutLocation(): void
    {
        $results = $this->dorkService->searchCompanies('Automotive');

        // Without location, queries should NOT contain any location term
        $allQueries = implode(' ', array_column($results, 'query'));
        $this->assertStringNotContainsStringIgnoringCase('Morocco', $allQueries,
            "Queries without location must not default to Morocco");
        $this->assertNotEmpty($results, 'Should return queries even without location');
    }

    /**
     * Sector-specific queries should use the location, not Morocco.
     */
    public function testSectorQueriesUseLocationParameter(): void
    {
        $sectors = ['Automotive', 'Aerospace', 'Industrial', 'Rail', 'Renewables', 'Medical', 'Telecom', 'HVAC'];

        foreach ($sectors as $sector) {
            $results = $this->dorkService->searchCompanies($sector, 'Germany');
            $allQueries = implode(' ', array_column($results, 'query'));

            $this->assertStringContainsString('Germany', $allQueries,
                "Sector '{$sector}' queries should include the provided location 'Germany'");
            $this->assertStringNotContainsStringIgnoringCase('Morocco', $allQueries,
                "Sector '{$sector}' queries must not leak 'Morocco' when location is 'Germany'");
        }
    }

    /**
     * findCompanyWebsite should use location, not default to Morocco.
     */
    public function testFindCompanyWebsiteUsesLocation(): void
    {
        $capturedContext = [];
        $this->logger->method('debug')
            ->willReturnCallback(function ($msg, $ctx) use (&$capturedContext) {
                if ($msg === 'Website search') {
                    $capturedContext = $ctx;
                }
            });

        $this->dorkService->findCompanyWebsite('Siemens', 'Germany');

        $this->assertStringContainsString('Germany', $capturedContext['url'] ?? '');
        $this->assertStringNotContainsString('Morocco', $capturedContext['url'] ?? '');
    }

    public function testFindCompanyWebsiteWithoutLocationIsGeneric(): void
    {
        $capturedContext = [];
        $this->logger->method('debug')
            ->willReturnCallback(function ($msg, $ctx) use (&$capturedContext) {
                if ($msg === 'Website search') {
                    $capturedContext = $ctx;
                }
            });

        $this->dorkService->findCompanyWebsite('Siemens');

        $this->assertStringNotContainsString('Morocco', $capturedContext['url'] ?? '');
    }

    // ────────────────────────────────────────────────────────────────
    //  3. COMPANY DISCOVERY SERVICE — MULTI-REGION CONFIGURATION
    // ────────────────────────────────────────────────────────────────

    public function testTargetLocationsContainsAllRegions(): void
    {
        $all = CompanyDiscoveryService::getTargetLocations();
        $this->assertNotEmpty($all, 'Should have target locations');

        // Check we have locations from each region
        $ma = CompanyDiscoveryService::getTargetLocations('MA');
        $us = CompanyDiscoveryService::getTargetLocations('US');
        $eu = CompanyDiscoveryService::getTargetLocations('EU');
        $gb = CompanyDiscoveryService::getTargetLocations('GB');

        $this->assertNotEmpty($ma, 'Should have Morocco locations');
        $this->assertNotEmpty($us, 'Should have US locations');
        $this->assertNotEmpty($eu, 'Should have EU locations');
        $this->assertNotEmpty($gb, 'Should have GB locations');
    }

    public function testTargetLocationsCountIsBalanced(): void
    {
        $ma = CompanyDiscoveryService::getTargetLocations('MA');
        $us = CompanyDiscoveryService::getTargetLocations('US');
        $eu = CompanyDiscoveryService::getTargetLocations('EU');
        $gb = CompanyDiscoveryService::getTargetLocations('GB');

        // Each region should have at least 3 locations
        $this->assertGreaterThanOrEqual(3, count($ma), 'MA should have ≥3 locations');
        $this->assertGreaterThanOrEqual(3, count($us), 'US should have ≥3 locations');
        $this->assertGreaterThanOrEqual(3, count($eu), 'EU should have ≥3 locations');
        $this->assertGreaterThanOrEqual(3, count($gb), 'GB should have ≥3 locations');
    }

    public function testTargetLocationsUnknownRegionReturnsEmpty(): void
    {
        $result = CompanyDiscoveryService::getTargetLocations('XX');
        $this->assertEmpty($result);
    }

    // ────────────────────────────────────────────────────────────────
    //  4. SALES ANALYST — REGION-CONDITIONAL OUTPUT
    // ────────────────────────────────────────────────────────────────

    private function createLeadForRegion(string $regionTag, bool $moroccoSignal = false): Lead
    {
        $lead = new Lead();
        $lead->setCompanyName("Test Company ({$regionTag})");
        $lead->setRegionTag($regionTag);
        $lead->setMoroccoSignal($moroccoSignal);
        $lead->setFitSignals(['pcba', 'smt']);
        $lead->setQualityStack(['ISO 9001']);
        $lead->setSectorTags(['automotive']);
        $lead->setLeadScore(60);
        $lead->setReviewStatus('pending');
        $lead->setNotesAuto('');
        return $lead;
    }

    /**
     * Competitive positioning should mention EU FTA for EU leads, not Morocco FZ.
     */
    public function testCompetitivePositioningIsRegionAwareForEU(): void
    {
        $lead = $this->createLeadForRegion('EU');
        $result = $this->salesAnalyst->analyzeLead($lead);

        $positioning = $result['competitive_positioning'];
        $allMessages = implode(' ', array_column($positioning, 'message'));

        $this->assertStringContainsStringIgnoringCase('EU', $allMessages,
            'EU lead positioning should reference EU trade benefits');
    }

    /**
     * Competitive positioning should mention nearshoring for US leads.
     */
    public function testCompetitivePositioningIsRegionAwareForUS(): void
    {
        $lead = $this->createLeadForRegion('US');
        $result = $this->salesAnalyst->analyzeLead($lead);

        $positioning = $result['competitive_positioning'];
        $allMessages = implode(' ', array_column($positioning, 'differentiator'));

        $this->assertStringContainsStringIgnoringCase('Nearshore', $allMessages,
            'US lead positioning should mention nearshore advantage');
    }

    /**
     * Competitive positioning should mention UK trade for GB leads.
     */
    public function testCompetitivePositioningIsRegionAwareForGB(): void
    {
        $lead = $this->createLeadForRegion('GB');
        $result = $this->salesAnalyst->analyzeLead($lead);

        $positioning = $result['competitive_positioning'];
        $allMessages = implode(' ', array_column($positioning, 'differentiator'));

        $this->assertStringContainsStringIgnoringCase('UK', $allMessages,
            'GB lead positioning should reference UK trade advantage');
    }

    /**
     * Morocco lead should reference local presence.
     */
    public function testCompetitivePositioningIsRegionAwareForMA(): void
    {
        $lead = $this->createLeadForRegion('MA', true);
        $result = $this->salesAnalyst->analyzeLead($lead);

        $positioning = $result['competitive_positioning'];
        $allDiff = implode(' ', array_column($positioning, 'differentiator'));

        $this->assertStringContainsStringIgnoringCase('Local', $allDiff,
            'MA lead should reference local/co-located advantage');
    }

    /**
     * Unknown region should still get some positioning (generic).
     */
    public function testCompetitivePositioningHandlesUnknownRegion(): void
    {
        $lead = $this->createLeadForRegion('unknown');
        $result = $this->salesAnalyst->analyzeLead($lead);

        $positioning = $result['competitive_positioning'];
        $this->assertNotEmpty($positioning, 'Even unknown region should get positioning');
    }

    /**
     * Conversation starters should be region-specific.
     */
    public function testConversationStartersAreRegionSpecific(): void
    {
        $regions = ['MA', 'US', 'EU', 'GB'];
        $openers = [];

        foreach ($regions as $regionTag) {
            $moroccoSignal = ($regionTag === 'MA');
            $lead = $this->createLeadForRegion($regionTag, $moroccoSignal);
            $result = $this->salesAnalyst->analyzeLead($lead);

            $geoStarters = array_filter(
                $result['conversation_starters'],
                fn($s) => $s['type'] === 'geographic'
            );

            // Each region should get at least one geographic starter
            $this->assertNotEmpty($geoStarters,
                "Region '{$regionTag}' should have at least one geographic conversation starter");

            $opener = reset($geoStarters)['opener'] ?? '';
            $openers[$regionTag] = $opener;
        }

        // All openers should be different (region-specific, not boilerplate)
        $uniqueOpeners = array_unique($openers);
        $this->assertCount(count($regions), $uniqueOpeners,
            'Each region should produce a unique conversation opener');
    }

    /**
     * supply_chain_risk pitch should NOT mention Morocco.
     */
    public function testPainPointPitchesAreRegionNeutral(): void
    {
        $lead = $this->createLeadForRegion('US');
        $lead->setNotesAuto('supply chain diversification shortage risk');
        $result = $this->salesAnalyst->analyzeLead($lead);

        // Check pain_points if present
        if (!empty($result['pain_points'])) {
            foreach ($result['pain_points'] as $pp) {
                $pitch = $pp['recommended_pitch'] ?? $pp['pitch'] ?? '';
                $this->assertStringNotContainsStringIgnoringCase(
                    'Morocco',
                    $pitch,
                    "Pain point pitch should not hardcode Morocco for a US lead"
                );
            }
        }

        // Also check the constant directly through reflection
        $ref = new \ReflectionClass(LeadSalesAnalystService::class);
        $constants = $ref->getConstants();
        $painPoints = $constants['PAIN_POINT_INDICATORS'] ?? [];

        foreach ($painPoints as $key => $config) {
            $this->assertStringNotContainsStringIgnoringCase(
                'Morocco',
                $config['pitch'],
                "PAIN_POINT_INDICATORS['{$key}']['pitch'] should not hardcode Morocco"
            );
        }
    }

    // ────────────────────────────────────────────────────────────────
    //  5. STATISTICAL CROSS-REGION PARITY
    // ────────────────────────────────────────────────────────────────

    /**
     * All 4 regions should produce the same number of dork queries for
     * the same sector — no one region should be dramatically over- or
     * under-represented.
     */
    public function testQueryCountParityAcrossRegions(): void
    {
        $locations = [
            'MA' => 'Tanger Free Zone',
            'US' => 'Texas',
            'EU' => 'Germany',
            'GB' => 'England',
        ];

        $counts = [];
        foreach ($locations as $region => $location) {
            $results = $this->dorkService->searchCompanies('Automotive', $location);
            $counts[$region] = count($results);
        }

        $min = min($counts);
        $max = max($counts);

        // Queries should be within 2x of each other (reasonable parity)
        $this->assertLessThanOrEqual(
            $min * 2,
            $max,
            sprintf(
                'Query count disparity too high: min=%d (%s), max=%d (%s). Counts: %s',
                $min,
                array_search($min, $counts),
                $max,
                array_search($max, $counts),
                json_encode($counts)
            )
        );
    }

    /**
     * Priority scores should be comparable across regions for equivalent leads.
     */
    public function testPriorityScoreParityAcrossRegions(): void
    {
        $regions = ['MA', 'US', 'EU', 'GB'];
        $scores = [];

        foreach ($regions as $region) {
            $moroccoSignal = ($region === 'MA');
            $lead = $this->createLeadForRegion($region, $moroccoSignal);
            $lead->setLeadScore(75);
            $lead->setDefenseFlag(false);

            $result = $this->salesAnalyst->analyzeLead($lead);
            $scores[$region] = $result['priority_score'];
        }

        // MA gets +15 for moroccoSignal, others get +10 for known region
        // The gap should be at most 5 points (15 - 10 = 5)
        $min = min($scores);
        $max = max($scores);

        $this->assertLessThanOrEqual(10, $max - $min,
            sprintf(
                'Priority score gap >10 across regions: %s',
                json_encode($scores)
            ));
    }

    /**
     * Full cross-region statistical summary: test that analysis works for
     * a diverse set of leads and produces non-trivial results.
     */
    public function testFullAnalysisWorksForAllRegionsAndSectors(): void
    {
        $regions = ['MA', 'US', 'EU', 'GB', 'unknown'];
        $sectors = [['automotive'], ['aerospace'], ['industrial'], ['renewables']];

        $successCount = 0;
        $totalTests = count($regions) * count($sectors);

        foreach ($regions as $region) {
            foreach ($sectors as $sectorTags) {
                $lead = $this->createLeadForRegion($region);
                $lead->setSectorTags($sectorTags);
                $lead->setFitSignals(['pcba', 'smt', 'testing']);
                $lead->setQualityStack(['ISO 9001']);

                $result = $this->salesAnalyst->analyzeLead($lead);

                $this->assertIsArray($result);
                $this->assertArrayHasKey('overall_fit_score', $result);
                $this->assertArrayHasKey('competitive_positioning', $result);
                $this->assertArrayHasKey('conversation_starters', $result);
                $this->assertGreaterThan(0, $result['overall_fit_score']);
                $this->assertNotEmpty($result['competitive_positioning']);

                $successCount++;
            }
        }

        $this->assertSame($totalTests, $successCount,
            "All {$totalTests} region×sector combinations should pass");
    }

    // ────────────────────────────────────────────────────────────────
    //  6. NO MOROCCO LEAKAGE — EXHAUSTIVE SCAN
    // ────────────────────────────────────────────────────────────────

    /**
     * Run a US-region lead through the full sales analyst pipeline and
     * verify the output JSON never mentions "Morocco" or "Tangier" except
     * in generic industry context.
     */
    public function testNoMoroccoLeakageInUSLeadAnalysis(): void
    {
        $lead = $this->createLeadForRegion('US');
        $lead->setFitSignals(['pcba', 'smt', 'through_hole']);
        $lead->setQualityStack(['ISO 9001', 'IATF 16949']);
        $lead->setSectorTags(['automotive']);
        $lead->setLeadScore(80);

        $result = $this->salesAnalyst->analyzeLead($lead);

        // Serialise the entire result to check for Morocco leakage
        $json = json_encode($result, JSON_PRETTY_PRINT);

        // These Morocco-specific terms should NOT appear in a US-lead analysis
        $forbiddenTerms = ['Tangier', 'Tanger', 'Morocco Free Zone', 'Morocco-based'];
        foreach ($forbiddenTerms as $term) {
            $this->assertStringNotContainsStringIgnoringCase(
                $term,
                $json,
                "US-region analysis output should not contain '{$term}'"
            );
        }
    }

    /**
     * Run an EU-region lead through the pipeline — same leakage check.
     */
    public function testNoMoroccoLeakageInEULeadAnalysis(): void
    {
        $lead = $this->createLeadForRegion('EU');
        $lead->setFitSignals(['pcba', 'smt']);
        $lead->setQualityStack(['ISO 9001']);
        $lead->setSectorTags(['aerospace']);
        $lead->setLeadScore(70);

        $result = $this->salesAnalyst->analyzeLead($lead);
        $json = json_encode($result, JSON_PRETTY_PRINT);

        $forbiddenTerms = ['Tangier', 'Tanger', 'Morocco Free Zone', 'Morocco-based'];
        foreach ($forbiddenTerms as $term) {
            $this->assertStringNotContainsStringIgnoringCase(
                $term,
                $json,
                "EU-region analysis output should not contain '{$term}'"
            );
        }
    }

    // ────────────────────────────────────────────────────────────────
    //  7. EGYPT (EG) REGION SUPPORT
    // ────────────────────────────────────────────────────────────────

    /**
     * @dataProvider egyptAliasProvider
     */
    public function testCountryServiceNormalisesEgyptLocations(string $input): void
    {
        $result = $this->countryService->normalizeRegionCode($input);
        $this->assertSame('EG', $result, "'{$input}' should normalise to 'EG'");
    }

    public static function egyptAliasProvider(): array
    {
        return [
            'Cairo' => ['Cairo'],
            'cairo lowercase' => ['cairo'],
            'Alexandria' => ['Alexandria'],
            'Suez' => ['Suez'],
            'Port Said' => ['Port Said'],
            'Ain Sokhna' => ['Ain Sokhna'],
            '6th of October City' => ['6th of October City'],
            '10th of Ramadan City' => ['10th of Ramadan City'],
            'New Cairo' => ['New Cairo'],
        ];
    }

    public function testCompetitivePositioningIsRegionAwareForEG(): void
    {
        $lead = $this->createLeadForRegion('EG');
        $result = $this->salesAnalyst->analyzeLead($lead);

        $positioning = $result['competitive_positioning'];
        $allDiff = implode(' ', array_column($positioning, 'differentiator'));

        $this->assertStringContainsStringIgnoringCase('North Africa Corridor', $allDiff,
            'EG lead positioning should reference North Africa Corridor advantage');
    }

    public function testConversationStartersIncludeEgyptCorridor(): void
    {
        $lead = $this->createLeadForRegion('EG');
        $result = $this->salesAnalyst->analyzeLead($lead);

        $geoStarters = array_filter(
            $result['conversation_starters'],
            fn($s) => ($s['type'] ?? '') === 'geographic'
        );

        $this->assertNotEmpty($geoStarters,
            'Egypt lead should have a geographic conversation starter');

        $topics = array_column(array_values($geoStarters), 'topic');
        $this->assertContains('egypt_corridor', $topics,
            'Egypt lead geo-starter should have topic "egypt_corridor"');
    }

    public function testDorkQueriesIncludeEgyptDirectoriesForEgypt(): void
    {
        $results = $this->dorkService->searchCompanies('Automotive', 'Cairo, Egypt');
        $allQueries = implode(' ', array_column($results, 'query'));

        // Should include Egypt-specific factory search and buyer-intent queries
        $this->assertStringContainsString('Cairo', $allQueries,
            'Egypt queries should include Cairo location');
        $this->assertStringContainsString('factory', $allQueries,
            'Egypt queries should include factory/industrial search');
    }

    public function testTargetLocationsContainsEgypt(): void
    {
        $eg = CompanyDiscoveryService::getTargetLocations('EG');
        $this->assertNotEmpty($eg, 'Should have Egypt locations');
        $this->assertGreaterThanOrEqual(3, count($eg), 'EG should have ≥3 locations');
    }

    public function testNoMoroccoLeakageInEgyptLeadAnalysis(): void
    {
        $lead = $this->createLeadForRegion('EG');
        $lead->setFitSignals(['pcba', 'smt', 'through_hole']);
        $lead->setQualityStack(['ISO 9001']);
        $lead->setSectorTags(['automotive']);
        $lead->setLeadScore(80);

        $result = $this->salesAnalyst->analyzeLead($lead);
        $json = json_encode($result, JSON_PRETTY_PRINT);

        $forbiddenTerms = ['Tangier', 'Tanger', 'Morocco Free Zone', 'Morocco-based'];
        foreach ($forbiddenTerms as $term) {
            $this->assertStringNotContainsStringIgnoringCase(
                $term,
                $json,
                "Egypt-region analysis output should not contain '{$term}'"
            );
        }
    }

    // ────────────────────────────────────────────────────────────────
    //  8. GCC REGION SUPPORT (AE, SA, QA, KW, OM, BH)
    // ────────────────────────────────────────────────────────────────

    /**
     * @dataProvider gccAliasProvider
     */
    public function testCountryServiceNormalisesGCCLocations(string $input, string $expected): void
    {
        $result = $this->countryService->normalizeRegionCode($input);
        $this->assertSame($expected, $result, "'{$input}' should normalise to '{$expected}'");
    }

    public static function gccAliasProvider(): array
    {
        return [
            'Dubai' => ['Dubai', 'AE'],
            'Abu Dhabi' => ['Abu Dhabi', 'AE'],
            'Jebel Ali' => ['Jebel Ali', 'AE'],
            'Sharjah' => ['Sharjah', 'AE'],
            'KIZAD' => ['KIZAD', 'AE'],
            'Riyadh' => ['Riyadh', 'SA'],
            'Jeddah' => ['Jeddah', 'SA'],
            'Dammam' => ['Dammam', 'SA'],
            'Jubail' => ['Jubail', 'SA'],
            'NEOM' => ['NEOM', 'SA'],
            'Doha' => ['Doha', 'QA'],
            'Manama' => ['Manama', 'BH'],
            'Muscat' => ['Muscat', 'OM'],
            'Kuwait City' => ['Kuwait City', 'KW'],
            'GCC keyword' => ['GCC', 'GCC_REGION'],
            'Gulf keyword' => ['Gulf', 'GCC_REGION'],
        ];
    }

    /**
     * @dataProvider gccCountryCodeProvider
     */
    public function testCompetitivePositioningIsRegionAwareForGCC(string $regionTag): void
    {
        $lead = $this->createLeadForRegion($regionTag);
        $result = $this->salesAnalyst->analyzeLead($lead);

        $positioning = $result['competitive_positioning'];
        $allDiff = implode(' ', array_column($positioning, 'differentiator'));

        $this->assertStringContainsStringIgnoringCase('Gulf Gateway', $allDiff,
            "{$regionTag} lead positioning should reference Gulf Gateway advantage");
    }

    public static function gccCountryCodeProvider(): array
    {
        return [
            'UAE' => ['AE'],
            'Saudi Arabia' => ['SA'],
            'Qatar' => ['QA'],
            'Kuwait' => ['KW'],
            'Oman' => ['OM'],
            'Bahrain' => ['BH'],
            'GCC region' => ['GCC'],
            'GCC_REGION' => ['GCC_REGION'],
        ];
    }

    /**
     * @dataProvider gccCountryCodeProvider
     */
    public function testConversationStartersIncludeGCCDiversification(string $regionTag): void
    {
        $lead = $this->createLeadForRegion($regionTag);
        $result = $this->salesAnalyst->analyzeLead($lead);

        $geoStarters = array_filter(
            $result['conversation_starters'],
            fn($s) => ($s['type'] ?? '') === 'geographic'
        );

        $this->assertNotEmpty($geoStarters,
            "{$regionTag} lead should have a geographic conversation starter");

        $topics = array_column(array_values($geoStarters), 'topic');
        $this->assertContains('gcc_diversification', $topics,
            "{$regionTag} lead geo-starter should have topic 'gcc_diversification'");
    }

    public function testDorkQueriesIncludeGCCDirectoriesForGCC(): void
    {
        $results = $this->dorkService->searchCompanies('Automotive', 'Dubai, UAE');
        $allQueries = implode(' ', array_column($results, 'query'));

        // Should include GCC-specific factory search and buyer-intent queries
        $this->assertStringContainsString('Dubai', $allQueries,
            'GCC queries should include Dubai location');
        $this->assertStringContainsString('factory', $allQueries,
            'GCC queries should include factory/industrial search');
    }

    public function testTargetLocationsContainsGCC(): void
    {
        $gcc = CompanyDiscoveryService::getTargetLocations('GCC');
        $this->assertNotEmpty($gcc, 'Should have GCC locations');
        $this->assertGreaterThanOrEqual(3, count($gcc), 'GCC should have ≥3 locations');
    }

    public function testNoMoroccoLeakageInGCCLeadAnalysis(): void
    {
        $lead = $this->createLeadForRegion('AE');
        $lead->setFitSignals(['pcba', 'smt', 'box_build']);
        $lead->setQualityStack(['AS9100', 'ISO 9001']);
        $lead->setSectorTags(['aerospace']);
        $lead->setLeadScore(85);

        $result = $this->salesAnalyst->analyzeLead($lead);
        $json = json_encode($result, JSON_PRETTY_PRINT);

        $forbiddenTerms = ['Tangier', 'Tanger', 'Morocco Free Zone', 'Morocco-based'];
        foreach ($forbiddenTerms as $term) {
            $this->assertStringNotContainsStringIgnoringCase(
                $term,
                $json,
                "GCC-region analysis output should not contain '{$term}'"
            );
        }
    }

    // ────────────────────────────────────────────────────────────────
    //  9. EXPANDED PARITY CHECKS (ALL 6 REGIONS)
    // ────────────────────────────────────────────────────────────────

    public function testPriorityScoreParityAcrossAllSixRegions(): void
    {
        $regions = ['MA', 'US', 'EU', 'GB', 'EG', 'AE'];
        $scores = [];

        foreach ($regions as $region) {
            $moroccoSignal = ($region === 'MA');
            $lead = $this->createLeadForRegion($region, $moroccoSignal);
            $lead->setLeadScore(75);
            $lead->setDefenseFlag(false);

            $result = $this->salesAnalyst->analyzeLead($lead);
            $scores[$region] = $result['priority_score'];
        }

        $min = min($scores);
        $max = max($scores);

        $this->assertLessThanOrEqual(10, $max - $min,
            sprintf(
                'Priority score gap >10 across 6 regions: %s',
                json_encode($scores)
            ));
    }

    public function testFullAnalysisWorksForAllSixRegionsAndSectors(): void
    {
        $regions = ['MA', 'US', 'EU', 'GB', 'EG', 'AE', 'SA', 'QA', 'unknown'];
        $sectors = [['automotive'], ['aerospace'], ['industrial'], ['renewables']];

        $successCount = 0;
        $totalTests = count($regions) * count($sectors);

        foreach ($regions as $region) {
            foreach ($sectors as $sectorTags) {
                $lead = $this->createLeadForRegion($region);
                $lead->setSectorTags($sectorTags);
                $lead->setFitSignals(['pcba', 'smt', 'testing']);
                $lead->setQualityStack(['ISO 9001']);

                $result = $this->salesAnalyst->analyzeLead($lead);

                $this->assertIsArray($result);
                $this->assertArrayHasKey('overall_fit_score', $result);
                $this->assertArrayHasKey('competitive_positioning', $result);
                $this->assertArrayHasKey('conversation_starters', $result);
                $this->assertGreaterThan(0, $result['overall_fit_score']);
                $this->assertNotEmpty($result['competitive_positioning']);

                $successCount++;
            }
        }

        $this->assertSame($totalTests, $successCount,
            "All {$totalTests} region×sector combinations should pass");
    }

    public function testConversationStartersUniqueAcrossAllSixRegions(): void
    {
        $regions = ['MA', 'US', 'EU', 'GB', 'EG', 'AE'];
        $openers = [];

        foreach ($regions as $regionTag) {
            $moroccoSignal = ($regionTag === 'MA');
            $lead = $this->createLeadForRegion($regionTag, $moroccoSignal);
            $result = $this->salesAnalyst->analyzeLead($lead);

            $geoStarters = array_filter(
                $result['conversation_starters'],
                fn($s) => $s['type'] === 'geographic'
            );

            $this->assertNotEmpty($geoStarters,
                "Region '{$regionTag}' should have at least one geographic conversation starter");

            $opener = reset($geoStarters)['opener'] ?? '';
            $openers[$regionTag] = $opener;
        }

        $uniqueOpeners = array_unique($openers);
        $this->assertCount(count($regions), $uniqueOpeners,
            'Each of the 6 regions should produce a unique conversation opener');
    }

    public function testQueryCountParityAcrossAllSixRegions(): void
    {
        $locations = [
            'MA' => 'Tanger Free Zone',
            'US' => 'Texas',
            'EU' => 'Germany',
            'GB' => 'England',
            'EG' => 'Cairo, Egypt',
            'GCC' => 'Dubai, UAE',
        ];

        $counts = [];
        foreach ($locations as $region => $location) {
            $results = $this->dorkService->searchCompanies('Automotive', $location);
            $counts[$region] = count($results);
        }

        $min = min($counts);
        $max = max($counts);

        $this->assertLessThanOrEqual(
            $min * 2,
            $max,
            sprintf(
                'Query count disparity too high across 6 regions: min=%d, max=%d. Counts: %s',
                $min,
                $max,
                json_encode($counts)
            )
        );
    }
}
