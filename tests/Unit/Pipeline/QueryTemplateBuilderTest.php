<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pipeline;

use App\Service\WebCrawler\Pipeline\QueryTemplateBuilder;
use PHPUnit\Framework\TestCase;

class QueryTemplateBuilderTest extends TestCase
{
    private QueryTemplateBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new QueryTemplateBuilder();
    }

    // ── Budget cap ──────────────────────────────────────────────

    public function testQueryCountNeverExceedsBudgetCap(): void
    {
        $queries = $this->builder->buildQueries('Automotive', 'Germany');
        $this->assertLessThanOrEqual(40, count($queries), 'Query budget must be ≤40');
        $this->assertGreaterThan(0, count($queries), 'Must produce at least 1 query');
    }

    public function testQueryCountCappedForBroadSector(): void
    {
        $queries = $this->builder->buildQueries('Industrial', 'France');
        $this->assertLessThanOrEqual(40, count($queries));
    }

    // ── Sector fallback ─────────────────────────────────────────

    public function testUnknownSectorFallsBackToGeneralManufacturing(): void
    {
        $queries = $this->builder->buildQueries('UnknownSector12345', 'Egypt');
        $this->assertGreaterThan(0, count($queries), 'Unknown sector must still produce queries');

        $types = array_column($queries, 'type');
        $this->assertContains('general', $types, 'Unknown sector must include general manufacturing templates');
    }

    public function testNullSectorProducesGeneralQueries(): void
    {
        $queries = $this->builder->buildQueries(null, 'Morocco');
        $this->assertGreaterThan(0, count($queries));

        $types = array_column($queries, 'type');
        $this->assertContains('general', $types);
    }

    public function testNullLocationProducesQueries(): void
    {
        $queries = $this->builder->buildQueries('Automotive', null);
        $this->assertGreaterThan(0, count($queries));
    }

    // ── Query structure ─────────────────────────────────────────

    public function testEachQueryHasRequiredMetadata(): void
    {
        $queries = $this->builder->buildQueries('Aerospace', 'Morocco');

        foreach ($queries as $i => $query) {
            $this->assertArrayHasKey('query', $query, "Query #{$i} must have 'query' key");
            $this->assertArrayHasKey('type', $query, "Query #{$i} must have 'type' key");
            $this->assertIsString($query['query'], "Query #{$i} 'query' must be string");
            $this->assertNotEmpty(trim($query['query']), "Query #{$i} 'query' must not be empty");
            $this->assertContains(
                $query['type'],
                ['sector_specific', 'directory', 'industrial_zone', 'general', 'certification'],
                "Query #{$i} type '{$query['type']}' is not a valid type",
            );
        }
    }

    // ── No duplicates ───────────────────────────────────────────

    public function testNoDuplicateQueries(): void
    {
        $queries = $this->builder->buildQueries('Automotive', 'Germany');
        $queryStrings = array_map(fn(array $q) => $q['query'], $queries);
        $unique = array_unique($queryStrings);

        $this->assertCount(
            count($unique),
            $queryStrings,
            'Duplicate queries found: ' . implode(', ', array_diff_key($queryStrings, $unique)),
        );
    }

    public function testNoDuplicatesAcrossDifferentSectors(): void
    {
        // Each sector should produce its own unique set
        $auto = $this->builder->buildQueries('Automotive', 'Germany');
        $aero = $this->builder->buildQueries('Aerospace', 'Germany');

        $autoStrings = array_map(fn(array $q) => $q['query'], $auto);
        $aeroStrings = array_map(fn(array $q) => $q['query'], $aero);

        // Sector-specific queries should differ
        $autoSector = array_filter($auto, fn($q) => $q['type'] === 'sector_specific');
        $aeroSector = array_filter($aero, fn($q) => $q['type'] === 'sector_specific');

        if (!empty($autoSector) && !empty($aeroSector)) {
            $autoSectorStrings = array_map(fn($q) => $q['query'], $autoSector);
            $aeroSectorStrings = array_map(fn($q) => $q['query'], $aeroSector);
            $overlap = array_intersect($autoSectorStrings, $aeroSectorStrings);
            $this->assertEmpty($overlap, 'Sector-specific queries should not overlap between sectors');
        }
    }

    // ── Sector-specific content ─────────────────────────────────

    public function testAutomotiveQueriesContainSectorTerms(): void
    {
        $queries = $this->builder->buildQueries('Automotive', 'Germany');
        $allText = implode(' ', array_column($queries, 'query'));
        $lower = mb_strtolower($allText);

        $this->assertTrue(
            str_contains($lower, 'automotive') || str_contains($lower, 'vehicle') || str_contains($lower, 'iatf'),
            'Automotive queries should contain automotive-related terms',
        );
    }

    public function testAerospaceQueriesContainSectorTerms(): void
    {
        $queries = $this->builder->buildQueries('Aerospace', 'France');
        $allText = implode(' ', array_column($queries, 'query'));
        $lower = mb_strtolower($allText);

        $this->assertTrue(
            str_contains($lower, 'aerospace') || str_contains($lower, 'aviation') || str_contains($lower, 'as9100'),
            'Aerospace queries should contain aerospace-related terms',
        );
    }

    public function testMedicalQueriesContainSectorTerms(): void
    {
        $queries = $this->builder->buildQueries('Medical', 'Germany');
        $allText = implode(' ', array_column($queries, 'query'));
        $lower = mb_strtolower($allText);

        $this->assertTrue(
            str_contains($lower, 'medical') || str_contains($lower, 'iso 13485') || str_contains($lower, 'medtech'),
            'Medical queries should contain medical-related terms',
        );
    }

    // ── Location appears in queries ─────────────────────────────

    public function testLocationAppearsInQueries(): void
    {
        $queries = $this->builder->buildQueries('Automotive', 'Morocco');
        $allText = mb_strtolower(implode(' ', array_column($queries, 'query')));

        $this->assertTrue(
            str_contains($allText, 'morocco') || str_contains($allText, 'maroc'),
            'Location should appear in at least some queries',
        );
    }

    // ── Query type distribution ─────────────────────────────────

    public function testMixOfQueryTypes(): void
    {
        $queries = $this->builder->buildQueries('Automotive', 'Germany');
        $types = array_unique(array_column($queries, 'type'));

        $this->assertGreaterThanOrEqual(2, count($types), 'Should produce at least 2 different query types');
    }

    // ── Known sectors recognized ────────────────────────────────

    /**
     * @dataProvider knownSectorProvider
     */
    public function testKnownSectorsProduceSectorSpecificQueries(string $sector): void
    {
        $queries = $this->builder->buildQueries($sector, 'Germany');
        $types = array_column($queries, 'type');

        $this->assertContains(
            'sector_specific',
            $types,
            "Known sector '{$sector}' should produce sector_specific queries",
        );
    }

    public static function knownSectorProvider(): array
    {
        return [
            'Automotive' => ['Automotive'],
            'Aerospace' => ['Aerospace'],
            'Medical' => ['Medical'],
            'Industrial' => ['Industrial'],
            'Telecommunications' => ['Telecommunications'],
            'Renewables' => ['Renewables'],
            'Defense' => ['Defense'],
            'Electronics' => ['Electronics'],
        ];
    }

    // ── Certification queries include location ──────────────────

    public function testCertificationQueriesIncludeLocationWhenProvided(): void
    {
        $queries = $this->builder->buildQueries('Automotive', 'Morocco');
        $certQueries = array_filter($queries, fn(array $q) => $q['type'] === 'certification');

        $this->assertNotEmpty($certQueries, 'Should have at least one certification query');

        foreach ($certQueries as $i => $q) {
            $this->assertStringContainsString(
                'Morocco',
                $q['query'],
                "Certification query #{$i} must include location: {$q['query']}",
            );
        }
    }
}
