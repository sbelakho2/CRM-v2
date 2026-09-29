<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contact;
use App\Entity\EmailUnsubscribe;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Manages email consent and GDPR compliance
 * Features: double opt-in, consent tracking, audit logging, unsubscribe management
 */
class EmailConsentService
{
    private const DEFAULT_BASE_URL = 'https://crm.starz-morocco.com';

    private EntityManagerInterface $em;
    private LoggerInterface $logger;
    private string $baseUrl;

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function __construct(
        EntityManagerInterface $em,
        LoggerInterface $logger,
        string $baseUrl = self::DEFAULT_BASE_URL
    ) {
        $this->em = $em;
        $this->logger = $logger;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Request double opt-in from a contact
     * Generates HMAC-signed confirmation token and sends verification email
     * 
     * @param Contact $contact The contact to verify
     * @param string $source Source of the opt-in request (form, import, manual)
     * @return array Token and confirmation URL
     */
    public function requestDoubleOptIn(Contact $contact, string $source = 'form'): array
    {
        // Generate HMAC-signed token: base64(email|timestamp|hmac)
        $timestamp = time();
        $payload = $contact->getEmail() . '|' . $timestamp;
        $hmac = hash_hmac('sha256', $payload, $this->getSigningSecret());
        $token = base64_encode($payload . '|' . $hmac);
        $expiresAt = new \DateTime('+7 days');

        $confirmUrl = sprintf(
            '%s/email/confirm-subscription/%s',
            $this->baseUrl,
            urlencode($token)
        );

        $this->logger->info("Double opt-in requested for contact {$contact->getEmail()}", [
            'source' => $source
        ]);

        return [
            'token' => $token,
            'confirm_url' => $confirmUrl,
            'expires_at' => $expiresAt
        ];
    }

    /**
     * Confirm opt-in using verification token
     * 
     * @param string $token Confirmation token
     * @return array Success status and contact info
     */
    public function confirmOptIn(string $token): array
    {
        // Token format: base64(email|timestamp|hmac)
        $decoded = base64_decode($token);
        $parts = explode('|', $decoded);

        if (count($parts) !== 3) {
            return [
                'success' => false,
                'error' => 'invalid_token',
                'message' => 'Invalid confirmation link.'
            ];
        }

        [$email, $timestamp, $hmac] = $parts;

        // Verify HMAC signature
        $expectedHmac = hash_hmac('sha256', $email . '|' . $timestamp, $this->getSigningSecret());
        if (!hash_equals($expectedHmac, $hmac)) {
            return [
                'success' => false,
                'error' => 'invalid_token',
                'message' => 'Invalid confirmation link.'
            ];
        }

        // Check if token is expired (7 days)
        if ((time() - (int)$timestamp) > (7 * 24 * 60 * 60)) {
            return [
                'success' => false,
                'error' => 'token_expired',
                'message' => 'Confirmation link has expired. Please request a new one.'
            ];
        }

        $contact = $this->em->getRepository(Contact::class)
            ->findOneBy(['email' => $email]);

        if (!$contact) {
            return [
                'success' => false,
                'error' => 'contact_not_found',
                'message' => 'Contact not found.'
            ];
        }

        // Confirm consent — remove from unsubscribe list if present
        $unsubscribe = $this->em->getRepository(EmailUnsubscribe::class)
            ->findOneBy(['email' => $contact->getEmail()]);
        
        if ($unsubscribe) {
            $this->em->remove($unsubscribe);
        }

        $this->em->flush();

        $this->logger->info("Opt-in confirmed for contact {$contact->getEmail()}", [
            'token' => $token
        ]);

        return [
            'success' => true,
            'contact' => $contact,
            'message' => 'Email subscription confirmed successfully!'
        ];
    }

    /**
     * Check if contact has given explicit consent
     * 
     * @param Contact $contact
     * @return bool
     */
    public function hasConsent(Contact $contact): bool
    {
        // Check if NOT in unsubscribe list
        $unsubscribe = $this->em->getRepository(EmailUnsubscribe::class)
            ->findOneBy(['email' => $contact->getEmail()]);

        return $unsubscribe === null;
    }

    /**
     * Unsubscribe a contact from all email communications
     * 
     * The reason is validated against EmailUnsubscribe::VALID_REASONS; any
     * free-form value is normalized to REASON_MANUAL so the column only ever
     * stores one of the entity's REASON_* constants.
     * 
     * @param Contact $contact
     * @param string $reason Optional reason for unsubscribing
     * @return EmailUnsubscribe
     */
    public function unsubscribe(Contact $contact, ?string $reason = null): EmailUnsubscribe
    {
        // Check if already unsubscribed
        $existing = $this->em->getRepository(EmailUnsubscribe::class)
            ->findOneBy(['email' => $contact->getEmail()]);

        if ($existing) {
            return $existing;
        }

        // Cap the reason to the entity's REASON_* constants.
        $normalizedReason = $reason ?? EmailUnsubscribe::REASON_MANUAL;
        if (!in_array($normalizedReason, EmailUnsubscribe::VALID_REASONS, true)) {
            $this->logger->warning("Unsubscribe reason not in allowed set — normalized to MANUAL", [
                'email' => $contact->getEmail(),
                'provided_reason' => $normalizedReason,
                'allowed_reasons' => EmailUnsubscribe::VALID_REASONS,
            ]);
            $normalizedReason = EmailUnsubscribe::REASON_MANUAL;
        }

        $unsubscribe = new EmailUnsubscribe();
        $unsubscribe->setEmail($contact->getEmail());
        $unsubscribe->setContact($contact);
        $unsubscribe->setReason($normalizedReason);
        $unsubscribe->setUnsubscribedAt(new \DateTime());

        $this->em->persist($unsubscribe);
        $this->em->flush();

        $this->logger->info("Contact {$contact->getEmail()} unsubscribed", [
            'reason' => $normalizedReason
        ]);

        return $unsubscribe;
    }

    /**
     * Resubscribe a contact (remove from unsubscribe list)
     * 
     * @param Contact $contact
     * @return bool
     */
    public function resubscribe(Contact $contact): bool
    {
        $unsubscribe = $this->em->getRepository(EmailUnsubscribe::class)
            ->findOneBy(['email' => $contact->getEmail()]);

        if (!$unsubscribe) {
            return false; // Not unsubscribed
        }

        $this->em->remove($unsubscribe);

        $this->em->flush();

        $this->logger->info("Contact {$contact->getEmail()} resubscribed");

        return true;
    }

    /**
     * Generate unsubscribe link for a contact
     * 
     * @param Contact $contact
     * @param int|null $campaignId Optional campaign ID
     * @return string
     */
    public function generateUnsubscribeLink(Contact $contact, ?int $campaignId = null): string
    {
        // Generate HMAC-signed token for one-click unsubscribe
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
            $this->baseUrl,
            http_build_query($params)
        );
    }

    /**
     * Process unsubscribe from token (one-click unsubscribe)
     * 
     * @param string $token Unsubscribe token
     * @return array
     */
    public function processUnsubscribeToken(string $token): array
    {
        $decoded = base64_decode($token);
        $parts = explode('|', $decoded);

        if (count($parts) !== 3) {
            return [
                'success' => false,
                'error' => 'Invalid unsubscribe token'
            ];
        }

        [$email, $timestamp, $hmac] = $parts;

        // Verify HMAC signature
        $expectedHmac = hash_hmac('sha256', $email . '|' . $timestamp, $this->getSigningSecret());
        if (!hash_equals($expectedHmac, $hmac)) {
            return [
                'success' => false,
                'error' => 'Invalid unsubscribe token'
            ];
        }

        // Token expires after 30 days
        if ((time() - (int)$timestamp) > (30 * 24 * 60 * 60)) {
            return [
                'success' => false,
                'error' => 'Unsubscribe link has expired'
            ];
        }

        $contact = $this->em->getRepository(Contact::class)
            ->findOneBy(['email' => $email]);

        if (!$contact) {
            return [
                'success' => false,
                'error' => 'Contact not found'
            ];
        }

        $this->unsubscribe($contact, 'One-click unsubscribe');

        return [
            'success' => true,
            'email' => $email,
            'message' => 'You have been unsubscribed successfully'
        ];
    }

    /**
     * Get consent audit trail for a contact
     * 
     * @param Contact $contact
     * @return array
     */
    public function getConsentAuditTrail(Contact $contact): array
    {
        $trail = [];

        // Check unsubscribe record
        $unsubscribe = $this->em->getRepository(EmailUnsubscribe::class)
            ->findOneBy(['email' => $contact->getEmail()]);

        if ($unsubscribe) {
            $trail[] = [
                'action' => 'unsubscribed',
                'timestamp' => $unsubscribe->getUnsubscribedAt()->format('Y-m-d H:i:s'),
                'reason' => $unsubscribe->getReason()
            ];
        }

        // Current status
        $trail[] = [
            'action' => 'current_status',
            'has_consent' => $this->hasConsent($contact)
        ];

        return $trail;
    }

    /**
     * Export contact data (GDPR right to data portability)
     * 
     * @param Contact $contact
     * @return array
     */
    public function exportContactData(Contact $contact): array
    {
        $company = $contact->getCompany();

        return [
            'personal_information' => [
                'email' => $contact->getEmail(),
                'first_name' => $contact->getFirstName(),
                'last_name' => $contact->getLastName(),
                'title' => $contact->getJobTitle(),
                'phone' => $contact->getPhone(),
                'company' => $company?->getName()
            ],
            'consent_status' => [
                'has_consent' => $this->hasConsent($contact),
                'audit_trail' => $this->getConsentAuditTrail($contact)
            ],
            'email_engagement' => $this->getEmailEngagementStats($contact),
            'export_date' => (new \DateTime())->format('Y-m-d H:i:s'),
            'export_format' => 'JSON'
        ];
    }

    /**
     * Delete contact data (GDPR right to be forgotten)
     * 
     * @param Contact $contact
     * @param bool $hardDelete True to permanently delete, false to anonymize
     * @return bool
     */
    public function deleteContactData(Contact $contact, bool $hardDelete = false): bool
    {
        if ($hardDelete) {
            // Permanent deletion
            $this->em->remove($contact);
            $this->logger->warning("Contact {$contact->getEmail()} permanently deleted (GDPR right to be forgotten)");
        } else {
            // Anonymize data
            $contact->setEmail('deleted_' . Uuid::v4()->toRfc4122() . '@anonymized.local');
            $contact->setFirstName('Deleted');
            $contact->setLastName('User');
            $contact->setJobTitle(null);
            $contact->setPhone(null);
            
            $this->logger->info("Contact anonymized (GDPR right to be forgotten)");
        }

        $this->em->flush();
        return true;
    }

    /**
     * Get email engagement statistics for a contact
     * 
     * @param Contact $contact
     * @return array
     */
    private function getEmailEngagementStats(Contact $contact): array
    {
        // Query EmailSend entities for this contact
        $sends = $this->em->getRepository(\App\Entity\EmailSend::class)
            ->findBy(['contact' => $contact]);
        
        $stats = [
            'total_emails_received' => count($sends),
            'emails_opened' => 0,
            'emails_clicked' => 0,
            'emails_replied' => 0
        ];

        foreach ($sends as $send) {
            if ($send->isOpened()) {
                $stats['emails_opened']++;
            }
            if ($send->isClicked()) {
                $stats['emails_clicked']++;
            }
            if ($send->isReplied()) {
                $stats['emails_replied']++;
            }
        }

        return $stats;
    }

    /**
     * Get the signing secret for token HMAC.
     * Fails closed: without APP_SECRET no token can be signed or verified.
     */
    private function getSigningSecret(): string
    {
        $secret = $_ENV['APP_SECRET'] ?? $_SERVER['APP_SECRET'] ?? getenv('APP_SECRET');

        if (!$secret) {
            throw new \RuntimeException('APP_SECRET is not configured — consent tokens cannot be signed or verified.');
        }

        return (string) $secret;
    }
}
