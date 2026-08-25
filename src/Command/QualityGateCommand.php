<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WebCrawler\Observability\PipelineMetricsCollector;
use App\Service\WebCrawler\QualityGate\GoldenDatasetRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Pre-deployment quality gate that validates:
 *
 *   1. Golden-dataset regression (classification accuracy ≥ threshold)
 *   2. PHPUnit test suite (all tests pass)
 *   3. Container lint (services wired correctly)
 *
 * Designed to run in CI or before `git push`.
 *
 *   php bin/console app:quality-gate
 *   php bin/console app:quality-gate --skip-tests --threshold=85
 *   php bin/console app:quality-gate --json
 */
#[AsCommand(
    name: 'app:quality-gate',
    description: 'Pre-deployment quality gate: runs golden-dataset regression, tests, and container lint',
)]
class QualityGateCommand extends Command
{
    public function __construct(
        private GoldenDatasetRunner $goldenDatasetRunner,
        private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('threshold', 't', InputOption::VALUE_REQUIRED, 'Minimum golden-dataset accuracy (percent)', '90')
            ->addOption('dataset', 'd', InputOption::VALUE_REQUIRED, 'Path to golden-dataset YAML', 'config/golden_dataset_v1.yaml')
            ->addOption('skip-tests', null, InputOption::VALUE_NONE, 'Skip PHPUnit test execution')
            ->addOption('skip-lint', null, InputOption::VALUE_NONE, 'Skip container lint check')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output results as JSON')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $threshold = (int) $input->getOption('threshold');
        $datasetPath = $input->getOption('dataset');
        $skipTests = $input->getOption('skip-tests');
        $skipLint = $input->getOption('skip-lint');
        $jsonOutput = $input->getOption('json');

        $checks = [];
        $allPassed = true;

        // ─────────────────────────────────────────────
        // Check 1: Golden Dataset Regression
        // ─────────────────────────────────────────────
        if (!$jsonOutput) {
            $io->section('🧪 Check 1: Golden Dataset Regression');
        }

        $fullPath = str_starts_with($datasetPath, '/')
            ? $datasetPath
            : $this->projectDir . '/' . ltrim($datasetPath, '/');

        $projectDirReal = realpath($this->projectDir);
        $normalizedFullPath = $this->normalizePath($fullPath);

        // Containment check on the normalized absolute path (works even when
        // the file does not exist yet, which realpath() cannot resolve).
        if ($projectDirReal === false
            || !str_starts_with($normalizedFullPath, rtrim($this->normalizePath($projectDirReal), '/') . '/')
        ) {
            $checks['golden_dataset'] = [
                'passed' => false,
                'detail' => "Dataset path is outside the project directory: {$datasetPath}",
            ];
            $allPassed = false;
        } elseif (!file_exists($fullPath)) {
            $checks['golden_dataset'] = [
                'passed' => false,
                'detail' => "Dataset file not found: {$fullPath}",
            ];
            $allPassed = false;
        } else {
            try {
                $this->goldenDatasetRunner->loadFromFile($fullPath);
                $report = $this->goldenDatasetRunner->run();

                $passed = $report->meetsThreshold($threshold / 100);
                $checks['golden_dataset'] = [
                    'passed'    => $passed,
                    'accuracy'  => $report->getAccuracyPercent(),
                    'threshold' => $threshold,
                    'total'     => $report->getTotal(),
                    'correct'   => $report->getCorrect(),
                    'precision' => round($report->getPrecision() * 100, 1),
                    'recall'    => round($report->getRecall() * 100, 1),
                    'f1'        => round($report->getF1() * 100, 1),
                    'failures'  => array_map(fn($f) => [
                        'name'     => $f['name'],
                        'expected' => $f['expected'],
                        'actual'   => $f['actual'],
                        'gate'     => $f['reject_gate'] ?? 'none',
                    ], $report->getFailures()),
                ];

                if (!$passed) {
                    $allPassed = false;
                }

                if (!$jsonOutput) {
                    $io->text(sprintf(
                        'Accuracy: %.1f%% (threshold: %d%%) — %s',
                        $report->getAccuracyPercent(),
                        $threshold,
                        $passed ? '✅ PASS' : '❌ FAIL',
                    ));

                    if (!$passed && !empty($report->getFailures())) {
                        $io->text('Failures:');
                        foreach ($report->getFailures() as $f) {
                            $io->text(sprintf(
                                '  • %s — expected %s, got %s (gate: %s)',
                                $f['name'], $f['expected'], $f['actual'],
                                $f['reject_gate'] ?? 'n/a',
                            ));
                        }
                    }
                }
            } catch (\Throwable $e) {
                $checks['golden_dataset'] = [
                    'passed' => false,
                    'detail' => 'Exception: ' . $e->getMessage(),
                ];
                $allPassed = false;

                if (!$jsonOutput) {
                    $io->error('Golden dataset check failed: ' . $e->getMessage());
                }
            }
        }

        // ─────────────────────────────────────────────
        // Check 2: PHPUnit Tests
        // ─────────────────────────────────────────────
        if ($skipTests) {
            $checks['phpunit'] = ['passed' => true, 'detail' => 'skipped'];
        } else {
            if (!$jsonOutput) {
                $io->section('🧪 Check 2: PHPUnit Test Suite');
            }

            $phpunitResult = $this->runPhpUnit();
            $checks['phpunit'] = $phpunitResult;

            if (!$phpunitResult['passed']) {
                $allPassed = false;
            }

            if (!$jsonOutput) {
                $io->text(sprintf(
                    'Tests: %d, Assertions: %d — %s',
                    $phpunitResult['tests'] ?? 0,
                    $phpunitResult['assertions'] ?? 0,
                    $phpunitResult['passed'] ? '✅ PASS' : '❌ FAIL',
                ));

                if (!$phpunitResult['passed'] && !empty($phpunitResult['output'])) {
                    $io->text('Output (last 20 lines):');
                    $lines = explode("\n", $phpunitResult['output']);
                    $io->text(array_slice($lines, -20));
                }
            }
        }

        // ─────────────────────────────────────────────
        // Check 3: Container Lint
        // ─────────────────────────────────────────────
        if ($skipLint) {
            $checks['container_lint'] = ['passed' => true, 'detail' => 'skipped'];
        } else {
            if (!$jsonOutput) {
                $io->section('🧪 Check 3: Container Lint');
            }

            $lintResult = $this->runContainerLint();
            $checks['container_lint'] = $lintResult;

            if (!$lintResult['passed']) {
                $allPassed = false;
            }

            if (!$jsonOutput) {
                $io->text($lintResult['passed'] ? '✅ PASS' : '❌ FAIL');
                if (!$lintResult['passed'] && !empty($lintResult['output'])) {
                    $io->text($lintResult['output']);
                }
            }
        }

        // ─────────────────────────────────────────────
        // Summary
        // ─────────────────────────────────────────────
        if ($jsonOutput) {
            $output->writeln(json_encode([
                'passed' => $allPassed,
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $io->newLine();
            if ($allPassed) {
                $io->success('All quality gate checks PASSED ✅');
            } else {
                $io->error('Quality gate FAILED ❌');
                $failed = array_keys(array_filter($checks, fn($c) => !$c['passed']));
                $io->text('Failed checks: ' . implode(', ', $failed));
            }
        }

        return $allPassed ? Command::SUCCESS : Command::FAILURE;
    }

    // ──────────────────────────────────────────────────
    // Subprocess runners
    // ──────────────────────────────────────────────────

    private function runPhpUnit(): array
    {
        $phpunit = $this->projectDir . '/vendor/bin/phpunit';
        if (!file_exists($phpunit)) {
            return ['passed' => false, 'detail' => 'phpunit not found', 'tests' => 0, 'assertions' => 0];
        }

        $testDir = $this->projectDir . '/tests';

        // Discover all *Test.php files
        $testFiles = glob($testDir . '/*Test.php');
        if (empty($testFiles)) {
            return ['passed' => true, 'detail' => 'no test files found', 'tests' => 0, 'assertions' => 0];
        }

        $totalTests = 0;
        $totalAssertions = 0;
        $allPassed = true;
        $outputs = [];

        foreach ($testFiles as $file) {
            $process = new Process(['php', $phpunit, $file]);
            $process->run();
            $out = $process->getOutput() . $process->getErrorOutput();
            $code = $process->getExitCode();
            $outputs[] = basename($file) . ": " . ($code === 0 ? 'OK' : "FAIL (exit $code)");

            if ($code !== 0) {
                $allPassed = false;
            }

            // Parse "OK (X tests, Y assertions)"
            if (preg_match('/OK\s*\((\d+)\s+tests?,\s*(\d+)\s+assertions?\)/', $out, $m)) {
                $totalTests += (int) $m[1];
                $totalAssertions += (int) $m[2];
            }
        }

        return [
            'passed'     => $allPassed,
            'tests'      => $totalTests,
            'assertions' => $totalAssertions,
            'files'      => count($testFiles),
            'output'     => implode("\n", $outputs),
        ];
    }

    private function runContainerLint(): array
    {
        $console = $this->projectDir . '/bin/console';
        $process = new Process(['php', $console, 'lint:container']);
        $process->run();
        $out = $process->getOutput() . $process->getErrorOutput();
        $code = $process->getExitCode();

        return [
            'passed' => $code === 0,
            'output' => $out,
        ];
    }

    /**
     * Collapse ".", ".." and duplicate separators so containment checks work
     * on paths that do not exist on disk yet (realpath() would fail).
     */
    private function normalizePath(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        $normalized = implode('/', $parts);

        return $isAbsolute ? '/' . $normalized : $normalized;
    }
}
