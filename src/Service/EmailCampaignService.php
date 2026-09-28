<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use App\Entity\Contact;
use App\Repository\EmailCampaignRepository;
use App\Repository\EmailSendRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class EmailCampaignService
{
    private const DEFAULT_WEBSITE_URL = 'https://starzelectronics.site';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailCampaignRepository $campaignRepository,
        private EmailSendRepository $sendRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private EmailTrackingSigner $trackingSigner,
        private LoggerInterface $logger,
        private ?EmailConsentService $consentService = null
    ) {}

    /**
     * Create a new email campaign
     */
    public function createCampaign(string $name, string $language, int $touchCount = 5): EmailCampaign
    {
        $campaign = new EmailCampaign();
        $campaign->setName($name);
        $campaign->setLanguage($language);
        $campaign->setTouchCount($touchCount);
        $campaign->setActive(true);

        $this->entityManager->persist($campaign);
        $this->entityManager->flush();

        return $campaign;
    }

    /**
     * Send email to contact as part of campaign.
     *
     * Idempotent per (campaign, contact, touch): if this touch was already
     * sent (or is being sent) the call is a no-op returning true, so a worker
     * crash + redelivery can never duplicate a customer email. Persists a
     * 'queued' EmailSend first, transitions to 'sending' before the mailer
     * call, and only marks the send 'sent' after the mailer succeeds. On
     * failure the send is marked 'failed' and false is returned.
     */
    public function sendToContact(EmailCampaign $campaign, Contact $contact, int $touchNumber): bool
    {
        // Already-sent guard: a redelivered message (worker died after SMTP
        // accept, Messenger retry, manual re-dispatch) must not email the
        // customer twice for the same campaign touch.
        $existing = $this->sendRepository->findSentTouch(
            $campaign->getId(),
            (int) $contact->getId(),
            $touchNumber
        );
        if ($existing !== null) {
            $this->logger->info('Skipping duplicate campaign touch (already sent)', [
                'campaign_id' => $campaign->getId(),
                'contact_id' => $contact->getId(),
                'touch_number' => $touchNumber,
                'existing_email_send_id' => $existing->getId(),
            ]);

            return true;
        }

        // Create send record in 'queued' state — reflects reality until the
        // mailer actually accepts the message.
        $send = new EmailSend();
        $send->setCampaign($campaign);
        $send->setContact($contact);
        $send->setTouchNumber($touchNumber);
        $send->setEmailAddress($contact->getEmail());
        $send->setStatus(EmailSend::STATUS_QUEUED);

        $this->entityManager->persist($send);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Concurrent worker won the race for this touch — treat as sent.
            $this->entityManager->clear();

            return true;
        }

        try {
            // Send actual email with tracking
            $this->sendEmail($campaign, $contact, $touchNumber, $send);
        } catch (\Throwable $e) {
            // sendEmail already persisted the 'failed' state; log here and
            // report the failure to the caller.
            $this->logger->error('Failed to send campaign email', [
                'email_send_id' => $send->getId(),
                'campaign_id' => $campaign->getId(),
                'contact_email' => $contact->getEmail(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        // Mailer accepted the message — mark as sent with an accurate timestamp.
        $send->setStatus(EmailSend::STATUS_SENT);
        $send->setSentAt(new \DateTime());
        $this->entityManager->flush();

        return true;
    }

    /**
     * Send the actual email via mailer
     *
     * @throws \Throwable Re-throws any transport failure after marking the
     *                    EmailSend record as 'failed'.
     */
    private function sendEmail(EmailCampaign $campaign, Contact $contact, int $touchNumber, EmailSend $send): void
    {
        // Move the record to 'sending' before the mailer call so the status
        // never claims 'sent' while the transport is still in flight.
        $send->setStatus(EmailSend::STATUS_SENDING);
        $this->entityManager->flush();

        try {
            $subject = sprintf('[Touch %d/%d] %s', $touchNumber, $campaign->getTouchCount(), $campaign->getName());
            
            $email = (new Email())
                ->from($_ENV['MAILER_FROM_ADDRESS'] ?? 'noreply@starzelectronics.site')
                ->to($contact->getEmail())
                ->subject($subject)
                ->html($this->generateEmailContent($campaign, $contact, $touchNumber, $send));

            // Add custom headers for webhook tracking
            // These headers will be included when email service sends webhook events
            $headers = $email->getHeaders();
            $headers->addTextHeader('X-Email-Send-ID', (string)$send->getId());
            $headers->addTextHeader('X-Campaign-ID', (string)$campaign->getId());
            $headers->addTextHeader('X-Touch-Number', (string)$touchNumber);
            
            // For Mailgun: Add custom variables (available in webhooks as 'user-variables')
            $headers->addTextHeader('X-Mailgun-Variables', json_encode([
                'email_send_id' => $send->getId(),
                'campaign_id' => $campaign->getId(),
                'touch_number' => $touchNumber
            ]));

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            // Log error and update send record to reflect failure
            $send->setStatus(EmailSend::STATUS_FAILED);
            $send->setFailureReason($e->getMessage());
            $this->entityManager->flush();

            throw $e;
        }
    }

    /**
     * Generate email content
     */
    private function generateEmailContent(EmailCampaign $campaign, Contact $contact, int $touchNumber, EmailSend $send): string
    {
        $companyName = $contact->getCompany() ? $contact->getCompany()->getName() : '';
        
        // Generate tracking URLs
        $trackingPixelSig = $send->getId() ? $this->trackingSigner->signOpen($send->getId()) : null;
        $trackingPixelUrl = $this->urlGenerator->generate(
            'app_email_send_track_open',
            ['id' => $send->getId(), 'sig' => $trackingPixelSig],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        
        $websiteUrl = $contact->getCompany() && $contact->getCompany()->getWebsite() 
            ? $contact->getCompany()->getWebsite() 
            : self::DEFAULT_WEBSITE_URL;
            
        $trackingLinkSig = $send->getId() ? $this->trackingSigner->signClick($send->getId(), $websiteUrl) : null;
        $trackingLinkUrl = $this->urlGenerator->generate(
            'app_email_send_track_click',
            ['id' => $send->getId(), 'url' => $websiteUrl, 'sig' => $trackingLinkSig],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // One-click unsubscribe link (HMAC-signed by EmailConsentService).
        // Fall back to building the same signed token locally when the consent
        // service is not available (e.g. unit tests), so the footer always
        // carries a working unsubscribe URL.
        $unsubscribeLink = $this->consentService
            ? $this->consentService->generateUnsubscribeLink($contact, $campaign->getId())
            : $this->buildFallbackUnsubscribeLink($contact, $campaign->getId());
        
        return sprintf('
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 0 auto; }
        .header { background: #2563eb; color: white; padding: 30px 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 40px 20px; background: #f9fafb; }
        .content p { margin: 0 0 15px 0; }
        .cta-button { 
            display: inline-block; 
            background: #2563eb; 
            color: white; 
            padding: 12px 30px; 
            text-decoration: none; 
            border-radius: 6px;
            margin: 20px 0;
            font-weight: 600;
        }
        .cta-button:hover { background: #1d4ed8; }
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 12px; background: white; }
        .footer a { color: #2563eb; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 STARZ Electronics Morocco</h1>
            <p style="margin: 10px 0 0 0; opacity: 0.9;">Your Electronic Components Partner</p>
        </div>
        <div class="content">
            <p><strong>Dear %s,</strong></p>
            
            <p>This is touch <strong>%d of %d</strong> in our <strong>%s</strong> campaign.</p>
            
            <p>We are reaching out to introduce STARZ Electronics Morocco, your trusted partner for high-quality electronic components and solutions.</p>
            
            %s
            
            <p><strong>Why Choose STARZ Electronics?</strong></p>
            <ul style="line-height: 1.8;">
                <li>Wide range of electronic components</li>
                <li>Competitive pricing and fast delivery</li>
                <li>Technical support and expertise</li>
                <li>Quality assurance and certifications</li>
            </ul>
            
            <p style="text-align: center;">
                <a href="%s" class="cta-button">Visit Our Website</a>
            </p>
            
            <p>We would love to discuss how we can support your business needs. Feel free to reply to this email or contact us directly.</p>
            
            <p>Best regards,<br>
            <strong>STARZ Electronics Morocco Team</strong><br>
            contact@starzelectronics.site</p>
        </div>
        <div class="footer">
            <p>&copy; %s STARZ Electronics Morocco. All rights reserved.</p>
            <p>Touch %d/%d - Campaign: %s (%s)</p>
            <p><a href="%s">Unsubscribe</a> | <a href="%s">Update Preferences</a></p>
        </div>
    </div>
    <!-- Open tracking pixel -->
    <img src="%s" width="1" height="1" style="display:none;" alt="">
</body>
</html>
        ',
            htmlspecialchars($contact->getFirstName() ?? '', ENT_QUOTES, 'UTF-8'),
            $touchNumber,
            $campaign->getTouchCount(),
            htmlspecialchars((string) $campaign->getName(), ENT_QUOTES, 'UTF-8'),
            $companyName ? '<p><strong>Company:</strong> ' . htmlspecialchars($companyName) . '</p>' : '',
            htmlspecialchars($trackingLinkUrl, ENT_QUOTES, 'UTF-8'),
            date('Y'),
            $touchNumber,
            $campaign->getTouchCount(),
            htmlspecialchars((string) $campaign->getName(), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) $campaign->getLanguage(), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($unsubscribeLink, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($unsubscribeLink, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($trackingPixelUrl, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Build a signed one-click unsubscribe link without the consent service.
     *
     * Mirrors EmailConsentService::generateUnsubscribeLink exactly so the
     * token remains verifiable by EmailConsentService::processUnsubscribeToken.
     */
    private function buildFallbackUnsubscribeLink(Contact $contact, ?int $campaignId): string
    {
        $timestamp = time();
        $payload = $contact->getEmail() . '|' . $timestamp;
        $hmac = hash_hmac('sha256', $payload, $this->getSigningSecret());
        $token = base64_encode($payload . '|' . $hmac);

        $params = ['token' => $token];
        if ($campaignId) {
            $params['campaign'] = $campaignId;
        }

        return sprintf(
            '%s/email/unsubscribe?%s',
            rtrim($_ENV['APP_BASE_URL'] ?? 'https://crm.starz-morocco.com', '/'),
            http_build_query($params)
        );
    }

    /**
     * Fails closed: without APP_SECRET no unsubscribe token can be signed.
     */
    private function getSigningSecret(): string
    {
        $secret = $_ENV['APP_SECRET'] ?? $_SERVER['APP_SECRET'] ?? getenv('APP_SECRET');

        if (!$secret) {
            throw new \RuntimeException('APP_SECRET is not configured — unsubscribe tokens cannot be signed.');
        }

        return (string) $secret;
    }

    /**
     * Mark email as opened
     */
    public function markOpened(EmailSend $send): void
    {
        $send->setOpened(true);
        $send->setOpenedAt(new \DateTime());
        $this->entityManager->persist($send);
        $this->entityManager->flush();
    }

    /**
     * Mark email as clicked
     */
    public function markClicked(EmailSend $send): void
    {
        $send->setClicked(true);
        $send->setClickedAt(new \DateTime());
        $this->entityManager->persist($send);
        $this->entityManager->flush();
    }

    /**
     * Mark email as replied
     */
    public function markReplied(EmailSend $send): void
    {
        $send->setReplied(true);
        $this->entityManager->persist($send);
        $this->entityManager->flush();
    }

    /**
     * Mark email as bounced
     */
    public function markBounced(EmailSend $send): void
    {
        $send->setBounced(true);
        $this->entityManager->persist($send);
        $this->entityManager->flush();
    }

    /**
     * Get campaign performance metrics
     */
    public function getCampaignMetrics(EmailCampaign $campaign): array
    {
        $sends = $campaign->getEmailSends();
        $total = count($sends);
        
        if ($total === 0) {
            return [
                'total_sent' => 0,
                'opened' => 0,
                'clicked' => 0,
                'replied' => 0,
                'bounced' => 0,
                'open_rate' => 0,
                'click_rate' => 0,
                'reply_rate' => 0,
                'bounce_rate' => 0,
            ];
        }

        $opened = 0;
        $clicked = 0;
        $replied = 0;
        $bounced = 0;

        foreach ($sends as $send) {
            if ($send->isOpened()) $opened++;
            if ($send->isClicked()) $clicked++;
            if ($send->isReplied()) $replied++;
            if ($send->isBounced()) $bounced++;
        }

        return [
            'total_sent' => $total,
            'opened' => $opened,
            'clicked' => $clicked,
            'replied' => $replied,
            'bounced' => $bounced,
            'open_rate' => ($opened / $total) * 100,
            'click_rate' => ($clicked / $total) * 100,
            'reply_rate' => ($replied / $total) * 100,
            'bounce_rate' => ($bounced / $total) * 100,
        ];
    }

    /**
     * Get contacts for next touch in sequence
     */
    public function getContactsForNextTouch(EmailCampaign $campaign, int $touchNumber): array
    {
        // Logic to find contacts who need the next touch
        // This would check who received touch N-1 and needs touch N
        return []; // To be implemented with complex query
    }

    /**
     * Get 5-touch sequence progress for contact
     */
    public function getContactProgress(Contact $contact, EmailCampaign $campaign): array
    {
        $touches = [1 => false, 2 => false, 3 => false, 4 => false, 5 => false];
        
        $qb = $this->entityManager->createQueryBuilder();
        $sends = $qb->select('s')
            ->from(EmailSend::class, 's')
            ->where('s.campaign = :campaign')
            ->andWhere('s.contact = :contact')
            ->setParameter('campaign', $campaign)
            ->setParameter('contact', $contact)
            ->getQuery()
            ->getResult();

        foreach ($sends as $send) {
            $touchNum = $send->getTouchNumber();
            if ($touchNum >= 1 && $touchNum <= 5) {
                $touches[$touchNum] = [
                    'sent' => $send->getSentAt(),
                    'opened' => $send->isOpened(),
                    'clicked' => $send->isClicked(),
                    'replied' => $send->isReplied(),
                ];
            }
        }

        return $touches;
    }
}
