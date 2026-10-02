<?php

namespace App\Command;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use App\Service\ContactEnrichmentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:enrich-contacts',
    description: 'Find and create real decision-maker contacts for companies using Google Search + LinkedIn + website scraping',
)]
class EnrichContactsCommand extends Command
{
    public function __construct(
        private CompanyRepository $companyRepository,
        private ContactEnrichmentService $enrichmentService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('company', InputArgument::OPTIONAL, 'Company ID or name to enrich')
            ->addOption('sector', 's', InputOption::VALUE_REQUIRED, 'Enrich all companies in a sector')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max companies to process (batch mode)', '10')
            ->addOption('max-contacts', 'm', InputOption::VALUE_REQUIRED, 'Max contacts per company', '10')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without persisting')
            ->setHelp(<<<'HELP'
Enrich contacts for one or more companies using multiple data sources:

  1. Google Search API → LinkedIn profile discovery for procurement/engineering/executive roles
  2. Company website scraping → JSON-LD, team pages, vCards, microdata extraction
  3. Google Search API → @domain email pattern discovery
  4. LLM enrichment → title inference for unnamed contacts (requires OPENAI_API_KEY)

Examples:
  # Enrich a specific company by ID
  php bin/console app:enrich-contacts 42

  # Enrich by company name
  php bin/console app:enrich-contacts "SMTC Corporation"

  # Enrich all Automotive companies (up to 10)
  php bin/console app:enrich-contacts --sector=Automotive

  # Enrich 25 Aerospace companies
  php bin/console app:enrich-contacts --sector=Aerospace --limit=25

  # Dry run to preview without saving
  php bin/console app:enrich-contacts 42 --dry-run
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Contact Enrichment');

        /** @var string $companyArg */
        $companyArg  = $input->getArgument('company');
        $sectorOpt   = $input->getOption('sector');
        $sector      = is_string($sectorOpt) && $sectorOpt !== '' ? $sectorOpt : null;
        $limitOpt    = $input->getOption('limit');
        $limit       = is_numeric($limitOpt) ? (int) $limitOpt : 10;
        $maxContactsOpt = $input->getOption('max-contacts');
        $maxContacts = is_numeric($maxContactsOpt) ? (int) $maxContactsOpt : 10;
        $dryRun      = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->warning('DRY RUN mode — no contacts will be saved.');
        }

        $companies = $this->resolveCompanies($companyArg, $sector, $limit, $io);
        if (empty($companies)) {
            $io->error('No companies found matching your criteria.');
            return Command::FAILURE;
        }

        $io->info(sprintf('Processing %d company/companies...', count($companies)));
        $io->newLine();

        $totalCreated = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;
        $summaryRows  = [];

        foreach ($companies as $i => $company) {
            $io->section(sprintf(
                '[%d/%d] %s (ID: %d)',
                $i + 1,
                count($companies),
                $company->getName(),
                $company->getId()
            ));

            $io->writeln([
                '  Website: ' . ($company->getWebsite() ?? '<comment>none</comment>'),
                '  Sector:  ' . ($company->getSector() ?? 'N/A'),
                '  Region:  ' . ($company->getRegion() ?? 'N/A'),
            ]);

            if ($dryRun) {
                $io->note('Skipping enrichment (dry-run)');
                continue;
            }

            try {
                $result = $this->enrichmentService->enrichCompanyContacts($company, $maxContacts);

                $created = $result['created'];
                $updated = $result['updated'];
                $skipped = $result['skipped'];
                $sources = $result['sources'];

                $totalCreated += $created;
                $totalUpdated += $updated;
                $totalSkipped += $skipped;

                if ($created > 0 || $updated > 0) {
                    $io->success(sprintf(
                        'Created: %d | Updated: %d | Skipped: %d | Sources: %s',
                        $created, $updated, $skipped, implode(', ', $sources)
                    ));

                    // Show contacts found
                    foreach ($result['contacts'] as $contact) {
                        $icon = $contact->isPrimaryContact() ? '★' : '•';
                        $io->writeln(sprintf(
                            '  %s <info>%s</info> — %s%s%s',
                            $icon,
                            $contact->getFullName(),
                            $contact->getJobTitle() ?? '<comment>no title</comment>',
                            $contact->getEmail() ? ' | ' . $contact->getEmail() : '',
                            $contact->getLinkedinUrl() ? ' | LinkedIn ✓' : ''
                        ));
                    }
                } else {
                    $io->note(sprintf('No contacts found (skipped: %d). Sources tried: %s',
                        $skipped, implode(', ', $sources) ?: 'none'));
                }

                $summaryRows[] = [
                    $company->getName(),
                    $created,
                    $updated,
                    $skipped,
                    implode(', ', $sources),
                ];

            } catch (\Throwable $e) {
                $io->error(sprintf('Failed: %s', $e->getMessage()));
                $summaryRows[] = [
                    $company->getName(),
                    0, 0, 0,
                    'ERROR: ' . $e->getMessage(),
                ];
            }

            $io->newLine();

            // Pause between companies to respect API rate limits
            if ($i < count($companies) - 1) {
                usleep(500_000);
            }
        }

        // Summary
        $io->newLine();
        $io->section('Summary');

        if (!empty($summaryRows)) {
            $io->table(
                ['Company', 'Created', 'Updated', 'Skipped', 'Sources'],
                $summaryRows
            );
        }

        $io->success(sprintf(
            'Total: %d created, %d updated, %d skipped across %d companies',
            $totalCreated, $totalUpdated, $totalSkipped, count($companies)
        ));

        return Command::SUCCESS;
    }

    /**
     * Resolve which companies to enrich.
     *
     * @return Company[]
     */
    private function resolveCompanies(?string $companyArg, ?string $sector, int $limit, SymfonyStyle $io): array
    {
        // Single company by ID or name
        if ($companyArg) {
            $company = null;
            if (is_numeric($companyArg)) {
                $company = $this->companyRepository->find((int) $companyArg);
            }
            if (!$company) {
                $company = $this->companyRepository->findOneBy(['name' => $companyArg]);
            }
            if (!$company) {
                // Partial name match
                /** @var list<\App\Entity\Company> $matches */
                $matches = $this->companyRepository->createQueryBuilder('c')
                    ->where('LOWER(c.name) LIKE :name')
                    ->setParameter('name', '%' . addcslashes(mb_strtolower($companyArg), '%_') . '%')
                    ->setMaxResults(5)
                    ->getQuery()
                    ->getResult();

                if (count($matches) === 1) {
                    $company = $matches[0];
                    $io->note(sprintf('Matched: "%s"', $company->getName()));
                } elseif (count($matches) > 1) {
                    $io->warning('Multiple matches found:');
                    foreach ($matches as $m) {
                        $io->writeln(sprintf('  ID %d: %s', $m->getId(), $m->getName()));
                    }
                    return [];
                }
            }

            return $company ? [$company] : [];
        }

        // Batch by sector
        if ($sector) {
            $qb = $this->companyRepository->createQueryBuilder('c')
                ->leftJoin('c.contacts', 'ct')
                ->where('c.sector = :sector')
                ->groupBy('c.id')
                ->having('COUNT(ct.id) < 3')
                ->orderBy('c.name', 'ASC')
                ->setParameter('sector', $sector)
                ->setMaxResults($limit);

            /** @var list<\App\Entity\Company> $companiesInSector */
            $companiesInSector = $qb->getQuery()->getResult();

            return $companiesInSector;
        }

        return [];
    }
}
