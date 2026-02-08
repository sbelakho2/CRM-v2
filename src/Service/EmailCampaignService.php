<?php

namespace App\Service;

use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use App\Entity\Contact;
use App\Repository\EmailCampaignRepository;
use App\Repository\EmailSendRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class EmailCampaignService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailCampaignRepository $campaignRepository,
        private EmailSendRepository $sendRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private EmailTrackingSigner $trackingSigner,
        private LoggerInterface $logger
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
     * Send email to contact as part of campaign
     */
    public function sendToContact(EmailCampaign $campaign, Contact $contact, int $touchNumber): EmailSend
    {
        // Create send record
        $send = new EmailSend();
        $send->setCampaign($campaign);
        $send->setContact($contact);
        $send->setTouchNumber($touchNumber);
        $send->setSentAt(new \DateTime());
        $send->setEmailAddress($contact->getEmail());
        $send->setStatus('sent');

        $this->entityManager->persist($send);
        $this->entityManager->flush();

        // Send actual email with tracking
        $this->sendEmail($campaign, $contact, $touchNumber, $send);

        return $send;
    }

    /**
     * Send the actual email via mailer
     */
    private function sendEmail(EmailCampaign $campaign, Contact $contact, int $touchNumber, EmailSend $send): void
    {
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
        } catch (\Exception $e) {
            // Log error and update send record to reflect failure
            $send->setStatus('failed');
            $send->setFailureReason($e->getMessage());
            $this->entityManager->flush();

            $this->logger->error('Failed to send email', [
                'email_send_id' => $send->getId(),
                'campaign_id' => $campaign->getId(),
                'contact_email' => $contact->getEmail(),
                'error' => $e->getMessage(),
            ]);
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
            : 'https://starzelectronics.site';
            
        $trackingLinkSig = $send->getId() ? $this->trackingSigner->signClick($send->getId(), $websiteUrl) : null;
        $trackingLinkUrl = $this->urlGenerator->generate(
            'app_email_send_track_click',
            ['id' => $send->getId(), 'url' => $websiteUrl, 'sig' => $trackingLinkSig],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        
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
            <p><a href="#">Unsubscribe</a> | <a href="#">Update Preferences</a></p>
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
            $campaign->getName(),
            $companyName ? '<p><strong>Company:</strong> ' . htmlspecialchars($companyName) . '</p>' : '',
            $trackingLinkUrl,
            date('Y'),
            $touchNumber,
            $campaign->getTouchCount(),
            $campaign->getName(),
            $campaign->getLanguage(),
            $trackingPixelUrl
        );
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
