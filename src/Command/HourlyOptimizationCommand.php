<?php

namespace App\Command;

use App\Service\HourlyOptimizationService;
use App\Service\AutonomousSalesSettingsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Psr\Log\LoggerInterface;

/**
 * Hourly Optimization Command
 *
 * Runs the one-hour automated testing and improvement loop.
 * Designed for cron scheduling (every hour) or manual invocation.
 *
 * Usage:
 *   php bin/console app:hourly-optimize                      # Full cycle
 *   php bin/console app:hourly-optimize --dry-run             # Evaluate only, no DB changes
 *   php bin/console app:hourly-optimize --limit=25            # Cap sends at 25
 *   php bin/console app:hourly-optimize --report              # Show last cycle report
 *
 * Cron:
 *   0 * * * * cd /path/to/project && php bin/console app:hourly-optimize --limit=50
 */
#[AsCommand(
    name: 'app:hourly-optimize',
    description: 'Run the one-hour automated testing and improvement loop',
)]
class HourlyOptimizationCommand extends Command
{
    public function __construct(
        private HourlyOptimizationService $hourlyOptimizer,
        private AutonomousSalesSettingsService $settingsService,
        private LockFactory $lockFactory,
        private ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Evaluate and report without making changes')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max sends per cycle', 50)
            ->addOption('report', null, InputOption::VALUE_NONE, 'Show a summary of what the cycle would do')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Hourly Optimization Cycle');

        if (!$this->settingsService->isEnabled()) {
            $io->warning('Autonomous sales system is disabled. Enable it to run the hourly optimizer.');
            return Command::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');
        $reportOnly = (bool) $input->getOption('report');

        if ($dryRun || $reportOnly) {
            $io->note('Running in DRY-RUN mode -- no database changes will be made.');
        }

        // Lock to prevent overlapping cycles
        $lock = $this->lockFactory->createLock('hourly_optimize', 3600);
        if (!$lock->acquire()) {
            $io->warning('Another hourly cycle is already running. Skipping.');
            return Command::SUCCESS;
        }

        try {
            $report = $this->hourlyOptimizer->runHourlyCycle($dryRun || $reportOnly, $limit);
            $this->renderReport($io, $report);

            $hasErrors = false;
            foreach ($report['stages'] ?? [] as $stageIndex => $stage) {
                if (!empty($stage['error']) || !empty($stage['errors'])) {
                    $hasErrors = true;
                    break;
                }
            }

            return $hasErrors ? Command::FAILURE : Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Hourly cycle failed: ' . $e->getMessage());
            $this->logger?->error('Hourly cycle failed', ['exception' => $e]);
            return Command::FAILURE;
        } finally {
            $lock->release();
        }
    }

    /**
     * Render the full cycle report to the console.
      * @param array<string|int, mixed> $report
     */
    private function renderReport(SymfonyStyle $io, array $report): void
    {
        $io->section('Cycle Summary');
        $io->definitionList(
            ['Cycle ID' => $report['cycleId']],
            ['Started' => $report['startedAt']],
            ['Duration' => ($report['duration'] ?? 0) . 's'],
            ['Dry Run' => $report['dryRun'] ? 'Yes' : 'No'],
        );

        if (isset($report['error'])) {
            $io->error('Cycle error: ' . $report['error']);
        }

        // Stage 0 - Baseline
        if (isset($report['stages'][0])) {
            $io->section('Stage 0 - Baseline Arms');
            foreach ($report['stages'][0]['armTypes'] ?? [] as $type => $info) {
                $io->text(sprintf(
                    '  [%s] Control: %s (rate: %.2f%%) | Total arms: %d',
                    $type,
                    $info['controlArmName'] ?? 'none',
                    ($info['controlExpectedRate'] ?? 0) * 100,
                    $info['totalArms']
                ));
            }
        }

        // Stage 1 - Snapshot
        if (isset($report['stages'][1])) {
            $gs = $report['stages'][1]['globalStats'];
            $io->section('Stage 1 - Snapshot');
            $io->text(sprintf(
                '  Arms: %d | Trials: %d | Successes: %d | Global rate: %.2f%% | Quarantined: %d',
                $gs['totalArms'],
                $gs['totalTrials'],
                $gs['totalSuccesses'],
                ($gs['globalExpectedRate'] ?? 0) * 100,
                $gs['quarantinedCount']
            ));
        }

        // Stage 2 - Candidates
        if (isset($report['stages'][2]) && !isset($report['stages'][2]['skipped'])) {
            $io->section('Stage 2 - Candidate Selection');
            foreach ($report['stages'][2]['exploitation'] ?? [] as $type => $arms) {
                $io->text(sprintf('  [%s] Exploitation pool: %d | Exploration pool: %d',
                    $type,
                    count($arms),
                    count($report['stages'][2]['exploration'][$type] ?? [])
                ));
            }
        }

        // Stage 3 - Gates
        if (isset($report['stages'][3])) {
            $s3 = $report['stages'][3];
            $io->section('Stage 3 - Personalization & QA Gates');
            if ($s3['dryRun'] ?? false) {
                $io->text('  [DRY RUN] Would compose up to ' . ($s3['wouldComposeUpTo'] ?? '?') . ' messages');
            } else {
                $gr = $s3['gateResults'] ?? [];
                $io->text(sprintf(
                    '  Passed: %d | Lint blocked: %d | Cadence blocked: %d | Errors: %d',
                    $gr['passed'] ?? 0,
                    $gr['lintBlocked'] ?? 0,
                    $gr['cadenceBlocked'] ?? 0,
                    $gr['errors'] ?? 0
                ));
            }
        }

        // Stage 4 - Execution
        if (isset($report['stages'][4])) {
            $s4 = $report['stages'][4];
            $io->section('Stage 4 - Execution Window');
            if ($s4['dryRun'] ?? false) {
                $io->text('  [DRY RUN] ' . ($s4['skipped'] ?? 'No sends'));
            } else {
                $io->text(sprintf('  Sent: %d | Failed: %d | Total: %d',
                    $s4['sent'] ?? 0,
                    $s4['failed'] ?? 0,
                    $s4['total'] ?? 0
                ));
            }
        }

        // Stage 5 - Evaluation
        if (isset($report['stages'][5])) {
            $io->section('Stage 5 - Hourly Evaluation');
            foreach ($report['stages'][5] as $type => $typeEval) {
                if (isset($typeEval['error'])) {
                    $io->text("  [{$type}] {$typeEval['error']}");
                    continue;
                }
                $io->text(sprintf('  [%s] Baseline rate: %.2f%%', $type, ($typeEval['baselineRate'] ?? 0) * 100));

                $rows = [];
                foreach ($typeEval['arms'] ?? [] as $armEval) {
                    $rows[] = [
                        $armEval['armName'],
                        sprintf('%.2f%%', ($armEval['expectedRate'] ?? 0) * 100),
                        sprintf('%.2fx', $armEval['relativePerformance'] ?? 0),
                        $armEval['totalTrials'],
                        strtoupper($armEval['verdict']),
                    ];
                }

                if (!empty($rows)) {
                    $io->table(['Arm', 'Rate', 'vs Baseline', 'Trials', 'Verdict'], $rows);
                }
            }
        }

        // Stage 6 - Safety
        if (isset($report['stages'][6])) {
            $s6 = $report['stages'][6];
            $io->section('Stage 6 - Safety Enforcement');
            $io->text(sprintf(
                '  System neg rate: %.2f%% | Safe mode: %s',
                ($s6['systemNegRate'] ?? 0) * 100,
                ($s6['safeModeActive'] ?? false) ? 'ACTIVE' : 'inactive'
            ));

            if (!empty($s6['instantKills'])) {
                $io->warning(sprintf('%d arm(s) instant-killed:', count($s6['instantKills'])));
                foreach ($s6['instantKills'] as $kill) {
                    $io->text(sprintf('    - %s (neg rate: %.1f%%)', $kill['armName'], ($kill['recentNegRate'] ?? 0) * 100));
                }
            }
            if (!empty($s6['underperformers'])) {
                $io->text(sprintf('  Underperformers: %d', count($s6['underperformers'])));
            }
        }

        // Stage 7 - Prune & Promote
        if (isset($report['stages'][7])) {
            $s7 = $report['stages'][7];
            $io->section('Stage 7 - Pruning & Promotion');
            $io->text(sprintf('  Pruned: %d | Promoted: %d', $s7['pruneCount'] ?? 0, $s7['promoteCount'] ?? 0));

            foreach ($s7['promoted'] ?? [] as $p) {
                $io->text(sprintf('    + PROMOTED: %s (%.2fx baseline)', $p['armName'], $p['relativePerformance'] ?? 0));
            }
            foreach ($s7['pruned'] ?? [] as $p) {
                $io->text(sprintf('    - PRUNED:   %s (%.2fx baseline)', $p['armName'], $p['relativePerformance'] ?? 0));
            }
        }

        // Stage 8 - Refresh
        if (isset($report['stages'][8])) {
            $s8 = $report['stages'][8];
            $io->section('Stage 8 - Adaptive Refresh');
            $io->text(sprintf('  Decayed: %d | Re-seeded: %d', $s8['decayedCount'] ?? 0, $s8['reseededCount'] ?? 0));
        }

        // Stage 9 - Assertions
        if (isset($report['stages'][9])) {
            $s9 = $report['stages'][9];
            $io->section('Stage 9 - Assertions');
            $allPassed = $s9['allPassed'] ?? false;
            if ($allPassed) {
                $io->text('  All invariant assertions PASSED.');
            } else {
                $io->warning('Some assertions FAILED:');
                foreach ($s9['assertions'] ?? [] as $name => $passed) {
                    if (!$passed) {
                        $io->text("    FAIL: {$name}");
                    }
                }
            }
        }

        // Stage 10 - Outcome
        if (isset($report['stages'][10])) {
            $s10 = $report['stages'][10];
            $io->newLine();

            $outcomeEmoji = match ($s10['outcome'] ?? 'hold') {
                'improve' => '[IMPROVE]',
                'rollback' => '[ROLLBACK]',
                default => '[HOLD]',
            };

            $io->section("Stage 10 - Guaranteed Outcome: {$outcomeEmoji}");
            $io->text('  ' . ($s10['reason'] ?? 'No reason provided.'));
        }

        $io->newLine();
        $io->success(sprintf(
            'Hourly optimization cycle completed in %.2fs.',
            $report['duration'] ?? 0
        ));
    }
}
