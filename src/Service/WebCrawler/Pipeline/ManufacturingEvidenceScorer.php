<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

/**
 * Accumulates manufacturing evidence across all pages of a domain
 * using weighted signal families.  Applies hard vetoes for clearly
 * non-target domain types (directory, media, association, …).
 */
final class ManufacturingEvidenceScorer
{
    /** Categories that are hard-vetoed regardless of evidence. */
    private const VETO_CATEGORIES = [
        'directory', 'media', 'association', 'government',
        'recruiter', 'consultant', 'competitor_ems',
    ];

    private const MAX_KEYWORD_HITS = 3;

    // ──────────────────────────────────────────────────────────────────
    //  Signal families – each has keywords + a family score cap
    // ──────────────────────────────────────────────────────────────────

    private const FAMILIES = [
        'manufacturing_process' => [
            'cap' => 40,
            'high' => [
                'cnc machining', 'injection molding', 'die casting',
                'stamping press', 'forging press', 'extrusion line',
                'laser cutting', '3d printing', 'additive manufacturing',
                'precision machining',
                'designs and manufactures', 'develops and manufactures',
                // French
                'conçoit et fabrique', 'conçoit et produit',
                // German
                'entwickelt und fertigt', 'entwickelt und produziert',
                // Spanish
                'diseña y fabrica',
                // Italian
                'progetta e produce',
            ],
            'medium' => [
                'machining', 'stamping', 'forging', 'casting', 'molding',
                'welding', 'assembly line', 'heat treatment', 'surface treatment',
                'coating', 'plating', 'turning', 'milling', 'extrusion',
                'sheet metal', 'fabrication', 'tooling',
                'manufactures', 'manufactured', 'manufacturing',
                'produces', 'production',
                // French
                'fabrique', 'fabrication',
                // German
                'fertigt', 'fertigung', 'produziert',
                // Spanish
                'fabrica', 'fabricación',
                // Italian
                'produce', 'produzione',
            ],
        ],
        'product_evidence' => [
            'cap' => 30,
            'high' => [
                'we design', 'we develop', 'our products', 'product range',
                'product portfolio', 'we manufacture',
                'our products include', 'our product range',
                // French
                'nos produits', 'notre gamme',
                // German
                'unsere produkte',
            ],
            'medium' => [
                'custom design', 'product line', 'product development',
                'engineering team', 'r&d', 'research and development',
                'prototype', 'new product',
                'our solutions', 'our systems', 'our technology',
                'technology company', 'develops',
                // French
                'nos solutions', 'nos systèmes',
                // German
                'unsere lösungen',
            ],
        ],
        'facility_signals' => [
            'cap' => 25,
            'high' => [
                'production facility', 'manufacturing plant', 'our factory',
                'cleanroom', 'production floor', 'shop floor',
                // French
                'notre usine', 'nos usines',
                // German
                'unser werk', 'unsere fabrik',
            ],
            'medium' => [
                'facility', 'plant', 'factory', 'warehouse',
                'production site', 'our site',
                // French
                'usine', 'atelier',
                // German
                'werk', 'anlage',
            ],
        ],
        'certifications' => [
            'cap' => 36,
            'certs' => [
                'iso 9001', 'iatf 16949', 'as9100', 'en 9100',
                'iso 13485', 'iso 14001', 'iso 45001', 'nadcap',
                'iso/ts 16949',
            ],
        ],
        'org_footprint' => [
            'cap' => 10,
            'low' => [
                'founded', 'established', 'headquarters', 'employees',
                'subsidiary', 'worldwide', 'global presence',
                'years of experience', 'since 19', 'since 20',
            ],
        ],
        'product_manufacturing' => [
            'cap' => 30,
            'high' => [
                // Test & measurement instrumentation
                'oscilloscopes', 'spectrum analyzers', 'signal generators',
                'measuring systems', 'test equipment', 'measuring instruments',
                'power analyzers',
                // Semiconductor / embedded products
                'semiconductor', 'microcontrollers', 'embedded systems',
                'industrial computers', 'soc devices', 'analog products',
                'calibration tools', 'diagnostic tools', 'measurement modules',
                // Electrical infrastructure products
                'switchgear', 'circuit breakers', 'power distribution units',
                'safety controllers', 'ecus', 'control panels',
                // Automotive electronics
                'automotive electronics', 'deterministic networking',
                // Consumer appliance manufacturing (FR)
                'appareils électroménagers',
                // Electrical equipment manufacturing (FR)
                'tableaux électriques', 'armoires de distribution',
                'équipements de contrôle',
            ],
            'medium' => [
                'sensors', 'encoders', 'transmitters', 'relays',
                'connectors', 'radar', 'avionics',
                'industrial electronics', 'power electronics',
                'electronic components', 'electronic control units',
            ],
        ],
    ];

    /**
     * Non-target sectors whose language triggers a hard veto regardless of
     * how much generic manufacturing vocabulary appears.  Each sector is
     * scored like an evidence family (high 3 / medium 2, capped per keyword);
     * a sector score >= SECTOR_VETO_THRESHOLD vetoes the domain.
     *
     * These are real producers, but they are NOT electronics/industrial
     * equipment buyers — the pipeline's target segment.
     */
    private const NON_TARGET_SECTORS = [
        'chemicals_raw_materials' => [
            'high' => [
                'phosphate mining', 'fertilizer production', 'phosphoric acid',
                'acide phosphorique', 'produits chimiques', 'crop protection',
                'petrochemical', 'chemical operations', 'chemical production',
                'chemical industry', 'refinery',
            ],
            'medium' => [
                'phosphate', 'fertilizer', 'engrais', 'chemicals',
                'polyurethane',
            ],
        ],
        'food_beverage_dairy' => [
            'high' => [
                'produits laitiers', 'nutrition infantile', 'food and beverage',
                'dairy products', 'agroalimentaire', 'food products',
                'meat processing', 'beverage production', 'confectionery',
            ],
            'medium' => [
                'laitiers', 'dairy', 'infant formula', 'food processing',
            ],
        ],
        'tic_services' => [
            'high' => [
                'testing, inspection, and certification',
                'testing, inspection, certification',
                'certification services', 'inspection and certification',
                'inspection services', 'testing services', 'audit services',
                'tic services', 'we certify', 'certify companies',
                'certification body', 'management system standards',
                'testing and certification',
            ],
            'medium' => [
                'conformity assessment', 'quality assurance services',
                'inspection body',
            ],
        ],
        'systems_integration' => [
            'high' => [
                'system integrator', 'systems integrator',
                'systems integration', 'automation services',
                'process automation', 'design and integrate', 'we integrate',
                'integration services', 'scada', 'mes solutions',
                'intégration de systèmes',
            ],
            'medium' => [
                'automation projects', 'integration projects',
            ],
        ],
        'research_centre' => [
            'high' => [
                'our research', 'research covers', 'develops and proves',
                'research centre', 'research center', 'research institute',
                'research and technology organisation',
                'research and technology organization',
            ],
            'medium' => [
                'research activities', 'research teams', 'researchers',
                'research projects', 'research infrastructure',
            ],
        ],
    ];

    private const SECTOR_VETO_THRESHOLD = 4;

    private const WEIGHT_HIGH   = 3;
    private const WEIGHT_MEDIUM = 2;
    private const WEIGHT_LOW    = 1;
    private const WEIGHT_CERT   = 4;

    public function __construct(
        private readonly int $passThreshold = 20,
    ) {
    }

    public function score(CrawledDomain $domain, PageClassification $classification): EvidenceScore
    {
        // Hard veto check
        if (\in_array($classification->getCategory(), self::VETO_CATEGORIES, true)) {
            return new EvidenceScore(
                0,
                false,
                true,
                'Vetoed: domain classified as ' . $classification->getCategory(),
                array_fill_keys(array_keys(self::FAMILIES), 0),
            );
        }

        $allText = mb_strtolower($domain->getAllText());

        $sectorReason = $this->detectSectorVeto($allText);
        if ($sectorReason !== null) {
            return new EvidenceScore(
                0,
                false,
                true,
                $sectorReason,
                array_fill_keys(array_keys(self::FAMILIES), 0),
            );
        }

        $familyScores = [];

        foreach (self::FAMILIES as $familyName => $family) {
            $familyScores[$familyName] = $this->scoreFamily($family, $allText);
        }

        $totalScore = array_sum($familyScores);
        $pass = $totalScore >= $this->passThreshold;

        return new EvidenceScore($totalScore, $pass, false, null, $familyScores);
    }

    /**
     * Detect whether the domain's text signals a non-target sector
     * (chemicals/mining, food/dairy, TIC services, systems integration,
     * research centre).  Returns the veto reason, or null if the text
     * does not strongly belong to any of these sectors.
     */
    private function detectSectorVeto(string $text): ?string
    {
        foreach (self::NON_TARGET_SECTORS as $sector => $tokens) {
            $score = 0;

            foreach ($tokens['high'] ?? [] as $token) {
                $score += min(self::MAX_KEYWORD_HITS, mb_substr_count($text, $token)) * self::WEIGHT_HIGH;
            }
            foreach ($tokens['medium'] ?? [] as $token) {
                $score += min(self::MAX_KEYWORD_HITS, mb_substr_count($text, $token)) * self::WEIGHT_MEDIUM;
            }

            if ($score >= self::SECTOR_VETO_THRESHOLD) {
                return sprintf(
                    'Vetoed: non-target sector %s (signal score %d)',
                    $sector,
                    $score,
                );
            }
        }

        return null;
    }

    private function scoreFamily(array $family, string $text): int
    {
        $cap = $family['cap'];
        $score = 0;

        // Certification family uses a dedicated scoring path
        if (isset($family['certs'])) {
            foreach ($family['certs'] as $cert) {
                if (mb_strpos($text, $cert) !== false) {
                    $score += self::WEIGHT_CERT;
                }
            }
            return min($cap, $score);
        }

        // Keyword scoring
        foreach ($family['high'] ?? [] as $keyword) {
            $hits = min(self::MAX_KEYWORD_HITS, mb_substr_count($text, $keyword));
            $score += $hits * self::WEIGHT_HIGH;
        }
        foreach ($family['medium'] ?? [] as $keyword) {
            $hits = min(self::MAX_KEYWORD_HITS, mb_substr_count($text, $keyword));
            $score += $hits * self::WEIGHT_MEDIUM;
        }
        foreach ($family['low'] ?? [] as $keyword) {
            $hits = min(self::MAX_KEYWORD_HITS, mb_substr_count($text, $keyword));
            $score += $hits * self::WEIGHT_LOW;
        }

        return min($cap, $score);
    }
}
