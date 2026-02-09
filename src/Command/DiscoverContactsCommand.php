<?php

namespace App\Command;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use App\Service\AggressiveContactDiscoveryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:discover-contacts',
    description: 'Aggressively discover key contacts using fast curl scraping + Gemini AI + Google Search',
)]
class DiscoverContactsCommand extends Command
{
    public function __construct(
        private CompanyRepository $companyRepository,
        private AggressiveContactDiscoveryService $discoveryService,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('company', InputArgument::OPTIONAL, 'Company ID or name to discover contacts for')
            ->addOption('sector', 's', InputOption::VALUE_REQUIRED, 'Process all companies in a sector')
            ->addOption('region', 'r', InputOption::VALUE_REQUIRED, 'Process all companies in a region (US, GCC, EU, EG, MA)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max companies to process', '50')
            ->addOption('max-contacts', 'm', InputOption::VALUE_REQUIRED, 'Max contacts per company', '15')
            ->addOption('skip-google', null, InputOption::VALUE_NONE, 'Skip paid Google API calls (scraping + Gemini only)')
            ->addOption('min-contacts', null, InputOption::VALUE_REQUIRED, 'Only process companies with fewer than N contacts', '3')
            ->addOption('batch', 'b', InputOption::VALUE_NONE, 'Process ALL companies with < min-contacts (respects --limit)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview what would happen without saving')
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Skip first N matching companies (for resuming)', '0')
            ->setHelp(<<<'HELP'
Aggressively discover contacts for companies using a fast pipeline:

  Phase 1 (FREE): Fast concurrent website scraping — curl_multi (20 parallel requests)
  Phase 2 (FREE): Gemini AI extraction — structured contact extraction from HTML
  Phase 3 (FREE): Gemini AI enrichment — fill in missing job titles
  Phase 4 (PAID): Google Search → LinkedIn profile discovery (targeted)
  Phase 5 (PAID): Google Search → @domain email discovery

Performance: 100 companies scraped in ~30-60 seconds (no browser/Playwright needed).
Cost optimization: Phases 1-3 are free. Phase 4-5 only trigger when
scraping found < 3 contacts, reducing API costs by ~70%.

Examples:
  # Discover contacts for a specific company
  php bin/console app:discover-contacts "SMTC Corporation"

  # Process all US companies (up to 50)
  php bin/console app:discover-contacts --region=US --limit=50

  # Process Aerospace sector, scraping only (no Google API cost)
  php bin/console app:discover-contacts --sector=Aerospace --skip-google

  # Batch all companies with < 3 contacts
  php bin/console app:discover-contacts --batch --limit=100

  # Resume from company #50
  php bin/console app:discover-contacts --batch --offset=50 --limit=100

  # Free-only pipeline (no Google API at all)
  php bin/console app:discover-contacts --batch --skip-google --limit=200
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🔍 Aggressive Contact Discovery');

        $companyArg  = $input->getArgument('company');
        $sector      = $input->getOption('sector');
        $region      = $input->getOption('region');
        $limit       = (int) $input->getOption('limit');
        $maxContacts = (int) $input->getOption('max-contacts');
        $skipGoogle  = $input->getOption('skip-google');
        $minContacts = (int) $input->getOption('min-contacts');
        $batch       = $input->getOption('batch');
        $dryRun      = $input->getOption('dry-run');
        $offset      = (int) $input->getOption('offset');

        if ($dryRun) {
            $io->warning('DRY RUN — no contacts will be saved.');
        }

        if ($skipGoogle) {
            $io->note('Google API calls DISABLED — using free pipeline only (scraping + Gemini)');
        }

        // Resolve companies
        $companies = $this->resolveCompanies($companyArg, $sector, $region, $limit, $minContacts, $batch, $offset, $io);
        if (empty($companies)) {
            $io->error('No companies found matching your criteria.');
            return Command::FAILURE;
        }

        $io->info(sprintf('Processing %d companies (offset: %d)...', count($companies), $offset));
        $io->newLine();

        // ── Fast batch pre-scraping (curl_multi — all sites in parallel) ──
        $preScrapedMap = [];
        if ($batch) {
            $companiesWithWebsites = array_filter($companies, fn($c) => !empty($c->getWebsite()));
            if (!empty($companiesWithWebsites)) {
                $io->info(sprintf('⚡ Fast-scraping %d websites (20 concurrent connections)...', count($companiesWithWebsites)));
                $batchStart = microtime(true);
                $preScrapedMap = $this->discoveryService->batchScrapeAll($companiesWithWebsites);
                $batchElapsed = round(microtime(true) - $batchStart, 1);
                $io->info(sprintf('⚡ Batch scraping completed in %ds (%d sites)', $batchElapsed, count($preScrapedMap)));
                $io->newLine();
            }
        }

        // Global stats
        $totalCreated = 0;
        $totalUpdated = 0;
        $totalSkipped = 0;
        $companiesWithContacts = 0;
        $summaryRows = [];
        $startTime = microtime(true);

        foreach ($companies as $i => $company) {
            $io->section(sprintf(
                '[%d/%d] %s (ID: %d)',
                $i + 1,
                count($companies),
                $company->getName(),
                $company->getId()
            ));

            $io->writeln([
                '  Website: ' . ($company->getWebsite() ?: '<comment>none</comment>'),
                '  Sector:  ' . ($company->getSector() ?? 'N/A'),
                '  Region:  ' . ($company->getRegion() ?? 'N/A'),
            ]);

            if (!$company->getWebsite()) {
                $io->note('No website — skipping phases 1-2, relying on Google API');
                if ($skipGoogle) {
                    $io->warning('No website AND Google API disabled — skipping this company');
                    $summaryRows[] = [$company->getName(), 0, 0, 0, 'NO_WEBSITE'];
                    continue;
                }
            }

            if ($dryRun) {
                $io->note('Skipping (dry-run)');
                continue;
            }

            try {
                // Look up pre-scraped Playwright data for this company
                $preScrapedData = null;
                if (!empty($preScrapedMap) && $company->getWebsite()) {
                    $website = $company->getWebsite();
                    if (!str_starts_with($website, 'http')) {
                        $website = 'https://' . $website;
                    }
                    $preScrapedData = $preScrapedMap[$website] ?? $preScrapedMap[$company->getWebsite()] ?? null;
                }

                $result = $this->discoveryService->discoverContacts(
                    $company,
                    $maxContacts,
                    $skipGoogle,
                    $preScrapedData,
                );

                $created = $result['created'] ?? 0;
                $updated = $result['updated'] ?? 0;
                $skipped = $result['skipped'] ?? 0;
                $sources = $result['sources'] ?? [];
                $stats   = $result['phase_stats'] ?? [];

                $totalCreated += $created;
                $totalUpdated += $updated;
                $totalSkipped += $skipped;

                if ($created > 0 || $updated > 0) {
                    $companiesWithContacts++;
                    $io->success(sprintf(
                        'Created: %d | Updated: %d | Skipped: %d',
                        $created, $updated, $skipped
                    ));

                    // Show phase breakdown
                    $phaseInfo = [];
                    foreach ($stats as $phase => $count) {
                        if ($count > 0) {
                            $phaseInfo[] = "{$phase}={$count}";
                        }
                    }
                    if (!empty($phaseInfo)) {
                        $io->writeln('  <info>Phase stats:</info> ' . implode(', ', $phaseInfo));
                    }

                    // Show contacts found
                    foreach ($result['contacts'] ?? [] as $contact) {
                        $icon = $contact->isPrimaryContact() ? '★' : '•';
                        $details = [];
                        if ($contact->getJobTitle()) $details[] = $contact->getJobTitle();
                        if ($contact->getEmail()) $details[] = $contact->getEmail();
                        if ($contact->getLinkedinUrl()) $details[] = 'LinkedIn ✓';
                        if ($contact->getPhone()) $details[] = '📞 ✓';

                        $io->writeln(sprintf(
                            '  %s <info>%s</info> — %s',
                            $icon,
                            $contact->getFullName(),
                            implode(' | ', $details) ?: '<comment>name only</comment>'
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
                $summaryRows[] = [$company->getName(), 0, 0, 0, 'ERROR: ' . mb_substr($e->getMessage(), 0, 60)];
                // Reset EntityManager after error to prevent cascading failures
                if (!$this->entityManager->isOpen()) {
                    $this->entityManager = $this->entityManager->create(
                        $this->entityManager->getConnection(),
                        $this->entityManager->getConfiguration()
                    );
                }
                $this->entityManager->clear();
                // Re-fetch remaining companies so they are managed
                $companyRepo = $this->entityManager->getRepository(\App\Entity\Company::class);
                for ($j = $i + 1; $j < count($companies); $j++) {
                    $companies[$j] = $companyRepo->find($companies[$j]->getId());
                }
            }

            $io->newLine();

            // Clear Doctrine UnitOfWork periodically to prevent memory buildup
            if (($i + 1) % 20 === 0) {
                $this->entityManager->clear();
                // Re-fetch remaining companies so they are managed by the fresh UnitOfWork
                $companyRepo = $this->entityManager->getRepository(\App\Entity\Company::class);
                for ($j = $i + 1; $j < count($companies); $j++) {
                    $companies[$j] = $companyRepo->find($companies[$j]->getId());
                }
                $io->writeln(sprintf('<comment>  [Memory: %s MB | Elapsed: %ds]</comment>',
                    round(memory_get_usage(true) / 1048576, 1),
                    round(microtime(true) - $startTime)
                ));
            }
        }

        // Summary
        $elapsed = round(microtime(true) - $startTime, 1);
        $io->newLine();
        $io->section('📊 Summary');

        if (!empty($summaryRows)) {
            $io->table(
                ['Company', 'Created', 'Updated', 'Skipped', 'Sources'],
                array_slice($summaryRows, 0, 50) // Show first 50 rows
            );
            if (count($summaryRows) > 50) {
                $io->note(sprintf('... and %d more companies', count($summaryRows) - 50));
            }
        }

        $io->success(sprintf(
            "Total: %d created, %d updated, %d skipped\n" .
            "Companies with contacts: %d/%d (%.1f%%)\n" .
            "Time: %ds | Memory: %s MB",
            $totalCreated, $totalUpdated, $totalSkipped,
            $companiesWithContacts, count($companies),
            count($companies) > 0 ? ($companiesWithContacts / count($companies) * 100) : 0,
            $elapsed,
            round(memory_get_usage(true) / 1048576, 1)
        ));

        return Command::SUCCESS;
    }

    /**
     * Resolve which companies to process.
     * @return Company[]
     */
    private function resolveCompanies(
        ?string $companyArg,
        ?string $sector,
        ?string $region,
        int $limit,
        int $minContacts,
        bool $batch,
        int $offset,
        SymfonyStyle $io,
    ): array {
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
                $matches = $this->companyRepository->createQueryBuilder('c')
                    ->where('LOWER(c.name) LIKE :name')
                    ->setParameter('name', '%' . mb_strtolower($companyArg) . '%')
                    ->setMaxResults(5)
                    ->getQuery()
                    ->getResult();

                if (count($matches) === 1) {
                    $company = $matches[0];
                    $io->note(sprintf('Matched: "%s"', $company->getName()));
                } elseif (count($matches) > 1) {
                    $io->warning('Multiple matches:');
                    foreach ($matches as $m) {
                        $io->writeln(sprintf('  ID %d: %s', $m->getId(), $m->getName()));
                    }
                    return [];
                }
            }
            return $company ? [$company] : [];
        }

        // Build query for batch/sector/region
        $qb = $this->companyRepository->createQueryBuilder('c')
            ->leftJoin('c.contacts', 'ct')
            ->groupBy('c.id')
            ->having('COUNT(ct.id) < :minContacts')
            ->setParameter('minContacts', $minContacts)
            ->orderBy('c.name', 'ASC');

        if ($sector) {
            $qb->andWhere('c.sector = :sector')->setParameter('sector', $sector);
        }
        if ($region) {
            $qb->andWhere('c.region = :region')->setParameter('region', $region);
        }

        // For batch mode, process all; otherwise require a filter
        if (!$batch && !$sector && !$region) {
            $io->error('Specify --company, --sector, --region, or --batch');
            return [];
        }

        // Prioritize companies with websites (more likely to yield results from scraping)
        $qb->addOrderBy('CASE WHEN c.website IS NOT NULL AND c.website != \'\' THEN 0 ELSE 1 END', 'ASC');

        $qb->setFirstResult($offset)
           ->setMaxResults($limit);

        $companies = $qb->getQuery()->getResult();

        if (!empty($companies)) {
            $withWebsite = count(array_filter($companies, fn($c) => !empty($c->getWebsite())));
            $io->info(sprintf(
                'Found %d companies to process (%d with websites, %d without)',
                count($companies), $withWebsite, count($companies) - $withWebsite
            ));
        }

        return $companies;
    }
}
