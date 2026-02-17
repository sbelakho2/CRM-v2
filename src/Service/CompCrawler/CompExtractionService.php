<?php

namespace App\Service\CompCrawler;

use App\Entity\Competitor;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * CompExtractionService — Deterministic + AI extraction pipeline.
 *
 * Phase 1 (deterministic):  HTML parsing, JSON-LD/microdata, regex patterns.
 * Phase 2 (AI):             Gemini Flash for structured extraction from unstructured text.
 *
 * Extraction targets per competitor type:
 *  - All:         company_name, domains, HQ address, country, employees, certs, industries
 *  - EMS:         smt_lines, pcba_capabilities, box_build, test_services
 *  - Machining:   axis_count, material_families, max_part_dimensions, tolerances
 *  - Harness:     connector_brands, cable_types, crimp_tooling, test_methods
 *  - Supercap:    cell_chemistry, voltage_range, capacitance_range, esr_spec, cycle_life
 */
class CompExtractionService
{
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';
    private const MAX_CONTENT_FOR_AI = 60000; // ~60KB

    public function __construct(
        private readonly CompCrawlerConfig $config,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $geminiApiKey = '',
    ) {}

    /**
     * Extract structured competitor profile from crawled HTML pages.
     *
     * @param Competitor            $competitor The competitor entity
     * @param array<string,string>  $pageContent  URL → HTML map from crawl
     *
     * @return array{profile: array, evidence: array, raw_signals: array}
     */
    public function extract(Competitor $competitor, array $pageContent): array
    {
        $profile = [];
        $evidence = [];
        $rawSignals = [];

        // Phase 1: Deterministic extraction per page
        foreach ($pageContent as $url => $html) {
            $pageSignals = $this->extractDeterministic($html, $url);
            $rawSignals[$url] = $pageSignals;

            $this->mergeSignals($profile, $pageSignals, $evidence, $url);
        }

        // Phase 2: AI extraction for high-value pages if deterministic was thin
        if ($this->shouldUseAi($profile, $competitor) && $this->isGeminiConfigured()) {
            $this->logger->info('CompExtract: Running AI extraction for {domain}', [
                'domain' => $competitor->getCanonicalDomain(),
            ]);

            $aiProfile = $this->extractWithGemini($competitor, $pageContent);
            if (!empty($aiProfile)) {
                $this->mergeAiProfile($profile, $aiProfile, $evidence);
            }
        }

        // Phase 3: Populate type-specific profile JSON fields
        $this->populateTypeProfiles($competitor, $profile);

        return [
            'profile' => $profile,
            'evidence' => $evidence,
            'raw_signals' => $rawSignals,
        ];
    }

    // ─── Phase 1: Deterministic ───────────────────────────────────────────────

    /**
     * Extract structured signals from a single HTML page.
     */
    private function extractDeterministic(string $html, string $url): array
    {
        $signals = [];

        // Strip scripts/styles for text analysis
        $cleanHtml = preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', '', $html);
        $text = strip_tags($cleanHtml);
        $text = preg_replace('/\s+/', ' ', trim($text));

        // JSON-LD extraction
        $jsonLd = $this->extractJsonLd($html);
        if (!empty($jsonLd)) {
            $signals['json_ld'] = $jsonLd;
            if (isset($jsonLd['name'])) $signals['company_name'] = $jsonLd['name'];
            if (isset($jsonLd['address'])) $signals['address'] = $jsonLd['address'];
            if (isset($jsonLd['url'])) $signals['website'] = $jsonLd['url'];
            if (isset($jsonLd['numberOfEmployees'])) $signals['employees'] = $jsonLd['numberOfEmployees'];
        }

        // Certifications
        $signals['certifications'] = $this->extractCertifications($text);

        // Industries served
        $signals['industries'] = $this->extractIndustries($text);

        // Capabilities
        $signals['capabilities'] = $this->extractCapabilities($text, $url);

        // EMS-specific
        $signals['ems'] = $this->extractEmsSignals($text);

        // Machining-specific
        $signals['machining'] = $this->extractMachiningSignals($text);

        // Harness-specific
        $signals['harness'] = $this->extractHarnessSignals($text);

        // Supercapacitor-specific
        $signals['supercap'] = $this->extractSupercapSignals($text);

        // Employee count from text
        if (empty($signals['employees'])) {
            $signals['employees'] = $this->extractEmployeeCount($text);
        }

        // Locations
        $signals['locations'] = $this->extractLocations($text);

        return $signals;
    }

    /**
     * Extract JSON-LD structured data.
     */
    private function extractJsonLd(string $html): array
    {
        $data = [];
        if (preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            foreach ($matches[1] as $block) {
                $decoded = json_decode(trim($block), true);
                if (!$decoded) continue;

                // Handle @graph
                $items = isset($decoded['@graph']) ? $decoded['@graph'] : [$decoded];
                foreach ($items as $item) {
                    $type = $item['@type'] ?? '';
                    if (in_array($type, ['Organization', 'Corporation', 'LocalBusiness', 'ManufacturingBusiness'])) {
                        $data = array_merge($data, $item);
                    }
                }
            }
        }
        return $data;
    }

    /**
     * Extract certifications from text.
     */
    private function extractCertifications(string $text): array
    {
        $certs = [];
        $patterns = [
            // ISO certs
            'ISO\s*9001' => 'ISO 9001',
            'ISO\s*14001' => 'ISO 14001',
            'ISO\s*13485' => 'ISO 13485',
            'ISO\s*45001' => 'ISO 45001',
            'ISO\s*27001' => 'ISO 27001',
            'ISO\s*16949|IATF\s*16949' => 'IATF 16949',
            'AS\s*9100' => 'AS 9100',
            'NADCAP' => 'NADCAP',
            // Industry certs
            'IPC[\s-]*A[\s-]*610' => 'IPC-A-610',
            'IPC[\s-]*J[\s-]*STD[\s-]*001' => 'IPC J-STD-001',
            'IPC[\s-]*A[\s-]*620' => 'IPC-A-620',
            'IPC[\s-]*6012' => 'IPC-6012',
            'UL\s*508A' => 'UL 508A',
            'UL\s*listed|UL\s*certified' => 'UL Listed',
            'CE\s*mark|CE\s*certified' => 'CE Marked',
            'RoHS' => 'RoHS Compliant',
            'REACH' => 'REACH Compliant',
            'ITAR' => 'ITAR Registered',
            'MIL[\s-]*STD' => 'MIL-STD Compliant',
        ];

        foreach ($patterns as $regex => $certName) {
            if (preg_match('/' . $regex . '/i', $text)) {
                $certs[] = $certName;
            }
        }

        return array_unique($certs);
    }

    /**
     * Extract industries served.
     */
    private function extractIndustries(string $text): array
    {
        $industries = [];
        $keywords = [
            'automotive' => 'Automotive',
            'aerospace' => 'Aerospace',
            'medical|healthcare' => 'Medical',
            'defense|military|defence' => 'Defense',
            'industrial|automation' => 'Industrial',
            'telecom|communication' => 'Telecom',
            'rail|railway|locomotive' => 'Rail',
            'renewable|solar|wind energy' => 'Renewables',
            'marine|naval|shipbuild' => 'Marine',
            'hvac|climate|heating' => 'HVAC',
            'consumer\s+electron' => 'Consumer Electronics',
            'power\s+electron' => 'Power Electronics',
            'data\s+center' => 'Data Center',
            'energy\s+storage' => 'Energy Storage',
        ];

        foreach ($keywords as $pattern => $industry) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $industries[] = $industry;
            }
        }

        return array_unique($industries);
    }

    /**
     * Extract general capabilities.
     */
    private function extractCapabilities(string $text, string $url): array
    {
        $caps = [];
        $patterns = [
            'smt|surface\s+mount' => 'SMT Assembly',
            'through[\s-]*hole' => 'Through-Hole Assembly',
            'bga\s+rework|bga\s+assembly' => 'BGA Assembly',
            'box[\s-]*build|system\s+integration' => 'Box Build / System Integration',
            'cable\s+(?:assembly|harness)' => 'Cable Assembly',
            'wire\s+harness' => 'Wire Harness',
            'pcb\s+design|pcba' => 'PCB Assembly',
            'conformal\s+coat' => 'Conformal Coating',
            'potting|encapsulat' => 'Potting/Encapsulation',
            'prototyp' => 'Prototyping',
            'cnc\s+machin|cnc\s+mill|cnc\s+turn' => 'CNC Machining',
            '3[\s-]*axis|5[\s-]*axis' => 'Multi-Axis Machining',
            'edm|electro[\s-]*discharge' => 'EDM',
            'grinding|surface\s+finish' => 'Precision Grinding',
            'injection\s+mold' => 'Injection Molding',
            'die[\s-]*cast' => 'Die Casting',
            'sheet\s+metal|stamping' => 'Sheet Metal/Stamping',
            'functional\s+test|ict|in[\s-]*circuit' => 'Functional Testing',
            'flying\s+probe' => 'Flying Probe Testing',
            'x[\s-]*ray\s+inspect' => 'X-Ray Inspection',
            'aoi|automated\s+optical' => 'AOI',
        ];

        foreach ($patterns as $regex => $cap) {
            if (preg_match('/' . $regex . '/i', $text)) {
                $caps[] = $cap;
            }
        }

        return array_unique($caps);
    }

    /**
     * EMS-specific signal extraction.
     */
    private function extractEmsSignals(string $text): array
    {
        $signals = [];

        // SMT lines count
        if (preg_match('/(\d+)\s*(?:smt|surface\s*mount)\s*(?:line|assembly)/i', $text, $m)) {
            $signals['smt_lines'] = (int) $m[1];
        }

        // Board size range
        if (preg_match('/(?:board|pcb)\s+size.*?(\d+)\s*[xX×]\s*(\d+)/i', $text, $m)) {
            $signals['max_board_size'] = $m[1] . 'x' . $m[2] . 'mm';
        }

        // Component count capability
        if (preg_match('/(\d[\d,]+)\s+(?:component|placement).*(?:per\s+hour|\/hr)/i', $text, $m)) {
            $signals['placements_per_hour'] = str_replace(',', '', $m[1]);
        }

        // NPI support
        if (preg_match('/\b(npi|new\s+product\s+introduction)\b/i', $text)) {
            $signals['npi_support'] = true;
        }

        // BOM management
        if (preg_match('/\b(bom\s+management|supply\s+chain|component\s+sourcing)\b/i', $text)) {
            $signals['bom_management'] = true;
        }

        return $signals;
    }

    /**
     * Machining-specific signal extraction.
     */
    private function extractMachiningSignals(string $text): array
    {
        $signals = [];

        // Axis count
        $axes = [];
        if (preg_match('/3[\s-]*axis/i', $text)) $axes[] = 3;
        if (preg_match('/4[\s-]*axis/i', $text)) $axes[] = 4;
        if (preg_match('/5[\s-]*axis/i', $text)) $axes[] = 5;
        if (preg_match('/7[\s-]*axis/i', $text)) $axes[] = 7;
        if ($axes) $signals['axis_count'] = max($axes);

        // Materials
        $materials = [];
        $matPatterns = [
            'aluminum|aluminium' => 'Aluminum',
            'steel|stainless' => 'Steel',
            'titanium' => 'Titanium',
            'brass' => 'Brass',
            'copper' => 'Copper',
            'inconel' => 'Inconel',
            'peek|ultem|delrin|nylon|plastic' => 'Engineering Plastics',
            'ceramic' => 'Ceramics',
            'hastelloy' => 'Hastelloy',
            'kovar|invar' => 'Special Alloys',
        ];
        foreach ($matPatterns as $regex => $mat) {
            if (preg_match('/' . $regex . '/i', $text)) $materials[] = $mat;
        }
        if ($materials) $signals['material_families'] = $materials;

        // Tolerance
        if (preg_match('/(?:tolerance|precision).*?(\+\/?-?\s*[\d.]+\s*(?:mm|μm|micron|thou|inch))/i', $text, $m)) {
            $signals['tolerance_spec'] = trim($m[1]);
        }
        if (preg_match('/±?\s*([\d.]+)\s*(?:mm|μm)/i', $text, $m) && (float)$m[1] < 1.0) {
            $signals['precision_tolerance'] = $m[0];
        }

        // Machine brands
        $brands = [];
        $brandPatterns = ['DMG\s*Mori', 'Mazak', 'Haas', 'Okuma', 'Makino', 'Matsuura', 'Fanuc', 'Doosan', 'Hermle'];
        foreach ($brandPatterns as $brand) {
            if (preg_match('/' . $brand . '/i', $text)) {
                $brands[] = preg_replace('/\s+/', ' ', $brand);
            }
        }
        if ($brands) $signals['machine_brands'] = $brands;

        return $signals;
    }

    /**
     * Harness-specific signal extraction.
     */
    private function extractHarnessSignals(string $text): array
    {
        $signals = [];

        // Connector brands
        $connectors = [];
        $connBrands = ['TE\s*Connectivity', 'Molex', 'Amphenol', 'JST', 'Hirose', 'Delphi', 'Deutsch', 'JAE', 'Yazaki'];
        foreach ($connBrands as $brand) {
            if (preg_match('/' . $brand . '/i', $text)) {
                $connectors[] = preg_replace('/\s+/', ' ', $brand);
            }
        }
        if ($connectors) $signals['connector_brands'] = $connectors;

        // Cable types
        $cableTypes = [];
        $cablePatterns = [
            'coaxial|coax' => 'Coaxial',
            'ribbon\s+cable' => 'Ribbon Cable',
            'shielded\s+cable' => 'Shielded Cable',
            'multi[\s-]*conductor' => 'Multi-Conductor',
            'fiber\s+optic|fibre\s+optic' => 'Fiber Optic',
            'high[\s-]*voltage' => 'High-Voltage',
            'flat\s+flex|ffc|fpc' => 'FFC/FPC',
        ];
        foreach ($cablePatterns as $regex => $type) {
            if (preg_match('/' . $regex . '/i', $text)) $cableTypes[] = $type;
        }
        if ($cableTypes) $signals['cable_types'] = $cableTypes;

        // Wire gauge
        if (preg_match('/(\d+)\s*(?:awg|gauge)/i', $text, $m)) {
            $signals['wire_gauge_min'] = (int) $m[1];
        }

        // Crimp tooling
        if (preg_match('/(?:crimp|crimping)\s+(?:tool|machine|press)/i', $text)) {
            $signals['has_crimp_tooling'] = true;
        }

        // Test methods
        $testMethods = [];
        $testPatterns = [
            'continuity\s+test' => 'Continuity',
            'hipot|hi[\s-]*pot|high[\s-]*potential' => 'Hipot',
            'pull\s+test' => 'Pull Test',
            'seal\s+test|leak\s+test' => 'Seal/Leak Test',
        ];
        foreach ($testPatterns as $regex => $method) {
            if (preg_match('/' . $regex . '/i', $text)) $testMethods[] = $method;
        }
        if ($testMethods) $signals['test_methods'] = $testMethods;

        return $signals;
    }

    /**
     * Supercapacitor-specific signal extraction.
     */
    private function extractSupercapSignals(string $text): array
    {
        $signals = [];

        // Cell chemistry
        $chems = [];
        $chemPatterns = [
            'edlc|electric\s*double[\s-]*layer' => 'EDLC',
            'pseudo[\s-]*capacitor' => 'Pseudocapacitor',
            'hybrid\s+capacitor' => 'Hybrid',
            'lithium[\s-]*ion\s+capacitor|lic' => 'Lithium-Ion Capacitor',
            'activated\s+carbon' => 'Activated Carbon',
            'graphene' => 'Graphene',
        ];
        foreach ($chemPatterns as $regex => $chem) {
            if (preg_match('/' . $regex . '/i', $text)) $chems[] = $chem;
        }
        if ($chems) $signals['cell_chemistry'] = $chems;

        // Voltage range
        if (preg_match('/(\d+(?:\.\d+)?)\s*V?\s*(?:to|[-–])\s*(\d+(?:\.\d+)?)\s*V/i', $text, $m)) {
            if ((float) $m[2] <= 10) { // Supercap voltage range sanity
                $signals['voltage_range'] = $m[1] . 'V - ' . $m[2] . 'V';
            }
        }

        // Capacitance
        if (preg_match('/(\d+(?:\.\d+)?)\s*F\b/i', $text, $m)) {
            $signals['capacitance_max_f'] = (float) $m[1];
        }
        if (preg_match('/(\d+)\s*(?:mF|millifarad)/i', $text, $m)) {
            $signals['capacitance_mf'] = (int) $m[1];
        }

        // ESR
        if (preg_match('/ESR.*?(\d+(?:\.\d+)?)\s*(?:mΩ|mohm|milliohm)/i', $text, $m)) {
            $signals['esr_mohm'] = (float) $m[1];
        }

        // Cycle life
        if (preg_match('/([\d,]+)\s*(?:cycles|cycle\s+life)/i', $text, $m)) {
            $signals['cycle_life'] = (int) str_replace(',', '', $m[1]);
        }

        // Module assembly
        if (preg_match('/module\s+(?:assembly|pack|bank)/i', $text)) {
            $signals['module_assembly'] = true;
        }

        return $signals;
    }

    /**
     * Extract employee count from text.
     */
    private function extractEmployeeCount(string $text): ?int
    {
        if (preg_match('/(\d[\d,]+)\s*(?:employees|associates|team\s+members|staff|workers)/i', $text, $m)) {
            return (int) str_replace(',', '', $m[1]);
        }
        if (preg_match('/(?:more\s+than|over)\s+(\d[\d,]+)\s+(?:people|employees)/i', $text, $m)) {
            return (int) str_replace(',', '', $m[1]);
        }
        return null;
    }

    /**
     * Extract location signals.
     */
    private function extractLocations(string $text): array
    {
        $locations = [];
        // Manufacturing locations
        if (preg_match('/(?:factory|plant|facility|manufacturing\s+site)\s+(?:in|at|located)\s+([^.]{5,80})/i', $text, $m)) {
            $locations[] = trim($m[1], ' ,.');
        }
        return $locations;
    }

    /**
     * Merge page signals into accumulated profile.
     */
    private function mergeSignals(array &$profile, array $signals, array &$evidence, string $url): void
    {
        // Simple string fields
        foreach (['company_name', 'address', 'website', 'employees'] as $field) {
            if (!empty($signals[$field]) && empty($profile[$field])) {
                $profile[$field] = $signals[$field];
                $evidence[$field] = $url;
            }
        }

        // Array-merge fields
        foreach (['certifications', 'industries', 'capabilities', 'locations'] as $field) {
            if (!empty($signals[$field])) {
                $profile[$field] = array_unique(array_merge($profile[$field] ?? [], $signals[$field]));
                $evidence[$field . '_urls'][] = $url;
            }
        }

        // Type-specific signals
        foreach (['ems', 'machining', 'harness', 'supercap'] as $type) {
            if (!empty($signals[$type])) {
                $profile[$type] = array_merge($profile[$type] ?? [], $signals[$type]);
                $evidence[$type . '_urls'][] = $url;
            }
        }
    }

    // ─── Phase 2: AI Extraction (Gemini Flash) ────────────────────────────────

    private function isGeminiConfigured(): bool
    {
        return !empty($this->geminiApiKey) && $this->geminiApiKey !== 'null';
    }

    /**
     * Decide if we should use AI extraction.
     */
    private function shouldUseAi(array $profile, Competitor $competitor): bool
    {
        // If we have very few deterministic signals, try AI
        $signalCount = count($profile['certifications'] ?? [])
            + count($profile['industries'] ?? [])
            + count($profile['capabilities'] ?? []);

        return $signalCount < 3;
    }

    /**
     * Use Gemini Flash to extract structured competitor data from HTML.
     */
    private function extractWithGemini(Competitor $competitor, array $pageContent): array
    {
        // Concatenate key pages, prioritize capabilities/about pages
        $priorityPages = [];
        $otherPages = [];

        foreach ($pageContent as $url => $html) {
            $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '/');
            $isHighValue = str_contains($path, 'capabilit') || str_contains($path, 'about')
                || str_contains($path, 'service') || str_contains($path, 'certif')
                || str_contains($path, 'manufactur') || str_contains($path, 'qualit');

            $clean = strip_tags(preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', '', $html));
            $clean = preg_replace('/\s+/', ' ', trim($clean));

            if ($isHighValue) {
                $priorityPages[] = "=== PAGE: {$url} ===\n" . substr($clean, 0, 10000);
            } else {
                $otherPages[] = "=== PAGE: {$url} ===\n" . substr($clean, 0, 5000);
            }
        }

        $combined = implode("\n\n", array_merge($priorityPages, $otherPages));
        if (strlen($combined) > self::MAX_CONTENT_FOR_AI) {
            $combined = substr($combined, 0, self::MAX_CONTENT_FOR_AI);
        }

        $types = implode(', ', $competitor->getCompetitorTypes());

        $prompt = <<<PROMPT
You are analyzing a competitor company website for Starz Electronics, an EMS/contract manufacturer.
The competitor domain is: {$competitor->getCanonicalDomain()}
The competitor types are: {$types}

Extract structured data from the following website content. Return ONLY a JSON object with these fields:
{
  "company_name": "string",
  "hq_country": "string (country name)",
  "employee_count": "integer or null",
  "certifications": ["ISO 9001", ...],
  "industries_served": ["Automotive", "Aerospace", ...],
  "capabilities": ["SMT Assembly", "CNC Machining", ...],
  "locations": [{"city": "...", "country": "..."}],
  "key_equipment": ["brand/model", ...],
  "ems_profile": {
    "smt_lines": "integer or null",
    "pcba_capability": true/false,
    "box_build": true/false,
    "npi_support": true/false
  },
  "machining_profile": {
    "max_axis": "integer or null",
    "materials": ["Aluminum", ...],
    "tolerance_spec": "string or null"
  },
  "harness_profile": {
    "connector_brands": ["TE Connectivity", ...],
    "cable_types": ["Coaxial", ...],
    "test_methods": ["Continuity", ...]
  },
  "supercap_profile": {
    "cell_chemistry": ["EDLC", ...],
    "voltage_range": "string or null",
    "max_capacitance_f": "number or null",
    "cycle_life": "integer or null"
  }
}

IMPORTANT: Return ONLY the JSON object. No markdown, no explanation.

Website content:
{$combined}
PROMPT;

        try {
            $response = $this->callGemini($prompt);
            return $this->parseJsonResponse($response);
        } catch (\Throwable $e) {
            $this->logger->warning('CompExtract: Gemini extraction failed: {msg}', ['msg' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Call Gemini Flash API with retry and response validation.
     */
    private function callGemini(string $prompt): string
    {
        $url = self::GEMINI_ENDPOINT . '?key=' . $this->geminiApiKey;
        $maxAttempts = 3;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->httpClient->request('POST', $url, [
                    'json' => [
                        'contents' => [
                            ['parts' => [['text' => $prompt]]],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.1,
                            'maxOutputTokens' => 4096,
                        ],
                    ],
                    'timeout' => 60,
                ]);

                $statusCode = $response->getStatusCode();

                // Rate limit — back off and retry
                if ($statusCode === 429) {
                    $delay = $attempt * 5; // 5s, 10s, 15s
                    $this->logger->warning('CompExtract: Gemini rate limited (429), waiting {delay}s (attempt {a}/{m})', [
                        'delay' => $delay, 'a' => $attempt, 'm' => $maxAttempts,
                    ]);
                    sleep($delay);
                    continue;
                }

                // Server error — retry
                if ($statusCode >= 500) {
                    $this->logger->warning('CompExtract: Gemini server error {code} (attempt {a}/{m})', [
                        'code' => $statusCode, 'a' => $attempt, 'm' => $maxAttempts,
                    ]);
                    sleep($attempt * 2);
                    continue;
                }

                $data = $response->toArray(false);

                // Validate response structure
                if (!isset($data['candidates']) || !is_array($data['candidates']) || empty($data['candidates'])) {
                    $this->logger->warning('CompExtract: Gemini returned no candidates (attempt {a}/{m})', [
                        'a' => $attempt, 'm' => $maxAttempts,
                    ]);
                    if ($attempt < $maxAttempts) {
                        sleep($attempt);
                        continue;
                    }
                    return '';
                }

                $candidate = $data['candidates'][0];
                if (!isset($candidate['content']['parts'][0]['text'])) {
                    // Could be a safety block or empty response
                    $finishReason = $candidate['finishReason'] ?? 'unknown';
                    $this->logger->warning('CompExtract: Gemini empty response, finishReason={reason}', [
                        'reason' => $finishReason,
                    ]);
                    return '';
                }

                // Log token usage for cost tracking
                $usage = $data['usageMetadata'] ?? [];
                if (!empty($usage)) {
                    $this->logger->debug('CompExtract: Gemini tokens — prompt={p} completion={c} total={t}', [
                        'p' => $usage['promptTokenCount'] ?? 0,
                        'c' => $usage['candidatesTokenCount'] ?? 0,
                        't' => $usage['totalTokenCount'] ?? 0,
                    ]);
                }

                return $candidate['content']['parts'][0]['text'];
            } catch (\Throwable $e) {
                $lastException = $e;
                $this->logger->warning('CompExtract: Gemini call failed (attempt {a}/{m}): {msg}', [
                    'a' => $attempt, 'm' => $maxAttempts, 'msg' => $e->getMessage(),
                ]);

                if ($attempt < $maxAttempts) {
                    sleep($attempt * 2);
                }
            }
        }

        throw $lastException ?? new \RuntimeException('Gemini API failed after ' . $maxAttempts . ' attempts');
    }

    /**
     * Parse a JSON response (strip markdown fences if present).
     */
    private function parseJsonResponse(string $text): array
    {
        // Strip markdown code fences
        $text = preg_replace('/^```(?:json)?\s*\n?/m', '', $text);
        $text = preg_replace('/\n?```\s*$/m', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Failed to parse Gemini JSON response');
        }

        return $data;
    }

    /**
     * Merge AI-extracted profile into accumulated profile.
     */
    private function mergeAiProfile(array &$profile, array $aiProfile, array &$evidence): void
    {
        // String fields (only fill gaps)
        foreach (['company_name', 'hq_country', 'employee_count'] as $field) {
            if (!empty($aiProfile[$field]) && empty($profile[$field])) {
                $profile[$field] = $aiProfile[$field];
                $evidence[$field] = 'gemini_extraction';
            }
        }

        // Array-merge fields
        foreach (['certifications', 'industries_served', 'capabilities', 'key_equipment'] as $field) {
            $srcKey = ($field === 'industries_served') ? 'industries' : $field;
            if (!empty($aiProfile[$field])) {
                $profile[$srcKey] = array_unique(array_merge($profile[$srcKey] ?? [], $aiProfile[$field]));
                $evidence[$srcKey . '_ai'] = true;
            }
        }

        // Type-specific profiles
        foreach (['ems_profile', 'machining_profile', 'harness_profile', 'supercap_profile'] as $key) {
            if (!empty($aiProfile[$key]) && is_array($aiProfile[$key])) {
                $shortKey = str_replace('_profile', '', $key);
                $profile[$shortKey] = array_merge($profile[$shortKey] ?? [], $aiProfile[$key]);
                $evidence[$shortKey . '_ai'] = true;
            }
        }

        // Locations
        if (!empty($aiProfile['locations'])) {
            $profile['locations'] = array_merge($profile['locations'] ?? [], $aiProfile['locations']);
        }
    }

    /**
     * Populate type-specific JSON fields on the Competitor entity.
     */
    private function populateTypeProfiles(Competitor $competitor, array $profile): void
    {
        // Certifications
        if (!empty($profile['certifications'])) {
            $competitor->setCertifications($profile['certifications']);
        }

        // Industries
        if (!empty($profile['industries'])) {
            $competitor->setIndustries($profile['industries']);
        }

        // Capabilities
        if (!empty($profile['capabilities'])) {
            $competitor->setCapabilities($profile['capabilities']);
        }

        // EMS profile
        if (!empty($profile['ems'])) {
            $competitor->setEmsProfile(array_merge($competitor->getEmsProfile() ?? [], $profile['ems']));
        }

        // Machining profile
        if (!empty($profile['machining'])) {
            $competitor->setMachiningProfile(array_merge($competitor->getMachiningProfile() ?? [], $profile['machining']));
        }

        // Harness profile
        if (!empty($profile['harness'])) {
            $competitor->setHarnessProfile(array_merge($competitor->getHarnessProfile() ?? [], $profile['harness']));
        }

        // Supercap profile
        if (!empty($profile['supercap'])) {
            $competitor->setSupercapProfile(array_merge($competitor->getSupercapProfile() ?? [], $profile['supercap']));
        }

        // Employee estimate
        if (!empty($profile['employees']) && is_numeric($profile['employees'])) {
            $competitor->setEmployeeEstimate((int) $profile['employees']);
        }

        // HQ country
        if (!empty($profile['hq_country'])) {
            $competitor->setHqCountry($profile['hq_country']);
        }
    }
}
