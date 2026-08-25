<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use App\Entity\Rfq;
use App\Entity\Quote;
use App\Entity\Lead;
use App\Entity\AbmHit;
use App\Entity\Activity;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages automated email campaign triggers based on CRM events
 * Supports: pipeline stage changes, RFQ submissions, quote sent, lead score changes, ABM hits
 */
class EmailCampaignTriggerService
{
    private EntityManagerInterface $em;
    private EmailSchedulerService $schedulerService;
    private EmailDripCampaignService $dripService;
    private LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $em,
        EmailSchedulerService $schedulerService,
        EmailDripCampaignService $dripService,
        LoggerInterface $logger
    ) {
        $this->em = $em;
        $this->schedulerService = $schedulerService;
        $this->dripService = $dripService;
        $this->logger = $logger;
    }

    /**
     * Trigger campaigns when company pipeline stage changes
     * 
     * @param Company $company The company with stage change
     * @param string $oldStage Previous pipeline stage
     * @param string $newStage New pipeline stage
     */
    public function handlePipelineStageChange(Company $company, string $oldStage, string $newStage): void
    {
        // Find trigger campaigns for this stage change
        $campaigns = $this->em->getRepository(EmailCampaign::class)
            ->createQueryBuilder('c')
            ->where('c.triggerType = :type')
            ->andWhere('c.status = :status')
            ->andWhere('JSON_EXTRACT(c.triggerConditions, \'$.new_stage\') = :newStage')
            ->setParameter('type', 'pipeline_stage_change')
            ->setParameter('status', 'sending')
            ->setParameter('newStage', $newStage)
            ->getQuery()
            ->getResult();

        foreach ($campaigns as $campaign) {
            // Get primary contact for company
            $contact = $this->getPrimaryContact($company);
            if (!$contact) {
                $this->logger->warning("No primary contact found for company {$company->getName()} - skipping trigger");
                continue;
            }

            // Check if campaign is a drip campaign
            if ($this->dripService->isDripCampaign($campaign)) {
                // Enroll in drip campaign
                $this->dripService->enrollContact($campaign, $contact);
                $this->logger->info("Enrolled contact {$contact->getEmail()} in drip campaign {$campaign->getName()} due to stage change: {$oldStage} → {$newStage}");
            } else {
                // Schedule single email
                $this->scheduleSingleEmail($campaign, $contact, [
                    'trigger_type' => 'pipeline_stage_change',
                    'old_stage' => $oldStage,
                    'new_stage' => $newStage
                ]);
            }
        }
    }

    /**
     * Trigger campaigns when RFQ is submitted
     * 
     * @param Rfq $rfq The submitted RFQ
     */
    public function handleRfqSubmission(Rfq $rfq): void
    {
        $company = $rfq->getCompany();

        if (!$company) {
            $this->logger->warning("No company found for RFQ {$rfq->getId()} - skipping trigger");
            return;
        }

        // Get primary contact for company
        $contact = $this->getPrimaryContact($company);
        if (!$contact) {
            $this->logger->warning("No contact found for company {$company->getName()} - skipping RFQ trigger");
            return;
        }

        // Find trigger campaigns for RFQ submission
        $campaigns = $this->em->getRepository(EmailCampaign::class)
            ->createQueryBuilder('c')
            ->where('c.triggerType = :type')
            ->andWhere('c.status = :status')
            ->setParameter('type', 'rfq_submission')
            ->setParameter('status', 'sending')
            ->getQuery()
            ->getResult();

        foreach ($campaigns as $campaign) {
            // Check if campaign should only trigger for specific sectors
            $conditions = $campaign->getTriggerConditions();
            if ($conditions && isset($conditions['sectors'])) {
                $companySector = $company?->getSector();
                if (!in_array($companySector, $conditions['sectors'])) {
                    continue;
                }
            }

            // Schedule follow-up email
            if ($this->dripService->isDripCampaign($campaign)) {
                $this->dripService->enrollContact($campaign, $contact);
                $this->logger->info("Enrolled contact {$contact->getEmail()} in RFQ follow-up drip campaign");
            } else {
                $this->scheduleSingleEmail($campaign, $contact, [
                    'trigger_type' => 'rfq_submission',
                    'rfq_id' => $rfq->getId()
                ]);
            }
        }
    }

    /**
     * Trigger campaigns when quote is sent
     * 
     * @param Quote $quote The sent quote
     */
    public function handleQuoteSent(Quote $quote): void
    {
        $company = $quote->getCompany();

        if (!$company) {
            $this->logger->warning("No company found for Quote {$quote->getId()} - skipping trigger");
            return;
        }

        // Get primary contact for company
        $contact = $this->getPrimaryContact($company);
        if (!$contact) {
            $this->logger->warning("No contact found for company {$company->getName()} - skipping quote trigger");
            return;
        }

        // Find trigger campaigns for quote sent
        $campaigns = $this->em->getRepository(EmailCampaign::class)
            ->createQueryBuilder('c')
            ->where('c.triggerType = :type')
            ->andWhere('c.status = :status')
            ->setParameter('type', 'quote_sent')
            ->setParameter('status', 'sending')
            ->getQuery()
            ->getResult();

        foreach ($campaigns as $campaign) {
            if ($this->dripService->isDripCampaign($campaign)) {
                // Enroll in quote follow-up sequence
                $this->dripService->enrollContact($campaign, $contact);
                $this->logger->info("Enrolled contact {$contact->getEmail()} in quote follow-up drip campaign");
            } else {
                $this->scheduleSingleEmail($campaign, $contact, [
                    'trigger_type' => 'quote_sent',
                    'quote_id' => $quote->getId(),
                    'quote_number' => $quote->getQuoteNumber()
                ]);
            }
        }
    }

    /**
     * Trigger campaigns when lead score changes significantly
     * 
     * @param Lead $lead The lead with score change
     * @param int $oldScore Previous lead score
     * @param int $newScore New lead score
     */
    public function handleLeadScoreChange(Lead $lead, int $oldScore, int $newScore): void
    {
        $company = $lead->getCompany();

        if (!$company) {
            $this->logger->warning("No company found for Lead {$lead->getId()} - skipping trigger");
            return;
        }

        // Get primary contact for company
        $contact = $this->getPrimaryContact($company);
        if (!$contact) {
            $this->logger->warning("No contact found for company {$company->getName()} - skipping lead score trigger");
            return;
        }

        // Find trigger campaigns for lead score changes
        $campaigns = $this->em->getRepository(EmailCampaign::class)
            ->createQueryBuilder('c')
            ->where('c.triggerType = :type')
            ->andWhere('c.status = :status')
            ->setParameter('type', 'lead_score_change')
            ->setParameter('status', 'sending')
            ->getQuery()
            ->getResult();

        foreach ($campaigns as $campaign) {
            $conditions = $campaign->getTriggerConditions();
            
            // Check if score meets threshold conditions
            if ($conditions) {
                $minScore = $conditions['min_score'] ?? 0;
                $scoreIncrease = $conditions['min_increase'] ?? 0;
                
                if ($newScore < $minScore) {
                    continue;
                }
                
                if (($newScore - $oldScore) < $scoreIncrease) {
                    continue;
                }
            }

            if ($this->dripService->isDripCampaign($campaign)) {
                $this->dripService->enrollContact($campaign, $contact);
                $this->logger->info("Enrolled high-scoring contact {$contact->getEmail()} in nurture campaign");
            } else {
                $this->scheduleSingleEmail($campaign, $contact, [
                    'trigger_type' => 'lead_score_change',
                    'old_score' => $oldScore,
                    'new_score' => $newScore
                ]);
            }
        }
    }

    /**
     * Trigger campaigns when high-value ABM hit is detected
     * 
     * @param AbmHit $hit The ABM hit
     */
    public function handleAbmHit(AbmHit $hit): void
    {
        $company = $hit->getCompany();
        
        if (!$company) {
            $this->logger->warning("No company found for ABM hit {$hit->getId()} - skipping trigger");
            return;
        }

        // Get primary contact
        $contact = $this->getPrimaryContact($company);
        if (!$contact) {
            $this->logger->warning("No primary contact found for company {$company->getName()} - skipping ABM trigger");
            return;
        }

        // Find trigger campaigns for ABM hits
        $campaigns = $this->em->getRepository(EmailCampaign::class)
            ->createQueryBuilder('c')
            ->where('c.triggerType = :type')
            ->andWhere('c.status = :status')
            ->setParameter('type', 'abm_hit')
            ->setParameter('status', 'sending')
            ->getQuery()
            ->getResult();

        foreach ($campaigns as $campaign) {
            $conditions = $campaign->getTriggerConditions();
            
            // Check engagement threshold (use pageViews as proxy for engagement score)
            if ($conditions && isset($conditions['min_engagement_score'])) {
                $pageViews = $hit->getPageViews() ?? 0;
                if ($pageViews < $conditions['min_engagement_score']) {
                    continue;
                }
            }

            if ($this->dripService->isDripCampaign($campaign)) {
                $this->dripService->enrollContact($campaign, $contact);
                $this->logger->info("Enrolled contact {$contact->getEmail()} in ABM engagement campaign");
            } else {
                $this->scheduleSingleEmail($campaign, $contact, [
                    'trigger_type' => 'abm_hit',
                    'page_views' => $hit->getPageViews(),
                    'session_duration' => $hit->getSessionDuration()
                ]);
            }
        }
    }

    /**
     * Schedule a single email send
     * 
     * @param EmailCampaign $campaign The campaign to send
     * @param Contact $contact The recipient
     * @param array $metadata Additional metadata for the send
     */
    private function scheduleSingleEmail(EmailCampaign $campaign, Contact $contact, array $metadata = []): void
    {
        // Check if contact already received this triggered campaign (avoid duplicates)
        $existingSend = $this->em->getRepository(EmailSend::class)
            ->createQueryBuilder('s')
            ->where('s.campaign = :campaign')
            ->andWhere('s.contact = :contact')
            ->andWhere('s.status != :cancelled')
            ->setParameter('campaign', $campaign)
            ->setParameter('contact', $contact)
            ->setParameter('cancelled', 'cancelled')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existingSend) {
            $this->logger->info("Contact {$contact->getEmail()} already received campaign {$campaign->getName()} - skipping duplicate");
            return;
        }

        // Calculate send time based on campaign delay settings
        $conditions = $campaign->getTriggerConditions();
        $delay = $conditions['delay_minutes'] ?? 0;
        $sendAt = new \DateTime();
        $sendAt->modify("+{$delay} minutes");

        // Create email send record
        $send = new EmailSend();
        $send->setCampaign($campaign);
        $send->setContact($contact);
        $send->setScheduledAt($sendAt);
        $send->setStatus($delay > 0 ? 'queued' : 'sending');
        // Note: metadata storage removed as EmailSend doesn't have this field

        $this->em->persist($send);
        $this->em->flush();

        $this->logger->info("Scheduled triggered email for {$contact->getEmail()} from campaign {$campaign->getName()}", [
            'send_at' => $sendAt->format('Y-m-d H:i:s'),
            'metadata' => $metadata
        ]);
    }

    /**
     * Get primary contact for a company
     * Priority: 1) Contact marked as primary, 2) First contact
     * 
     * @param Company $company
     * @return Contact|null
     */
    private function getPrimaryContact(Company $company): ?Contact
    {
        $contacts = $company->getContacts();
        
        if ($contacts->isEmpty()) {
            return null;
        }

        // Look for primary contact
        foreach ($contacts as $contact) {
            if ($contact->isPrimaryContact()) {
                return $contact;
            }
        }

        // Return first contact as fallback
        return $contacts->first() ?: null;
    }

    /**
     * Get all available trigger types
     * 
     * @return array
     */
    public function getAvailableTriggerTypes(): array
    {
        return [
            'pipeline_stage_change' => [
                'label' => 'Pipeline Stage Change',
                'description' => 'Trigger when company moves to a new pipeline stage',
                'conditions' => ['new_stage' => 'required', 'sectors' => 'optional']
            ],
            'rfq_submission' => [
                'label' => 'RFQ Submission',
                'description' => 'Trigger when a new RFQ is submitted',
                'conditions' => ['sectors' => 'optional', 'delay_minutes' => 'optional']
            ],
            'quote_sent' => [
                'label' => 'Quote Sent',
                'description' => 'Trigger when a quote is sent to customer',
                'conditions' => ['delay_minutes' => 'optional']
            ],
            'lead_score_change' => [
                'label' => 'Lead Score Change',
                'description' => 'Trigger when lead score increases significantly',
                'conditions' => ['min_score' => 'required', 'min_increase' => 'required']
            ],
            'abm_hit' => [
                'label' => 'ABM Engagement',
                'description' => 'Trigger when target account shows high engagement',
                'conditions' => ['min_engagement_score' => 'required']
            ]
        ];
    }

    /**
     * Create a triggered campaign
     * 
     * @param string $name Campaign name
     * @param string $triggerType Type of trigger
     * @param array $triggerConditions Conditions for triggering
     * @param EmailCampaign|null $baseCampaign Base campaign to use (optional)
     * @return EmailCampaign
     */
    public function createTriggeredCampaign(
        string $name,
        string $triggerType,
        array $triggerConditions,
        ?EmailCampaign $baseCampaign = null
    ): EmailCampaign {
        $campaign = new EmailCampaign();
        $campaign->setName($name);
        $campaign->setTriggerType($triggerType);
        $campaign->setTriggerConditions($triggerConditions);
        $campaign->setStatus('sending'); // Active by default
        $campaign->setType('triggered');
        
        if ($baseCampaign) {
            $campaign->setSubject($baseCampaign->getSubject());
            $campaign->setBodyHtml($baseCampaign->getBodyHtml());
            $campaign->setFromEmail($baseCampaign->getFromEmail());
            $campaign->setFromName($baseCampaign->getFromName());
        }

        $this->em->persist($campaign);
        $this->em->flush();

        $this->logger->info("Created triggered campaign: {$name} ({$triggerType})");

        return $campaign;
    }
}
