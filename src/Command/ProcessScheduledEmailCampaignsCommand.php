<?php

namespace App\Command;

use App\Service\EmailSchedulerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Dispatches every due scheduled email campaign through Messenger.
 *
 * Cron/worker fallback for delayed queue messages (and for delayed messages
 * missed during downtime). Send itself is idempotent per
 * (campaign, contact, touch), so repeated runs never duplicate customer
 * emails.
 */
#[AsCommand(
    name: 'app:email:process-scheduled',
    description: 'Dispatch due scheduled email campaigns to the Messenger queue',
)]
class ProcessScheduledEmailCampaignsCommand extends Command
{
    public function __construct(
        private EmailSchedulerService $scheduler,
        private LockFactory $lockFactory,
        private \App\Repository\WorkerHeartbeatRepository $heartbeatRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lock = $this->lockFactory->createLock('email-process-scheduled', 300);
        if (!$lock->acquire()) {
            $io->note('Another process-scheduled run is already active; skipping.');

            return Command::SUCCESS;
        }

        try {
            $dispatched = $this->scheduler->dispatchDueCampaigns();
            $this->heartbeatRepository->beat(
                'email:process-scheduled',
                $dispatched === [] ? 'idle' : sprintf('dispatched=%d', count($dispatched))
            );

            if ($dispatched === []) {
                $io->success('No due scheduled campaigns.');
            } else {
                foreach ($dispatched as $entry) {
                    $io->writeln(sprintf('Campaign %d: %d recipients queued.', $entry['campaign_id'], $entry['queued']));
                }
                $io->success(sprintf('Dispatched %d due campaign(s).', count($dispatched)));
            }
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
