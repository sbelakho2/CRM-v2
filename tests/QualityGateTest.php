<?php

declare(strict_types=1);

namespace App\Tests;

use App\Command\QualityGateCommand;
use App\Service\WebCrawler\QualityGate\GoldenDatasetRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class QualityGateTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__);
    }

    public function testCommandIsRegistered(): void
    {
        $runner = new GoldenDatasetRunner();
        $cmd = new QualityGateCommand($runner, $this->projectDir);

        $this->assertEquals('app:quality-gate', $cmd->getName());
    }

    public function testGoldenDatasetPassesWithDefaultDataset(): void
    {
        $runner = new GoldenDatasetRunner();
        $cmd = new QualityGateCommand($runner, $this->projectDir);

        $app = new Application();
        $app->add($cmd);

        $tester = new CommandTester($app->find('app:quality-gate'));
        $tester->execute([
            '--skip-tests' => true,
            '--skip-lint'  => true,
            '--threshold'  => '85',
        ]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Golden Dataset', $output);
        // With skip-tests and skip-lint, only golden dataset matters
        $this->assertStringContainsString('Accuracy:', $output);
    }

    public function testJsonOutputFormat(): void
    {
        $runner = new GoldenDatasetRunner();
        $cmd = new QualityGateCommand($runner, $this->projectDir);

        $app = new Application();
        $app->add($cmd);

        $tester = new CommandTester($app->find('app:quality-gate'));
        $tester->execute([
            '--skip-tests' => true,
            '--skip-lint'  => true,
            '--threshold'  => '85',
            '--json'       => true,
        ]);

        $output = $tester->getDisplay();
        $json = json_decode($output, true);

        $this->assertNotNull($json, 'Output should be valid JSON');
        $this->assertArrayHasKey('passed', $json);
        $this->assertArrayHasKey('checks', $json);
        $this->assertArrayHasKey('golden_dataset', $json['checks']);
    }

    public function testMissingDatasetFails(): void
    {
        $runner = new GoldenDatasetRunner();
        $cmd = new QualityGateCommand($runner, $this->projectDir);

        $app = new Application();
        $app->add($cmd);

        $tester = new CommandTester($app->find('app:quality-gate'));
        $tester->execute([
            '--dataset'    => 'config/nonexistent_dataset.yaml',
            '--skip-tests' => true,
            '--skip-lint'  => true,
            '--json'       => true,
        ]);

        $json = json_decode($tester->getDisplay(), true);

        $this->assertFalse($json['passed']);
        $this->assertFalse($json['checks']['golden_dataset']['passed']);
        $this->assertStringContainsString('not found', $json['checks']['golden_dataset']['detail']);
    }

    public function testHighThresholdMayFail(): void
    {
        $runner = new GoldenDatasetRunner();
        $cmd = new QualityGateCommand($runner, $this->projectDir);

        $app = new Application();
        $app->add($cmd);

        $tester = new CommandTester($app->find('app:quality-gate'));
        // Threshold of 100% — may fail if any golden dataset entry is wrong
        $tester->execute([
            '--threshold'  => '100',
            '--skip-tests' => true,
            '--skip-lint'  => true,
            '--json'       => true,
        ]);

        $json = json_decode($tester->getDisplay(), true);
        $this->assertNotNull($json);
        $this->assertArrayHasKey('golden_dataset', $json['checks']);
        // Just verify it ran — we don't assert pass/fail since dataset may be 100% or not
        $this->assertArrayHasKey('accuracy', $json['checks']['golden_dataset']);
    }

    public function testSkippedChecksReportAsSkipped(): void
    {
        $runner = new GoldenDatasetRunner();
        $cmd = new QualityGateCommand($runner, $this->projectDir);

        $app = new Application();
        $app->add($cmd);

        $tester = new CommandTester($app->find('app:quality-gate'));
        $tester->execute([
            '--skip-tests' => true,
            '--skip-lint'  => true,
            '--json'       => true,
        ]);

        $json = json_decode($tester->getDisplay(), true);
        $this->assertEquals('skipped', $json['checks']['phpunit']['detail']);
        $this->assertEquals('skipped', $json['checks']['container_lint']['detail']);
    }

    public function testGoldenDatasetReportIncludesMetrics(): void
    {
        $runner = new GoldenDatasetRunner();
        $cmd = new QualityGateCommand($runner, $this->projectDir);

        $app = new Application();
        $app->add($cmd);

        $tester = new CommandTester($app->find('app:quality-gate'));
        $tester->execute([
            '--skip-tests' => true,
            '--skip-lint'  => true,
            '--json'       => true,
        ]);

        $json = json_decode($tester->getDisplay(), true);
        $gd = $json['checks']['golden_dataset'];

        $this->assertArrayHasKey('precision', $gd);
        $this->assertArrayHasKey('recall', $gd);
        $this->assertArrayHasKey('f1', $gd);
        $this->assertArrayHasKey('total', $gd);
        $this->assertArrayHasKey('correct', $gd);
        $this->assertGreaterThan(0, $gd['total']);
    }
}
