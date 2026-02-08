<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\Lead;
use App\Entity\Activity;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Service to provide contextual guidance notifications for users
 */
class GuidanceNotificationService
{
    private const SESSION_KEY = 'guidance_notifications';

    public function __construct(
        private EntityManagerInterface $em,
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator
    ) {}

    /**
     * Add a guidance notification to the session
     * @param string $type Type of notification (success, warning, info, tip)
     * @param string $messageKey Translation key for the message
     * @param array $messageParams Parameters for the translation
     * @param string|null $actionUrl URL for the action button
     * @param string|null $actionLabelKey Translation key for the action button label
     * @param string|null $dismissKey Unique key to identify this notification type for auto-dismissal
     */
    private function addGuidance(
        string $type, 
        string $messageKey, 
        array $messageParams = [], 
        ?string $actionUrl = null, 
        ?string $actionLabelKey = null, 
        ?string $dismissKey = null
    ): void {
        $session = $this->requestStack->getSession();
        $notifications = $session->get(self::SESSION_KEY, []);
        
        // Translate message and label
        $message = $this->translator->trans($messageKey, $messageParams);
        $actionLabel = $actionLabelKey ? $this->translator->trans($actionLabelKey) : null;
        
        // Check if this notification was permanently dismissed
        $dismissedNotifications = $session->get('dismissed_guidance', []);
        if ($dismissKey !== null && in_array($dismissKey, $dismissedNotifications)) {
            // This notification was permanently dismissed, don't show it again
            return;
        }

        // Prevent duplicate notifications with the same dismissKey
        if ($dismissKey !== null) {
            foreach ($notifications as $notification) {
                if (isset($notification['dismissKey']) && $notification['dismissKey'] === $dismissKey) {
                    // Notification already exists, don't add duplicate
                    return;
                }
            }
        }

        $notifications[] = [
            'type' => $type,
            'message' => $message,
            'actionUrl' => $actionUrl,
            'actionLabel' => $actionLabel,
            'dismissKey' => $dismissKey,
            'timestamp' => time(),
        ];

        $session->set(self::SESSION_KEY, $notifications);
    }

    /**
     * Remove notifications by dismiss key
     * Used to automatically dismiss notifications when the related action is completed
     */
    public function autoDismissNotifications(string $dismissKey): void
    {
        $session = $this->requestStack->getSession();
        $notifications = $session->get(self::SESSION_KEY, []);

        $notifications = array_filter($notifications, function($notification) use ($dismissKey) {
            return !isset($notification['dismissKey']) || $notification['dismissKey'] !== $dismissKey;
        });

        // Re-index array
        $notifications = array_values($notifications);
        $session->set(self::SESSION_KEY, $notifications);
    }

    /**
     * Get and clear all guidance notifications
     */
    public function getGuidanceNotifications(): array
    {
        $session = $this->requestStack->getSession();
        $notifications = $session->get(self::SESSION_KEY, []);
        $session->remove(self::SESSION_KEY);
        return $notifications;
    }

    /**
     * Check if guidance notifications exist
     */
    public function hasGuidanceNotifications(): bool
    {
        $session = $this->requestStack->getSession();
        $notifications = $session->get(self::SESSION_KEY, []);
        return !empty($notifications);
    }

    /**
     * Clear all guidance notifications
     */
    public function clearGuidanceNotifications(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove(self::SESSION_KEY);
    }

    /**
     * After creating a new company, suggest next steps
     */
    public function afterCompanyCreated(Company $company): void
    {
        $companyId = $company->getId();
        $companyName = $company->getName();

        // Check if company has contacts
        if ($company->getContacts()->isEmpty()) {
            $this->addGuidance(
                'info',
                "📇 Add contacts to {$companyName} to start building relationships",
                "/contacts/new?company_id={$companyId}",
                'Add Contact',
                "company_{$companyId}_add_contact"
            );
        }

        // Check if compliance documents are uploaded
        $complianceCount = $this->em->getRepository('App\Entity\ComplianceDocument')
            ->count(['company' => $company]);

        if ($complianceCount === 0) {
            $this->addGuidance(
                'warning',
                "📋 Upload compliance documents for {$companyName} (certifications, quality docs)",
                "/compliance/company/{$companyId}",
                'Add Compliance',
                "company_{$companyId}_add_compliance"
            );
        }

        // Suggest logging first activity
        $activityCount = $this->em->getRepository(Activity::class)
            ->count(['company' => $company]);

        if ($activityCount === 0) {
            $this->addGuidance(
                'info',
                "⚡ Log your first activity with {$companyName} to track engagement",
                $this->urlGenerator->generate('app_activity_new', ['company' => $companyId]),
                'Log Activity',
                "company_{$companyId}_log_activity"
            );
        }
    }

    /**
     * After creating a contact, suggest next steps
     */
    public function afterContactCreated(Contact $contact): void
    {
        $contactName = $contact->getFirstName() . ' ' . $contact->getLastName();
        $contactId = $contact->getId();

        // Suggest logging an activity
        $this->addGuidance(
            'info',
            "📞 Log your first interaction with {$contactName} to start tracking engagement",
            $this->urlGenerator->generate('app_activity_new', ['contact' => $contactId]),
            'Log Activity',
            "contact_{$contactId}_log_activity"
        );
    }

    /**
     * After converting a lead to company
     */
    public function afterLeadConverted(Company $company, Lead $lead): void
    {
        $companyName = $company->getName();
        $companyId = $company->getId();

        $this->addGuidance(
            'success',
            "✅ Lead converted to company: {$companyName}",
            "/companies/{$companyId}",
            'View Company'
        );

        // Suggest adding contacts
        if ($company->getContacts()->isEmpty()) {
            $this->addGuidance(
                'info',
                "👥 Add key contacts at {$companyName} to begin outreach",
                "/contacts/new?company={$companyId}",
                'Add Contact'
            );
        }

        // Suggest compliance check
        $this->addGuidance(
            'warning',
            "📋 Don't forget to request and upload compliance documents for {$companyName}",
            "/compliance/company/{$companyId}",
            'Add Compliance'
        );
    }

    /**
     * After logging an activity, suggest follow-up
     */
    public function afterActivityLogged(Activity $activity): void
    {
        $outcome = $activity->getOutcome();
        $activityId = $activity->getId();

        // If outcome requires follow-up, remind user
        if ($outcome === 'Follow-up Required' || $outcome === 'Needs Follow-up') {
            $companyName = $activity->getCompany()?->getName();
            $companyId = $activity->getCompany()?->getId();
            $this->addGuidance(
                'warning',
                "⏰ Schedule a follow-up activity for {$companyName} - don't lose momentum!",
                $companyId ? $this->urlGenerator->generate('app_activity_new', ['company' => $companyId]) : $this->urlGenerator->generate('app_activity_new'),
                'Schedule Follow-up',
                "activity_{$activityId}_followup"
            );
        }

        // If it's a successful call/meeting, suggest next steps
        if ($outcome === 'Successful' && in_array($activity->getType(), ['Call', 'Meeting'])) {
            $this->addGuidance(
                'success',
                "🎯 Great call! Consider sending a follow-up email or creating a quote",
                null,
                null,
                "activity_{$activityId}_success_followup"
            );
        }

        // If it's a lost opportunity, suggest documenting reasons
        if ($outcome === 'Lost' || $outcome === 'No Interest') {
            $this->addGuidance(
                'info',
                "📝 Log reasons and lessons learned from this outcome in the activity notes",
                $this->urlGenerator->generate('app_activity_edit', ['id' => $activityId]),
                'Update Notes',
                "activity_{$activityId}_lost_reasons"
            );
        }
    }

    /**
     * After approving leads, suggest conversion
     */
    public function afterLeadsApproved(int $count): void
    {
        if ($count > 0) {
            $this->addGuidance(
                'info',
                "✅ {$count} lead(s) approved. Convert them to companies to start engagement",
                "/leads",
                'View Leads'
            );
        }
    }

    /**
     * Check for incomplete company profiles and notify
     */
    public function checkIncompleteCompanyProfile(Company $company): void
    {
        $issues = [];
        $companyId = $company->getId();
        $companyName = $company->getName();

        // Check for missing key information
        if (!$company->getWebsite()) {
            $issues[] = 'website';
        }

        if (!$company->getSector()) {
            $issues[] = 'sector';
        }

        if (!$company->getAccountTier()) {
            $issues[] = 'account tier';
        }

        if (!$company->getPipelineStage()) {
            $issues[] = 'pipeline stage';
        }

        if ($company->getContacts()->isEmpty()) {
            $issues[] = 'contacts';
        }

        if (!empty($issues)) {
            $missingFields = implode(', ', $issues);
            $this->addGuidance(
                'warning',
                "⚠️ {$companyName} profile is incomplete. Missing: {$missingFields}",
                "/companies/{$companyId}/edit",
                'Complete Profile'
            );
        }
    }

    /**
     * Remind about compliance document expiry
     */
    public function checkComplianceReminders(User $user): void
    {
        $thirtyDaysFromNow = new \DateTime('+30 days');
        
        // Find companies with expiring compliance documents
        $qb = $this->em->createQueryBuilder();
        $qb->select('c.id', 'c.name', 'COUNT(cd.id) as expiring_count')
            ->from('App\Entity\ComplianceDocument', 'cd')
            ->join('cd.company', 'c')
            ->where('cd.expiryDate IS NOT NULL')
            ->andWhere('cd.expiryDate BETWEEN :now AND :thirtyDays')
            ->setParameter('now', new \DateTime())
            ->setParameter('thirtyDays', $thirtyDaysFromNow)
            ->groupBy('c.id', 'c.name')
            ->setMaxResults(3);

        $results = $qb->getQuery()->getResult();

        foreach ($results as $result) {
            $companyId = $result['id'];
            $companyName = $result['name'];
            $count = $result['expiring_count'];
            
            $this->addGuidance(
                'warning',
                "⚠️ {$count} compliance document(s) expiring soon for {$companyName}",
                "/compliance/company/{$companyId}",
                'Review Compliance',
                "compliance_{$companyId}_expiring_" . date('Y-m-d')
            );
        }
    }

    /**
     * Suggest creating email campaign after multiple activities
     */
    public function suggestEmailCampaign(User $user): void
    {
        // Check if user has logged many activities but no recent campaigns
        $recentActivities = $this->em->getRepository(Activity::class)
            ->createQueryBuilder('a')
            ->where('a.user = :user')
            ->andWhere('a.activityDate >= :lastWeek')
            ->setParameter('user', $user)
            ->setParameter('lastWeek', new \DateTime('-7 days'))
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if ($recentActivities >= 10) {
            $this->addGuidance(
                'tip',
                "💡 You've been active this week! Consider creating an email campaign to scale your outreach",
                "/email-campaigns/new",
                'Create Campaign'
            );
        }
    }

    /**
     * After creating a quote, suggest next steps
     */
    public function afterQuoteCreated(int $quoteId, string $companyName): void
    {
        $this->addGuidance(
            'success',
            "💰 Quote created for {$companyName}. Remember to follow up within 48 hours",
            "/quotes/{$quoteId}",
            'View Quote'
        );

        $this->addGuidance(
            'info',
            "📧 Consider sending a follow-up email explaining the quote details",
            $this->urlGenerator->generate('app_activity_new'),
            'Log Follow-up'
        );
    }

    /**
     * After receiving an RFQ, guide through the process
     */
    public function afterRFQReceived(int $rfqId, string $companyName): void
    {
        $this->addGuidance(
            'info',
            "📨 New RFQ from {$companyName}. Check if NDA is required before proceeding",
            "/rfqs/{$rfqId}",
            'View RFQ'
        );

        $this->addGuidance(
            'warning',
            "⏰ Review the RFQ deadline and ensure timely response",
            null,
            null
        );
    }

    /**
     * Daily workflow reminders for users
     */
    public function dailyWorkflowReminders(User $user): void
    {
        // Check for pending lead reviews
        $pendingLeads = $this->em->getRepository(Lead::class)
            ->count(['reviewStatus' => 'pending']);

        if ($pendingLeads > 0) {
            $this->addGuidance(
                'info',
                'guidance.pending_leads',
                ['{count}' => $pendingLeads],
                '/leads/review',
                'guidance.action.review_leads',
                "daily_pending_leads_{$user->getId()}_" . date('Y-m-d')
            );
        }

        // Check for activities logged today
        $today = new \DateTime();
        $today->setTime(0, 0, 0);
        $tomorrow = clone $today;
        $tomorrow->modify('+1 day');
        
        $todayActivities = $this->em->getRepository(Activity::class)
            ->createQueryBuilder('a')
            ->where('a.user = :user')
            ->andWhere('a.activityDate >= :today')
            ->andWhere('a.activityDate < :tomorrow')
            ->setParameter('user', $user)
            ->setParameter('today', $today)
            ->setParameter('tomorrow', $tomorrow)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if ((int) $todayActivities === 0) {
            $this->addGuidance(
                'tip',
                'guidance.no_activities',
                [],
                $this->urlGenerator->generate('app_activity_new'),
                'guidance.action.log_activity',
                "daily_no_activities_{$user->getId()}_" . date('Y-m-d')
            );
        }
    }

    /**
     * After creating an RFQ, guide through the process
     */
    public function afterRFQCreated(int $rfqId, string $companyName, bool $hasSopDate): void
    {
        $this->addGuidance(
            'success',
            'guidance.rfq_created',
            ['{name}' => $companyName],
            "/rfqs/{$rfqId}",
            'guidance.action.view_rfq',
            "rfq_{$rfqId}_created"
        );

        if ($hasSopDate) {
            $this->addGuidance(
                'info',
                'guidance.rfq_sop_reminder',
                [],
                null,
                null,
                "rfq_{$rfqId}_sop_reminder"
            );
        }

        $this->addGuidance(
            'info',
            'guidance.rfq_nda_check',
            [],
            "/rfqs/{$rfqId}",
            'guidance.action.manage_nda',
            "rfq_{$rfqId}_nda_check"
        );

        $this->addGuidance(
            'tip',
            'guidance.rfq_log_activity',
            [],
            $this->urlGenerator->generate('app_activity_new'),
            'guidance.action.log_activity',
            "rfq_{$rfqId}_log_activity"
        );
    }

    /**
     * After approving leads, suggest conversion
     */
    public function afterBulkLeadsApproved(int $count): void
    {
        if ($count > 0) {
            $this->addGuidance(
                'success',
                'guidance.bulk_leads_approved',
                ['{count}' => $count],
                '/leads/review',
                'guidance.action.view_leads'
            );

            $this->addGuidance(
                'info',
                'guidance.convert_leads',
                [],
                '/leads/review',
                'guidance.action.convert_leads'
            );
        }
    }

    /**
     * After creating an email campaign
     */
    public function afterEmailCampaignCreated(int $campaignId, string $campaignName): void
    {
        $this->addGuidance(
            'success',
            'guidance.campaign_created',
            ['{name}' => $campaignName],
            "/email-campaigns/{$campaignId}",
            'guidance.action.view_campaign',
            "campaign_{$campaignId}_created"
        );

        $this->addGuidance(
            'info',
            'guidance.campaign_send',
            [],
            "/email-campaigns/{$campaignId}/send",
            'guidance.action.send_campaign',
            "campaign_{$campaignId}_send"
        );

        $this->addGuidance(
            'tip',
            'guidance.campaign_best_practice',
            [],
            null,
            null,
            "campaign_{$campaignId}_best_practice"
        );
    }

    /**
     * After sending an email campaign
     */
    public function afterEmailCampaignSent(int $campaignId, int $sentCount): void
    {
        // Auto-dismiss the "send campaign" notification
        $this->autoDismissNotifications("campaign_{$campaignId}_send");

        $this->addGuidance(
            'success',
            'guidance.campaign_sent',
            ['{count}' => $sentCount],
            "/email-campaigns/{$campaignId}/analytics",
            'guidance.action.view_analytics',
            "campaign_{$campaignId}_sent"
        );

        $this->addGuidance(
            'info',
            'guidance.campaign_monitor',
            [],
            "/email-campaigns/{$campaignId}/analytics",
            'guidance.action.track_performance',
            "campaign_{$campaignId}_monitor"
        );

        $this->addGuidance(
            'warning',
            'guidance.campaign_log_responses',
            [],
            $this->urlGenerator->generate('app_activity_new'),
            'guidance.action.log_activity',
            "campaign_{$campaignId}_log_responses"
        );
    }

    /**
     * After updating RFQ status
     */
    public function afterRFQStatusUpdated(int $rfqId, string $newStatus, string $companyName): void
    {
        $guidance = [
            'Submitted' => [
                'type' => 'success',
                'messageKey' => 'guidance.rfq_submitted',
                'tipKey' => 'guidance.rfq_submitted_tip'
            ],
            'Won' => [
                'type' => 'success',
                'messageKey' => 'guidance.rfq_won',
                'tipKey' => 'guidance.rfq_won_tip'
            ],
            'Lost' => [
                'type' => 'warning',
                'messageKey' => 'guidance.rfq_lost',
                'tipKey' => 'guidance.rfq_lost_tip'
            ],
            'In Review' => [
                'type' => 'info',
                'messageKey' => 'guidance.rfq_review',
                'tipKey' => 'guidance.rfq_review_tip'
            ]
        ];

        if (isset($guidance[$newStatus])) {
            $this->addGuidance(
                $guidance[$newStatus]['type'],
                $guidance[$newStatus]['messageKey'],
                ['{name}' => $companyName],
                "/rfqs/{$rfqId}",
                'guidance.action.view_rfq',
                "rfq_{$rfqId}_status_{$newStatus}"
            );

            $this->addGuidance(
                'tip',
                $guidance[$newStatus]['tipKey'],
                [],
                null,
                null,
                "rfq_{$rfqId}_status_tip_{$newStatus}"
            );
        }
    }

    /**
     * After uploading compliance document
     */
    public function afterComplianceDocumentUploaded(string $companyName, int $companyId): void
    {
        $this->addGuidance(
            'success',
            'guidance.compliance_uploaded',
            ['{name}' => $companyName],
            "/compliance/company/{$companyId}",
            'guidance.action.view_documents',
            "compliance_{$companyId}_uploaded"
        );

        // Check if more documents are needed
        $complianceCount = $this->em->getRepository('App\Entity\ComplianceDocument')
            ->count(['company' => $companyId]);

        if ($complianceCount < 3) {
            $this->addGuidance(
                'tip',
                'guidance.compliance_tip',
                [],
                "/compliance/company/{$companyId}",
                'guidance.action.add_more',
                "compliance_{$companyId}_additional"
            );
        }
    }

    /**
     * After creating a playbook
     */
    public function afterPlaybookCreated(int $playbookId, string $playbookName): void
    {
        $this->addGuidance(
            'success',
            'guidance.playbook_created',
            ['{name}' => $playbookName],
            "/playbooks/{$playbookId}",
            'guidance.action.view_playbook',
            "playbook_{$playbookId}_created"
        );

        $this->addGuidance(
            'info',
            'guidance.playbook_assign',
            [],
            "/playbooks",
            'guidance.action.assign_companies',
            "playbook_{$playbookId}_assign"
        );
    }

    /**
     * Remind about upcoming RFQ SOP dates
     */
    public function checkUpcomingRFQDeadlines(User $user): void
    {
        $thirtyDaysFromNow = new \DateTime('+30 days');
        
        $qb = $this->em->createQueryBuilder();
        $upcomingRfqs = $qb->select('r')
            ->from('App\Entity\RFQ', 'r')
            ->where('r.sopDate IS NOT NULL')
            ->andWhere('r.sopDate BETWEEN :now AND :thirtyDays')
            ->andWhere('r.status NOT IN (:completed)')
            ->setParameter('now', new \DateTime())
            ->setParameter('thirtyDays', $thirtyDaysFromNow)
            ->setParameter('completed', ['Won', 'Lost'])
            ->setMaxResults(3)
            ->getQuery()
            ->getResult();

        foreach ($upcomingRfqs as $rfq) {
            $daysLeft = (new \DateTime())->diff($rfq->getSopDate())->days;
            $companyName = $rfq->getCompany() ? $rfq->getCompany()->getName() : 'Unknown';
            
            $this->addGuidance(
                'warning',
                'guidance.rfq_sop_deadline',
                ['{name}' => $companyName, '{days}' => $daysLeft],
                "/rfqs/{$rfq->getId()}",
                'guidance.action.review_rfq',
                "rfq_{$rfq->getId()}_sop_reminder_" . date('Y-m-d')
            );
        }
    }
}
