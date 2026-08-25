<?php

namespace App\Command;

use App\Service\CadenceGovernorService;
use App\Service\ThompsonSamplerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Processes delayed soft failures for outbound messages past their reply
 * window (see CadenceGovernorService::processDelayedSoftFailures()).
 *
 * Designed for daily cron scheduling:
 *   0 4 * * * cd /path/to/project && php bin/console app:cadence-soft-failures
 *
 * Lock-protected so overlapping runs are skipped.
 */
#[AsCommand(
    name: 'app:cadence-soft-failures',
    description: 'Apply delayed soft-failure penalties to messages past their reply window (daily cron)',
)]
class CadenceSoftFailuresCommand extends Command
{
    public function __construct(
        private CadenceGovernorService $cadenceGovernor,
        private ThompsonSamplerService $sampler,
        private LockFactory $lockFactory,
        private ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
Processes delayed soft failures: finds outbound messages sent more than
CadenceGovernorService::REPLY_WINDOW_DAYS ago that never received a reply,
click or bounce, and applies a censored soft penalty (β += 0.30) to the
subject-line / value-prop Thompson arms.

Run daily via cron:
  0 4 * * * cd /path/to/project && php bin/console app:cadence-soft-failures
HELP
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Cadence: Delayed Soft Failures');

        $lock = $this->lockFactory->createLock('cadence_soft_failures', 3600);
        if (!$lock->acquire()) {
            $io->warning('Another soft-failure run is already in progress. Skipping.');
            return Command::SUCCESS;
        }

        try {
            $processed = $this->cadenceGovernor->processDelayedSoftFailures($this->sampler);

            if (count($processed) === 0) {
                $io->success('No messages past their reply window — nothing to do.');
                return Command::SUCCESS;
            }

            $io->section(sprintf('Applied soft failure to %d message(s)', count($processed)));
            $rows = [];
            foreach ($processed as $item) {
                $rows[] = [
                    $item['messageId'],
                    $item['contactId'] ?? 'N/A',
                    $item['armId'],
                    $item['sentAt'] ?? 'N/A',
                ];
            }
            $io->table(['Message ID', 'Contact ID', 'Arm ID', 'Sent At'], $rows);

            $io->success('Delayed soft failures processed.');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Soft-failure processing failed: ' . $e->getMessage());
            $this->logger?->error('Cadence soft-failure processing failed', ['exception' => $e]);
            return Command::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
