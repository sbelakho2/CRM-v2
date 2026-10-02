<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailCampaign;
use App\Entity\Contact;
use App\Entity\EmailSend;
use App\Message\EmailCampaignMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Service for email campaign scheduling and queue management
 * 
 * Eligibility (unsubscribe/bounce/cadence/archival) is enforced centrally
 * by App\Service\EmailSendPolicy inside every send path.
 * 
 * Features:
 * - Send time optimization based on engagement history
 * - Timezone-aware scheduling
 * - Queue management with batch processing
 * - Rate limiting and throttling
 * - Retry logic for failed sends
 * - Cross-module cadence check (OutboundMessage + EmailSend combined)
 */
class EmailSchedulerService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private EmailCampaignService $campaignService,
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * Schedule a campaign for sending.
     *
     * Persists scheduledAt; the due-campaign worker claims and dispatches
     * it exactly once (see scheduleCampaign). Returns the number of
     * enrolled recipients.
     *
     * Send-time optimization is real, not a placeholder: when enabled (and
     * no explicit time given), the target time is derived from the hour of
     * day recipients historically opened campaign email. With insufficient
     * evidence (< MIN_OPENS_FOR_OPTIMIZATION opens) it falls back to "now"
     * rather than pretending to optimize.
     */
    /**
     * Schedule a campaign for future sending.
     *
     * SINGLE OWNERSHIP MODEL (audit rule): this method ONLY records the
     * schedule. The due-campaign worker (dispatchDueCampaigns, via
     * app:email:process-scheduled) is the ONLY component that claims and
     * dispatches scheduled campaigns — with FOR UPDATE SKIP LOCKED and the
     * scheduled_dispatched_at claim marker. Dispatching here as well would
     * double-enqueue the same campaign (delayed message now, cron claim
     * later) and re-introduce exactly-once races.
     *
     * Returns the number of currently enrolled recipients.
     */
    public function scheduleCampaign(
        EmailCampaign $campaign,
        ?\DateTimeImmutable $scheduledAt = null,
        bool $optimizeSendTime = false
    ): int {
        if ($optimizeSendTime && $scheduledAt === null) {
            $scheduledAt = $this->computeOptimalSendTime();
        }

        // Resolve the EFFECTIVE time with a real fallback: previously an
        // unset scheduledAt (or an optimization that returned null for
        // insufficient evidence) left the campaign with no schedule and no
        // dispatcher — a silent no-op despite returning a recipient count.
        $effective = $scheduledAt ?? new \DateTimeImmutable();

        $campaign->setScheduledAt(\DateTime::createFromImmutable($effective));
        // Re-scheduling resets the claim: the previous schedule's
        // dispatch marker no longer applies.
        $campaign->setScheduledDispatchedAt(null);
        $this->entityManager->flush();

        return count($campaign->getContacts());
    }

    /**
     * Minimum opens required before historical engagement data is trusted to
     * pick an optimized send hour.
     */
    private const MIN_OPENS_FOR_OPTIMIZATION = 50;

    /**
     * Evidence-based optimal send time: the hour of day with the most
     * campaign-email opens in the last 90 days, projected onto the next
     * occurrence of that hour. Returns null when there is not enough
     * engagement history to justify a claim.
     */
    private function computeOptimalSendTime(): ?\DateTimeImmutable
    {
        try {
            $openedAtValues = $this->entityManager->createQueryBuilder()
                ->select('es.openedAt')
                ->from(EmailSend::class, 'es')
                ->where('es.openedAt IS NOT NULL')
                ->andWhere('es.openedAt > :since')
                ->setParameter('since', (new \DateTime())->modify('-90 days'))
                ->orderBy('es.openedAt', 'DESC')
                ->setMaxResults(1000)
                ->getQuery()
                ->getSingleColumnResult();
        } catch (\Throwable) {
            // Engagement history unavailable -> no defensible optimization
            // claim; schedule immediately instead of pretending.
            return null;
        }

        if (count($openedAtValues) < self::MIN_OPENS_FOR_OPTIMIZATION) {
            return null;
        }

        $hourHistogram = array_fill(0, 24, 0);
        foreach ($openedAtValues as $openedAt) {
            if ($openedAt instanceof \DateTimeInterface) {
                $hourHistogram[(int) $openedAt->format('G')]++;
            }
        }

        $bestHour = array_search(max($hourHistogram), $hourHistogram, true);

        $target = new \DateTimeImmutable('today +' . (int) $bestHour . ' hours');
        if ($target <= new \DateTimeImmutable()) {
            $target = $target->modify('+1 day');
        }

        return $target;
    }

    /**
     * Dispatch every due scheduled campaign (scheduledAt <= now).
     *
     * Cron/worker fallback for queues that do not support delayed delivery
     * or missed delayed messages after downtime. Idempotent: sendToContact
     * de-duplicates per (campaign, contact, touch).
     *
     * @return array{campaign_id: int, queued: int}[]
     */
    public function dispatchDueCampaigns(): array
    {
        $dispatched = [];

        $this->entityManager->wrapInTransaction(function () use (&$dispatched): void {
            $now = new \DateTimeImmutable();

            // Claim due campaigns with a REAL database lock: a plain
            // SELECT-inside-transaction still lets two concurrent workers
            // both read scheduled_dispatched_at IS NULL before either
            // commits. FOR UPDATE SKIP LOCKED makes each worker claim a
            // disjoint set of rows; the loser simply never sees rows the
            // winner holds. Archived/inactive campaigns are excluded before
            // any queue work is created for them.
            // ( scheduledAt is preserved unchanged for audit. )
            $dueIds = $this->entityManager->getConnection()->fetchFirstColumn(
                'SELECT id FROM email_campaigns
                 WHERE scheduled_at IS NOT NULL
                   AND scheduled_at <= :now
                   AND scheduled_dispatched_at IS NULL
                   AND active = 1
                   AND archived_at IS NULL
                 ORDER BY scheduled_at
                 FOR UPDATE SKIP LOCKED',
                ['now' => $now->format('Y-m-d H:i:s')]
            );

            if ($dueIds === []) {
                return;
            }

            /** @var list<EmailCampaign> $due */
            $due = $this->entityManager->createQueryBuilder()
                ->select('c')
                ->from(EmailCampaign::class, 'c')
                ->where('c.id IN (:ids)')
                ->setParameter('ids', $dueIds)
                ->getQuery()
                ->getResult();

            foreach ($due as $campaign) {
                $campaign->setScheduledDispatchedAt(new \DateTime());
            }

            $this->entityManager->flush();

            foreach ($due as $campaign) {
                $recipientIds = array_map(
                    static fn (Contact $contact) => $contact->getId(),
                    $campaign->getContacts()->toArray()
                );

                if ($recipientIds === []) {
                    $dispatched[] = ['campaign_id' => (int) $campaign->getId(), 'queued' => 0];
                    continue;
                }

                $this->messageBus->dispatch(
                    new EmailCampaignMessage((int) $campaign->getId(), $recipientIds, 1, 1)
                );

                $dispatched[] = ['campaign_id' => (int) $campaign->getId(), 'queued' => count($recipientIds)];
            }
        });

        return $dispatched;
    }

    /**
     * Process a campaign and send to all enrolled contacts.
     *
     * Note: This uses the existing EmailCampaignService which creates EmailSend
     * records immediately when sending.
     */
    public function processCampaign(EmailCampaign $campaign): int
    {
        $contacts = $campaign->getContacts();

        $emailsSent = 0;
        foreach ($contacts as $contact) {
            // Eligibility (unsubscribe/bounce/cadence/archival) is enforced
            // inside sendToContact via EmailSendPolicy for every sender.
            // Default to first touch when using scheduler-style processing.
            // A single contact's failure must not abort the batch.
            try {
                $result = $this->campaignService->sendToContact($campaign, $contact, 1);
                if ($result->outcome === \App\Service\CampaignSendResult::SENT) {
                    $emailsSent++;
                }
            } catch (\Throwable $e) {
                $this->logger?->error('Campaign send failed for contact — continuing with batch', [
                    'campaign_id' => $campaign->getId(),
                    'contact_id' => $contact->getId(),
                    'contact_email' => $contact->getEmail(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $emailsSent;
    }

}
