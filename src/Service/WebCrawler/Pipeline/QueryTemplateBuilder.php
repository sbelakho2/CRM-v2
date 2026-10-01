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
     * Region-specific B2B / manufacturing directory sites for site: queries.
     * Maps region code (MA, US, DE, FR...) to an array of directory domains.
     */
    private const REGION_DIRECTORIES = [
        'MA' => [
            'site:kerix.net',
            'site:charika.ma',
            'site:pagesjaunes.ma',
            'site:telecontact.ma',
            'site:amica.org.ma',
        ],
        'US' => [
            'site:thomasnet.com',
            'site:globalspec.com',
            'site:industrynet.com',
            'site:macraesbluebook.com',
            'site:mfg.com',
        ],
        'EU' => [
            'site:europages.com',
            'site:kompass.com',
            'site:industrystock.com',
            'site:directindustry.com',
        ],
        'GB' => [
            'site:applegate.co.uk',
            'site:construction.co.uk',
            'site:themanufacturer.com',
        ],
        'DE' => [
            'site:wlw.de',
            'site:europages.de',
            'site:wer-zu-wem.de',
            'site:industrieanzeiger.de',
        ],
        'FR' => [
            'site:europages.fr',
            'site:kompass.com',
            'site:industrie.com',
            'site:annuaire-pro.fr',
        ],
        'TN' => [
            'site:pagesjaunes.com.tn',
            'site:tunisieindustrie.nat.tn',
            'site:annuairetn.net',
        ],
        'EG' => [
            'site:yellowpages.com.eg',
            'site:daleel.com.eg',
            'site:egypt-business.com',
        ],
        'IT' => [
            'site:europages.it',
            'site:kompass.it',
        ],
        'ES' => [
            'site:europages.es',
            'site:kompass.es',
        ],
        'TR' => [
            'site:yellowpages.com.tr',
        ],
        'GCC' => [
            'site:yellowpages.ae',
            'site:saudiyellowpages.com',
            'site:bahrainyellowpages.com',
            'site:qataryellowpages.com',
        ],
    ];

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
        $region = $this->resolveRegionFromLocation($location);

        // Common exclusion patterns appended to all generic queries
        $exclude = ' -site:linkedin.com -site:wikipedia.org -site:facebook.com -site:twitter.com -site:instagram.com -site:youtube.com -site:glassdoor.com -site:crunchbase.com';

        // 1. Sector-specific queries
        $sectorConfig = $this->resolveSector($sector);
        if ($sectorConfig !== null) {
            $queries = array_merge($queries, $this->buildSectorQueries($sectorConfig, $location));
        }

        // 2. Certification queries
        $queries = array_merge($queries, $this->buildCertificationQueries($sectorConfig, $location));

        // 3. Directory / supplier portal queries
        $queries = array_merge($queries, $this->buildDirectoryQueries($sector, $location));

        // 4. Site: queries targeting known B2B directories
        $queries = array_merge($queries, $this->buildSiteDirectoryQueries($sector, $location, $region));

        // 5. Industrial zone queries (location-specific)
        if ($location !== null) {
            $queries = array_merge($queries, $this->buildIndustrialZoneQueries($location));
        }

        // 6. Contact / team discovery queries — find pages likely to have decision-maker info
        $queries = array_merge($queries, $this->buildContactDiscoveryQueries($sector, $location));

        // 7. General manufacturing queries (always included as fallback)
        $queries = array_merge($queries, $this->buildGeneralQueries($location, $exclude));

        // 8. Diverse discovery queries (varied patterns for broad coverage)
        $queries = array_merge($queries, $this->buildDiverseDiscoveryQueries($sector, $location, $region, $exclude));

        // Deduplicate and cap
        $queries = $this->deduplicateQueries($queries);
        $queries = array_slice($queries, 0, self::MAX_QUERIES);

        return $queries;
    }

    /**
     * Resolve a region code from the location string.
     * Uses location name matching (like GoogleDorkService::detectRegionFromLocation).
     */
    private function resolveRegionFromLocation(?string $location): ?string
    {
        if ($location === null || trim($location) === '') {
            return null;
        }

        $loc = mb_strtolower($location);

        // Morocco markers
        $maMarkers = ['morocco', 'maroc', 'المغرب', 'tangier', 'tanger', 'casablanca', 'kenitra', 'rabat', 'marrakech', 'fes', 'meknes'];
        foreach ($maMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'MA';
            }
        }

        // US markers
        $usMarkers = ['united states', 'usa', 'u.s.a', 'new york', 'california', 'texas', 'florida', 'illinois', 'ohio', 'michigan'];
        foreach ($usMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'US';
            }
        }

        // UK markers
        $ukMarkers = ['united kingdom', 'uk', 'britain', 'england', 'scotland', 'wales', 'london', 'manchester', 'birmingham'];
        foreach ($ukMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'GB';
            }
        }

        // France markers
        $frMarkers = ['france', 'paris', 'lyon', 'marseille', 'toulouse', 'bordeaux', 'lille'];
        foreach ($frMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'FR';
            }
        }

        // Germany markers
        $deMarkers = ['germany', 'deutschland', 'berlin', 'munich', 'münchen', 'hamburg', 'frankfurt', 'stuttgart', 'cologne', 'köln', 'düsseldorf', 'dusseldorf'];
        foreach ($deMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'DE';
            }
        }

        // Tunisia markers
        $tnMarkers = ['tunisia', 'tunisie', 'tunis', 'sfax', 'sousse'];
        foreach ($tnMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'TN';
            }
        }

        // Egypt markers
        $egMarkers = ['egypt', 'cairo', 'alexandria', 'suez'];
        foreach ($egMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'EG';
            }
        }

        // Italy markers
        $itMarkers = ['italy', 'italia', 'rome', 'milan', 'milano', 'turin', 'torino', 'bologna', 'florence', 'venice'];
        foreach ($itMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'IT';
            }
        }

        // Spain markers
        $esMarkers = ['spain', 'españa', 'espana', 'madrid', 'barcelona', 'valencia', 'seville', 'bilbao'];
        foreach ($esMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'ES';
            }
        }

        // Turkey markers
        $trMarkers = ['turkey', 'türkiye', 'turkiye', 'istanbul', 'ankara', 'izmir', 'bursa'];
        foreach ($trMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'TR';
            }
        }

        // GCC markers
        $gccMarkers = ['uae', 'united arab emirates', 'dubai', 'abu dhabi', 'saudi arabia', 'riyadh', 'jeddah', 'qatar', 'doha', 'kuwait', 'oman', 'bahrain'];
        foreach ($gccMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'GCC';
            }
        }

        return null;
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
    private /**
 * @param array<string|int, mixed> $sectorConfig
 */
function buildSectorQueries(array $sectorConfig, ?string $location): array
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
    private /**
 * @param array<string|int, mixed> $sectorConfig
 */
function buildCertificationQueries(?array $sectorConfig, ?string $location): array
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
     * Build contact / team discovery queries.
     * These find company pages that are likely to have team member listings,
     * decision-maker contact info, or leadership pages.
     *
     * @return array<int, array{query: string, type: string}>
     */
    private function buildContactDiscoveryQueries(?string $sector, ?string $location): array
    {
        $queries = [];
        $locationClause = $location !== null ? ' ' . $location : '';
        $sectorClause = $sector !== null ? ' ' . $sector : '';
        $exclude = ' -site:linkedin.com -site:wikipedia.org -site:facebook.com -site:crunchbase.com';

        // Find company "meet the team" / "leadership" pages directly
        $teamPatterns = [
            "\"meet our team\" OR \"meet the team\"{$sectorClause}{$locationClause}",
            "\"leadership team\" OR \"our leadership\"{$sectorClause}{$locationClause}",
            "\"our team\" \"management\"{$sectorClause}{$locationClause}",
            "\"board of directors\" OR \"executive team\"{$sectorClause}{$locationClause}",
        ];
        foreach ($teamPatterns as $pattern) {
            $queries[] = [
                'query' => trim($pattern . $exclude),
                'type'  => 'general',
            ];
        }

        // Find "contact us" pages combined with sector — these often have direct emails
        $contactPatterns = [
            "\"contact us\" OR \"contact\" \"purchasing\" OR \"procurement\"{$sectorClause}{$locationClause}",
            "\"sales@\" OR \"purchasing@\" OR \"procurement@\"{$sectorClause}{$locationClause}",
            "inquiry OR \"request for quote\" OR \"request quote\"{$sectorClause}{$locationClause}",
        ];
        foreach ($contactPatterns as $pattern) {
            $queries[] = [
                'query' => trim($pattern . $exclude),
                'type'  => 'general',
            ];
        }

        // Find company about pages that list key personnel
        $aboutPatterns = [
            "\"about us\" \"our team\" \"ceo\"{$sectorClause}{$locationClause}",
            "\"key personnel\" OR \"key people\"{$sectorClause}{$locationClause}",
        ];
        foreach ($aboutPatterns as $pattern) {
            $queries[] = [
                'query' => trim($pattern . $exclude),
                'type'  => 'general',
            ];
        }

        return $queries;
    }

    /**
     * Build site: directory queries targeting known B2B directories.
     *
     * @return array<int, array{query: string, type: string}>
     */
    private function buildSiteDirectoryQueries(?string $sector, ?string $location, ?string $region): array
    {
        $queries = [];
        $sectorClause = $sector !== null ? ' ' . $sector : '';
        $locationClause = $location !== null ? ' ' . $location : '';

        // Get region-specific directories
        $directories = [];
        if ($region !== null && isset(self::REGION_DIRECTORIES[$region])) {
            $directories = self::REGION_DIRECTORIES[$region];
        }

        // Also add general directories (always included)
        $generalDirectories = self::REGION_DIRECTORIES['EU'] ?? [];

        $allDirectories = array_unique(array_merge($directories, $generalDirectories));

        foreach ($allDirectories as $siteDork) {
            // With sector: site:kerix.net automotive tangier
            if ($sector !== null) {
                $queries[] = [
                    'query' => trim("{$siteDork}{$sectorClause}{$locationClause}"),
                    'type'  => 'directory',
                ];
            }
            // Without sector: site:kerix.net manufacturer tangier
            $queries[] = [
                'query' => trim("{$siteDork} manufacturer{$locationClause}"),
                'type'  => 'directory',
            ];
            // Industry-specific: site:kerix.net OEM components
            $queries[] = [
                'query' => trim("{$siteDork} OEM components{$locationClause}"),
                'type'  => 'directory',
            ];
        }

        // Also add direct sector-specific directory queries
        // E.g., "automotive companies in Tangier Morocco site:kerix.net"
        foreach ($allDirectories as $siteDork) {
            if ($sector !== null) {
                $queries[] = [
                    'query' => trim("\"{$sector}\" company{$locationClause} {$siteDork}"),
                    'type'  => 'directory',
                ];
            }
        }

        return $queries;
    }

    /**
     * Build diverse discovery queries with varied patterns for broad coverage.
     * Inspired by the old GoogleDorkService's query diversity.
     *
     * @return array<int, array{query: string, type: string}>
     */
    private function buildDiverseDiscoveryQueries(?string $sector, ?string $location, ?string $region, string $exclude): array
    {
        $queries = [];
        $locationClause = $location !== null ? ' ' . $location : '';

        if ($sector !== null) {
            // "about us" style queries — high intent for real companies
            $queries[] = [
                'query' => trim("{$sector} \"about us\" OR \"founded\"{$locationClause}{$exclude}"),
                'type'  => 'general',
            ];
            // "our products" style queries — finds product pages
            $queries[] = [
                'query' => trim("{$sector} \"our products\" OR \"our solutions\"{$locationClause}{$exclude}"),
                'type'  => 'general',
            ];
            // Technical product queries
            $queries[] = [
                'query' => trim("{$sector} OEM manufacturer{$locationClause}{$exclude}"),
                'type'  => 'general',
            ];
            // Wire harness / cable assembly — core EMS service
            $queries[] = [
                'query' => trim("{$sector} \"wire harness\" OR \"cable assembly\"{$locationClause}{$exclude}"),
                'type'  => 'general',
            ];
            // Supplier/tier queries
            $queries[] = [
                'query' => trim("{$sector} \"tier 1\" OR \"tier 2\" supplier{$locationClause}{$exclude}"),
                'type'  => 'general',
            ];
        }

        return $queries;
    }

    /**
     * @return array<int, array{query: string, type: string}>
     */
    private function buildGeneralQueries(?string $location, string $exclude = ''): array
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
                'query' => trim($pattern . $exclude),
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
    private /**
 * @param array<string|int, mixed> $queries
 */
function deduplicateQueries(array $queries): array
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
