<?php

namespace App\Command;

use App\Repository\CompetitorChangeEventRepository;
use App\Repository\CompetitorRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:compcrawl-diff',
    description: 'Show recent competitor change events and profile diffs',
)]
class CompCrawlDiffCommand extends Command
{
    public function __construct(
        private readonly CompetitorChangeEventRepository $changeEventRepo,
        private readonly CompetitorRepository $competitorRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_OPTIONAL, 'Look back N days', '7')
            ->addOption('severity', 's', InputOption::VALUE_OPTIONAL, 'Filter by severity: low, medium, high')
            ->addOption('domain', 'd', InputOption::VALUE_OPTIONAL, 'Filter by competitor domain')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Filter by change type')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Max events to show', '50')
            ->setHelp(<<<'HELP'
Show recent competitor change events detected by the CompCrawler pipeline.

Examples:
  php bin/console app:compcrawl-diff                     # Last 7 days
  php bin/console app:compcrawl-diff --days=30           # Last 30 days
  php bin/console app:compcrawl-diff -s high             # High severity only
  php bin/console app:compcrawl-diff -d example.com      # Single competitor
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('📊 CompCrawler — Change Events');

        $days = (int) $input->getOption('days');
        $severity = $input->getOption('severity');
        $domain = $input->getOption('domain');
        $changeType = $input->getOption('type');
        $limit = (int) $input->getOption('limit');

        // If specific competitor
        if ($domain) {
            $competitor = $this->competitorRepo->findByDomain($domain);
            if (!$competitor) {
                $io->error("Competitor not found: {$domain}");
                return Command::FAILURE;
            }

            $events = $this->changeEventRepo->findByCompetitor($competitor->getId(), $limit);
            $io->section("Change history for {$competitor->getName()} ({$domain})");
        } else {
            $events = $this->changeEventRepo->findRecent($limit, null, $days);
            $io->section("Change events — last {$days} days");
        }

        // Filter by severity
        if ($severity) {
            $events = array_filter($events, fn($e) => $e->getSeverity() === $severity);
        }

        // Filter by type
        if ($changeType) {
            $events = array_filter($events, fn($e) => str_contains($e->getChangeType(), $changeType));
        }

        if (empty($events)) {
            $io->info('No change events found matching criteria.');
            return Command::SUCCESS;
        }

        // Summary counts
        $severityCounts = ['high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($events as $event) {
            $sev = $event->getSeverity();
            if (isset($severityCounts[$sev])) $severityCounts[$sev]++;
        }

        $io->text(sprintf(
            "Found %d events: 🔴 %d high | 🟡 %d medium | 🟢 %d low",
            count($events),
            $severityCounts['high'],
            $severityCounts['medium'],
            $severityCounts['low']
        ));
        $io->newLine();

        // Event table
        $rows = [];
        foreach ($events as $event) {
            $sevIcon = match ($event->getSeverity()) {
                'high' => '🔴',
                'medium' => '🟡',
                default => '🟢',
            };

            $rows[] = [
                $event->getCreatedAt()->format('Y-m-d H:i'),
                $sevIcon . ' ' . $event->getSeverity(),
                $event->getCompetitor()->getCanonicalDomain(),
                $event->getChangeType(),
                mb_substr($event->getDiffSummary() ?? '', 0, 60),
            ];
        }

        $io->table(['Date', 'Severity', 'Domain', 'Change Type', 'Description'], $rows);

        // Type distribution
        $typeCounts = [];
        foreach ($events as $event) {
            $t = $event->getChangeType();
            $typeCounts[$t] = ($typeCounts[$t] ?? 0) + 1;
        }
        arsort($typeCounts);

        $io->section('Change Type Distribution');
        foreach ($typeCounts as $t => $c) {
            $io->text("  {$t}: {$c}");
        }

        return Command::SUCCESS;
    }
}
