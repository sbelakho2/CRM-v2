<?php

namespace App\Service;

use App\Entity\Contact;
use App\Entity\Lead;
use App\Entity\OutboundMessage;
use App\Entity\SpintaxTemplate;
use App\Entity\BanditArm;
use App\Repository\ContactRepository;
use App\Repository\LeadRepository;
use App\Repository\OutboundMessageRepository;
use App\Repository\SpintaxTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Autonomous Sales Orchestrator
 * 
 * Coordinates all modules of the Autonomous Sales System:
 * - Lead discovery and scoring
 * - Thompson Sampling for subject line optimization
 * - Spintax message personalization
 * - ML-style email personalization (tone, content)
 * - Email classification and closed-loop learning
 * - Competitor targeting (Sniper mode)
 * - Dynamic competitor learning from web scraping
 * 
 * Starz Electronics/Morocco Services:
 * - PCB Assembly, Cable Harness, Overmolding, Windings, System Integration
 * - Industries: Automotive, Aerospace, Industrial
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
class AutonomousSalesOrchestratorService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ThompsonSamplerService $thompsonSampler,
        private SpintaxEngineService $spintaxEngine,
        private EmailClassifierService $emailClassifier,
        private CompetitorDetectionService $competitorDetection,
        private LeadSalesAnalystService $leadAnalyst,
        private OutboundMessageRepository $outboundRepository,
        private ContactRepository $contactRepository,
        private LeadRepository $leadRepository,
        private SpintaxTemplateRepository $templateRepository,
        private ?MailerInterface $mailer,
        private LoggerInterface $logger,
        private ?EmailPersonalizationService $personalizationService = null,
        private ?CompetitorLearnerService $competitorLearner = null,
    ) {}

    /**
     * Compose a personalized message for a contact
     * 
     * Uses Thompson Sampling to select optimal subject line,
     * ML personalization for tone/content matching,
     * and Spintax to generate unique message variation
     */
    public function composeMessage(Contact $contact, array $context = []): array
    {
        // Get contact's company info for context
        $company = $contact->getCompany();
        
        // Build personalization context
        $fullContext = array_merge([
            'first_name' => $contact->getFirstName() ?? 'there',
            'last_name' => $contact->getLastName() ?? '',
            'company_name' => $company?->getName() ?? 'your company',
            'job_title' => $contact->getJobTitle() ?? '',
            'sender_name' => $_ENV['SALES_SENDER_NAME'] ?? 'Sales Team',
        ], $context);

        // Apply ML personalization if available
        $personalization = null;
        if ($this->personalizationService) {
            $personalization = $this->personalizationService->personalizeEmail($contact, $fullContext);
            // Merge personalization suggestions into context
            if (isset($personalization['greeting'])) {
                $fullContext['greeting_style'] = $personalization['greeting'];
            }
            if (isset($personalization['tone'])) {
                $fullContext['tone'] = $personalization['tone'];
            }
        }

        // Get template (select based on context or best performing)
        $template = $this->selectTemplate($contact, $context);
        
        if (!$template) {
            // Seed default templates if none exist
            $this->spintaxEngine->seedDefaultTemplates();
            $template = $this->templateRepository->findRandomActive('email');
        }

        if (!$template) {
            throw new \RuntimeException('No active spintax templates available');
        }

        // Thompson Sampling: Select best subject line arm
        $armResult = $this->thompsonSampler->sampleAndSelect('subject_line');
        
        // If we have a subject arm, use it instead of template subject
        $subjectSpintax = $template->getSubjectSpintax();
        $subjectArm = null;
        
        if ($armResult) {
            $subjectArm = $armResult['arm'];
            $subjectSpintax = $subjectArm->getArmValue();
        }

        // Spin and personalize the message
        $result = $this->spintaxEngine->spinAndPersonalize(
            $subjectSpintax,
            $template->getBodySpintax(),
            $fullContext
        );

        // Track template usage
        $template->incrementTimesUsed();
        $this->entityManager->flush();

        return [
            'subject' => $result['subject'],
            'body' => $result['body'],
            'variationHash' => $result['variationHash'],
            'templateId' => $template->getId(),
            'templateName' => $template->getName(),
            'subjectArmId' => $subjectArm?->getId(),
            'subjectArmName' => $subjectArm?->getArmName(),
            'personalization' => $personalization,
        ];
    }

    /**
     * Select the best template for a contact based on context
     */
    private function selectTemplate(Contact $contact, array $context = []): ?SpintaxTemplate
    {
        // Check if this is a Tier 1 auto supplier (ideal customer)
        $company = $contact->getCompany();
        $companyName = strtolower($company?->getName() ?? '');
        
        $tier1Customers = ['aptiv', 'yazaki', 'leoni', 'valeo', 'lear', 'sumitomo', 'continental', 'bosch', 'denso'];
        foreach ($tier1Customers as $tier1) {
            if (str_contains($companyName, $tier1)) {
                // Use Tier 1 Auto template if available
                $template = $this->templateRepository->findOneBy(['name' => 'Initial Outreach - Tier1 Auto', 'active' => true]);
                if ($template) {
                    return $template;
                }
            }
        }

        // Check if context specifies a service type
        $serviceType = $context['service_type'] ?? null;
        if ($serviceType) {
            $templateName = match($serviceType) {
                'cable_harness', 'harness', 'wire' => 'Initial Outreach - Cable Harness',
                'pcba', 'pcb', 'electronics' => 'Initial Outreach - PCBA',
                'competitor_displacement' => 'Competitor Displacement',
                default => 'Initial Outreach - General',
            };
            $template = $this->templateRepository->findOneBy(['name' => $templateName, 'active' => true]);
            if ($template) {
                return $template;
            }
        }

        // Check if prospect uses a known competitor
        $lead = $this->leadRepository->findOneBy(['company' => $company]);
        if ($lead) {
            $competitorBoost = $this->competitorDetection->getCompetitorScoreBoost($lead);
            if ($competitorBoost > 0) {
                $template = $this->templateRepository->findOneBy(['name' => 'Competitor Displacement', 'active' => true]);
                if ($template) {
                    return $template;
                }
            }
        }

        // Default: randomly select from active templates
        return $this->templateRepository->findRandomActive('email');
    }

    /**
     * Save outbound message for closed-loop tracking
     */
    public function saveOutboundMessage(
        Contact $contact,
        string $subject,
        string $bodyText,
        ?string $bodyHtml = null,
        ?BanditArm $subjectArm = null,
        ?SpintaxTemplate $template = null,
        ?string $variationHash = null,
        ?string $messageId = null,
        ?string $provider = null
    ): OutboundMessage {
        $message = new OutboundMessage();
        $message->setContact($contact);
        $message->setSubject($subject);
        $message->setBodyText($bodyText);
        $message->setBodyHtml($bodyHtml);
        $message->setSubjectArm($subjectArm);
        $message->setTemplate($template);
        $message->setVariationHash($variationHash);
        $message->setMessageId($messageId);
        $message->setProvider($provider);
        $message->setStatus(OutboundMessage::STATUS_PENDING);

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        $this->logger->info('Saved outbound message', [
            'id' => $message->getId(),
            'contactId' => $contact->getId(),
            'subjectArmId' => $subjectArm?->getId(),
        ]);

        return $message;
    }

    /**
     * Send email via mailer
     */
    public function sendEmail(OutboundMessage $message): bool
    {
        if (!$this->mailer) {
            $this->logger->warning('Mailer not configured, skipping send');
            return false;
        }

        $contact = $message->getContact();
        
        if (!$contact->getEmail()) {
            $this->logger->warning('Contact has no email', ['contactId' => $contact->getId()]);
            return false;
        }

        try {
            $email = (new Email())
                ->from($_ENV['MAILER_FROM_ADDRESS'] ?? 'noreply@starzelectronics.site')
                ->to($contact->getEmail())
                ->subject($message->getSubject())
                ->text($message->getBodyText());

            if ($message->getBodyHtml()) {
                $email->html($message->getBodyHtml());
            }

            // Add tracking headers
            $headers = $email->getHeaders();
            $headers->addTextHeader('X-Outbound-Message-ID', (string) $message->getId());
            
            if ($message->getSubjectArm()) {
                $headers->addTextHeader('X-Subject-Arm-ID', (string) $message->getSubjectArm()->getId());
            }

            $this->mailer->send($email);

            // Update message status
            $message->setStatus(OutboundMessage::STATUS_SENT);
            $message->setSentAt(new \DateTime());
            $this->entityManager->flush();

            $this->logger->info('Email sent successfully', [
                'messageId' => $message->getId(),
                'to' => $contact->getEmail(),
            ]);

            return true;

        } catch (\Exception $e) {
            $message->setStatus(OutboundMessage::STATUS_FAILED);
            $this->entityManager->flush();

            $this->logger->error('Failed to send email', [
                'messageId' => $message->getId(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Record email event (open, click, bounce, etc.) for closed-loop learning
     */
    public function recordEmailEvent(int $outboundMessageId, string $eventType): void
    {
        $message = $this->outboundRepository->find($outboundMessageId);
        
        if (!$message) {
            $this->logger->warning('Outbound message not found', ['id' => $outboundMessageId]);
            return;
        }

        // Update message status based on event
        $statusMap = [
            'delivered' => OutboundMessage::STATUS_DELIVERED,
            'open' => OutboundMessage::STATUS_OPENED,
            'click' => OutboundMessage::STATUS_CLICKED,
            'reply' => OutboundMessage::STATUS_REPLIED,
            'bounce' => OutboundMessage::STATUS_BOUNCED,
        ];

        $newStatus = $statusMap[$eventType] ?? null;
        
        if ($newStatus && $this->shouldUpdateStatus($message->getStatus(), $newStatus)) {
            $message->setStatus($newStatus);
            
            // Update timestamp
            switch ($eventType) {
                case 'delivered':
                    $message->setDeliveredAt(new \DateTime());
                    break;
                case 'open':
                    $message->setOpenedAt(new \DateTime());
                    break;
                case 'click':
                    $message->setClickedAt(new \DateTime());
                    break;
                case 'reply':
                    $message->setRepliedAt(new \DateTime());
                    break;
            }
        }

        // Record outcome in Thompson Sampler if not already done
        if (!$message->isOutcomeRecorded() && $message->getSubjectArm()) {
            $success = in_array($eventType, ['open', 'click', 'reply'], true);
            $failure = in_array($eventType, ['bounce'], true);

            if ($success || $failure) {
                $this->thompsonSampler->recordOutcome(
                    $message->getSubjectArm()->getId(),
                    $success
                );
                $message->setOutcomeRecorded(true);
            }
        }

        $this->entityManager->flush();

        $this->logger->info('Recorded email event', [
            'messageId' => $outboundMessageId,
            'eventType' => $eventType,
            'newStatus' => $message->getStatus(),
        ]);
    }

    /**
     * Check if status should be updated (status progression)
     */
    private function shouldUpdateStatus(string $currentStatus, string $newStatus): bool
    {
        $statusOrder = [
            OutboundMessage::STATUS_PENDING => 0,
            OutboundMessage::STATUS_SENT => 1,
            OutboundMessage::STATUS_DELIVERED => 2,
            OutboundMessage::STATUS_OPENED => 3,
            OutboundMessage::STATUS_CLICKED => 4,
            OutboundMessage::STATUS_REPLIED => 5,
            OutboundMessage::STATUS_BOUNCED => 1, // Can override sent
            OutboundMessage::STATUS_FAILED => 0,
        ];

        $currentOrder = $statusOrder[$currentStatus] ?? 0;
        $newOrder = $statusOrder[$newStatus] ?? 0;

        return $newOrder > $currentOrder || $newStatus === OutboundMessage::STATUS_BOUNCED;
    }

    /**
     * Get system statistics
     */
    public function getStats(): array
    {
        // Discovery stats (leads)
        $totalLeads = $this->leadRepository->count([]);
        $pendingLeads = $this->leadRepository->count(['reviewStatus' => 'pending']);
        $scoredLeads = $this->leadRepository->count(['leadScore >= 0']);

        // Scoring stats
        $avgScore = $this->entityManager->createQueryBuilder()
            ->select('AVG(l.leadScore)')
            ->from(Lead::class, 'l')
            ->where('l.leadScore IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        // Optimizer stats (Thompson Sampling)
        $armStats = $this->thompsonSampler->getBanditStats('subject_line');

        // Inbox stats
        $inboxStats = $this->emailClassifier->getClassificationStats();

        // Competitor stats
        $competitorStats = $this->competitorDetection->getCompetitorStats();

        return [
            'discovery' => [
                'totalLeads' => $totalLeads,
                'pendingReview' => $pendingLeads,
                'scored' => $scoredLeads,
            ],
            'scoring' => [
                'averageScore' => round((float) $avgScore, 2),
            ],
            'optimizer' => [
                'arms' => $armStats['arm_count'],
                'trials' => $armStats['total_trials'],
                'successRate' => $armStats['overall_rate'],
                'convergence' => $armStats['convergence'],
            ],
            'inbox' => $inboxStats,
            'competitors' => [
                'detectionsCount' => count($competitorStats),
                'stats' => $competitorStats,
            ],
        ];
    }

    /**
     * Score leads batch
     */
    public function scoreLeads(int $limit = 50): array
    {
        $leads = $this->leadRepository->createQueryBuilder('l')
            ->where('l.leadScore IS NULL OR l.updatedAt < :stale')
            ->setParameter('stale', new \DateTime('-7 days'))
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $scored = [];
        $byTier = ['hot' => 0, 'warm' => 0, 'cold' => 0, 'ice' => 0];

        foreach ($leads as $lead) {
            // Use existing LeadSalesAnalystService for analysis
            $analysis = $this->leadAnalyst->analyzeLead($lead);
            
            // Calculate base score from analysis
            $score = (int) $analysis['fit_score'];
            
            // Add competitor boost if detected
            $competitorBoost = $this->competitorDetection->getCompetitorScoreBoost($lead);
            $score = min(100, $score + $competitorBoost);
            
            $lead->setLeadScore($score);
            $lead->setUpdatedAt(new \DateTime());
            
            // Determine tier
            $tier = match(true) {
                $score >= 70 => 'hot',
                $score >= 50 => 'warm',
                $score >= 30 => 'cold',
                default => 'ice',
            };
            $byTier[$tier]++;

            $scored[] = [
                'id' => $lead->getId(),
                'name' => $lead->getCompanyName(),
                'score' => $score,
                'tier' => $tier,
                'competitorBoost' => $competitorBoost,
            ];
        }

        $this->entityManager->flush();

        $this->logger->info('Scored leads batch', [
            'count' => count($scored),
            'byTier' => $byTier,
        ]);

        return [
            'scored' => count($scored),
            'byTier' => $byTier,
            'leads' => $scored,
        ];
    }

    /**
     * Initialize default data (templates, arms, competitors)
     */
    public function initialize(): array
    {
        $templates = $this->spintaxEngine->seedDefaultTemplates();
        $arms = $this->thompsonSampler->seedDefaultSubjectLineArms();
        
        // Seed competitors if service available
        $competitors = [];
        if ($this->competitorLearner) {
            $competitors = $this->competitorLearner->seedCompetitors();
        }

        return [
            'templates' => count($templates),
            'arms' => count($arms),
            'competitors' => count($competitors),
        ];
    }
}
