<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

/**
 * Deterministic domain classifier using weighted lexical rules,
 * schema.org types, and certification tokens.
 *
 * Replaces any LLM-based classification with pure rule-based scoring.
 */
final class PageClassifier
{
    private const WEIGHT_HIGH   = 3;
    private const WEIGHT_MEDIUM = 2;
    private const WEIGHT_LOW    = 1;
    private const WEIGHT_CERT   = 4;
    private const WEIGHT_SCHEMA = 5;

    /** Maximum times a single keyword can count. */
    private const MAX_KEYWORD_HITS = 3;

    private const CATEGORIES = [
        'manufacturer',
        'oem_tier',
        'distributor',
        'directory',
        'media',
        'association',
        'government',
        'recruiter',
        'consultant',
        'competitor_ems',
    ];

    // ──────────────────────────────────────────────────────────────────
    //  Keyword lexicons per category
    // ──────────────────────────────────────────────────────────────────

    private const KEYWORDS = [
        'manufacturer' => [
            'high' => [
                'we manufacture', 'we are a manufacturer', 'manufacturing company',
                'our factory', 'our production facility', 'production line',
                'machining center', 'stamping press', 'injection molding',
                'die casting', 'our manufacturing',
                'designs and manufactures', 'develops and manufactures',
                'manufactures and supplies', 'manufactures and distributes',
                // French
                'conçoit et fabrique', 'conçoit et produit', 'nous fabriquons',
                'notre usine', 'ligne de production',
                // German
                'entwickelt und fertigt', 'entwickelt und produziert',
                'wir fertigen', 'wir produzieren', 'unsere fabrik',
                // Spanish
                'diseña y fabrica', 'fabricamos',
            ],
            'medium' => [
                'manufacturer', 'manufacturing', 'manufactures', 'manufactured',
                'fabrication', 'machining',
                'cnc', 'stamping', 'forging', 'casting', 'molding',
                'extrusion', 'tooling', 'heat treatment', 'surface treatment',
                'coating', 'plating', 'welding', 'turning', 'milling',
                'sheet metal', 'metal parts', 'precision parts',
                // French
                'fabrique', 'fabricant', 'fabrication', 'produit',
                // German
                'fertigt', 'hersteller', 'fertigung', 'produziert',
                // Spanish
                'fabrica', 'fabricante', 'fabricación',
            ],
            'low' => [
                'production', 'assembly', 'quality control', 'inspection',
                'raw materials', 'prototype', 'factory', 'production facility',
                'produces', 'produce',
            ],
        ],
        'oem_tier' => [
            'high' => [
                'tier 1 supplier', 'tier 2 supplier', 'oem supplier',
                'automotive supplier', 'original equipment manufacturer',
                'system integrator',
                'our products include', 'product portfolio',
                'our product range', 'product range includes',
            ],
            'medium' => [
                'oem', 'tier 1', 'tier 2', 'automotive parts',
                'original equipment', 'vehicle components',
                'technology company', 'product range', 'product line',
                'develops and supplies', 'develops pioneering',
            ],
            'low' => [
                'automotive', 'car parts', 'vehicle', 'powertrain',
            ],
        ],
        'distributor' => [
            'high' => [
                'we distribute', 'authorized distributor', 'wholesale distribution',
                'distribution network', 'our distribution',
            ],
            'medium' => [
                'distributor', 'distribution', 'wholesale', 'reseller',
                'dealer', 'stockist', 'supply chain solutions',
            ],
            'low' => [
                'logistics', 'inventory', 'delivery', 'shipping',
            ],
        ],
        'directory' => [
            'high' => [
                'business directory', 'company directory', 'find companies',
                'search companies', 'company listings', 'company profiles',
                'company database', 'b2b marketplace', 'b2b e-commerce platform',
                'connecting buyers with', 'find suppliers',
            ],
            'medium' => [
                'directory', 'listing', 'business search',
                'yellow pages', 'industry directory',
                'marketplace', 'platform connecting',
            ],
            'low' => [],
        ],
        'media' => [
            'high' => [
                'subscribe to our newsletter', 'press release', 'editorial team',
                'our journalists', 'our editors',
            ],
            'medium' => [
                'news', 'magazine', 'journal', 'publication',
                'editor', 'reporter', 'article',
            ],
            'low' => [
                'blog', 'subscribe', 'newsletter',
            ],
        ],
        'association' => [
            'high' => [
                'trade association', 'industry association', 'member companies',
                'annual conference', 'membership network',
                'international industrial trade fair', 'industrial trade fair',
                'trade fair featuring', 'salon international',
            ],
            'medium' => [
                'association', 'federation', 'chamber', 'trade body',
                'trade show', 'industry event', 'membership',
                'trade fair', 'industrial fair', 'exhibition',
            ],
            'low' => [],
        ],
        'government' => [
            'high' => [
                'government agency', 'ministry of', 'department of',
                'public administration',
                'promotes foreign investment', 'investment authority',
                'government incentives', 'tax credits', 'grant funding',
            ],
            'medium' => [
                'government', 'public sector', 'regulation', 'policy',
                'free zones', 'investment promotion',
            ],
            'low' => [],
        ],
        'recruiter' => [
            'high' => [
                'recruitment agency', 'staffing agency', 'talent acquisition',
                'headhunter', 'employment agency', 'headhunting',
            ],
            'medium' => [
                'recruitment', 'staffing', 'hiring', 'job placement',
            ],
            'low' => [
                'career opportunities', 'vacancies',
            ],
        ],
        'consultant' => [
            'high' => [
                'consulting firm', 'management consulting', 'advisory services',
                'consultancy',
                'testing, inspection, and certification',
                'inspection and certification', 'certification services',
                'iot consulting', 'digital twin solutions',
                'industry reports', 'market size', 'market research',
                'market forecast',
            ],
            'medium' => [
                'consulting', 'advisory', 'strategy', 'professional services',
                'inspection services', 'audit services',
                'testing services',
            ],
            'low' => [],
        ],
        'competitor_ems' => [
            'high' => [
                'electronics manufacturing services', 'ems provider',
                'contract electronics manufacturer', 'pcb assembly services',
                'prototype to production',
                'manufacturing services company', 'contract manufacturer',
                'manufacturing partner', 'manufacturing services',
                'contract manufacturing', 'electronics manufacturing',
            ],
            'medium' => [
                'pcb assembly', 'smt assembly', 'electronic assembly',
                'contract electronics', 'box build', 'turnkey ems',
                'system supplier and contract', 'ems activities',
                'product realization services',
            ],
            'low' => [
                'smt', 'pcba', 'electronics contract',
            ],
        ],
    ];

    /** Certifications that boost the manufacturer score. */
    private const CERTIFICATIONS = [
        'iso 9001', 'iatf 16949', 'as9100', 'en 9100',
        'iso 13485', 'iso 14001', 'iso 45001', 'nadcap',
        'iso/ts 16949',
    ];

    /** Schema.org types that boost specific categories. */
    private const SCHEMA_TYPES = [
        'manufacturer' => ['Manufacturer'],
        'oem_tier'     => ['AutoPartsStore'],
        'distributor'  => [],
        'directory'    => ['WebSite', 'ItemList'],
        'media'        => ['NewsMediaOrganization', 'Newspaper'],
        'association'  => ['NGO'],
        'government'   => ['GovernmentOrganization', 'GovernmentOffice'],
        'recruiter'    => ['EmploymentAgency'],
        'consultant'   => [],
        'competitor_ems' => [],
    ];

    /**
     * Classify a domain based on all its crawled page content.
     */
    public function classify(CrawledDomain $domain): PageClassification
    {
        $allText = mb_strtolower($domain->getAllText());
        $structuredData = $domain->getAllStructuredData();

        if ($allText === '' && empty($structuredData)) {
            return new PageClassification('unknown', 0.0, array_fill_keys(self::CATEGORIES, 0));
        }

        // Score every category
        $scores = [];
        foreach (self::CATEGORIES as $category) {
            $scores[$category] = $this->scoreCategory($category, $allText, $structuredData);
        }

        // Find winner
        arsort($scores);
        $topCategory = array_key_first($scores);
        $topScore = $scores[$topCategory];

        if ($topScore === 0) {
            return new PageClassification('unknown', 0.0, $scores);
        }

        $totalScore = array_sum($scores);
        $confidence = $totalScore > 0 ? round($topScore / $totalScore, 3) : 0.0;

        return new PageClassification($topCategory, $confidence, $scores);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Scoring engine
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param array<int, array<string, mixed>> $structuredData
     */
    private function scoreCategory(string $category, string $text, array $structuredData): int
    {
        $score = 0;

        // Keyword scoring
        $keywords = self::KEYWORDS[$category] ?? [];
        foreach ($keywords['high'] ?? [] as $keyword) {
            $hits = min(self::MAX_KEYWORD_HITS, mb_substr_count($text, $keyword));
            $score += $hits * self::WEIGHT_HIGH;
        }
        foreach ($keywords['medium'] ?? [] as $keyword) {
            $hits = min(self::MAX_KEYWORD_HITS, mb_substr_count($text, $keyword));
            $score += $hits * self::WEIGHT_MEDIUM;
        }
        foreach ($keywords['low'] ?? [] as $keyword) {
            $hits = min(self::MAX_KEYWORD_HITS, mb_substr_count($text, $keyword));
            $score += $hits * self::WEIGHT_LOW;
        }

        // Certification scoring (manufacturer only)
        if ($category === 'manufacturer') {
            foreach (self::CERTIFICATIONS as $cert) {
                if (mb_strpos($text, $cert) !== false) {
                    $score += self::WEIGHT_CERT;
                }
            }
        }

        // Schema.org type scoring
        $schemaTypes = self::SCHEMA_TYPES[$category] ?? [];
        if (!empty($schemaTypes)) {
            foreach ($structuredData as $item) {
                $type = $item['@type'] ?? '';
                if (\is_string($type) && \in_array($type, $schemaTypes, true)) {
                    $score += self::WEIGHT_SCHEMA;
                }
                // Handle @type as array
                if (\is_array($type)) {
                    foreach ($type as $t) {
                        if (\in_array($t, $schemaTypes, true)) {
                            $score += self::WEIGHT_SCHEMA;
                            break;
                        }
                    }
                }
            }
        }

        return $score;
    }
}
