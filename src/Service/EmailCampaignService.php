<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use Symfony\Component\Mailer\Transport\TransportInterface;
use App\Entity\Contact;
use App\Repository\EmailCampaignRepository;
use App\Repository\EmailSendRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class EmailCampaignService
{
    private const DEFAULT_WEBSITE_URL = 'https://starzelectronics.site';

    public function __construct(
        private ManagerRegistry $managerRegistry,
        private EntityManagerInterface $entityManager,
        private EmailCampaignRepository $campaignRepository,
        private EmailSendRepository $sendRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private EmailTrackingSigner $trackingSigner,
        private LoggerInterface $logger,
        private EmailSendPolicy $sendPolicy,
        private TransportInterface $mailerTransport,
        private ?\App\Service\EmailDripCampaignService $dripCampaignService = null,
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
     * Canonical path for EVERY sender (manual, scheduler, Messenger, drip).
     *
     *  1. EmailSendPolicy is evaluated first: unsubscribed, bounced,
     *     cadence-exceeded or archived contacts are refused here — no
     *     calling route can bypass the policy.
     *  2. Idempotency/retry per (campaign, contact, touch): SENT rows are
     *     never resent; QUEUED/SENDING rows belong to a concurrent worker;
     *     FAILED rows are REUSED for the retry (reset to queued, retryCount
     *     incremented) — never re-inserted, because the unique constraint
     *     forbids a second row for the same touch.
     */
    public function sendToContact(EmailCampaign $campaign, Contact $contact, int $touchNumber): CampaignSendResult
    {
        if (!$this->isValidTouchNumber($campaign, $touchNumber)) {
            throw new \InvalidArgumentException(sprintf(
                'Touch number %d is outside the campaign sequence (1..%d).',
                $touchNumber,
                max(1, (int) $campaign->getTouchCount())
            ));
        }

        $eligibility = $this->sendPolicy->evaluate($contact, $campaign);
        if (!$eligibility->allowed) {
            $this->logger->info('Campaign send refused by send policy', [
                'campaign_id' => $campaign->getId(),
                'contact_id' => $contact->getId(),
                'reason' => $eligibility->reason,
            ]);

            return CampaignSendResult::skipped((string) $eligibility->reason);
        }

        $existing = $this->sendRepository->findTouch(
            (int) $campaign->getId(),
            (int) $contact->getId(),
            $touchNumber
        );

        if ($existing !== null) {
            $claim = $this->claimExistingTouch($existing);
            if ($claim !== null) {
                return $claim;
            }
            $send = $existing;
        } else {
            // No row yet: create the send record in 'queued' state —
            // reflects reality until the transport accepts the message.
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
                // A concurrent worker won the race for this touch. After a
                // failed flush the ORM manager may be closed: re-fetch
                // through a reset manager rather than reusing it.
                $this->entityManager = $this->managerRegistry->resetManager();
                $this->sendRepository = $this->entityManager->getRepository(EmailSend::class);

                $winner = $this->sendRepository->findTouch(
                    (int) $campaign->getId(),
                    (int) $contact->getId(),
                    $touchNumber
                );
                if ($winner !== null && $winner->getStatus() === EmailSend::STATUS_SENT) {
                    return CampaignSendResult::alreadySent();
                }

                return CampaignSendResult::alreadyInProgress();
            }
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

            return CampaignSendResult::failed();
        }

        // Transport accepted the message — mark as sent with an accurate
        // timestamp and release the delivery lease.
        $send->setStatus(EmailSend::STATUS_SENT);
        $send->setSentAt(new \DateTime());
        $send->setSendLeaseExpiresAt(null);
        $this->entityManager->flush();

        $this->progressDripSequence($send);

        return CampaignSendResult::sent();
    }

    /**
     * Drip progression: after an authoritative delivery (transport
     * accepted), schedule the next touch of a drip sequence. Idempotent —
     * if the next-touch row already exists, nothing happens (unique
     * (campaign, contact, touch) constraint + pre-check).
     */
    private function progressDripSequence(EmailSend $sent): void
    {
        try {
            $campaign = $sent->getCampaign();
            if ($campaign !== null && $campaign->getType() === EmailCampaign::TYPE_DRIP) {
                $this->dripCampaignService?->processCompletedTouch($sent);
            }
        } catch (\Throwable $e) {
            // Progression failure must never fail the delivered send.
            $this->logger->error('Drip progression failed after successful send', [
                'email_send_id' => $sent->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private const SEND_LEASE_SECONDS = 900; // 15 minutes

    private function isValidTouchNumber(EmailCampaign $campaign, int $touchNumber): bool
    {
        $max = max(1, (int) $campaign->getTouchCount());

        return $touchNumber >= 1 && $touchNumber <= $max;
    }

    /**
     * Decide what to do with an existing touch row. Returns a terminal
     * CampaignSendResult when the caller must NOT proceed, or null when the
     * row is claimable for a (re)try:
     *
     *  - SENT: never resend (idempotency).
     *  - QUEUED/SENDING with an UNEXPIRED lease: another worker owns it.
     *  - QUEUED/SENDING with an EXPIRED lease: crash debris — claimable.
     *  - FAILED/CANCELLED: claimable retry, reusing the row.
     *  - BOUNCED: terminal for this address (suppression handles retries).
     */
    private function claimExistingTouch(EmailSend $existing): ?CampaignSendResult
    {
        $status = $existing->getStatus();

        if ($status === EmailSend::STATUS_SENT || $status === EmailSend::STATUS_BOUNCED) {
            return CampaignSendResult::alreadySent();
        }

        if (in_array($status, [EmailSend::STATUS_QUEUED, EmailSend::STATUS_SENDING], true)) {
            $lease = $existing->getSendLeaseExpiresAt();
            $leaseAlive = $lease !== null && $lease > new \DateTime();

            // A live SENDING lease means another worker owns delivery.
            if ($leaseAlive && $status === EmailSend::STATUS_SENDING) {
                return CampaignSendResult::alreadyInProgress();
            }

            // QUEUED rows scheduled for the future (drip/triggered
            // scheduling) are not yet due — only the due-send worker may
            // execute them at their scheduled time.
            $scheduledFor = $existing->getScheduledAt();
            if ($status === EmailSend::STATUS_QUEUED && $scheduledFor !== null && $scheduledFor > new \DateTime()) {
                return CampaignSendResult::alreadyInProgress();
            }

            // A live QUEUED lease belongs to a worker between claim and
            // transport handoff.
            if ($leaseAlive && $status === EmailSend::STATUS_QUEUED) {
                return CampaignSendResult::alreadyInProgress();
            }
        }

        // Claimable: stale lease, lease-less non-sending debris, FAILED,
        // CANCELLED — reuse the row and count the retry attempt.
        $existing->setRetryCount($existing->getRetryCount() + 1);
        $existing->setFailureReason(null);

        return null; // caller proceeds with this row
    }

    /**
     * Execute a PRE-CREATED EmailSend row (drip progression and triggered
     * emails schedule rows with a future scheduledAt; the due-send worker
     * claims and executes exactly those rows).
     *
     * Same policy/idempotency/state semantics as sendToContact, but the
     * row already exists BY DESIGN — never call the create-or-dedupe path
     * for it.
     */
    public function sendExisting(EmailSend $send): CampaignSendResult
    {
        $campaign = $send->getCampaign();
        $contact = $send->getContact();

        if ($campaign === null || $contact === null) {
            $send->setStatus(EmailSend::STATUS_FAILED);
            $send->setFailureReason('missing campaign or contact');
            $this->entityManager->flush();

            return CampaignSendResult::failed();
        }

        if (!$this->isValidTouchNumber($campaign, (int) $send->getTouchNumber())) {
            $send->setStatus(EmailSend::STATUS_FAILED);
            $send->setFailureReason('touch number outside campaign sequence');
            $this->entityManager->flush();

            return CampaignSendResult::failed();
        }

        $eligibility = $this->sendPolicy->evaluate($contact, $campaign);
        if (!$eligibility->allowed) {
            // Policy refusal is terminal for a scheduled row: mark cancelled
            // so the due-send worker does not re-pick it forever.
            $send->setStatus(EmailSend::STATUS_CANCELLED);
            $send->setFailureReason('policy: ' . $eligibility->reason);
            $send->setSendLeaseExpiresAt(null);
            $this->entityManager->flush();

            return CampaignSendResult::skipped((string) $eligibility->reason);
        }

        if ($send->getStatus() === EmailSend::STATUS_SENT) {
            return CampaignSendResult::alreadySent();
        }

        $send->setRetryCount($send->getRetryCount() + 1);

        try {
            $this->sendEmail($campaign, $contact, (int) $send->getTouchNumber(), $send);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send scheduled campaign email', [
                'email_send_id' => $send->getId(),
                'error' => $e->getMessage(),
            ]);

            return CampaignSendResult::failed();
        }

        $send->setStatus(EmailSend::STATUS_SENT);
        $send->setSentAt(new \DateTime());
        $send->setSendLeaseExpiresAt(null);
        $this->entityManager->flush();

        $this->progressDripSequence($send);

        return CampaignSendResult::sent();
    }

    /**
     * Send the actual email — SYNCHRONOUSLY through the real transport.
     *
     * MailerInterface routes through the async Messenger bus in production,
     * so using it here would make STATUS_SENT mean "queued into Symfony
     * Mailer" while the actual SMTP delivery (and its failures) happen
     * later, invisible to the campaign retry machinery. This worker owns
     * delivery and retries, so it talks to the transport directly and only
     * marks SENT after the transport accepted the message.
     *
     * @throws \Throwable Re-throws any transport failure after marking the
     *                    EmailSend record as 'failed'.
     */
    private function sendEmail(EmailCampaign $campaign, Contact $contact, int $touchNumber, EmailSend $send): void
    {
        // Move the record to 'sending' with a fresh lease before the
        // transport call: crash debris becomes re-claimable when the lease
        // expires instead of sticking as "in progress" forever.
        $send->setStatus(EmailSend::STATUS_SENDING);
        $send->setSendLeaseExpiresAt(
            (new \DateTime())->modify('+' . self::SEND_LEASE_SECONDS . ' seconds')
        );
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

            $this->mailerTransport->send($email);
        } catch (\Throwable $e) {
            // Log error and update send record to reflect failure
            $send->setStatus(EmailSend::STATUS_FAILED);
            $send->setSendLeaseExpiresAt(null);
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
        // Engagement denominators must describe the DELIVERED population:
        // queued/sending/failed/cancelled rows are not emails a recipient
        // could have opened. Bounced rows count as delivered-then-rejected
        // and are reported separately.
        $delivered = array_values(array_filter(
            iterator_to_array($sends),
            static fn (EmailSend $s) => in_array($s->getStatus(), [EmailSend::STATUS_SENT, EmailSend::STATUS_BOUNCED], true)
        ));
        $total = count($delivered);

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

        foreach ($delivered as $send) {
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
    /**
     * Contacts eligible for the given touch, computed in SQL:
     *
     *  - touch 1: enrolled contacts with no touch-1 record yet
     *  - touch N>1: contacts whose touch N-1 was SENT, who have no touch-N
     *    record, who have not unsubscribed, not hard-bounced, and not
     *    replied-stop. Cadence/archival are re-checked per contact by the
     *    send policy at delivery time.
     */
    public function getContactsForNextTouch(EmailCampaign $campaign, int $touchNumber): array
    {
        if ($touchNumber < 1) {
            return [];
        }

        $previous = max(1, $touchNumber - 1);

        $qb = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contact::class, 'c')
            ->join('c.emailCampaigns', 'ec')
            ->andWhere('ec = :campaign')
            ->andWhere('c.email IS NOT NULL')
            ->andWhere('c.archivedAt IS NULL')
            // Global unsubscribe suppression by email address.
            ->andWhere('NOT EXISTS (SELECT u.id FROM App\Entity\EmailUnsubscribe u WHERE u.email = c.email)')
            // No record for the target touch yet (any status).
            ->andWhere('NOT EXISTS (SELECT t.id FROM App\Entity\EmailSend t WHERE t.campaign = :campaign AND t.contact = c AND t.touchNumber = :touch)')
            // No hard bounce on this address in the last 90 days.
            ->andWhere('NOT EXISTS (SELECT b.id FROM App\Entity\EmailSend b WHERE b.emailAddress = c.email AND b.bounced = true AND b.sentAt > :bounceWindow)')
            // Reply-stop: any human reply in this sequence pauses further
            // automated touches until it is reviewed.
            ->andWhere('NOT EXISTS (SELECT r.id FROM App\Entity\EmailSend r WHERE r.campaign = :campaign AND r.contact = c AND r.replied = true)')
            ->setParameter('campaign', $campaign)
            ->setParameter('touch', $touchNumber)
            ->setParameter('bounceWindow', (new \DateTime())->modify('-90 days'));

        if ($touchNumber > 1) {
            // Touch N requires SENT touch N-1: the recipient actually
            // received the previous email in the sequence.
            $qb->andWhere('EXISTS (SELECT p.id FROM App\Entity\EmailSend p WHERE p.campaign = :campaign AND p.contact = c AND p.touchNumber = :previous AND p.status = :sent)')
               ->setParameter('previous', $previous)
               ->setParameter('sent', EmailSend::STATUS_SENT);
        }

        return $qb->getQuery()->getResult();
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
