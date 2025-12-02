<?php

namespace App\Controller;

use App\Entity\EmailSend;
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
        $data = $request->request->all();
        
        $this->logger->info('Mailgun webhook received', ['data' => $data]);
        
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
                
                // Mark contact as unsubscribed if we can identify them
                if (isset($data['email'])) {
                    $email = $data['email'];
                    $contactRepo = $this->entityManager->getRepository(\App\Entity\Contact::class);
                    $contact = $contactRepo->findOneBy(['email' => $email]);
                    
                    if ($contact) {
                        $contact->setEmailOptOut(true);
                        $contact->setEmailOptOutDate(new \DateTimeImmutable());
                        $this->entityManager->flush();
                        $this->logger->info('Contact marked as unsubscribed', ['contact_id' => $contact->getId()]);
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
        $events = json_decode($request->getContent(), true);
        
        if (!is_array($events)) {
            return new Response('Invalid JSON', 400);
        }
        
        foreach ($events as $data) {
            $this->logger->info('SendGrid webhook received', ['data' => $data]);
            
            $event = $data['event'] ?? '';
            $sendId = $data['email_send_id'] ?? null; // Custom argument we'll add
            
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
        $data = json_decode($request->getContent(), true);
        
        $this->logger->info('Postmark webhook received', ['data' => $data]);
        
        $recordType = $data['RecordType'] ?? '';
        $metadata = $data['Metadata'] ?? [];
        $sendId = $metadata['email_send_id'] ?? null;
        
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
        $data = json_decode($request->getContent(), true) ?? $request->request->all();
        
        $this->logger->info('Generic webhook received', ['data' => $data]);
        
        $sendId = $data['email_send_id'] ?? null;
        $event = $data['event'] ?? null;
        
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
}
