<?php

namespace App\Service\WebCrawler;

use Symfony\Component\Yaml\Yaml;
use Psr\Log\LoggerInterface;

/**
 * Intelligent Lead Scoring Engine
 * 
 * Scores leads 0-100 based on relevance signals (region-agnostic):
 * - Geo (15): Target region presence (any region)
 * - Manufacturing Fit (25): PCBA/SMT/EMS keywords & capabilities
 * - Procurement (20): Supplier portal, RFQ, quality requirements
 * - Sector (15): Target industry alignment
 * - Company Size & Scale (15): Revenue, employee count, operations scale
 * - Contactability (7): Public contact info availability
 * - Freshness (3): Recent content updates
 * 
 * Target: Precision @ top-50 ≥ 75% across all regions
 * Note: Region targeting is handled at discovery layer, not scoring layer
 */
class LeadScoringService
{
    private array $config;
    private array $weights;
    private array $keywords;
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

        $this->config = Yaml::parseFile($path);
        $this->weights = $this->config['weights'] ?? [];
        $this->keywords = $this->config['keywords'] ?? [];
        $this->zones = $this->config['zones'] ?? [];
    }

    /**
     * Score a lead based on extracted features (region-agnostic)
     * 
     * @param array $lead Lead data with extracted features
     * @return array ['score' => int, 'breakdown' => array, 'recommendation' => string]
     */
    public function scoreLead(array $lead): array
    {
        $breakdown = [];
        $totalScore = 0;

        // 1. Geo Signal (+15): Target region presence (any region)
        $geoScore = $this->scoreGeo($lead);
        $breakdown['geo'] = [
            'score' => $geoScore,
            'weight' => 15,
            'signals' => $lead['geo_signals'] ?? []
        ];
        $totalScore += $geoScore;

        // 2. Manufacturing Fit (+25): PCBA/SMT/EMS keywords and capabilities
        $mfgScore = $this->scoreManufacturingFit($lead);
        $breakdown['manufacturing'] = [
            'score' => $mfgScore,
            'weight' => 25,
            'signals' => $lead['mfg_signals'] ?? []
        ];
        $totalScore += $mfgScore;

        // 3. Procurement Readiness (+20): Portal, RFQ, quality markers
        $procurementScore = $this->scoreProcurement($lead);
        $breakdown['procurement'] = [
            'score' => $procurementScore,
            'weight' => 20,
            'signals' => $lead['procurement_signals'] ?? []
        ];
        $totalScore += $procurementScore;

        // 4. Sector Fit (+15): Target industry alignment
        $sectorScore = $this->scoreSector($lead);
        $breakdown['sector'] = [
            'score' => $sectorScore,
            'weight' => 15,
            'signals' => $lead['sector_signals'] ?? []
        ];
        $totalScore += $sectorScore;

        // 5. Company Scale (+15): Revenue, employees, global presence
        $scaleScore = $this->scoreCompanyScale($lead);
        $breakdown['company_scale'] = [
            'score' => $scaleScore,
            'weight' => 15,
            'signals' => $lead['scale_signals'] ?? []
        ];
        $totalScore += $scaleScore;

        // 6. Contactability (+7): Public contact info
        $contactScore = $this->scoreContactability($lead);
        $breakdown['contactability'] = [
            'score' => $contactScore,
            'weight' => 7,
            'signals' => $lead['contact_signals'] ?? []
        ];
        $totalScore += $contactScore;

        // 7. Freshness (+3): Recent content updates
        $freshnessScore = $this->scoreFreshness($lead);
        $breakdown['freshness'] = [
            'score' => $freshnessScore,
            'weight' => 3,
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
     * Score geographic relevance (0-15)
     * Region-agnostic with MEDIUM bonus for Moroccan presence
     */
    private function scoreGeo(array $lead): int
    {
        $pageContent = strtolower($lead['page_content'] ?? '');
        $address = strtolower($lead['address'] ?? '');
        $location = strtolower($lead['location'] ?? '');
        $combined = $pageContent . ' ' . $address . ' ' . $location;

        // Moroccan indicators get bonus points
        $moroccanIndicators = ['morocco', 'tanger', 'casablanca', 'marrakech', 'fez', 'meknes', 'tangier'];
        $hasMoroccoPresence = false;
        foreach ($moroccanIndicators as $indicator) {
            if (stripos($combined, $indicator) !== false) {
                $hasMoroccoPresence = true;
                break;
            }
        }

        // Check for any target region indicators
        $targetRegions = [
            // Africa
            'johannesburg', 'cape town', 'south africa', 'pretoria', 'durban',
            // Europe
            'germany', 'poland', 'czech', 'france', 'italy', 'spain', 'netherlands', 'belgium', 'austria', 'hungary',
            'london', 'manchester', 'scotland', 'midlands', 'yorkshire',
            // USA
            'new york', 'new jersey', 'pennsylvania', 'massachusetts', 'virginia', 'florida',
            'houston', 'dallas', 'austin', 'texas',
            'seattle', 'portland', 'oregon', 'washington'
        ];

        $matches = 0;
        foreach ($targetRegions as $region) {
            if (stripos($combined, $region) !== false) {
                $matches++;
            }
        }

        // Scoring logic: base score + Morocco bonus
        if ($hasMoroccoPresence) {
            // Moroccan presence: medium advantage
            if ($matches >= 1) {
                // Both Morocco AND other regions
                return 15; // Full points + synergy
            } else {
                // Morocco only
                return 12; // Medium advantage (80% of max)
            }
        } else {
            // No Morocco presence
            if ($matches >= 2) {
                return 10; // Other regions present
            } elseif ($matches > 0) {
                return 5;  // Single other region
            }
        }
        
        return 0;
    }

    /**
     * Score manufacturing fit (0-25)
     * Elevated weight: manufacturing capability is primary filter
     */
    private function scoreManufacturingFit(array $lead): int
    {
        $pageContent = strtolower($lead['page_content'] ?? '');
        $uniqueTerms = [];

        $mfgKeywords = [
            'pcba', 'smt', 'ems', 'circuit board', 'assembly', 'manufacturing',
            'fabrication', 'production', 'electronics', 'semiconductor',
            'component', 'soldering', 'surface mount', 'contract manufacturer',
            'odm', 'oem', 'turnkey', 'full-service', 'supply chain'
        ];

        foreach ($mfgKeywords as $keyword) {
            if (stripos($pageContent, $keyword) !== false) {
                $uniqueTerms[] = $keyword;
            }
        }

        // 2.5 points per unique term, max 25
        return min(25, count($uniqueTerms) * 2);
    }

    /**
     * Score procurement readiness (0-20)
     * Shows company has formal sourcing/procurement processes
     */
    private function scoreProcurement(array $lead): int
    {
        $pageContent = strtolower($lead['page_content'] ?? '');
        $markers = 0;

        $procurementKeywords = [
            'procurement', 'purchasing', 'rfq', 'request for quote',
            'supplier', 'vendor', 'sourcing', 'supply chain',
            'quality', 'certification', 'iso', 'compliance',
            'contact us', 'inquiry', 'quote', 'specification'
        ];

        foreach ($procurementKeywords as $keyword) {
            if (stripos($pageContent, $keyword) !== false) {
                $markers++;
            }
        }

        // 2 points per marker, max 20
        return min(20, $markers * 2);
    }

    /**
     * Score sector alignment (0-15)
     */
    private function scoreSector(array $lead): int
    {
        $pageContent = strtolower($lead['page_content'] ?? '');
        $uniqueTerms = [];

        $sectorKeywords = [
            'automotive', 'industrial', 'aerospace', 'rail', 'renewables', 'power electronics',
            'medical devices', 'telecommunications', 'defense', 'energy'
        ];

        foreach ($sectorKeywords as $sector) {
            if (stripos($pageContent, $sector) !== false) {
                $uniqueTerms[] = $sector;
            }
        }

        // 3 points per unique sector, max 15
        return min(15, count($uniqueTerms) * 3);
    }

    /**
     * Score company scale and capability (0-15)
     * Includes bonus for Moroccan operations
     */
    private function scoreCompanyScale(array $lead): int
    {
        $score = 0;
        $pageContent = strtolower($lead['page_content'] ?? '');

        // MOROCCAN BONUS: Check for Moroccan operations/presence
        $moroccanKeywords = ['morocco', 'tanger', 'casablanca', 'marrakech', 'fez', 'meknes',
                            'moroccan facility', 'morocco manufacturing', 'morocco operations',
                            'maroc', 'royaume du maroc'];
        $hasMoroccoOps = false;
        foreach ($moroccanKeywords as $keyword) {
            if (stripos($pageContent, $keyword) !== false) {
                $hasMoroccoOps = true;
                $score += 2; // +2 bonus for Morocco operations
                break;
            }
        }

        // Global operations / multiple facilities
        $globalIndicators = ['global', 'worldwide', 'international', 'multiple locations', 'offices in'];
        foreach ($globalIndicators as $indicator) {
            if (stripos($pageContent, $indicator) !== false) {
                $score += 3;
                break; // Only count once
            }
        }

        // Company size signals
        if (!empty($lead['employee_count'])) {
            $employees = $lead['employee_count'];
            if ($employees > 500) {
                $score += 4;
            } elseif ($employees > 100) {
                $score += 2;
            }
        }

        // Revenue/market presence
        if (!empty($lead['annual_revenue'])) {
            if (stripos($pageContent, 'million') !== false || stripos($pageContent, 'billion') !== false) {
                $score += 3;
            }
        }

        // Established company (founded years ago)
        if (!empty($lead['founded_year'])) {
            $founded = (int)$lead['founded_year'];
            $yearsOld = date('Y') - $founded;
            if ($yearsOld >= 10) {
                $score += 5;
            }
        }

        return min(15, $score);
    }

    /**
     * Score contactability (0-7)
     */
    private function scoreContactability(array $lead): int
    {
        $score = 0;

        // Role-based email
        if (!empty($lead['contact_emails_public'])) {
            $score += 3;
        }

        // Contact form or supplier portal
        if (!empty($lead['contact_form_url']) || !empty($lead['supplier_portal_url'])) {
            $score += 4;
        }

        return min(7, $score);
    }

    /**
     * Score content freshness (0-3)
     * Lower weight: doesn't indicate quality
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

            // 3 points if updated in last 24 months
            if ($monthsAgo <= 24) {
                return 3;
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
}
