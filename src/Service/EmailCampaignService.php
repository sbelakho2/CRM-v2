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
        private ?\App\Service\EmailAbTestService $abTestService = null,
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
            $existingId = $existing->getId();
            $claim = $this->claimExistingTouch($existing);
            if ($claim !== null) {
                return $claim;
            }
            // claimExistingTouch cleared the identity map: re-fetch the row
            // in its freshly-claimed SENDING state.
            $send = $this->sendRepository->find($existingId);
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
        $send->setNextAttemptAt(null);
        $this->entityManager->flush();

        $this->progressDripSequence($send);

        return CampaignSendResult::sent();
    }

    /**
     * Queue a touch for delivery by the due-send worker (used by the manual
     * mass-send UI: SMTP handoffs happen in workers, never in web requests).
     */
    public function queueTouch(EmailCampaign $campaign, Contact $contact, int $touchNumber): CampaignSendResult
    {
        if (!$this->isValidTouchNumber($campaign, $touchNumber)) {
            throw new \InvalidArgumentException(sprintf(
                'Touch number %d is outside the campaign sequence (1..%d).',
                $touchNumber,
                max(1, (int) $campaign->getTouchCount())
            ));
        }

        $existing = $this->sendRepository->findTouch(
            (int) $campaign->getId(),
            (int) $contact->getId(),
            $touchNumber
        );

        if ($existing !== null) {
            if ($existing->getStatus() === EmailSend::STATUS_SENT || $existing->getStatus() === EmailSend::STATUS_BOUNCED) {
                return CampaignSendResult::alreadySent();
            }

            return CampaignSendResult::alreadyInProgress();
        }

        $send = new EmailSend();
        $send->setCampaign($campaign);
        $send->setContact($contact);
        $send->setTouchNumber($touchNumber);
        $send->setEmailAddress($contact->getEmail());
        $send->setStatus(EmailSend::STATUS_QUEUED);
        $send->setScheduledAt(new \DateTime()); // due immediately

        $this->entityManager->persist($send);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $this->entityManager = $this->managerRegistry->resetManager();
            $this->sendRepository = $this->entityManager->getRepository(EmailSend::class);

            return CampaignSendResult::alreadyInProgress();
        }

        return CampaignSendResult::queued();
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

    /** Terminal failure only after this many delivery attempts. */
    public const MAX_SEND_ATTEMPTS = 5;

    private function isValidTouchNumber(EmailCampaign $campaign, int $touchNumber): bool
    {
        $max = max(1, (int) $campaign->getTouchCount());

        return $touchNumber >= 1 && $touchNumber <= $max;
    }

    /**
     * ATOMICALLY claim an existing touch row for this worker.
     *
     * Reading status/lease in PHP and proceeding is a race: two workers can
     * both see an expired SENDING or a FAILED row and both send it — the
     * unique (campaign, contact, touch) constraint only protects first
     * INSERT, not concurrent ownership. Ownership is therefore decided by a
     * single conditional UPDATE: the one worker whose affectedRows === 1
     * owns the delivery.
     *
     * Returns null when the row was claimed for the caller (row is reloaded
     * with SENDING state + fresh lease), or a terminal result.
     */
    private function claimExistingTouch(EmailSend $existing): ?CampaignSendResult
    {
        $status = $existing->getStatus();

        if ($status === EmailSend::STATUS_SENT || $status === EmailSend::STATUS_BOUNCED) {
            return CampaignSendResult::alreadySent();
        }

        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $leaseUntil = (new \DateTime())->modify('+' . self::SEND_LEASE_SECONDS . ' seconds')->format('Y-m-d H:i:s');

        // Claimable-by-this-worker:
        //   - FAILED/CANCELLED rows (retryable within the attempts cap,
        //     backoff elapsed),
        //   - SENDING rows with a dead lease (crash debris),
        //   - QUEUED rows that are due (or unscheduled) with a dead lease.
        // NOT claimable (affectedRows 0 → in progress elsewhere):
        //   - live SENDING/QUEUED leases,
        //   - QUEUED rows scheduled for the future (drip/triggered rows —
        //     only the due-send worker may execute them at their time).
        $affected = $this->entityManager->getConnection()->executeStatement(
            'UPDATE email_sends
             SET status = :sending,
                 send_lease_expires_at = :lease,
                 retry_count = retry_count + 1,
                 failure_reason = NULL
             WHERE id = :id
               AND (
                     status IN (:failed, :cancelled)
                     AND retry_count < :maxAttempts
                     AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
                   OR (status = :sending
                       AND (send_lease_expires_at IS NULL OR send_lease_expires_at <= :now))
                   OR (status = :queued
                       AND (scheduled_at IS NULL OR scheduled_at <= :now)
                       AND (send_lease_expires_at IS NULL OR send_lease_expires_at <= :now))
               )',
            [
                'sending' => EmailSend::STATUS_SENDING,
                'failed' => EmailSend::STATUS_FAILED,
                'cancelled' => EmailSend::STATUS_CANCELLED,
                'queued' => EmailSend::STATUS_QUEUED,
                'lease' => $leaseUntil,
                'id' => $existing->getId(),
                'maxAttempts' => self::MAX_SEND_ATTEMPTS,
                'now' => $now,
            ]
        );

        if ($affected === 1) {
            // Reload through a cleared identity map so the caller sees the
            // SENDING + lease state this worker just wrote.
            $this->entityManager->clear();

            return null;
        }

        return CampaignSendResult::alreadyInProgress();
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
    public function sendExisting(EmailSend $send, bool $alreadyClaimedByWorker = false): CampaignSendResult
    {
        // The due-send worker claims rows atomically (FOR UPDATE SKIP LOCKED
        // + SENDING/lease transition) BEFORE calling this method; it owns
        // the live lease and proceeds directly. Other callers must win the
        // conditional claim themselves.
        if (!$alreadyClaimedByWorker && in_array($send->getStatus(), [EmailSend::STATUS_QUEUED, EmailSend::STATUS_SENDING, EmailSend::STATUS_FAILED], true)) {
            $sendId = $send->getId();
            $claim = $this->claimExistingTouch($send);
            if ($claim !== null) {
                return $claim;
            }
            $this->entityManager->clear();
            $send = $this->sendRepository->find($sendId);
            if ($send === null) {
                return CampaignSendResult::alreadyInProgress();
            }
        }

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
            $send->setNextAttemptAt(null);
            $this->entityManager->flush();

            return CampaignSendResult::skipped((string) $eligibility->reason);
        }

        if ($send->getStatus() === EmailSend::STATUS_SENT) {
            return CampaignSendResult::alreadySent();
        }

        // Drip branch conditions are evaluated AT DELIVERY TIME against the
        // current engagement state (see EmailDripCampaignService).
        if (
            $campaign->getType() === EmailCampaign::TYPE_DRIP
            && $this->dripCampaignService !== null
            && !$this->dripCampaignService->shouldSendTouch($campaign, $contact, (int) $send->getTouchNumber())
        ) {
            $send->setStatus(EmailSend::STATUS_CANCELLED);
            $send->setFailureReason('drip branch condition not met');
            $send->setSendLeaseExpiresAt(null);
            $send->setNextAttemptAt(null);
            $this->entityManager->flush();

            return CampaignSendResult::skipped('drip_branch_condition');
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
            // ── Canonical content resolution (configuration actually
            // reaches delivery): A/B variant (deterministic per contact,
            // persisted before transport) → touch template → campaign
            // defaults → branded fallback.
            $content = $this->resolveCampaignContent($campaign, $contact, $touchNumber, $send);

            $email = (new Email())
                ->from(new \Symfony\Component\Mime\Address(
                    $content['from_email'],
                    $content['from_name']
                ))
                ->to($contact->getEmail())
                ->subject($content['subject'])
                ->html($this->personalize($content['body_html'], $contact, $campaign, $touchNumber, $send));

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

            $sentMessage = $this->mailerTransport->send($email);

            // Capture the provider message ID for delivery/bounce
            // reconciliation (best effort — some transports assign none).
            try {
                $send->setProviderMessageId($sentMessage?->getMessageId());
            } catch (\Throwable) {
                // messageId unavailable on this transport — nothing to record
            }
        } catch (\Throwable $e) {
            // Log error and update send record to reflect failure. Failed
            // rows are RETRYABLE with exponential backoff (the due-send
            // worker picks them up again) until the attempts cap makes the
            // failure terminal.
            $send->setStatus(EmailSend::STATUS_FAILED);
            $send->setSendLeaseExpiresAt(null);
            $send->setFailureReason($e->getMessage());

            // retryCount IS the attempt counter: incremented exactly once
            // immediately before every transport attempt (claimExistingTouch
            // / sendExisting). After a failure, another attempt is scheduled
            // iff attempts-so-far < MAX — no off-by-one, no mixed counting.
            if ($send->getRetryCount() < self::MAX_SEND_ATTEMPTS) {
                $backoffSeconds = min(2 ** $send->getRetryCount() * 60, 3600);
                $send->setNextAttemptAt((new \DateTime())->modify('+' . $backoffSeconds . ' seconds'));
            } else {
                // Terminal: no further attempts scheduled.
                $send->setNextAttemptAt(null);
            }

            $this->entityManager->flush();

            throw $e;
        }
    }

    /**
     * Generate email content
     */
    /**
     * Resolve the content actually delivered for a campaign touch.
     *
     *   1. A/B variant configuration — assigned DETERMINISTICALLY per
     *      contact (stable hash), persisted on the EmailSend BEFORE the
     *      transport side effect, and its subject/body/from used verbatim.
     *   2. Touch template: touchTemplates[n]['template_id'] → EmailTemplate
     *      subjectLine/bodyHtml.
     *   3. Campaign defaults: subject/bodyHtml/fromName/fromEmail.
     *   4. Branded fallback (previous behavior) when nothing is configured.
     *
     * @return array{subject: string, body_html: string, from_email: string, from_name: string, variant: ?string}
     */
    private function resolveCampaignContent(EmailCampaign $campaign, Contact $contact, int $touchNumber, EmailSend $send): array
    {
        $defaults = [
            'subject' => $campaign->getSubject(),
            'body_html' => $campaign->getBodyHtml(),
            'from_email' => $campaign->getFromEmail(),
            'from_name' => $campaign->getFromName(),
        ];

        // 2. Touch template overrides/extends campaign defaults.
        $touchTemplates = $campaign->getTouchTemplates();
        $templateId = $touchTemplates[$touchNumber]['template_id'] ?? null;
        if ($templateId !== null) {
            $template = $this->entityManager->find(\App\Entity\EmailTemplate::class, (int) $templateId);
            if ($template !== null) {
                $defaults['subject'] = $template->getSubjectLine() ?? $defaults['subject'];
                $defaults['body_html'] = $template->getBodyHtml() ?? $defaults['body_html'];
            }
        }

        $variant = null;

        // 1. A/B variant — WEIGHTED deterministic bucketing over the
        //    CONFIGURED audience split (test_percentage shared across
        //    variants, remainder = control). crc32(contactId) % 10000 maps
        //    into cumulative percentage bands, so a 20% test with two
        //    variants yields 10/10/80 — not the equal thirds the old
        //    modulo-count assignment produced. Stable per contact, so
        //    redelivery retries keep the same variant.
        $variant = null;

        $abTest = $this->abTestService?->getCurrentAbTest($campaign);
        if ($abTest !== null && !empty($abTest['variants'])) {
            $testPercentage = (float) ($abTest['test_percentage'] ?? 20);
            $variantKeys = array_values(array_keys($abTest['variants']));
            $perVariant = $testPercentage / max(1, count($variantKeys));

            $bucket = crc32((string) $contact->getId()) % 10000; // 0..9999
            $cumulative = 0.0;
            foreach ($variantKeys as $key) {
                $cumulative += $perVariant * 100.0; // basis points
                if ($bucket < $cumulative) {
                    $variant = (string) $key;
                    break;
                }
            }
            $variant ??= 'control'; // above all bands

            if ($variant !== 'control') {
                try {
                    $config = $this->abTestService->getVariantConfiguration($campaign, $variant);
                    $defaults['subject'] = $config['subject'] ?? $defaults['subject'];
                    $defaults['body_html'] = $config['body_html'] ?? $defaults['body_html'];
                    $defaults['from_name'] = $config['from_name'] ?? $defaults['from_name'];
                } catch (\InvalidArgumentException) {
                    $variant = 'control';
                }
            }

            // NOTE: send counters are NOT incremented here — variant sends
            // are DERIVED from EmailSend rows (status sent/bounced), so a
            // failed attempt + retry cannot inflate the denominator. Only
            // the assignment itself is persisted, once, on the row.
        }

        $send->setVariant($variant);

        $subject = $defaults['subject'] ?? sprintf('[Touch %d/%d] %s', $touchNumber, $campaign->getTouchCount(), $campaign->getName());

        return [
            'subject' => $subject,
            'body_html' => $defaults['body_html'] ?? $this->generateEmailContent($campaign, $contact, $touchNumber, $send),
            'from_email' => $defaults['from_email'] ?? ($_ENV['MAILER_FROM_ADDRESS'] ?? 'noreply@starzelectronics.site'),
            'from_name' => $defaults['from_name'] ?? 'Starz Electronics',
            'variant' => $variant,
        ];
    }

    /**
     * Personalize the resolved body: contact/company merge tags, then the
     * tracking pixel and unsubscribe footer on every campaign email.
     */
    private function personalize(string $body, Contact $contact, EmailCampaign $campaign, int $touchNumber, EmailSend $send): string
    {
        $companyName = $contact->getCompany() ? $contact->getCompany()->getName() : '';
        $firstName = $contact->getFirstName() ?? 'there';

        $personalized = str_replace(
            ['{{first_name}}', '{{last_name}}', '{{email}}', '{{company}}', '{{campaign_name}}', '{{touch_number}}'],
            [$firstName, $contact->getLastName() ?? '', $contact->getEmail() ?? '', $companyName, $campaign->getName(), (string) $touchNumber],
            $body
        );

        return $personalized . $this->renderTrackingFooter($contact, $campaign, $send);
    }

    /**
     * Tracking pixel + one-click unsubscribe footer appended to EVERY
     * campaign email body exactly once, regardless of where the body came
     * from (variant, template, campaign default or fallback).
     */
    private function renderTrackingFooter(Contact $contact, EmailCampaign $campaign, EmailSend $send): string
    {
        $trackingPixelSig = $send->getId() ? $this->trackingSigner->signOpen($send->getId()) : null;
        $trackingPixelUrl = $this->urlGenerator->generate(
            'app_email_send_track_open',
            ['id' => $send->getId(), 'sig' => $trackingPixelSig],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $unsubscribeLink = $this->consentService
            ? $this->consentService->generateUnsubscribeLink($contact, $campaign->getId())
            : $this->buildFallbackUnsubscribeLink($contact, $campaign->getId());

        return sprintf(
            '<!-- campaign tracking --><img src="%s" width="1" height="1" style="display:none;" alt="">'
            . '<div style="text-align:center;padding:12px;color:#6b7280;font-size:12px;">'
            . '<a href="%s" style="color:#2563eb;">Unsubscribe</a></div>',
            htmlspecialchars($trackingPixelUrl, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) $unsubscribeLink, ENT_QUOTES, 'UTF-8')
        );
    }

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
        <!-- Footer/tracking/unsubscribe are appended centrally by
             personalize() so they appear exactly once on every body. -->
    </div>
</body>
</html>
        ',
            htmlspecialchars($contact->getFirstName() ?? '', ENT_QUOTES, 'UTF-8'),
            $touchNumber,
            $campaign->getTouchCount(),
            htmlspecialchars((string) $campaign->getName(), ENT_QUOTES, 'UTF-8'),
            $companyName ? '<p><strong>Company:</strong> ' . htmlspecialchars($companyName) . '</p>' : '',
            htmlspecialchars($trackingLinkUrl, ENT_QUOTES, 'UTF-8')
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
        // Derive from the configured sequence length (drip campaigns can
        // exceed the old hard-coded five touches).
        $touchCount = max(1, (int) $campaign->getTouchCount());
        $touches = array_fill(1, $touchCount, false);
        
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
