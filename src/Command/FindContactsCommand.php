<?php

namespace App\Command;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use App\Service\WebCrawler\GoogleDorkService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:find-contacts',
    description: 'Find procurement/purchasing contacts at a company using web search',
)]
class FindContactsCommand extends Command
{
    public function __construct(
        private CompanyRepository $companyRepo,
        private GoogleDorkService $googleDork
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('company', InputArgument::REQUIRED, 'Company ID or name')
            ->setHelp(<<<'HELP'
Find procurement and purchasing contacts at a specific company.

This command generates search URLs for:
- Google Dorks for finding email addresses
- Role-specific searches (Procurement Engineer, Buyer, etc.)
- Company website contact pages

Examples:
  # Find contacts by company ID
  php bin/console app:find-contacts 123

  # Find contacts by company name
  php bin/console app:find-contacts "Yazaki Morocco"

The command searches for these procurement roles:
- Procurement Engineer
- Purchasing Engineer
- Commodity Manager
- Buyer
- Supply Chain Manager
- Supplier Quality Engineer
- Category Manager

For production use, integrate with:
- RocketReach for email finding
- Apollo.io for contact enrichment
- Hunter.io for email verification
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Find Procurement Contacts');

        /** @var string $companyInput */
        $companyInput = $input->getArgument('company');

        // Try to find company by ID first
        $company = null;
        if (filter_var($companyInput, FILTER_VALIDATE_INT) !== false) {
            $company = $this->companyRepo->find((int)$companyInput);
        }

        // If not found, search by name
        if (!$company) {
            $company = $this->companyRepo->findOneBy(['name' => $companyInput]);
        }

        if (!$company) {
            $io->error("Company not found: {$companyInput}");
            $io->note('Try searching: php bin/console app:company:search "' . $companyInput . '"');
            return Command::FAILURE;
        }

        $io->section("Finding contacts at: " . $company->getName());
        $io->info([
            'Sector: ' . ($company->getSector() ?? 'N/A'),
            'Website: ' . ($company->getWebsite() ?? 'N/A'),
            'Region: ' . ($company->getRegion() ?? 'N/A'),
        ]);

        // Generate Google Dork URLs for finding emails
        $io->section('Google Dork Search URLs');
        
        $domain = $this->extractDomain($company->getWebsite());
        if ($domain) {
            $io->writeln("Searching for emails at domain: <info>{$domain}</info>");
            $io->newLine();
            
            $emailResults = $this->googleDork->findContactEmails($company->getName(), $domain);
            
            if (!empty($emailResults)) {
                foreach ($emailResults as $result) {
                    $io->writeln("• <info>{$result['title']}</info>");
                    $io->writeln("  {$result['url']}");
                    $io->newLine();
                }
            } else {
                $io->note('No email search results. Generating manual search queries...');
            }
            
            // Generate manual search URLs for procurement roles
            $roles = [
                'Procurement Manager',
                'Purchasing Manager',
                'Commodity Manager',
                'Buyer',
                'Supply Chain Manager',
                'Supplier Quality Engineer',
            ];
            
            $io->section('Role-Specific Search URLs');
            foreach ($roles as $role) {
                $searchQuery = urlencode("{$company->getName()} {$role} email");
                $io->writeln("• <comment>{$role}</comment>");
                $io->writeln("  https://www.google.com/search?q={$searchQuery}");
                $io->newLine();
            }
        } else {
            $io->warning('No company website found. Cannot generate email search queries.');
            
            // Still generate basic search
            $searchQuery = urlencode("{$company->getName()} procurement contact email");
            $io->writeln("Basic search: https://www.google.com/search?q={$searchQuery}");
        }

        $io->newLine();
        $io->info([
            'To add contacts manually:',
            '1. Visit the search URLs above',
            '2. Copy contact information',
            '3. Add via web interface or CLI:',
            '   php bin/console app:contact:add ' . $company->getId(),
        ]);

        return Command::SUCCESS;
    }

    private function extractDomain(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        $parsed = parse_url($url);
        return $parsed['host'] ?? null;
    }
}
