<?php

namespace App\Service;

use App\Entity\Lead;
use App\Entity\Company;
use Psr\Log\LoggerInterface;

/**
 * LeadBot Sales Analyst Service
 * 
 * Analyzes leads to generate intelligent conversation starters for sales reps.
 * 
 * This service compares:
 * 1. Lead's capabilities (fitSignals) with our company's capabilities
 * 2. Lead's quality certifications with our certifications
 * 3. Lead's sector focus with our target sectors
 * 4. Market signals and hiring patterns to identify pain points
 * 
 * Outputs:
 * - Fit Score (0-100): How well the lead matches our capabilities
 * - Conversation Starters: Specific talking points based on analysis
 * - Pain Point Predictions: Likely challenges the lead is facing
 * - Competitive Positioning: How to position against competitors
 * 
 * Example output:
 * "They use AS9100 standards but their careers page shows 5 QA Engineer openings.
 *  They may be struggling with QA bandwidth. Pitch our QA-verified assembly services."
 */
class LeadSalesAnalystService
{
    // Our company's capabilities (would be configurable in production)
    private const OUR_CAPABILITIES = [
        'pcba' => true,
        'smt' => true,
        'through_hole' => true,
        'cable_assembly' => true,
        'box_build' => true,
        'testing' => true,
        'prototyping' => true,
        'npi' => true,           // New Product Introduction
        'bom_sourcing' => true,
        'dfa_review' => true,    // Design for Assembly
        'conformal_coating' => true,
        'x_ray_inspection' => true,
        'aoi' => true,           // Automated Optical Inspection
        'functional_test' => true,
    ];
    
    // Our certifications
    private const OUR_CERTIFICATIONS = [
        'ISO 9001',
        'ISO 14001',
        'IATF 16949',    // Automotive
        'AS9100',        // Aerospace
        'ISO 13485',     // Medical
        'IPC-A-610',     // Electronics Assembly
        'J-STD-001',     // Soldering
        'UL',
        'CE',
        'RoHS',
    ];
    
    // Our target sectors
    private const TARGET_SECTORS = [
        'automotive',
        'aerospace',
        'defense',
        'medical',
        'industrial',
        'telecommunications',
        'power_electronics',
        'renewables',
    ];
    
    // Pain point indicators based on signals
    private const PAIN_POINT_INDICATORS = [
        'qa_struggles' => [
            'signals' => ['quality_hiring', 'recall_news', 'certification_pending'],
            'pitch' => 'Quality-certified production with 100% AOI and X-ray inspection',
        ],
        'capacity_constraints' => [
            'signals' => ['production_hiring', 'overtime_mentions', 'delivery_complaints'],
            'pitch' => 'Scalable capacity to handle demand spikes without compromising quality',
        ],
        'cost_pressure' => [
            'signals' => ['margin_pressure', 'cost_reduction_initiatives', 'procurement_hiring'],
            'pitch' => 'Cost-optimized sourcing with multi-distributor price comparison',
        ],
        'supply_chain_risk' => [
            'signals' => ['supply_chain_mentions', 'shortage_news', 'diversification_interest'],
            'pitch' => 'Morocco-based manufacturing for supply chain diversification and nearshoring',
        ],
        'time_to_market' => [
            'signals' => ['npi_mentions', 'fast_prototyping', 'startup', 'product_launch'],
            'pitch' => 'Rapid NPI services with 48-hour quick-turn prototyping',
        ],
        'compliance_complexity' => [
            'signals' => ['regulatory_changes', 'audit_mentions', 'compliance_hiring'],
            'pitch' => 'Full traceability and compliance documentation included',
        ],
    ];
    
    public function __construct(
        private LoggerInterface $logger
    ) {}
    
    /**
     * Analyze a lead and generate sales intelligence
     * 
     * @param Lead $lead The lead to analyze
     * @return array Comprehensive sales analysis
     */
    public function analyzeLead(Lead $lead): array
    {
        $fitSignals = $lead->getFitSignals() ?? [];
        $qualityStack = $lead->getQualityStack() ?? [];
        $sectorTags = $lead->getSectorTags() ?? [];
        $notesAuto = $lead->getNotesAuto() ?? '';
        
        // Calculate fit scores
        $capabilityFit = $this->calculateCapabilityFit($fitSignals);
        $certificationFit = $this->calculateCertificationFit($qualityStack);
        $sectorFit = $this->calculateSectorFit($sectorTags);
        
        // Overall fit score (weighted average)
        $overallFit = (
            ($capabilityFit['score'] * 0.4) +
            ($certificationFit['score'] * 0.35) +
            ($sectorFit['score'] * 0.25)
        );
        
        // Identify pain points from notes and signals
        $painPoints = $this->identifyPainPoints($notesAuto, $fitSignals, $sectorTags);
        
        // Generate conversation starters
        $conversationStarters = $this->generateConversationStarters(
            $lead,
            $capabilityFit,
            $certificationFit,
            $sectorFit,
            $painPoints
        );
        
        // Generate competitive positioning
        $competitivePositioning = $this->generateCompetitivePositioning($lead, $sectorTags);
        
        // Identify key decision makers to target
        $decisionMakerTargets = $this->identifyDecisionMakerTargets($fitSignals, $sectorTags);
        
        // Generate email opener suggestion
        $emailOpener = $this->generateEmailOpener($lead, $conversationStarters);
        
        // Risk assessment
        $dealRisks = $this->assessDealRisks($lead, $fitSignals, $qualityStack);
        
        $analysis = [
            'lead_id' => $lead->getId(),
            'company_name' => $lead->getCompanyName(),
            'overall_fit_score' => round($overallFit, 1),
            'fit_grade' => $this->scoreToGrade($overallFit),
            'fit_breakdown' => [
                'capability_fit' => $capabilityFit,
                'certification_fit' => $certificationFit,
                'sector_fit' => $sectorFit,
            ],
            'pain_points' => $painPoints,
            'conversation_starters' => $conversationStarters,
            'competitive_positioning' => $competitivePositioning,
            'decision_maker_targets' => $decisionMakerTargets,
            'email_opener' => $emailOpener,
            'deal_risks' => $dealRisks,
            'recommended_approach' => $this->determineApproach($overallFit, $painPoints),
            'priority_score' => $this->calculatePriorityScore($lead, $overallFit, $painPoints),
            'next_steps' => $this->generateNextSteps($lead, $overallFit, $painPoints),
            'analyzed_at' => (new \DateTime())->format('c'),
        ];
        
        $this->logger->info('Lead analyzed for sales intelligence', [
            'lead_id' => $lead->getId(),
            'company' => $lead->getCompanyName(),
            'fit_score' => $overallFit,
            'pain_points_count' => count($painPoints),
        ]);
        
        return $analysis;
    }
    
    /**
     * Calculate capability fit between lead's needs and our offerings
     */
    private function calculateCapabilityFit(array $fitSignals): array
    {
        $matchedCapabilities = [];
        $unmatchedNeeds = [];
        $additionalOfferings = [];
        
        foreach ($fitSignals as $capability => $hasNeed) {
            if (!$hasNeed) {
                continue;
            }
            
            $normalizedCapability = $this->normalizeCapabilityName($capability);
            
            if (isset(self::OUR_CAPABILITIES[$normalizedCapability]) && self::OUR_CAPABILITIES[$normalizedCapability]) {
                $matchedCapabilities[] = $capability;
            } else {
                $unmatchedNeeds[] = $capability;
            }
        }
        
        // Find capabilities we offer that they didn't mention (upsell opportunities)
        foreach (self::OUR_CAPABILITIES as $capability => $offered) {
            if ($offered) {
                $found = false;
                foreach ($fitSignals as $leadCap => $hasNeed) {
                    if ($this->normalizeCapabilityName($leadCap) === $capability) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $additionalOfferings[] = $capability;
                }
            }
        }
        
        $totalNeeds = count(array_filter($fitSignals));
        $score = $totalNeeds > 0 
            ? (count($matchedCapabilities) / $totalNeeds) * 100 
            : 50; // Default if no needs identified
        
        return [
            'score' => round($score, 1),
            'matched' => $matchedCapabilities,
            'unmatched_needs' => $unmatchedNeeds,
            'upsell_opportunities' => array_slice($additionalOfferings, 0, 5),
        ];
    }
    
    /**
     * Calculate certification alignment
     */
    private function calculateCertificationFit(array $qualityStack): array
    {
        $matchedCerts = [];
        $additionalCerts = [];
        $missingCerts = [];
        
        foreach ($qualityStack as $cert) {
            $normalizedCert = $this->normalizeCertification($cert);
            $matched = false;
            
            foreach (self::OUR_CERTIFICATIONS as $ourCert) {
                if (str_contains(strtolower($ourCert), strtolower($normalizedCert)) ||
                    str_contains(strtolower($normalizedCert), strtolower($ourCert))) {
                    $matchedCerts[] = $cert;
                    $matched = true;
                    break;
                }
            }
            
            if (!$matched) {
                $missingCerts[] = $cert;
            }
        }
        
        // Certifications we have that they may value
        foreach (self::OUR_CERTIFICATIONS as $ourCert) {
            $found = false;
            foreach ($qualityStack as $theirCert) {
                if (str_contains(strtolower($ourCert), strtolower($theirCert)) ||
                    str_contains(strtolower($theirCert), strtolower($ourCert))) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $additionalCerts[] = $ourCert;
            }
        }
        
        $totalCerts = count($qualityStack);
        $score = $totalCerts > 0 
            ? (count($matchedCerts) / $totalCerts) * 100 
            : 70; // Default assumption
        
        return [
            'score' => round($score, 1),
            'matched' => $matchedCerts,
            'we_offer_additionally' => array_slice($additionalCerts, 0, 5),
            'they_require_we_lack' => $missingCerts,
        ];
    }
    
    /**
     * Calculate sector alignment
     */
    private function calculateSectorFit(array $sectorTags): array
    {
        $matchedSectors = [];
        
        foreach ($sectorTags as $sector) {
            $normalizedSector = strtolower(str_replace([' ', '-', '_'], '', $sector));
            
            foreach (self::TARGET_SECTORS as $targetSector) {
                $normalizedTarget = strtolower(str_replace([' ', '-', '_'], '', $targetSector));
                
                if (str_contains($normalizedSector, $normalizedTarget) ||
                    str_contains($normalizedTarget, $normalizedSector)) {
                    $matchedSectors[] = $sector;
                    break;
                }
            }
        }
        
        $totalSectors = count($sectorTags);
        $score = $totalSectors > 0 
            ? (count($matchedSectors) / $totalSectors) * 100 
            : 50;
        
        // Boost score if they're in our prime sectors
        $primeSectors = ['aerospace', 'automotive', 'defense'];
        foreach ($sectorTags as $sector) {
            foreach ($primeSectors as $prime) {
                if (str_contains(strtolower($sector), $prime)) {
                    $score = min(100, $score + 15);
                    break 2;
                }
            }
        }
        
        return [
            'score' => round($score, 1),
            'matched_sectors' => $matchedSectors,
            'primary_sector' => $sectorTags[0] ?? 'Unknown',
        ];
    }
    
    /**
     * Identify likely pain points from signals and notes
     */
    private function identifyPainPoints(string $notesAuto, array $fitSignals, array $sectorTags): array
    {
        $painPoints = [];
        $notesLower = strtolower($notesAuto);
        
        // Check for QA struggles
        if (str_contains($notesLower, 'quality') || 
            str_contains($notesLower, 'qa engineer') ||
            str_contains($notesLower, 'inspection') ||
            str_contains($notesLower, 'recall')) {
            $painPoints[] = [
                'type' => 'qa_struggles',
                'confidence' => 'high',
                'evidence' => 'Quality-related content detected on website/careers page',
                'pitch' => self::PAIN_POINT_INDICATORS['qa_struggles']['pitch'],
            ];
        }
        
        // Check for capacity constraints
        if (str_contains($notesLower, 'hiring') ||
            str_contains($notesLower, 'growing') ||
            str_contains($notesLower, 'expansion')) {
            $painPoints[] = [
                'type' => 'capacity_constraints',
                'confidence' => 'medium',
                'evidence' => 'Growth/hiring indicators suggest capacity needs',
                'pitch' => self::PAIN_POINT_INDICATORS['capacity_constraints']['pitch'],
            ];
        }
        
        // Check for supply chain concerns
        if (str_contains($notesLower, 'supply chain') ||
            str_contains($notesLower, 'sourcing') ||
            str_contains($notesLower, 'shortage') ||
            str_contains($notesLower, 'nearshore') ||
            str_contains($notesLower, 'diversif')) {
            $painPoints[] = [
                'type' => 'supply_chain_risk',
                'confidence' => 'high',
                'evidence' => 'Supply chain related content detected',
                'pitch' => self::PAIN_POINT_INDICATORS['supply_chain_risk']['pitch'],
            ];
        }
        
        // Check for time-to-market pressure (common in certain sectors)
        if (in_array('startup', array_map('strtolower', $sectorTags)) ||
            str_contains($notesLower, 'prototype') ||
            str_contains($notesLower, 'fast') ||
            str_contains($notesLower, 'rapid')) {
            $painPoints[] = [
                'type' => 'time_to_market',
                'confidence' => 'medium',
                'evidence' => 'Speed/prototyping emphasis detected',
                'pitch' => self::PAIN_POINT_INDICATORS['time_to_market']['pitch'],
            ];
        }
        
        // Sector-specific pain points
        foreach ($sectorTags as $sector) {
            $sectorLower = strtolower($sector);
            
            if (str_contains($sectorLower, 'medical')) {
                $painPoints[] = [
                    'type' => 'compliance_complexity',
                    'confidence' => 'high',
                    'evidence' => 'Medical sector requires stringent compliance',
                    'pitch' => 'ISO 13485 certified production with full traceability and DHR documentation',
                ];
            }
            
            if (str_contains($sectorLower, 'defense') || str_contains($sectorLower, 'aerospace')) {
                $painPoints[] = [
                    'type' => 'compliance_complexity',
                    'confidence' => 'high',
                    'evidence' => 'Defense/Aerospace sector requires AS9100/ITAR compliance',
                    'pitch' => 'AS9100 certified with full traceability, serialization, and counterfeit prevention',
                ];
            }
        }
        
        // Deduplicate by type
        $seen = [];
        $uniquePainPoints = [];
        foreach ($painPoints as $pp) {
            if (!in_array($pp['type'], $seen)) {
                $seen[] = $pp['type'];
                $uniquePainPoints[] = $pp;
            }
        }
        
        return $uniquePainPoints;
    }
    
    /**
     * Generate conversation starters based on analysis
     */
    private function generateConversationStarters(
        Lead $lead,
        array $capabilityFit,
        array $certificationFit,
        array $sectorFit,
        array $painPoints
    ): array {
        $starters = [];
        
        // Pain point based starters
        foreach ($painPoints as $painPoint) {
            $starters[] = [
                'type' => 'pain_point',
                'topic' => $painPoint['type'],
                'opener' => $this->generatePainPointOpener($painPoint),
                'follow_up' => $painPoint['pitch'],
                'priority' => $painPoint['confidence'] === 'high' ? 1 : 2,
            ];
        }
        
        // Capability match starters
        if (!empty($capabilityFit['matched'])) {
            $capabilities = implode(', ', array_slice($capabilityFit['matched'], 0, 3));
            $starters[] = [
                'type' => 'capability_match',
                'topic' => 'shared_capabilities',
                'opener' => "I noticed you're focused on {$capabilities}. That's exactly our specialty.",
                'follow_up' => "We've helped similar companies reduce time-to-market by 30%.",
                'priority' => 2,
            ];
        }
        
        // Certification alignment starters
        if (!empty($certificationFit['matched'])) {
            $certs = implode(', ', array_slice($certificationFit['matched'], 0, 2));
            $starters[] = [
                'type' => 'certification_match',
                'topic' => 'quality_standards',
                'opener' => "Your {$certs} standards align perfectly with our certified processes.",
                'follow_up' => 'We maintain these certifications across all our production lines.',
                'priority' => 3,
            ];
        }
        
        // Upsell opportunity starters
        if (!empty($capabilityFit['upsell_opportunities'])) {
            $upsell = $capabilityFit['upsell_opportunities'][0];
            $starters[] = [
                'type' => 'upsell',
                'topic' => 'additional_services',
                'opener' => "Beyond your current needs, have you considered {$upsell}?",
                'follow_up' => 'Many clients find this reduces their total cost of ownership.',
                'priority' => 4,
            ];
        }
        
        // Morocco/nearshoring angle if applicable
        if ($lead->getMoroccoSignal()) {
            $starters[] = [
                'type' => 'geographic',
                'topic' => 'morocco_presence',
                'opener' => "I see you already have presence in Morocco. We're located in Tangier Free Zone.",
                'follow_up' => 'Having a local partner could streamline your supply chain.',
                'priority' => 2,
            ];
        }
        
        // Sort by priority
        usort($starters, fn($a, $b) => $a['priority'] <=> $b['priority']);
        
        return $starters;
    }
    
    /**
     * Generate pain point specific opener
     */
    private function generatePainPointOpener(array $painPoint): string
    {
        return match($painPoint['type']) {
            'qa_struggles' => "Quality control is increasingly challenging in today's market. Are you looking to strengthen your QA processes?",
            'capacity_constraints' => "Congratulations on the growth I see you're experiencing. How are you handling the capacity demands?",
            'cost_pressure' => "Component costs have been volatile lately. Are you exploring ways to optimize your procurement?",
            'supply_chain_risk' => "Supply chain diversification is on every OEM's mind. Have you considered nearshoring options?",
            'time_to_market' => "Speed to market can make or break a product launch. How's your current NPI timeline?",
            'compliance_complexity' => "Regulatory requirements keep getting more complex. How are you managing compliance documentation?",
            default => "I noticed an area where we might be able to help. Can we discuss your current challenges?",
        };
    }
    
    /**
     * Generate competitive positioning advice
     */
    private function generateCompetitivePositioning(Lead $lead, array $sectorTags): array
    {
        $positioning = [];
        
        // Morocco advantage
        $positioning[] = [
            'differentiator' => 'Geographic Arbitrage',
            'message' => 'Morocco Free Zone location offers EU tariff benefits with competitive costs',
            'vs_competitors' => 'Unlike Asian suppliers, we offer same-day communication and 3-day shipping to EU',
        ];
        
        // Technology advantage
        $positioning[] = [
            'differentiator' => 'Quote Technology',
            'message' => 'Real-time BOM pricing with multi-distributor comparison',
            'vs_competitors' => 'Get accurate quotes in hours, not days',
        ];
        
        // Sector-specific positioning
        foreach ($sectorTags as $sector) {
            $sectorLower = strtolower($sector);
            
            if (str_contains($sectorLower, 'auto')) {
                $positioning[] = [
                    'differentiator' => 'Automotive Excellence',
                    'message' => 'IATF 16949 certified with PPAP documentation',
                    'vs_competitors' => 'Full automotive qualification support included',
                ];
                break;
            }
            
            if (str_contains($sectorLower, 'aero') || str_contains($sectorLower, 'defense')) {
                $positioning[] = [
                    'differentiator' => 'Aerospace Compliance',
                    'message' => 'AS9100 certified with serialization and full traceability',
                    'vs_competitors' => 'Counterfeit prevention program and First Article support',
                ];
                break;
            }
        }
        
        return $positioning;
    }
    
    /**
     * Identify decision maker targets
     */
    private function identifyDecisionMakerTargets(array $fitSignals, array $sectorTags): array
    {
        $targets = [];
        
        // Default targets
        $targets[] = [
            'role' => 'VP of Supply Chain',
            'why' => 'Key decision maker for contract manufacturing',
            'approach' => 'Lead with cost and risk reduction',
        ];
        
        $targets[] = [
            'role' => 'Director of Engineering',
            'why' => 'Influences supplier selection for new products',
            'approach' => 'Lead with technical capabilities and NPI support',
        ];
        
        // Sector-specific targets
        foreach ($sectorTags as $sector) {
            if (str_contains(strtolower($sector), 'medical')) {
                $targets[] = [
                    'role' => 'VP of Quality/Regulatory',
                    'why' => 'Critical for medical device compliance',
                    'approach' => 'Lead with ISO 13485 and regulatory expertise',
                ];
                break;
            }
        }
        
        return array_slice($targets, 0, 3);
    }
    
    /**
     * Generate email opener suggestion
     */
    private function generateEmailOpener(Lead $lead, array $conversationStarters): string
    {
        $companyName = $lead->getCompanyName();
        
        if (!empty($conversationStarters)) {
            $topStarter = $conversationStarters[0];
            $opener = $topStarter['opener'];
            
            return "Subject: Supporting {$companyName}'s Manufacturing Goals\n\n" .
                   "Hi [Name],\n\n" .
                   "{$opener}\n\n" .
                   "{$topStarter['follow_up']}\n\n" .
                   "Would you have 15 minutes this week to explore if there's a fit?\n\n" .
                   "Best regards";
        }
        
        return "Subject: Partnership Opportunity with {$companyName}\n\n" .
               "Hi [Name],\n\n" .
               "I came across {$companyName} and was impressed by your work in electronics manufacturing.\n\n" .
               "We specialize in contract manufacturing with a focus on quality and quick turnaround.\n\n" .
               "Would you be open to a brief call to see if we might be able to support your growth?\n\n" .
               "Best regards";
    }
    
    /**
     * Assess potential deal risks
     */
    private function assessDealRisks(Lead $lead, array $fitSignals, array $qualityStack): array
    {
        $risks = [];
        
        // Certification gaps
        $missingCritical = [];
        foreach ($qualityStack as $cert) {
            $normalized = strtolower($cert);
            $weHave = false;
            
            foreach (self::OUR_CERTIFICATIONS as $ourCert) {
                if (str_contains(strtolower($ourCert), $normalized) ||
                    str_contains($normalized, strtolower($ourCert))) {
                    $weHave = true;
                    break;
                }
            }
            
            if (!$weHave && (str_contains($normalized, 'nadcap') || str_contains($normalized, 'itar'))) {
                $missingCritical[] = $cert;
            }
        }
        
        if (!empty($missingCritical)) {
            $risks[] = [
                'type' => 'certification_gap',
                'severity' => 'high',
                'description' => 'They require ' . implode(', ', $missingCritical) . ' which we may not have',
                'mitigation' => 'Verify if these are hard requirements or preferences',
            ];
        }
        
        // Competition risk
        if ($lead->getReviewStatus() === 'pending' && $lead->getLeadScore() && $lead->getLeadScore() > 80) {
            $risks[] = [
                'type' => 'competition',
                'severity' => 'medium',
                'description' => 'High-value lead likely being pursued by competitors',
                'mitigation' => 'Act quickly and differentiate on response time',
            ];
        }
        
        // Defense sector risk
        if ($lead->getDefenseFlag()) {
            $risks[] = [
                'type' => 'compliance',
                'severity' => 'medium',
                'description' => 'Defense work may require ITAR/export controls',
                'mitigation' => 'Clarify export control requirements early',
            ];
        }
        
        return $risks;
    }
    
    /**
     * Determine recommended sales approach
     */
    private function determineApproach(float $fitScore, array $painPoints): string
    {
        if ($fitScore >= 80 && !empty($painPoints)) {
            return 'AGGRESSIVE: High fit with identified pain points. Prioritize immediate outreach.';
        }
        
        if ($fitScore >= 70) {
            return 'STANDARD: Good fit. Follow normal sales cadence with personalized messaging.';
        }
        
        if ($fitScore >= 50) {
            return 'NURTURE: Moderate fit. Add to nurture campaign and monitor for trigger events.';
        }
        
        return 'LOW PRIORITY: Limited fit. Consider only if capacity allows.';
    }
    
    /**
     * Calculate priority score for lead ranking
     */
    private function calculatePriorityScore(Lead $lead, float $fitScore, array $painPoints): int
    {
        $priority = (int) $fitScore;
        
        // Boost for identified pain points
        $priority += count($painPoints) * 5;
        
        // Boost for high lead score
        if ($lead->getLeadScore() && $lead->getLeadScore() > 70) {
            $priority += 10;
        }
        
        // Boost for Morocco presence
        if ($lead->getMoroccoSignal()) {
            $priority += 15;
        }
        
        // Boost for defense (high-value)
        if ($lead->getDefenseFlag()) {
            $priority += 10;
        }
        
        // Boost for contact information availability
        if ($lead->hasContactInfo()) {
            $priority += 5;
        }
        
        return min(100, $priority);
    }
    
    /**
     * Generate recommended next steps
     */
    private function generateNextSteps(Lead $lead, float $fitScore, array $painPoints): array
    {
        $steps = [];
        
        if ($fitScore >= 70) {
            $steps[] = [
                'action' => 'research',
                'description' => 'Research key contacts on LinkedIn',
                'timeframe' => 'Today',
            ];
            
            $steps[] = [
                'action' => 'outreach',
                'description' => 'Send personalized email to VP Supply Chain',
                'timeframe' => 'Within 24 hours',
            ];
        }
        
        if (!empty($painPoints)) {
            $steps[] = [
                'action' => 'prepare',
                'description' => 'Prepare case study relevant to their pain points',
                'timeframe' => 'Before first call',
            ];
        }
        
        if ($lead->getWebsiteRoot()) {
            $steps[] = [
                'action' => 'monitor',
                'description' => 'Set up news alert for company',
                'timeframe' => 'Ongoing',
            ];
        }
        
        $steps[] = [
            'action' => 'crm',
            'description' => 'Update lead status and notes in CRM',
            'timeframe' => 'After each interaction',
        ];
        
        return $steps;
    }
    
    /**
     * Convert score to letter grade
     */
    private function scoreToGrade(float $score): string
    {
        if ($score >= 90) return 'A';
        if ($score >= 80) return 'B';
        if ($score >= 70) return 'C';
        if ($score >= 60) return 'D';
        return 'F';
    }
    
    /**
     * Normalize capability name for matching
     */
    private function normalizeCapabilityName(string $capability): string
    {
        $mapping = [
            'pcb_assembly' => 'pcba',
            'pcb assembly' => 'pcba',
            'surface_mount' => 'smt',
            'surface mount' => 'smt',
            'tht' => 'through_hole',
            'wire_harness' => 'cable_assembly',
            'system_integration' => 'box_build',
            'ict' => 'testing',
            'functional_testing' => 'functional_test',
        ];
        
        $normalized = strtolower(str_replace([' ', '-'], '_', $capability));
        
        return $mapping[$normalized] ?? $normalized;
    }
    
    /**
     * Normalize certification name for matching
     */
    private function normalizeCertification(string $cert): string
    {
        return trim(preg_replace('/[:\-\s]+/', ' ', $cert));
    }
    
    /**
     * Batch analyze multiple leads
     */
    public function analyzeMultipleLeads(array $leads): array
    {
        $results = [];
        
        foreach ($leads as $lead) {
            $results[] = $this->analyzeLead($lead);
        }
        
        // Sort by priority score descending
        usort($results, fn($a, $b) => $b['priority_score'] <=> $a['priority_score']);
        
        return $results;
    }
}
