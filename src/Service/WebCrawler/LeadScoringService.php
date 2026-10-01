<?php

namespace App\Service\WebCrawler;

use Symfony\Component\Yaml\Yaml;
use Psr\Log\LoggerInterface;

/**
 * Intelligent Lead Scoring Engine
 * 
 * Scores leads 0-100 based on relevance signals:
 * - Geo (12-20): Target region presence (Morocco/US/EU/UK/Egypt/GCC)
 * - Manufacturing Fit (20): PCBA/SMT/EMS keywords
 * - Procurement (18): Supplier portal, RFQ, quality requirements
 * - Sector (12): Target industry alignment
 * - Region Evidence (15): Facility/jobs/news evidence in any target region
 * - Contactability (8): Public contact info
 * - Freshness (7): Recent content updates
 * 
 * Supports multi-region scoring: Morocco, US (East Coast + Texas),
 * EU (Core, Nordics, CEE), UK, Egypt, and GCC — each with config-driven weights.
 *
 * Target: Precision @ top-50 ≥ 75%
 *
 * @phpstan-type ScoreBreakdownEntry array{score: int, weight: int, signals: mixed}
 * @phpstan-type ScoreResult array{score: int, breakdown: array<string, ScoreBreakdownEntry>, recommendation: string, reason: string, fallback_scored?: bool}
 */
class LeadScoringService
{
    /** @var array<string, mixed> */
    private array $config;
    /** @var array<string, int> */
    private array $weights;
    /** @var array<string, list<string>> */
    private array $keywords;
    /** @var array<string, list<string>> */
    private array $zones;

    public function __construct(
        private LoggerInterface $logger,
        ?string $configPath = null
    ) {
        $configPath = $configPath ?? __DIR__ . '/../../../config/crawler_config.yaml';
        $this->loadConfig($configPath);
    }

    /**
     * Load configuration from YAML
     */
    private function loadConfig(string $path): void
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Config file not found: {$path}");
        }

        $parsed = Yaml::parseFile($path);
        if (!is_array($parsed)) {
            throw new \RuntimeException("Invalid crawler config, expected a YAML map: {$path}");
        }
        /** @var array<string, mixed> $parsed */
        $this->config = $parsed;

        $weights = $this->config['weights'] ?? null;
        if (!is_array($weights)) {
            $weights = [];
        }
        /** @var array<string, int> $weights */
        $this->weights = $weights;

        $keywords = $this->config['keywords'] ?? null;
        if (!is_array($keywords)) {
            $keywords = [];
        }
        /** @var array<string, list<string>> $keywords */
        $this->keywords = $keywords;

        $zones = $this->config['zones'] ?? null;
        if (!is_array($zones)) {
            $zones = [];
        }
        /** @var array<string, list<string>> $zones */
        $this->zones = $zones;
    }

    /**
     * Coerce untrusted crawler-extracted values to string.
     * Mirrors PHP weak-mode scalar casts; non-scalars degrade to ''.
     */
    private static function strValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Score a lead based on extracted features
     *
     * @param array<string, mixed> $lead Lead data with extracted features
     * @return ScoreResult
     */
    public function scoreLead(array $lead): array
    {
        // Check if we have enough content to score
        $pageContent = self::strValue($lead['page_content'] ?? null);
        $hasMinimalContent = strlen(trim($pageContent)) >= 100;
        
        // Use fallback scoring if content is empty or minimal
        if (!$hasMinimalContent) {
            return $this->scoreLeadWithFallback($lead);
        }
        
        $breakdown = [];
        $totalScore = 0;

        // 1. Geo Signal (12-20): Target region presence
        $geoScore = $this->scoreGeo($lead);
        $geoMaxWeight = max(
            $this->weights['geo_morocco'] ?? $this->weights['geo'] ?? 20,
            $this->weights['geo_us'] ?? 16,
            $this->weights['geo_eu'] ?? 14,
            $this->weights['geo_uk'] ?? 12,
            $this->weights['geo_egypt'] ?? 14,
            $this->weights['geo_gcc'] ?? 14
        );
        $breakdown['geo'] = [
            'score' => $geoScore,
            'weight' => $geoMaxWeight,
            'signals' => $lead['geo_signals'] ?? []
        ];
        $totalScore += $geoScore;

        // 2. Manufacturing Fit (+20): PCBA/SMT/EMS keywords
        $mfgScore = $this->scoreManufacturingFit($lead);
        $breakdown['manufacturing'] = [
            'score' => $mfgScore,
            'weight' => $this->weights['mfg_fit'] ?? 20,
            'signals' => $lead['mfg_signals'] ?? []
        ];
        $totalScore += $mfgScore;

        // 3. Procurement Readiness (+18): Portal, RFQ, quality markers
        $procurementScore = $this->scoreProcurement($lead);
        $breakdown['procurement'] = [
            'score' => $procurementScore,
            'weight' => $this->weights['procurement'] ?? 18,
            'signals' => $lead['procurement_signals'] ?? []
        ];
        $totalScore += $procurementScore;

        // 4. Sector Fit (+12): Target industry alignment
        $sectorScore = $this->scoreSector($lead);
        $breakdown['sector'] = [
            'score' => $sectorScore,
            'weight' => $this->weights['sector_generic'] ?? $this->weights['sector'] ?? 12,
            'signals' => $lead['sector_signals'] ?? []
        ];
        $totalScore += $sectorScore;

        // 5. Region Evidence (+15): Facility/jobs/news in any target region
        $regionEvidenceScore = $this->scoreRegionEvidence($lead);
        $breakdown['region_evidence'] = [
            'score' => $regionEvidenceScore,
            'weight' => $this->weights['region_evidence'] ?? $this->weights['morocco_evidence'] ?? 15,
            'signals' => $lead['region_evidence'] ?? $lead['morocco_evidence'] ?? []
        ];
        $totalScore += $regionEvidenceScore;

        // 6. Contactability (+8): Public contact info
        $contactScore = $this->scoreContactability($lead);
        $breakdown['contactability'] = [
            'score' => $contactScore,
            'weight' => $this->weights['contactability'] ?? 8,
            'signals' => $lead['contact_signals'] ?? []
        ];
        $totalScore += $contactScore;

        // 7. Freshness (+7): Recent content updates
        $freshnessScore = $this->scoreFreshness($lead);
        $breakdown['freshness'] = [
            'score' => $freshnessScore,
            'weight' => $this->weights['freshness'] ?? 7,
            'signals' => $lead['freshness_signals'] ?? []
        ];
        $totalScore += $freshnessScore;

        // Cap at 100
        $totalScore = min(100, $totalScore);

        // Determine recommendation
        $recommendation = $this->getRecommendation($totalScore);

        return [
            'score' => $totalScore,
            'breakdown' => $breakdown,
            'recommendation' => $recommendation,
            'reason' => $this->generateReason($breakdown, $totalScore)
        ];
    }

    /**
     * Score geographic relevance (0-20)
     * 
     * Checks all configured target regions and returns the
     * highest match score:
     *   Morocco free zones / cities → geo_morocco weight (default 20)
     *   US East Coast / Texas metros → geo_us weight (default 16)
     *   EU countries / TLDs         → geo_eu weight (default 14)
     *   UK                          → geo_uk weight (default 12)
     *   Egypt zones / cities / TLDs → geo_egypt weight (default 14)
     *   GCC free zones / cities / TLDs → geo_gcc weight (default 14)
     *
     * @param array<string, mixed> $lead
     */
    private function scoreGeo(array $lead): int
    {
        $pageContent = strtolower(self::strValue($lead['page_content'] ?? null));
        $address = strtolower(self::strValue($lead['address'] ?? null));
        $regionTag = strtolower(self::strValue($lead['region_tag'] ?? null));
        $siteLocation = strtolower(self::strValue($lead['site_location'] ?? null));
        $combined = $pageContent . ' ' . $address . ' ' . $regionTag . ' ' . $siteLocation;
        $url = strtolower(self::strValue($lead['website_root'] ?? $lead['lead_url'] ?? null));

        $bestScore = 0;
        $regionsCfg = $this->config['regions'] ?? null;
        /** @var array<string, array<string, list<string>>> $regions */
        $regions = is_array($regionsCfg) ? $regionsCfg : [];

        // 1. Morocco free zones + cities
        $moroccoWeight = $this->weights['geo_morocco'] ?? $this->weights['geo'] ?? 20;
        $moroccoLocations = array_merge(
            $this->zones['morocco_freezones'] ?? [],
            $this->zones['morocco_cities'] ?? []
        );
        foreach ($moroccoLocations as $zone) {
            if (stripos($combined, strtolower($zone)) !== false) {
                $bestScore = max($bestScore, $moroccoWeight);
                break;
            }
        }

        // 2. US East Coast states + Texas metros
        $usWeight = $this->weights['geo_us'] ?? 16;
        foreach ($regions['usa']['east_coast_states'] ?? [] as $state) {
            if (preg_match('/\b' . preg_quote(strtolower($state), '/') . '\b/', $combined)) {
                $bestScore = max($bestScore, $usWeight);
                break;
            }
        }
        if ($bestScore < $usWeight) {
            foreach ($regions['usa']['texas_metros'] ?? [] as $metro) {
                if (stripos($combined, strtolower($metro)) !== false) {
                    $bestScore = max($bestScore, $usWeight);
                    break;
                }
            }
        }

        // 3. EU countries + TLDs
        $euWeight = $this->weights['geo_eu'] ?? 14;
        $euCountries = array_merge(
            $regions['eu']['core_countries'] ?? [],
            $regions['eu']['nordics'] ?? [],
            $regions['eu']['cee'] ?? []
        );
        foreach ($euCountries as $country) {
            if (preg_match('/\b' . preg_quote(strtolower($country), '/') . '\b/', $combined)) {
                $bestScore = max($bestScore, $euWeight);
                break;
            }
        }
        if ($bestScore < $euWeight) {
            foreach ($regions['eu']['tlds'] ?? [] as $tld) {
                if (str_contains($url, $tld)) {
                    $bestScore = max($bestScore, $euWeight);
                    break;
                }
            }
        }

        // 4. UK
        $ukWeight = $this->weights['geo_uk'] ?? 12;
        foreach ($regions['uk']['countries'] ?? [] as $ukRegion) {
            if (stripos($combined, strtolower($ukRegion)) !== false) {
                $bestScore = max($bestScore, $ukWeight);
                break;
            }
        }
        if ($bestScore < $ukWeight) {
            foreach ($regions['uk']['tlds'] ?? [] as $tld) {
                if (str_contains($url, $tld)) {
                    $bestScore = max($bestScore, $ukWeight);
                    break;
                }
            }
        }

        // 5. Egypt industrial zones + cities + TLDs
        $egyptWeight = $this->weights['geo_egypt'] ?? 14;
        $egyptLocations = array_merge(
            $this->zones['egypt_zones'] ?? [],
            $this->zones['egypt_cities'] ?? []
        );
        foreach ($egyptLocations as $zone) {
            if (stripos($combined, strtolower($zone)) !== false) {
                $bestScore = max($bestScore, $egyptWeight);
                break;
            }
        }
        if ($bestScore < $egyptWeight) {
            foreach ($regions['egypt']['cities'] ?? [] as $city) {
                if (stripos($combined, strtolower($city)) !== false) {
                    $bestScore = max($bestScore, $egyptWeight);
                    break;
                }
            }
        }
        if ($bestScore < $egyptWeight) {
            foreach ($regions['egypt']['tlds'] ?? [] as $tld) {
                if (str_contains($url, $tld)) {
                    $bestScore = max($bestScore, $egyptWeight);
                    break;
                }
            }
        }

        // 6. GCC free zones + cities + country codes + TLDs
        $gccWeight = $this->weights['geo_gcc'] ?? 14;
        $gccLocations = array_merge(
            $this->zones['gcc_freezones'] ?? [],
            $this->zones['gcc_cities'] ?? []
        );
        foreach ($gccLocations as $zone) {
            if (stripos($combined, strtolower($zone)) !== false) {
                $bestScore = max($bestScore, $gccWeight);
                break;
            }
        }
        if ($bestScore < $gccWeight) {
            foreach ($regions['gcc']['countries'] ?? [] as $country) {
                if (preg_match('/\b' . preg_quote(strtolower($country), '/') . '\b/', $combined)) {
                    $bestScore = max($bestScore, $gccWeight);
                    break;
                }
            }
        }
        if ($bestScore < $gccWeight) {
            foreach ($regions['gcc']['cities'] ?? [] as $city) {
                if (stripos($combined, strtolower($city)) !== false) {
                    $bestScore = max($bestScore, $gccWeight);
                    break;
                }
            }
        }
        if ($bestScore < $gccWeight) {
            foreach ($regions['gcc']['tlds'] ?? [] as $tld) {
                if (str_contains($url, $tld)) {
                    $bestScore = max($bestScore, $gccWeight);
                    break;
                }
            }
        }

        return $bestScore;
    }

    /**
     * Score manufacturing fit (0-20)
     *
     * @param array<string, mixed> $lead
     */
    private function scoreManufacturingFit(array $lead): int
    {
        $pageContent = strtolower(self::strValue($lead['page_content'] ?? null));
        $uniqueTerms = [];

        foreach ($this->keywords['manufacturing'] ?? [] as $keyword) {
            if (stripos($pageContent, strtolower($keyword)) !== false) {
                $uniqueTerms[] = $keyword;
            }
        }

        // 5 points per unique term, max 20
        return min(20, count($uniqueTerms) * 5);
    }

    /**
     * Score procurement readiness (0-18)
     *
     * @param array<string, mixed> $lead
     */
    private function scoreProcurement(array $lead): int
    {
        $pageContent = strtolower(self::strValue($lead['page_content'] ?? null));
        $markers = 0;

        foreach ($this->keywords['procurement'] ?? [] as $keyword) {
            if (stripos($pageContent, strtolower($keyword)) !== false) {
                $markers++;
            }
        }

        // 6 points per marker, max 18
        return min(18, $markers * 6);
    }

    /**
     * Score sector alignment (0-12)
     *
     * @param array<string, mixed> $lead
     */
    private function scoreSector(array $lead): int
    {
        $pageContent = strtolower(self::strValue($lead['page_content'] ?? null));
        $uniqueTerms = [];

        foreach ($this->keywords['sectors'] ?? [] as $sector) {
            if (stripos($pageContent, strtolower($sector)) !== false) {
                $uniqueTerms[] = $sector;
            }
        }

        // 3 points per unique sector, max 12
        return min(12, count($uniqueTerms) * 3);
    }

    /**
     * Score region-specific evidence (0-15)
     * 
     * Awards points for evidence of real operations in any target region:
     *   Facility pages mentioning the region  (+7)
     *   Job postings in the region             (+5)
     *   Press releases / news about the region (+3)
     * 
     * Accepts both legacy morocco_* fields and generic *_evidence fields.
     *
     * @param array<string, mixed> $lead
     */
    private function scoreRegionEvidence(array $lead): int
    {
        $score = 0;

        // Facility evidence (legacy morocco_facility OR generic facility_evidence)
        if (!empty($lead['morocco_facility']) || !empty($lead['facility_evidence'])) {
            $score += 7;
        }

        // Jobs evidence (legacy morocco_jobs OR generic jobs_evidence)
        if (!empty($lead['morocco_jobs']) || !empty($lead['jobs_evidence'])) {
            $score += 5;
        }

        // News / PR evidence (legacy morocco_news OR generic news_evidence)
        if (!empty($lead['morocco_news']) || !empty($lead['news_evidence'])) {
            $score += 3;
        }

        return min(15, $score);
    }

    /**
     * Score contactability (0-8)
     *
     * @param array<string, mixed> $lead
     */
    private function scoreContactability(array $lead): int
    {
        $score = 0;

        // Role-based email
        if (!empty($lead['contact_emails_public'])) {
            $score += 4;
        }

        // Contact form with procurement option
        if (!empty($lead['contact_form_url'])) {
            $score += 2;
        }

        // Supplier portal
        if (!empty($lead['supplier_portal_url'])) {
            $score += 2;
        }

        return min(8, $score);
    }

    /**
     * Score content freshness (0-7)
     *
     * @param array<string, mixed> $lead
     */
    private function scoreFreshness(array $lead): int
    {
        $lastModified = $lead['content_last_modified'] ?? null;

        if (!is_string($lastModified) || $lastModified === '') {
            return 0;
        }

        try {
            $modifiedDate = new \DateTime($lastModified);
            $now = new \DateTime();
            $monthsAgo = $now->diff($modifiedDate)->m + ($now->diff($modifiedDate)->y * 12);

            // Full 7 points if updated in the last 24 months.
            // B2B contact pages stay relevant well beyond a year; the wider
            // window keeps scoring stable as crawl timestamps age.
            if ($monthsAgo <= 24) {
                return 7;
            }

            return 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get recommendation based on score
     */
    private function getRecommendation(int $score): string
    {
        $thresholdsCfg = $this->config['thresholds'] ?? null;
        /** @var array<string, int> $thresholds */
        $thresholds = is_array($thresholdsCfg) ? $thresholdsCfg : [];
        $recommendThreshold = $thresholds['recommend'] ?? 55;
        $dropThreshold = $thresholds['drop'] ?? 30;

        if ($score >= $recommendThreshold) {
            return 'approve';
        } elseif ($score < $dropThreshold) {
            return 'drop';
        } else {
            return 'review';
        }
    }

    /**
     * Generate human-readable reason
     *
     * @param array<string, ScoreBreakdownEntry> $breakdown
     */
    private function generateReason(array $breakdown, int $totalScore): string
    {
        $reasons = [];

        foreach ($breakdown as $category => $data) {
            if ($data['score'] > 0 && !empty($data['signals'])) {
                $reasons[] = ucfirst($category) . ' (' . $data['score'] . ')';
            }
        }

        if (empty($reasons)) {
            return "Low relevance (Score: {$totalScore})";
        }

        return implode(', ', $reasons) . " → Total: {$totalScore}";
    }

    /**
     * Batch score multiple leads
     *
     * @param list<array<string, mixed>> $leads
     * @return list<array<string, mixed>>
     */
    public function scoreLeads(array $leads): array
    {
        $scored = [];

        foreach ($leads as $lead) {
            $scoreData = $this->scoreLead($lead);
            $scored[] = array_merge($lead, [
                'lead_score' => $scoreData['score'],
                'score_breakdown' => $scoreData['breakdown'],
                'recommendation' => $scoreData['recommendation'],
                'score_reason' => $scoreData['reason']
            ]);
        }

        // Sort by score descending
        usort($scored, fn($a, $b) => $b['lead_score'] <=> $a['lead_score']);

        return $scored;
    }

    /**
     * Calculate precision @ top-N
     *
     * @param list<array<string, mixed>> $scoredLeads
     * @param list<mixed> $approvedLeadIds
     */
    public function calculatePrecision(array $scoredLeads, array $approvedLeadIds, int $topN = 50): float
    {
        $topLeads = array_slice($scoredLeads, 0, $topN);
        $approved = 0;

        foreach ($topLeads as $lead) {
            if (in_array($lead['lead_id'], $approvedLeadIds)) {
                $approved++;
            }
        }

        return count($topLeads) > 0 ? ($approved / count($topLeads)) : 0.0;
    }
    
    /**
     * Fallback scoring when page content is empty or minimal
     * 
     * Uses alternative signals like:
     * - Company name patterns (EMS indicators)
     * - Domain patterns
     * - Pre-extracted metadata
     * - Sector tags
     * - Quality certifications
     * 
     * @param array<string, mixed> $lead Lead data with minimal content
     * @return ScoreResult Score data with fallback indicators
     */
    private function scoreLeadWithFallback(array $lead): array
    {
        $breakdown = [];
        $totalScore = 0;
        
        $this->logger->info('Using fallback scoring - minimal content', [
            'company' => $lead['company_name'] ?? 'unknown',
            'url' => $lead['website_root'] ?? $lead['lead_url'] ?? 'unknown',
        ]);
        
        // 1. Company name analysis (max 15 points)
        $companyScore = $this->scoreCompanyNameFallback($lead);
        $breakdown['company_name'] = [
            'score' => $companyScore,
            'weight' => 15,
            'signals' => ['Fallback: company name analysis'],
        ];
        $totalScore += $companyScore;
        
        // 2. Pre-extracted metadata (max 20 points)
        $metadataScore = $this->scoreMetadataFallback($lead);
        $breakdown['metadata'] = [
            'score' => $metadataScore,
            'weight' => 20,
            'signals' => ['Fallback: pre-extracted metadata'],
        ];
        $totalScore += $metadataScore;
        
        // 3. Sector tags if available (max 12 points)
        $sectorScore = $this->scoreSectorTagsFallback($lead);
        $breakdown['sector'] = [
            'score' => $sectorScore,
            'weight' => 12,
            'signals' => $lead['sector_tags'] ?? ['Fallback: sector tags'],
        ];
        $totalScore += $sectorScore;
        
        // 4. Quality certifications if available (max 10 points)
        $qualityScore = $this->scoreQualityStackFallback($lead);
        $breakdown['quality'] = [
            'score' => $qualityScore,
            'weight' => 10,
            'signals' => $lead['quality_stack'] ?? ['Fallback: quality stack'],
        ];
        $totalScore += $qualityScore;
        
        // 5. Region bonus — any target region (max 10 points)
        $regionScore = $this->scoreRegionFallback($lead);
        $breakdown['region'] = [
            'score' => $regionScore,
            'weight' => 10,
            'signals' => ['Fallback: region analysis'],
        ];
        $totalScore += $regionScore;
        
        // 6. Contactability if available (max 8 points)
        $contactScore = $this->scoreContactability($lead);
        $breakdown['contactability'] = [
            'score' => $contactScore,
            'weight' => 8,
            'signals' => ['Available contact info'],
        ];
        $totalScore += $contactScore;
        
        // Cap at 75 (can never get full score without content analysis)
        $totalScore = min(75, $totalScore);
        
        // Mark as requiring review since it's fallback scored
        $recommendation = $totalScore >= 45 ? 'review' : 'drop';
        
        return [
            'score' => $totalScore,
            'breakdown' => $breakdown,
            'recommendation' => $recommendation,
            'reason' => 'Fallback scoring (limited content) → Score: ' . $totalScore,
            'fallback_scored' => true,
        ];
    }
    
    /**
     * Score based on company name patterns
     *
     * @param array<string, mixed> $lead
     */
    private function scoreCompanyNameFallback(array $lead): int
    {
        $companyName = strtolower(self::strValue($lead['company_name'] ?? null));
        if (empty($companyName)) {
            return 0;
        }
        
        $score = 0;
        
        // EMS/manufacturing indicators in name
        $emsIndicators = ['ems', 'electronics', 'pcb', 'circuit', 'assembly', 'manufacturing', 
                          'tech', 'systems', 'solutions', 'industrial', 'automotive'];
        
        foreach ($emsIndicators as $indicator) {
            if (str_contains($companyName, $indicator)) {
                $score += 5;
            }
        }
        
        return min(15, $score);
    }
    
    /**
     * Score based on pre-extracted metadata
     *
     * @param array<string, mixed> $lead
     */
    private function scoreMetadataFallback(array $lead): int
    {
        $score = 0;
        
        // Fit signals present
        $fitSignals = $lead['fit_signals'] ?? [];
        if (is_array($fitSignals) && count($fitSignals) > 0) {
            $score += min(10, count($fitSignals) * 2);
        }
        
        // Region signal (legacy morocco_signal or generic region_signal)
        if (!empty($lead['morocco_signal']) || !empty($lead['region_signal'])) {
            $score += 5;
        }
        
        // Supplier portal URL present
        if (!empty($lead['supplier_portal_url'])) {
            $score += 3;
        }
        
        // RFQ page present
        if (!empty($lead['rfq_rfp_page_url'])) {
            $score += 2;
        }
        
        return min(20, $score);
    }
    
    /**
     * Score based on pre-extracted sector tags
     *
     * @param array<string, mixed> $lead
     */
    private function scoreSectorTagsFallback(array $lead): int
    {
        $sectorTags = $lead['sector_tags'] ?? [];
        if (!is_array($sectorTags)) {
            return 0;
        }
        
        $targetSectors = ['automotive', 'aerospace', 'defense', 'medical', 'industrial', 
                          'telecom', 'power electronics', 'renewables', 'rail', 'hvac', 'marine',
                          'consumer electronics', 'data center', 'energy storage'];
        
        $matches = 0;
        foreach ($sectorTags as $tag) {
            $tag = strtolower(self::strValue($tag));
            foreach ($targetSectors as $target) {
                if (str_contains($tag, $target)) {
                    $matches++;
                    break;
                }
            }
        }
        
        return min(12, $matches * 4);
    }
    
    /**
     * Score based on quality certifications
     *
     * @param array<string, mixed> $lead
     */
    private function scoreQualityStackFallback(array $lead): int
    {
        $qualityStack = $lead['quality_stack'] ?? [];
        if (!is_array($qualityStack)) {
            return 0;
        }
        
        $valuableCerts = ['iatf 16949', 'as9100', 'iso 13485', 'iso 9001', 'nadcap', 'itar'];
        
        $matches = 0;
        foreach ($qualityStack as $cert) {
            $cert = strtolower(self::strValue($cert));
            foreach ($valuableCerts as $valuable) {
                if (str_contains($cert, $valuable)) {
                    $matches++;
                    break;
                }
            }
        }
        
        return min(10, $matches * 3);
    }
    
    /**
     * Score based on region — all target regions scored equally
     *
     * @param array<string, mixed> $lead
     */
    private function scoreRegionFallback(array $lead): int
    {
        $regionTag = strtolower(self::strValue($lead['region_tag'] ?? null));
        $siteLocation = strtolower(self::strValue($lead['site_location'] ?? null));
        $combined = $regionTag . ' ' . $siteLocation;
        
        // All target regions get the same bonus
        $targetRegions = [
            'morocco', 'eu_', 'europe', 'us_', 'america',
            'uk', 'england', 'scotland', 'wales',
            'egypt', 'cairo', 'suez', 'alexandria',
            'gcc', 'dubai', 'uae', 'abu dhabi', 'saudi', 'riyadh',
            'qatar', 'doha', 'kuwait', 'oman', 'muscat', 'bahrain', 'manama',
        ];
        foreach ($targetRegions as $target) {
            if (str_contains($combined, $target)) {
                return 10;
            }
        }
        
        return 0;
    }
}
