<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

/**
 * Generates a controlled, budget-capped set of search queries from sector + location.
 *
 * Replaces the sprawling buildGoogleDorkQueries() method (1,100+ lines)
 * with a small, predictable template system. Hard budget: ≤40 queries per run.
 *
 * Each query carries type metadata for downstream observability:
 *   - sector_specific: tailored to the target industry vertical
 *   - directory: targets industrial directories and supplier portals
 *   - industrial_zone: targets known manufacturing zones/clusters
 *   - general: broad manufacturing discovery
 *   - certification: finds companies by quality certifications
 */
final class QueryTemplateBuilder
{
    private const MAX_QUERIES = 40;

    /**
     * Sector keyword families. Each sector maps to an array of keyword groups
     * used to build targeted search queries.
     */
    private const SECTOR_KEYWORDS = [
        'Automotive' => [
            'terms'  => ['automotive', 'vehicle', 'car parts', 'tier 1 supplier', 'tier 2 supplier', 'OEM automotive'],
            'certs'  => ['IATF 16949'],
            'extras' => ['powertrain', 'chassis', 'body electronics', 'ADAS', 'EV components'],
        ],
        'Aerospace' => [
            'terms'  => ['aerospace', 'aviation', 'aircraft', 'aerostructures', 'avionics'],
            'certs'  => ['AS9100', 'NADCAP'],
            'extras' => ['defense systems', 'satellite', 'UAV', 'flight control', 'landing gear'],
        ],
        'Medical' => [
            'terms'  => ['medical device', 'medtech', 'medical equipment', 'surgical instruments', 'diagnostic equipment'],
            'certs'  => ['ISO 13485', 'FDA 21 CFR', 'CE marking medical'],
            'extras' => ['implantable', 'patient monitoring', 'infusion pump', 'imaging systems'],
        ],
        'Industrial' => [
            'terms'  => ['industrial equipment', 'industrial automation', 'machinery', 'process control', 'instrumentation'],
            'certs'  => ['ISO 9001'],
            'extras' => ['PLC', 'sensors', 'actuators', 'power supplies', 'motor drive'],
        ],
        'Rail' => [
            'terms'  => ['rail systems', 'railway electronics', 'rolling stock', 'train control systems', 'rail transport equipment'],
            'certs'  => ['IRIS Certification', 'ISO 9001'],
            'extras' => ['signalling', 'traction systems', 'braking systems', 'passenger information systems', 'onboard electronics'],
        ],
        'Telecommunications' => [
            'terms'  => ['telecommunications', 'telecom equipment', 'network equipment', '5G', 'fiber optics'],
            'certs'  => ['TL 9000'],
            'extras' => ['base station', 'antenna', 'RF equipment', 'optical transceiver'],
        ],
        'Renewables' => [
            'terms'  => ['renewable energy', 'solar', 'wind energy', 'energy storage', 'power electronics'],
            'certs'  => ['IEC 62443'],
            'extras' => ['inverter', 'BMS', 'charge controller', 'wind turbine', 'photovoltaic'],
        ],
        'Energy Storage' => [
            'terms'  => ['energy storage', 'battery systems', 'battery pack manufacturer', 'battery management system', 'grid storage'],
            'certs'  => ['IEC 62619', 'ISO 9001'],
            'extras' => ['battery module', 'BMS', 'power conversion', 'inverter', 'charge controller'],
        ],
        'Defense' => [
            'terms'  => ['defense', 'military electronics', 'tactical systems', 'radar systems', 'C4ISR'],
            'certs'  => ['AS9100', 'ITAR'],
            'extras' => ['electronic warfare', 'night vision', 'naval systems', 'armored vehicle electronics'],
        ],
        'Marine' => [
            'terms'  => ['marine electronics', 'maritime systems', 'shipbuilding equipment', 'naval equipment', 'offshore systems'],
            'certs'  => ['ISO 9001'],
            'extras' => ['navigation systems', 'communication systems', 'power distribution', 'engine control', 'deck equipment'],
        ],
        'HVAC' => [
            'terms'  => ['hvac equipment', 'climate control systems', 'heating ventilation air conditioning', 'air handling unit', 'heat pump manufacturer'],
            'certs'  => ['ISO 9001'],
            'extras' => ['compressor', 'control board', 'blower motor', 'thermostat', 'building automation'],
        ],
        'Data Center' => [
            'terms'  => ['data center equipment', 'server infrastructure', 'power distribution unit', 'data center cooling', 'rack systems'],
            'certs'  => ['ISO 9001'],
            'extras' => ['server power supply', 'rack monitoring', 'cooling controls', 'UPS', 'network switch'],
        ],
        'Power Electronics' => [
            'terms'  => ['power electronics', 'power conversion equipment', 'motor drives', 'industrial inverter', 'rectifier manufacturer'],
            'certs'  => ['ISO 9001'],
            'extras' => ['dc-dc converter', 'ac-dc power supply', 'variable frequency drive', 'battery charger', 'power module'],
        ],
        'Consumer Electronics' => [
            'terms'  => ['consumer electronics manufacturer', 'smart devices', 'home electronics', 'appliance electronics', 'embedded consumer devices'],
            'certs'  => ['ISO 9001'],
            'extras' => ['display module', 'control board', 'wireless module', 'power adapter', 'home appliance'],
        ],
        'Electronics' => [
            'terms'  => ['electronics manufacturer', 'PCB assembly', 'electronic components', 'power electronics', 'embedded systems'],
            'certs'  => ['IPC-A-610', 'J-STD-001'],
            'extras' => ['connector', 'cable assembly', 'wire harness', 'box build', 'test equipment'],
        ],
    ];

    /**
     * Location-specific alternative names and languages.
     * Helps queries reach local-language results.
     */
    private const LOCATION_ALTERNATES = [
        'Morocco'   => ['Maroc', 'المغرب'],
        'Egypt'     => ['Egypte', 'مصر'],
        'Tunisia'   => ['Tunisie', 'تونس'],
        'France'    => ['France'],
        'Germany'   => ['Deutschland'],
        'Italy'     => ['Italia'],
        'Spain'     => ['España'],
        'Turkey'    => ['Türkiye'],
        'UK'        => ['United Kingdom', 'Britain'],
        'USA'       => ['United States'],
        'Netherlands' => ['Nederland'],
        'Belgium'   => ['Belgique', 'België'],
        'Czech Republic' => ['Česko'],
        'Poland'    => ['Polska'],
        'Romania'   => ['România'],
        'Hungary'   => ['Magyarország'],
    ];

    /**
     * Build a controlled set of search queries for a sector + location.
     *
     * @return array<int, array{query: string, type: string}>
     */
    public function buildQueries(?string $sector, ?string $location): array
    {
        $queries = [];

        // 1. Sector-specific queries
        $sectorConfig = $this->resolveSector($sector);
        if ($sectorConfig !== null) {
            $queries = array_merge($queries, $this->buildSectorQueries($sectorConfig, $location));
        }

        // 2. Certification queries
        $queries = array_merge($queries, $this->buildCertificationQueries($sectorConfig, $location));

        // 3. Directory / supplier portal queries
        $queries = array_merge($queries, $this->buildDirectoryQueries($sector, $location));

        // 4. Industrial zone queries (location-specific)
        if ($location !== null) {
            $queries = array_merge($queries, $this->buildIndustrialZoneQueries($location));
        }

        // 5. General manufacturing queries (always included as fallback)
        $queries = array_merge($queries, $this->buildGeneralQueries($location));

        // Deduplicate and cap
        $queries = $this->deduplicateQueries($queries);
        $queries = array_slice($queries, 0, self::MAX_QUERIES);

        return $queries;
    }

    /**
     * Resolve a sector string to its configuration, or null if unrecognized.
     *
     * @return array{terms: string[], certs: string[], extras: string[]}|null
     */
    private function resolveSector(?string $sector): ?array
    {
        if ($sector === null) {
            return null;
        }

        // Direct match
        foreach (self::SECTOR_KEYWORDS as $name => $config) {
            if (strcasecmp($name, $sector) === 0) {
                return $config;
            }
        }

        // Partial match (e.g., "Auto" matches "Automotive")
        $sectorLower = mb_strtolower($sector);
        foreach (self::SECTOR_KEYWORDS as $name => $config) {
            if (str_contains(mb_strtolower($name), $sectorLower) || str_contains($sectorLower, mb_strtolower($name))) {
                return $config;
            }
        }

        return null;
    }

    /**
     * @param array{terms: string[], certs: string[], extras: string[]} $sectorConfig
     * @return array<int, array{query: string, type: string}>
     */
    private function buildSectorQueries(array $sectorConfig, ?string $location): array
    {
        $queries = [];
        $locationClause = $location !== null ? ' ' . $location : '';

        // Core sector term + manufacturer intent
        foreach ($sectorConfig['terms'] as $term) {
            $queries[] = [
                'query' => "\"{$term}\" manufacturer{$locationClause}",
                'type'  => 'sector_specific',
            ];
        }

        // Sector extras (capabilities, subsystems) — pick top 3 to stay within budget
        $extras = array_slice($sectorConfig['extras'], 0, 3);
        foreach ($extras as $extra) {
            $queries[] = [
                'query' => "\"{$extra}\" manufacturer production{$locationClause}",
                'type'  => 'sector_specific',
            ];
        }

        // Location alternates for sector queries (pick first 2 sector terms)
        if ($location !== null) {
            $alts = $this->resolveLocationAlternates($location);
            $sectorTerms = array_slice($sectorConfig['terms'], 0, 2);
            foreach ($alts as $alt) {
                foreach ($sectorTerms as $term) {
                    $queries[] = [
                        'query' => "\"{$term}\" manufacturer {$alt}",
                        'type'  => 'sector_specific',
                    ];
                }
            }
        }

        return $queries;
    }

    /**
     * @return string[]
     */
    private function resolveLocationAlternates(string $location): array
    {
        if (isset(self::LOCATION_ALTERNATES[$location])) {
            return self::LOCATION_ALTERNATES[$location];
        }

        $locationLower = mb_strtolower($location);

        foreach (self::LOCATION_ALTERNATES as $canonical => $alternates) {
            if (str_contains($locationLower, mb_strtolower($canonical))) {
                return $alternates;
            }

            foreach ($alternates as $alternate) {
                if (str_contains($locationLower, mb_strtolower($alternate))) {
                    return $alternates;
                }
            }
        }

        return [];
    }

    /**
     * @param array{terms: string[], certs: string[], extras: string[]}|null $sectorConfig
     * @return array<int, array{query: string, type: string}>
     */
    private function buildCertificationQueries(?array $sectorConfig, ?string $location): array
    {
        $queries = [];
        $locationClause = $location !== null ? ' ' . $location : '';

        $certs = $sectorConfig['certs'] ?? ['ISO 9001'];

        foreach ($certs as $cert) {
            $queries[] = [
                'query' => "\"{$cert}\" certified manufacturer{$locationClause}",
                'type'  => 'certification',
            ];
        }

        // Always add ISO 9001 if not already present
        if (!in_array('ISO 9001', $certs, true)) {
            $queries[] = [
                'query' => "\"ISO 9001\" manufacturer production{$locationClause}",
                'type'  => 'certification',
            ];
        }

        return $queries;
    }

    /**
     * @return array<int, array{query: string, type: string}>
     */
    private function buildDirectoryQueries(?string $sector, ?string $location): array
    {
        $queries = [];
        $locationClause = $location !== null ? ' ' . $location : '';
        $sectorClause = $sector !== null ? ' ' . $sector : '';

        $directoryPatterns = [
            '"supplier portal" "vendor registration"' . $sectorClause . $locationClause,
            '"supplier directory" manufacturer' . $sectorClause . $locationClause,
            '"industrial directory" manufacturing company' . $locationClause,
        ];

        foreach ($directoryPatterns as $pattern) {
            $queries[] = [
                'query' => trim($pattern),
                'type'  => 'directory',
            ];
        }

        return $queries;
    }

    /**
     * @return array<int, array{query: string, type: string}>
     */
    private function buildIndustrialZoneQueries(string $location): array
    {
        $queries = [];

        $zonePatterns = [
            "\"industrial zone\" manufacturer {$location}",
            "\"free zone\" manufacturing company {$location}",
            "\"industrial park\" production {$location}",
        ];

        foreach ($zonePatterns as $pattern) {
            $queries[] = [
                'query' => $pattern,
                'type'  => 'industrial_zone',
            ];
        }

        return $queries;
    }

    /**
     * @return array<int, array{query: string, type: string}>
     */
    private function buildGeneralQueries(?string $location): array
    {
        $queries = [];
        $locationClause = $location !== null ? ' ' . $location : '';

        $generalPatterns = [
            "electronics manufacturer{$locationClause}",
            "PCB assembly manufacturer{$locationClause}",
            "manufacturing company factory{$locationClause}",
            "OEM manufacturer production plant{$locationClause}",
        ];

        foreach ($generalPatterns as $pattern) {
            $queries[] = [
                'query' => trim($pattern),
                'type'  => 'general',
            ];
        }

        return $queries;
    }

    /**
     * Remove exact duplicate query strings, preserving first occurrence.
     *
     * @param array<int, array{query: string, type: string}> $queries
     * @return array<int, array{query: string, type: string}>
     */
    private function deduplicateQueries(array $queries): array
    {
        $seen = [];
        $unique = [];

        foreach ($queries as $query) {
            $normalized = mb_strtolower(trim($query['query']));
            if (!isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $unique[] = $query;
            }
        }

        return $unique;
    }
}
