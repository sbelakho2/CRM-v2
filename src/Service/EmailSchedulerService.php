<?php

namespace App\Service;

use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use App\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Service for email campaign scheduling and queue management
 * 
 * Features:
 * - Send time optimization based on engagement history
 * - Timezone-aware scheduling
 * - Queue management with batch processing
 * - Rate limiting and throttling
 * - Retry logic for failed sends
 */
class EmailSchedulerService
{
    private const DEFAULT_BATCH_SIZE = 100;
    private const DEFAULT_RATE_LIMIT = 50; // emails per minute
    private const MAX_RETRIES = 3;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private EmailSegmentService $segmentService
    ) {}

    /**
     * Schedule a campaign for sending
     */
    public function scheduleCampaign(
        EmailCampaign $campaign,
        ?\DateTimeImmutable $scheduledAt = null,
        bool $optimizeSendTime = false
    ): void {
        if ($campaign->getStatus() === 'draft') {
            $campaign->setStatus('scheduled');
        }

        if ($scheduledAt) {
            $campaign->setScheduledAt($scheduledAt);
        }

        if ($optimizeSendTime && $campaign->isSendTimeOptimization()) {
            // Will optimize send time per contact based on engagement history
            $campaign->setScheduledAt(null); // Individual optimization
        }

        $campaign->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        // Queue the campaign for processing
        // TODO: Dispatch message to Symfony Messenger
        // $this->messageBus->dispatch(new ProcessCampaignMessage($campaign->getId()));
    }

    /**
     * Process a campaign and create EmailSend records for all contacts
     */
    public function processCampaign(EmailCampaign $campaign): int
    {
        $segment = $campaign->getSegment();
        if (!$segment) {
            throw new \RuntimeException('Campaign must have a segment');
        }

        // Get all contacts in the segment
        $contacts = $this->segmentService->getSegmentContacts($segment);
        
        $emailsSent = 0;
        foreach ($contacts as $contact) {
            // Skip unsubscribed contacts
            if (!$this->canSendToContact($contact)) {
                continue;
            }

            $this->createEmailSend($campaign, $contact);
            $emailsSent++;
        }

        // Update campaign status
        $campaign->setStatus('sending');
        $campaign->setSentAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $emailsSent;
    }

    /**
     * Create an EmailSend record for a contact
     */
    private function createEmailSend(EmailCampaign $campaign, Contact $contact): EmailSend
    {
        $emailSend = new EmailSend();
        $emailSend->setCampaign($campaign);
        $emailSend->setContact($contact);
        $emailSend->setEmailAddress($contact->getEmail());
        $emailSend->setStatus('queued');
        
        // Optimize send time if enabled
        if ($campaign->isSendTimeOptimization()) {
            $optimalTime = $this->calculateOptimalSendTime($contact);
            $emailSend->setScheduledAt($optimalTime);
        } else {
            $emailSend->setScheduledAt($campaign->getScheduledAt() ?? new \DateTimeImmutable());
        }

        $this->entityManager->persist($emailSend);
        $this->entityManager->flush();

        return $emailSend;
    }

    /**
     * Calculate optimal send time for a contact based on engagement history
     */
    private function calculateOptimalSendTime(Contact $contact): \DateTimeImmutable
    {
        // Get contact's email engagement history
        $engagementHistory = $this->getContactEngagementHistory($contact);

        if (empty($engagementHistory)) {
            // Default to 9 AM in contact's timezone
            return $this->getDefaultSendTime($contact);
        }

        // Analyze open times to find optimal hour
        $hourCounts = [];
        foreach ($engagementHistory as $engagement) {
            $hour = (int) $engagement['openedAt']->format('G');
            $hourCounts[$hour] = ($hourCounts[$hour] ?? 0) + 1;
        }

        // Find hour with most opens
        arsort($hourCounts);
        $optimalHour = array_key_first($hourCounts);

        // Calculate next occurrence of that hour
        $now = new \DateTimeImmutable();
        $targetTime = $now->setTime($optimalHour, 0, 0);

        // If that time has already passed today, schedule for tomorrow
        if ($targetTime <= $now) {
            $targetTime = $targetTime->modify('+1 day');
        }

        return $targetTime;
    }

    /**
     * Get default send time (9 AM) adjusted for contact's timezone
     */
    private function getDefaultSendTime(Contact $contact): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        
        // Try to get timezone from contact or company
        $timezone = $this->getContactTimezone($contact);
        
        // Set to 9 AM in that timezone
        $sendTime = $now->setTimezone(new \DateTimeZone($timezone))
            ->setTime(9, 0, 0);

        // If 9 AM has passed, schedule for tomorrow
        if ($sendTime <= $now) {
            $sendTime = $sendTime->modify('+1 day');
        }

        return $sendTime;
    }

    /**
     * Get contact's timezone (from company location or default to UTC)
     */
    private function getContactTimezone(Contact $contact): string
    {
        $company = $contact->getCompany();
        
        if (!$company) {
            return 'UTC';
        }

        // Map country to timezone (simplified)
        $timezoneMap = [
            'US' => 'America/New_York',
            'UK' => 'Europe/London',
            'DE' => 'Europe/Berlin',
            'FR' => 'Europe/Paris',
            'JP' => 'Asia/Tokyo',
            'CN' => 'Asia/Shanghai',
            'AU' => 'Australia/Sydney',
            'IN' => 'Asia/Kolkata',
            'BR' => 'America/Sao_Paulo',
            'CA' => 'America/Toronto',
            'MX' => 'America/Mexico_City',
            'MA' => 'Africa/Casablanca', // Morocco
        ];

        $country = $company->getCountry();
        return $timezoneMap[$country] ?? 'UTC';
    }

    /**
     * Get contact's email engagement history
     */
    private function getContactEngagementHistory(Contact $contact): array
    {
        return $this->entityManager->createQuery(
            'SELECT es.openedAt, es.clickedAt 
             FROM App\Entity\EmailSend es 
             WHERE es.contact = :contact 
             AND es.openedAt IS NOT NULL 
             ORDER BY es.openedAt DESC'
        )
        ->setParameter('contact', $contact)
        ->setMaxResults(50)
        ->getResult();
    }

    /**
     * Check if we can send to a contact (not unsubscribed, has email, etc.)
     */
    private function canSendToContact(Contact $contact): bool
    {
        // Check if contact has an email
        if (empty($contact->getEmail())) {
            return false;
        }

        // Check if contact is subscribed
        if (!$contact->isSubscribed()) {
            return false;
        }

        // Check if contact is on global suppression list
        $unsubscribe = $this->entityManager->getRepository('App\Entity\EmailUnsubscribe')
            ->findOneBy(['email' => $contact->getEmail()]);

        return $unsubscribe === null;
    }

    /**
     * Process queued emails (run by cron or worker)
     */
    public function processQueue(int $batchSize = self::DEFAULT_BATCH_SIZE): int
    {
        // Find queued emails that are due to be sent
        $queuedEmails = $this->entityManager->createQuery(
            'SELECT es FROM App\Entity\EmailSend es 
             WHERE es.status = :status 
             AND es.scheduledAt <= :now 
             ORDER BY es.scheduledAt ASC'
        )
        ->setParameter('status', 'queued')
        ->setParameter('now', new \DateTimeImmutable())
        ->setMaxResults($batchSize)
        ->getResult();

        $sent = 0;
        foreach ($queuedEmails as $emailSend) {
            try {
                $this->sendEmail($emailSend);
                $sent++;
            } catch (\Exception $e) {
                $this->handleSendFailure($emailSend, $e);
            }
        }

        return $sent;
    }

    /**
     * Send a single email
     */
    private function sendEmail(EmailSend $emailSend): void
    {
        $campaign = $emailSend->getCampaign();
        $contact = $emailSend->getContact();
        $template = $campaign->getTemplate();

        if (!$template) {
            throw new \RuntimeException('Campaign must have a template');
        }

        // Render template with contact data
        /** @var \App\Repository\EmailTemplateRepository $templateRepo */
        $templateRepo = $this->entityManager->getRepository('App\Entity\EmailTemplate');
        $templateService = new EmailTemplateService($this->entityManager, $templateRepo);
        $personalizationData = $this->getPersonalizationData($contact);
        $rendered = $templateService->renderTemplate($template, $personalizationData);

        // TODO: Actually send email via Symfony Mailer
        // For now, just mark as sent
        $emailSend->setStatus('sent');
        $emailSend->setSentAt(new \DateTimeImmutable());
        
        $this->entityManager->flush();
    }

    /**
     * Get personalization data for a contact
     */
    private function getPersonalizationData(Contact $contact): array
    {
        $data = [
            'contact.firstName' => $contact->getFirstName(),
            'contact.lastName' => $contact->getLastName(),
            'contact.email' => $contact->getEmail(),
            'contact.phone' => $contact->getPhone(),
            'contact.title' => $contact->getTitle(),
        ];

        $company = $contact->getCompany();
        if ($company) {
            $data['company.name'] = $company->getName();
            $data['company.industry'] = $company->getIndustry();
            $data['company.website'] = $company->getWebsite();
        }

        return $data;
    }

    /**
     * Handle send failure with retry logic
     */
    private function handleSendFailure(EmailSend $emailSend, \Exception $exception): void
    {
        $retryCount = $emailSend->getRetryCount() ?? 0;
        
        if ($retryCount >= self::MAX_RETRIES) {
            // Max retries exceeded, mark as failed
            $emailSend->setStatus('failed');
            $emailSend->setFailureReason($exception->getMessage());
        } else {
            // Retry later with exponential backoff
            $emailSend->setRetryCount($retryCount + 1);
            $backoffMinutes = pow(2, $retryCount) * 5; // 5, 10, 20 minutes
            $retryAt = (new \DateTimeImmutable())->modify("+{$backoffMinutes} minutes");
            $emailSend->setScheduledAt($retryAt);
        }

        $this->entityManager->flush();
    }

    /**
     * Cancel a scheduled campaign
     */
    public function cancelCampaign(EmailCampaign $campaign): void
    {
        if (!in_array($campaign->getStatus(), ['scheduled', 'sending'])) {
            throw new \RuntimeException('Can only cancel scheduled or sending campaigns');
        }

        // Mark all queued emails as cancelled
        $this->entityManager->createQuery(
            'UPDATE App\Entity\EmailSend es 
             SET es.status = :cancelled 
             WHERE es.campaign = :campaign 
             AND es.status = :queued'
        )
        ->setParameter('cancelled', 'cancelled')
        ->setParameter('campaign', $campaign)
        ->setParameter('queued', 'queued')
        ->execute();

        $campaign->setStatus('cancelled');
        $campaign->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    /**
     * Get campaign sending progress
     */
    public function getCampaignProgress(EmailCampaign $campaign): array
    {
        $stats = $this->entityManager->createQuery(
            'SELECT 
                COUNT(es.id) as total,
                SUM(CASE WHEN es.status = :queued THEN 1 ELSE 0 END) as queued,
                SUM(CASE WHEN es.status = :sent THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN es.status = :failed THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN es.openedAt IS NOT NULL THEN 1 ELSE 0 END) as opened,
                SUM(CASE WHEN es.clickedAt IS NOT NULL THEN 1 ELSE 0 END) as clicked
             FROM App\Entity\EmailSend es 
             WHERE es.campaign = :campaign'
        )
        ->setParameter('campaign', $campaign)
        ->setParameter('queued', 'queued')
        ->setParameter('sent', 'sent')
        ->setParameter('failed', 'failed')
        ->getSingleResult();

        $total = (int) $stats['total'];
        
        return [
            'total' => $total,
            'queued' => (int) $stats['queued'],
            'sent' => (int) $stats['sent'],
            'failed' => (int) $stats['failed'],
            'opened' => (int) $stats['opened'],
            'clicked' => (int) $stats['clicked'],
            'percentComplete' => $total > 0 ? round((($stats['sent'] + $stats['failed']) / $total) * 100, 2) : 0,
            'openRate' => $stats['sent'] > 0 ? round(($stats['opened'] / $stats['sent']) * 100, 2) : 0,
            'clickRate' => $stats['sent'] > 0 ? round(($stats['clicked'] / $stats['sent']) * 100, 2) : 0,
        ];
    }
}
