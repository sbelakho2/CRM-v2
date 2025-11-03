<?php

namespace App\Command;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use App\Service\WebCrawler\LinkedInScraperService;
use App\Service\WebCrawler\GoogleDorkService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:find-contacts',
    description: 'Find procurement/purchasing contacts at a company',
)]
class FindContactsCommand extends Command
{
    public function __construct(
        private CompanyRepository $companyRepo,
        private LinkedInScraperService $linkedInScraper,
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
- LinkedIn Sales Navigator searches
- Google Dorks for finding email addresses
- Role-specific searches (Procurement Engineer, Buyer, etc.)

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
- LinkedIn Sales Navigator API
- RocketReach for email finding
- Apollo.io for contact enrichment
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Find Procurement Contacts');

        $companyInput = $input->getArgument('company');

        // Try to find company by ID first
        $company = null;
        if (is_numeric($companyInput)) {
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
            'LinkedIn: ' . ($company->getLinkedinCompanyUrl() ?? 'N/A'),
        ]);

        // Generate LinkedIn search URLs
        $io->section('LinkedIn Search URLs');
        $linkedInResults = $this->linkedInScraper->findContactsAtCompany($company);
        
        foreach ($linkedInResults as $result) {
            $io->writeln("• <info>{$result['title']}</info>");
            $io->writeln("  {$result['url']}");
            $io->newLine();
        }

        // Generate Google Dork URLs for finding emails
        $io->section('Google Dork Search URLs');
        
        $domain = $this->extractDomain($company->getWebsite());
        if ($domain) {
            $io->writeln("Searching for emails at domain: <info>{$domain}</info>");
            $io->newLine();
            
            $emailResults = $this->googleDork->findContactEmails($company->getName(), $domain);
            
            if (empty($emailResults)) {
                $io->note('No email search results. Google Dorks generated for manual review.');
            }
        } else {
            $io->warning('No company website found. Cannot generate email search queries.');
        }

        // Show LinkedIn company profile search
        if (!$company->getLinkedinCompanyUrl()) {
            $io->section('Find Company LinkedIn Profile');
            $profileUrl = $this->linkedInScraper->findCompanyProfile($company->getName());
            if ($profileUrl) {
                $io->writeln($profileUrl);
            }
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
