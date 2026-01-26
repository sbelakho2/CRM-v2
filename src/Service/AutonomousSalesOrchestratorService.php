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
        // Get contact's company info for context
        $company = $contact->getCompany();
        
        // Build base context - now includes sender_company for templates
        $baseContext = array_merge([
            'first_name' => $contact->getFirstName() ?? 'there',
            'last_name' => $contact->getLastName() ?? '',
            'company_name' => $company?->getName() ?? 'your company',
            'job_title' => $contact->getJobTitle() ?? '',
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
                $fullContext['value_prop_short'] = $this->personalizationService->enforceContentLength(
                    $valuePropResult['value_prop'], 
                    'brief'
                );
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

        // Thompson Sampling: Select best subject line arm
        // IMPROVEMENT: Now campaign-aware - uses campaign-specific arms when campaignId provided
        $armName = $campaignId ? "subject_line_{$campaignId}" : 'subject_line';
        $armResult = $this->thompsonSampler->sampleAndSelect($armName);
        
        // If campaign-specific arm not found, fall back to generic
        if (!$armResult && $campaignId) {
            $armResult = $this->thompsonSampler->sampleAndSelect('subject_line');
        }
        
        // If we have a subject arm, use it instead of template subject
        $subjectSpintax = $template->getSubjectSpintax();
        $subjectArm = null;
        
        if ($armResult) {
            $subjectArm = $armResult['arm'];
            $subjectSpintax = $subjectArm->getArmValue();
        }
        
        // ==================== SUBJECT LINE SYNTHESIS ====================
        // Apply learned successful patterns to subject line
        if ($this->personalizationService && !empty($context['successfulPatterns'])) {
            $subjectSpintax = $this->personalizationService->synthesizeSubjectLine($contact, $subjectSpintax);
        }

        // Spin and personalize the message with full context
        $result = $this->spintaxEngine->spinAndPersonalize(
            $subjectSpintax,
            $template->getBodySpintax(),
            $fullContext
        );
        
        // Apply tone transformations to the final output if personalization service is available
        if ($this->personalizationService && $personalization) {
            $tone = $personalization['tone'] ?? 'formal';
            $engagementLevel = $personalization['engagementLevel'] ?? 'cold';
            
            // ==================== OUTPUT QUALITY FIXES ====================
            // Apply all quality fixes: You-focus, tone, length, and cleanup
            // This eliminates "We"-focused language and ensures natural flow
            $result['body'] = $this->personalizationService->applyOutputQualityFixes(
                $result['body'], 
                $engagementLevel,
                $tone
            );
            
            // ==================== QUALITY VALIDATION ====================
            // Run comprehensive quality checks on the final email
            // This includes warmth validation, templated language detection, etc.
            $qualityCheck = $this->personalizationService->runFullQualityCheck(
                $result['body'],
                $contact,
                $fullContext
            );
        } else {
            $qualityCheck = null;
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

        // ==================== SERVICE TYPE CHECK ====================
        $serviceType = $context['service_type'] ?? null;
        if ($serviceType) {
            $templateName = match($serviceType) {
                'cable_harness', 'harness', 'wire' => 'Initial Outreach - Cable Harness',
                'pcba', 'pcb', 'electronics' => 'Initial Outreach - PCBA',
                'competitor_displacement' => 'Competitor Displacement',
                default => 'Initial Outreach - PCBA',
            };
            $template = $this->templateRepository->findOneBy(['name' => $templateName, 'active' => true]);
            if ($template) {
                return $template;
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
     * 
     * Uses separate event type tracking to allow replies to override open tracking.
     * Open/click events are tracked separately from reply classification.
     * 
     * @param int|OutboundMessage $messageOrId Message ID or entity
     * @param string $eventType Event type: 'open', 'click', 'reply', 'bounce', 'delivered'
     * @param string|null $replyContent Raw reply content for classification (optional)
     * @return array Result with classification and Thompson update info
     */
    public function recordEmailEvent(int|OutboundMessage $messageOrId, string $eventType, ?string $replyContent = null): array
    {
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
            $classificationResult = $this->emailClassifier->classifyReply($replyContent);
            $classification = $classificationResult['classification'];
            $message->setReplyClassification($classification);
            $result['classification'] = $classification;
        }

        // Record outcome in Thompson Sampler with proper event tracking
        // Key fix: Track by event type, not just boolean "recorded"
        // This allows replies to properly override open tracking
        $arm = $message->getSubjectArm();
        if ($arm) {
            $currentRecordedType = $message->getRecordedEventType();
            
            // Determine if we should record this event
            $shouldRecord = false;
            $eventPriority = ['bounce' => 1, 'open' => 2, 'click' => 3, 'reply' => 4];
            $currentPriority = $eventPriority[$currentRecordedType] ?? 0;
            $newPriority = $eventPriority[$eventType] ?? 0;
            
            // Record if: no previous record, or this event has higher priority
            if ($currentRecordedType === null || $newPriority > $currentPriority) {
                $shouldRecord = true;
            }
            
            if ($shouldRecord) {
                // For replies, determine success based on classification
                if ($eventType === 'reply' && $classification) {
                    // Positive: interested
                    // Neutral: out_of_office, unknown (don't update)
                    // Negative: not_interested, unsubscribe, bounce
                    $positiveClassifications = ['interested', 'meeting_request', 'information_request'];
                    $negativeClassifications = ['not_interested', 'unsubscribe', 'bounce', 'spam'];
                    
                    if (in_array($classification, $positiveClassifications, true)) {
                        // Use reply-specific recording with amplified reward
                        $this->thompsonSampler->recordReplyOutcome(
                            $arm->getId(),
                            true  // Positive reply
                        );
                        $result['thompson_updated'] = true;
                    } elseif (in_array($classification, $negativeClassifications, true)) {
                        $this->thompsonSampler->recordOutcome(
                            $arm->getId(),
                            false,  // Failure
                            'reply'
                        );
                        $result['thompson_updated'] = true;
                    }
                    // Out of office and unknown don't update Thompson (neutral)
                } else {
                    // Regular event handling
                    $success = in_array($eventType, ['open', 'click'], true);
                    $failure = in_array($eventType, ['bounce'], true);

                    if ($success || $failure) {
                        $this->thompsonSampler->recordOutcome(
                            $arm->getId(),
                            $success,
                            $eventType
                        );
                        $result['thompson_updated'] = true;
                    }
                }
                
                if ($result['thompson_updated']) {
                    $message->setOutcomeRecorded(true);
                    $message->setRecordedEventType($eventType);
                }
            }
        }

        $this->entityManager->flush();

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
