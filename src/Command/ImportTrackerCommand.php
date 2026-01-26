<?php

namespace App\Command;

use App\Service\Import\TrackerImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-tracker',
    description: 'Import companies from Tracker.xlsx (CSV format)',
)]
class ImportTrackerCommand extends Command
{
    public function __construct(
        private TrackerImportService $importService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Path to CSV file (converted from Tracker.xlsx)')
            ->addOption('template', 't', InputOption::VALUE_NONE, 'Generate CSV template')
            ->setHelp(<<<'HELP'
Import companies from Tracker.xlsx into the CRM database.

IMPORTANT: Convert Tracker.xlsx to CSV format first:
1. Open Tracker.xlsx in Excel
2. Save As > CSV (Comma delimited) (*.csv)
3. Save to a location you can reference

Examples:
  # Import from CSV file
  php bin/console app:import-tracker path/to/tracker.csv

  # Import from default location
  php bin/console app:import-tracker "C:\Users\sadok\CRM Project\Tracker.csv"

  # Generate CSV template
  php bin/console app:import-tracker --template

Expected CSV columns:
- Company Name (required)
- Sector
- Location
- Website
- Account Tier (A/B/C)
- Pipeline Stage
- Portal URL
- Portal Registered
- Portal ID
- Submitted Date
- Approval Date
- Buyer Name
- Buyer Email
- Notes

The import will:
- Create new companies that don't exist
- Update existing companies with new data
- Import supplier portal information
- Skip empty rows
- Log all errors for review
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Tracker.xlsx Import');

        // Handle template generation
        if ($input->getOption('template')) {
            $templatePath = getcwd() . '/tracker_template.csv';
            $this->importService->generateTemplate($templatePath);
            
            $io->success("Template generated: {$templatePath}");
            $io->info([
                'Fill in the template with your company data.',
                'Then run: php bin/console app:import-tracker ' . $templatePath
            ]);
            
            return Command::SUCCESS;
        }

        // Get file path
        $filePath = $input->getArgument('file');
        
        if (!$filePath) {
            // Try default location
            $defaultPath = 'C:\Users\sadok\CRM Project\Tracker.csv';
            
            if (file_exists($defaultPath)) {
                $filePath = $defaultPath;
                $io->note("Using default file: {$filePath}");
            } else {
                $io->error('Please provide a CSV file path or convert Tracker.xlsx to CSV first');
                $io->info([
                    'To convert Tracker.xlsx to CSV:',
                    '1. Open Tracker.xlsx in Excel',
                    '2. File > Save As > CSV (Comma delimited)',
                    '3. Save as Tracker.csv',
                    '',
                    'Then run: php bin/console app:import-tracker "C:\Users\sadok\CRM Project\Tracker.csv"',
                    '',
                    'Or generate a template: php bin/console app:import-tracker --template'
                ]);
                return Command::FAILURE;
            }
        }

        // Verify file exists
        if (!file_exists($filePath)) {
            $io->error("File not found: {$filePath}");
            return Command::FAILURE;
        }

        $io->section("Importing from: {$filePath}");

        if (!$io->confirm('This will import/update companies in the database. Continue?', true)) {
            return Command::SUCCESS;
        }

        // Run import
        $io->progressStart();
        
        try {
            $stats = $this->importService->importFromCsv($filePath);
            
            $io->progressFinish();
            
            // Display results
            $io->success('Import completed!');
            
            $io->table(
                ['Metric', 'Count'],
                [
                    ['Rows Processed', $stats['processed']],
                    ['Companies Imported', $stats['imported']],
                    ['Companies Updated', $stats['updated']],
                    ['Rows Skipped', $stats['skipped']],
                    ['Errors', count($stats['errors'])],
                ]
            );

            if (!empty($stats['errors'])) {
                $io->section('Errors');
                foreach ($stats['errors'] as $error) {
                    $io->error($error);
                }
            }

            $io->info([
                '',
                'Next steps:',
                '1. Review imported companies: php bin/console app:company:list',
                '2. Run webcrawler to enrich data: php bin/console app:discover-companies --sector=Automotive',
                '3. Find contacts: php bin/console app:find-contacts <company-id>',
            ]);

        } catch (\Exception $e) {
            $io->progressFinish();
            $io->error('Import failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
