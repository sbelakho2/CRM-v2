<?php

namespace App\Command;

use App\Entity\Company;
use App\Entity\Contact;
use App\Repository\CompanyRepository;
use App\Service\WebCrawler\CompanyDiscoveryService;
use App\Service\WebCrawler\GoogleDorkService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:quality-audit',
    description: 'Run webcrawler across regions/sectors and audit quality metrics',
)]
class QualityAuditCommand extends Command
{
    private const REGIONS = ['MA', 'US', 'EU', 'GCC', 'EG', 'TN'];

    private const SECTORS = [
        'Automotive', 'Aerospace', 'Industrial', 'Rail', 'Renewables',
        'Medical', 'Defense', 'Telecom', 'HVAC', 'Marine',
        'Power Electronics', 'Consumer Electronics', 'Data Center', 'Energy Storage',
    ];

    /**
     * Sample locations per region (1 per region to avoid excessive API use).
     */
    private const SAMPLE_LOCATIONS = [
        'MA'  => 'Casablanca Morocco',
        'US'  => 'Texas',
        'EU'  => 'Germany',
        'GCC' => 'Dubai UAE',
        'EG'  => 'Cairo Egypt',
        'TN'  => 'Tunis Tunisia',
    ];

    public function __construct(
        private CompanyDiscoveryService $discoveryService,
        private GoogleDorkService       $googleDorkService,
        private EntityManagerInterface  $em,
        private CompanyRepository       $companyRepo,
        private ManagerRegistry         $doctrine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sectors', 's', InputOption::VALUE_OPTIONAL, 'Comma-separated sector list (default: all)', null)
            ->addOption('regions', 'r', InputOption::VALUE_OPTIONAL, 'Comma-separated region list (default: MA,US,EU,GCC,EG)', null)
            ->addOption('audit-only', null, InputOption::VALUE_NONE, 'Only audit existing DB data, don\'t run new discovery')
            ->addOption('wipe', null, InputOption::VALUE_NONE, 'Wipe all discovered companies + their contacts before running')
            ->addOption('no-interaction', 'n', InputOption::VALUE_NONE, 'Skip confirmation prompts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🔍 Webcrawler Quality Audit');

        $sectors = $input->getOption('sectors')
            ? explode(',', $input->getOption('sectors'))
            : self::SECTORS;
        $regions = $input->getOption('regions')
            ? explode(',', $input->getOption('regions'))
            : self::REGIONS;
        $auditOnly = $input->getOption('audit-only');
        $wipe = $input->getOption('wipe');

        // ── WIPE PHASE: Delete all discovered companies + contacts ──
        if ($wipe && !$auditOnly) {
            $io->section('Phase 0: Wiping discovered companies and contacts');

            // Delete contacts belonging to discovered companies
            $contactsDeleted = $this->em->createQuery(
                'DELETE FROM App\\Entity\\Contact c WHERE c.company IN ('
                . 'SELECT comp FROM App\\Entity\\Company comp WHERE comp.companyStatus = :status'
                . ')'
            )->setParameter('status', Company::STATUS_DISCOVERED)->execute();

            // Delete discovered companies
            $companiesDeleted = $this->em->createQuery(
                'DELETE FROM App\\Entity\\Company c WHERE c.companyStatus = :status'
            )->setParameter('status', Company::STATUS_DISCOVERED)->execute();

            $io->success("Wiped {$companiesDeleted} discovered companies and {$contactsDeleted} contacts.");
        }

        // ── PHASE 1: Run discovery (unless --audit-only) ─────────
        if (!$auditOnly) {
            $totalRuns = count($sectors) * count($regions);
            $io->section("Phase 1: Discovery — {$totalRuns} sector×region combinations");

            $quotaEstimate = $totalRuns * 5; // ~5 queries per combo
            $io->warning([
                "This will use approximately {$quotaEstimate} Google Custom Search API queries.",
                'Free tier: 100 queries/day. Overage: $5/1000 queries.',
                'Estimated cost: $' . number_format(max(0, $quotaEstimate - 100) * 0.005, 2),
            ]);

            $discoveredTotal = 0;
            $runIndex = 0;

            foreach ($sectors as $sector) {
                $sector = trim($sector);
                foreach ($regions as $region) {
                    $region = trim(strtoupper($region));
                    $location = self::SAMPLE_LOCATIONS[$region] ?? $region;
                    $runIndex++;

                    $io->writeln(sprintf(
                        "  [%d/%d] <info>%s</info> × <comment>%s</comment> (%s)",
                        $runIndex, $totalRuns, $sector, $region, $location
                    ));

                    try {
                        $companies = $this->discoveryService->discoverCompanies($sector, $location);
                        $discoveredTotal += count($companies);
                        $io->writeln(sprintf('         → <info>%d</info> companies discovered', count($companies)));
                    } catch (\Throwable $e) {
                        $io->writeln(sprintf('         → <error>ERROR</error>: %s', $e->getMessage()));
                        // Reset EntityManager if it was closed by the error
                        if (!$this->em->isOpen()) {
                            $this->em = $this->doctrine->resetManager();
                        }
                    }

                    // Rate-limit between runs
                    usleep(500000);
                }
            }

            $io->success("Discovery complete: {$discoveredTotal} new companies saved.");
        }

        // ── PHASE 2: Quality audit on all discovered companies ───
        $io->section('Phase 2: Quality Audit');

        $allCompanies = $this->companyRepo->createQueryBuilder('c')
            ->where('c.companyStatus = :status')
            ->setParameter('status', Company::STATUS_DISCOVERED)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $totalCompanies = count($allCompanies);
        if ($totalCompanies === 0) {
            $io->warning('No discovered companies in the database. Run without --audit-only first.');
            return Command::SUCCESS;
        }

        $io->writeln("Auditing <info>{$totalCompanies}</info> discovered companies...\n");

        // ── Compute metrics ──────────────────────────────────────
        $junkCount = 0;
        $junkReasons = [];
        $hasContactCount = 0;
        $hasAddressCount = 0;
        $hasPhoneCount = 0;
        $hasWebsiteCount = 0;
        $hasLinkedInCount = 0;
        $hasDescriptionCount = 0;
        $regionBreakdown = [];
        $sectorBreakdown = [];
        $junkExamples = [];

        foreach ($allCompanies as $company) {
            /** @var Company $company */
            $name = $company->getName();
            $sector = $company->getSector() ?? 'Unknown';
            $region = $company->getRegion() ?? 'Unknown';

            // Initialize breakdowns
            if (!isset($regionBreakdown[$region])) {
                $regionBreakdown[$region] = ['total' => 0, 'junk' => 0, 'contacts' => 0, 'address' => 0];
            }
            if (!isset($sectorBreakdown[$sector])) {
                $sectorBreakdown[$sector] = ['total' => 0, 'junk' => 0, 'contacts' => 0, 'address' => 0];
            }

            $regionBreakdown[$region]['total']++;
            $sectorBreakdown[$sector]['total']++;

            // ── Junk detection ───────────────────────────────────
            $isJunk = $this->isJunkCompany($company);
            if ($isJunk) {
                $junkCount++;
                $regionBreakdown[$region]['junk']++;
                $sectorBreakdown[$sector]['junk']++;
                $reason = $this->getJunkReason($company);
                $junkReasons[$reason] = ($junkReasons[$reason] ?? 0) + 1;
                if (count($junkExamples) < 30) {
                    $junkExamples[] = [
                        'name' => $name,
                        'website' => $company->getWebsite() ?? 'N/A',
                        'sector' => $sector,
                        'reason' => $reason,
                    ];
                }
                continue; // Don't count junk in contact/address stats
            }

            // ── Contact check ────────────────────────────────────
            $contacts = $this->em->getRepository(Contact::class)->findBy(['company' => $company]);
            $hasRealContact = false;
            foreach ($contacts as $contact) {
                $fn = trim($contact->getFirstName() ?? '');
                $ln = trim($contact->getLastName() ?? '');
                if (!empty($fn) && !empty($ln)) {
                    // Reject fake "General Contact" fallback entries
                    if ($fn === 'General' && $ln === 'Contact') {
                        continue;
                    }
                    $hasRealContact = true;
                    break;
                }
            }
            if ($hasRealContact) {
                $hasContactCount++;
                $regionBreakdown[$region]['contacts']++;
                $sectorBreakdown[$sector]['contacts']++;
            }

            // ── Address check ────────────────────────────────────
            $address = $company->getAddress();
            $city = $company->getCity();
            $country = $company->getCountry();
            $hasAddress = (!empty($address) && mb_strlen($address) >= 5)
                || (!empty($city) && !empty($country));
            if ($hasAddress) {
                $hasAddressCount++;
                $regionBreakdown[$region]['address']++;
                $sectorBreakdown[$sector]['address']++;
            }

            // ── Other enrichment ─────────────────────────────────
            if (!empty($company->getWebsite())) $hasWebsiteCount++;
            if (!empty($company->getLinkedinCompanyUrl())) $hasLinkedInCount++;
            $notes = $company->getNotes() ?? '';
            if (!empty($notes) && mb_strlen($notes) >= 20) $hasDescriptionCount++;
            if (str_contains($notes, '📞')) $hasPhoneCount++;
        }

        // ── Report ───────────────────────────────────────────────
        $nonJunkCount = $totalCompanies - $junkCount;
        $nonJunkPct = $totalCompanies > 0 ? round(($nonJunkCount / $totalCompanies) * 100, 1) : 0;
        $contactPct = $nonJunkCount > 0 ? round(($hasContactCount / $nonJunkCount) * 100, 1) : 0;
        $addressPct = $nonJunkCount > 0 ? round(($hasAddressCount / $nonJunkCount) * 100, 1) : 0;

        $io->section('📊 Overall Quality Metrics');
        $passNonJunk = $nonJunkPct >= 95;
        $passContacts = $contactPct >= 90;
        $passAddresses = $addressPct >= 90;

        $io->table(
            ['Metric', 'Value', 'Target', 'Status'],
            [
                ['Total Companies', $totalCompanies, '', ''],
                ['Non-Junk', "{$nonJunkCount}/{$totalCompanies} ({$nonJunkPct}%)", '≥ 95%', $passNonJunk ? '✅ PASS' : '❌ FAIL'],
                ['With Contacts', "{$hasContactCount}/{$nonJunkCount} ({$contactPct}%)", '≥ 90%', $passContacts ? '✅ PASS' : '❌ FAIL'],
                ['With Addresses', "{$hasAddressCount}/{$nonJunkCount} ({$addressPct}%)", '≥ 90%', $passAddresses ? '✅ PASS' : '❌ FAIL'],
                ['With Website', "{$hasWebsiteCount}/{$nonJunkCount}", '', ''],
                ['With LinkedIn', "{$hasLinkedInCount}/{$nonJunkCount}", '', ''],
                ['With Phone', "{$hasPhoneCount}/{$nonJunkCount}", '', ''],
                ['With Description', "{$hasDescriptionCount}/{$nonJunkCount}", '', ''],
            ]
        );

        // ── Region breakdown ─────────────────────────────────────
        $io->section('📍 Quality by Region');
        $regionRows = [];
        foreach ($regionBreakdown as $reg => $data) {
            $nonJunk = $data['total'] - $data['junk'];
            $junkPct = $data['total'] > 0 ? round(($data['junk'] / $data['total']) * 100, 1) : 0;
            $conPct = $nonJunk > 0 ? round(($data['contacts'] / $nonJunk) * 100, 1) : 0;
            $addrPct = $nonJunk > 0 ? round(($data['address'] / $nonJunk) * 100, 1) : 0;
            $regionRows[] = [$reg, $data['total'], "{$data['junk']} ({$junkPct}%)", "{$data['contacts']} ({$conPct}%)", "{$data['address']} ({$addrPct}%)"];
        }
        $io->table(['Region', 'Total', 'Junk', 'With Contacts', 'With Address'], $regionRows);

        // ── Sector breakdown ─────────────────────────────────────
        $io->section('🏭 Quality by Sector');
        $sectorRows = [];
        foreach ($sectorBreakdown as $sec => $data) {
            $nonJunk = $data['total'] - $data['junk'];
            $junkPct = $data['total'] > 0 ? round(($data['junk'] / $data['total']) * 100, 1) : 0;
            $conPct = $nonJunk > 0 ? round(($data['contacts'] / $nonJunk) * 100, 1) : 0;
            $addrPct = $nonJunk > 0 ? round(($data['address'] / $nonJunk) * 100, 1) : 0;
            $sectorRows[] = [$sec, $data['total'], "{$data['junk']} ({$junkPct}%)", "{$data['contacts']} ({$conPct}%)", "{$data['address']} ({$addrPct}%)"];
        }
        $io->table(['Sector', 'Total', 'Junk', 'With Contacts', 'With Address'], $sectorRows);

        // ── Junk breakdown by reason ─────────────────────────────
        if (!empty($junkReasons)) {
            $io->section('🗑️ Junk Breakdown by Reason');
            arsort($junkReasons);
            $reasonRows = [];
            foreach ($junkReasons as $reason => $count) {
                $reasonRows[] = [$reason, $count, round(($count / $junkCount) * 100, 1) . '%'];
            }
            $io->table(['Reason', 'Count', '% of Junk'], $reasonRows);
        }

        // ── Junk examples ────────────────────────────────────────
        if (!empty($junkExamples)) {
            $io->section('🗑️ Junk Examples (first 30)');
            $exampleRows = [];
            foreach ($junkExamples as $ex) {
                $exampleRows[] = [$ex['name'], $ex['website'], $ex['sector'], $ex['reason']];
            }
            $io->table(['Name', 'Website', 'Sector', 'Reason'], $exampleRows);
        }

        // ── Final verdict ────────────────────────────────────────
        $io->section('🏁 Verdict');
        if ($passNonJunk && $passContacts && $passAddresses) {
            $io->success('ALL QUALITY THRESHOLDS MET ✅');
            return Command::SUCCESS;
        } else {
            $failures = [];
            if (!$passNonJunk) $failures[] = "Non-junk: {$nonJunkPct}% (need ≥95%)";
            if (!$passContacts) $failures[] = "Contacts: {$contactPct}% (need ≥90%)";
            if (!$passAddresses) $failures[] = "Addresses: {$addressPct}% (need ≥90%)";
            $io->error('QUALITY THRESHOLDS NOT MET: ' . implode(' | ', $failures));
            return Command::FAILURE;
        }
    }

    /**
     * Determine if a company is junk based on heuristics.
     */
    private function isJunkCompany(Company $company): bool
    {
        $name = strtolower($company->getName());

        // ── Pattern 1: No name or very short ─────────────────────
        if (empty($name) || mb_strlen($name) < 3) {
            return true;
        }

        // ── Pattern 2: Name is a URL/domain ──────────────────────
        if (preg_match('/^(https?:\/\/|www\.)/', $name)) {
            return true;
        }

        // ── Pattern 3: Name is generic page title ────────────────
        $genericTitles = [
            'home', 'about', 'about us', 'contact', 'contact us', 'login',
            'welcome', 'products', 'services', 'solutions', 'careers',
            'news', 'blog', 'privacy', 'terms', 'cookie', 'search results',
            'page not found', '404', 'error', 'untitled', 'test',
        ];
        if (in_array($name, $genericTitles, true)) {
            return true;
        }

        // ── Pattern 4: Textile / apparel / garment ───────────────
        if (preg_match('/\b(textile|apparel|garment|knitwear|tannery|leather\s+goods|footwear|embroidery|weaving|spinning|dyeing|carpet)\b/i', $name)) {
            return true;
        }

        // ── Pattern 5: Trade body / association / chamber ────────
        if (preg_match('/\b(chamber\s+of\s+commerce|trade\s+association|manufacturers?\s+association|business\s+council|employers?\s+federation|exporters?\s+council|industry\s+body)\b/i', $name)) {
            return true;
        }

        // ── Pattern 6: Packaging / printing ──────────────────────
        if (preg_match('/\b(packaging\s+company|corrugated|carton\s+manufactur|printing\s+house|label\s+manufactur)\b/i', $name)) {
            return true;
        }

        // ── Pattern 7: Plastic injection / rubber / foundry ──────
        if (preg_match('/\b(plastic\s+injection|injection\s+mold|blow\s+mold|rubber\s+molding|foundry|scrap\s+metal|steel\s+mill)\b/i', $name)) {
            return true;
        }

        // ── Pattern 8: Furniture / glass / ceramics ──────────────
        if (preg_match('/\b(furniture\s+manufactur|woodworking|glass\s+manufactur|ceramic\s+tile)\b/i', $name)) {
            return true;
        }

        // ── Pattern 9: News site / media / magazine ──────────────
        if (preg_match('/\b(news|magazine|journal|daily|tribune|times|gazette|herald|post|chronicle|review|digest)\b/i', $name)) {
            // Check if it's truly a news org by checking website
            $website = strtolower($company->getWebsite() ?? '');
            if (preg_match('/news|media|press|journal|magazine/i', $website)) {
                return true;
            }
        }

        // ── Pattern 10: Consulting / services / recruitment ──────
        if (preg_match('/\b(consulting\s+firm|recruitment|staffing\s+agency|market\s+research|law\s+firm|insurance\s+company)\b/i', $name)) {
            return true;
        }

        // ── Pattern 11: Check website domain for junk patterns ───
        $website = strtolower($company->getWebsite() ?? '');
        $junkDomainPatterns = [
            'wikipedia.org', 'youtube.com', 'facebook.com', 'twitter.com',
            'linkedin.com', 'instagram.com', 'reddit.com', 'amazon.com',
            'ebay.com', 'alibaba.com', 'news', 'blog', '.gov',
        ];
        foreach ($junkDomainPatterns as $pattern) {
            if (str_contains($website, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get a human-readable reason why a company is junk.
     */
    private function getJunkReason(Company $company): string
    {
        $name = strtolower($company->getName());
        $website = strtolower($company->getWebsite() ?? '');

        if (empty($name) || mb_strlen($name) < 3) return 'Empty/short name';
        if (preg_match('/^(https?:\/\/|www\.)/', $name)) return 'Name is URL';
        if (preg_match('/\b(textile|apparel|garment|knitwear|tannery|leather|footwear|embroidery|weaving|spinning|dyeing|carpet)\b/i', $name)) return 'Textile/apparel';
        if (preg_match('/\b(chamber\s+of|trade\s+assoc|manufacturers?\s+assoc|business\s+council|exporters?\s+council)\b/i', $name)) return 'Trade body/association';
        if (preg_match('/\b(packaging|corrugated|carton|printing\s+house|label\s+manufactur)\b/i', $name)) return 'Packaging/printing';
        if (preg_match('/\b(plastic\s+injection|injection\s+mold|blow\s+mold|rubber|foundry|scrap|steel\s+mill)\b/i', $name)) return 'Plastic/foundry/steel';
        if (preg_match('/\b(furniture|woodworking|glass\s+manufactur|ceramic)\b/i', $name)) return 'Furniture/glass/ceramics';
        if (preg_match('/\b(news|magazine|journal|daily|tribune|times)\b/i', $name)) return 'News/media';
        if (preg_match('/\b(consulting|recruitment|staffing|market\s+research|law\s+firm|insurance)\b/i', $name)) return 'Consulting/services';

        foreach (['wikipedia.org', 'youtube.com', 'facebook.com', 'linkedin.com', 'reddit.com', '.gov'] as $p) {
            if (str_contains($website, $p)) return "Junk domain ({$p})";
        }

        $genericTitles = ['home', 'about', 'contact', 'welcome', 'products', 'services', 'careers', 'news', 'blog'];
        if (in_array($name, $genericTitles, true)) return 'Generic page title';

        return 'Other';
    }
}
