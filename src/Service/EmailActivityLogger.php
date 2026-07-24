<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\EmailSend;
use App\Entity\EmailCampaign;
use App\Entity\OutboundMessage;
use App\Entity\Contact;
use App\Entity\Company;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Logs email activities to the unified Activity timeline.
 *
 * Covers BOTH email modules:
 *   - Email Campaigns (EmailSend / EmailCampaign)
 *   - Autonomous Sales (OutboundMessage)
 *
 * This ensures a contact's Activity timeline shows ALL email communication
 * regardless of which module sent it — closing the activity visibility gap.
 */
class EmailActivityLogger
{
    private EntityManagerInterface $em;
    private Security $security;

    public function __construct(
        EntityManagerInterface $em,
        Security $security
    ) {
        $this->em = $em;
        $this->security = $security;
    }

    /**
     * Log an email send as an Activity
     * 
     * @param EmailSend $send The email send to log
     * @return Activity|null The created activity or null if failed
     */
    public function logEmailSend(EmailSend $send): ?Activity
    {
        $contact = $send->getContact();
        $campaign = $send->getCampaign();
        
        if (!$contact || !$campaign) {
            return null;
        }

        $company = $contact->getCompany();
        if (!$company) {
            return null;
        }

        // Get current user or default to first user
        $user = $this->security->getUser();
        if (!$user) {
            $user = $this->em->getRepository(User::class)->findOneBy([], ['id' => 'ASC']);
        }

        $activity = new Activity();
        $activity->setCompany($company);
        $activity->setContact($contact);
        $activity->setUser($user);
        $activity->setType('Email');
        $activity->setActivityDate($send->getSentAt() ?? new \DateTime());
        
        // Build description
        $description = sprintf(
            "Email sent: %s\nCampaign: %s\nSubject: %s",
            $contact->getEmail(),
            $campaign->getName(),
            $campaign->getSubject() ?? $campaign->getName()
        );

        // Add engagement info if available
        if ($send->isOpened()) {
            $openTime = $send->getOpenedAt() ?? $send->getSentAt();
            $description .= sprintf(
                "\n✓ Opened at: %s",
                $openTime ? $openTime->format('Y-m-d H:i') : 'unknown'
            );
        }

        if ($send->isClicked()) {
            $clickTime = $send->getClickedAt() ?? $send->getSentAt();
            $description .= sprintf(
                "\n✓ Clicked at: %s",
                $clickTime ? $clickTime->format('Y-m-d H:i') : 'unknown'
            );
        }

        if ($send->isReplied()) {
            $description .= "\n✓ Replied";
        }

        $activity->setDescription($description);

        $this->em->persist($activity);
        $this->em->flush();

        return $activity;
    }

    /**
     * Update activity when email engagement changes
     * 
     * @param EmailSend $send The email send with updated engagement
     * @param string $engagementType Type of engagement (opened, clicked, replied)
     */
    public function updateEmailEngagement(EmailSend $send, string $engagementType): void
    {
        // Find existing activity for this email send
        $contact = $send->getContact();
        $campaign = $send->getCampaign();
        
        if (!$contact || !$campaign) {
            return;
        }

        $company = $contact->getCompany();
        if (!$company) {
            return;
        }

        // Search for the activity by matching description pattern
        $activities = $this->em->getRepository(Activity::class)
            ->createQueryBuilder('a')
            ->where('a.company = :company')
            ->andWhere('a.contact = :contact')
            ->andWhere('a.type = :type')
            ->andWhere('a.description LIKE :pattern')
            ->setParameter('company', $company)
            ->setParameter('contact', $contact)
            ->setParameter('type', 'Email')
            ->setParameter('pattern', '%Campaign: ' . $campaign->getName() . '%')
            ->orderBy('a.activityDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        if (empty($activities)) {
            // No existing activity found, create a new one
            $this->logEmailSend($send);
            return;
        }

        $activity = $activities[0];
        $description = $activity->getDescription();

        // Add engagement marker if not already present
        $engagementMarkers = [
            'opened' => '✓ Opened at',
            'clicked' => '✓ Clicked at',
            'replied' => '✓ Replied'
        ];

        $marker = $engagementMarkers[$engagementType] ?? null;
        if (!$marker || strpos($description, $marker) !== false) {
            return; // Already logged
        }

        // Append new engagement
        $timestamp = match($engagementType) {
            'opened' => $send->getOpenedAt() ?? new \DateTime(),
            'clicked' => $send->getClickedAt() ?? new \DateTime(),
            'replied' => null, // No timestamp for replied
            default => new \DateTime()
        };

        if ($timestamp) {
            $description .= sprintf(
                "\n%s: %s",
                $marker,
                $timestamp->format('Y-m-d H:i')
            );
        } else {
            $description .= "\n{$marker}";
        }

        $activity->setDescription($description);
        $this->em->flush();
    }

    /**
     * Log a campaign event (created, sent, completed)
     * 
     * @param EmailCampaign $campaign The campaign
     * @param string $eventType Event type (created, sent, completed)
     * @param array $stats Optional stats to include
     */
    public function logCampaignEvent(EmailCampaign $campaign, string $eventType, array $stats = []): void
    {
        // Get primary company for campaign (from segment or first contact)
        $segment = $campaign->getSegment();
        $company = null;
        
        if ($segment) {
            // Try to find a company through the campaign's first send
            $firstSend = $this->em->getRepository(EmailSend::class)
                ->createQueryBuilder('s')
                ->where('s.campaign = :campaign')
                ->setParameter('campaign', $campaign)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($firstSend && $firstSend->getContact()) {
                $company = $firstSend->getContact()->getCompany();
            }
        }

        if (!$company) {
            // Get first send's company
            $send = $this->em->getRepository(EmailSend::class)
                ->createQueryBuilder('s')
                ->where('s.campaign = :campaign')
                ->setParameter('campaign', $campaign)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            
            if ($send && $send->getContact()) {
                $company = $send->getContact()->getCompany();
            }
        }

        if (!$company) {
            return; // Cannot log without a company
        }

        $user = $this->security->getUser();
        if (!$user) {
            $user = $this->em->getRepository(User::class)->findOneBy([], ['id' => 'ASC']);
        }

        $activity = new Activity();
        $activity->setCompany($company);
        $activity->setUser($user);
        $activity->setType('Email Campaign');
        $activity->setActivityDate(new \DateTime());

        // Build description based on event type
        $description = match($eventType) {
            'created' => sprintf("Email campaign created: %s", $campaign->getName()),
            'sent' => sprintf("Email campaign sent: %s", $campaign->getName()),
            'completed' => sprintf("Email campaign completed: %s", $campaign->getName()),
            default => sprintf("Email campaign event: %s - %s", $campaign->getName(), $eventType)
        };

        // Add stats if provided
        if (!empty($stats)) {
            $description .= "\n\nCampaign Statistics:";
            
            if (isset($stats['total_sent'])) {
                $description .= sprintf("\n• Sent: %d emails", $stats['total_sent']);
            }
            
            if (isset($stats['opened'])) {
                $openRate = $stats['total_sent'] > 0 
                    ? round(($stats['opened'] / $stats['total_sent']) * 100, 1)
                    : 0;
                $description .= sprintf("\n• Opened: %d (%s%%)", $stats['opened'], $openRate);
            }
            
            if (isset($stats['clicked'])) {
                $clickRate = $stats['total_sent'] > 0 
                    ? round(($stats['clicked'] / $stats['total_sent']) * 100, 1)
                    : 0;
                $description .= sprintf("\n• Clicked: %d (%s%%)", $stats['clicked'], $clickRate);
            }
            
            if (isset($stats['replied'])) {
                $replyRate = $stats['total_sent'] > 0 
                    ? round(($stats['replied'] / $stats['total_sent']) * 100, 1)
                    : 0;
                $description .= sprintf("\n• Replied: %d (%s%%)", $stats['replied'], $replyRate);
            }

            if (isset($stats['bounced'])) {
                $description .= sprintf("\n• Bounced: %d", $stats['bounced']);
            }
        }

        $activity->setDescription($description);

        $this->em->persist($activity);
        $this->em->flush();
    }

    /**
     * Get email activities for a company
     * 
     * @param Company $company
     * @param int $limit Maximum number of activities to retrieve
     * @return array
     */
    public function getEmailActivities(Company $company, int $limit = 50): array
    {
        return $this->em->getRepository(Activity::class)
            ->createQueryBuilder('a')
            ->where('a.company = :company')
            ->andWhere('a.type IN (:types)')
            ->setParameter('company', $company)
            ->setParameter('types', ['Email', 'Email Campaign', 'Outbound Email', 'Outbound Email Engagement'])
            ->orderBy('a.activityDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get email activities for a contact
     * 
     * @param Contact $contact
     * @param int $limit Maximum number of activities to retrieve
     * @return array
     */
    public function getContactEmailActivities(Contact $contact, int $limit = 50): array
    {
        return $this->em->getRepository(Activity::class)
            ->createQueryBuilder('a')
            ->where('a.contact = :contact')
            ->andWhere('a.type IN (:types)')
            ->setParameter('contact', $contact)
            ->setParameter('types', ['Email', 'Email Campaign', 'Outbound Email', 'Outbound Email Engagement'])
            ->orderBy('a.activityDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get aggregated email activity stats for a company
     * 
     * @param Company $company
     * @return array
     */
    public function getCompanyEmailStats(Company $company): array
    {
        $activities = $this->getEmailActivities($company, 1000);
        
        $stats = [
            'total_emails_sent' => 0,
            'emails_opened' => 0,
            'emails_clicked' => 0,
            'emails_replied' => 0,
            'campaigns_received' => 0,
            'outbound_sent' => 0,
            'last_email_date' => null,
            'engagement_rate' => 0
        ];

        foreach ($activities as $activity) {
            $type = $activity->getType();
            $description = $activity->getDescription();

            if ($type === 'Email' || $type === 'Outbound Email') {
                $stats['total_emails_sent']++;

                if ($type === 'Outbound Email') {
                    $stats['outbound_sent']++;
                }

                if (strpos($description, '✓ Opened') !== false) {
                    $stats['emails_opened']++;
                }
                if (strpos($description, '✓ Clicked') !== false) {
                    $stats['emails_clicked']++;
                }
                if (strpos($description, '✓ Replied') !== false) {
                    $stats['emails_replied']++;
                }
                
                if (!$stats['last_email_date'] || $activity->getActivityDate() > $stats['last_email_date']) {
                    $stats['last_email_date'] = $activity->getActivityDate();
                }
            } elseif ($type === 'Email Campaign') {
                $stats['campaigns_received']++;
            }
        }

        // Calculate engagement rate
        if ($stats['total_emails_sent'] > 0) {
            $engaged = $stats['emails_opened'] + $stats['emails_clicked'] + $stats['emails_replied'];
            $stats['engagement_rate'] = round(($engaged / $stats['total_emails_sent']) * 100, 1);
        }

        return $stats;
    }

    // ==================== AUTONOMOUS SALES OUTBOUND LOGGING ====================

    /**
     * Log an autonomous sales outbound email send as an Activity.
     *
     * Creates an Activity record so that outbound emails appear on the
     * contact's/company's unified CRM timeline alongside campaign emails,
     * calls, meetings, etc.
     *
     * @param OutboundMessage $message The outbound message that was sent
     * @return Activity|null The created activity or null if insufficient data
     */
    public function logOutboundSend(OutboundMessage $message): ?Activity
    {
        $contact = $message->getContact();
        if (!$contact) {
            return null;
        }

        $company = $contact->getCompany();
        if (!$company) {
            return null;
        }

        $user = $this->security->getUser();
        if (!$user) {
            $user = $this->em->getRepository(User::class)->findOneBy([], ['id' => 'ASC']);
        }

        $activity = new Activity();
        $activity->setCompany($company);
        $activity->setContact($contact);
        $activity->setUser($user);
        $activity->setType('Outbound Email');
        $activity->setSubject($message->getSubject());
        $activity->setActivityDate($message->getSentAt() ?? new \DateTime());
        $activity->setStatus(Activity::STATUS_COMPLETED);
        $activity->setOutcome(Activity::OUTCOME_PENDING);

        $templateName = $message->getTemplate()?->getName() ?? 'unknown';
        $armName = $message->getSubjectArm()?->getArmName() ?? 'default';

        $description = sprintf(
            "Outbound email sent (Autonomous Sales)\nTo: %s\nSubject: %s\nTemplate: %s\nSubject arm: %s",
            $contact->getEmail() ?? 'unknown',
            $message->getSubject() ?? '(no subject)',
            $templateName,
            $armName
        );

        $activity->setDescription($description);

        $this->em->persist($activity);
        $this->em->flush();

        return $activity;
    }

    /**
     * Update an outbound email's Activity when engagement occurs.
     *
     * Appends engagement markers (opened, clicked, replied) to the existing
     * Activity description, and updates the outcome field for analytics.
     *
     * @param OutboundMessage $message  The outbound message with engagement
     * @param string          $eventType  'opened', 'clicked', 'replied', 'bounced'
     * @param string|null     $classification  Reply classification (for replies)
     */
    public function updateOutboundEngagement(
        OutboundMessage $message,
        string $eventType,
        ?string $classification = null
    ): void {
        $contact = $message->getContact();
        if (!$contact) {
            return;
        }

        $company = $contact->getCompany();
        if (!$company) {
            return;
        }

        // Find existing activity for this outbound message
        $activities = $this->em->getRepository(Activity::class)
            ->createQueryBuilder('a')
            ->where('a.company = :company')
            ->andWhere('a.contact = :contact')
            ->andWhere('a.type = :type')
            ->andWhere('a.description LIKE :pattern')
            ->setParameter('company', $company)
            ->setParameter('contact', $contact)
            ->setParameter('type', 'Outbound Email')
            ->setParameter('pattern', '%Outbound email sent (Autonomous Sales)%Subject: ' . substr($message->getSubject() ?? '', 0, 50) . '%')
            ->orderBy('a.activityDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        if (empty($activities)) {
            // No existing activity — create one with the engagement already logged
            $activity = $this->logOutboundSend($message);
            if (!$activity) {
                return;
            }
            $activities = [$activity];
        }

        $activity = $activities[0];
        $description = $activity->getDescription();

        // Engagement markers
        $markers = [
            'opened'  => '✓ Opened',
            'clicked' => '✓ Clicked',
            'replied' => '✓ Replied',
            'bounced' => '✗ Bounced',
        ];

        $marker = $markers[$eventType] ?? null;
        if (!$marker || str_contains($description, $marker)) {
            return; // Already logged or unknown event
        }

        $timestamp = match ($eventType) {
            'opened'  => $message->getOpenedAt() ?? new \DateTime(),
            'clicked' => $message->getClickedAt() ?? new \DateTime(),
            'replied' => $message->getRepliedAt() ?? new \DateTime(),
            default   => new \DateTime(),
        };

        $line = sprintf("\n%s at: %s", $marker, $timestamp->format('Y-m-d H:i'));

        if ($eventType === 'replied' && $classification) {
            $line .= sprintf(' (classified: %s)', $classification);
        }

        $description .= $line;
        $activity->setDescription($description);

        // Update outcome based on engagement
        $outcome = match ($eventType) {
            'replied'                       => match ($classification) {
                'interested',
                'meeting_request',
                'information_request'       => Activity::OUTCOME_POSITIVE,
                'not_interested',
                'unsubscribe', 'spam'       => Activity::OUTCOME_NEGATIVE,
                default                     => Activity::OUTCOME_NEUTRAL,
            },
            'bounced'                       => Activity::OUTCOME_NO_RESPONSE,
            'opened', 'clicked'             => Activity::OUTCOME_PENDING,
            default                         => null,
        };

        if ($outcome) {
            $activity->setOutcome($outcome);
        }

        $this->em->flush();
    }
}
