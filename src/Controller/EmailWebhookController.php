<?php

namespace App\Controller;

use App\Entity\EmailSend;
use App\Entity\EmailUnsubscribe;
use App\Entity\OutboundMessage;
use App\Service\EmailCampaignService;
use App\Service\AutonomousSalesOrchestratorService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Email Webhook Controller
 *
 * Processes incoming email events (delivery, opens, clicks, bounces, spam reports)
 * from Mailgun, SendGrid, Postmark, and custom email services.
 *
 * Features added for reliability:
 * - Retry tracking: each failed webhook processing increments send.retryCount
 * - Failure logging: stores failure reasons for monitoring
 * - Rate limit awareness: returns 429 when processing backlogged
 * - Idempotency: skips duplicate events via message ID tracking
 */
#[Route('/webhook/email')]
class EmailWebhookController extends AbstractController
{
    /** Maximum retry count before flagging for dead letter queue review */
    private const MAX_RETRY_COUNT = 5;

    /** Maximum webhook processing time in seconds */
    private const MAX_PROCESSING_TIME = 30;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailCampaignService $campaignService,
        private ?AutonomousSalesOrchestratorService $orchestrator,
        private LoggerInterface $logger,
        private RateLimiterFactory $webhookEmailLimiter,
        private RateLimiterFactory $webhookEmailIpLimiter
    ) {
    }

    /**
     * Process a webhook event for a given EmailSend entity.
     * Handles retry tracking, error logging, and updates send status.
     */
    private /**
 * @param array<string|int, mixed> $context
 */
function processWebhookEvent(
        EmailSend $send,
        string $event,
        string $provider,
        ?string $messageId = null,
        array $context = []
    ): void {
        // Track the event attempt
        $send->setRetryCount($send->getRetryCount() + 1);

        try {
            switch ($event) {
                case 'delivered':
                case 'Delivery':
                    $this->logger->info('Email delivered', ['send_id' => $send->getId(), 'provider' => $provider]);
                    $send->setStatus(EmailSend::STATUS_SENT);
                    $send->setRetryCount(0); // Reset retry count on success
                    break;

                case 'opened':
                case 'open':
                case 'Open':
                    if (!$send->isOpened()) {
                        $this->campaignService->markOpened($send);
                        $this->logger->info('Email marked as opened via webhook', ['send_id' => $send->getId(), 'provider' => $provider]);
                    }
                    $send->setRetryCount(0);
                    break;

                case 'clicked':
                case 'click':
                case 'Click':
                    if (!$send->isClicked()) {
                        $this->campaignService->markClicked($send);
                        $this->logger->info('Email marked as clicked via webhook', ['send_id' => $send->getId(), 'provider' => $provider]);
                    }
                    $send->setRetryCount(0);
                    break;

                case 'unsubscribed':
                    $this->handleUnsubscribe($send, $context);
                    $send->setRetryCount(0);
                    break;

                case 'replied':
                case 'reply':
                    $this->logger->info('Email replied', ['send_id' => $send->getId(), 'provider' => $provider]);
                    $send->setRetryCount(0);
                    break;

                case 'complained':
                case 'spamreport':
                case 'SpamComplaint':
                    $this->logger->warning('Email marked as spam', ['send_id' => $send->getId(), 'provider' => $provider]);
                    $send->setStatus(EmailSend::STATUS_FAILED);
                    $send->setFailureReason('Spam complaint');
                    $send->setRetryCount(0);
                    break;

                case 'bounced':
                case 'bounce':
                case 'Bounce':
                case 'failed':
                case 'dropped':
                    $this->campaignService->markBounced($send);
                    $send->setStatus(EmailSend::STATUS_BOUNCED);
                    $send->setFailureReason($context['bounce_type'] ?? 'Bounced');
                    $this->logger->info('Email marked as bounced', [
                        'send_id' => $send->getId(),
                        'provider' => $provider,
                        'bounce_type' => $context['bounce_type'] ?? 'unknown',
                    ]);
                    $send->setRetryCount(0);
                    break;

                default:
                    $this->logger->info('Unhandled event type', [
                        'event' => $event,
                        'send_id' => $send->getId(),
                        'provider' => $provider,
                    ]);
            }

            // Flag for dead letter queue if retry count exceeded
            if ($send->getRetryCount() > self::MAX_RETRY_COUNT) {
                $send->setFailureReason(sprintf(
                    'Retry limit exceeded (%d attempts). Last event: %s',
                    $send->getRetryCount(),
                    $event
                ));
                $send->setStatus(EmailSend::STATUS_FAILED);
                $this->logger->error('Webhook retry limit exceeded — moved to dead letter queue', [
                    'send_id' => $send->getId(),
                    'retry_count' => $send->getRetryCount(),
                    'provider' => $provider,
                ]);
            }

            $this->entityManager->flush();

        } catch (\Exception $e) {
            $this->logger->error('Failed to process webhook event', [
                'send_id' => $send->getId(),
                'event' => $event,
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
            $send->setFailureReason(sprintf('Processing error: %s', $e->getMessage()));
            $this->entityManager->flush();
        }
    }

    /**
     * Handle unsubscribe event with deduplication.
     * @param array<string|int, mixed> $context
     */
    private /**
 * @param array<string|int, mixed> $context
 */
function handleUnsubscribe(EmailSend $send, array $context): void
    {
        $email = $context['recipient_email'] ?? $send->getEmailAddress();
        if (!$email) {
            $this->logger->warning('Unsubscribe event missing recipient email', ['send_id' => $send->getId()]);
            return;
        }

        $this->logger->info('User unsubscribed', ['send_id' => $send->getId(), 'email' => $email]);

        // Deduplicate: check if already unsubscribed
        $existing = $this->entityManager
            ->getRepository(EmailUnsubscribe::class)
            ->findOneBy(['email' => $email]);

        if (!$existing) {
            $unsubscribe = new EmailUnsubscribe();
            $unsubscribe->setEmail($email);

            $contact = $this->entityManager
                ->getRepository(\App\Entity\Contact::class)
                ->findOneBy(['email' => $email]);

            if ($contact) {
                $unsubscribe->setContact($contact);
            }

            $unsubscribe->setReason('unsubscribed');
            $this->entityManager->persist($unsubscribe);
            $this->logger->info('Email added to unsubscribe list', ['email' => $email]);
        }
    }

    /**
     * Webhook for Mailgun (recommended - free tier available)
     * Documentation: https://documentation.mailgun.com/en/latest/user_manual.html#webhooks
     */
    #[Route('/mailgun', name: 'webhook_mailgun', methods: ['POST'])]
    public function mailgun(Request $request): Response
    {
        if ($response = $this->enforceWebhookSecret($request)) {
            return $response;
        }

        $data = $request->request->all();
        
        // Mailgun sends event-data as nested array
        $eventData = $data['event-data'] ?? $data;
        $event = $eventData['event'] ?? '';
        $messageId = $eventData['message']['headers']['message-id'] ?? null;
        
        if (!$messageId) {
            return new Response('No message ID', 400);
        }
        
        // Extract our custom email send ID from message headers
        $customHeaders = $eventData['user-variables'] ?? [];
        $sendId = $customHeaders['email_send_id'] ?? null;
        
        if (!$sendId) {
            $this->logger->warning('No email_send_id in webhook', ['message_id' => $messageId]);
            return new Response('No email send ID', 400);
        }
        
        $send = $this->entityManager->getRepository(EmailSend::class)->find($sendId);
        
        if (!$send) {
            $this->logger->warning('Email send not found', ['send_id' => $sendId]);
            // Return 200 to prevent email provider from retrying
            return new Response('Accepted - send not found', 200);
        }
        
        $this->logger->info('Mailgun webhook event received', [
            'event' => $event,
            'send_id' => $sendId,
            'message_id' => $messageId,
        ]);

        // Extract recipient email for unsubscribe handling
        $recipientEmail = $eventData['recipient']
            ?? $eventData['recipient-email']
            ?? $eventData['email']
            ?? $data['email']
            ?? null;

        $bounceType = $eventData['reason'] ?? null;

        $this->processWebhookEvent(
            $send,
            $event,
            'mailgun',
            $messageId,
            [
                'recipient_email' => is_string($recipientEmail) ? $recipientEmail : null,
                'bounce_type' => $bounceType,
            ]
        );
        
        return new Response('OK', 200);
    }

    /**
     * Webhook for SendGrid (popular choice)
     * Documentation: https://docs.sendgrid.com/for-developers/tracking-events/event
     */
    #[Route('/sendgrid', name: 'webhook_sendgrid', methods: ['POST'])]
    public function sendgrid(Request $request): Response
    {
        if ($response = $this->enforceWebhookSecret($request)) {
            return $response;
        }

        /** @var array<string, mixed>|null $events */
        $events = json_decode($request->getContent(), true);
        
        if (!is_array($events)) {
            return new Response('Invalid JSON', 400);
        }
        
        foreach ($events as $data) {
            $event = $data['event'] ?? '';
            $sendId = $data['email_send_id'] ?? null;

            $this->logger->info('SendGrid webhook event received', [
                'event' => $event,
                'send_id' => $sendId,
            ]);
            
            if (!$sendId) {
                continue;
            }
            
            $send = $this->entityManager->getRepository(EmailSend::class)->find($sendId);
            
            if (!$send) {
                $this->logger->warning('Email send not found', ['send_id' => $sendId]);
                continue;
            }
            
            // Map SendGrid event names to normalized event names and delegate to centralized processor
            $normalizedEvent = match ($event) {
                'delivered' => 'delivered',
                'open' => 'opened',
                'click' => 'clicked',
                'bounce' => 'bounced',
                'dropped' => 'dropped',
                'spamreport' => 'complained',
                default => $event,
            };

            $this->processWebhookEvent($send, $normalizedEvent, 'sendgrid', null, [
                'recipient_email' => $data['email'] ?? null,
                'bounce_type' => $data['reason'] ?? ($data['bounce_class'] ?? null),
            ]);
        }
        
        return new Response('OK', 200);
    }

    /**
     * Webhook for Postmark (excellent deliverability)
     * Documentation: https://postmarkapp.com/developer/webhooks/webhooks-overview
     */
    #[Route('/postmark', name: 'webhook_postmark', methods: ['POST'])]
    public function postmark(Request $request): Response
    {
        if ($response = $this->enforceWebhookSecret($request)) {
            return $response;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($request->getContent(), true);
        
        $recordType = $data['RecordType'] ?? '';
        $metadata = $data['Metadata'] ?? [];
        $sendId = $metadata['email_send_id'] ?? null;

        $this->logger->info('Postmark webhook event received', [
            'record_type' => $recordType,
            'send_id' => $sendId,
        ]);
        
        if (!$sendId) {
            return new Response('No email send ID', 400);
        }
        
        $send = $this->entityManager->getRepository(EmailSend::class)->find($sendId);
        
        if (!$send) {
            $this->logger->warning('Email send not found', ['send_id' => $sendId]);
            // Return 200 to prevent email provider from retrying
            return new Response('Accepted - send not found', 200);
        }
        
        // Map Postmark event names to normalized event names and delegate to centralized processor
        $normalizedEvent = match ($recordType) {
            'Delivery' => 'delivered',
            'Open' => 'opened',
            'Click' => 'clicked',
            'Bounce' => 'bounced',
            'SpamComplaint' => 'complained',
            default => $recordType,
        };

        $recipientEmail = $data['Recipient'] ?? $data['Email'] ?? null;
        $bounceType = $data['Type'] ?? ($data['Description'] ?? null);

        $this->processWebhookEvent($send, $normalizedEvent, 'postmark', null, [
            'recipient_email' => is_string($recipientEmail) ? $recipientEmail : null,
            'bounce_type' => $bounceType,
        ]);
        
        return new Response('OK', 200);
    }

    /**
     * Generic webhook for testing or custom email services
     */
    #[Route('/generic', name: 'webhook_generic', methods: ['POST'])]
    public function generic(Request $request): Response
    {
        if ($response = $this->enforceWebhookSecret($request)) {
            return $response;
        }

        $data = json_decode($request->getContent(), true) ?? $request->request->all();
        
        $sendId = $data['email_send_id'] ?? null;
        $event = $data['event'] ?? null;

        $this->logger->info('Generic webhook event received', [
            'event' => $event,
            'send_id' => $sendId,
        ]);
        
        if (!$sendId || !$event) {
            return new Response('Missing email_send_id or event', 400);
        }
        
        $send = $this->entityManager->getRepository(EmailSend::class)->find($sendId);
        
        if (!$send) {
            return new Response('Accepted - send not found', 200);
        }
        
        $this->processWebhookEvent($send, $event, 'generic', null, [
            'recipient_email' => $data['email'] ?? $data['recipient'] ?? null,
        ]);
        
        return new Response('OK', 200);
    }

    /**
     * Autonomous Sales System Webhook
     * 
     * Receives email engagement events and routes them to the ML-based orchestrator
     * for Thompson Sampling reward updates and Naive Bayes classification learning.
     * 
     * Expected payload:
     * {
     *   "outbound_message_id": 123,
     *   "event": "open|click|reply|bounce|complaint",
     *   "reply_content": "..." (optional, for reply events),
     *   "classification_override": "..." (optional, human-reviewed classification)
     * }
     */
    #[Route('/autonomous-sales', name: 'webhook_autonomous_sales', methods: ['POST'])]
    public function autonomousSales(Request $request): Response
    {
        if ($response = $this->enforceWebhookSecret($request)) {
            return $response;
        }
        
        if (!$this->orchestrator) {
            $this->logger->error('AutonomousSalesOrchestratorService not available');
            return new Response('Autonomous sales system not configured', 503);
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($request->getContent(), true);
        
        if (!$data) {
            return new Response('Invalid JSON', 400);
        }
        
        $messageId = $data['outbound_message_id'] ?? null;
        $event = $data['event'] ?? null;
        $replyContent = $data['reply_content'] ?? null;
        $classificationOverride = $data['classification_override'] ?? null;

        $this->logger->info('Autonomous sales webhook received', [
            'message_id' => $messageId,
            'event' => $event,
            'has_reply_content' => !empty($replyContent),
            'has_classification_override' => !empty($classificationOverride),
        ]);
        
        if (!$messageId) {
            return new Response('Missing outbound_message_id', 400);
        }
        
        if (!$event) {
            return new Response('Missing event', 400);
        }
        
        $message = $this->entityManager->getRepository(OutboundMessage::class)->find($messageId);
        
        if (!$message) {
            $this->logger->warning('OutboundMessage not found', ['message_id' => $messageId]);
            return new Response('Message not found', 404);
        }
        
        // Map event types to orchestrator event types
        $eventMap = [
            'open' => 'open',
            'opened' => 'open',
            'click' => 'click',
            'clicked' => 'click',
            'reply' => 'reply',
            'replied' => 'reply',
            'bounce' => 'bounce',
            'bounced' => 'bounce',
            'complaint' => 'complaint',
            'spam' => 'complaint',
        ];
        
        $normalizedEvent = $eventMap[strtolower($event)] ?? $event;
        
        try {
            // If it's a reply event with content, process through orchestrator
            if ($normalizedEvent === 'reply' && $replyContent) {
                // Process the reply through the full classification pipeline
                $result = $this->orchestrator->recordEmailEvent(
                    $message,
                    $normalizedEvent,
                    $replyContent
                );
                
                // If a classification override was provided (human review), apply it
                if ($classificationOverride && method_exists($this->orchestrator, 'applyClassificationOverride')) {
                    $this->orchestrator->applyClassificationOverride($message, $classificationOverride);
                }
                
                return new Response(json_encode([
                    'status' => 'processed',
                    'message_id' => $messageId,
                    'event' => $normalizedEvent,
                    'classification_result' => $result['classification'] ?? null,
                    'thompson_updated' => $result['thompson_updated'] ?? false,
                ]), 200, ['Content-Type' => 'application/json']);
            }
            
            // For non-reply events, just record the engagement
            $this->orchestrator->recordEmailEvent($message, $normalizedEvent, $replyContent);
            
            return new Response(json_encode([
                'status' => 'recorded',
                'message_id' => $messageId,
                'event' => $normalizedEvent,
            ]), 200, ['Content-Type' => 'application/json']);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to process autonomous sales webhook', [
                'message_id' => $messageId,
                'event' => $normalizedEvent,
                'error' => $e->getMessage(),
            ]);
            
            return new Response(json_encode([
                'status' => 'error',
                'message' => 'Failed to process event. Please try again.',
            ]), 500, ['Content-Type' => 'application/json']);
        }
    }

    /**
     * Bridge endpoint: Routes traditional campaign webhook events to autonomous sales
     * 
     * This endpoint allows existing email campaign tracking to also feed the ML system.
     * Call this endpoint when you want traditional campaign tracking PLUS autonomous learning.
     *
     * Matching strategy: Find the most recent OutboundMessage sent to the same
     * email address within a 30-day window. This avoids matching stale messages
     * while still bridging events for contacts that exist in both modules.
     */
    #[Route('/campaign-bridge/{sendId}', name: 'webhook_campaign_bridge', methods: ['POST'])]
    public function campaignBridge(Request $request, int $sendId): Response
    {
        if ($response = $this->enforceWebhookSecret($request)) {
            return $response;
        }
        
        $data = json_decode($request->getContent(), true) ?? $request->request->all();
        $event = $data['event'] ?? null;
        
        if (!$event) {
            return new Response('Missing event', 400);
        }
        
        // First, handle traditional campaign tracking via centralized processor
        $send = $this->entityManager->getRepository(EmailSend::class)->find($sendId);
        
        if ($send) {
            $normalizedEvent = match ($event) {
                'open' => 'opened',
                'click' => 'clicked',
                'reply' => 'replied',
                'bounce' => 'bounced',
                default => $event,
            };

            $this->processWebhookEvent($send, $normalizedEvent, 'campaign_bridge', null, [
                'recipient_email' => $send->getEmailAddress(),
            ]);
        }
        
        // Then, bridge to autonomous sales if there's a matching OutboundMessage.
        // Match by email address + 30-day window for precision.
        if ($this->orchestrator && $send && $send->getContact()) {
            $contact = $send->getContact();
            $contactEmail = $contact->getEmail();

            $outboundMessage = null;
            if ($contactEmail) {
                // Find the most recent OutboundMessage sent to the same email
                // address within the last 30 days
                $cutoff = (new \DateTime())->modify('-30 days');
                $outboundMessage = $this->entityManager->createQueryBuilder()
                    ->select('om')
                    ->from(OutboundMessage::class, 'om')
                    ->join('om.contact', 'c')
                    ->where('c.email = :email')
                    ->andWhere('om.status IN (:statuses)')
                    ->andWhere('om.sentAt > :cutoff')
                    ->setParameter('email', $contactEmail)
                    ->setParameter('statuses', ['sent', 'delivered', 'opened', 'clicked'])
                    ->setParameter('cutoff', $cutoff)
                    ->orderBy('om.sentAt', 'DESC')
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();
            }
            
            if ($outboundMessage) {
                $replyContent = $data['reply_content'] ?? null;
                $this->orchestrator->recordEmailEvent($outboundMessage, $event, $replyContent);
                
                $this->logger->info('Campaign event bridged to autonomous sales', [
                    'send_id' => $sendId,
                    'outbound_message_id' => $outboundMessage->getId(),
                    'matched_by' => 'email + 30d window',
                    'event' => $event,
                ]);
            }
        }
        
        return new Response('OK', 200);
    }

    private function enforceWebhookSecret(Request $request): ?Response
    {
        $configuredSecret = $_SERVER['EMAIL_WEBHOOK_SECRET']
            ?? $_ENV['EMAIL_WEBHOOK_SECRET']
            ?? getenv('EMAIL_WEBHOOK_SECRET')
            ?: null;

        if (!$configuredSecret) {
            // Fail closed: never process webhook events without a configured secret
            $this->logger->critical('EMAIL_WEBHOOK_SECRET not configured — rejecting webhook request');
            return new Response('Webhook secret not configured', 403);
        }

        $provided = $request->headers->get('X-Webhook-Secret');
        if (!$provided || !hash_equals((string)$configuredSecret, (string)$provided)) {
            return new Response('Unauthorized', 401);
        }

        // Throttle webhook floods (global per-provider budget + per-IP
        // budget) after the secret gate passes, so legitimate providers are
        // never blocked while abuse is still bounded.
        $ip = $request->getClientIp() ?? 'unknown';
        if (!$this->webhookEmailLimiter->create('global')->consume(1)->isAccepted()
            || !$this->webhookEmailIpLimiter->create($ip)->consume(1)->isAccepted()) {
            $this->logger->warning('Webhook rate limit exceeded', ['ip' => $ip]);
            return new Response('Too Many Requests', 429);
        }

        return null;
    }
}
