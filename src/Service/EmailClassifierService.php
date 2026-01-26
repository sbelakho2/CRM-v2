<?php

namespace App\Service;

use App\Entity\InboxMessage;
use App\Entity\OutboundMessage;
use App\Repository\InboxMessageRepository;
use App\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Email Classifier Service (Clerk)
 * 
 * Classifies incoming emails using Naive Bayes + rule-based classification.
 * Supports human-in-the-loop review for uncertain classifications.
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

    // Rule-based patterns (fast path)
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
            '/please remove/i',
            '/don\'?t contact/i',
            '/not looking/i',
            '/not a (good )?fit/i',
            '/not for (us|me)/i',
            '/wrong (person|contact)/i',
            '/already have/i',
            '/no need/i',
        ],
    ];

    // Naive Bayes training data (word frequencies per class)
    // In production, this would be loaded from database (sa_bayes_training)
    private array $bayesModel = [
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
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private InboxMessageRepository $inboxRepository,
        private ContactRepository $contactRepository,
        private ThompsonSamplerService $thompsonSampler,
        private LoggerInterface $logger
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
        
        $this->logger->debug('Email classified by Naive Bayes', [
            'fromEmail' => $fromEmail,
            'classification' => $bayesResult['classification'],
            'confidence' => $bayesResult['confidence'],
            'requiresReview' => $requiresReview,
        ]);
        
        return [
            'classification' => $bayesResult['classification'],
            'confidence' => $bayesResult['confidence'],
            'method' => InboxMessage::METHOD_NAIVE_BAYES,
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
     * Classify using Naive Bayes
     */
    private function classifyByNaiveBayes(string $text): array
    {
        // Tokenize and normalize
        $words = $this->tokenize($text);
        
        $probabilities = [];
        $totalClasses = count($this->bayesModel);
        
        foreach ($this->bayesModel as $class => $wordFreqs) {
            // Prior probability (uniform for simplicity)
            $logProb = log(1.0 / $totalClasses);
            
            // Total words in this class
            $totalClassWords = array_sum($wordFreqs);
            $vocabularySize = count($wordFreqs);
            
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
            $this->updateThompsonSamplerFromReply($inReplyTo, $inboxMessage);
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
     * Update Thompson Sampler based on reply classification
     */
    private function updateThompsonSamplerFromReply(OutboundMessage $outbound, InboxMessage $inbox): void
    {
        if ($outbound->isOutcomeRecorded()) {
            return; // Already recorded
        }
        
        $arm = $outbound->getSubjectArm();
        if (!$arm) {
            return;
        }
        
        // Positive reply = success, negative reply = failure
        $success = $inbox->isPositiveResponse();
        
        $this->thompsonSampler->recordOutcome($arm->getId(), $success);
        
        // Mark as recorded
        $outbound->setOutcomeRecorded(true);
        $outbound->setStatus(OutboundMessage::STATUS_REPLIED);
        $outbound->setRepliedAt(new \DateTime());
        
        $this->entityManager->flush();
        
        $this->logger->info('Updated Thompson Sampler from reply', [
            'armId' => $arm->getId(),
            'armName' => $arm->getArmName(),
            'success' => $success,
            'classification' => $inbox->getClassification(),
        ]);
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
        
        // If linked to outbound message, update Thompson Sampler
        $inReplyTo = $message->getInReplyTo();
        if ($inReplyTo && $inReplyTo->getSubjectArm() && !$inReplyTo->isOutcomeRecorded()) {
            $this->updateThompsonSamplerFromReply($inReplyTo, $message);
        }
        
        $this->logger->info('Human review submitted', [
            'messageId' => $messageId,
            'oldClassification' => $oldClassification,
            'newClassification' => $correctClassification,
            'reviewedBy' => $reviewedBy,
        ]);
    }

    /**
     * Get classification statistics
     */
    public function getClassificationStats(): array
    {
        return $this->inboxRepository->getClassificationStats();
    }
}
