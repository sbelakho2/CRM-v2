<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contact;
use App\Entity\Lead;
use App\Entity\OutboundMessage;
use App\Entity\RFQ;
use App\Entity\SpintaxTemplate;
use App\Entity\BanditArm;
use App\Repository\ContactRepository;
use App\Repository\LeadRepository;
use App\Repository\OutboundMessageRepository;
use App\Repository\SpintaxTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
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
        private AutonomousSalesSettingsService $settingsService,
        private ?EmailPersonalizationService $personalizationService = null,
        private ?CompetitorLearnerService $competitorLearner = null,
        private ?CadenceGovernorService $cadenceGovernor = null,
        private ?CopyLintService $copyLintService = null,
        private ?SalesPipelineOrchestratorService $pipelineOrchestrator = null,
        private ?EmailActivityLogger $activityLogger = null,
        private ?string $mailerFromAddress = null,
        private ?LockFactory $lockFactory = null,
    ) {}

    /**
     * Compose a personalized message for a contact
     * 
     * Uses Thompson Sampling to select optimal subject line,
     * ML personalization for tone/content matching,
     * and Spintax to generate unique message variation.
     * 
     * Now includes:
     * - Content length enforcement based on engagement
     * - Value prop A/B testing via Thompson Sampling
     * - Competitor displacement hooks when applicable
     * - Subject line synthesis from successful patterns
     * - Campaign-aware Thompson Sampling for multi-campaign support
     * 
     * @param Contact $contact The contact to compose a message for
     * @param array $context Additional context variables
     * @param string|null $campaignId Optional campaign ID for campaign-specific A/B testing
     */
    public function composeMessage(Contact $contact, array $context = [], ?string $campaignId = null): array
    {
        $this->assertEnabled();

        // Get contact's company info for context
        $company = $contact->getCompany();
        
        // Build base context - now includes sender_company for templates
        $jobTitle = $contact->getJobTitle() ?? '';
        $baseContext = array_merge([
            'first_name' => $contact->getFirstName() ?? 'there',
            'last_name' => $contact->getLastName() ?? '',
            'company_name' => $company?->getName() ?? 'your company',
            'job_title' => $jobTitle,
            'job_title_area' => $this->extractFunctionalArea($jobTitle),
            'sender_name' => $_ENV['SALES_SENDER_NAME'] ?? 'Sales Team',
            'sender_company' => $_ENV['SENDER_COMPANY_NAME'] ?? 'Starz Electronics',  // FIXED: Configurable sender company
        ], $context);

        // Apply ML personalization if available - get full personalization context
        $personalization = null;
        $fullContext = $baseContext;
        $contentLength = 'standard';
        $valuePropArmId = null;
        
        if ($this->personalizationService) {
            $personalization = $this->personalizationService->getPersonalizationContext($contact, $baseContext);
            
            // Merge all personalization variables into full context
            // This includes: value_prop, pain_hook, social_proof, cta, greeting, closing, etc.
            $fullContext = array_merge($baseContext, $personalization['variables']);
            
            // ==================== CIALDINI PERSUASION CONTEXT ====================
            // Build and merge comprehensive persuasion context from all 7 principles
            // This adds: reciprocity, scarcity, authority, consistency, liking, social_proof, unity
            // Plus Pre-suasion: presuasive_opener, extend_impact, privileged_moment
            // Plus Geographic: geo_logistics, geo_timezone, geo_trade, geo_cultural, geo_proximity
            $persuasionContext = $this->personalizationService->buildPersuasionContext($contact);
            $fullContext = array_merge($fullContext, $persuasionContext);
            
            // Store successful patterns for template selection
            $context['successfulPatterns'] = $personalization['successfulPatterns'] ?? [];
            $context['industry'] = $personalization['industry'] ?? null;
            $context['role'] = $personalization['role'] ?? null;
            $contentLength = $personalization['contentLength'] ?? 'standard';
            
            // ==================== VALUE PROP A/B TESTING ====================
            // Try to get A/B tested value prop variant
            $industry = $personalization['industry'] ?? 'other';
            $contentFocus = $this->mapContentToFocus($personalization['content'] ?? 'business');
            
            $valuePropResult = $this->personalizationService->getValuePropVariant($industry, $contentFocus);
            if ($valuePropResult['is_ab_test']) {
                $fullContext['value_prop'] = $valuePropResult['value_prop'];
                // Create brief version by taking first sentence
                $vp = $valuePropResult['value_prop'];
                $dotPos = strpos($vp, '.');
                $fullContext['value_prop_short'] = $dotPos !== false ? substr($vp, 0, $dotPos + 1) : $vp;
                $valuePropArmId = $valuePropResult['arm_id'];
            }
            
            // ==================== CURIOSITY SUBJECT LINES ====================
            // Generate curiosity-gap subject lines for templates that use {{curiosity_subject}}
            $patternTypes = ['question', 'intrigue', 'social_proof', 'specificity', 'value_forward'];
            $selectedPatternType = $patternTypes[array_rand($patternTypes)];
            $fullContext['curiosity_subject'] = $this->personalizationService->getCuriositySubjectLine(
                $contact,
                $selectedPatternType
            );
            
            // ==================== COMPETITOR DISPLACEMENT ====================
            // Check for competitor displacement opportunity
            $competitorHook = $this->getCompetitorDisplacementHook($contact);
            if ($competitorHook) {
                $fullContext['competitor_hook'] = $competitorHook['hook'];
                $fullContext['competitor_pain'] = $competitorHook['pain'];
                $fullContext['competitor_diff'] = $competitorHook['differentiation'];
            }
        }

        // Get template (select based on context, patterns, or best performing)
        $template = $this->selectTemplate($contact, $context);
        
        if (!$template) {
            // Seed default templates if none exist
            $this->spintaxEngine->seedDefaultTemplates();
            $template = $this->templateRepository->findRandomActive('email');
        }

        if (!$template) {
            throw new \RuntimeException('No active spintax templates available');
        }

        // ==================== ICP CLUSTER ====================
        // Determine ICP cluster for per-industry bandit pools
        $company = $contact->getCompany();
        $icpCluster = 'global'; // fallback
        if ($company) {
            $industry = strtolower($company->getSector() ?? '');
            if ($industry && $industry !== 'other') {
                $icpCluster = $industry;
            }
        }

        // Decision trace: log every arm selection for attribution debugging
        $decisionTrace = [
            'timestamp' => (new \DateTime())->format('c'),
            'icpCluster' => $icpCluster,
        ];

        // Thompson Sampling: Select best subject line arm
        // Now passes icpCluster for per-industry pools + 10% control group routing
        $armName = $campaignId ? "subject_line_{$campaignId}" : 'subject_line';
        $armResult = $this->thompsonSampler->sampleAndSelect($armName, $icpCluster);
        
        // If campaign-specific arm not found, fall back to generic
        if (!$armResult && $campaignId) {
            $armResult = $this->thompsonSampler->sampleAndSelect('subject_line', $icpCluster);
        }
        
        // If we have a subject arm, use it instead of template subject
        $subjectSpintax = $template->getSubjectSpintax();
        $subjectArm = null;
        $isControlGroup = false;
        
        if ($armResult) {
            $subjectArm = $armResult['arm'];
            $subjectSpintax = $subjectArm->getArmValue();
            $isControlGroup = $armResult['isControl'] ?? false;
            $decisionTrace['subjectArm'] = [
                'armId' => $subjectArm->getId(),
                'armName' => $subjectArm->getArmName(),
                'isControl' => $isControlGroup,
                'sampledValue' => $armResult['sampledValue'] ?? null,
            ];
        }
        
        // ==================== SUBJECT LINE SYNTHESIS ====================
        // Apply learned successful patterns to subject line
        // Skip synthesis for control group (they get baseline copy)
        if (!$isControlGroup && $this->personalizationService && !empty($context['successfulPatterns'])) {
            $subjectSpintax = $this->personalizationService->synthesizeSubjectLine($contact, $subjectSpintax);
        }

        // Spin and personalize the message with full context
        $result = $this->spintaxEngine->spinAndPersonalize(
            $subjectSpintax,
            $template->getBodySpintax(),
            $fullContext
        );
        
        // Apply tone transformations to the final output if personalization service is available
        $toneApplied = 'formal'; // default
        if ($this->personalizationService && $personalization) {
            $engagementLevel = $personalization['engagementLevel'] ?? 'cold';
            
            // Use architecture-recommended tone based on engagement level.
            $profileTone = $personalization['tone'] ?? 'formal';
            $architectureTone = match($engagementLevel) {
                'hot' => 'formal',
                'warm' => 'friendly',
                default => 'casual',
            };
            $tone = ($profileTone === 'formal' && $engagementLevel === 'cold') 
                ? $architectureTone 
                : $profileTone;
            $toneApplied = $tone;
            
            // Skip personalization fixes for control group (they get raw template output)
            if (!$isControlGroup) {
                $result['body'] = $this->personalizationService->applyOutputQualityFixes(
                    $result['body'], 
                    $engagementLevel,
                    $tone
                );
            }
            
            // Quality validation always runs (we want to measure control vs treatment)
            $qualityCheck = $this->personalizationService->runFullQualityCheck(
                $result['body'],
                $contact,
                $fullContext
            );
        } else {
            $qualityCheck = null;
        }

        // ==================== COPY LINT GATE ====================
        // Compose-time lint is ADVISORY: a violation is logged with severity and
        // recorded in the decision trace, but the message is only hard-blocked
        // at send time (sendEmail enforces the gate).
        $copyLintResult = null;
        if ($this->copyLintService) {
            $copyLintResult = $this->copyLintService->lint($result['subject'], $result['body'], $fullContext);
            if (!$copyLintResult['passed']) {
                $this->logger->warning('Copy lint FAILED at compose time (advisory — send-time gate still applies)', [
                    'contactId' => $contact->getId(),
                    'violations' => $copyLintResult['violations'],
                ]);
            }
            $decisionTrace['copyLint'] = $copyLintResult;
        }

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
            'valuePropArmId' => $valuePropArmId,  // Track for value prop A/B testing
            'contentLength' => $contentLength,     // Track applied content length
            'personalization' => $personalization,
            'qualityCheck' => $qualityCheck,       // Quality validation results
            'copyLintResult' => $copyLintResult,   // Copy lint gate result
            'isControlGroup' => $isControlGroup,   // Control group flag
            'icpCluster' => $icpCluster,           // ICP cluster used
            'toneApplied' => $toneApplied,         // Tone variant applied
            'decisionTrace' => $decisionTrace,     // Full decision audit trail
        ];
    }
    
    /**
     * Map content focus constant to value prop key
     */
    private function mapContentToFocus(string $content): string
    {
        return match($content) {
            'technical' => 'technical',
            'business' => 'business',
            'value_focused' => 'value_focused',
            'relationship' => 'relationship',
            default => 'business',
        };
    }

    /**
     * Get optimal send time for a contact
     * 
     * Uses learned send time data from personalization profiles.
     * 
     * @param Contact $contact The contact to get send time for
     * @return array Send time recommendation with 'time', 'day', 'source', 'confidence'
     */
    public function getOptimalSendTime(Contact $contact): array
    {
        $this->assertEnabled();

        if ($this->personalizationService) {
            return $this->personalizationService->getOptimalSendTime($contact);
        }
        
        // Fallback if personalization service not available
        return [
            'time' => '09:00',
            'day' => 'Tuesday',
            'source' => 'default',
            'confidence' => 0.5,
        ];
    }

    /**
     * Check if a company is associated with a known competitor
     * 
     * Returns competitor displacement messaging if applicable.
     * 
     * @param Contact $contact The contact to check
     * @return array|null Competitor hook data or null
     */
    public function getCompetitorDisplacementHook(Contact $contact): ?array
    {
        if (!$this->personalizationService) {
            return null;
        }
        
        $company = $contact->getCompany();
        if (!$company) {
            return null;
        }
        
        // Check company notes/sources for competitor mentions
        $sourceNotes = strtolower($company->getSourceNotes() ?? '');
        $competitors = $this->personalizationService->getKnownCompetitors();
        
        foreach ($competitors as $competitor) {
            if (str_contains($sourceNotes, $competitor)) {
                return $this->personalizationService->getCompetitorHook($competitor);
            }
        }
        
        return null;
    }

    /**
     * Select the best template for a contact based on context
     * 
     * Now includes reply pattern learning to prefer templates that
     * match patterns from previously successful emails.
      * @param array<string|int, mixed> $context
     */
    private function selectTemplate(Contact $contact, array $context = []): ?SpintaxTemplate
    {
        $company = $contact->getCompany();
        $companyName = strtolower($company?->getName() ?? '');
        
        // ==================== REPLY PATTERN LEARNING ====================
        // Check if we have successful subject patterns to match against
        $successfulPatterns = $context['successfulPatterns'] ?? [];
        if (!empty($successfulPatterns)) {
            $matchedTemplate = $this->findTemplateMatchingPatterns($successfulPatterns);
            if ($matchedTemplate) {
                $this->logger->info('Selected template based on successful reply patterns', [
                    'templateName' => $matchedTemplate->getName(),
                    'patternsMatched' => count($successfulPatterns),
                ]);
                return $matchedTemplate;
            }
        }
        
        // ==================== SERVICE TYPE CHECK (HIGHEST PRIORITY) ====================
        // Explicit service type overrides all heuristic selection (industry, role, etc.)
        // This is intentionally first: when a user/system specifies a service type,
        // it should always be honored over inferred characteristics.
        $serviceType = $context['service_type'] ?? null;
        if ($serviceType && $serviceType !== 'general') {
            $templateName = match($serviceType) {
                'cable_harness', 'harness', 'wire' => 'Initial Outreach - Cable Harness',
                'pcba', 'pcb', 'electronics' => 'Initial Outreach - PCBA',
                'competitor_displacement' => 'Competitor Displacement',
                'automotive', 'tier1_auto' => 'Initial Outreach - Tier1 Auto',
                'technical', 'engineering' => 'Initial Outreach - Technical',
                'cost', 'nearshore' => 'Initial Outreach - Cost Focus',
                default => null,
            };
            if ($templateName) {
                $template = $this->templateRepository->findOneBy(['name' => $templateName, 'active' => true]);
                if ($template) {
                    return $template;
                }
            }
        }

        // ==================== INDUSTRY-SPECIFIC SELECTION ====================
        // Select template based on industry if available
        $industry = $context['industry'] ?? null;
        if ($industry && $industry !== 'other') {
            $industryTemplateMap = [
                'automotive' => 'Initial Outreach - Tier1 Auto',
                'aerospace' => 'Initial Outreach - Technical',
                'defense' => 'Initial Outreach - Technical',
                'medical' => 'Initial Outreach - Technical',
            ];
            
            if (isset($industryTemplateMap[$industry])) {
                $template = $this->templateRepository->findOneBy(['name' => $industryTemplateMap[$industry], 'active' => true]);
                if ($template) {
                    return $template;
                }
            }
        }
        
        // ==================== ROLE-SPECIFIC SELECTION ====================
        // Select template based on role if available
        $role = $context['role'] ?? null;
        if ($role && $role !== 'other') {
            $roleTemplateMap = [
                'procurement' => 'Initial Outreach - Cost Focus',
                'engineering' => 'Initial Outreach - Technical',
                'quality' => 'Initial Outreach - Technical',
                'management' => 'Initial Outreach - PCBA',
                'operations' => 'Initial Outreach - Cost Focus',
                'supply_chain' => 'Initial Outreach - Cost Focus',
            ];
            
            if (isset($roleTemplateMap[$role])) {
                $template = $this->templateRepository->findOneBy(['name' => $roleTemplateMap[$role], 'active' => true]);
                if ($template) {
                    return $template;
                }
            }
        }
        
        // ==================== TIER 1 AUTO CHECK ====================
        // Check if this is a Tier 1 auto supplier (ideal customer)
        $tier1Customers = ['aptiv', 'yazaki', 'leoni', 'valeo', 'lear', 'sumitomo', 'continental', 'bosch', 'denso'];
        foreach ($tier1Customers as $tier1) {
            if (str_contains($companyName, $tier1)) {
                $template = $this->templateRepository->findOneBy(['name' => 'Initial Outreach - Tier1 Auto', 'active' => true]);
                if ($template) {
                    return $template;
                }
            }
        }

        // ==================== COMPETITOR CHECK ====================
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

        // ==================== FALLBACK ====================
        $template = $this->templateRepository->findRandomActive('email');
        
        if (!$template) {
            $this->logger->warning('No active templates found, seeding defaults');
            $seeded = $this->spintaxEngine->seedDefaultTemplates();
            $template = !empty($seeded) ? $seeded[0] : null;
        }
        
        return $template;
    }

    /**
     * Find a template that matches successful reply patterns
     * 
     * This enables learning from past successful emails by preferring
     * templates with similar subject line structures.
      * @param array<string|int, mixed> $successfulPatterns
     */
    private function findTemplateMatchingPatterns(array $successfulPatterns): ?SpintaxTemplate
    {
        $templates = $this->templateRepository->findActiveByType('email');
        
        if (empty($templates)) {
            return null;
        }
        
        $bestTemplate = null;
        $bestScore = 0;
        
        foreach ($templates as $template) {
            $subjectSpintax = strtolower($template->getSubjectSpintax());
            $score = 0;
            
            foreach ($successfulPatterns as $pattern) {
                $pattern = strtolower($pattern);
                
                // Check for keyword overlap
                $patternWords = array_filter(explode(' ', preg_replace('/[^a-z\s]/', '', $pattern)));
                $templateWords = array_filter(explode(' ', preg_replace('/[^a-z\s]/', '', $subjectSpintax)));
                
                $overlap = count(array_intersect($patternWords, $templateWords));
                $score += $overlap;
                
                // Bonus for structural similarity (same length, similar punctuation)
                if (abs(strlen($pattern) - strlen($subjectSpintax)) < 20) {
                    $score += 2;
                }
            }
            
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestTemplate = $template;
            }
        }
        
        // Only return if we have a meaningful match (score > 3)
        return $bestScore > 3 ? $bestTemplate : null;
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
        $this->assertEnabled();

        if (!$this->mailer) {
            $this->logger->warning('Mailer not configured, skipping send');
            return false;
        }

        $contact = $message->getContact();
        
        if (!$contact->getEmail()) {
            $this->logger->warning('Contact has no email', ['contactId' => $contact->getId()]);
            return false;
        }

        // ==================== CADENCE GOVERNOR ====================
        // Check send cadence limits and stop rules before sending
        if ($this->cadenceGovernor) {
            $cadenceCheck = $this->cadenceGovernor->canSendTo($contact);
            if (!$cadenceCheck['allowed']) {
                $this->logger->info('Cadence governor blocked send', [
                    'contactId' => $contact->getId(),
                    'reason' => $cadenceCheck['reason'],
                    'nextAllowedAt' => $cadenceCheck['nextAllowedAt']?->format('Y-m-d H:i'),
                ]);
                return false;
            }

            // Check business hours
            $bizHours = $this->cadenceGovernor->isWithinBusinessHours($contact);
            if (!$bizHours['inWindow']) {
                $this->logger->info('Outside business hours for contact timezone', [
                    'contactId' => $contact->getId(),
                    'timezone' => $bizHours['timezone'],
                    'localHour' => $bizHours['localHour'],
                    'dayOfWeek' => $bizHours['dayOfWeek'],
                ]);
                return false;
            }
        }

        // ==================== COPY LINT GATE (final check) ====================
        if ($this->copyLintService) {
            $lintResult = $this->copyLintService->lint(
                $message->getSubject() ?? '',
                $message->getBodyText() ?? ''
            );
            if (!$lintResult['passed']) {
                $this->logger->warning('Copy lint blocked send at delivery time', [
                    'messageId' => $message->getId(),
                    'violations' => $lintResult['violations'],
                ]);
                $message->setStatus(OutboundMessage::STATUS_FAILED);
                $this->entityManager->flush();
                return false;
            }
        }

        // ==================== SET REPLY WINDOW EXPIRY ====================
        // For delayed soft-failure processing
        $replyWindowDays = CadenceGovernorService::REPLY_WINDOW_DAYS;
        $message->setReplyWindowExpiry(
            (new \DateTime())->modify("+{$replyWindowDays} days")
        );

        // ==================== CONCURRENCY LOCK ====================
        // Two concurrent hourly runs (or a webhook retry racing a cron) could
        // otherwise double-send to the same message. Short lock (30 min) keyed
        // by message id.
        $lock = null;
        if ($this->lockFactory) {
            $lock = $this->lockFactory->createLock('autonomous_sales_send_' . $message->getId(), 1800, false);
            if (!$lock->acquire()) {
                $this->logger->warning('Send skipped: another process is already handling this message', [
                    'messageId' => $message->getId(),
                ]);
                return false;
            }
        }

        try {
            $email = (new Email())
                ->from($this->resolveSenderAddress())
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

            // Log to unified Activity timeline so CRM users see outbound emails
            if ($this->activityLogger) {
                try {
                    $this->activityLogger->logOutboundSend($message);
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to log outbound send to Activity', [
                        'messageId' => $message->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

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
        } finally {
            $lock?->release();
        }
    }

    /**
     * Resolve the "From" address for outbound autonomous emails.
     *
     * Uses the configured %env(MAILER_FROM_ADDRESS)%-based parameter first
     * (bound as $mailerFromAddress), falls back to the raw environment
     * variable, and only then to a documented fallback.
     */
    private function resolveSenderAddress(): string
    {
        $from = $this->mailerFromAddress
            ?? ($_ENV['MAILER_FROM_ADDRESS'] ?? '')
            ?? '';

        if ($from !== '') {
            return $from;
        }

        $this->logger->warning('MAILER_FROM_ADDRESS not configured — using fallback sender', [
            'fallback' => 'noreply@starzelectronics.site',
        ]);

        return 'noreply@starzelectronics.site';
    }

    /**
     * Record email event (open, click, bounce, etc.) for closed-loop learning
     * 
     * Uses separate event type tracking to allow replies to override open tracking.
     * Open/click events are tracked separately from reply classification.
     * 
     * @param int|OutboundMessage $messageOrId Message ID or entity
     * @param string $eventType Event type: 'open', 'click', 'reply', 'bounce', 'delivered', 'complaint'
     * @param string|null $replyContent Raw reply content for classification (optional)
     * @return array Result with classification and Thompson update info
     */
    public function recordEmailEvent(int|OutboundMessage $messageOrId, string $eventType, ?string $replyContent = null): array
    {
        // Validate against the known event set — unknown types are logged and
        // rejected instead of silently no-op'ing.
        $validEventTypes = ['open', 'click', 'reply', 'bounce', 'delivered', 'complaint'];
        if (!in_array($eventType, $validEventTypes, true)) {
            $this->logger->warning('Ignoring unknown email event type', [
                'messageId' => $messageOrId instanceof OutboundMessage ? $messageOrId->getId() : $messageOrId,
                'eventType' => $eventType,
            ]);
            return ['status' => 'error', 'event_type' => $eventType, 'message' => "Unknown event type: {$eventType}"];
        }

        // Support both ID and entity
        if ($messageOrId instanceof OutboundMessage) {
            $message = $messageOrId;
        } else {
            $message = $this->outboundRepository->find($messageOrId);
        }
        
        if (!$message) {
            $this->logger->warning('Outbound message not found', ['id' => $messageOrId]);
            return ['status' => 'error', 'message' => 'Message not found'];
        }

        // ==================== CONCURRENCY LOCK ====================
        // Prevent duplicate webhook deliveries / concurrent runs from
        // double-recording the same event on the same message.
        $lock = null;
        if ($this->lockFactory) {
            $lock = $this->lockFactory->createLock('autonomous_sales_event_' . $message->getId(), 1800, false);
            if (!$lock->acquire()) {
                $this->logger->warning('Event skipped: another process is already recording this event', [
                    'messageId' => $message->getId(),
                    'eventType' => $eventType,
                ]);
                return ['status' => 'skipped', 'event_type' => $eventType, 'message' => 'Event already being processed'];
            }
        }

        try {
            return $this->doRecordEmailEvent($message, $eventType, $replyContent);
        } finally {
            $lock?->release();
        }
    }

    private function doRecordEmailEvent(OutboundMessage $message, string $eventType, ?string $replyContent = null): array
    {
        $result = [
            'status' => 'recorded',
            'event_type' => $eventType,
            'thompson_updated' => false,
            'classification' => null,
        ];

        // Update message status based on event
        $statusMap = [
            'delivered' => OutboundMessage::STATUS_DELIVERED,
            'open' => OutboundMessage::STATUS_OPENED,
            'click' => OutboundMessage::STATUS_CLICKED,
            'reply' => OutboundMessage::STATUS_REPLIED,
            'bounce' => OutboundMessage::STATUS_BOUNCED,
            'complaint' => OutboundMessage::STATUS_BOUNCED, // Complaints are hard failures like bounces
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

        // Store reply content if provided
        if ($replyContent && method_exists($message, 'setReplyContent')) {
            $message->setReplyContent($replyContent);
        }

        // If this is a reply event with content, classify it
        $classification = null;
        if ($eventType === 'reply' && $replyContent && $this->emailClassifier) {
            $replySubject = $message->getSubject() ? 'Re: ' . $message->getSubject() : '';
            $replyFrom = $message->getContact()?->getEmail() ?? '';
            $classificationResult = $this->emailClassifier->classifyEmail($replySubject, $replyContent, $replyFrom);
            $classification = $classificationResult['classification'];
            $message->setReplyClassification($classification);
            $result['classification'] = $classification;
        }

        // ==================== WEIGHTED THOMPSON UPDATES ====================
        // Record outcome via weighted event system (replaces old integer ±1)
        // Event weights: open=0.10, click=0.30, positive_reply=2.50,
        //   neutral_reply=0.50, negative_reply=3.00, unsubscribe=7.00, bounce=1.00
        $arm = $message->getSubjectArm();
        if ($arm) {
            $currentRecordedType = $message->getRecordedEventType();
            
            // Determine if we should record this event (higher priority overrides lower)
            $eventPriority = ['bounce' => 1, 'open' => 2, 'click' => 3, 'reply' => 4];
            $currentPriority = $eventPriority[$currentRecordedType] ?? 0;
            $newPriority = $eventPriority[$eventType] ?? 0;
            
            $shouldRecord = ($currentRecordedType === null || $newPriority > $currentPriority);
            
            if ($shouldRecord) {
                $weightedEventType = null;

                if ($eventType === 'reply' && $classification) {
                    $positiveClassifications = ['interested', 'meeting_request', 'information_request'];
                    $negativeClassifications = ['not_interested', 'unsubscribe', 'bounce', 'spam'];
                    
                    if (in_array($classification, $positiveClassifications, true)) {
                        $weightedEventType = 'positive_reply';
                    } elseif ($classification === 'unsubscribe') {
                        $weightedEventType = 'unsubscribe';
                    } elseif (in_array($classification, $negativeClassifications, true)) {
                        $weightedEventType = 'negative_reply';
                    } else {
                        $weightedEventType = 'neutral_reply';
                    }
                } elseif (in_array($eventType, ['open', 'click', 'bounce'], true)) {
                    $weightedEventType = $eventType;
                }

                if ($weightedEventType) {
                    // Update subject arm
                    $this->thompsonSampler->recordWeightedOutcome($arm->getId(), $weightedEventType);
                    
                    // Also update value-prop arm if tracked
                    $vpArmId = $message->getValuePropArmId();
                    if ($vpArmId) {
                        $this->thompsonSampler->recordWeightedOutcome($vpArmId, $weightedEventType);
                    }

                    $result['thompson_updated'] = true;
                    $result['weighted_event'] = $weightedEventType;
                    
                    $message->setOutcomeRecorded(true);
                    $message->setRecordedEventType($eventType);
                    
                    // Update funnel stage
                    $funnelStage = match($weightedEventType) {
                        'open' => 'opened',
                        'click' => 'clicked',
                        'positive_reply' => 'engaged',
                        'negative_reply', 'unsubscribe' => 'stopped',
                        'bounce' => 'bounced',
                        default => null,
                    };
                    if ($funnelStage) {
                        $message->setFunnelStage($funnelStage);
                    }

                    // Create opportunity RFQ for positive email engagement
                    if ($weightedEventType === 'positive_reply' && $this->pipelineOrchestrator) {
                        $newRfq = $this->pipelineOrchestrator->afterPositiveEmailEngagement($message, $classification ?? 'interested');
                        $result['opportunity_created'] = $newRfq !== null;
                        if ($newRfq) {
                            $result['rfq_id'] = $newRfq->getId();
                        }
                    }
                }
            }
        }

        $this->entityManager->flush();

        // Log engagement to unified Activity timeline
        if ($this->activityLogger) {
            $activityEventType = match ($eventType) {
                'open' => 'opened',
                'click' => 'clicked',
                'reply' => 'replied',
                'bounce' => 'bounced',
                default => null,
            };
            if ($activityEventType) {
                try {
                    $this->activityLogger->updateOutboundEngagement(
                        $message,
                        $activityEventType,
                        $classification
                    );
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to log outbound engagement to Activity', [
                        'messageId' => $message->getId(),
                        'eventType' => $eventType,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->logger->info('Recorded email event', [
            'messageId' => $message->getId(),
            'eventType' => $eventType,
            'newStatus' => $message->getStatus(),
            'recordedEventType' => $message->getRecordedEventType(),
            'classification' => $classification,
            'thompsonUpdated' => $result['thompson_updated'],
        ]);
        
        return $result;
    }

    /**
     * Apply human-reviewed classification override
     * 
     * When a human reviews a reply classification and corrects it,
     * this method updates the Thompson Sampler accordingly and
     * trains the Naive Bayes model with the corrected classification.
     * 
     * @param OutboundMessage $message The message with the reply
     * @param string $correctClassification The human-reviewed correct classification
     */
    public function applyClassificationOverride(OutboundMessage $message, string $correctClassification): void
    {
        $previousClassification = $message->getReplyClassification();
        $replyContent = $message->getReplyContent();
        
        // Update the classification
        $message->setReplyClassification($correctClassification);
        
        // If the classification changed, update Thompson Sampler
        $arm = $message->getSubjectArm();
        if ($arm && $previousClassification !== $correctClassification) {
            $positiveClassifications = ['interested', 'meeting_request', 'information_request'];
            $negativeClassifications = ['not_interested', 'unsubscribe', 'bounce', 'spam'];
            
            // Reverse previous outcome if it was recorded
            if ($message->getRecordedEventType() === 'reply') {
                $wasPrevPositive = in_array($previousClassification, $positiveClassifications, true);
                $wasPrevNegative = in_array($previousClassification, $negativeClassifications, true);
                
                // Undo previous outcome
                if ($wasPrevPositive) {
                    // Previous was positive, now need to reverse it
                    $arm->setAlpha(max(1.0, $arm->getAlpha() - 2.0)); // Reply was double-weighted
                } elseif ($wasPrevNegative) {
                    $arm->setBeta(max(1.0, $arm->getBeta() - 1.0));
                }
                
                // Apply correct outcome
                $isNowPositive = in_array($correctClassification, $positiveClassifications, true);
                $isNowNegative = in_array($correctClassification, $negativeClassifications, true);
                
                if ($isNowPositive) {
                    $this->thompsonSampler->recordReplyOutcome($arm->getId(), true);
                } elseif ($isNowNegative) {
                    $this->thompsonSampler->recordOutcome($arm->getId(), false, 'reply');
                }
            }
        }
        
        // Train Naive Bayes with corrected classification
        if ($replyContent && $this->emailClassifier) {
            $this->emailClassifier->learnFromHumanReview($replyContent, $correctClassification);
        }
        
        $this->entityManager->flush();
        
        $this->logger->info('Applied classification override', [
            'messageId' => $message->getId(),
            'previousClassification' => $previousClassification,
            'newClassification' => $correctClassification,
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
        $scoredLeads = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from(Lead::class, 'l')
            ->where('l.leadScore >= 0')
            ->getQuery()
            ->getSingleScalarResult();

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
        $this->assertEnabled();

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
        $this->assertEnabled();

        $templates = $this->spintaxEngine->seedDefaultTemplates();
        $arms = $this->thompsonSampler->seedDefaultSubjectLineArms();
        $valuePropArms = [];

        // Seed value prop variants for A/B testing (sanitized to avoid unverified claims)
        if ($this->personalizationService) {
            $industries = [
                'automotive', 'aerospace', 'industrial', 'defense', 'medical',
                'consumer', 'telecom', 'renewables', 'semiconductor', 'rail',
                'hvac', 'marine', 'other',
            ];
            $contentFocuses = ['business', 'technical', 'value_focused', 'relationship'];

            foreach ($industries as $industry) {
                foreach ($contentFocuses as $focus) {
                    $valuePropArms = array_merge(
                        $valuePropArms,
                        $this->personalizationService->seedValuePropVariants($industry, $focus)
                    );
                }
            }
        }
        
        // Seed competitors if service available
        $competitors = [];
        if ($this->competitorLearner) {
            $competitors = $this->competitorLearner->seedCompetitors();
        }

        return [
            'templates' => count($templates),
            'arms' => count($arms),
            'valuePropArms' => count($valuePropArms),
            'competitors' => count($competitors),
        ];
    }

    private function assertEnabled(): void
    {
        if (!$this->settingsService->isEnabled()) {
            throw new \RuntimeException('Autonomous sales system is disabled.');
        }
    }

    /**
     * Extract the functional area from a job title for use in email copy.
     * 
     * "Director, Technology" → "technology"
     * "VP of Procurement" → "procurement"
     * "Chief Technology Officer" → "technology"
     * "Engineering Manager" → "engineering"
     */
    private function extractFunctionalArea(string $jobTitle): string
    {
        $title = strtolower(trim($jobTitle));
        
        if (empty($title)) {
            return 'your area';
        }
        
        // Strip common prefixes/titles
        $title = preg_replace('/\b(senior|junior|chief|vice|executive|lead|head|principal|associate|assistant|deputy|global|regional|group)\b\s*/i', '', $title);
        $title = preg_replace('/\b(officer|manager|director|president|vp|svp|evp|ceo|coo|cto|cfo|cmo|cio|cpo)\b\s*/i', '', $title);
        $title = preg_replace('/\b(of|for|and|the|&)\b/i', ' ', $title);
        $title = preg_replace('/[,\-\/]+/', ' ', $title); // Strip punctuation
        
        $area = trim(preg_replace('/\s+/', ' ', $title));
        
        // If nothing remains, try common mappings
        if (empty($area) || strlen($area) < 3) {
            if (preg_match('/\b(cto|cio|technology|tech|it|digital)\b/i', $jobTitle)) return 'technology';
            if (preg_match('/\b(ceo|coo|president|general)\b/i', $jobTitle)) return 'business operations';
            if (preg_match('/\b(cfo|finance|financial)\b/i', $jobTitle)) return 'finance';
            if (preg_match('/\b(cmo|marketing)\b/i', $jobTitle)) return 'marketing';
            if (preg_match('/\b(cpo|procurement|purchasing|sourcing)\b/i', $jobTitle)) return 'procurement';
            return 'your area';
        }
        
        return strtolower($area);
    }
}
