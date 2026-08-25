<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailCampaign;
use App\Entity\EmailTemplate;
use App\Entity\EmailSegment;
use App\Entity\Contact;
use App\Entity\EmailSend;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for managing drip campaigns (automated multi-touch sequences)
 *
 * Drip scheduling configuration (delay per touch, conditions) is stored in
 * EmailCampaign.touchTemplates so it never collides with real A/B tests
 * stored in EmailCampaign.abTestVariants.
 */
class EmailDripCampaignService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailSchedulerService $schedulerService,
        private EmailSegmentService $segmentService,
        private EmailConsentService $consentService
    ) {}

    /**
     * Create a drip campaign sequence
     * 
     * @param string $name Campaign name
     * @param string $description Campaign description
     * @param array $sequence Array of touch configurations
     * @param EmailSegment|null $segment Target audience
     * @param array $triggerConditions When to start the sequence
     * @return EmailCampaign Drip campaign
     */
    public function createDripCampaign(
        string $name,
        string $description,
        array $sequence,
        ?EmailSegment $segment = null,
        array $triggerConditions = []
    ): EmailCampaign {
        // Validate sequence
        if (count($sequence) < 2) {
            throw new \InvalidArgumentException("Drip campaign must have at least 2 touches");
        }

        if (count($sequence) > 10) {
            throw new \InvalidArgumentException("Drip campaign cannot exceed 10 touches");
        }

        // Create campaign
        $campaign = new EmailCampaign();
        $campaign->setName($name);
        $campaign->setDescription($description);
        $campaign->setTouchCount(count($sequence));
        $campaign->setLanguage('EN');
        $campaign->setActive(true);
        $campaign->setStatus('draft');
        $campaign->setType(EmailCampaign::TYPE_DRIP);
        $campaign->setCreatedAt(new \DateTimeImmutable());
        $campaign->setUpdatedAt(new \DateTimeImmutable());

        if ($segment) {
            $campaign->setSegment($segment);
        }

        // Store trigger conditions in the campaign's own JSON field (does not
        // collide with A/B test storage).
        $campaign->setTriggerConditions($triggerConditions);

        // Build touch templates array — this is the drip scheduling config:
        // delay value/unit and branching conditions per touch.
        $touchTemplates = [];
        foreach ($sequence as $index => $touch) {
            $touchNum = $index + 1;
            $touchTemplates[$touchNum] = [
                'template_id' => $touch['template_id'] ?? null,
                'delay_value' => $touch['delay_value'] ?? 1,
                'delay_unit' => $touch['delay_unit'] ?? 'days', // minutes, hours, days, weeks
                'conditions' => $touch['conditions'] ?? [], // Conditional branching
            ];
        }

        $campaign->setTouchTemplates($touchTemplates);

        $this->entityManager->persist($campaign);
        $this->entityManager->flush();

        return $campaign;
    }

    /**
     * Add a contact to a drip campaign
     * 
     * @param EmailCampaign $campaign Drip campaign
     * @param Contact $contact Contact to add
     * @param int $startingTouch Which touch to start from (default 1)
     * @return array Enrollment information
     */
    public function enrollContact(EmailCampaign $campaign, Contact $contact, int $startingTouch = 1): array
    {
        if (!$this->isDripCampaign($campaign)) {
            throw new \InvalidArgumentException("Campaign is not a drip campaign");
        }

        // Suppression-list check at enroll time: never enroll someone who has
        // unsubscribed (implicit consent model — absence of unsubscribe = consent).
        if (!$this->consentService->hasConsent($contact)) {
            return [
                'success' => false,
                'message' => 'Contact is on the global suppression list',
            ];
        }

        // Re-enrollment rule: only block when there is an active (non-cancelled,
        // non-completed) queued/sending send. Completed sequences may be re-enrolled.
        if ($this->hasActiveEnrollment($campaign, $contact)) {
            return [
                'success' => false,
                'message' => 'Contact is already enrolled in this drip campaign',
            ];
        }

        // Schedule first touch
        $firstTouch = $campaign->getTouchTemplates()[$startingTouch] ?? null;
        if (!$firstTouch) {
            throw new \InvalidArgumentException("Invalid starting touch: $startingTouch");
        }

        // Calculate send time for first touch
        $sendAt = $this->calculateNextSendTime(new \DateTimeImmutable(), $firstTouch);

        // Create email send record
        $emailSend = new EmailSend();
        $emailSend->setCampaign($campaign);
        $emailSend->setContact($contact);
        $emailSend->setTouchNumber($startingTouch);
        $emailSend->setScheduledAt($sendAt);
        $emailSend->setStatus('queued');

        $this->entityManager->persist($emailSend);
        $this->entityManager->flush();

        return [
            'success' => true,
            'message' => "Contact enrolled in drip campaign",
            'next_send_at' => $sendAt->format('Y-m-d H:i:s'),
            'touch_number' => $startingTouch,
        ];
    }

    /**
     * Process completed touches and schedule next touch
     * 
     * @param EmailSend $completedSend Completed email send
     */
    public function processCompletedTouch(EmailSend $completedSend): void
    {
        $campaign = $completedSend->getCampaign();
        
        if (!$this->isDripCampaign($campaign)) {
            return;
        }

        $contact = $completedSend->getContact();
        $currentTouch = $completedSend->getTouchNumber();
        $nextTouch = $currentTouch + 1;

        // Check if there are more touches
        $touchTemplates = $campaign->getTouchTemplates();
        if (!isset($touchTemplates[$nextTouch])) {
            // Campaign complete for this contact
            return;
        }

        // Check conditions for next touch
        $nextTouchConfig = $touchTemplates[$nextTouch];
        if (!$this->evaluateConditions($completedSend, $nextTouchConfig['conditions'] ?? [])) {
            // Conditions not met, skip or branch
            return;
        }

        // Calculate when to send next touch
        $sendAt = $this->calculateNextSendTime(
            $completedSend->getSentAt() ?? new \DateTimeImmutable(),
            $nextTouchConfig
        );

        // Create next email send
        $nextEmailSend = new EmailSend();
        $nextEmailSend->setCampaign($campaign);
        $nextEmailSend->setContact($contact);
        $nextEmailSend->setTouchNumber($nextTouch);
        $nextEmailSend->setScheduledAt($sendAt);
        $nextEmailSend->setStatus('queued');

        $this->entityManager->persist($nextEmailSend);
        $this->entityManager->flush();
    }

    /**
     * Remove a contact from a drip campaign
     * 
     * @param EmailCampaign $campaign Campaign
     * @param Contact $contact Contact
     * @return bool Success status
     */
    public function unenrollContact(EmailCampaign $campaign, Contact $contact): bool
    {
        // Find all scheduled sends for this contact in this campaign
        $qb = $this->entityManager->createQueryBuilder();
        $scheduledSends = $qb->select('es')
            ->from(EmailSend::class, 'es')
            ->where('es.campaign = :campaign')
            ->andWhere('es.contact = :contact')
            ->andWhere('es.status = :status')
            ->setParameter('campaign', $campaign)
            ->setParameter('contact', $contact)
            ->setParameter('status', 'queued')
            ->getQuery()
            ->getResult();

        // Cancel all scheduled sends
        foreach ($scheduledSends as $send) {
            $send->setStatus('cancelled');
        }

        $this->entityManager->flush();

        return count($scheduledSends) > 0;
    }

    /**
     * Get drip campaign progress for a contact
     * 
     * @param EmailCampaign $campaign Campaign
     * @param Contact $contact Contact
     * @return array Progress information
     */
    public function getContactProgress(EmailCampaign $campaign, Contact $contact): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $sends = $qb->select('es')
            ->from(EmailSend::class, 'es')
            ->where('es.campaign = :campaign')
            ->andWhere('es.contact = :contact')
            ->orderBy('es.touchNumber', 'ASC')
            ->setParameter('campaign', $campaign)
            ->setParameter('contact', $contact)
            ->getQuery()
            ->getResult();

        $progress = [
            'enrolled' => count($sends) > 0,
            'current_touch' => 0,
            'total_touches' => $campaign->getTouchCount(),
            'completion_percentage' => 0,
            'touches' => [],
        ];

        foreach ($sends as $send) {
            $touchNum = $send->getTouchNumber();
            $progress['touches'][$touchNum] = [
                'touch_number' => $touchNum,
                'status' => $send->getStatus(),
                'scheduled_at' => $send->getScheduledAt()?->format('Y-m-d H:i:s'),
                'sent_at' => $send->getSentAt()?->format('Y-m-d H:i:s'),
                'opened' => $send->isOpened(),
                'clicked' => $send->isClicked(),
                'replied' => $send->isReplied(),
            ];

            if ($send->getStatus() === 'sent') {
                $progress['current_touch'] = max($progress['current_touch'], $touchNum);
            }
        }

        $progress['completion_percentage'] = $campaign->getTouchCount() > 0
            ? round(($progress['current_touch'] / $campaign->getTouchCount()) * 100, 2)
            : 0;

        return $progress;
    }

    /**
     * Get all contacts enrolled in a drip campaign
     * 
     * @param EmailCampaign $campaign Campaign
     * @return array Enrolled contacts with progress
     */
    public function getEnrolledContacts(EmailCampaign $campaign): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $sends = $qb->select('es', 'c')
            ->from(EmailSend::class, 'es')
            ->join('es.contact', 'c')
            ->where('es.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->orderBy('c.lastName', 'ASC')
            ->addOrderBy('c.firstName', 'ASC')
            ->getQuery()
            ->getResult();

        // Group by contact
        $contactsMap = [];
        foreach ($sends as $send) {
            $contactId = $send->getContact()->getId();
            if (!isset($contactsMap[$contactId])) {
                $contactsMap[$contactId] = [
                    'contact' => $send->getContact(),
                    'progress' => $this->getContactProgress($campaign, $send->getContact()),
                ];
            }
        }

        return array_values($contactsMap);
    }

    /**
     * Pause a drip campaign (stops scheduling new touches)
     * 
     * @param EmailCampaign $campaign Campaign
     */
    public function pauseDripCampaign(EmailCampaign $campaign): void
    {
        $campaign->setStatus('paused');
        $campaign->setActive(false);
        $this->entityManager->flush();
    }

    /**
     * Resume a paused drip campaign
     * 
     * @param EmailCampaign $campaign Campaign
     */
    public function resumeDripCampaign(EmailCampaign $campaign): void
    {
        $campaign->setStatus('sending');
        $campaign->setActive(true);
        $this->entityManager->flush();
    }

    /**
     * Get drip campaign analytics
     * 
     * @param EmailCampaign $campaign Campaign
     * @return array Analytics data
     */
    public function getDripAnalytics(EmailCampaign $campaign): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        
        // Get all sends grouped by touch number
        $touchStats = $qb->select(
                'es.touchNumber',
                'COUNT(es.id) as total',
                'SUM(CASE WHEN es.status = :sent THEN 1 ELSE 0 END) as sent',
                'SUM(CASE WHEN es.opened = 1 THEN 1 ELSE 0 END) as opened',
                'SUM(CASE WHEN es.clicked = 1 THEN 1 ELSE 0 END) as clicked',
                'SUM(CASE WHEN es.replied = 1 THEN 1 ELSE 0 END) as replied'
            )
            ->from(EmailSend::class, 'es')
            ->where('es.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->setParameter('sent', 'sent')
            ->groupBy('es.touchNumber')
            ->orderBy('es.touchNumber', 'ASC')
            ->getQuery()
            ->getResult();

        $analytics = [
            'total_enrolled' => 0,
            'active_contacts' => 0,
            'completed_contacts' => 0,
            'touches' => [],
            'overall_metrics' => [
                'total_sends' => 0,
                'total_opens' => 0,
                'total_clicks' => 0,
                'total_replies' => 0,
            ],
        ];

        $totalSent = 0;
        $totalOpened = 0;
        $totalClicked = 0;
        $totalReplied = 0;

        foreach ($touchStats as $stat) {
            $sent = (int) $stat['sent'];
            $opened = (int) $stat['opened'];
            $clicked = (int) $stat['clicked'];
            $replied = (int) $stat['replied'];

            $analytics['touches'][$stat['touchNumber']] = [
                'touch_number' => $stat['touchNumber'],
                'total' => (int) $stat['total'],
                'sent' => $sent,
                'opened' => $opened,
                'clicked' => $clicked,
                'replied' => $replied,
                'open_rate' => $sent > 0 ? round(($opened / $sent) * 100, 2) : 0,
                'click_rate' => $sent > 0 ? round(($clicked / $sent) * 100, 2) : 0,
                'reply_rate' => $sent > 0 ? round(($replied / $sent) * 100, 2) : 0,
            ];

            $totalSent += $sent;
            $totalOpened += $opened;
            $totalClicked += $clicked;
            $totalReplied += $replied;
        }

        $analytics['overall_metrics'] = [
            'total_sends' => $totalSent,
            'total_opens' => $totalOpened,
            'total_clicks' => $totalClicked,
            'total_replies' => $totalReplied,
            'overall_open_rate' => $totalSent > 0 ? round(($totalOpened / $totalSent) * 100, 2) : 0,
            'overall_click_rate' => $totalSent > 0 ? round(($totalClicked / $totalSent) * 100, 2) : 0,
            'overall_reply_rate' => $totalSent > 0 ? round(($totalReplied / $totalSent) * 100, 2) : 0,
        ];

        // Count unique contacts
        $qb = $this->entityManager->createQueryBuilder();
        $uniqueContacts = $qb->select('COUNT(DISTINCT es.contact)')
            ->from(EmailSend::class, 'es')
            ->where('es.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->getQuery()
            ->getSingleScalarResult();

        $analytics['total_enrolled'] = (int) $uniqueContacts;

        // Contacts with an active (queued/sending) send are "active"
        $activeContacts = $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT es.contact)')
            ->from(EmailSend::class, 'es')
            ->where('es.campaign = :campaign')
            ->andWhere('es.status IN (:activeStatuses)')
            ->setParameter('campaign', $campaign)
            ->setParameter('activeStatuses', [
                EmailSend::STATUS_QUEUED,
                EmailSend::STATUS_SENDING,
            ])
            ->getQuery()
            ->getSingleScalarResult();

        $analytics['active_contacts'] = (int) $activeContacts;

        // Contacts with at least one send and no active send are "completed"
        $completedContacts = $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT es.contact)')
            ->from(EmailSend::class, 'es')
            ->where('es.campaign = :campaign')
            ->andWhere('es.contact NOT IN (SELECT es2.contact FROM App\Entity\EmailSend es2 WHERE es2.campaign = :campaign AND es2.status IN (:activeStatuses))')
            ->setParameter('campaign', $campaign)
            ->setParameter('activeStatuses', [
                EmailSend::STATUS_QUEUED,
                EmailSend::STATUS_SENDING,
            ])
            ->getQuery()
            ->getSingleScalarResult();

        $analytics['completed_contacts'] = (int) $completedContacts;

        return $analytics;
    }

    /**
     * Calculate when to send the next touch
     * 
     * @param \DateTimeImmutable $fromTime Starting time
     * @param array $touchConfig Touch configuration
     * @return \DateTimeImmutable Send time
     */
    private function calculateNextSendTime(\DateTimeImmutable $fromTime, array $touchConfig): \DateTimeImmutable
    {
        $delayValue = $touchConfig['delay_value'] ?? 1;
        $delayUnit = $touchConfig['delay_unit'] ?? 'days';

        return match($delayUnit) {
            'minutes' => $fromTime->modify("+$delayValue minutes"),
            'hours' => $fromTime->modify("+$delayValue hours"),
            'days' => $fromTime->modify("+$delayValue days"),
            'weeks' => $fromTime->modify("+$delayValue weeks"),
            default => $fromTime->modify("+$delayValue days")
        };
    }

    /**
     * Evaluate conditions for sending next touch
     * 
     * @param EmailSend $previousSend Previous send
     * @param array $conditions Conditions to evaluate
     * @return bool True if conditions are met
     */
    private function evaluateConditions(EmailSend $previousSend, array $conditions): bool
    {
        if (empty($conditions)) {
            return true; // No conditions, always send
        }

        foreach ($conditions as $condition) {
            $type = $condition['type'] ?? 'always';
            
            switch ($type) {
                case 'opened':
                    if (!$previousSend->isOpened()) {
                        return false;
                    }
                    break;
                    
                case 'not_opened':
                    if ($previousSend->isOpened()) {
                        return false;
                    }
                    break;
                    
                case 'clicked':
                    if (!$previousSend->isClicked()) {
                        return false;
                    }
                    break;
                    
                case 'not_clicked':
                    if ($previousSend->isClicked()) {
                        return false;
                    }
                    break;
                    
                case 'replied':
                    if (!$previousSend->isReplied()) {
                        return false;
                    }
                    break;
                    
                case 'always':
                default:
                    // Always send
                    break;
            }
        }

        return true;
    }

    /**
     * Check if a campaign is a drip campaign
     *
     * New campaigns are marked with EmailCampaign::TYPE_DRIP. Legacy campaigns
     * stored their drip marker in abTestVariants[0]['is_drip'] — that format is
     * still honored for backward compatibility.
     * 
     * @param EmailCampaign $campaign Campaign
     * @return bool True if drip campaign
     */
    public function isDripCampaign(EmailCampaign $campaign): bool
    {
        if ($campaign->getType() === EmailCampaign::TYPE_DRIP) {
            return true;
        }

        // Legacy format: abTestVariants = [['is_drip' => true, ...]]
        $config = $campaign->getAbTestVariants();
        return !empty($config) && isset($config[0]['is_drip']) && $config[0]['is_drip'] === true;
    }

    /**
     * Check if a contact has an ACTIVE enrollment (queued or sending send).
     * Completed or cancelled sequences do not block re-enrollment.
     * 
     * @param EmailCampaign $campaign Campaign
     * @param Contact $contact Contact
     * @return bool True if contact has an active, non-cancelled queued/sending send
     */
    private function hasActiveEnrollment(EmailCampaign $campaign, Contact $contact): bool
    {
        $qb = $this->entityManager->createQueryBuilder();
        $count = $qb->select('COUNT(es.id)')
            ->from(EmailSend::class, 'es')
            ->where('es.campaign = :campaign')
            ->andWhere('es.contact = :contact')
            ->andWhere('es.status IN (:activeStatuses)')
            ->setParameter('campaign', $campaign)
            ->setParameter('contact', $contact)
            ->setParameter('activeStatuses', [
                EmailSend::STATUS_QUEUED,
                EmailSend::STATUS_SENDING,
            ])
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
