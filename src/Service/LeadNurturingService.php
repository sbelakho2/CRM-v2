<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Lead;
use App\Entity\Company;
use App\Entity\Activity;
use App\Entity\Contact;
use App\Repository\LeadRepository;
use App\Repository\CompanyRepository;
use App\Repository\ActivityRepository;
use App\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lead Nurturing Automation Service
 * 
 * Handles automated lead progression and nurturing workflows:
 * - Automatic stage advancement based on engagement
 * - Follow-up scheduling based on inactivity
 * - Lead scoring updates based on activities
 * - Nurturing campaign triggers
 */
class LeadNurturingService
{
    // Nurturing stages
    public const STAGE_NEW = 'new';
    public const STAGE_CONTACTED = 'contacted';
    public const STAGE_ENGAGED = 'engaged';
    public const STAGE_QUALIFIED = 'qualified';
    public const STAGE_OPPORTUNITY = 'opportunity';
    public const STAGE_CONVERTED = 'converted';
    public const STAGE_DORMANT = 'dormant';
    public const STAGE_LOST = 'lost';
    
    public const VALID_NURTURING_STAGES = [
        self::STAGE_NEW,
        self::STAGE_CONTACTED,
        self::STAGE_ENGAGED,
        self::STAGE_QUALIFIED,
        self::STAGE_OPPORTUNITY,
        self::STAGE_CONVERTED,
        self::STAGE_DORMANT,
        self::STAGE_LOST,
    ];
    
    // Activity types that indicate engagement (match Activity entity type values)
    private const ENGAGEMENT_ACTIVITIES = [
        'Email' => 5,
        'Call' => 20,
        'Meeting' => 30,
        'Site Visit' => 50,
        'Follow-up' => 15,
    ];
    
    // Inactivity thresholds (days)
    private const INACTIVITY_THRESHOLDS = [
        self::STAGE_NEW => 3,          // 3 days without contact
        self::STAGE_CONTACTED => 7,    // 7 days after first contact
        self::STAGE_ENGAGED => 14,     // 14 days without engagement
        self::STAGE_QUALIFIED => 21,   // 21 days without progress
        self::STAGE_OPPORTUNITY => 30, // 30 days stale opportunity
    ];
    
    // Score thresholds for stage transitions
    private const SCORE_THRESHOLDS = [
        self::STAGE_CONTACTED => 10,   // Min score to move from new
        self::STAGE_ENGAGED => 25,     // Min score for engaged
        self::STAGE_QUALIFIED => 50,   // Min score for qualified
        self::STAGE_OPPORTUNITY => 75, // Min score for opportunity
    ];
    
    private EntityManagerInterface $entityManager;
    private LeadRepository $leadRepository;
    private CompanyRepository $companyRepository;
    private ActivityRepository $activityRepository;
    private ContactRepository $contactRepository;
    private LoggerInterface $logger;
    
    public function __construct(
        EntityManagerInterface $entityManager,
        LeadRepository $leadRepository,
        CompanyRepository $companyRepository,
        ActivityRepository $activityRepository,
        ContactRepository $contactRepository,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->leadRepository = $leadRepository;
        $this->companyRepository = $companyRepository;
        $this->activityRepository = $activityRepository;
        $this->contactRepository = $contactRepository;
        $this->logger = $logger;
    }
    
    /**
     * Process nurturing rules for all active leads
     * 
     * @return array Summary of actions taken
     */
    public function processAllLeads(): array
    {
        $summary = [
            'processed' => 0,
            'stage_advanced' => 0,
            'marked_dormant' => 0,
            'followups_scheduled' => 0,
            'scores_updated' => 0,
        ];
        
        // Get all leads that are not converted, lost, or already in CRM
        $leads = $this->leadRepository->findBy([
            'alreadyInCrm' => false,
            'reviewStatus' => 'approved',
        ]);
        
        foreach ($leads as $lead) {
            $this->processLead($lead, $summary);
        }
        
        $this->entityManager->flush();
        
        $this->logger->info('Lead nurturing complete', $summary);
        
        return $summary;
    }
    
    /**
     * Process nurturing rules for a single lead
     */
    public function processLead(Lead $lead, ?array &$summary = null): array
    {
        if ($summary === null) {
            $summary = [
                'processed' => 0,
                'stage_advanced' => 0,
                'marked_dormant' => 0,
                'followups_scheduled' => 0,
                'scores_updated' => 0,
            ];
        }
        
        $summary['processed']++;
        
        $actions = [];
        $currentStage = $this->determineNurturingStage($lead);
        
        // Persist the computed nurturing stage to the DB
        if ($lead->getNurturingStage() !== $currentStage) {
            $lead->setNurturingStage($currentStage);
        }
        
        // 1. Check for engagement and update score
        $engagementScore = $this->calculateEngagementScore($lead);
        if ($engagementScore !== $lead->getLeadScore()) {
            $lead->setLeadScore($engagementScore);
            $summary['scores_updated']++;
            $actions[] = sprintf('Score updated to %d', $engagementScore);
        }
        
        // 2. Check for stage advancement
        $newStage = $this->evaluateStageAdvancement($lead, $currentStage, $engagementScore);
        if ($newStage !== $currentStage) {
            // Persist new stage to the Lead entity
            $lead->setNurturingStage($newStage);
            
            if ($newStage === self::STAGE_DORMANT) {
                $summary['marked_dormant']++;
                $actions[] = 'Marked as dormant due to inactivity';
            } else {
                // Persist stage change to company pipeline
                $company = $lead->getCompany();
                if ($company) {
                    $stageMap = [
                        self::STAGE_CONTACTED => Company::STAGE_MQL,
                        self::STAGE_ENGAGED => Company::STAGE_SQL,
                        self::STAGE_QUALIFIED => Company::STAGE_SQO,
                        self::STAGE_OPPORTUNITY => Company::STAGE_PROPOSAL,
                    ];
                    if (isset($stageMap[$newStage])) {
                        $company->setPipelineStage($stageMap[$newStage]);
                    }
                }
                $summary['stage_advanced']++;
                $actions[] = sprintf('Stage advanced from %s to %s', $currentStage, $newStage);
            }
        }
        
        // 3. Check if follow-up is needed
        $followupNeeded = $this->checkFollowupNeeded($lead, $currentStage);
        if ($followupNeeded) {
            $summary['followups_scheduled']++;
            $actions[] = $followupNeeded['reason'];
        }
        
        return $actions;
    }
    
    /**
     * Calculate engagement score based on activities
     */
    public function calculateEngagementScore(Lead $lead): int
    {
        $baseScore = 0; // Start fresh to avoid score inflation on repeated runs
        $engagementBonus = 0;
        
        // Get company associated with this lead
        $company = $lead->getCompany();
        if (!$company) {
            // Try to find by website
            $company = $this->companyRepository->findOneBy(['websiteRoot' => $lead->getWebsiteRoot()]);
        }
        
        if ($company) {
            // Get activities for the company in last 30 days
            $activities = $this->activityRepository->findRecentByCompany($company, 30);
            
            foreach ($activities as $activity) {
                $type = $activity->getType();
                if (isset(self::ENGAGEMENT_ACTIVITIES[$type])) {
                    $engagementBonus += self::ENGAGEMENT_ACTIVITIES[$type];
                }
            }
        }
        
        // Calculate based on lead signals
        $fitSignals = $lead->getFitSignals() ?? [];
        $qualityStack = $lead->getQualityStack() ?? [];
        
        // Fit signals bonus (max 20)
        $signalBonus = min(20, count($fitSignals) * 3);
        
        // Quality certifications bonus (max 15)
        $certBonus = min(15, count($qualityStack) * 3);
        
        // Contact availability bonus
        $contactBonus = 0;
        if ($lead->getContactEmailsPublic() && count($lead->getContactEmailsPublic()) > 0) {
            $contactBonus += 10;
        }
        if ($lead->getHasContactForm()) {
            $contactBonus += 5;
        }
        if ($lead->getRfqRfpPageUrl()) {
            $contactBonus += 10;
        }
        
        // Sector alignment bonus (max 15)
        $sectorBonus = 0;
        $sectorTags = $lead->getSectorTags() ?? [];
        $targetSectors = ['automotive', 'aerospace', 'industrial', 'defense', 'medical', 'rail', 'renewables', 'telecom', 'hvac', 'marine', 'power electronics', 'consumer electronics', 'data center', 'energy storage'];
        foreach ($sectorTags as $sector) {
            if (in_array(strtolower($sector), $targetSectors)) {
                $sectorBonus += 5;
            }
        }
        $sectorBonus = min(15, $sectorBonus);
        
        $totalScore = $baseScore + $engagementBonus + $signalBonus + $certBonus + $contactBonus + $sectorBonus;
        
        // Cap at 100
        return min(100, max(0, $totalScore));
    }
    
    /**
     * Determine current nurturing stage based on lead state
     */
    public function determineNurturingStage(Lead $lead): string
    {
        // Check if converted (already in CRM)
        if ($lead->getAlreadyInCrm()) {
            return self::STAGE_CONVERTED;
        }
        
        // Check if lost (denied)
        if ($lead->getReviewStatus() === 'denied') {
            return self::STAGE_LOST;
        }
        
        $company = $lead->getCompany();
        if (!$company) {
            return self::STAGE_NEW;
        }
        
        // Check company pipeline stage
        $pipelineStage = $company->getPipelineStage();
        if ($pipelineStage === Company::STAGE_AWARD) {
            return self::STAGE_CONVERTED;
        }
        if (in_array($pipelineStage, [Company::STAGE_SQO, Company::STAGE_PROPOSAL])) {
            return self::STAGE_OPPORTUNITY;
        }
        if ($pipelineStage === Company::STAGE_SQL) {
            return self::STAGE_QUALIFIED;
        }
        
        // Check for activity history
        $activities = $this->activityRepository->findBy(['company' => $company], ['createdAt' => 'DESC'], 10);
        
        if (empty($activities)) {
            return self::STAGE_NEW;
        }
        
        // Check for engagement indicators
        $hasEngagement = false;
        foreach ($activities as $activity) {
            $type = $activity->getType();
            if (in_array($type, ['Email', 'Call', 'Meeting', 'Site Visit', 'Follow-up'])) {
                $hasEngagement = true;
                break;
            }
        }
        
        if ($hasEngagement) {
            return self::STAGE_ENGAGED;
        }
        
        return self::STAGE_CONTACTED;
    }
    
    /**
     * Evaluate if lead should advance to next stage
     */
    public function evaluateStageAdvancement(Lead $lead, string $currentStage, int $score): string
    {
        // Check for inactivity (could mark as dormant)
        $lastActivity = $this->getLastActivityDate($lead);
        if ($lastActivity) {
            $daysSinceActivity = $lastActivity->diff(new \DateTime())->days;
            $threshold = self::INACTIVITY_THRESHOLDS[$currentStage] ?? 30;
            
            if ($daysSinceActivity > $threshold && $currentStage !== self::STAGE_NEW) {
                return self::STAGE_DORMANT;
            }
        }
        
        // Check score-based advancement
        switch ($currentStage) {
            case self::STAGE_NEW:
                if ($score >= self::SCORE_THRESHOLDS[self::STAGE_CONTACTED]) {
                    return self::STAGE_CONTACTED;
                }
                break;
                
            case self::STAGE_CONTACTED:
                if ($score >= self::SCORE_THRESHOLDS[self::STAGE_ENGAGED]) {
                    return self::STAGE_ENGAGED;
                }
                break;
                
            case self::STAGE_ENGAGED:
                if ($score >= self::SCORE_THRESHOLDS[self::STAGE_QUALIFIED]) {
                    return self::STAGE_QUALIFIED;
                }
                break;
                
            case self::STAGE_QUALIFIED:
                if ($score >= self::SCORE_THRESHOLDS[self::STAGE_OPPORTUNITY]) {
                    return self::STAGE_OPPORTUNITY;
                }
                break;
        }
        
        return $currentStage;
    }
    
    /**
     * Check if follow-up is needed and return reason
     */
    public function checkFollowupNeeded(Lead $lead, string $stage): ?array
    {
        $lastActivity = $this->getLastActivityDate($lead);
        
        if (!$lastActivity) {
            if ($stage === self::STAGE_NEW) {
                return [
                    'reason' => 'New lead needs initial outreach',
                    'priority' => 'high',
                    'suggested_action' => 'email',
                    'suggested_template' => 'initial_outreach',
                ];
            }
            return null;
        }
        
        $daysSince = $lastActivity->diff(new \DateTime())->days;
        $threshold = self::INACTIVITY_THRESHOLDS[$stage] ?? 14;
        
        // Follow up if approaching inactivity threshold (80% of threshold)
        $warningThreshold = intval($threshold * 0.8);
        
        if ($daysSince >= $warningThreshold && $daysSince < $threshold) {
            return [
                'reason' => sprintf('No activity in %d days - follow-up recommended', $daysSince),
                'priority' => 'medium',
                'suggested_action' => $this->suggestFollowupAction($stage),
                'suggested_template' => $this->suggestFollowupTemplate($stage),
            ];
        }
        
        return null;
    }
    
    /**
     * Get leads that need follow-up
     * 
     * @return array Array of leads with follow-up recommendations
     */
    public function getLeadsNeedingFollowup(int $limit = 50): array
    {
        $results = [];
        
        $leads = $this->leadRepository->findBy([
            'alreadyInCrm' => false,
            'reviewStatus' => 'approved',
        ], ['leadScore' => 'DESC'], $limit);
        
        foreach ($leads as $lead) {
            $stage = $this->determineNurturingStage($lead);
            $followup = $this->checkFollowupNeeded($lead, $stage);
            
            if ($followup) {
                $results[] = [
                    'lead' => $lead,
                    'stage' => $stage,
                    'followup' => $followup,
                ];
            }
        }
        
        // Sort by priority
        usort($results, function ($a, $b) {
            $priorities = ['high' => 0, 'medium' => 1, 'low' => 2];
            return ($priorities[$a['followup']['priority']] ?? 2) <=> ($priorities[$b['followup']['priority']] ?? 2);
        });
        
        return $results;
    }
    
    /**
     * Get stalled leads (in same stage too long)
     */
    public function getStalledLeads(int $limit = 50): array
    {
        $results = [];
        
        $leads = $this->leadRepository->findBy([
            'alreadyInCrm' => false,
            'reviewStatus' => 'approved',
        ], ['updatedAt' => 'ASC'], $limit);
        
        foreach ($leads as $lead) {
            $stage = $this->determineNurturingStage($lead);
            
            if ($stage === self::STAGE_DORMANT) {
                $results[] = [
                    'lead' => $lead,
                    'stage' => $stage,
                    'reason' => 'Marked dormant due to inactivity',
                    'reactivation_suggestion' => $this->suggestReactivation($lead),
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Reactivate a dormant lead
     */
    public function reactivateLead(Lead $lead): array
    {
        $actions = [];
        
        // Reset to contacted stage
        $score = $this->calculateEngagementScore($lead);
        
        // Boost score slightly for reactivation
        $newScore = min(100, $score + 5);
        $lead->setLeadScore($newScore);
        $lead->setUpdatedAt(new \DateTime());
        
        $this->entityManager->flush();
        
        $actions[] = 'Lead reactivated';
        $actions[] = sprintf('Score adjusted to %d', $newScore);
        
        return $actions;
    }
    
    /**
     * Get nurturing analytics summary
     */
    public function getNurturingSummary(): array
    {
        $stages = [];
        foreach (self::VALID_NURTURING_STAGES as $stage) {
            $stages[$stage] = 0;
        }
        
        $leads = $this->leadRepository->findBy([
            'reviewStatus' => 'approved',
        ]);
        
        $totalScore = 0;
        $scoreCount = 0;
        
        foreach ($leads as $lead) {
            $stage = $this->determineNurturingStage($lead);
            $stages[$stage]++;
            
            if ($lead->getLeadScore() !== null) {
                $totalScore += $lead->getLeadScore();
                $scoreCount++;
            }
        }
        
        return [
            'total_leads' => count($leads),
            'by_stage' => $stages,
            'average_score' => $scoreCount > 0 ? round($totalScore / $scoreCount, 1) : 0,
            'needs_followup' => count($this->getLeadsNeedingFollowup()),
            'stalled' => count($this->getStalledLeads()),
        ];
    }
    
    // Private helpers
    
    private function getLastActivityDate(Lead $lead): ?\DateTime
    {
        $company = $lead->getCompany();
        if (!$company) {
            $company = $this->companyRepository->findOneBy(['websiteRoot' => $lead->getWebsiteRoot()]);
        }
        
        if (!$company) {
            return null;
        }
        
        $activity = $this->activityRepository->findOneBy(
            ['company' => $company],
            ['createdAt' => 'DESC']
        );
        
        if ($activity) {
            $date = $activity->getCreatedAt();
            return $date instanceof \DateTime ? $date : \DateTime::createFromInterface($date);
        }
        
        return null;
    }
    
    private function suggestFollowupAction(string $stage): string
    {
        return match ($stage) {
            self::STAGE_NEW => 'email',
            self::STAGE_CONTACTED => 'call',
            self::STAGE_ENGAGED => 'meeting',
            self::STAGE_QUALIFIED => 'proposal',
            self::STAGE_OPPORTUNITY => 'meeting',
            default => 'email',
        };
    }
    
    private function suggestFollowupTemplate(string $stage): string
    {
        return match ($stage) {
            self::STAGE_NEW => 'initial_outreach',
            self::STAGE_CONTACTED => 'follow_up_call',
            self::STAGE_ENGAGED => 'schedule_meeting',
            self::STAGE_QUALIFIED => 'send_proposal',
            self::STAGE_OPPORTUNITY => 'check_in',
            default => 'general_follow_up',
        };
    }
    
    private function suggestReactivation(Lead $lead): string
    {
        $sectorTags = $lead->getSectorTags() ?? [];
        
        if (in_array('automotive', $sectorTags)) {
            return 'Try automotive industry news angle - automotive quality compliance offer';
        }
        if (in_array('aerospace', $sectorTags)) {
            return 'Try aerospace angle - AS9100 certification and quick-turn capability';
        }
        if ($lead->getMoroccoSignal()) {
            return 'Try nearshoring angle - Morocco free zone benefits';
        }
        
        return 'Try value-add angle - complimentary BOM analysis offer';
    }
}
