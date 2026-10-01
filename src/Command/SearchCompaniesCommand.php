<?php

namespace App\Command;

use App\Entity\Lead;
use App\Service\GoogleSearchService;
use App\Service\CountryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:search-companies',
    description: 'Search for companies using Google Custom Search and create leads'
)]
class SearchCompaniesCommand extends Command
{
    public function __construct(
        private GoogleSearchService $googleSearchService,
        private EntityManagerInterface $entityManager,
        private CountryService $countryService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::REQUIRED, 'Search query (e.g., "aerospace manufacturing morocco")')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximum number of results', 10)
            ->addOption('import', 'i', InputOption::VALUE_NONE, 'Automatically import results as leads')
            ->addOption('sector', 's', InputOption::VALUE_OPTIONAL, 'Filter by sector', null)
            ->addOption('location', null, InputOption::VALUE_OPTIONAL, 'Target location/region (e.g. "Germany", "Texas", "Tanger Free Zone")', null)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show results without importing')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompts (cron-safe)')
            ->setHelp(<<<'HELP'
The <info>app:search-companies</info> command searches for companies using Google Custom Search API.

Usage:
  <info>php bin/console app:search-companies "aerospace suppliers morocco"</info>
  <info>php bin/console app:search-companies "automotive parts" --limit=20 --import</info>
  <info>php bin/console app:search-companies "electronics" --sector=aerospace --dry-run</info>

Options:
  --limit (-l)     Maximum number of results (default: 10)
  --import (-i)    Automatically import results as leads
  --sector (-s)    Filter by specific sector
  --dry-run        Preview results without importing

Examples:
  # Search and preview 10 results
  <info>php bin/console app:search-companies "aerospace morocco" --dry-run</info>

  # Search and import 20 companies as leads
  <info>php bin/console app:search-companies "automotive suppliers" --limit=20 --import</info>

  # Search aerospace sector
  <info>php bin/console app:search-companies "manufacturing" --sector=aerospace --import</info>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        /** @var string $query */
        $query = $input->getArgument('query');
        $limit = (int)$input->getOption('limit');
        /** @var mixed $import */
        $import = $input->getOption('import');
        /** @var mixed $sector */
        $sector = $input->getOption('sector');
        /** @var mixed $location */
        $location = $input->getOption('location');
        /** @var mixed $dryRun */
        $dryRun = $input->getOption('dry-run');

        $io->title('Google Custom Search - Company Discovery');

        // Show quota estimation
        $quota = $this->googleSearchService->estimateQuota(1, $limit);
        $io->section('Quota Estimation');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Total Queries', $quota['total_queries']],
                ['Free Quota Used', $quota['free_quota']],
                ['Billable Queries', $quota['billable_queries']],
                ['Estimated Cost', '$' . number_format($quota['estimated_cost'], 2)],
            ]
        );

        // Only prompt in an interactive terminal; --yes / --no-interaction
        // (or a cron environment without a TTY) proceeds without asking.
        if ($input->isInteractive() && !$input->getOption('yes') && !$io->confirm('Continue with search?', true)) {
            $io->info('Search cancelled.');
            return Command::SUCCESS;
        }

        $io->section('Searching...');
        $io->text("Query: <comment>{$query}</comment>");
        $io->text("Limit: <comment>{$limit}</comment>");
        if ($location) {
            $io->text("Location: <comment>{$location}</comment>");
        }

        try {
            // Perform search — use location if provided, otherwise generic
            if ($sector) {
                $results = $this->googleSearchService->searchBySector($sector, $location ?: 'all', $limit);
            } else {
                if ($limit > 10) {
                    $clamped = min($limit, 50);
                    $io->warning("Limit of {$limit} requested, clamping to {$clamped} for safety.");
                    $limit = $clamped;
                }
                $results = $this->googleSearchService->searchCompanies($query, $limit);
            }

            if (empty($results['results'])) {
                $io->warning('No results found.');
                return Command::SUCCESS;
            }

            $io->success(sprintf(
                'Found %d results in %.2f seconds',
                count($results['results']),
                $results['searchTime']
            ));

            // Display results
            $io->section('Search Results');
            $tableData = [];
            foreach ($results['results'] as $index => $result) {
                $tableData[] = [
                    $index + 1,
                    $this->truncate($result['title'], 50),
                    $result['displayLink'],
                    $this->truncate($result['snippet'], 80),
                ];
            }

            $io->table(
                ['#', 'Company Name', 'Website', 'Description'],
                $tableData
            );

            // Import leads
            if ($dryRun) {
                $io->note('Dry-run mode: No leads will be created.');
                return Command::SUCCESS;
            }

            if ($import || $io->confirm('Import these results as leads?', false)) {
                $imported = $this->importLeads($results['results'], $query, $location, $sector, $io);
                $io->success("Successfully imported {$imported} leads.");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Search failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function importLeads(array $results, string $source, ?string $location, ?string $sector, SymfonyStyle $io): int
    {
        $imported = 0;
        $skipped = 0;

        $io->progressStart(count($results));

        $regionTag = $location ? ($this->countryService->normalizeRegionCode($location) ?? 'unknown') : 'unknown';

        foreach ($results as $result) {
            $website = $this->googleSearchService->extractWebsite($result);
            
            // Check if lead already exists
            if ($website) {
                $existing = $this->entityManager->getRepository(Lead::class)
                    ->findOneBy(['website' => $website]);
                
                if ($existing) {
                    $skipped++;
                    $io->progressAdvance();
                    continue;
                }
            }

            // Create new lead
            $lead = new Lead();
            $lead->setCompanyName($this->cleanCompanyName($result['title']));
            $lead->setWebsite($website);
            $lead->setDescription($result['snippet']);
            $lead->setSource('Google Search: ' . $source);
            $lead->setReviewStatus('new');
            $lead->setCreatedAt(new \DateTimeImmutable());
            $lead->setSiteLocation($location);
            $lead->setRegionTag($regionTag);
            if ($sector) {
                $lead->setSectorTags([$sector]);
            }

            // Extract additional info from pagemap if available
            if (isset($result['pagemap']['organization'])) {
                $org = $result['pagemap']['organization'][0] ?? [];
                if (isset($org['name'])) {
                    $lead->setCompanyName($org['name']);
                }
            }

            $this->entityManager->persist($lead);
            $imported++;
            
            $io->progressAdvance();
        }

        $this->entityManager->flush();
        $io->progressFinish();

        if ($skipped > 0) {
            $io->note("Skipped {$skipped} duplicate leads.");
        }

        return $imported;
    }

    private function cleanCompanyName(string $title): string
    {
        // Remove common suffixes from title
        $title = preg_replace('/\s*[-|]\s*.+$/', '', $title);
        return trim($title);
    }

    private function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length - 3) . '...';
    }
}
