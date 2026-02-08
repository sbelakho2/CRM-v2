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
use Symfony\Component\Routing\Attribute\Route;

#[Route('/webhook/email')]
class EmailWebhookController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailCampaignService $campaignService,
        private ?AutonomousSalesOrchestratorService $orchestrator,
        private LoggerInterface $logger
    ) {
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

        // Handle different event types
        switch ($event) {
            case 'delivered':
                // Email successfully delivered
                $this->logger->info('Email delivered', ['send_id' => $sendId]);
                break;
                
            case 'opened':
                // Email opened (alternative to pixel tracking)
                if (!$send->isOpened()) {
                    $this->campaignService->markOpened($send);
                    $this->logger->info('Email marked as opened via webhook', ['send_id' => $sendId]);
                }
                break;
                
            case 'clicked':
                // Link clicked (alternative to redirect tracking)
                if (!$send->isClicked()) {
                    $this->campaignService->markClicked($send);
                    $this->logger->info('Email marked as clicked via webhook', ['send_id' => $sendId]);
                }
                break;
                
            case 'unsubscribed':
                // User unsubscribed
                $this->logger->info('User unsubscribed', ['send_id' => $sendId]);
                
                // Persist to global suppression list
                $email = $eventData['recipient']
                    ?? $eventData['recipient-email']
                    ?? $eventData['email']
                    ?? $data['email']
                    ?? null;

                if (is_string($email) && $email !== '') {
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
                        $this->entityManager->flush();
                        $this->logger->info('Email added to unsubscribe list', ['email' => $email]);
                    }
                }
                break;
                
            case 'complained':
                // User marked as spam
                $this->logger->warning('Email marked as spam', ['send_id' => $sendId]);
                break;
                
            case 'bounced':
            case 'failed':
                // Email bounced
                $this->campaignService->markBounced($send);
                $this->logger->info('Email marked as bounced', ['send_id' => $sendId]);
                break;
                
            default:
                $this->logger->info('Unhandled event type', ['event' => $event, 'send_id' => $sendId]);
        }
        
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

        $events = json_decode($request->getContent(), true);
        
        if (!is_array($events)) {
            return new Response('Invalid JSON', 400);
        }
        
        foreach ($events as $data) {
            $event = $data['event'] ?? '';
            $sendId = $data['email_send_id'] ?? null; // Custom argument we'll add

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
            
            // Handle different event types
            switch ($event) {
                case 'delivered':
                    $this->logger->info('Email delivered', ['send_id' => $sendId]);
                    break;
                    
                case 'open':
                    if (!$send->isOpened()) {
                        $this->campaignService->markOpened($send);
                        $this->logger->info('Email marked as opened via webhook', ['send_id' => $sendId]);
                    }
                    break;
                    
                case 'click':
                    if (!$send->isClicked()) {
                        $this->campaignService->markClicked($send);
                        $this->logger->info('Email marked as clicked via webhook', ['send_id' => $sendId]);
                    }
                    break;
                    
                case 'bounce':
                case 'dropped':
                    $this->campaignService->markBounced($send);
                    $this->logger->info('Email marked as bounced', ['send_id' => $sendId]);
                    break;
                    
                case 'spamreport':
                    $this->logger->warning('Email marked as spam', ['send_id' => $sendId]);
                    break;
            }
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
        
        // Handle different record types
        switch ($recordType) {
            case 'Delivery':
                $this->logger->info('Email delivered', ['send_id' => $sendId]);
                break;
                
            case 'Open':
                if (!$send->isOpened()) {
                    $this->campaignService->markOpened($send);
                    $this->logger->info('Email marked as opened via webhook', ['send_id' => $sendId]);
                }
                break;
                
            case 'Click':
                if (!$send->isClicked()) {
                    $this->campaignService->markClicked($send);
                    $this->logger->info('Email marked as clicked via webhook', ['send_id' => $sendId]);
                }
                break;
                
            case 'Bounce':
                $this->campaignService->markBounced($send);
                $bounceType = $data['Type'] ?? 'Unknown';
                $this->logger->info('Email marked as bounced', [
                    'send_id' => $sendId,
                    'bounce_type' => $bounceType
                ]);
                break;
                
            case 'SpamComplaint':
                $this->logger->warning('Email marked as spam', ['send_id' => $sendId]);
                break;
        }
        
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
        
        switch ($event) {
            case 'opened':
                $this->campaignService->markOpened($send);
                break;
            case 'clicked':
                $this->campaignService->markClicked($send);
                break;
            case 'replied':
                $this->campaignService->markReplied($send);
                break;
            case 'bounced':
                $this->campaignService->markBounced($send);
                break;
        }
        
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
                'message' => 'Failed to process event: ' . $e->getMessage(),
            ]), 500, ['Content-Type' => 'application/json']);
        }
    }

    /**
     * Bridge endpoint: Routes traditional campaign webhook events to autonomous sales
     * 
     * This endpoint allows existing email campaign tracking to also feed the ML system.
     * Call this endpoint when you want traditional campaign tracking PLUS autonomous learning.
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
        
        // First, handle traditional campaign tracking
        $send = $this->entityManager->getRepository(EmailSend::class)->find($sendId);
        
        if ($send) {
            switch ($event) {
                case 'opened':
                case 'open':
                    if (!$send->isOpened()) {
                        $this->campaignService->markOpened($send);
                    }
                    break;
                case 'clicked':
                case 'click':
                    if (!$send->isClicked()) {
                        $this->campaignService->markClicked($send);
                    }
                    break;
                case 'replied':
                case 'reply':
                    $this->campaignService->markReplied($send);
                    break;
                case 'bounced':
                case 'bounce':
                    $this->campaignService->markBounced($send);
                    break;
            }
        }
        
        // Then, if this email send has an associated OutboundMessage, update autonomous sales
        if ($this->orchestrator) {
            // Try to find OutboundMessage linked to this send's contact and campaign
            $outboundMessage = null;
            if ($send && $send->getContact()) {
                $outboundMessage = $this->entityManager->getRepository(OutboundMessage::class)
                    ->findOneBy([
                        'contact' => $send->getContact(),
                        'status' => 'sent',
                    ], ['sentAt' => 'DESC']);
            }
            
            if ($outboundMessage) {
                $replyContent = $data['reply_content'] ?? null;
                $this->orchestrator->recordEmailEvent($outboundMessage, $event, $replyContent);
                
                $this->logger->info('Campaign event bridged to autonomous sales', [
                    'send_id' => $sendId,
                    'outbound_message_id' => $outboundMessage->getId(),
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
            // No secret configured — allow but log warning in non-production
            $this->logger->warning('EMAIL_WEBHOOK_SECRET not configured — webhook endpoint is unprotected');
            return null;
        }

        $provided = $request->headers->get('X-Webhook-Secret');
        if (!$provided || !hash_equals((string)$configuredSecret, (string)$provided)) {
            return new Response('Unauthorized', 401);
        }

        return null;
    }
}
