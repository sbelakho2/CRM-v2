<?php

namespace App\Controller\Api;

use App\Entity\BanditArm;
use App\Entity\SpintaxTemplate;
use App\Entity\InboxMessage;
use App\Repository\BanditArmRepository;
use App\Repository\ContactRepository;
use App\Repository\InboxMessageRepository;
use App\Repository\LeadRepository;
use App\Repository\LearnedCompetitorRepository;
use App\Repository\OutboundMessageRepository;
use App\Repository\PersonalizationProfileRepository;
use App\Repository\SpintaxTemplateRepository;
use App\Service\AutonomousSalesOrchestratorService;
use App\Service\CompetitorLearnerService;
use App\Service\EmailClassifierService;
use App\Service\EmailPersonalizationService;
use App\Service\SpintaxEngineService;
use App\Service\ThompsonSamplerService;
use App\Service\CompetitorDetectionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Autonomous Sales API Controller
 * 
 * Provides REST API endpoints for the Autonomous Sales System.
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
#[Route('/api/autonomous')]
class AutonomousSalesController extends AbstractController
{
    public function __construct(
        private AutonomousSalesOrchestratorService $orchestrator,
        private ThompsonSamplerService $thompsonSampler,
        private SpintaxEngineService $spintaxEngine,
        private EmailClassifierService $emailClassifier,
        private CompetitorDetectionService $competitorDetection,
        private ?CompetitorLearnerService $competitorLearner,
        private ?EmailPersonalizationService $personalizationService,
        private EntityManagerInterface $entityManager,
        private BanditArmRepository $armRepository,
        private SpintaxTemplateRepository $templateRepository,
        private ContactRepository $contactRepository,
        private LeadRepository $leadRepository,
        private InboxMessageRepository $inboxRepository,
        private OutboundMessageRepository $outboundRepository,
        private ?LearnedCompetitorRepository $learnedCompetitorRepository,
        private ?PersonalizationProfileRepository $personalizationRepository,
        private RateLimiterFactory $apiGeneralLimiter,
        private LoggerInterface $logger
    ) {}

    /**
     * Validate the CSRF token sent via the X-CSRF-Token header (or _token body field)
     * and apply the api_general rate limit (except for webhook routes, which must
     * not be throttled in a way that could break mail delivery).
     * Returns a 403/429 JsonResponse when rejected, null when accepted.
     */
    private function requireCsrf(Request $request): ?JsonResponse
    {
        $token = $request->headers->get('X-CSRF-Token')
            ?? $request->request->get('_token')
            ?? $this->getRequestBodyValue($request, '_token');

        if (!$this->isCsrfTokenValid('autonomous_sales', $token ?? '')) {
            return $this->json(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $route = $request->attributes->get('_route');
        if (!in_array($route, ['api_autonomous_webhook_email', 'api_autonomous_webhook_sendgrid'], true)) {
            $limiter = $this->apiGeneralLimiter->create($this->getUser()?->getUserIdentifier() ?? (string) $request->getClientIp());
            $limit = $limiter->consume();
            if (!$limit->isAccepted()) {
                $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();
                return $this->json(['success' => false, 'error' => 'Too many requests. Please try again later.'], 429, [
                    'Retry-After' => (string) max(1, $retryAfter),
                ]);
            }
        }

        return null;
    }

    private function getRequestBodyValue(Request $request, string $key): mixed
    {
        /** @var array<string, mixed>|null $data */
        /** @var array<string, mixed>|null $data */
        $data = json_decode($request->getContent(), true);
        return is_array($data) ? ($data[$key] ?? null) : null;
    }

    /**
     * Authenticate inbound provider webhook callbacks (Mailgun/SendGrid).
     *
     * Providers cannot carry a browser CSRF token, so the webhook routes are
     * CSRF-exempt and instead authenticate via the shared webhook secret
     * (EMAIL_WEBHOOK_SECRET) presented in the X-Webhook-Secret header. The
     * comparison is timing-safe (hash_equals) and fails closed: when the
     * secret is not configured, the request is rejected rather than processed.
     * This mirrors EmailWebhookController::enforceWebhookSecret().
     * Returns a 401/403 JsonResponse when rejected, null when accepted.
     */
    private function authenticateWebhook(Request $request): ?JsonResponse
    {
        $configuredSecret = $_SERVER['EMAIL_WEBHOOK_SECRET']
            ?? $_ENV['EMAIL_WEBHOOK_SECRET']
            ?? getenv('EMAIL_WEBHOOK_SECRET')
            ?: null;

        if (!$configuredSecret) {
            // Fail closed: never process webhook events without a configured secret
            $this->logger->critical('EMAIL_WEBHOOK_SECRET not configured — rejecting autonomous sales webhook request');
            return $this->json(['success' => false, 'error' => 'Webhook secret not configured'], 403);
        }

        $provided = $request->headers->get('X-Webhook-Secret');
        if (!$provided || !hash_equals((string) $configuredSecret, (string) $provided)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        return null;
    }
    #[IsGranted('ROLE_USER')]

    /**
     * Health check endpoint
     */
    #[Route('/health', name: 'api_autonomous_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json([
            'status' => 'healthy',
            'service' => 'autonomous-sales',
            'timestamp' => (new \DateTime())->format('c'),
        ]);
    }
    #[IsGranted('ROLE_USER')]

    /**
     * Get system statistics
     */
    #[Route('/stats', name: 'api_autonomous_stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        try {
            $stats = $this->orchestrator->getStats();
            
            return $this->json([
                'success' => true,
                'stats' => $stats,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Operation failed. Please try again.',
            ], 500);
        }
    }
    #[IsGranted('ROLE_USER')]

    /**
     * Initialize default data (templates, arms)
     */
    #[Route('/initialize', name: 'api_autonomous_initialize', methods: ['POST'])]
    public function initialize(Request $request): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        try {
            $result = $this->orchestrator->initialize();
            
            return $this->json([
                'success' => true,
                'initialized' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Operation failed. Please try again.',
            ], 500);
        }
    }
    #[IsGranted('ROLE_USER')]

    // ==================== TEMPLATES ====================

    /**
     * List spintax templates
     */
    #[Route('/templates', name: 'api_autonomous_templates_list', methods: ['GET'])]
    public function listTemplates(Request $request): JsonResponse
    {
        $type = $request->query->get('type', 'email');
        
        $templates = $this->templateRepository->findActiveByType($type);
        
        return $this->json([
            'success' => true,
            'templates' => array_map(fn($t) => [
                'id' => $t->getId(),
                'name' => $t->getName(),
                'description' => $t->getDescription(),
                'templateType' => $t->getTemplateType(),
                'subjectSpintax' => $t->getSubjectSpintax(),
                'bodySpintax' => $t->getBodySpintax(),
                'availableVariables' => $t->getAvailableVariables(),
                'timesUsed' => $t->getTimesUsed(),
                'openRate' => $t->getOpenRate(),
                'replyRate' => $t->getReplyRate(),
                'active' => $t->isActive(),
            ], $templates),
        ]);
    }
    #[IsGranted('ROLE_USER')]

    /**
     * Create a new spintax template
     */
    #[Route('/templates', name: 'api_autonomous_templates_create', methods: ['POST'])]
    public function createTemplate(Request $request): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        try {
            /** @var array<string, mixed>|null $data */
            /** @var array<string, mixed>|null $data */
            $data = json_decode($request->getContent(), true);
            
            $template = new SpintaxTemplate();
            $template->setName($data['name'] ?? 'Untitled');
            $template->setDescription($data['description'] ?? null);
            $template->setTemplateType($data['templateType'] ?? 'email');
            $template->setSubjectSpintax($data['subjectSpintax'] ?? '');
            $template->setBodySpintax($data['bodySpintax'] ?? '');
            $template->setAvailableVariables($data['availableVariables'] ?? ['first_name', 'company_name']);
            
            $this->entityManager->persist($template);
            $this->entityManager->flush();
            
            return $this->json([
                'success' => true,
                'templateId' => $template->getId(),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Operation failed. Please try again.',
            ], 400);
        }
    }
    #[IsGranted('ROLE_USER')]

    /**
     * Preview template variations
     */
    #[Route('/templates/preview', name: 'api_autonomous_templates_preview', methods: ['POST'])]
    public function previewTemplate(Request $request): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        try {
            /** @var array<string, mixed>|null $data */
            /** @var array<string, mixed>|null $data */
            $data = json_decode($request->getContent(), true);
            
            $subjectSpintax = $data['subjectSpintax'] ?? '';
            $bodySpintax = $data['bodySpintax'] ?? '';
            $count = min($data['count'] ?? 5, 10);
            
            $context = $data['context'] ?? [
                'first_name' => 'John',
                'company_name' => 'Acme Corp',
                'sender_name' => 'Sales Team',
            ];
            
            $variations = $this->spintaxEngine->previewVariations(
                $subjectSpintax,
                $bodySpintax,
                $context,
                $count
            );
            
            return $this->json([
                'success' => true,
                'variations' => $variations,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Operation failed. Please try again.',
            ], 400);
        }
    }
    #[IsGranted('ROLE_USER')]

    // ==================== BANDIT ARMS ====================

    /**
     * List bandit arms
     */
    #[Route('/arms', name: 'api_autonomous_arms_list', methods: ['GET'])]
    public function listArms(Request $request): JsonResponse
    {
        $type = $request->query->get('type', 'subject_line');
        
        $arms = $this->thompsonSampler->getArmsWithStats($type);
        $stats = $this->thompsonSampler->getBanditStats($type);
        
        return $this->json([
            'success' => true,
            'arms' => $arms,
            'stats' => $stats,
        ]);
    }
    #[IsGranted('ROLE_USER')]

    /**
     * Create a new bandit arm
     */
    #[Route('/arms', name: 'api_autonomous_arms_create', methods: ['POST'])]
    public function createArm(Request $request): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        try {
            /** @var array<string, mixed>|null $data */
            /** @var array<string, mixed>|null $data */
            $data = json_decode($request->getContent(), true);
            
            $arm = $this->thompsonSampler->createArm(
                $data['armType'] ?? 'subject_line',
                $data['armName'] ?? 'Untitled',
                $data['armValue'] ?? ''
            );
            
            return $this->json([
                'success' => true,
                'armId' => $arm->getId(),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Operation failed. Please try again.',
            ], 400);
        }
    }
    #[IsGranted('ROLE_USER')]

    /**
     * Record feedback for an arm (closed-loop learning)
     */
    #[Route('/feedback', name: 'api_autonomous_feedback', methods: ['POST'])]
    public function recordFeedback(Request $request): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        try {
            /** @var array<string, mixed>|null $data */
            /** @var array<string, mixed>|null $data */
            $data = json_decode($request->getContent(), true);
            
            $armId = $data['armId'] ?? null;
            $eventType = $data['eventType'] ?? null;
            
            if (!$armId || !$eventType) {
                return $this->json([
                    'success' => false,
                    'error' => 'armId and eventType are required',
                ], 400);
            }
            
            // Map event types to success/failure
            $successEvents = ['open', 'click', 'reply'];
            $failureEvents = ['bounce', 'unsubscribe'];
            
            if (in_array($eventType, $successEvents, true)) {
                $this->thompsonSampler->recordOutcome((int) $armId, true);
            } elseif (in_array($eventType, $failureEvents, true)) {
                $this->thompsonSampler->recordOutcome((int) $armId, false);
            } else {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid eventType: ' . $eventType,
                ], 400);
            }
            
            return $this->json([
                'success' => true,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Operation failed. Please try again.',
            ], 400);
        }
    }
    #[IsGranted('ROLE_USER')]

    // ==================== COMPOSE ====================

    /**
     * Compose a message for a contact
     */
    #[Route('/compose', name: 'api_autonomous_compose', methods: ['POST'])]
    public function compose(Request $request): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        try {
            /** @var array<string, mixed>|null $data */
            /** @var array<string, mixed>|null $data */
            $data = json_decode($request->getContent(), true);
            
            $contactId = $data['contactId'] ?? null;
            
            if (!$contactId) {
                return $this->json([
                    'success' => false,
                    'error' => 'contactId is required',
                ], 400);
            }
            
            $contact = $this->contactRepository->find($contactId);
            
            if (!$contact) {
                return $this->json([
                    'success' => false,
                    'error' => 'Contact not found',
                ], 404);
            }
            
            // Additional context from request
            $context = [];
            if (isset($data['firstName'])) $context['first_name'] = $data['firstName'];
            if (isset($data['company'])) $context['company_name'] = $data['company'];
            if (isset($data['appName'])) $context['app_name'] = $data['appName'];
            if (isset($data['appGenre'])) $context['app_genre'] = $data['appGenre'];
            
            $message = $this->orchestrator->composeMessage($contact, $context);
            
            return $this->json([
                'success' => true,
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Operation failed. Please try again.',
            ], 500);
        }
    }
    #[IsGranted('ROLE_USER')]

    // ==================== SCORING ====================

    /**
     * Score leads batch
     */
    #[Route('/score', name: 'api_autonomous_score', methods: ['POST'])]
    public function score(Request $request): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        try {
            /** @var array<string, mixed>|null $data */
            /** @var array<string, mixed>|null $data */
            $data = json_decode($request->getContent(), true);
            
            $limit = min($data['limit'] ?? 50, 100);
            
            $result = $this->orchestrator->scoreLeads($limit);
            
            return $this->json([
                'success' => true,
                'scored' => $result['scored'],
                'byTier' => $result['byTier'],
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Operation failed. Please try again.',
            ], 500);
        }
    }
    #[IsGranted('ROLE_USER')]

    // ==================== INBOX (CLASSIFICATION) ====================

    /**
     * Get inbox messages
     */
    #[Route('/inbox', name: 'api_autonomous_inbox', methods: ['GET'])]
    public function inbox(Request $request): JsonResponse
    {
        $status = $request->query->get('status');
        
        if ($status === 'pending_review') {
            $messages = $this->inboxRepository->findPendingReview();
        } elseif ($status) {
            $messages = $this->inboxRepository->findByClassification($status);
        } else {
            $messages = $this->inboxRepository->findBy([], ['receivedAt' => 'DESC'], 100);
        }
        
        $stats = $this->emailClassifier->getClassificationStats();
        
        return $this->json([
            'success' => true,
            'messages' => array_map(fn($m) => [
                'id' => $m->getId(),
                'fromEmail' => $m->getFromEmail(),
                'subject' => $m->getSubject(),
                'classification' => $m->getClassification(),
                'confidence' => $m->getClassificationConfidence(),
                'method' => $m->getClassificationMethod(),
                'requiresReview' => $m->requiresHumanReview(),
                'receivedAt' => $m->getReceivedAt()->format('c'),
            ], $messages),
            'stats' => $stats,
        ]);
    }
    #[IsGranted('ROLE_USER')]

    /**
     * Classify an email
     */
    #[Route('/classify', name: 'api_autonomous_classify', methods: ['POST'])]
    public function classify(Request $request): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        try {
            /** @var array<string, mixed>|null $data */
            /** @var array<string, mixed>|null $data */
            $data = json_decode($request->getContent(), true);
            
            $subject = $data['subject'] ?? '';
            $body = $data['body'] ?? '';
            $fromEmail = $data['fromEmail'] ?? '';
            
            $result = $this->emailClassifier->classifyEmail($subject, $body, $fromEmail);
            
            return $this->json([
                'success' => true,
                'classification' => $result,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Operation failed. Please try again.',
            ], 500);
        }
    }
    #[IsGranted('ROLE_USER')]

    /**
     * Submit human review
     */
    #[Route('/inbox/review', name: 'api_autonomous_inbox_review', methods: ['POST'])]
    public function submitReview(Request $request): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        try {
            /** @var array<string, mixed>|null $data */
            /** @var array<string, mixed>|null $data */
            $data = json_decode($request->getContent(), true);
            
            $messageId = $data['messageId'] ?? null;
            $correctCategory = $data['correctCategory'] ?? null;
            
            if (!$messageId || !$correctCategory) {
                return $this->json([
                    'success' => false,
                    'error' => 'messageId and correctCategory are required',
                ], 400);
            }
            
            $reviewedBy = $this->getUser()?->getUserIdentifier() ?? 'anonymous';
            
            $this->emailClassifier->submitHumanReview($messageId, $correctCategory, $reviewedBy);
            
            return $this->json([
                'success' => true,
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Operation failed. Please try again.',
            ], 400);
        }
    }
    #[IsGranted('ROLE_USER')]

    // ==================== COMPETITOR TARGETING ====================

    /**
     * Get competitor leads (Sniper mode)
     */
    #[Route('/competitor-leads', name: 'api_autonomous_competitor_leads', methods: ['GET'])]
    public function competitorLeads(Request $request): JsonResponse
    {
        $tier = $request->query->get('tier');
        $minScore = (int) $request->query->get('minScore', 0);
        
        $result = $this->competitorDetection->getCompetitorLeads(
            $tier ? (int) $tier : null,
            $minScore
        );
        
        return $this->json([
            'success' => true,
            'leads' => array_map(fn($l) => [
                'id' => $l->getId(),
                'companyName' => $l->getCompanyName(),
                'score' => $l->getLeadScore(),
                'website' => $l->getWebsiteRoot(),
            ], $result['leads']),
            'byCompetitor' => array_map(fn($leads) => array_map(fn($l) => [
                'id' => $l->getId(),
                'companyName' => $l->getCompanyName(),
                'score' => $l->getLeadScore(),
            ], $leads), $result['byCompetitor']),
            'competitorStats' => $result['competitorStats'],
        ]);
    }
    #[IsGranted('ROLE_USER')]

    // ==================== DYNAMIC COMPETITORS ====================

    /**
     * Get learned competitors
     */
    #[Route('/competitors/learned', name: 'api_autonomous_learned_competitors', methods: ['GET'])]
    public function learnedCompetitors(Request $request): JsonResponse
    {
        if (!$this->learnedCompetitorRepository) {
            return $this->json(['success' => false, 'error' => 'Competitor learning not available'], 501);
        }

        $tier = $request->query->get('tier');
        $verified = $request->query->get('verified');
        
        if ($tier !== null) {
            $competitors = $this->learnedCompetitorRepository->findActiveByTier((int) $tier);
        } elseif ($verified === 'true') {
            $competitors = $this->learnedCompetitorRepository->findVerified();
        } else {
            $competitors = $this->learnedCompetitorRepository->findAllActive();
        }

        return $this->json([
            'success' => true,
            'competitors' => array_map(fn($c) => [
                'id' => $c->getId(),
                'domain' => $c->getDomain(),
                'name' => $c->getName(),
                'tier' => $c->getTier(),
                'industry' => $c->getIndustry(),
                'detectionCount' => $c->getDetectionCount(),
                'confidenceScore' => $c->getConfidenceScore(),
                'verified' => $c->isVerified(),
                'discoverySource' => $c->getDiscoverySource(),
            ], $competitors),
            'total' => count($competitors),
        ]);
    }
    #[IsGranted('ROLE_USER')]

    /**
     * Seed baseline competitors
     */
    #[Route('/competitors/seed', name: 'api_autonomous_seed_competitors', methods: ['POST'])]
    public function seedCompetitors(Request $request): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        if (!$this->competitorLearner) {
            return $this->json(['success' => false, 'error' => 'Competitor learning not available'], 501);
        }

        try {
            $seeded = $this->competitorLearner->seedCompetitors();
            
            return $this->json([
                'success' => true,
                'seeded' => count($seeded),
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => 'Operation failed. Please try again.'], 500);
        }
    }
    #[IsGranted('ROLE_USER')]

    /**
     * Learn competitors from content
     */
    #[Route('/competitors/learn', name: 'api_autonomous_learn_competitors', methods: ['POST'])]
    public function learnCompetitors(Request $request): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        if (!$this->competitorLearner) {
            return $this->json(['success' => false, 'error' => 'Competitor learning not available'], 501);
        }

        try {
            /** @var array<string, mixed>|null $data */
            /** @var array<string, mixed>|null $data */
            $data = json_decode($request->getContent(), true);
            $content = $data['content'] ?? '';
            $sourceUrl = $data['source_url'] ?? 'api_submission';
            $source = $data['source'] ?? 'website_scrape';

            if (empty($content)) {
                return $this->json(['success' => false, 'error' => 'content is required'], 400);
            }

            $discovered = $this->competitorLearner->learnFromContent($content, $sourceUrl, $source);

            return $this->json([
                'success' => true,
                'discovered' => array_map(fn($d) => [
                    'action' => $d['action'],
                    'domain' => $d['competitor']->getDomain(),
                    'name' => $d['competitor']->getName(),
                ], $discovered),
                'total' => count($discovered),
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => 'Operation failed. Please try again.'], 500);
        }
    }
    #[IsGranted('ROLE_USER')]

    /**
     * Verify a learned competitor
     */
    #[Route('/competitors/{id}/verify', name: 'api_autonomous_verify_competitor', methods: ['POST'])]
    public function verifyCompetitor(int $id, Request $request): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        if (!$this->competitorLearner) {
            return $this->json(['success' => false, 'error' => 'Competitor learning not available'], 501);
        }

        try {
            /** @var array<string, mixed>|null $data */
            /** @var array<string, mixed>|null $data */
            $data = json_decode($request->getContent(), true);
            $verifiedBy = $data['verified_by'] ?? 'api';
            $newTier = $data['tier'] ?? null;

            $competitor = $this->competitorLearner->verifyCompetitor($id, $verifiedBy, $newTier);

            if (!$competitor) {
                return $this->json(['success' => false, 'error' => 'Competitor not found'], 404);
            }

            return $this->json([
                'success' => true,
                'competitor' => [
                    'id' => $competitor->getId(),
                    'domain' => $competitor->getDomain(),
                    'name' => $competitor->getName(),
                    'tier' => $competitor->getTier(),
                    'verified' => $competitor->isVerified(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => 'Operation failed. Please try again.'], 500);
        }
    }
    #[IsGranted('ROLE_USER')]

    /**
     * Get competitor statistics
     */
    #[Route('/competitors/stats', name: 'api_autonomous_competitor_stats', methods: ['GET'])]
    public function competitorStats(): JsonResponse
    {
        if (!$this->competitorLearner) {
            return $this->json(['success' => false, 'error' => 'Competitor learning not available'], 501);
        }

        return $this->json([
            'success' => true,
            'stats' => $this->competitorLearner->getStatistics(),
        ]);
    }
    #[IsGranted('ROLE_USER')]

    // ==================== ML PERSONALIZATION ====================

    /**
     * Generate personalized email for a contact
     */
    #[Route('/personalize', name: 'api_autonomous_personalize', methods: ['POST'])]
    public function personalizeEmail(Request $request): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        if (!$this->personalizationService) {
            return $this->json(['success' => false, 'error' => 'Personalization service not available'], 501);
        }

        try {
            /** @var array<string, mixed>|null $data */
            /** @var array<string, mixed>|null $data */
            $data = json_decode($request->getContent(), true);
            $contactId = $data['contact_id'] ?? null;
            $templateSubject = $data['subject_template'] ?? '';
            $templateBody = $data['body_template'] ?? '';
            $variables = $data['variables'] ?? [];

            if (!$contactId) {
                return $this->json(['success' => false, 'error' => 'contact_id is required'], 400);
            }

            $contact = $this->contactRepository->find($contactId);
            if (!$contact) {
                return $this->json(['success' => false, 'error' => 'Contact not found'], 404);
            }

            $result = $this->personalizationService->personalizeEmail(
                $contact,
                $templateSubject,
                $templateBody,
                $variables
            );

            return $this->json([
                'success' => true,
                'subject' => $result['subject'],
                'body' => $result['body'],
                'settings' => $result['settings'],
                'profileId' => $result['profile']->getId(),
                'similarProfilesUsed' => $result['similarProfilesUsed'],
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => 'Operation failed. Please try again.'], 500);
        }
    }
    #[IsGranted('ROLE_USER')]

    /**
     * Get personalization profile for a contact
     */
    #[Route('/personalization/profile/{contactId}', name: 'api_autonomous_personalization_profile', methods: ['GET'])]
    public function getPersonalizationProfile(int $contactId): JsonResponse
    {
        if (!$this->personalizationRepository) {
            return $this->json(['success' => false, 'error' => 'Personalization not available'], 501);
        }

        $profile = $this->personalizationRepository->findByContactId($contactId);
        
        if (!$profile) {
            return $this->json(['success' => false, 'profile' => null, 'message' => 'No profile yet']);
        }

        return $this->json([
            'success' => true,
            'profile' => [
                'id' => $profile->getId(),
                'contactId' => $profile->getContactId(),
                'preferredTone' => $profile->getPreferredTone(),
                'preferredContent' => $profile->getPreferredContent(),
                'preferredStyle' => $profile->getPreferredStyle(),
                'emailsOpened' => $profile->getEmailsOpened(),
                'emailsReplied' => $profile->getEmailsReplied(),
                'engagementScore' => $profile->getEngagementScore(),
                'bestSendTime' => $profile->getBestSendTime(),
                'bestSendDay' => $profile->getBestSendDay(),
                'topicInterests' => $profile->getTopicInterests(),
            ],
        ]);
    }
    #[IsGranted('ROLE_USER')]

    /**
     * Record email interaction for learning
     */
    #[Route('/personalization/interaction', name: 'api_autonomous_record_interaction', methods: ['POST'])]
    public function recordInteraction(Request $request): JsonResponse
    {
        if ($response = $this->requireCsrf($request)) {
            return $response;
        }

        if (!$this->personalizationService) {
            return $this->json(['success' => false, 'error' => 'Personalization service not available'], 501);
        }

        try {
            /** @var array<string, mixed>|null $data */
            /** @var array<string, mixed>|null $data */
            $data = json_decode($request->getContent(), true);
            $contactId = $data['contact_id'] ?? null;
            $eventType = $data['event_type'] ?? null;
            $messageId = $data['message_id'] ?? null;
            $metadata = $data['metadata'] ?? [];

            if (!$contactId || !$eventType) {
                return $this->json(['success' => false, 'error' => 'contact_id and event_type are required'], 400);
            }

            $contact = $this->contactRepository->find($contactId);
            if (!$contact) {
                return $this->json(['success' => false, 'error' => 'Contact not found'], 404);
            }

            $message = $messageId ? $this->outboundRepository->find($messageId) : null;

            $profile = $this->personalizationService->recordInteraction($contact, $eventType, $message, $metadata);

            return $this->json([
                'success' => true,
                'profile' => [
                    'id' => $profile->getId(),
                    'emailsOpened' => $profile->getEmailsOpened(),
                    'emailsReplied' => $profile->getEmailsReplied(),
                    'engagementScore' => $profile->getEngagementScore(),
                ],
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => 'Operation failed. Please try again.'], 500);
        }
    }
    #[IsGranted('ROLE_USER')]

    /**
     * Get personalization statistics
     */
    #[Route('/personalization/stats', name: 'api_autonomous_personalization_stats', methods: ['GET'])]
    public function personalizationStats(): JsonResponse
    {
        if (!$this->personalizationService) {
            return $this->json(['success' => false, 'error' => 'Personalization service not available'], 501);
        }

        return $this->json([
            'success' => true,
            'stats' => $this->personalizationService->getStatistics(),
        ]);
    }

    // ==================== WEBHOOKS ====================

    /**
     * Webhook for email events (generic)
     */
    #[Route('/webhook/email-events', name: 'api_autonomous_webhook_email', methods: ['POST'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function webhookEmailEvents(Request $request): JsonResponse
    {
        if ($response = $this->authenticateWebhook($request)) {
            return $response;
        }

        try {
            /** @var array<string, mixed>|null $data */
            /** @var array<string, mixed>|null $data */
            $data = json_decode($request->getContent(), true);
            
            $messageId = $data['outbound_message_id'] ?? $data['message_id'] ?? null;
            $eventType = $data['event'] ?? $data['event_type'] ?? null;
            
            if (!$messageId || !$eventType) {
                return $this->json([
                    'success' => false,
                    'error' => 'outbound_message_id and event are required',
                ], 400);
            }
            
            $this->orchestrator->recordEmailEvent((int) $messageId, $eventType);
            
            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Operation failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Webhook for inbound email (SendGrid-like format)
     */
    #[Route('/webhook/sendgrid', name: 'api_autonomous_webhook_sendgrid', methods: ['POST'])]
    #[IsGranted('PUBLIC_ACCESS')]
    public function webhookSendgrid(Request $request): JsonResponse
    {
        if ($response = $this->authenticateWebhook($request)) {
            return $response;
        }

        try {
            /** @var array<string, mixed>|null $data */
            /** @var array<string, mixed>|null $data */
            $data = json_decode($request->getContent(), true);
            
            $fromEmail = $data['from'] ?? '';
            $subject = $data['subject'] ?? '';
            $body = $data['text'] ?? $data['html'] ?? '';
            
            if (!$fromEmail) {
                return $this->json([
                    'success' => false,
                    'error' => 'from email is required',
                ], 400);
            }
            
            // Try to find the outbound message this is replying to
            $inReplyTo = null;
            if (isset($data['in_reply_to'])) {
                $inReplyTo = $this->outboundRepository->findByMessageId($data['in_reply_to']);
            }
            
            $inboxMessage = $this->emailClassifier->processIncomingEmail(
                $fromEmail,
                $subject,
                $body,
                $inReplyTo,
                $data
            );
            
            return $this->json([
                'success' => true,
                'messageId' => $inboxMessage->getId(),
                'classification' => $inboxMessage->getClassification(),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'Operation failed. Please try again.',
            ], 500);
        }
    }
}
