<?php

namespace App\Command;

use App\Entity\Competitor;
use App\Repository\CompetitorRepository;
use App\Service\CompCrawler\CompChangeDetectorService;
use App\Service\CompCrawler\CompCrawlerConfig;
use App\Service\CompCrawler\CompDiscoveryService;
use App\Service\CompCrawler\CompEntityResolverService;
use App\Service\CompCrawler\CompExtractionService;
use App\Service\CompCrawler\CompIntelSyncService;
use App\Service\CompCrawler\CompProfileCrawlerService;
use App\Service\CompCrawler\CompScoringService;
use App\Service\CompCrawler\CompVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:compcrawl-run',
    description: 'Run the CompCrawler competitor intelligence pipeline',
)]
class CompCrawlRunCommand extends Command
{
    public function __construct(
        private readonly CompDiscoveryService $discovery,
        private readonly CompVerificationService $verification,
        private readonly CompProfileCrawlerService $crawler,
        private readonly CompExtractionService $extraction,
        private readonly CompEntityResolverService $resolver,
        private readonly CompScoringService $scoring,
        private readonly CompChangeDetectorService $changeDetector,
        private readonly CompIntelSyncService $intelSync,
        private readonly CompetitorRepository $competitorRepo,
        private readonly CompCrawlerConfig $config,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('phase', 'p', InputOption::VALUE_OPTIONAL, 'Run specific phase: discover, verify, crawl, extract, score, sync, all', 'all')
            ->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Competitor type: ems, machining, harness, supercapacitor')
            ->addOption('region', 'r', InputOption::VALUE_OPTIONAL, 'Region to focus on (e.g. morocco, eu_cee, gcc)')
            ->addOption('domain', 'd', InputOption::VALUE_OPTIONAL, 'Process a single domain')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Max competitors to process per phase', '50')
            ->addOption('deep', null, InputOption::VALUE_NONE, 'Run deep crawl instead of shallow')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without executing')
            ->setHelp(<<<'HELP'
CompCrawler: Competitor Discovery, Profiling & Change Intelligence Pipeline

Phases:
  discover    — Search for new competitor candidates via multilingual queries
  verify      — Verify candidates using type-specific evidence gates
  crawl       — Crawl verified competitor websites (shallow or deep)
  extract     — Extract structured profiles from crawled content
  score       — Compute threat/overlap/strategic relevance scores
  sync        — Push verified competitor intel to LeadCrawler block list
  all         — Run full pipeline (default)

Examples:
  php bin/console app:compcrawl-run                          # Full pipeline
  php bin/console app:compcrawl-run -p discover -t ems       # Discover EMS competitors
  php bin/console app:compcrawl-run -p crawl --deep          # Deep crawl due competitors
  php bin/console app:compcrawl-run -d example.com -p crawl  # Crawl single domain
  php bin/console app:compcrawl-run -p score                 # Re-score all competitors
  php bin/console app:compcrawl-run -p sync                  # Sync block list to LeadCrawler
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🔍 CompCrawler — Competitor Intelligence Pipeline');

        $phase = $input->getOption('phase');
        $type = $input->getOption('type');
        $region = $input->getOption('region');
        $domain = $input->getOption('domain');
        $limit = (int) $input->getOption('limit');
        $deep = $input->getOption('deep');
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $io->warning('DRY RUN mode — no changes will be persisted');
        }

        $startTime = microtime(true);
        $stats = ['discovered' => 0, 'verified' => 0, 'crawled' => 0, 'extracted' => 0, 'scored' => 0, 'synced' => 0];

        try {
            // Single domain mode
            if ($domain) {
                return $this->processSingleDomain($io, $domain, $phase, $deep, $dryRun);
            }

            // Phase execution
            if (in_array($phase, ['discover', 'all'])) {
                $stats['discovered'] = $this->runDiscover($io, $type, $region, $limit, $dryRun);
            }

            if (in_array($phase, ['verify', 'all'])) {
                $stats['verified'] = $this->runVerify($io, $limit, $dryRun);
            }

            if (in_array($phase, ['crawl', 'all'])) {
                $stats['crawled'] = $this->runCrawl($io, $deep, $limit, $dryRun);
            }

            if (in_array($phase, ['extract', 'all'])) {
                $stats['extracted'] = $this->runExtract($io, $limit, $dryRun);
            }

            if (in_array($phase, ['score', 'all'])) {
                $stats['scored'] = $this->runScore($io, $dryRun);
            }

            if (in_array($phase, ['sync', 'all'])) {
                $stats['synced'] = $this->runSync($io, $dryRun);
            }

        } catch (\Throwable $e) {
            $io->error("Pipeline error: {$e->getMessage()}");
            $io->text($e->getTraceAsString());
            return Command::FAILURE;
        }

        $elapsed = round(microtime(true) - $startTime, 1);

        $io->newLine();
        $io->success("CompCrawler pipeline complete in {$elapsed}s");
        $io->table(
            ['Phase', 'Count'],
            array_map(fn($k, $v) => [ucfirst($k), $v], array_keys($stats), array_values($stats))
        );

        return Command::SUCCESS;
    }

    // ─── Phase: Discover ───────────────────────────────────────────────────────

    private function runDiscover(SymfonyStyle $io, ?string $type, ?string $region, int $limit, bool $dryRun): int
    {
        $io->section('Phase 1: Discovery');

        $types = $type ? [$type] : ['ems', 'machining', 'harness', 'supercapacitor'];
        $regions = $region ? [$region] : ['morocco', 'eu_cee', 'gcc'];

        $totalFound = 0;

        foreach ($types as $t) {
            foreach ($regions as $r) {
                $io->text("  Searching: type={$t}, region={$r}");
                if ($dryRun) continue;

                $result = $this->discovery->discover($t, $r, (int) ceil($limit / count($types)));
                $totalFound += $result['discovered'] ?? 0;
                $io->text("    → Found " . ($result['discovered'] ?? 0) . " candidates (skipped " . ($result['skipped'] ?? 0) . ")");
            }
        }

        $io->text("Total discovered: {$totalFound}");
        return $totalFound;
    }

    // ─── Phase: Verify ─────────────────────────────────────────────────────────

    private function runVerify(SymfonyStyle $io, int $limit, bool $dryRun): int
    {
        $io->section('Phase 2: Verification');

        $candidates = $this->competitorRepo->findBy(
            ['status' => Competitor::STATUS_CANDIDATE],
            ['createdAt' => 'DESC'],
            $limit
        );

        $io->text(count($candidates) . " candidates to verify");
        $verified = 0;
        $rejected = 0;

        $io->progressStart(count($candidates));

        foreach ($candidates as $candidate) {
            if ($dryRun) {
                $io->progressAdvance();
                continue;
            }

            $types = $candidate->getCompetitorTypes();
            $primaryType = $types[0] ?? 'ems';

            // Quick fetch of homepage for verification
            $homepage = @file_get_contents("https://{$candidate->getCanonicalDomain()}");
            if (!$homepage) {
                $homepage = @file_get_contents("http://{$candidate->getCanonicalDomain()}");
            }

            if (!$homepage) {
                $candidate->setStatus(Competitor::STATUS_REJECTED);
                $candidate->setNotes('Verification failed: homepage unreachable');
                $rejected++;
                $io->progressAdvance();
                continue;
            }

            $result = $this->verification->verify($candidate, $homepage);

            if ($result['passed']) {
                $candidate->setStatus(Competitor::STATUS_VERIFIED);
                $candidate->setProofGrade($this->mapScoreToGrade($result['score']));
                $verified++;
            } else {
                if ($result['veto']) {
                    $candidate->setStatus(Competitor::STATUS_REJECTED);
                    $candidate->setNotes('Veto: ' . ($result['veto'] ?? 'unknown'));
                } else {
                    $candidate->setStatus(Competitor::STATUS_REJECTED);
                    $candidate->setNotes('Insufficient evidence: ' . count($result['families_passed']) . ' families');
                }
                $rejected++;
            }

            $io->progressAdvance();
        }

        $io->progressFinish();
        $this->em->flush();

        $io->text("Verified: {$verified} | Rejected: {$rejected}");
        return $verified;
    }

    // ─── Phase: Crawl ──────────────────────────────────────────────────────────

    private function runCrawl(SymfonyStyle $io, bool $deep, int $limit, bool $dryRun): int
    {
        $io->section('Phase 3: Crawl');

        $competitors = $deep
            ? $this->competitorRepo->findDueForDeepCrawl()
            : $this->competitorRepo->findDueForShallowCrawl();

        $competitors = array_slice($competitors, 0, $limit);
        $io->text(count($competitors) . " competitors due for " . ($deep ? 'deep' : 'shallow') . " crawl");

        $totalPages = 0;

        $io->progressStart(count($competitors));

        foreach ($competitors as $competitor) {
            if ($dryRun) {
                $io->progressAdvance();
                continue;
            }

            $result = $deep
                ? $this->crawler->deepCrawl($competitor)
                : $this->crawler->shallowCrawl($competitor);

            $totalPages += $result['pages_crawled'];

            // Store content temporarily in memory for extraction in next phase
            // (In production, you'd batch this or use a message queue)

            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->text("Total pages crawled: {$totalPages}");
        return count($competitors);
    }

    // ─── Phase: Extract ────────────────────────────────────────────────────────

    private function runExtract(SymfonyStyle $io, int $limit, bool $dryRun): int
    {
        $io->section('Phase 4: Extraction');

        // Find recently crawled competitors that need profile refresh
        $competitors = $this->competitorRepo->findBy(
            ['status' => Competitor::STATUS_VERIFIED],
            ['lastCrawledAt' => 'DESC'],
            $limit
        );

        $io->text(count($competitors) . " competitors to extract");
        $extracted = 0;

        $io->progressStart(count($competitors));

        foreach ($competitors as $competitor) {
            if ($dryRun) {
                $io->progressAdvance();
                continue;
            }

            // Quick crawl for extraction
            $crawlResult = $this->crawler->shallowCrawl($competitor);
            if (empty($crawlResult['content'])) {
                $io->progressAdvance();
                continue;
            }

            // Build pre-extraction snapshot
            $oldSnapshot = $this->changeDetector->buildSnapshot($competitor);

            // Extract
            $extractResult = $this->extraction->extract($competitor, $crawlResult['content']);

            // Detect changes
            $this->changeDetector->detectChanges($competitor, $extractResult['profile'], $oldSnapshot);

            // Promote to monitoring if we have good data
            if (!empty($extractResult['profile']['certifications']) || !empty($extractResult['profile']['capabilities'])) {
                if ($competitor->getStatus() === Competitor::STATUS_VERIFIED) {
                    $competitor->setStatus(Competitor::STATUS_MONITORING);
                }
            }

            $extracted++;
            $io->progressAdvance();
        }

        $io->progressFinish();
        $this->em->flush();
        $io->text("Extracted: {$extracted}");
        return $extracted;
    }

    // ─── Phase: Score ──────────────────────────────────────────────────────────

    private function runScore(SymfonyStyle $io, bool $dryRun): int
    {
        $io->section('Phase 5: Scoring');

        $competitors = $this->competitorRepo->findActive();
        $io->text(count($competitors) . " active competitors to score");
        $scored = 0;

        $io->progressStart(count($competitors));

        foreach ($competitors as $competitor) {
            if ($dryRun) {
                $io->progressAdvance();
                continue;
            }

            $this->scoring->score($competitor);
            $scored++;
            $io->progressAdvance();
        }

        $io->progressFinish();
        $this->em->flush();
        $io->text("Scored: {$scored}");
        return $scored;
    }

    // ─── Phase: Sync ───────────────────────────────────────────────────────────

    private function runSync(SymfonyStyle $io, bool $dryRun): int
    {
        $io->section('Phase 6: Intel Sync');

        if ($dryRun) {
            $io->text("Would sync competitor intel to LeadCrawler block list");
            return 0;
        }

        $result = $this->intelSync->generateBlockIntel();
        $io->text("Created: {$result['created']} | Updated: {$result['updated']} | Total: {$result['total_domains']}");
        return $result['created'] + $result['updated'];
    }

    // ─── Single Domain Mode ────────────────────────────────────────────────────

    private function processSingleDomain(SymfonyStyle $io, string $domain, string $phase, bool $deep, bool $dryRun): int
    {
        $canonical = $this->resolver->canonicalizeDomain($domain);
        $io->section("Processing single domain: {$canonical}");

        // Resolve
        $resolved = $this->resolver->resolve($canonical);
        $competitor = $resolved['competitor'];

        if (!$competitor) {
            $io->text("Creating new competitor entry for {$canonical}");
            if ($dryRun) return Command::SUCCESS;

            $competitor = new Competitor();
            $competitor->setCanonicalDomain($canonical);
            $competitor->setName($canonical);
            $competitor->setStatus(Competitor::STATUS_CANDIDATE);
            $competitor->setCompetitorTypes(['ems']);
            $this->em->persist($competitor);
            $this->em->flush();
        } else {
            $io->text("Found existing: {$competitor->getName()} ({$resolved['match_type']})");
        }

        if ($dryRun) return Command::SUCCESS;

        // Verify
        if (in_array($phase, ['verify', 'crawl', 'extract', 'all'])) {
            $io->text("  Verifying...");
            $homepage = @file_get_contents("https://{$canonical}");
            if ($homepage) {
                $result = $this->verification->verify($competitor, $homepage);
                $io->text("    Verified: " . ($result['passed'] ? 'YES' : 'NO') . " | Score: {$result['score']}");

                if ($result['passed']) {
                    $competitor->setStatus(Competitor::STATUS_VERIFIED);
                    $competitor->setProofGrade($this->mapScoreToGrade($result['score']));
                }
            }
        }

        // Crawl + Extract
        if (in_array($phase, ['crawl', 'extract', 'all'])) {
            $io->text("  Crawling (" . ($deep ? 'deep' : 'shallow') . ")...");
            $crawlResult = $deep
                ? $this->crawler->deepCrawl($competitor)
                : $this->crawler->shallowCrawl($competitor);
            $io->text("    Pages: {$crawlResult['pages_crawled']} | Changed: {$crawlResult['pages_changed']}");

            if (!empty($crawlResult['content'])) {
                $io->text("  Extracting profile...");
                $oldSnapshot = $this->changeDetector->buildSnapshot($competitor);
                $extractResult = $this->extraction->extract($competitor, $crawlResult['content']);
                $io->text("    Certs: " . count($extractResult['profile']['certifications'] ?? []));
                $io->text("    Caps:  " . count($extractResult['profile']['capabilities'] ?? []));
                $io->text("    Industries: " . count($extractResult['profile']['industries'] ?? []));

                $changes = $this->changeDetector->detectChanges($competitor, $extractResult['profile'], $oldSnapshot);
                if (!empty($changes)) {
                    $io->text("    Change events: " . count($changes));
                }
            }
        }

        // Score
        if (in_array($phase, ['score', 'all'])) {
            $io->text("  Scoring...");
            $scores = $this->scoring->score($competitor);
            $io->text("    Threat: {$scores['threat']} | Overlap: {$scores['overlap']} | Strategic: {$scores['strategic']}");
        }

        $this->em->flush();

        $io->success("Done: {$competitor->getName()} [{$competitor->getStatus()}]");
        return Command::SUCCESS;
    }

    private function mapScoreToGrade(float $score): string
    {
        if ($score >= 80) return 'A';
        if ($score >= 60) return 'B';
        if ($score >= 40) return 'C';
        return 'D';
    }
}
