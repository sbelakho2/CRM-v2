<?php

namespace App\Command;

use App\Entity\EmailSend;
use App\Repository\EmailSendRepository;
use App\Service\CampaignSendResult;
use App\Service\EmailCampaignService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Executes DUE pre-scheduled EmailSend rows (drip sequences, triggered
 * emails, queued manual sends).
 *
 * Two-phase execution (audit rule: no external side effects while holding
 * database locks):
 *
 *   Phase 1 — SHORT claim transaction: atomically claim due rows (QUEUED
 *   and due, or retryable FAILED past their backoff) with
 *   FOR UPDATE SKIP LOCKED, mark them SENDING with a fresh lease, COMMIT.
 *
 *   Phase 2 — delivery OUTSIDE any transaction: each claimed row goes
 *   through the canonical sendExisting() path (policy-checked, idempotent,
 *   transport-synchronous), which performs its own per-row state commits.
 *
 *   SMTP is irreversible: a database transaction can never roll it back.
 *   Keeping claims committed before transport and state transitions
 *   committed per-row after transport is the closest database-only
 *   approximation of exactly-once; provider message-ID reconciliation is
 *   the remaining long-term step.
 */
#[AsCommand(
    name: 'app:email:process-due-sends',
    description: 'Claim and execute due scheduled EmailSend rows (drip/triggered/queued manual sends)',
)]
class ProcessDueEmailSendsCommand extends Command
{
    private const BATCH_SIZE = 100;
    private const LEASE_SECONDS = 900; // 15 minutes

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailSendRepository $sendRepository,
        private EmailCampaignService $campaignService,
        private LockFactory $lockFactory,
        private \App\Repository\WorkerHeartbeatRepository $heartbeatRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lock = $this->lockFactory->createLock('email-process-due-sends', 600);
        if (!$lock->acquire()) {
            $io->note('Another due-send worker is already active; skipping.');

            return Command::SUCCESS;
        }

        try {
            // ── Phase 1: short, lock-only claim transaction ────────────────
            $claimedIds = $this->entityManager->wrapInTransaction(
                function (): array {
                    $now = (new \DateTime())->format('Y-m-d H:i:s');
                    $leaseUntil = (new \DateTime())->modify('+' . self::LEASE_SECONDS . ' seconds')
                        ->format('Y-m-d H:i:s');

                    // Due now: QUEUED rows whose schedule arrived (or manual
                    // queue-now rows with no schedule), retryable FAILED rows
                    // whose exponential backoff has elapsed, AND crash-debris
                    // SENDING rows whose lease expired (a worker died mid-send;
                    // without this branch they would be stranded forever).
                    $dueIds = $this->entityManager->getConnection()->fetchFirstColumn(
                        'SELECT id FROM email_sends
                         WHERE (
                                (status = :queued
                                    AND (scheduled_at IS NULL OR scheduled_at <= :now)
                                    AND (send_lease_expires_at IS NULL OR send_lease_expires_at <= :now))
                             OR (status = :failed
                                    AND retry_count < :maxRetries
                                    AND next_attempt_at IS NOT NULL
                                    AND next_attempt_at <= :now)
                             OR (status = :sending
                                    AND send_lease_expires_at IS NOT NULL
                                    AND send_lease_expires_at <= :now)
                           )
                         ORDER BY scheduled_at
                         LIMIT ' . self::BATCH_SIZE . '
                         FOR UPDATE SKIP LOCKED',
                        [
                            'queued' => EmailSend::STATUS_QUEUED,
                            'failed' => EmailSend::STATUS_FAILED,
                            'now' => $now,
                            'maxRetries' => EmailCampaignService::MAX_SEND_ATTEMPTS,
                        ]
                    );

                    if ($dueIds === []) {
                        return [];
                    }

                    $this->entityManager->getConnection()->executeStatement(
                        'UPDATE email_sends
                         SET status = :sending, send_lease_expires_at = :lease
                         WHERE id IN (:ids)',
                        [
                            'sending' => EmailSend::STATUS_SENDING,
                            'lease' => $leaseUntil,
                            'ids' => $dueIds,
                        ],
                        ['ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
                    );

                    return $dueIds;
                }
            );

            if ($claimedIds === []) {
                // Beat on idle runs too: a worker that only heartbeats when
                // it has work looks dead to the health probe on quiet days.
                $this->heartbeatRepository->beat('email:process-due-sends', 'idle');
                $io->success('No due scheduled sends.');

                return Command::SUCCESS;
            }

            // ── Phase 2: delivery outside any transaction ──────────────────
            $counts = ['sent' => 0, 'already' => 0, 'skipped' => 0, 'failed' => 0];

            // Fresh rows (the claim transaction committed; identity map is
            // stale relative to the SENDING transition).
            $this->entityManager->clear();
            /** @var list<\App\Entity\EmailSend> $rows */
            $rows = $this->sendRepository->createQueryBuilder('e')
                ->andWhere('e.id IN (:ids)')
                ->setParameter('ids', $claimedIds)
                ->getQuery()
                ->getResult();

            foreach ($rows as $send) {
                $result = $this->campaignService->sendExisting($send, alreadyClaimedByWorker: true);

                match ($result->outcome) {
                    CampaignSendResult::SENT => $counts['sent']++,
                    CampaignSendResult::ALREADY_SENT, CampaignSendResult::ALREADY_IN_PROGRESS => $counts['already']++,
                    'skipped' => $counts['skipped']++,
                    default => $counts['failed']++,
                };
            }

            $io->success(sprintf(
                'Processed %d due send(s): %d sent, %d skipped by policy, %d already handled, %d failed (retryable with backoff).',
                is_array($claimedIds) ? count($claimedIds) : 0,
                $counts['sent'],
                $counts['skipped'],
                $counts['already'],
                $counts['failed']
            ));
            $this->heartbeatRepository->beat('email:process-due-sends', sprintf(
                'sent=%d skipped=%d failed=%d',
                $counts['sent'],
                $counts['skipped'],
                $counts['failed']
            ));
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
