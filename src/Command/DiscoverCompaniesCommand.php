<?php

namespace App\Command;

use App\Service\WebCrawler\CompanyDiscoveryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:discover-companies',
    description: 'Discover and import companies using webcrawler',
)]
class DiscoverCompaniesCommand extends Command
{
    public function __construct(
        private CompanyDiscoveryService $discoveryService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sector', 's', InputOption::VALUE_OPTIONAL, 'Specific sector to search')
            ->addOption('location', 'l', InputOption::VALUE_OPTIONAL, 'Specific location to search')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Discover all sectors and locations')
            ->setHelp(<<<'HELP'
This command discovers companies using web crawling techniques.

Examples:
  # Discover companies in Automotive sector
  php bin/console app:discover-companies --sector=Automotive

  # Discover companies in Automotive sector at Tanger Free Zone
  php bin/console app:discover-companies --sector=Automotive --location="Tanger Free Zone"

  # Discover companies across all sectors and locations
  php bin/console app:discover-companies --all

The webcrawler uses:
- LinkedIn search to find company profiles
- Google Dorks to discover company websites and information
- Automatic deduplication to avoid importing duplicates

Discovered companies are saved with:
- Name, sector, location
- Website and LinkedIn URLs (when found)
- Default pipeline stage: Prospect
- Default tier: C
- Source notes indicating auto-discovery date
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Company Discovery Webcrawler');

        $sector = $input->getOption('sector');
        $location = $input->getOption('location');
        $all = $input->getOption('all');

        if ($all) {
            $io->section('Discovering companies across all sectors');
            
            $io->warning([
                'This will search for companies in all target sectors and locations.',
                'This may take a long time and generate many search queries.',
                'Consider running sector-by-sector for better control.'
            ]);

            if (!$io->confirm('Continue?', false)) {
                return Command::SUCCESS;
            }

            $io->progressStart();
            $companies = $this->discoveryService->discoverAllSectors();
            $io->progressFinish();

            $io->success(sprintf('Discovered %d companies across all sectors', count($companies)));

        } elseif ($sector) {
            $io->section("Discovering companies in {$sector}" . ($location ? " at {$location}" : ''));
            
            $companies = $this->discoveryService->discoverCompanies($sector, $location);

            $io->success(sprintf('Discovered %d companies', count($companies)));

            if (count($companies) > 0) {
                $io->section('Sample of discovered companies');
                $rows = [];
                foreach (array_slice($companies, 0, 10) as $company) {
                    $rows[] = [
                        $company->getName(),
                        $company->getSector(),
                        $company->getWebsite() ?? 'N/A',
                    ];
                }
                $io->table(['Name', 'Sector', 'Website'], $rows);

                if (count($companies) > 10) {
                    $io->note(sprintf('Showing 10 of %d companies', count($companies)));
                }
            }

        } else {
            $io->error('Please specify --sector=<sector> or use --all flag');
            $io->note('Available sectors: Automotive, Industrial, Aerospace, Rail, Renewables, Power Electronics');
            return Command::FAILURE;
        }

        $io->info([
            '',
            'Note: The webcrawler generates search URLs for LinkedIn and Google.',
            'For production use, integrate with:',
            '  - LinkedIn Sales Navigator API',
            '  - RocketReach API',
            '  - Apollo.io API',
            '  - Google Custom Search API',
            '',
            'Manual verification and data entry recommended for now.',
        ]);

        return Command::SUCCESS;
    }
}
