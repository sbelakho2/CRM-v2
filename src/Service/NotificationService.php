<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Entity\RFQ;
use App\Entity\EmailSend;
use App\Entity\Lead;
use App\Entity\Quote;
use App\Entity\AbmAccount;
use App\Repository\RFQRepository;
use App\Repository\EmailSendRepository;
use App\Repository\LeadRepository;
use App\Repository\QuoteRepository;
use App\Repository\AbmAccountRepository;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service to detect and create notifications for users
 */
class NotificationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationRepository $notificationRepo,
        private RFQRepository $rfqRepo,
        private EmailSendRepository $emailSendRepo,
        private LeadRepository $leadRepo,
        private QuoteRepository $quoteRepo,
        private AbmAccountRepository $abmAccountRepo,
        private LoggerInterface $logger
    ) {}

    /**
     * Check for RFQ deadlines (due in 2 days)
     */
    public function checkRFQDeadlines(User $user): int
    {
        $notificationsCreated = 0;
        $now = new \DateTime();
        $twoDaysFromNow = clone $now;
        $twoDaysFromNow->modify('+2 days');

        // Find RFQs with upcoming SOP dates
        $rfqs = $this->rfqRepo->createQueryBuilder('r')
            ->where('r.sopDate IS NOT NULL')
            ->andWhere('r.sopDate > :now')
            ->andWhere('r.sopDate <= :twoDays')
            ->setParameter('now', $now)
            ->setParameter('twoDays', $twoDaysFromNow)
            ->getQuery()
            ->getResult();

        foreach ($rfqs as $rfq) {
            // Check if we already notified about this RFQ
            if (!$this->notificationRepo->existsForEntity($user, 'rfq_due', 'RFQ', $rfq->getId())) {
                $notification = new Notification();
                $notification->setUser($user);
                $notification->setType('rfq_due');
                $notification->setEntityType('RFQ');
                $notification->setEntityId($rfq->getId());
                $notification->setMessage(sprintf(
                    'RFQ %s from %s due %s',
                    $rfq->getId(),
                    $rfq->getCompany() ? $rfq->getCompany()->getName() : 'Unknown',
                    $rfq->getSopDate()->format('M d, Y')
                ));
                $notification->setData([
                    'company_name' => $rfq->getCompany()?->getName(),
                    'rfq_id' => $rfq->getId(),
                    'due_date' => $rfq->getSopDate()->format('Y-m-d'),
                    'days_until' => $rfq->getSopDate()->diff($now)->days
                ]);

                $this->em->persist($notification);
                $notificationsCreated++;
            }
        }

        if ($notificationsCreated > 0) {
            $this->em->flush();
            $this->logger->info("Created {$notificationsCreated} RFQ deadline notifications for user {$user->getId()}");
        }

        return $notificationsCreated;
    }

    /**
     * Check for email replies (recently opened emails)
     */
    public function checkEmailReplies(User $user): int
    {
        $notificationsCreated = 0;
        $oneHourAgo = (new \DateTime())->modify('-1 hour');

        // Find recently opened emails
        $emails = $this->emailSendRepo->createQueryBuilder('es')
            ->join('es.campaign', 'c')
            ->where('es.openedAt IS NOT NULL')
            ->andWhere('es.openedAt > :oneHourAgo')
            ->setParameter('oneHourAgo', $oneHourAgo)
            ->getQuery()
            ->getResult();

        foreach ($emails as $email) {
            // Check if we already notified
            if (!$this->notificationRepo->existsForEntity($user, 'email_reply', 'EmailSend', $email->getId())) {
                $notification = new Notification();
                $notification->setUser($user);
                $notification->setType('email_reply');
                $notification->setEntityType('EmailSend');
                $notification->setEntityId($email->getId());
                $notification->setMessage(sprintf(
                    'Email "%s" opened by %s',
                    $email->getCampaign()?->getSubject() ?? $email->getCampaign()?->getName() ?? 'Unknown',
                    $email->getEmailAddress() ?? 'Unknown'
                ));
                $notification->setData([
                    'subject' => $email->getCampaign()?->getSubject() ?? $email->getCampaign()?->getName(),
                    'recipient' => $email->getEmailAddress(),
                    'opened_at' => $email->getOpenedAt()?->format('Y-m-d H:i:s')
                ]);

                $this->em->persist($notification);
                $notificationsCreated++;
            }
        }

        if ($notificationsCreated > 0) {
            $this->em->flush();
            $this->logger->info("Created {$notificationsCreated} email reply notifications for user {$user->getId()}");
        }

        return $notificationsCreated;
    }

    /**
     * Check for newly approved leads (for assigned user)
     */
    public function checkLeadApprovals(User $user): int
    {
        $notificationsCreated = 0;
        $fiveMinutesAgo = (new \DateTime())->modify('-5 minutes');

        // Find recently approved leads
        $leads = $this->leadRepo->createQueryBuilder('l')
            ->where('l.ownerRep = :rep')
            ->andWhere('l.reviewStatus = :approved')
            ->andWhere('l.updatedAt > :fiveMinutesAgo')
            ->setParameter('rep', $user->getEmail())
            ->setParameter('approved', 'approved')
            ->setParameter('fiveMinutesAgo', $fiveMinutesAgo)
            ->getQuery()
            ->getResult();

        foreach ($leads as $lead) {
            if (!$this->notificationRepo->existsForEntity($user, 'lead_approval', 'Lead', $lead->getId())) {
                $notification = new Notification();
                $notification->setUser($user);
                $notification->setType('lead_approval');
                $notification->setEntityType('Lead');
                $notification->setEntityId($lead->getId());
                $notification->setMessage(sprintf(
                    'Lead %s (%s) approved - Score: %d',
                    $lead->getId(),
                    $lead->getCompanyName(),
                    $lead->getLeadScore() ?? 0
                ));
                $notification->setData([
                    'lead_id' => $lead->getId(),
                    'company_name' => $lead->getCompanyName(),
                    'lead_score' => $lead->getLeadScore()
                ]);

                $this->em->persist($notification);
                $notificationsCreated++;
            }
        }

        if ($notificationsCreated > 0) {
            $this->em->flush();
            $this->logger->info("Created {$notificationsCreated} lead approval notifications for user {$user->getId()}");
        }

        return $notificationsCreated;
    }

    /**
     * Check for quote views
     */
    public function checkQuoteViews(User $user): int
    {
        $notificationsCreated = 0;
        $oneHourAgo = (new \DateTime())->modify('-1 hour');

        // Find recently viewed quotes
        $quotes = $this->quoteRepo->createQueryBuilder('q')
            ->where('q.lastViewedAt IS NOT NULL')
            ->andWhere('q.lastViewedAt > :oneHourAgo')
            ->setParameter('oneHourAgo', $oneHourAgo)
            ->getQuery()
            ->getResult();

        foreach ($quotes as $quote) {
            if (!$this->notificationRepo->existsForEntity($user, 'quote_viewed', 'Quote', $quote->getId())) {
                $notification = new Notification();
                $notification->setUser($user);
                $notification->setType('quote_viewed');
                $notification->setEntityType('Quote');
                $notification->setEntityId($quote->getId());
                $notification->setMessage(sprintf(
                    'Quote #%s viewed by %s',
                    $quote->getId(),
                    $quote->getCompany()?->getName() ?? 'Customer'
                ));
                $notification->setData([
                    'quote_id' => $quote->getId(),
                    'company_name' => $quote->getCompany()?->getName(),
                    'viewed_at' => $quote->getLastViewedAt()?->format('Y-m-d H:i:s')
                ]);

                $this->em->persist($notification);
                $notificationsCreated++;
            }
        }

        if ($notificationsCreated > 0) {
            $this->em->flush();
            $this->logger->info("Created {$notificationsCreated} quote view notifications for user {$user->getId()}");
        }

        return $notificationsCreated;
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Notification $notification): void
    {
        $notification->markAsRead();
        $this->em->flush();
    }

    /**
     * Get unread notification count for user
     */
    public function getUnreadCount(User $user): int
    {
        return $this->notificationRepo->countUnreadForUser($user);
    }

    /**
     * Get recent unread notifications for user
     */
    public function getRecentUnread(User $user, int $limit = 5): array
    {
        return $this->notificationRepo->findUnreadForUser($user, $limit);
    }

    /**
     * Clean up old notifications (older than 7 days)
     */
    public function cleanupOldNotifications(): int
    {
        $sevenDaysAgo = (new \DateTime())->modify('-7 days');
        return $this->notificationRepo->deleteOlderThan($sevenDaysAgo);
    }
}
