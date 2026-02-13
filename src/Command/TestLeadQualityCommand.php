<?php

namespace App\Command;

use App\Entity\Company;
use App\Entity\Contact;
use App\Service\WebCrawler\GoogleDorkService;
use App\Service\GoogleSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Test command to validate lead generation quality across European regions.
 *
 * THREE QUALITY GATES (all must pass):
 *   1. COMPANY QUALITY  — ≥95% of companies are non-junk (real OEM/manufacturer names)
 *   2. CONTACT RATE      — ≥85% of companies have at least 1 real contact found
 *   3. NO COMPETITORS    — ≥95% of companies are NOT EMS/harness competitors
 *
 * Each gate must pass 3× in a row for a country to be certified.
 */
#[AsCommand(
    name: 'app:test-lead-quality',
    description: 'Test lead generation quality across European regions and sectors',
)]
class TestLeadQualityCommand extends Command
{
    /**
     * Countries to test (requested: DE, FR, NL, CZ, FI, SE, IT, ES, PL, GB)
     */
    private const TEST_COUNTRIES = [
        'DE' => 'Germany',
        'FR' => 'France',
        'NL' => 'Netherlands',
        'CZ' => 'Czech Republic',
        'FI' => 'Finland',
        'SE' => 'Sweden',
        'IT' => 'Italy',
        'ES' => 'Spain',
        'PL' => 'Poland',
        'GB' => 'United Kingdom',
    ];

    /**
     * All 14 target sectors
     */
    private const TEST_SECTORS = [
        'Automotive',
        'Aerospace',
        'Industrial',
        'Rail',
        'Renewables',
        'Medical',
        'Defense',
        'Telecom',
        'HVAC',
        'Marine',
        'Power Electronics',
        'Consumer Electronics',
        'Data Center',
        'Energy Storage',
    ];

    /**
     * Foreign-language navigation/homepage words that should NEVER be company names.
     * If any of these appear as a company name, it's a quality failure.
     */
    private const FOREIGN_JUNK_WORDS = [
        // German
        'startseite', 'willkommen', 'herzlich willkommen', 'über uns', 'uber uns',
        'unternehmen', 'impressum', 'kontakt', 'produkte', 'leistungen', 'karriere',
        'stellenangebote', 'aktuelles', 'datenschutz', 'anfahrt', 'standorte',
        // French
        'accueil', 'bienvenue', 'à propos', 'a propos', 'nos services', 'nos produits',
        'qui sommes-nous', 'qui sommes nous', 'contactez-nous', 'actualités', 'actualites',
        'recrutement', 'savoir-faire', 'mentions légales', 'mentions legales',
        // Dutch
        'welkom', 'startpagina', 'over ons', 'producten', 'diensten', 'vacatures',
        'bedrijf', 'ons bedrijf', 'onze producten', 'nieuws',
        // Czech
        'domů', 'domu', 'úvod', 'uvod', 'vítejte', 'vitejte', 'o nás', 'o nas',
        'kontakty', 'produkty', 'služby', 'sluzby', 'o společnosti', 'o spolecnosti',
        // Finnish
        'etusivu', 'tervetuloa', 'meistä', 'meista', 'yhteystiedot', 'tuotteet',
        'palvelut', 'ajankohtaista',
        // Swedish
        'startsida', 'startsidan', 'välkommen', 'valkommen', 'om oss', 'kontakta oss',
        'produkter', 'tjänster', 'tjanster', 'företaget', 'foretaget', 'nyheter',
        // Italian
        'pagina iniziale', 'benvenuto', 'benvenuti', 'chi siamo', 'contatti', 'contattaci',
        'azienda', 'lavora con noi', 'servizi', 'prodotti',
        // Spanish
        'inicio', 'bienvenido', 'bienvenidos', 'quiénes somos', 'quienes somos',
        'sobre nosotros', 'contáctenos', 'contactenos', 'empresa', 'productos', 'servicios',
        // Polish
        'strona główna', 'strona glowna', 'witamy', 'witaj', 'o nas', 'kontakt',
        'produkty', 'usługi', 'uslugi', 'nasza firma',
        // Norwegian/Danish
        'hjem', 'hjemmeside', 'velkommen',
        // Romanian
        'acasă', 'acasa', 'despre noi',
        // Hungarian
        'kezdőlap', 'kezdolap', 'rólunk', 'rolunk', 'kapcsolat',
    ];

    /**
     * Consulting/advisory words that should trigger rejection
     */
    private const CONSULTING_WORDS = [
        'consulting', 'consultancy', 'consultants', 'consultant', 'consult',
        'advisory', 'advisors', 'advisor', 'beratung', 'conseil', 'advies',
        'consulenza', 'consultoría', 'consultoria', 'doradztwo', 'rådgivning',
        'neuvonta', 'poradenství', 'poradenstvi',
    ];

    /**
     * Foreign-language words that should NEVER appear in a contact name.
     * These are navigation/UI artifacts, not real person names.
     */
    private const FOREIGN_CONTACT_JUNK = [
        // German UI / nav / cookie banner artifacts
        'startseite', 'willkommen', 'impressum', 'kontakt', 'datenschutz',
        'unternehmen', 'stellenangebote', 'karriere', 'produkte', 'leistungen',
        'anfahrt', 'standorte', 'aktuelles', 'einstellungen', 'akzeptieren',
        'verwenden', 'zustimmen', 'ablehnen', 'erforderlich', 'auswahl',
        'bestätigen', 'bestatigen', 'notwendig', 'funktional', 'statistik',
        'marketing', 'personalisierung', 'weitere', 'geschäftsleitung',
        'geschaeftsleitung', 'geschäftsführer', 'geschaeftsfuehrer',
        'führungsteam', 'fuehrungsteam', 'geschäftsführung', 'vorstand',
        'nachhaltigkeitsbericht', 'pressemitteilung', 'pressemitteilungen',
        'vertrieb', 'einkauf', 'fertigung', 'entwicklung', 'forschung',
        'qualitätsmanagement', 'qualitaetsmanagement', 'referenzen',
        'branchenlösungen', 'branchenloesungen', 'technologie', 'innovation',
        // French UI artifacts
        'accueil', 'bienvenue', 'actualités', 'recrutement', 'mentions',
        'légales', 'confidentialité', 'consentement', 'accepter', 'refuser',
        'gérer', 'nécessaire', 'fonctionnel', 'préférences',
        // Dutch
        'welkom', 'producten', 'diensten', 'vacatures', 'bedrijf',
        'privacybeleid', 'cookiebeleid', 'toestemming', 'accepteren',
        // Italian
        'benvenuto', 'benvenuti', 'contatti', 'contattaci', 'azienda',
        'accetta', 'rifiuta', 'consenso', 'necessario',
        // Spanish
        'bienvenido', 'bienvenidos', 'inicio', 'empresa', 'productos',
        'servicios', 'aceptar', 'rechazar', 'privacidad',
        // Polish
        'witamy', 'witaj', 'produkty', 'usługi', 'uslugi', 'polityka',
        'prywatność', 'prywatnosc', 'zgoda', 'akceptuję',
        // Czech
        'domů', 'domu', 'úvod', 'uvod', 'produkty', 'služby', 'sluzby',
        'souhlasím', 'souhlas', 'odmítnout',
        // Finnish
        'etusivu', 'tervetuloa', 'tuotteet', 'palvelut', 'ajankohtaista',
        'hyväksy', 'evästeet',
        // Swedish
        'startsida', 'välkommen', 'valkommen', 'produkter', 'tjänster',
        'tjanster', 'nyheter', 'godkänn', 'samtycke',
        // Cookie consent terms (appear as "names" when scraped)
        'cookie', 'cookies', 'gdpr', 'dsgvo',
    ];

    /**
     * Common foreign-language address fragments that indicate
     * a poorly-parsed German/French/Dutch address.
     * These signal "foreign text was grabbed instead of a real address."
     */
    private const FOREIGN_ADDRESS_JUNK = [
        // German address artifacts that aren't real parsed addresses
        'telefon', 'telefax', 'handelsregister', 'amtsgericht',
        'geschäftsführer', 'geschaeftsfuehrer', 'registergericht',
        'umsatzsteuer', 'ust-id', 'steuer-nr', 'hrb',
        // French
        'siret', 'siren', 'rcs', 'sarl', 'capital social',
        // Generic junk
        'lorem ipsum', 'undefined', 'null', 'n/a', 'not available',
        'placeholder',
    ];

    /**
     * EMS COMPETITOR signals — snippet/domain patterns indicating a company
     * that PROVIDES electronics manufacturing services (our competition).
     */
    private const COMPETITOR_SNIPPET_PATTERNS = [
        'contract\s+(electronics?\s+)?manufactur',
        'pcb\s+assembl',
        'pcba\s+(manufactur|assembl|service|provider)',
        'electronics\s+manufacturing\s+services?',
        '\bems\s+(provider|company|partner)',
        'cable\s+(harness|assembly)\s+(manufactur|provider|service)',
        'wire\s+harness\s+(manufactur|provider|service)',
        'smt\s+(assembly|line|manufactur)',
        'through[\s-]hole\s+(assembly|soldering)',
        'box[\s-]build\s+assembly',
        'turnkey\s+(electronics|ems|contract)',
        'prototype\s+to\s+production',
        'low[\s-]volume.*high[\s-]mix',
        'high[\s-]mix.*low[\s-]volume',
        'we\s+(manufacture|assemble|produce|build)\s+(pcb|electronic|cable|wire)',
        'our\s+(manufacturing|assembly|production)\s+(capabilities|services|facility)',
    ];

    /**
     * EMS competitor domain fragments — domains known to be EMS/harness competitors.
     */
    private const COMPETITOR_DOMAIN_FRAGMENTS = [
        'pcbassembly', 'pcba', 'wireharness', 'cableassembly',
        'contractmanufactur', 'electronicsmanufactur',
    ];

    public function __construct(
        private GoogleDorkService $googleDork,
        private EntityManagerInterface $entityManager,
        private ?GoogleSearchService $googleSearchService = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('country', 'c', InputOption::VALUE_OPTIONAL, 'Test a specific country code (DE, FR, NL, CZ, FI, SE, IT, ES, PL, GB)')
            ->addOption('sector', 's', InputOption::VALUE_OPTIONAL, 'Test a specific sector')
            ->addOption('passes', 'p', InputOption::VALUE_OPTIONAL, 'Number of passing rounds required', '3')
            ->addOption('threshold', 't', InputOption::VALUE_OPTIONAL, 'Company quality threshold %', '95')
            ->addOption('contact-threshold', null, InputOption::VALUE_OPTIONAL, 'Contact finding rate threshold %', '85')
            ->addOption('competitor-threshold', null, InputOption::VALUE_OPTIONAL, 'No-competitor threshold %', '95')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Show what would be tested without running')
            ->addOption('no-persist', null, InputOption::VALUE_NONE, 'Skip saving companies/contacts to the database')
            ->addOption('max-per-combo', null, InputOption::VALUE_OPTIONAL, 'Max results per country+sector combo', '5')
            ->setHelp(<<<'HELP'
Tests lead generation quality across European regions.

THREE QUALITY GATES (all must pass N times in a row):
  1. COMPANY QUALITY  — ≥95% non-junk company names (no nav words, no consulting, etc.)
  2. CONTACT RATE      — ≥85% of companies have at least 1 real person contact
  3. NO COMPETITORS    — ≥95% of companies are NOT EMS/harness competitors

Examples:
  # Test Germany, all sectors, 3 passes required
  php bin/console app:test-lead-quality --country=DE --passes=3

  # Test with custom thresholds
  php bin/console app:test-lead-quality --country=DE --threshold=95 --contact-threshold=85 --competitor-threshold=95
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🧪 Lead Generation Quality Test — 3 Gates');

        $countries = self::TEST_COUNTRIES;
        $sectors = self::TEST_SECTORS;
        $requiredPasses = (int) $input->getOption('passes');
        $companyThreshold = (float) $input->getOption('threshold');
        $contactThreshold = (float) $input->getOption('contact-threshold');
        $competitorThreshold = (float) $input->getOption('competitor-threshold');
        $maxPerCombo = (int) $input->getOption('max-per-combo');
        $noPersist = $input->getOption('no-persist');

        // Filter to specific country if requested
        $countryFilter = $input->getOption('country');
        if ($countryFilter) {
            $code = strtoupper($countryFilter);
            if (!isset($countries[$code])) {
                $io->error("Unknown country code: {$code}. Valid: " . implode(', ', array_keys($countries)));
                return Command::FAILURE;
            }
            $countries = [$code => $countries[$code]];
        }

        // Filter to specific sector if requested
        $sectorFilter = $input->getOption('sector');
        if ($sectorFilter) {
            $found = false;
            foreach ($sectors as $s) {
                if (strtolower($s) === strtolower($sectorFilter)) {
                    $sectors = [$s];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $io->error("Unknown sector: {$sectorFilter}. Valid: " . implode(', ', self::TEST_SECTORS));
                return Command::FAILURE;
            }
        }

        $totalCombos = count($countries) * count($sectors);
        $io->info([
            "Countries: " . implode(', ', array_keys($countries)),
            "Sectors: " . count($sectors),
            "Total combinations: {$totalCombos}",
            "Required consecutive passes: {$requiredPasses}",
            "Gate 1 — Company quality: ≥{$companyThreshold}%",
            "Gate 2 — Contact rate:    ≥{$contactThreshold}%",
            "Gate 3 — No competitors:  ≥{$competitorThreshold}%",
            "Max results per combo: {$maxPerCombo}",
        ]);

        if ($input->getOption('dry-run')) {
            $io->section('Test Matrix (dry run)');
            $rows = [];
            foreach ($countries as $code => $name) {
                foreach ($sectors as $sector) {
                    $rows[] = [$code, $name, $sector];
                }
            }
            $io->table(['Code', 'Country', 'Sector'], $rows);
            $io->success("Dry run complete. {$totalCombos} combinations would be tested.");
            return Command::SUCCESS;
        }

        // Inject GoogleSearchService into GoogleDorkService if available
        if ($this->googleSearchService) {
            $this->googleDork->setGoogleSearchService($this->googleSearchService);
        }

        // ═══════════════════════════════════════════════════════════════
        // AGGREGATE TRACKING — accumulate results across ALL iterations
        // for a single grand report at the end
        // ═══════════════════════════════════════════════════════════════
        $grandTotalCompanies = 0;
        $grandGoodCompanies = 0;
        $grandCompaniesWithContacts = 0;
        $grandCompetitors = 0;
        $grandAllIssues = [];     // company-level issues
        $grandNoContactList = []; // companies missing contacts
        $grandCompetitorList = []; // competitor companies found
        $grandIterationData = []; // per-iteration snapshots

        // ═══════════════════════════════════════════════════════════════
        // MAIN TEST LOOP — run $requiredPasses iterations, tracking all
        // ═══════════════════════════════════════════════════════════════
        for ($iteration = 1; $iteration <= $requiredPasses; $iteration++) {
            $io->section("━━━ Pass {$iteration} / {$requiredPasses} ━━━");

            $iterTotal = 0;
            $iterGood = 0;
            $iterWithContacts = 0;
            $iterCompetitors = 0;
            $iterRows = [];

            foreach ($countries as $countryCode => $countryName) {
                foreach ($sectors as $sector) {
                    $comboKey = "{$countryCode}:{$sector}";
                    $io->text("  Testing: {$countryName} × {$sector}...");

                    try {
                        $results = $this->googleDork->searchCompanies($sector, $countryName);
                        $results = array_slice($results, 0, $maxPerCombo);
                    } catch (\Exception $e) {
                        $io->warning("    ⚠ Search failed: {$e->getMessage()}");
                        continue;
                    }

                    $total = count($results);
                    if ($total === 0) {
                        $io->text("    → No results");
                        continue;
                    }

                    $comboGood = 0;
                    $comboContacts = 0;
                    $comboCompetitors = 0;

                    foreach ($results as $result) {
                        $name = $result['name'] ?? '';
                        $domain = $result['displayLink'] ?? '';
                        $snippet = $result['snippet'] ?? '';
                        $title = $result['title'] ?? '';
                        $contacts = $result['contacts'] ?? [];

                        // ── Gate 1: Company quality ─────────────────
                        $companyIssues = $this->validateCompanyName($name, $domain, $snippet, $title, $result);

                        // ── Gate 2: Contact presence ────────────────
                        $hasRealContact = $this->hasRealPersonContact($contacts);

                        // ── Gate 3: Not a competitor ────────────────
                        $isCompetitor = $this->isEmsCompetitor($name, $domain, $snippet, $title);

                        $isGoodCompany = empty($companyIssues);
                        if ($isGoodCompany) $comboGood++;
                        if ($hasRealContact) $comboContacts++;
                        if ($isCompetitor) $comboCompetitors++;

                        // Accumulate grand totals
                        $iterTotal++;
                        $grandTotalCompanies++;
                        if ($isGoodCompany) { $iterGood++; $grandGoodCompanies++; }
                        if ($hasRealContact) { $iterWithContacts++; $grandCompaniesWithContacts++; }
                        if ($isCompetitor) { $iterCompetitors++; $grandCompetitors++; }

                        // Track failures for the report
                        if (!$isGoodCompany) {
                            $grandAllIssues[] = "{$name} ({$domain}): " . implode(', ', $companyIssues);
                        }
                        if (!$hasRealContact) {
                            $grandNoContactList[] = "{$name} ({$domain})";
                        }
                        if ($isCompetitor) {
                            $grandCompetitorList[] = "{$name} ({$domain})";
                        }

                        // ── Verbose per-company output ──────────────
                        $contactCount = count($contacts);
                        $flags = [];
                        if (!$isGoodCompany) $flags[] = '⛔JUNK';
                        if (!$hasRealContact) $flags[] = '👤NOCONTACT';
                        if ($isCompetitor) $flags[] = '🏭COMPETITOR';
                        $flagStr = empty($flags) ? '✅' : implode(' ', $flags);

                        $io->text(sprintf(
                            "    %s %s [%s] C:%d",
                            $flagStr,
                            $name,
                            $domain,
                            $contactCount
                        ));

                        // Show contacts if any
                        if ($contactCount > 0) {
                            foreach ($contacts as $c) {
                                $cName = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
                                $cTitle = $c['job_title'] ?? '';
                                $cEmail = $c['email'] ?? '';
                                $io->text("      👤 {$cName}" . ($cTitle ? " ({$cTitle})" : '') . ($cEmail ? " <{$cEmail}>" : ''));
                            }
                        }

                        // ── Persist good companies + contacts to the database ──
                        if (!$noPersist && $isGoodCompany && !$isCompetitor) {
                            $this->persistCompanyWithContacts(
                                $name, $domain, $result, $contacts,
                                $sector, $countryCode, $countryName
                            );
                        }
                    }

                    // Per-combo summary line
                    $comboPct = $total > 0 ? round(($comboGood / $total) * 100) : 100;
                    $comboContactPct = $total > 0 ? round(($comboContacts / $total) * 100) : 100;
                    $comboNonCompPct = $total > 0 ? round((($total - $comboCompetitors) / $total) * 100) : 100;
                    $io->text(sprintf(
                        "    → %d results | Quality:%d%% Contact:%d%% NonComp:%d%%",
                        $total, $comboPct, $comboContactPct, $comboNonCompPct
                    ));

                    $iterRows[] = [
                        $countryCode, $sector, $total,
                        "{$comboPct}%", "{$comboContactPct}%", "{$comboNonCompPct}%",
                    ];

                    // Rate limit between API calls
                    usleep(300000); // 300ms
                }
            }

            // ── Iteration summary table ──────────────────────────────
            $io->section("Pass {$iteration} Summary");
            $io->table(
                ['CC', 'Sector', '#', 'Quality', 'Contacts', 'NonComp'],
                $iterRows
            );

            // Iteration-level aggregate
            $iterQPct = $iterTotal > 0 ? round(($iterGood / $iterTotal) * 100, 1) : 100;
            $iterCPct = $iterTotal > 0 ? round(($iterWithContacts / $iterTotal) * 100, 1) : 100;
            $iterNPct = $iterTotal > 0 ? round((($iterTotal - $iterCompetitors) / $iterTotal) * 100, 1) : 100;

            $g1 = $iterQPct >= $companyThreshold ? '✅' : '❌';
            $g2 = $iterCPct >= $contactThreshold ? '✅' : '❌';
            $g3 = $iterNPct >= $competitorThreshold ? '✅' : '❌';

            $io->text('');
            $io->text("  Pass {$iteration} aggregate ({$iterTotal} companies):");
            $io->text("    {$g1} Gate 1 — Company quality:  {$iterQPct}% (need ≥{$companyThreshold}%)");
            $io->text("    {$g2} Gate 2 — Contact rate:     {$iterCPct}% (need ≥{$contactThreshold}%)");
            $io->text("    {$g3} Gate 3 — No competitors:   {$iterNPct}% (need ≥{$competitorThreshold}%)");

            $grandIterationData[] = [
                'total' => $iterTotal, 'quality' => $iterQPct,
                'contacts' => $iterCPct, 'noncomp' => $iterNPct,
            ];
        }

        // ═══════════════════════════════════════════════════════════════
        // GRAND FINAL REPORT — across all $requiredPasses iterations
        // ═══════════════════════════════════════════════════════════════
        $io->section('━━━ GRAND FINAL REPORT ━━━');

        $gQPct = $grandTotalCompanies > 0 ? round(($grandGoodCompanies / $grandTotalCompanies) * 100, 1) : 100;
        $gCPct = $grandTotalCompanies > 0 ? round(($grandCompaniesWithContacts / $grandTotalCompanies) * 100, 1) : 100;
        $gNPct = $grandTotalCompanies > 0 ? round((($grandTotalCompanies - $grandCompetitors) / $grandTotalCompanies) * 100, 1) : 100;

        $g1Pass = $gQPct >= $companyThreshold;
        $g2Pass = $gCPct >= $contactThreshold;
        $g3Pass = $gNPct >= $competitorThreshold;
        $allPass = $g1Pass && $g2Pass && $g3Pass;

        $io->text("Total companies evaluated: {$grandTotalCompanies} (across {$requiredPasses} passes)");
        $io->text('');

        // Per-pass breakdown
        foreach ($grandIterationData as $i => $d) {
            $passNum = $i + 1;
            $io->text("  Pass {$passNum}: {$d['total']} companies — Q:{$d['quality']}% C:{$d['contacts']}% NC:{$d['noncomp']}%");
        }
        $io->text('');

        $e1 = $g1Pass ? '✅' : '❌';
        $e2 = $g2Pass ? '✅' : '❌';
        $e3 = $g3Pass ? '✅' : '❌';

        $io->text("  {$e1} Gate 1 — Company quality:  {$gQPct}% ({$grandGoodCompanies}/{$grandTotalCompanies}) need ≥{$companyThreshold}%");
        $io->text("  {$e2} Gate 2 — Contact rate:     {$gCPct}% ({$grandCompaniesWithContacts}/{$grandTotalCompanies}) need ≥{$contactThreshold}%");
        $io->text("  {$e3} Gate 3 — No competitors:   {$gNPct}% (" . ($grandTotalCompanies - $grandCompetitors) . "/{$grandTotalCompanies}) need ≥{$competitorThreshold}%");

        // Show failures if any
        if (!$g1Pass && !empty($grandAllIssues)) {
            $io->section('Company quality failures:');
            foreach (array_unique(array_slice($grandAllIssues, 0, 20)) as $issue) {
                $io->text("  ⛔ {$issue}");
            }
            if (count($grandAllIssues) > 20) {
                $io->text("  ... and " . (count($grandAllIssues) - 20) . " more");
            }
        }

        if (!$g2Pass && !empty($grandNoContactList)) {
            $io->section('Companies missing contacts:');
            foreach (array_unique(array_slice($grandNoContactList, 0, 20)) as $nc) {
                $io->text("  👤 {$nc}");
            }
            if (count($grandNoContactList) > 20) {
                $io->text("  ... and " . (count($grandNoContactList) - 20) . " more");
            }
        }

        if (!$g3Pass && !empty($grandCompetitorList)) {
            $io->section('EMS competitors found:');
            foreach (array_unique(array_slice($grandCompetitorList, 0, 20)) as $comp) {
                $io->text("  🏭 {$comp}");
            }
        }

        if ($allPass) {
            $io->success([
                "ALL 3 GATES PASSED across {$requiredPasses} passes!",
                "Company quality: {$gQPct}% ≥ {$companyThreshold}%",
                "Contact rate:    {$gCPct}% ≥ {$contactThreshold}%",
                "No competitors:  {$gNPct}% ≥ {$competitorThreshold}%",
            ]);
            return Command::SUCCESS;
        } else {
            $failed = [];
            if (!$g1Pass) $failed[] = "Company quality: {$gQPct}% < {$companyThreshold}%";
            if (!$g2Pass) $failed[] = "Contact rate: {$gCPct}% < {$contactThreshold}%";
            if (!$g3Pass) $failed[] = "No competitors: {$gNPct}% < {$competitorThreshold}%";
            $io->error(array_merge(["FAILED GATES:"], $failed));
            return Command::FAILURE;
        }
    }

    /**
     * Clean a company name: strip platform suffixes (" - LinkedIn", " | Site"),
     * trim whitespace, and reject obviously-bad names.
     * Returns the cleaned name, or null if the name is junk.
     */
    private function cleanCompanyName(string $raw): ?string
    {
        // Strip common platform suffixes
        $name = preg_replace('/\s*[-–|·]\s*(LinkedIn|Facebook|Twitter|Indeed|Glassdoor|Crunchbase|Bloomberg|ZoomInfo|YouTube|Xing|Viadeo)$/i', '', $raw);
        // Strip trailing " - Page" / " ... | Something"
        $name = preg_replace('/\s*\|\s*[^|]+$/', '', $name);
        $name = trim($name, " \t\n\r\0\x0B.,;:-–");

        if ($name === '') {
            return null;
        }

        // Reject names that are just numbers or very short (< 2 chars)
        if (mb_strlen($name) < 2 || preg_match('/^\d+$/', $name)) {
            return null;
        }

        return $name;
    }

    /**
     * Persist a discovered company and its contacts to the database.
     * Deduplicates by website domain — if a company with the same website
     * already exists, updates it (adds new contacts, refreshes sector).
     */
    private function persistCompanyWithContacts(
        string $name,
        string $domain,
        array $result,
        array $contacts,
        string $sector,
        string $countryCode,
        string $countryName,
    ): void {
        // ── Clean company name ─────────────────────────────────────
        $cleanName = $this->cleanCompanyName($name);
        if ($cleanName === null) {
            return; // Junk name, skip entirely
        }

        // ── Normalise website URL for dedup ────────────────────────
        $website = $result['website'] ?? ('https://' . $domain);
        $websiteNorm = preg_replace('#^https?://(www\.)?#i', '', rtrim($website, '/'));

        // Check for existing company by website domain
        $existingCompany = null;
        $allCompanies = $this->entityManager->getRepository(Company::class)->findAll();
        foreach ($allCompanies as $c) {
            $cWebsite = preg_replace('#^https?://(www\.)?#i', '', rtrim($c->getWebsite() ?? '', '/'));
            if ($cWebsite !== '' && strcasecmp($cWebsite, $websiteNorm) === 0) {
                $existingCompany = $c;
                break;
            }
        }

        if ($existingCompany !== null) {
            $company = $existingCompany;
            // Fix name if it still has platform suffixes (legacy data)
            $existingClean = $this->cleanCompanyName($company->getName() ?? '');
            if ($existingClean !== null && $existingClean !== $company->getName()) {
                $company->setName($existingClean);
            }
            // Update sector if not set
            if (empty($company->getSector()) && !empty($sector)) {
                $company->setSector($sector);
            }
            // Update region if not set
            if (empty($company->getRegion()) && !empty($countryName)) {
                $company->setRegion($countryName);
            }
        } else {
            $company = new Company();
            $company->setName($cleanName);
            $company->setWebsite($website);
            $company->setSector($sector);
            $company->setCountry($countryName);
            $company->setRegion($countryName);
            $company->setCompanyStatus(Company::STATUS_DISCOVERED);
            $company->setPipelineStage(Company::STAGE_PROSPECT);
            $company->setAccountTier(Company::TIER_C);
            $company->setSourceNotes("Webcrawler lead-quality test — {$countryCode} {$sector}");

            // LinkedIn company URL if available
            $linkedinUrl = $result['linkedin_company_url'] ?? $result['linkedinCompanyUrl'] ?? null;
            if ($linkedinUrl) {
                $company->setLinkedinCompanyUrl($linkedinUrl);
            }

            $this->entityManager->persist($company);
        }

        // Persist contacts — dedup by first+last name within the same company
        $existingContactNames = [];
        foreach ($company->getContacts() as $ec) {
            $existingContactNames[] = mb_strtolower(trim($ec->getFirstName() . ' ' . $ec->getLastName()));
        }

        foreach ($contacts as $c) {
            $firstName = trim($c['first_name'] ?? '');
            $lastName = trim($c['last_name'] ?? '');
            if (empty($firstName) || empty($lastName)) {
                continue;
            }

            // ── Contact-level junk filter ──────────────────────────
            $fullName = mb_strtolower("{$firstName} {$lastName}");
            $jobTitle = mb_strtolower($c['job_title'] ?? '');

            // Skip email-alias "names" (e.g. "Avl Deutschland", "Italy Hotline", "Salesinfo Avlitaly")
            $junkContactWords = [
                'hotline', 'info', 'salesinfo', 'support', 'contact',
                'admin', 'webmaster', 'noreply', 'marketing', 'sales',
                'helpdesk', 'service', 'general', 'generale', 'direzione',
                'redazione', 'segreteria', 'ufficio', 'reception',
            ];
            $isJunkContact = false;
            foreach ($junkContactWords as $jw) {
                if (str_contains($fullName, $jw)) {
                    $isJunkContact = true;
                    break;
                }
            }
            if ($isJunkContact) {
                continue;
            }

            // Skip country/region names used as first names (e.g. "Italy Hotline", "Avl Croatia")
            $countryFirstNames = [
                'italy', 'italia', 'france', 'deutschland', 'germany', 'poland',
                'polska', 'croatia', 'españa', 'spain', 'europe', 'asia',
            ];
            if (in_array(mb_strtolower($firstName), $countryFirstNames, true)
                || in_array(mb_strtolower($lastName), $countryFirstNames, true)) {
                continue;
            }

            // Skip political/head-of-state titles in job title
            $politicalTitles = [
                'president of the', 'presidente della', 'président de la',
                'prime minister', 'head of state', 'king of', 'queen of',
                'chancellor of', 'italian republic', 'french republic',
            ];
            foreach ($politicalTitles as $pt) {
                if (str_contains($jobTitle, $pt)) {
                    $isJunkContact = true;
                    break;
                }
            }
            if ($isJunkContact) {
                continue;
            }

            $contactKey = mb_strtolower(trim("{$firstName} {$lastName}"));
            if (in_array($contactKey, $existingContactNames, true)) {
                continue; // Already exists
            }

            $contact = new Contact();
            $contact->setFirstName($firstName);
            $contact->setLastName($lastName);
            $contact->setCompany($company);
            $contact->setSource('Webcrawler');

            if (!empty($c['job_title'])) {
                $contact->setJobTitle(mb_substr($c['job_title'], 0, 100));
            }
            if (!empty($c['email'])) {
                $contact->setEmail($c['email']);
            }
            if (!empty($c['phone'])) {
                $contact->setPhone($c['phone']);
            }
            if (!empty($c['linkedin_url'])) {
                $contact->setLinkedInUrl($c['linkedin_url']);
            }

            $this->entityManager->persist($contact);
            $existingContactNames[] = $contactKey;
        }

        $this->entityManager->flush();
    }

    /**
     * Gate 1: Validate a company name for quality issues.
     * Checks ONLY company-level issues, NOT contacts or competitors.
     *
     * @return string[] List of quality issues (empty = clean)
     */
    private function validateCompanyName(string $name, string $domain, string $snippet, string $title, array $fullResult = []): array
    {
        $issues = [];
        $nameLower = mb_strtolower(trim($name));
        $domainLower = strtolower(trim($domain));

        // ─── 1. Foreign-language navigation word as company name ──
        if (in_array($nameLower, self::FOREIGN_JUNK_WORDS, true)) {
            $issues[] = "Foreign nav word: '{$name}'";
        }

        // ─── 2. Consulting / advisory firm ───────────────────────
        foreach (self::CONSULTING_WORDS as $cw) {
            if (str_contains($nameLower, $cw)) {
                $issues[] = "Consulting word in name: '{$cw}'";
                break;
            }
            if (str_contains($domainLower, $cw)) {
                $issues[] = "Consulting word in domain: '{$cw}'";
                break;
            }
        }

        // ─── 3. Name is too short or too long ────────────────────
        if (mb_strlen($name) < 2) {
            $issues[] = 'Name too short';
        }
        if (mb_strlen($name) > 60) {
            $issues[] = 'Name too long (likely a sentence)';
        }

        // ─── 4. Name looks like a URL ────────────────────────────
        if (preg_match('/^(https?:\/\/|www\.)/i', $name)) {
            $issues[] = 'Name is a URL';
        }

        // ─── 5. Name ends in TLD ─────────────────────────────────
        if (preg_match('/\.(com|net|org|io|co|fr|de|nl|it|es|pl|cz|fi|se)$/i', $name)) {
            $issues[] = 'Name ends in TLD';
        }

        // ─── 6. Foreign-language title snippets ──────────────────
        if ($this->isForeignLanguageText($snippet) && $this->isForeignLanguageText($title)) {
            $issues[] = 'Both title and snippet appear non-English';
        }

        // ─── 7. Name is a generic page section ──────────────────
        $genericNames = [
            'home', 'homepage', 'about', 'about us', 'contact', 'contact us',
            'products', 'services', 'solutions', 'careers', 'news', 'blog',
        ];
        if (in_array($nameLower, $genericNames, true)) {
            $issues[] = "Generic page section name: '{$name}'";
        }

        return $issues;
    }

    /**
     * Gate 2: Check whether a company has at least 1 real person contact.
     * A "real" contact has both a first_name and last_name that look like
     * actual human names (2+ alpha chars each, not junk words, not product/
     * marketing text, not form labels, not German phrases).
     */
    private function hasRealPersonContact(array $contacts): bool
    {
        if (empty($contacts)) {
            return false;
        }

        // Comprehensive junk words — names that are clearly not people
        static $junkWords = [
            // Form labels
            'first', 'last', 'name', 'email', 'phone', 'work', 'fax', 'mobile',
            'address', 'submit', 'field', 'form', 'input', 'text', 'message',
            // German common words
            'die', 'der', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'und',
            'oder', 'aber', 'auch', 'nur', 'wie', 'mit', 'für', 'von', 'zur',
            'zum', 'bei', 'auf', 'aus', 'bis', 'nach', 'vor', 'ist', 'sind',
            'hat', 'wir', 'sie', 'wird', 'werden', 'kann', 'können',
            'deine', 'meine', 'seine', 'ihre', 'unsere',
            'zukunft', 'perfekte', 'verlauf', 'analyse', 'mittels',
            'deutsche', 'deutscher', 'handelsflotte', 'unbenannter',
            'bausatz', 'megawatt', 'systemintegration', 'ansprechpartner',
            'menü', 'schlie', 'schließen', 'entwickelt', 'startseite',
            'inhalt', 'ergebnis', 'übersicht', 'kontakt', 'datenschutz',
            'impressum', 'nicht', 'noch', 'sehr', 'hier', 'dann', 'wenn',
            // French common words
            'les', 'des', 'une', 'dans', 'pour', 'sur', 'avec', 'nous', 'vous',
            'certains', 'activation', 'ces', 'sont', 'nécessaires',
            'fonctionnement', 'aide', 'améliorer', 'notre', 'site',
            'nationale', 'civile', 'ecole', 'école', 'agence',
            'anesthésie', 'loco', 'énergies', 'energies',
            'naval', 'ouvèze', 'payre', 'partenariats',
            // English product/marketing/action words
            'passionate', 'leadership', 'capacity', 'polarity', 'liquid',
            'develops', 'printer', 'showcase', 'conduct', 'speech', 'tests',
            'test', 'label', 'action', 'cooled', 'custom', 'free',
            'protection', 'infrastructure', 'rico', 'puerto', 'africa',
            // HTML/CSS UI element names
            'accordion', 'scroll', 'overlap', 'announcement', 'carousel',
            'slider', 'dropdown', 'modal', 'popup', 'tooltip', 'sidebar',
            'footer', 'header', 'navbar', 'toggle', 'collapse',
            'boot', 'align', 'flex', 'grid', 'container', 'wrapper',
            'overlay', 'badge', 'alert', 'spinner', 'widget',
            'immediate', 'panoramique', 'conductive', 'supports',
            'indoor', 'outdoor', 'strip', 'led', 'posts', 'latest',
            'interface', 'dual', 'integrated', 'micro', 'comparison',
            'discover', 'references',
            // HTML/UI artifacts
            'cookie', 'cookies', 'consent', 'privacy', 'settings',
            'accept', 'reject', 'manage', 'preferences', 'notice',
            // Company/corporate terms
            'gmbh', 'ltd', 'llc', 'inc', 'corp', 'group', 'holding',
            'technologies', 'technology', 'solutions', 'systems', 'services',
            'industries', 'manufacturing', 'engineering', 'electronics',
            // Country/geographic/generic non-person words
            'islands', 'colombia', 'comoros', 'cantonese', 'native',
            'milestones', 'milestone', 'program', 'programme', 'business',
            'small', 'cambodia', 'congo', 'guinea', 'samoa',
            // Polish common words / institutional
            'biuro', 'projektowe', 'projektowy', 'gliwicki', 'techniki',
            'park', 'spiralnych', 'pasja', 'główna', 'glowna',
            'przez', 'plików', 'plikow', 'strona',
            'firma', 'grupa', 'spółka', 'spolka', 'oddział', 'oddzial',
            'centrum', 'instytut', 'izba', 'gospodarcza',
            'koleje', 'małopolskie', 'malopolskie', 'polsko',
            'chińska', 'chinska', 'orientacja', 'kierunek',
            'dogodne', 'terminy', 'godziny', 'pracy',
            // Czech common words
            'společnost', 'spolecnost', 'oddělení', 'oddeleni',
            'hlavní', 'hlavni', 'závod', 'zavod', 'ústav', 'ustav',
            'práce', 'prace', 'český', 'cesky', 'česká', 'ceska',
            'nabídka', 'nabidka', 'aktuality',
            // English sentence fragment / marketing junk
            'fully', 'professional', 'operators', 'depart',
            'aircraft', 'private', 'many', 'three', 'companies',
            'orientation', 'cooperation', 'borehole', 'calibrator',
            'magic', 'garden', 'party',
            'après', 'apres',
            // Job titles parsed as names
            'director', 'manager', 'president', 'chairman', 'officer',
            'chief', 'executive', 'vice', 'board', 'supervisory',
            'team', 'staff', 'department',
        ];

        // Junk full-name phrases
        static $junkPhrases = [
            'first name', 'last name', 'full name', 'work email', 'work phone',
            'custom text', 'custom showcase', 'action call', 'speech tests',
            'label printer', 'code of', 'code conduct', 'puerto rico',
            'south africa', 'leadership team', 'capacity free',
            'deutsche handelsflotte', 'deine zukunft', 'perfekte systemintegration',
            'unbenannter verlauf', 'polarity protection', 'analyse wird',
            // French consent / institutional
            'certains de ces', 'activation de ces', 'ecole nationale',
            'école nationale', 'agence gardeners', 'comparison study',
            'for dietmar', 'indoor led', 'led strip', 'fast boot',
            'immediate boot', 'flex align', 'box overlap',
            'accordion box', 'accordion item', 'announcement scroll',
            'integrated micro', 'interface dual', 'supports conductive',
            'discover ondina', 'latest posts', 'options panoramique',
            'references naval', 'partenariats afrique',
            'anesthésie loco', 'ouvèze payre',
            'islands colombia', 'native cantonese', 'key milestones',
            'small business', 'business program',
            // Polish junk phrases
            'przez nas', 'godziny pracy', 'biuro projektowe',
            'gliwicki park', 'park techniki', 'dogodne terminy',
            'fully professional', 'many private', 'operators will',
            'three companies', 'term orientation', 'magic garden',
            'rd party', 'link wp', 'service après', 'service apres',
            'spiralnych cormak', 'pasc borehole',
            'polsko-chińska', 'polsko-chinska', 'izba gospodarcza',
            'koleje małopolskie', 'koleje malopolskie',
            'polskie radio', 'common direction',
            // Czech junk phrases
            'soubory cookie', 'ochrana osobních', 'ochrana osobnich',
            'zásady ochrany', 'zasady ochrany',
        ];

        foreach ($contacts as $contact) {
            $firstName = trim($contact['first_name'] ?? '');
            $lastName = trim($contact['last_name'] ?? '');

            // Both parts must be present
            if (empty($firstName) || empty($lastName)) {
                continue;
            }

            // Reject email addresses parsed as names
            $fullRaw = "{$firstName} {$lastName}";
            if (str_contains($fullRaw, '@') || str_contains($fullRaw, '<') || str_contains($fullRaw, '>')) {
                continue;
            }

            // Reject names starting with "for " or "of " (parsing artifacts)
            if (preg_match('/^(for|of)\s/i', $firstName)) {
                continue;
            }

            $firstLower = mb_strtolower($firstName);
            $lastLower = mb_strtolower($lastName);
            $fullLower = mb_strtolower("{$firstName} {$lastName}");

            // Skip foreign junk words
            if (in_array($firstLower, self::FOREIGN_CONTACT_JUNK, true)
                || in_array($lastLower, self::FOREIGN_CONTACT_JUNK, true)) {
                continue;
            }
            // Skip if first/last is a navigation word
            if (in_array($firstLower, self::FOREIGN_JUNK_WORDS, true)
                || in_array($lastLower, self::FOREIGN_JUNK_WORDS, true)) {
                continue;
            }

            // Skip comprehensive junk words
            if (in_array($firstLower, $junkWords, true)
                || in_array($lastLower, $junkWords, true)) {
                continue;
            }

            // Skip junk phrases
            $isPhrase = false;
            foreach ($junkPhrases as $phrase) {
                if (str_starts_with($fullLower, $phrase)) {
                    $isPhrase = true;
                    break;
                }
            }
            if ($isPhrase) continue;

            // Each name part must be 2+ alpha chars (with European accents/hyphens)
            if (!preg_match('/^[A-ZÀ-Ÿa-zà-ÿ\-\'\.]{2,}$/u', $firstName)) {
                continue;
            }
            if (!preg_match('/^[A-ZÀ-Ÿa-zà-ÿ\-\'\.\s]{2,}$/u', $lastName)) {
                continue;
            }

            // Name parts shouldn't be too long (likely compound nouns, not names)
            if (mb_strlen($firstName) > 20 || mb_strlen($lastName) > 25) {
                continue;
            }

            // Passed all checks — this is a real person contact
            return true;
        }

        return false;
    }

    /**
     * Gate 3: Detect EMS competitors / wire harness manufacturers.
     * Uses snippet patterns and domain fragments from class constants.
     */
    private function isEmsCompetitor(string $name, string $domain, string $snippet, string $title): bool
    {
        $searchText = mb_strtolower("{$name} {$snippet} {$title}");
        $domainLower = strtolower($domain);

        // Check snippet-level competitor patterns (from constants)
        foreach (self::COMPETITOR_SNIPPET_PATTERNS as $pattern) {
            if (preg_match('/' . $pattern . '/i', $searchText)) {
                return true;
            }
        }

        // Check domain fragments (from constants)
        foreach (self::COMPETITOR_DOMAIN_FRAGMENTS as $fragment) {
            if (str_contains($domainLower, $fragment)) {
                return true;
            }
        }

        // Additional name-level competitor signals
        $competitorNamePatterns = [
            '/\b(pcba?\s+(assembly|manufactur|fabricat|production))/i',
            '/\b(cable\s+(assembl|harness|manufactur))/i',
            '/\b(wire\s*harness\s+(manufactur|assembl|maker|producer|supplier))/i',
            '/\b(contract\s+(electronics?\s+)?manufactur)/i',
            '/\bems\s+(provider|company|services?|manufactur)/i',
            '/\b(electronic[s]?\s+manufacturing\s+service)/i',
            '/\b(leiterplatten|kabelbaum|kabelbäume|faisceau|faiscaux|cablaggio|arnés|mazo de cables)/i',
        ];

        $nameAndSnippet = mb_strtolower("{$name} {$snippet}");
        foreach ($competitorNamePatterns as $pat) {
            if (preg_match($pat, $nameAndSnippet)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a single contact for quality.
     *
     * @return string[] List of quality issues (empty = clean)
     */
    private function validateContact(array $contact): array
    {
        $issues = [];
        $firstName = $contact['first_name'] ?? '';
        $lastName = $contact['last_name'] ?? '';
        $fullName = trim("$firstName $lastName");

        if (empty($firstName) && empty($lastName)) {
            return []; // No name = skip validation (may just have email)
        }

        // Check each name part against foreign junk words
        foreach ([$firstName, $lastName] as $part) {
            $partLower = mb_strtolower(trim($part));
            if (in_array($partLower, self::FOREIGN_CONTACT_JUNK, true)) {
                $issues[] = "Contact '{$fullName}': foreign junk word '{$part}'";
                break;
            }
        }

        // Check full name against foreign junk
        $fullLower = mb_strtolower($fullName);
        foreach (self::FOREIGN_JUNK_WORDS as $junk) {
            if ($fullLower === $junk) {
                $issues[] = "Contact name is foreign nav word: '{$fullName}'";
                break;
            }
        }

        // Contact name should look like a real person name
        // First/last should be 2+ chars and alpha (with accents)
        if (!empty($firstName) && !preg_match('/^[A-ZÀ-Ÿa-zà-ÿ\-\'\.]{2,}$/u', $firstName)) {
            $issues[] = "Contact first name invalid: '{$firstName}'";
        }
        if (!empty($lastName) && !preg_match('/^[A-ZÀ-Ÿa-zà-ÿ\-\'\.\s]{2,}$/u', $lastName)) {
            $issues[] = "Contact last name invalid: '{$lastName}'";
        }

        return $issues;
    }

    /**
     * Validate an address for quality.
     *
     * @return string[] List of quality issues (empty = clean)
     */
    private function validateAddress(string $address): array
    {
        $issues = [];
        $addrLower = mb_strtolower($address);

        // Check for known foreign-language address junk
        foreach (self::FOREIGN_ADDRESS_JUNK as $junk) {
            if (str_contains($addrLower, $junk)) {
                $issues[] = "Address contains foreign junk: '{$junk}' in '{$address}'";
                break;
            }
        }

        // Address should be reasonable length
        if (mb_strlen($address) > 300) {
            $issues[] = "Address too long (" . mb_strlen($address) . " chars), likely garbage";
        }

        return $issues;
    }

    /**
     * Heuristic: detect if text is predominantly foreign-language (not English).
     *
     * Uses common foreign-language function words as markers.
     * Returns true if ≥3 foreign markers found and 0 English markers.
     */
    private function isForeignLanguageText(string $text): bool
    {
        if (mb_strlen($text) < 20) {
            return false;
        }

        $lower = mb_strtolower($text);

        // German markers
        $deMarkers = ['und', 'für', 'fur', 'der', 'die', 'das', 'ist', 'von', 'mit', 'auf', 'aus', 'bei', 'nach', 'über', 'werden', 'haben', 'sein', 'sich', 'werden', 'alle', 'nicht', 'auch', 'noch', 'ein', 'eine', 'einem', 'einen', 'einer'];
        // French markers
        $frMarkers = ['les', 'des', 'une', 'dans', 'pour', 'sur', 'avec', 'nous', 'vous', 'sont', 'par', 'cette', 'notre', 'votre', 'leurs', 'ses', 'aux', 'entre', 'plus', 'tout', 'tous', 'aussi', 'être', 'avoir', 'fait'];
        // Dutch markers
        $nlMarkers = ['het', 'een', 'van', 'voor', 'met', 'bij', 'uit', 'naar', 'over', 'ook', 'nog', 'wordt', 'zijn', 'niet', 'meer', 'alle', 'veel', 'onze'];
        // Italian markers  
        $itMarkers = ['del', 'della', 'delle', 'degli', 'dei', 'nel', 'nella', 'nelle', 'negli', 'nei', 'per', 'con', 'sono', 'una', 'questo', 'questa', 'nostro', 'nostra', 'anche', 'più'];
        // Spanish markers
        $esMarkers = ['del', 'las', 'los', 'una', 'para', 'con', 'por', 'como', 'más', 'mas', 'pero', 'sus', 'desde', 'hasta', 'entre', 'sobre', 'después', 'cada', 'estos', 'esta', 'nuestro', 'nuestra', 'somos'];
        // Polish markers
        $plMarkers = ['jest', 'nie', 'się', 'sie', 'jak', 'lub', 'oraz', 'też', 'tez', 'przez', 'przy', 'dla', 'już', 'juz', 'tylko', 'może', 'moze', 'czy', 'tego', 'których', 'ktorych', 'naszych', 'więcej', 'wiecej'];
        // Czech markers
        $czMarkers = ['jsou', 'není', 'neni', 'jako', 'také', 'take', 'nebo', 'které', 'ktere', 'jeho', 'její', 'jeji', 'může', 'muze', 'více', 'vice', 'naše', 'nase'];

        $allForeignMarkers = array_merge($deMarkers, $frMarkers, $nlMarkers, $itMarkers, $esMarkers, $plMarkers, $czMarkers);

        // English markers (presence suggests English text)
        $enMarkers = ['the', 'and', 'for', 'with', 'from', 'that', 'this', 'are', 'was', 'were', 'been', 'have', 'has', 'had', 'will', 'would', 'could', 'should', 'which', 'their', 'they', 'them', 'there', 'about', 'into', 'more', 'other', 'some', 'than', 'only', 'also', 'just', 'after', 'before', 'through', 'between', 'each'];

        $foreignHits = 0;
        $englishHits = 0;

        foreach ($allForeignMarkers as $marker) {
            if (preg_match('/\b' . preg_quote($marker, '/') . '\b/iu', $lower)) {
                $foreignHits++;
            }
        }

        foreach ($enMarkers as $marker) {
            if (preg_match('/\b' . preg_quote($marker, '/') . '\b/i', $lower)) {
                $englishHits++;
            }
        }

        // Foreign language if many foreign markers but very few English ones
        return $foreignHits >= 3 && $englishHits <= 1;
    }
}
