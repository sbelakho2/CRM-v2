<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WebCrawler\QualityGate\GoldenDatasetRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the golden dataset regression test from the CLI.
 *
 * Usage:
 *   php bin/console app:golden-dataset-regression
 *   php bin/console app:golden-dataset-regression --threshold=95
 *   php bin/console app:golden-dataset-regression --dataset=config/golden_dataset_v1.yaml
 *   php bin/console app:golden-dataset-regression --verbose   (shows per-gate breakdown)
 */
#[AsCommand(
    name: 'app:golden-dataset-regression',
    description: 'Run classification pipeline against the golden dataset and report accuracy',
)]
class GoldenDatasetRegressionCommand extends Command
{
    public function __construct(
        private GoldenDatasetRunner $runner,
        private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dataset',
                'd',
                InputOption::VALUE_REQUIRED,
                'Path to golden dataset YAML (relative to project root)',
                'config/golden_dataset_v1.yaml',
            )
            ->addOption(
                'threshold',
                't',
                InputOption::VALUE_REQUIRED,
                'Minimum accuracy percentage to pass (0-100)',
                '90',
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output results as JSON instead of human-readable table',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $datasetPath = $this->projectDir . '/' . $input->getOption('dataset');
        $threshold = ((int) $input->getOption('threshold')) / 100;
        /** @var mixed $jsonMode */
        $jsonMode = $input->getOption('json');

        $io->title('Golden Dataset Regression Test');
        $io->text("Dataset: $datasetPath");

        // ── Load ──────────────────────────────────────
        try {
            $this->runner->loadFromFile($datasetPath);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        // ── Run ───────────────────────────────────────
        $report = $this->runner->run();

        // ── JSON output ───────────────────────────────
        if ($jsonMode) {
            $output->writeln(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $report->meetsThreshold($threshold) ? Command::SUCCESS : Command::FAILURE;
        }

        // ── Human table ───────────────────────────────
        $io->section('Results');

        $rows = [];
        foreach ($report->getResults() as $r) {
            $status = $r['correct'] ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $rows[] = [
                $status,
                $r['name'],
                $r['domain'],
                $r['expected'],
                $r['actual'],
                $r['reject_gate'] ?? '—',
                $r['category'],
            ];
        }

        $io->table(
            ['', 'Name', 'Domain', 'Expected', 'Actual', 'Reject Gate', 'Category'],
            $rows,
        );

        // ── Verbose per-gate breakdown ────────────────
        if ($output->isVerbose()) {
            $io->section('Per-Gate Breakdown');
            foreach ($report->getResults() as $r) {
                $io->text(sprintf('<info>%s</info> (%s):', $r['name'], $r['domain']));
                foreach ($r['gates'] as $gate => $info) {
                    $icon = $info['passed'] ? '✓' : '✗';
                    $io->text(sprintf('  %s %s: %s', $icon, $gate, $info['detail']));
                }
                $io->newLine();
            }
        }

        // ── Summary ───────────────────────────────────
        $io->section('Summary');
        $io->text($report->toSummaryString());

        $pct = $report->getAccuracyPercent();
        $thresholdPct = $threshold * 100;

        if ($report->meetsThreshold($threshold)) {
            $io->success(sprintf(
                'PASSED — Accuracy %.1f%% meets threshold of %.0f%%',
                $pct,
                $thresholdPct,
            ));
            return Command::SUCCESS;
        }

        $io->error(sprintf(
            'FAILED — Accuracy %.1f%% below threshold of %.0f%%',
            $pct,
            $thresholdPct,
        ));

        return Command::FAILURE;
    }
}
