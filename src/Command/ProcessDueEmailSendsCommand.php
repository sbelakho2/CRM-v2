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
 * emails): status = queued AND scheduled_at <= now.
 *
 * This is the missing consumer for the EmailSend.scheduledAt model: rows
 * were created by drip progression / trigger scheduling but nothing
 * processed them. Rows are claimed with FOR UPDATE SKIP LOCKED and a
 * delivery lease, then executed through the canonical sendExisting() path
 * (policy-checked, idempotent, transport-synchronous).
 */
#[AsCommand(
    name: 'app:email:process-due-sends',
    description: 'Claim and execute due scheduled EmailSend rows (drip/triggered sequences)',
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
            $counts = ['sent' => 0, 'already' => 0, 'skipped' => 0, 'failed' => 0];

            $this->entityManager->wrapInTransaction(function () use (&$counts, $io): void {
                $now = new \DateTime();

                // Atomic claim: lock disjoint due rows; lease them so a crash
                // mid-run leaves recoverable debris instead of lost work.
                $dueIds = $this->entityManager->getConnection()->fetchFirstColumn(
                    'SELECT id FROM email_sends
                     WHERE status = :queued
                       AND scheduled_at IS NOT NULL
                       AND scheduled_at <= :now
                       AND (send_lease_expires_at IS NULL OR send_lease_expires_at <= :now)
                     ORDER BY scheduled_at
                     LIMIT ' . self::BATCH_SIZE . '
                     FOR UPDATE SKIP LOCKED',
                    ['queued' => EmailSend::STATUS_QUEUED, 'now' => $now]
                );

                if ($dueIds === []) {
                    return;
                }

                $leaseUntil = (new \DateTime())->modify('+' . self::LEASE_SECONDS . ' seconds');
                $this->entityManager->getConnection()->executeStatement(
                    'UPDATE email_sends SET send_lease_expires_at = :lease WHERE id IN (:ids)',
                    ['lease' => $leaseUntil, 'ids' => $dueIds],
                    ['ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
                );

                $rows = $this->sendRepository->createQueryBuilder('e')
                    ->andWhere('e.id IN (:ids)')
                    ->setParameter('ids', $dueIds)
                    ->getQuery()
                    ->getResult();

                foreach ($rows as $send) {
                    $result = $this->campaignService->sendExisting($send);
                    $counts[$result->outcome === CampaignSendResult::ALREADY_SENT
                        || $result->outcome === CampaignSendResult::ALREADY_IN_PROGRESS
                        ? 'already'
                        : ($result->outcome === 'skipped' ? 'skipped' : ($result->outcome === CampaignSendResult::FAILED ? 'failed' : 'sent'))]++;
                }
            });

            $total = array_sum($counts);
            if ($total === 0) {
                $io->success('No due scheduled sends.');
            } else {
                $io->success(sprintf(
                    'Processed %d due send(s): %d sent, %d skipped by policy, %d already handled, %d failed.',
                    $total,
                    $counts['sent'],
                    $counts['skipped'],
                    $counts['already'],
                    $counts['failed']
                ));
            }
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
