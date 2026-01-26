<?php

namespace App\Service\WebCrawler;

use Symfony\Component\Yaml\Yaml;
use Psr\Log\LoggerInterface;

/**
 * Intelligent Lead Scoring Engine
 * 
 * Scores leads 0-100 based on relevance signals:
 * - Geo (20): Morocco free zone presence
 * - Manufacturing Fit (20): PCBA/SMT/EMS keywords
 * - Procurement (18): Supplier portal, RFQ, quality requirements
 * - Sector (12): Target industry alignment
 * - Morocco Evidence (15): Sourcing/facility evidence
 * - Contactability (8): Public contact info
 * - Freshness (7): Recent content updates
 * 
 * Target: Precision @ top-50 ≥ 75%
 */
class LeadScoringService
{
    private array $config;
    private array $weights;
    private array $keywords;
    private array $zones;

    public function __construct(
        private LoggerInterface $logger,
        string $configPath = null
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

        $this->config = Yaml::parseFile($path);
        $this->weights = $this->config['weights'] ?? [];
        $this->keywords = $this->config['keywords'] ?? [];
        $this->zones = $this->config['zones'] ?? [];
    }

    /**
     * Score a lead based on extracted features
     * 
     * @param array $lead Lead data with extracted features
     * @return array ['score' => int, 'breakdown' => array, 'recommendation' => string]
     */
    public function scoreLead(array $lead): array
    {
        // Check if we have enough content to score
        $pageContent = $lead['page_content'] ?? '';
        $hasMinimalContent = strlen(trim($pageContent)) >= 100;
        
        // Use fallback scoring if content is empty or minimal
        if (!$hasMinimalContent) {
            return $this->scoreLeadWithFallback($lead);
        }
        
        $breakdown = [];
        $totalScore = 0;

        // 1. Geo Signal (+20): Morocco free zone presence
        $geoScore = $this->scoreGeo($lead);
        $breakdown['geo'] = [
            'score' => $geoScore,
            'weight' => $this->weights['geo'],
            'signals' => $lead['geo_signals'] ?? []
        ];
        $totalScore += $geoScore;

        // 2. Manufacturing Fit (+20): PCBA/SMT/EMS keywords
        $mfgScore = $this->scoreManufacturingFit($lead);
        $breakdown['manufacturing'] = [
            'score' => $mfgScore,
            'weight' => $this->weights['mfg_fit'],
            'signals' => $lead['mfg_signals'] ?? []
        ];
        $totalScore += $mfgScore;

        // 3. Procurement Readiness (+18): Portal, RFQ, quality markers
        $procurementScore = $this->scoreProcurement($lead);
        $breakdown['procurement'] = [
            'score' => $procurementScore,
            'weight' => $this->weights['procurement'],
            'signals' => $lead['procurement_signals'] ?? []
        ];
        $totalScore += $procurementScore;

        // 4. Sector Fit (+12): Target industry alignment
        $sectorScore = $this->scoreSector($lead);
        $breakdown['sector'] = [
            'score' => $sectorScore,
            'weight' => $this->weights['sector'],
            'signals' => $lead['sector_signals'] ?? []
        ];
        $totalScore += $sectorScore;

        // 5. Morocco Evidence (+15): Sourcing/facility evidence
        $moroccoScore = $this->scoreMoroccoEvidence($lead);
        $breakdown['morocco_evidence'] = [
            'score' => $moroccoScore,
            'weight' => $this->weights['morocco_evidence'],
            'signals' => $lead['morocco_evidence'] ?? []
        ];
        $totalScore += $moroccoScore;

        // 6. Contactability (+8): Public contact info
        $contactScore = $this->scoreContactability($lead);
        $breakdown['contactability'] = [
            'score' => $contactScore,
            'weight' => $this->weights['contactability'],
            'signals' => $lead['contact_signals'] ?? []
        ];
        $totalScore += $contactScore;

        // 7. Freshness (+7): Recent content updates
        $freshnessScore = $this->scoreFreshness($lead);
        $breakdown['freshness'] = [
            'score' => $freshnessScore,
            'weight' => $this->weights['freshness'],
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
     */
    private function scoreGeo(array $lead): int
    {
        $pageContent = strtolower($lead['page_content'] ?? '');
        $address = strtolower($lead['address'] ?? '');
        $combined = $pageContent . ' ' . $address;

        $matches = 0;
        foreach ($this->zones['morocco_freezones'] ?? [] as $zone) {
            if (stripos($combined, strtolower($zone)) !== false) {
                $matches++;
            }
        }

        // Full 20 points if any free zone mentioned
        return $matches > 0 ? 20 : 0;
    }

    /**
     * Score manufacturing fit (0-20)
     */
    private function scoreManufacturingFit(array $lead): int
    {
        $pageContent = strtolower($lead['page_content'] ?? '');
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
     */
    private function scoreProcurement(array $lead): int
    {
        $pageContent = strtolower($lead['page_content'] ?? '');
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
     */
    private function scoreSector(array $lead): int
    {
        $pageContent = strtolower($lead['page_content'] ?? '');
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
     * Score Morocco sourcing evidence (0-15)
     */
    private function scoreMoroccoEvidence(array $lead): int
    {
        $score = 0;

        // Facility pages mentioning Morocco
        if (!empty($lead['morocco_facility'])) {
            $score += 7;
        }

        // Job postings in Morocco
        if (!empty($lead['morocco_jobs'])) {
            $score += 5;
        }

        // Press releases/news about Morocco
        if (!empty($lead['morocco_news'])) {
            $score += 3;
        }

        return min(15, $score);
    }

    /**
     * Score contactability (0-8)
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
     */
    private function scoreFreshness(array $lead): int
    {
        $lastModified = $lead['content_last_modified'] ?? null;

        if (!$lastModified) {
            return 0;
        }

        try {
            $modifiedDate = new \DateTime($lastModified);
            $now = new \DateTime();
            $monthsAgo = $now->diff($modifiedDate)->m + ($now->diff($modifiedDate)->y * 12);

            // Full 7 points if updated in last 18 months
            if ($monthsAgo <= 18) {
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
        $recommendThreshold = $this->config['thresholds']['recommend'] ?? 55;
        $dropThreshold = $this->config['thresholds']['drop'] ?? 30;

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
     * @param array $lead Lead data with minimal content
     * @return array Score data with fallback indicators
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
        
        // 5. Region bonus for Morocco (max 10 points)
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
     */
    private function scoreCompanyNameFallback(array $lead): int
    {
        $companyName = strtolower($lead['company_name'] ?? '');
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
     */
    private function scoreMetadataFallback(array $lead): int
    {
        $score = 0;
        
        // Fit signals present
        $fitSignals = $lead['fit_signals'] ?? [];
        if (is_array($fitSignals) && count($fitSignals) > 0) {
            $score += min(10, count($fitSignals) * 2);
        }
        
        // Morocco signal
        if (!empty($lead['morocco_signal'])) {
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
     */
    private function scoreSectorTagsFallback(array $lead): int
    {
        $sectorTags = $lead['sector_tags'] ?? [];
        if (!is_array($sectorTags)) {
            return 0;
        }
        
        $targetSectors = ['automotive', 'aerospace', 'defense', 'medical', 'industrial', 
                          'telecommunications', 'power', 'renewables'];
        
        $matches = 0;
        foreach ($sectorTags as $tag) {
            $tag = strtolower($tag);
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
            $cert = strtolower($cert);
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
     * Score based on region (bonus for Morocco)
     */
    private function scoreRegionFallback(array $lead): int
    {
        $regionTag = strtolower($lead['region_tag'] ?? '');
        $siteLocation = strtolower($lead['site_location'] ?? '');
        
        // Morocco gets full bonus
        if (str_contains($regionTag, 'morocco') || str_contains($siteLocation, 'morocco')) {
            return 10;
        }
        
        // Target regions get partial bonus
        $targetRegions = ['eu_', 'europe', 'us_', 'america', 'uk'];
        foreach ($targetRegions as $target) {
            if (str_contains($regionTag, $target)) {
                return 5;
            }
        }
        
        return 0;
    }
}
