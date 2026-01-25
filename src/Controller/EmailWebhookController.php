<?php

namespace App\Controller;

use App\Entity\EmailSend;
use App\Entity\EmailUnsubscribe;
use App\Service\EmailCampaignService;
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
            return new Response('Email send not found', 404);
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
            return new Response('Email send not found', 404);
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
            return new Response('Email send not found', 404);
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

    private function enforceWebhookSecret(Request $request): ?Response
    {
        $configuredSecret = $_SERVER['EMAIL_WEBHOOK_SECRET']
            ?? $_ENV['EMAIL_WEBHOOK_SECRET']
            ?? getenv('EMAIL_WEBHOOK_SECRET')
            ?: null;

        if (!$configuredSecret) {
            return null;
        }

        $provided = $request->headers->get('X-Webhook-Secret');
        if (!$provided || !hash_equals((string)$configuredSecret, (string)$provided)) {
            return new Response('Unauthorized', 401);
        }

        return null;
    }
}
