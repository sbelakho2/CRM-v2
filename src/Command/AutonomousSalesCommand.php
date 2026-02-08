<?php

namespace App\Command;

use App\Entity\OutboundMessage;
use App\Service\AutonomousSalesOrchestratorService;
use App\Service\AutonomousSalesSettingsService;
use App\Service\CompetitorLearnerService;
use App\Service\CompetitorDetectionService;
use App\Service\EmailClassifierService;
use App\Repository\ContactRepository;
use App\Repository\OutboundMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Autonomous Sales System Command
 * 
 * CLI interface for the Autonomous Sales System:
 * - Initialize system (seed templates, arms, competitors)
 * - Score leads in batch
 * - Display system statistics
 * - Run email campaigns
 * - Process inbox queue (batch classification)
 * - Refresh competitor cache
 * - Full batch run (for cron scheduling)
 * 
 * Starz Electronics/Morocco Services:
 * - PCB Assembly, Cable Harness, Overmolding, Windings, System Integration
 * - Industries: Automotive, Aerospace, Industrial
 * 
 * Usage:
 *   php bin/console app:autonomous-sales --init
 *   php bin/console app:autonomous-sales --score --limit=100
 *   php bin/console app:autonomous-sales --stats
 *   php bin/console app:autonomous-sales --seed-competitors
 *   php bin/console app:autonomous-sales --batch  # Full scheduled run
 *   php bin/console app:autonomous-sales --process-inbox --limit=50
 *   php bin/console app:autonomous-sales --refresh-cache
 *   php bin/console app:autonomous-sales --bayes-stats
 */
#[AsCommand(
    name: 'app:autonomous-sales',
    description: 'Run the Autonomous Sales System operations',
)]
class AutonomousSalesCommand extends Command
{
    public function __construct(
        private AutonomousSalesOrchestratorService $orchestrator,
        private AutonomousSalesSettingsService $settingsService,
        private ?CompetitorLearnerService $competitorLearner,
        private ?CompetitorDetectionService $competitorDetection,
        private ?EmailClassifierService $emailClassifier,
        private ContactRepository $contactRepository,
        private ?OutboundMessageRepository $outboundMessageRepository,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('init', null, InputOption::VALUE_NONE, 'Initialize system (seed templates, arms, competitors)')
            ->addOption('score', null, InputOption::VALUE_NONE, 'Score leads in batch')
            ->addOption('stats', null, InputOption::VALUE_NONE, 'Display system statistics')
            ->addOption('seed-competitors', null, InputOption::VALUE_NONE, 'Seed/update competitor database')
            ->addOption('compose', null, InputOption::VALUE_REQUIRED, 'Compose message for contact ID')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit for batch operations', 50)
            ->addOption('service', null, InputOption::VALUE_REQUIRED, 'Service type for compose (pcba, harness, general)')
            // New batch operation options
            ->addOption('batch', null, InputOption::VALUE_NONE, 'Run full scheduled batch (score + inbox + cache)')
            ->addOption('process-inbox', null, InputOption::VALUE_NONE, 'Process pending reply classifications')
            ->addOption('refresh-cache', null, InputOption::VALUE_NONE, 'Refresh competitor detection cache')
            ->addOption('bayes-stats', null, InputOption::VALUE_NONE, 'Show Naive Bayes model statistics')
            ->addOption('decay-arms', null, InputOption::VALUE_NONE, 'Apply confidence decay to unused Thompson arms')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dry run mode (no database changes)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🚀 Autonomous Sales System');

        if (!$this->settingsService->isEnabled() && !$input->getOption('stats')) {
            $io->warning('Autonomous Sales system is disabled. Enable it to run operations.');
            return Command::SUCCESS;
        }
        
        $dryRun = $input->getOption('dry-run');
        if ($dryRun) {
            $io->warning('Running in DRY RUN mode - no database changes will be made');
        }

        // Full batch operation (for cron scheduling)
        if ($input->getOption('batch')) {
            return $this->runFullBatch($io, $input, $dryRun);
        }

        // Initialize
        if ($input->getOption('init')) {
            return $this->runInitialize($io);
        }

        // Score leads
        if ($input->getOption('score')) {
            $limit = (int) $input->getOption('limit');
            return $this->runScoreLeads($io, $limit);
        }

        // Display stats
        if ($input->getOption('stats')) {
            return $this->runStats($io);
        }

        // Seed competitors
        if ($input->getOption('seed-competitors')) {
            return $this->runSeedCompetitors($io);
        }

        // Process inbox queue
        if ($input->getOption('process-inbox')) {
            $limit = (int) $input->getOption('limit');
            return $this->runProcessInbox($io, $limit, $dryRun);
        }
        
        // Refresh competitor cache
        if ($input->getOption('refresh-cache')) {
            return $this->runRefreshCache($io);
        }
        
        // Bayes model statistics
        if ($input->getOption('bayes-stats')) {
            return $this->runBayesStats($io);
        }
        
        // Decay unused Thompson arms
        if ($input->getOption('decay-arms')) {
            return $this->runDecayArms($io, $dryRun);
        }

        // Compose message
        if ($contactId = $input->getOption('compose')) {
            $serviceType = $input->getOption('service') ?? 'general';
            return $this->runCompose($io, (int) $contactId, $serviceType);
        }

        // Default: show help
        $io->section('Available Operations');
        $io->listing([
            '--init              Initialize system (templates, arms, competitors)',
            '--score             Score leads in batch (use --limit to control)',
            '--stats             Display system statistics',
            '--seed-competitors  Seed competitor database',
            '--compose=ID        Compose personalized message for contact',
            '',
            '--- Batch Operations (for cron) ---',
            '--batch             Full scheduled run (score + inbox + cache)',
            '--process-inbox     Process pending reply classifications',
            '--refresh-cache     Refresh competitor detection cache',
            '--bayes-stats       Show Naive Bayes model statistics',
            '--decay-arms        Apply confidence decay to unused Thompson arms',
            '--dry-run           Dry run mode (no database changes)',
        ]);
        
        $io->section('Starz Services');
        $io->listing([
            'PCB Assembly (fine-pitch, BGA, multi-layer)',
            'Cable & Wire Harness Assembly',
            'Overmolding & Plastic Injection',
            'Copper Windings',
            'System Integration & Turnkey Projects',
            'Mechanical Services (CNC)',
        ]);
        
        $io->section('Recommended Cron Schedule');
        $io->text('Add to crontab:');
        $io->block(
            "# Every hour: Score leads and process inbox\n" .
            "0 * * * * cd /path/to/project && php bin/console app:autonomous-sales --batch --limit=100\n\n" .
            "# Daily at 2am: Refresh competitor cache\n" .
            "0 2 * * * cd /path/to/project && php bin/console app:autonomous-sales --refresh-cache\n\n" .
            "# Weekly: Decay unused arms (Sundays at 3am)\n" .
            "0 3 * * 0 cd /path/to/project && php bin/console app:autonomous-sales --decay-arms",
            'CRON'
        );

        return Command::SUCCESS;
    }

    private function runInitialize(SymfonyStyle $io): int
    {
        $io->section('Initializing Autonomous Sales System');

        $result = $this->orchestrator->initialize();

        $io->success('System initialized successfully');
        $io->table(
            ['Component', 'Count'],
            [
                ['Templates', $result['templates']],
                ['Subject Line Arms', $result['arms']],
                ['Competitors', $result['competitors'] ?? 0],
            ]
        );

        return Command::SUCCESS;
    }

    private function runScoreLeads(SymfonyStyle $io, int $limit): int
    {
        $io->section("Scoring leads (limit: {$limit})");

        $result = $this->orchestrator->scoreLeads($limit);

        $io->success("Scored {$result['scored']} leads");
        
        $io->table(
            ['Tier', 'Count'],
            [
                ['🔥 Hot (70+)', $result['byTier']['hot']],
                ['☀️ Warm (50-69)', $result['byTier']['warm']],
                ['❄️ Cold (30-49)', $result['byTier']['cold']],
                ['🧊 Ice (<30)', $result['byTier']['ice']],
            ]
        );

        if (count($result['leads']) > 0) {
            $io->section('Top Scored Leads');
            $topLeads = array_slice($result['leads'], 0, 10);
            $rows = array_map(fn($l) => [
                $l['id'],
                substr($l['name'] ?? 'Unknown', 0, 30),
                $l['score'],
                $l['tier'],
                $l['competitorBoost'] > 0 ? "+{$l['competitorBoost']}" : '-',
            ], $topLeads);
            
            $io->table(['ID', 'Company', 'Score', 'Tier', 'Competitor Boost'], $rows);
        }

        return Command::SUCCESS;
    }

    private function runStats(SymfonyStyle $io): int
    {
        $io->section('System Statistics');

        $stats = $this->orchestrator->getStats();

        // Discovery stats
        $io->definitionList(
            ['Total Leads' => $stats['discovery']['totalLeads']],
            ['Pending Review' => $stats['discovery']['pendingReview']],
            ['Scored Leads' => $stats['discovery']['scored']],
        );

        // Scoring stats
        $io->table(
            ['Metric', 'Value'],
            [
                ['Average Score', $stats['scoring']['averageScore']],
            ]
        );

        // Optimizer stats (Thompson Sampling)
        $io->section('Thompson Sampling (Subject Lines)');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Active Arms', $stats['optimizer']['arms']],
                ['Total Trials', $stats['optimizer']['trials']],
                ['Success Rate', ($stats['optimizer']['successRate'] * 100) . '%'],
                ['Convergence', $stats['optimizer']['convergence'] ? 'Yes' : 'No'],
            ]
        );

        // Inbox stats
        if (isset($stats['inbox'])) {
            $io->section('Email Classification');
            $io->table(
                ['Classification', 'Count'],
                array_map(fn($k, $v) => [$k, $v], array_keys($stats['inbox']), array_values($stats['inbox']))
            );
        }

        // Competitor stats
        $io->section('Competitor Detection');
        $io->text("Total detections: {$stats['competitors']['detectionsCount']}");

        return Command::SUCCESS;
    }

    private function runSeedCompetitors(SymfonyStyle $io): int
    {
        if (!$this->competitorLearner) {
            $io->error('CompetitorLearnerService not available');
            return Command::FAILURE;
        }

        $io->section('Seeding Competitors');

        $competitors = $this->competitorLearner->seedCompetitors();

        $io->success('Seeded ' . count($competitors) . ' competitors');

        // Group by tier
        $byTier = [1 => [], 2 => [], 3 => []];
        foreach ($competitors as $c) {
            $byTier[$c->getTier()][] = $c->getName();
        }

        $io->section('Tier 1 - Direct Competitors (North Africa)');
        $io->listing($byTier[1]);

        $io->section('Tier 2 - Eastern European EMS');
        $io->listing($byTier[2]);

        $io->section('Tier 3 - Global EMS');
        $io->listing($byTier[3]);

        return Command::SUCCESS;
    }

    private function runCompose(SymfonyStyle $io, int $contactId, string $serviceType): int
    {
        $contact = $this->contactRepository->find($contactId);
        
        if (!$contact) {
            $io->error("Contact not found: {$contactId}");
            return Command::FAILURE;
        }

        $io->section('Composing Message');
        $io->text("Contact: {$contact->getFirstName()} {$contact->getLastName()}");
        $io->text("Email: {$contact->getEmail()}");
        $io->text("Company: {$contact->getCompany()?->getName()}");
        $io->text("Service Type: {$serviceType}");

        $result = $this->orchestrator->composeMessage($contact, [
            'service_type' => $serviceType,
        ]);

        $io->section('Generated Email');
        $io->block($result['subject'], 'SUBJECT');
        $io->newLine();
        $io->block($result['body'], 'BODY');
        
        $io->section('Metadata');
        $io->table(
            ['Field', 'Value'],
            [
                ['Template', $result['templateName']],
                ['Template ID', $result['templateId']],
                ['Subject Arm', $result['subjectArmName'] ?? 'N/A'],
                ['Variation Hash', substr($result['variationHash'], 0, 16) . '...'],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * Full batch operation for cron scheduling
     * 
     * Runs all scheduled operations:
     * 1. Score leads
     * 2. Process inbox queue
     * 3. Refresh competitor cache (if stale)
     */
    private function runFullBatch(SymfonyStyle $io, InputInterface $input, bool $dryRun): int
    {
        $limit = (int) $input->getOption('limit');
        $startTime = microtime(true);
        $results = [];
        
        $io->section('📋 Running Full Batch');
        
        // 1. Score leads
        $io->text('Step 1/3: Scoring leads...');
        if (!$dryRun) {
            $scoreResult = $this->orchestrator->scoreLeads($limit);
            $results['scoring'] = [
                'scored' => $scoreResult['scored'],
                'hot' => $scoreResult['byTier']['hot'] ?? 0,
                'warm' => $scoreResult['byTier']['warm'] ?? 0,
            ];
            $io->text("  ✓ Scored {$scoreResult['scored']} leads");
        } else {
            $io->text('  [DRY RUN] Would score up to ' . $limit . ' leads');
            $results['scoring'] = ['scored' => 0, 'hot' => 0, 'warm' => 0];
        }
        
        // 2. Process inbox queue
        $io->text('Step 2/3: Processing inbox queue...');
        if (!$dryRun && $this->emailClassifier && $this->outboundMessageRepository) {
            $inboxResult = $this->processInboxQueueInternal($limit);
            $results['inbox'] = $inboxResult;
            $io->text("  ✓ Processed {$inboxResult['processed']} replies");
        } else {
            $io->text('  [DRY RUN or service unavailable] Would process up to ' . $limit . ' replies');
            $results['inbox'] = ['processed' => 0];
        }
        
        // 3. Check competitor cache freshness
        $io->text('Step 3/3: Checking competitor cache...');
        if (!$dryRun && $this->competitorDetection) {
            $cacheAge = $this->getCompetitorCacheAge();
            if ($cacheAge > 86400) { // 24 hours
                $io->text('  Cache stale, refreshing...');
                $this->refreshCompetitorCacheInternal();
                $results['cache'] = ['refreshed' => true, 'age_hours' => round($cacheAge / 3600, 1)];
            } else {
                $results['cache'] = ['refreshed' => false, 'age_hours' => round($cacheAge / 3600, 1)];
                $io->text("  ✓ Cache fresh ({$results['cache']['age_hours']}h old)");
            }
        } else {
            $io->text('  [DRY RUN or service unavailable]');
            $results['cache'] = ['refreshed' => false];
        }
        
        $duration = round(microtime(true) - $startTime, 2);
        
        $io->newLine();
        $io->success("Batch completed in {$duration}s");
        
        $io->table(
            ['Operation', 'Result'],
            [
                ['Leads Scored', $results['scoring']['scored']],
                ['Hot Leads', $results['scoring']['hot']],
                ['Warm Leads', $results['scoring']['warm']],
                ['Inbox Processed', $results['inbox']['processed']],
                ['Cache Refreshed', $results['cache']['refreshed'] ? 'Yes' : 'No'],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * Process pending reply classifications from inbox
     */
    private function runProcessInbox(SymfonyStyle $io, int $limit, bool $dryRun): int
    {
        $io->section('📧 Processing Inbox Queue');
        
        if (!$this->emailClassifier) {
            $io->error('EmailClassifierService not available');
            return Command::FAILURE;
        }
        
        if (!$this->outboundMessageRepository) {
            $io->error('OutboundMessageRepository not available');
            return Command::FAILURE;
        }
        
        if ($dryRun) {
            $pendingCount = $this->getPendingRepliesCount();
            $io->text("[DRY RUN] Would process up to {$limit} of {$pendingCount} pending replies");
            return Command::SUCCESS;
        }
        
        $result = $this->processInboxQueueInternal($limit);
        
        $io->success("Processed {$result['processed']} replies");
        
        if ($result['processed'] > 0) {
            $io->table(
                ['Classification', 'Count'],
                array_map(
                    fn($k, $v) => [$k, $v],
                    array_keys($result['byClassification']),
                    array_values($result['byClassification'])
                )
            );
        }

        return Command::SUCCESS;
    }

    /**
     * Refresh competitor detection cache
     */
    private function runRefreshCache(SymfonyStyle $io): int
    {
        $io->section('🔄 Refreshing Competitor Cache');
        
        if (!$this->competitorDetection) {
            $io->error('CompetitorDetectionService not available');
            return Command::FAILURE;
        }
        
        $beforeCount = $this->getCompetitorCacheSize();
        $this->refreshCompetitorCacheInternal();
        $afterCount = $this->getCompetitorCacheSize();
        
        $io->success('Cache refreshed successfully');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Entries Before', $beforeCount],
                ['Entries After', $afterCount],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * Display Naive Bayes model statistics
     */
    private function runBayesStats(SymfonyStyle $io): int
    {
        $io->section('📊 Naive Bayes Model Statistics');
        
        if (!$this->emailClassifier) {
            $io->error('EmailClassifierService not available');
            return Command::FAILURE;
        }
        
        // Check if the method exists (it should after our updates)
        if (!method_exists($this->emailClassifier, 'getBayesStatistics')) {
            $io->warning('getBayesStatistics() method not available. Model may use in-memory fallback only.');
            return Command::SUCCESS;
        }
        
        $stats = $this->emailClassifier->getBayesStatistics();
        
        $io->table(
            ['Classification', 'Word Count', 'Total Frequency'],
            array_map(
                fn($k, $v) => [$k, $v['words'], $v['frequency']],
                array_keys($stats['byClassification']),
                array_values($stats['byClassification'])
            )
        );
        
        $io->definitionList(
            ['Total Unique Words' => $stats['totalWords']],
            ['Training Examples' => $stats['trainingExamples']],
            ['Model Status' => $stats['status'] ?? 'Active'],
        );

        return Command::SUCCESS;
    }

    /**
     * Apply confidence decay to unused Thompson Sampling arms
     */
    private function runDecayArms(SymfonyStyle $io, bool $dryRun): int
    {
        $io->section('📉 Decaying Unused Thompson Arms');
        
        // Get arms that haven't been used in 14+ days
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('a')
            ->from(\App\Entity\BanditArm::class, 'a')
            ->where('a.lastUsedAt < :cutoff OR a.lastUsedAt IS NULL')
            ->setParameter('cutoff', new \DateTime('-14 days'));
        
        $staleArms = $qb->getQuery()->getResult();
        
        if (empty($staleArms)) {
            $io->success('No arms require decay - all have been used recently');
            return Command::SUCCESS;
        }
        
        $io->text(sprintf('Found %d arms unused for 14+ days', count($staleArms)));
        
        $rows = [];
        foreach ($staleArms as $arm) {
            $daysSince = $arm->getDaysSinceLastUsed();
            $decayFactor = max(0.5, 1 - ($daysSince - 14) * 0.02);
            
            $oldAlpha = $arm->getAlpha();
            $oldBeta = $arm->getBeta();
            $newAlpha = max(1.0, 1.0 + ($oldAlpha - 1.0) * $decayFactor);
            $newBeta = max(1.0, 1.0 + ($oldBeta - 1.0) * $decayFactor);
            
            $rows[] = [
                $arm->getName(),
                $daysSince,
                sprintf('%.1f/%.1f', $oldAlpha, $oldBeta),
                sprintf('%.1f/%.1f', $newAlpha, $newBeta),
                sprintf('%.0f%%', $decayFactor * 100),
            ];
            
            if (!$dryRun) {
                $arm->setAlpha($newAlpha);
                $arm->setBeta($newBeta);
            }
        }
        
        $io->table(['Arm', 'Days Unused', 'Old α/β', 'New α/β', 'Decay'], $rows);
        
        if (!$dryRun) {
            $this->entityManager->flush();
            $io->success('Applied decay to ' . count($staleArms) . ' arms');
        } else {
            $io->text('[DRY RUN] Would apply decay to ' . count($staleArms) . ' arms');
        }

        return Command::SUCCESS;
    }

    // ============ Internal helper methods ============

    private function processInboxQueueInternal(int $limit): array
    {
        $result = [
            'processed' => 0,
            'byClassification' => [],
        ];
        
        // Find messages with replies but no recorded classification
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('m')
            ->from(OutboundMessage::class, 'm')
            ->where('m.replyContent IS NOT NULL')
            ->andWhere('m.recordedEventClassification IS NULL')
            ->setMaxResults($limit);
        
        $pendingMessages = $qb->getQuery()->getResult();
        
        foreach ($pendingMessages as $message) {
            $replyContent = $message->getReplyContent();
            if (!$replyContent) {
                continue;
            }
            
            // Classify the reply
            $classification = $this->emailClassifier->classifyReply($replyContent);
            $category = $classification['classification'];
            
            // Record the classification
            $message->setRecordedEventClassification($category);
            
            // Update Thompson Sampling based on classification
            $this->orchestrator->recordEmailEvent($message, 'reply', $replyContent);
            
            // Track stats
            $result['processed']++;
            $result['byClassification'][$category] = ($result['byClassification'][$category] ?? 0) + 1;
        }
        
        $this->entityManager->flush();
        
        return $result;
    }

    private function getPendingRepliesCount(): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(m.id)')
            ->from(OutboundMessage::class, 'm')
            ->where('m.replyContent IS NOT NULL')
            ->andWhere('m.recordedEventClassification IS NULL');
        
        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function getCompetitorCacheAge(): int
    {
        // Check for cache timestamp in competitor_learner_cache table or similar
        // For now, return a default that triggers refresh
        try {
            $conn = $this->entityManager->getConnection();
            $result = $conn->executeQuery(
                "SELECT MAX(updated_at) as last_update FROM known_competitor"
            )->fetchAssociative();
            
            if ($result && $result['last_update']) {
                $lastUpdate = new \DateTime($result['last_update']);
                return time() - $lastUpdate->getTimestamp();
            }
        } catch (\Exception $e) {
            // Table may not exist or be empty
        }
        
        return PHP_INT_MAX; // Force refresh if no data
    }

    private function getCompetitorCacheSize(): int
    {
        try {
            $conn = $this->entityManager->getConnection();
            return (int) $conn->executeQuery("SELECT COUNT(*) FROM known_competitor")->fetchOne();
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function refreshCompetitorCacheInternal(): void
    {
        if ($this->competitorLearner) {
            // Re-seed competitors (this updates existing and adds new)
            $this->competitorLearner->seedCompetitors();
        }
        
        // Clear any internal caches
        if ($this->competitorDetection && method_exists($this->competitorDetection, 'clearCache')) {
            $this->competitorDetection->clearCache();
        }
    }
}
