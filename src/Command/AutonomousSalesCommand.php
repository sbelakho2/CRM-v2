<?php

namespace App\Command;

use App\Service\AutonomousSalesOrchestratorService;
use App\Service\CompetitorLearnerService;
use App\Repository\ContactRepository;
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
 */
#[AsCommand(
    name: 'app:autonomous-sales',
    description: 'Run the Autonomous Sales System operations',
)]
class AutonomousSalesCommand extends Command
{
    public function __construct(
        private AutonomousSalesOrchestratorService $orchestrator,
        private ?CompetitorLearnerService $competitorLearner,
        private ContactRepository $contactRepository,
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
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🚀 Autonomous Sales System');

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

        // Compose message
        if ($contactId = $input->getOption('compose')) {
            $serviceType = $input->getOption('service') ?? 'general';
            return $this->runCompose($io, (int) $contactId, $serviceType);
        }

        // Default: show help
        $io->section('Available Operations');
        $io->listing([
            '--init           Initialize system (templates, arms, competitors)',
            '--score          Score leads in batch (use --limit to control)',
            '--stats          Display system statistics',
            '--seed-competitors   Seed competitor database',
            '--compose=ID     Compose personalized message for contact',
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
}
