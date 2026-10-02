<?php

namespace App\Command;

use App\Entity\User;
use App\Service\NotificationService;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:check-notifications',
    description: 'Check for new notifications for all or specific user',
)]
class CheckNotificationsCommand extends Command
{
    public function __construct(
        private NotificationService $notificationService,
        private UserRepository $userRepository,
        private LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', 'u', InputOption::VALUE_OPTIONAL, 'User ID to check notifications for')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check all users')
            ->addOption('cleanup', 'c', InputOption::VALUE_NONE, 'Clean up old notifications (>7 days)')
            ->setHelp(<<<'HELP'
This command checks for new notifications for users.

Examples:
  # Check notifications for user ID 1
  php bin/console app:check-notifications --user=1

  # Check notifications for all users
  php bin/console app:check-notifications --all

  # Clean up old notifications
  php bin/console app:check-notifications --cleanup

  # Combine: check all users AND cleanup
  php bin/console app:check-notifications --all --cleanup
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Notification Checker');

        $lock = $this->lockFactory->createLock('check_notifications', 300);
        if (!$lock->acquire()) {
            $io->warning('Another notification check is already running. Skipping.');
            return Command::FAILURE;
        }

        try {
            $cleanup = (bool) $input->getOption('cleanup');
            $userIdOption = $input->getOption('user');
            $userId = \is_string($userIdOption) && $userIdOption !== '' ? $userIdOption : null;
            $checkAll = (bool) $input->getOption('all');

            $totalCreated = 0;

            if (!$userId && !$checkAll && !$cleanup) {
                $io->error('Please specify --user=<id>, --all, or --cleanup');
                return Command::FAILURE;
            }

            // Cleanup old notifications
            if ($cleanup) {
                $io->section('Cleaning up old notifications');
                $deleted = $this->notificationService->cleanupOldNotifications();
                $io->success("Deleted {$deleted} notifications older than 7 days");
            }

            // Check all users
            if ($checkAll) {
                $io->section('Checking notifications for all users');
                $userCount = (int) $this->userRepository->createQueryBuilder('u')
                    ->select('COUNT(u.id)')
                    ->getQuery()
                    ->getSingleScalarResult();
                /** @var iterable<array{0: User}> $users */
                $users = $this->userRepository->createQueryBuilder('u')
                    ->getQuery()
                    ->iterate();
                $io->progressStart($userCount);

                foreach ($users as $row) {
                    $user = $row[0];
                    $rfqCount = $this->notificationService->checkRFQDeadlines($user);
                    $emailCount = $this->notificationService->checkEmailReplies($user);
                    $leadCount = $this->notificationService->checkLeadApprovals($user);
                    $quoteCount = $this->notificationService->checkQuoteViews($user);

                    $userTotal = $rfqCount + $emailCount + $leadCount + $quoteCount;
                    if ($userTotal > 0) {
                        $io->note("User {$user->getId()}: {$userTotal} notifications created");
                    }
                    $totalCreated += $userTotal;

                    $io->progressAdvance();
                }

                $io->progressFinish();
            }

            // Check specific user
            if ($userId) {
                $io->section("Checking notifications for user {$userId}");
                $user = $this->userRepository->find($userId);

                if (!$user) {
                    $io->error("User {$userId} not found");
                    return Command::FAILURE;
                }

                $io->progressStart(4);

                $rfqCount = $this->notificationService->checkRFQDeadlines($user);
                $io->progressAdvance();
                $io->writeln("  RFQ deadlines: {$rfqCount} created");

                $emailCount = $this->notificationService->checkEmailReplies($user);
                $io->progressAdvance();
                $io->writeln("  Email replies: {$emailCount} created");

                $leadCount = $this->notificationService->checkLeadApprovals($user);
                $io->progressAdvance();
                $io->writeln("  Lead approvals: {$leadCount} created");

                $quoteCount = $this->notificationService->checkQuoteViews($user);
                $io->progressAdvance();
                $io->writeln("  Quote views: {$quoteCount} created");

                $io->progressFinish();
                $totalCreated = $rfqCount + $emailCount + $leadCount + $quoteCount;
            }

            $io->success("Total notifications created: {$totalCreated}");

            return Command::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
