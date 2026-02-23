<?php

namespace App\Service;

use App\Entity\InboxMessage;
use App\Entity\OutboundMessage;
use App\Repository\InboxMessageRepository;
use App\Repository\ContactRepository;
use App\Repository\BayesTrainingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Email Classifier Service (Clerk)
 * 
 * Classifies incoming emails using Naive Bayes + rule-based classification.
 * Supports human-in-the-loop review for uncertain classifications.
 * Now uses persistent Bayes model from database that learns from human reviews.
 * 
 * Classification Categories:
 * - INTERESTED: Positive response → Queue for follow-up
 * - NOT_INTERESTED: Polite decline → Mark as closed
 * - UNSUBSCRIBE: Opt-out request → Remove from list
 * - OUT_OF_OFFICE: Auto-reply → Re-queue later
 * - BOUNCE: Delivery failure → Mark invalid
 * - UNKNOWN: Uncertain → Queue for human review
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
class EmailClassifierService
{
    private const CONFIDENCE_THRESHOLD = 0.70; // Below this, flag for human review
    
    // Cache for Bayes model (loaded from database)
    private ?array $bayesModelCache = null;
    private ?int $bayesModelCacheTime = null;
    private const BAYES_CACHE_TTL = 300; // 5 minutes

    // Rule-based patterns (fast path)
    // NOTE: 'please remove' moved from NOT_INTERESTED to only UNSUBSCRIBE to avoid overlap
    private const RULE_PATTERNS = [
        InboxMessage::CLASSIFICATION_UNSUBSCRIBE => [
            '/\bunsubscribe\b/i',
            '/remove.*from.*list/i',
            '/stop.*email(ing)?/i',
            '/opt.?out/i',
            '/no longer wish to receive/i',
            '/please remove/i',
        ],
        InboxMessage::CLASSIFICATION_OUT_OF_OFFICE => [
            '/out of (the )?office/i',
            '/on vacation/i',
            '/away from (my )?desk/i',
            '/automatic reply/i',
            '/auto.?reply/i',
            '/will (return|be back)/i',
            '/limited access to email/i',
            '/currently (out|away|traveling)/i',
        ],
        InboxMessage::CLASSIFICATION_BOUNCE => [
            '/delivery.*fail/i',
            '/undeliverable/i',
            '/mailbox.*full/i',
            '/user.*unknown/i',
            '/address.*rejected/i',
            '/does not exist/i',
            '/no such user/i',
            '/mail.*returned/i',
            '/permanent.*failure/i',
        ],
        InboxMessage::CLASSIFICATION_INTERESTED => [
            '/sounds interesting/i',
            '/tell me more/i',
            '/schedule.*call/i',
            '/let\'?s (talk|connect|discuss)/i',
            '/I\'?d (like|love) to (learn|know|hear)/i',
            '/please send.*information/i',
            '/can (you|we) (talk|meet|call)/i',
            '/interested in/i',
            '/good timing/i',
            '/perfect timing/i',
            '/reach out to me/i',
        ],
        InboxMessage::CLASSIFICATION_NOT_INTERESTED => [
            '/not interested/i',
            '/no thank/i',
            // Removed 'please remove' - it's in UNSUBSCRIBE
            '/don\'?t contact/i',
            '/not looking/i',
            '/not a (good )?fit/i',
            '/not for (us|me)/i',
            '/wrong (person|contact)/i',
            '/already have/i',
            '/no need/i',
        ],
    ];

    // Fallback Bayes model (used only if database is empty)
    private const FALLBACK_BAYES_MODEL = [
        'interested' => [
            'interested' => 10, 'call' => 8, 'discuss' => 7, 'meeting' => 7,
            'learn' => 6, 'more' => 6, 'schedule' => 6, 'talk' => 6,
            'great' => 5, 'perfect' => 5, 'sounds' => 5, 'timing' => 5,
            'yes' => 5, 'definitely' => 4, 'connect' => 4, 'forward' => 4,
        ],
        'not_interested' => [
            'not' => 10, 'no' => 9, 'thanks' => 8, 'thank' => 8,
            'remove' => 7, 'unsubscribe' => 7, 'stop' => 6, 'interested' => 6,
            'don\'t' => 5, 'wrong' => 5, 'already' => 5, 'have' => 5,
            'pass' => 4, 'decline' => 4, 'need' => 4,
        ],
        'out_of_office' => [
            'out' => 10, 'office' => 10, 'vacation' => 8, 'away' => 8,
            'return' => 7, 'back' => 7, 'automatic' => 6, 'reply' => 6,
            'limited' => 5, 'access' => 5, 'currently' => 5, 'traveling' => 5,
        ],
        'bounce' => [
            'delivery' => 10, 'failed' => 10, 'undeliverable' => 9, 'mailbox' => 8,
            'full' => 6, 'unknown' => 8, 'rejected' => 7, 'exist' => 6,
            'permanent' => 7, 'error' => 5,
        ],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private InboxMessageRepository $inboxRepository,
        private ContactRepository $contactRepository,
        private ThompsonSamplerService $thompsonSampler,
        private ?BayesTrainingRepository $bayesTrainingRepository,
        private LoggerInterface $logger,
        private ?LlmService $llmService = null,
    ) {}

    /**
     * Classify an email
     */
    public function classifyEmail(string $subject, string $body, string $fromEmail): array
    {
        $combinedText = $subject . ' ' . $body;
        
        // First, try rule-based classification (fast path)
        $ruleResult = $this->classifyByRules($combinedText);
        
        if ($ruleResult['classification'] !== null) {
            $this->logger->debug('Email classified by rules', [
                'fromEmail' => $fromEmail,
                'classification' => $ruleResult['classification'],
            ]);
            
            return [
                'classification' => $ruleResult['classification'],
                'confidence' => 0.95,
                'method' => InboxMessage::METHOD_RULE_BASED,
                'requiresReview' => false,
                'matchedPattern' => $ruleResult['matchedPattern'],
            ];
        }
        
        // Fallback to Naive Bayes
        $bayesResult = $this->classifyByNaiveBayes($combinedText);
        
        $requiresReview = $bayesResult['confidence'] < self::CONFIDENCE_THRESHOLD;

        // ── LLM TIEBREAKER ─────────────────────────────────────────
        // When Bayes is uncertain, ask the local LLM for a second opinion.
        // If the LLM agrees with Bayes, boost confidence; if it disagrees
        // but is itself confident, prefer the LLM verdict.
        $method = InboxMessage::METHOD_NAIVE_BAYES;
        if ($requiresReview && $this->llmService !== null) {
            try {
                $llmResult = $this->llmService->classifyEmail($subject, $body, $fromEmail ?? null);
                if ($llmResult !== null) {
                    $llmCategory = strtoupper($llmResult['category'] ?? '');
                    $llmConfidence = (float) ($llmResult['confidence'] ?? 0.5);
                    $this->logger->info('LLM email classification', [
                        'fromEmail' => $fromEmail,
                        'llm_category' => $llmCategory,
                        'llm_confidence' => $llmConfidence,
                        'bayes_classification' => $bayesResult['classification'],
                        'bayes_confidence' => $bayesResult['confidence'],
                    ]);

                    // Map LLM category to system categories
                    $categoryMap = [
                        'INTERESTED' => InboxMessage::CLASSIFICATION_INTERESTED,
                        'NOT_INTERESTED' => InboxMessage::CLASSIFICATION_NOT_INTERESTED,
                        'UNSUBSCRIBE' => InboxMessage::CLASSIFICATION_UNSUBSCRIBE,
                        'OUT_OF_OFFICE' => InboxMessage::CLASSIFICATION_OUT_OF_OFFICE,
                        'BOUNCE' => InboxMessage::CLASSIFICATION_BOUNCE,
                        'FORWARD' => InboxMessage::CLASSIFICATION_INTERESTED, // forwards mean someone is engaging
                    ];
                    $mappedCategory = $categoryMap[$llmCategory] ?? null;

                    if ($mappedCategory !== null && $llmConfidence >= 0.7) {
                        if ($mappedCategory === $bayesResult['classification']) {
                            // Both agree — boost confidence, no review needed
                            $bayesResult['confidence'] = min(0.95, $bayesResult['confidence'] + 0.2);
                            $requiresReview = false;
                            $method = 'llm_confirmed';
                        } else {
                            // LLM disagrees — if LLM is highly confident, override
                            if ($llmConfidence >= 0.85) {
                                $bayesResult['classification'] = $mappedCategory;
                                $bayesResult['confidence'] = $llmConfidence;
                                $requiresReview = false;
                                $method = 'llm_override';
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->debug('LLM email classification failed, falling back to Bayes', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->debug('Email classified by Naive Bayes', [
            'fromEmail' => $fromEmail,
            'classification' => $bayesResult['classification'],
            'confidence' => $bayesResult['confidence'],
            'requiresReview' => $requiresReview,
        ]);
        
        return [
            'classification' => $bayesResult['classification'],
            'confidence' => $bayesResult['confidence'],
            'method' => $method,
            'requiresReview' => $requiresReview,
            'probabilities' => $bayesResult['probabilities'],
        ];
    }

    /**
     * Classify using rule-based patterns
     */
    private function classifyByRules(string $text): array
    {
        foreach (self::RULE_PATTERNS as $classification => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text, $matches)) {
                    return [
                        'classification' => $classification,
                        'matchedPattern' => $pattern,
                    ];
                }
            }
        }
        
        return [
            'classification' => null,
            'matchedPattern' => null,
        ];
    }

    /**
     * Classify using Naive Bayes with persistent database model
     */
    private function classifyByNaiveBayes(string $text): array
    {
        // Tokenize and normalize
        $words = $this->tokenize($text);
        
        // Load Bayes model from database (with caching)
        $bayesModel = $this->loadBayesModel();
        
        $probabilities = [];
        $totalClasses = count($bayesModel);
        
        if ($totalClasses === 0) {
            return [
                'classification' => InboxMessage::CLASSIFICATION_UNKNOWN,
                'confidence' => 0.0,
                'probabilities' => [],
            ];
        }
        
        foreach ($bayesModel as $class => $wordFreqs) {
            // Prior probability (uniform for simplicity)
            $logProb = log(1.0 / $totalClasses);
            
            // Total words in this class
            $totalClassWords = array_sum($wordFreqs);
            $vocabularySize = count($wordFreqs);
            
            // Prevent division by zero
            if ($totalClassWords === 0) {
                $totalClassWords = 1;
            }
            if ($vocabularySize === 0) {
                $vocabularySize = 1;
            }
            
            foreach ($words as $word) {
                // Laplace smoothing
                $wordCount = $wordFreqs[strtolower($word)] ?? 0;
                $probability = ($wordCount + 1) / ($totalClassWords + $vocabularySize);
                $logProb += log($probability);
            }
            
            $probabilities[$class] = $logProb;
        }
        
        // Normalize probabilities using softmax
        $maxLogProb = max($probabilities);
        $expProbs = [];
        foreach ($probabilities as $class => $logProb) {
            $expProbs[$class] = exp($logProb - $maxLogProb);
        }
        $sumExp = array_sum($expProbs);
        
        $normalizedProbs = [];
        foreach ($expProbs as $class => $expProb) {
            $normalizedProbs[$class] = $expProb / $sumExp;
        }
        
        // Get best classification
        arsort($normalizedProbs);
        $bestClass = array_key_first($normalizedProbs);
        $confidence = $normalizedProbs[$bestClass];
        
        // Map internal class names to classification constants
        $classificationMap = [
            'interested' => InboxMessage::CLASSIFICATION_INTERESTED,
            'not_interested' => InboxMessage::CLASSIFICATION_NOT_INTERESTED,
            'out_of_office' => InboxMessage::CLASSIFICATION_OUT_OF_OFFICE,
            'bounce' => InboxMessage::CLASSIFICATION_BOUNCE,
            'unsubscribe' => InboxMessage::CLASSIFICATION_UNSUBSCRIBE,
        ];
        
        $classification = $classificationMap[$bestClass] ?? InboxMessage::CLASSIFICATION_UNKNOWN;
        
        // If confidence is too low, mark as unknown
        if ($confidence < 0.5) {
            $classification = InboxMessage::CLASSIFICATION_UNKNOWN;
        }
        
        return [
            'classification' => $classification,
            'confidence' => round($confidence, 4),
            'probabilities' => $normalizedProbs,
        ];
    }

    /**
     * Load Bayes model from database with caching
     */
    private function loadBayesModel(): array
    {
        // Check cache
        if ($this->bayesModelCache !== null && 
            $this->bayesModelCacheTime !== null &&
            (time() - $this->bayesModelCacheTime) < self::BAYES_CACHE_TTL) {
            return $this->bayesModelCache;
        }
        
        // Load from database if repository available
        if ($this->bayesTrainingRepository) {
            $model = $this->bayesTrainingRepository->getAllWordFrequencies();
            
            if (!empty($model)) {
                $this->bayesModelCache = $model;
                $this->bayesModelCacheTime = time();
                return $model;
            }
        }
        
        // Fallback to hardcoded model
        $this->bayesModelCache = self::FALLBACK_BAYES_MODEL;
        $this->bayesModelCacheTime = time();
        return self::FALLBACK_BAYES_MODEL;
    }

    /**
     * Clear Bayes model cache (call after human review updates)
     */
    public function clearBayesCache(): void
    {
        $this->bayesModelCache = null;
        $this->bayesModelCacheTime = null;
    }

    /**
     * Learn from human review (update Bayes model)
     * 
     * When a human corrects a classification, we update the word frequencies
     * in the database to improve future classifications.
     */
    public function learnFromHumanReview(string $text, string $correctClassification): int
    {
        if (!$this->bayesTrainingRepository) {
            $this->logger->warning('Bayes training repository not available');
            return 0;
        }
        
        // Map classification to internal class name
        $classMap = [
            InboxMessage::CLASSIFICATION_INTERESTED => 'interested',
            InboxMessage::CLASSIFICATION_NOT_INTERESTED => 'not_interested',
            InboxMessage::CLASSIFICATION_OUT_OF_OFFICE => 'out_of_office',
            InboxMessage::CLASSIFICATION_BOUNCE => 'bounce',
            InboxMessage::CLASSIFICATION_UNSUBSCRIBE => 'unsubscribe',
        ];
        
        $internalClass = $classMap[$correctClassification] ?? null;
        if (!$internalClass) {
            $this->logger->warning('Unknown classification for learning', [
                'classification' => $correctClassification,
            ]);
            return 0;
        }
        
        // Update word frequencies
        $updatedCount = $this->bayesTrainingRepository->learnFromText($text, $internalClass);
        
        // Clear cache to pick up new frequencies
        $this->clearBayesCache();
        
        $this->logger->info('Learned from human review', [
            'classification' => $correctClassification,
            'wordsUpdated' => $updatedCount,
        ]);
        
        return $updatedCount;
    }

    /**
     * Get Bayes model statistics
     */
    public function getBayesModelStats(): array
    {
        if (!$this->bayesTrainingRepository) {
            return ['error' => 'Repository not available'];
        }
        
        return $this->bayesTrainingRepository->getModelStats();
    }

    /**
     * Tokenize text into words
     */
    private function tokenize(string $text): array
    {
        // Convert to lowercase and extract words
        $text = strtolower($text);
        preg_match_all('/\b[a-z\']+\b/', $text, $matches);
        
        return $matches[0] ?? [];
    }

    /**
     * Process incoming email and create InboxMessage
     */
    public function processIncomingEmail(
        string $fromEmail,
        string $subject,
        string $body,
        ?OutboundMessage $inReplyTo = null,
        ?array $metadata = null
    ): InboxMessage {
        // Classify the email
        $result = $this->classifyEmail($subject, $body, $fromEmail);
        
        // Create inbox message
        $inboxMessage = new InboxMessage();
        $inboxMessage->setFromEmail($fromEmail);
        $inboxMessage->setSubject($subject);
        $inboxMessage->setBodyText($body);
        $inboxMessage->setClassification($result['classification']);
        $inboxMessage->setClassificationConfidence($result['confidence']);
        $inboxMessage->setClassificationMethod($result['method']);
        $inboxMessage->setRequiresHumanReview($result['requiresReview']);
        $inboxMessage->setMetadata($metadata);
        
        if ($inReplyTo) {
            $inboxMessage->setInReplyTo($inReplyTo);
        }
        
        // Try to link to contact
        $contact = $this->contactRepository->findOneBy(['email' => $fromEmail]);
        if ($contact) {
            $inboxMessage->setContact($contact);
        }
        
        $this->entityManager->persist($inboxMessage);
        $this->entityManager->flush();
        
        // If this is a reply to an outbound message, update Thompson Sampler
        if ($inReplyTo && $inReplyTo->getSubjectArm()) {
            $this->updateThompsonSamplerFromReplyWithAmplification($inReplyTo, $inboxMessage);
        }
        
        $this->logger->info('Processed incoming email', [
            'id' => $inboxMessage->getId(),
            'fromEmail' => $fromEmail,
            'classification' => $result['classification'],
            'confidence' => $result['confidence'],
            'requiresReview' => $result['requiresReview'],
        ]);
        
        return $inboxMessage;
    }

    /**
     * Submit human review for a message
     */
    public function submitHumanReview(int $messageId, string $correctClassification, string $reviewedBy): void
    {
        $message = $this->inboxRepository->find($messageId);
        
        if (!$message) {
            throw new \InvalidArgumentException("Message not found: $messageId");
        }
        
        $oldClassification = $message->getClassification();
        
        $message->setClassification($correctClassification);
        $message->setClassificationMethod(InboxMessage::METHOD_HUMAN);
        $message->setHumanReviewedAt(new \DateTime());
        $message->setReviewedBy($reviewedBy);
        $message->setRequiresHumanReview(false);
        $message->setClassificationConfidence(1.0); // Human = 100% confidence
        
        $this->entityManager->flush();
        
        // Learn from this review to improve Bayes model
        $text = ($message->getSubject() ?? '') . ' ' . ($message->getBodyText() ?? '');
        $wordsLearned = $this->learnFromHumanReview($text, $correctClassification);
        
        // If linked to outbound message, update Thompson Sampler with proper reply tracking
        $inReplyTo = $message->getInReplyTo();
        if ($inReplyTo && $inReplyTo->getSubjectArm()) {
            $this->updateThompsonSamplerFromReplyWithAmplification($inReplyTo, $message);
        }
        
        $this->logger->info('Human review submitted', [
            'messageId' => $messageId,
            'oldClassification' => $oldClassification,
            'newClassification' => $correctClassification,
            'reviewedBy' => $reviewedBy,
            'wordsLearned' => $wordsLearned,
        ]);
    }

    /**
     * Update Thompson Sampler from reply with proper tracking and amplification
     * 
     * Replies are more valuable signals than opens. This method:
     * 1. Allows replies to override previous open tracking
     * 2. Uses amplified recording (2x weight) for reply signals
     * 3. Stores reply classification for analytics
     */
    private function updateThompsonSamplerFromReplyWithAmplification(OutboundMessage $outbound, InboxMessage $inbox): void
    {
        $arm = $outbound->getSubjectArm();
        if (!$arm) {
            return;
        }
        
        // Check if we can record reply (replies can override open tracking)
        if (!$outbound->canRecordReplyOutcome()) {
            $this->logger->debug('Reply outcome already recorded', [
                'outboundId' => $outbound->getId(),
            ]);
            return;
        }
        
        $classification = $inbox->getClassification();
        $positiveReply = $inbox->isPositiveResponse();
        
        // Use amplified recording for replies (2x weight)
        $this->thompsonSampler->recordReplyOutcome($arm->getId(), $positiveReply, $classification);
        
        // Update tracking
        $outbound->setOutcomeRecorded(true);
        $outbound->setRecordedEventType('reply');
        $outbound->setReplyClassification($classification);
        $outbound->setStatus(OutboundMessage::STATUS_REPLIED);
        $outbound->setRepliedAt(new \DateTime());
        
        $this->entityManager->flush();
        
        $this->logger->info('Updated Thompson Sampler from reply with amplification', [
            'armId' => $arm->getId(),
            'armName' => $arm->getArmName(),
            'positiveReply' => $positiveReply,
            'classification' => $classification,
        ]);
    }

    /**
     * Get classification statistics
     */
    public function getClassificationStats(): array
    {
        return $this->inboxRepository->getClassificationStats();
    }

    /**
     * Get Naive Bayes model statistics from database
     * 
     * Returns word counts and frequency information per classification
     * for monitoring the ML model's training state.
     */
    public function getBayesStatistics(): array
    {
        $stats = [
            'byClassification' => [],
            'totalWords' => 0,
            'trainingExamples' => 0,
            'status' => 'active',
        ];
        
        if (!$this->bayesTrainingRepository) {
            $stats['status'] = 'in-memory-only';
            
            // Return stats from fallback model
            foreach (self::FALLBACK_BAYES_MODEL as $classification => $words) {
                $stats['byClassification'][$classification] = [
                    'words' => count($words),
                    'frequency' => array_sum($words),
                ];
                $stats['totalWords'] += count($words);
                $stats['trainingExamples'] += array_sum($words);
            }
            
            return $stats;
        }
        
        try {
            // Query database for statistics
            $qb = $this->bayesTrainingRepository->createQueryBuilder('b');
            $result = $qb->select('b.classification, COUNT(b.word) as word_count, SUM(b.frequency) as total_freq')
                ->groupBy('b.classification')
                ->getQuery()
                ->getResult();
            
            foreach ($result as $row) {
                $stats['byClassification'][$row['classification']] = [
                    'words' => (int) $row['word_count'],
                    'frequency' => (int) $row['total_freq'],
                ];
                $stats['totalWords'] += (int) $row['word_count'];
                $stats['trainingExamples'] += (int) $row['total_freq'];
            }
            
            // If database is empty, use fallback model stats
            if (empty($stats['byClassification'])) {
                $stats['status'] = 'fallback-to-memory';
                foreach (self::FALLBACK_BAYES_MODEL as $classification => $words) {
                    $stats['byClassification'][$classification] = [
                        'words' => count($words),
                        'frequency' => array_sum($words),
                    ];
                    $stats['totalWords'] += count($words);
                    $stats['trainingExamples'] += array_sum($words);
                }
            }
        } catch (\Exception $e) {
            $stats['status'] = 'error: ' . $e->getMessage();
        }
        
        return $stats;
    }
}
