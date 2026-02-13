<?php

namespace App\Command;

use App\Repository\CompetitorRepository;
use App\Repository\CompetitorChangeEventRepository;
use App\Service\CompCrawler\CompIntelSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:compcrawl-audit',
    description: 'Audit the CompCrawler database: stats, data quality, coverage',
)]
class CompCrawlAuditCommand extends Command
{
    public function __construct(
        private readonly CompetitorRepository $competitorRepo,
        private readonly CompetitorChangeEventRepository $changeEventRepo,
        private readonly CompIntelSyncService $intelSync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('export-blocklist', null, InputOption::VALUE_NONE, 'Export flat block list for LeadCrawler')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Output format: table, json', 'table')
            ->setHelp(<<<'HELP'
Audit the CompCrawler competitor intelligence database.

Examples:
  php bin/console app:compcrawl-audit                  # Full audit report
  php bin/console app:compcrawl-audit --export-blocklist  # Export block domains
  php bin/console app:compcrawl-audit -f json          # JSON output
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🔍 CompCrawler Audit Report');

        // Export block list mode
        if ($input->getOption('export-blocklist')) {
            return $this->exportBlockList($io, $input->getOption('format'));
        }

        // Dashboard stats
        $stats = $this->competitorRepo->getDashboardStats();

        $io->section('Overview');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Total Competitors', $stats['total']],
                ['Active (verified+monitoring)', $stats['active']],
                ['Candidates (pending)', $stats['candidates']],
                ['Rejected', $stats['rejected']],
                ['Avg Threat Score', round($stats['avg_threat'] ?? 0, 1)],
                ['Avg Overlap Score', round($stats['avg_overlap'] ?? 0, 1)],
                ['High-Threat (≥70)', $stats['high_threat']],
            ]
        );

        // Type distribution
        if (!empty($stats['type_distribution'])) {
            $io->section('Type Distribution');
            $typeRows = [];
            foreach ($stats['type_distribution'] as $type => $count) {
                $typeRows[] = [$type, $count];
            }
            $io->table(['Competitor Type', 'Count'], $typeRows);
        }

        // Recent change events
        $io->section('Recent Change Events (last 30 days)');
        $recentEvents = $this->changeEventRepo->findRecent(20);
        if (empty($recentEvents)) {
            $io->text('No change events in the last 30 days.');
        } else {
            $eventRows = [];
            foreach ($recentEvents as $event) {
                $eventRows[] = [
                    $event->getCompetitor()->getCanonicalDomain(),
                    $event->getChangeType(),
                    $event->getSeverity(),
                    $event->getDiffSummary() ?? '',
                    $event->getCreatedAt()->format('Y-m-d H:i'),
                ];
            }
            $io->table(['Domain', 'Type', 'Severity', 'Description', 'Detected'], $eventRows);
        }

        // Data quality check
        $io->section('Data Quality');
        $quality = $this->assessDataQuality();
        $qualityRows = [];
        foreach ($quality as $check => $result) {
            $qualityRows[] = [$check, $result['status'], $result['detail']];
        }
        $io->table(['Check', 'Status', 'Detail'], $qualityRows);

        // Top threats
        $io->section('Top 10 Threats');
        $topThreats = $this->competitorRepo->findFiltered([], 'threatScore', 'DESC', 10);
        if (!empty($topThreats)) {
            $threatRows = [];
            foreach ($topThreats as $c) {
                $threatRows[] = [
                    $c->getName(),
                    $c->getCanonicalDomain(),
                    $c->getThreatScore(),
                    $c->getOverlapScore(),
                    implode(', ', array_slice($c->getCompetitorTypes(), 0, 2)),
                    $c->getHqCountry() ?? '—',
                ];
            }
            $io->table(['Company', 'Domain', 'Threat', 'Overlap', 'Types', 'Country'], $threatRows);
        }

        // Coverage by verified domains
        $allDomains = $this->competitorRepo->getAllVerifiedDomains();
        $io->section('Coverage');
        $io->text("Total verified domains in block list: " . count($allDomains));

        return Command::SUCCESS;
    }

    private function assessDataQuality(): array
    {
        $checks = [];

        // Check: competitors without certifications
        $noCerts = count($this->competitorRepo->findBy(['status' => 'monitoring']));
        $totalActive = count($this->competitorRepo->findActive());
        $withCerts = 0;
        foreach ($this->competitorRepo->findActive() as $c) {
            if (!empty($c->getCertifications())) $withCerts++;
        }
        $certCoverage = $totalActive > 0 ? round(($withCerts / $totalActive) * 100) : 0;
        $checks['Certification coverage'] = [
            'status' => $certCoverage > 50 ? '✅' : '⚠️',
            'detail' => "{$withCerts}/{$totalActive} ({$certCoverage}%) have certifications",
        ];

        // Check: competitors without capabilities
        $withCaps = 0;
        foreach ($this->competitorRepo->findActive() as $c) {
            if (!empty($c->getCapabilities())) $withCaps++;
        }
        $capCoverage = $totalActive > 0 ? round(($withCaps / $totalActive) * 100) : 0;
        $checks['Capability coverage'] = [
            'status' => $capCoverage > 50 ? '✅' : '⚠️',
            'detail' => "{$withCaps}/{$totalActive} ({$capCoverage}%) have capabilities",
        ];

        // Check: stale competitors (not crawled in 30 days)
        $stale = 0;
        $threshold = new \DateTime('-30 days');
        foreach ($this->competitorRepo->findActive() as $c) {
            if (!$c->getLastCrawledAt() || $c->getLastCrawledAt() < $threshold) $stale++;
        }
        $checks['Stale profiles'] = [
            'status' => $stale === 0 ? '✅' : '⚠️',
            'detail' => "{$stale} competitors not crawled in 30+ days",
        ];

        // Check: unscored competitors
        $unscored = 0;
        foreach ($this->competitorRepo->findActive() as $c) {
            if ($c->getThreatScore() === 0 && $c->getOverlapScore() === 0) $unscored++;
        }
        $checks['Unscored competitors'] = [
            'status' => $unscored === 0 ? '✅' : '⚠️',
            'detail' => "{$unscored} active competitors with no scores",
        ];

        return $checks;
    }

    private function exportBlockList(SymfonyStyle $io, string $format): int
    {
        $blockList = $this->intelSync->exportFlatBlockList();

        if ($format === 'json') {
            $io->writeln(json_encode($blockList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $io->section('Blocked Domains (' . count($blockList['blocked_domains']) . ')');
            foreach ($blockList['blocked_domains'] as $domain) {
                $io->text("  - {$domain}");
            }

            $io->section('Competitor Phrases (' . count($blockList['competitor_phrases']) . ')');
            foreach ($blockList['competitor_phrases'] as $phrase) {
                $io->text("  - {$phrase}");
            }
        }

        return Command::SUCCESS;
    }
}
