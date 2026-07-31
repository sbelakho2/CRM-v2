<?php

namespace App\Controller;

use App\Service\WebCrawler\CompanyDiscoveryService;
use App\Service\WebCrawler\GoogleDorkService;
use App\Service\ContactEnrichmentService;
use App\Repository\CompanyRepository;
use App\Service\CountryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Psr\Log\LoggerInterface;

#[Route('/webcrawler')]
#[IsGranted('ROLE_USER')]
class WebCrawlerController extends AbstractController
{
    private const TARGET_SECTORS = [
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

    /** Directory where discovery status files are written */
    private const DISCOVERY_STATUS_DIR = 'var/discovery';

    public function __construct(
        private CompanyDiscoveryService $discoveryService,
        private GoogleDorkService $googleDorkService,
        private CountryService $countryService,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private ?ContactEnrichmentService $contactEnrichmentService = null,
        private ?CompanyRepository $companyRepository = null,
        private ?LoggerInterface $logger = null
    ) {}

    #[Route('', name: 'app_webcrawler_index', methods: ['GET'])]
    public function index(): Response
    {
        if (!$this->companyRepository) {
            throw $this->createNotFoundException('Company repository not available');
        }

        $recentCompanies = $this->companyRepository->createQueryBuilder('c')
            ->andWhere('c.companyStatus = :status')
            ->setParameter('status', \App\Entity\Company::STATUS_DISCOVERED)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $allDiscovered = $this->companyRepository->count(['companyStatus' => \App\Entity\Company::STATUS_DISCOVERED]);
        $allApproved = $this->companyRepository->count(['companyStatus' => \App\Entity\Company::STATUS_APPROVED]);
        $allActive = $this->companyRepository->count(['companyStatus' => \App\Entity\Company::STATUS_ACTIVE]);

        $stats = [
            'discovered' => $allDiscovered,
            'approved' => $allApproved,
            'active' => $allActive,
        ];

        $locations = $this->countryService->getRegionOptions(
            CompanyDiscoveryService::getTargetLocations()
        );
        $regionLabels = $locations;
        foreach ($recentCompanies as $company) {
            $tag = $company->getRegion();
            if ($tag && !isset($regionLabels[$tag])) {
                $regionLabels[$tag] = strtoupper((string) $tag);
            }
        }

        return $this->render('webcrawler/index.html.twig', [
            'sectors' => self::TARGET_SECTORS,
            'locations' => $locations,
            'region_labels' => $regionLabels,
            'recent_companies' => $recentCompanies,
            'stats' => $stats,
        ]);
    }

    #[Route('/discover', name: 'app_webcrawler_discover', methods: ['POST'])]
    public function discover(Request $request): JsonResponse
    {
        $sector = $request->request->get('sector');
        $location = $request->request->get('location');
        $locationLabel = $this->resolveLocationLabel($location);
        $sector = is_string($sector) && trim($sector) !== '' ? trim($sector) : null;

        // Resolve region code from the location parameter for the CLI command
        $regionCode = is_string($location) && trim($location) !== '' ? trim($location) : null;

        $statusDir = $this->projectDir . '/' . self::DISCOVERY_STATUS_DIR;
        if (!is_dir($statusDir)) {
            mkdir($statusDir, 0775, true);
        }

        // Check if a discovery is already running
        $pidFile = $statusDir . '/discovery.pid';
        if (file_exists($pidFile)) {
            $existingPid = (int) file_get_contents($pidFile);
            if ($existingPid > 0 && file_exists("/proc/{$existingPid}")) {
                return new JsonResponse([
                    'success' => true,
                    'async' => true,
                    'status' => 'already_running',
                    'message' => 'A discovery run is already in progress.',
                ]);
            }
            // Stale PID file — remove it
            @unlink($pidFile);
        }

        // Build the CLI command
        $consolePath = $this->projectDir . '/bin/console';
        $logFile = $statusDir . '/discovery.log';
        $statusFile = $statusDir . '/discovery.json';

        // Write initial status
        file_put_contents($statusFile, json_encode([
            'status' => 'starting',
            'sector' => $sector,
            'location' => $locationLabel,
            'region' => $regionCode,
            'started_at' => date('c'),
            'pid' => null,
        ]));

        // Build command arguments
        // Use setsid to create a new session so the process is fully detached
        // from the PHP-FPM process group. Without this, `systemctl restart php-fpm`
        // sends SIGTERM to FPM workers which propagates to child processes.
        // nohup only protects against SIGHUP, not SIGTERM.
        $cmd = sprintf(
            'ulimit -n 65536; setsid nohup php %s app:discover-companies',
            escapeshellarg($consolePath)
        );

        if ($sector) {
            $cmd .= ' --sector=' . escapeshellarg($sector);
        }

        if ($locationLabel && !$sector) {
            // When no sector is specified, use --all with --region
            $cmd .= ' --all';
            if ($regionCode) {
                $cmd .= ' --region=' . escapeshellarg($regionCode);
            }
        } elseif ($locationLabel) {
            $cmd .= ' --location=' . escapeshellarg($locationLabel);
        }

        $cmd .= ' --no-interaction -vvv --env=prod';
        $cmd .= ' > ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';

        // Execute in background
        $pid = (int) trim(shell_exec($cmd) ?? '0');

        if ($pid > 0) {
            file_put_contents($pidFile, (string) $pid);
            // Update status with PID
            file_put_contents($statusFile, json_encode([
                'status' => 'running',
                'sector' => $sector,
                'location' => $locationLabel,
                'region' => $regionCode,
                'started_at' => date('c'),
                'pid' => $pid,
            ]));
        }

        return new JsonResponse([
            'success' => true,
            'async' => true,
            'status' => $pid > 0 ? 'started' : 'failed',
            'pid' => $pid,
            'message' => $pid > 0
                ? sprintf('Discovery started (PID %d). %s', $pid,
                    $sector ? "Sector: {$sector}" : 'All sectors')
                : 'Failed to start background discovery process.',
        ]);
    }

    #[Route('/discover-status', name: 'app_webcrawler_discover_status', methods: ['GET'])]
    public function discoverStatus(): JsonResponse
    {
        $statusDir = $this->projectDir . '/' . self::DISCOVERY_STATUS_DIR;
        $pidFile = $statusDir . '/discovery.pid';
        $logFile = $statusDir . '/discovery.log';
        $statusFile = $statusDir . '/discovery.json';

        $status = file_exists($statusFile)
            ? json_decode(file_get_contents($statusFile), true) ?? []
            : [];

        // Check if process is still running
        $running = false;
        $pid = null;
        if (file_exists($pidFile)) {
            $pid = (int) file_get_contents($pidFile);
            $running = $pid > 0 && file_exists("/proc/{$pid}");
            if (!$running) {
                // Process finished — clean up PID file
                @unlink($pidFile);
            }
        }

        // Parse log file for stats
        $stats = $this->parseDiscoveryLogStats($logFile);

        // Count currently discovered companies
        $discoveredCount = 0;
        if ($this->companyRepository) {
            $discoveredCount = $this->companyRepository->count([
                'companyStatus' => \App\Entity\Company::STATUS_DISCOVERED,
            ]);
        }

        return new JsonResponse([
            'running' => $running,
            'pid' => $pid,
            'started_at' => $status['started_at'] ?? null,
            'sector' => $status['sector'] ?? null,
            'location' => $status['location'] ?? null,
            'region' => $status['region'] ?? null,
            'stats' => $stats,
            'discovered_total' => $discoveredCount,
        ]);
    }

    #[Route('/discover-stop', name: 'app_webcrawler_discover_stop', methods: ['POST'])]
    public function discoverStop(): JsonResponse
    {
        $statusDir = $this->projectDir . '/' . self::DISCOVERY_STATUS_DIR;
        $pidFile = $statusDir . '/discovery.pid';

        if (!file_exists($pidFile)) {
            return new JsonResponse(['success' => false, 'message' => 'No discovery running.']);
        }

        $pid = (int) file_get_contents($pidFile);
        if ($pid > 0 && file_exists("/proc/{$pid}")) {
            // Send SIGTERM
            posix_kill($pid, 15);
            usleep(500000); // wait 500ms
            // Force kill if still alive
            if (file_exists("/proc/{$pid}")) {
                posix_kill($pid, 9);
            }
        }

        @unlink($pidFile);

        return new JsonResponse(['success' => true, 'message' => 'Discovery stopped.']);
    }

    #[Route('/search-google', name: 'app_webcrawler_search_google', methods: ['POST'])]
    public function searchGoogle(Request $request): JsonResponse
    {
        set_time_limit(120);

        $sector = $request->request->get('sector');
        $location = $request->request->get('location');
        $locationLabel = $this->resolveLocationLabel($location);
        $customQuery = $request->request->get('custom_query');

        try {
            if ($customQuery) {
                // Custom Google Dork search
                $results = $this->googleDorkService->customSearch($customQuery);
            } else {
                // Standard sector + location search
                $results = $this->googleDorkService->searchCompanies($sector, $locationLabel);
            }

            return new JsonResponse([
                'success' => true,
                'results' => count($results),
                'companies' => $results
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Google search failed', ['exception' => $e]);
            return new JsonResponse([
                'success' => false,
                'error' => 'Search operation failed. Please try again.'
            ], 500);
        }
    }

    #[Route('/keyword-expansion', name: 'app_webcrawler_keyword_expansion', methods: ['GET', 'POST'])]
    public function keywordExpansion(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $baseSector = $request->request->get('sector');
            $baseKeywords = $request->request->get('keywords', '');

            // Generate keyword variations
            $expanded = $this->expandKeywords($baseSector, $baseKeywords);

            return new JsonResponse([
                'success' => true,
                'keywords' => $expanded
            ]);
        }

        return $this->render('webcrawler/keyword_expansion.html.twig', [
            'sectors' => self::TARGET_SECTORS,
        ]);
    }

    #[Route('/dork-generator', name: 'app_webcrawler_dork_generator', methods: ['GET'])]
    public function dorkGenerator(): Response
    {
        $dorkTemplates = [
            'Supplier Portal' => [
                'site:{domain} "supplier portal"',
                'site:{domain} "vendor registration"',
                'site:{domain} inurl:supplier',
            ],
            'Contact Pages' => [
                'site:{domain} "procurement manager"',
                'site:{domain} "purchasing" email',
                'site:{domain} contact',
            ],
            'Company Info' => [
                '"{company}" "{location}" supplier',
                '"{company}" manufacturing {sector}',
            ],
        ];

        return $this->render('webcrawler/dork_generator.html.twig', [
            'dork_templates' => $dorkTemplates,
            'sectors' => self::TARGET_SECTORS,
            'locations' => CompanyDiscoveryService::getTargetLocations(),
        ]);
    }

    #[Route('/enrich-contacts', name: 'app_webcrawler_enrich_contacts', methods: ['POST'])]
    public function enrichContacts(Request $request): JsonResponse
    {
        $companyId = $request->request->get('company_id');

        if (!$companyId) {
            return new JsonResponse(['error' => 'company_id is required'], 400);
        }

        if (!$this->contactEnrichmentService || !$this->companyRepository) {
            return new JsonResponse(['error' => 'Contact enrichment service not available'], 500);
        }

        $company = $this->companyRepository->find($companyId);
        if (!$company) {
            return new JsonResponse(['error' => 'Company not found'], 404);
        }

        try {
            $result = $this->contactEnrichmentService->enrichCompanyContacts($company);

            return new JsonResponse([
                'success' => true,
                'company' => $company->getName(),
                'contacts_created' => $result['created'] ?? 0,
                'contacts_updated' => $result['updated'] ?? 0,
                'contacts_skipped' => $result['skipped'] ?? 0,
                'sources_used' => $result['sources'] ?? [],
                'contacts' => array_map(fn($c) => [
                    'name' => $c->getFirstName() . ' ' . $c->getLastName(),
                    'job_title' => $c->getJobTitle(),
                    'email' => $c->getEmail(),
                    'phone' => $c->getPhone(),
                    'linkedin_url' => $c->getLinkedinUrl(),
                    'source' => $c->getSource(),
                ], $result['contacts'] ?? []),
            ]);
        } catch (\Exception $e) {
            $this->logger?->error('Contact enrichment failed', ['exception' => $e]);
            return new JsonResponse([
                'success' => false,
                'error' => 'Contact enrichment failed. Please try again.',
            ], 500);
        }
    }

    #[Route('/enrich-batch', name: 'app_webcrawler_enrich_batch', methods: ['POST'])]
    public function enrichBatch(Request $request): JsonResponse
    {
        $companyIds = $request->request->all('company_ids');
        $sector = $request->request->get('sector');
        $limit = (int) $request->request->get('limit', 10);

        if (!$this->contactEnrichmentService || !$this->companyRepository) {
            return new JsonResponse(['error' => 'Contact enrichment service not available'], 500);
        }

        try {
            $companies = [];

            if (!empty($companyIds)) {
                // Enrich specific companies
                foreach ($companyIds as $id) {
                    $company = $this->companyRepository->find($id);
                    if ($company) {
                        $companies[] = $company;
                    }
                }
            } elseif ($sector) {
                // Enrich companies by sector that have few/no contacts
                $companies = $this->companyRepository->createQueryBuilder('c')
                    ->leftJoin('c.contacts', 'ct')
                    ->where('c.sector = :sector')
                    ->groupBy('c.id')
                    ->having('COUNT(ct.id) < 3')
                    ->setParameter('sector', $sector)
                    ->setMaxResults($limit)
                    ->getQuery()
                    ->getResult();
            } else {
                return new JsonResponse(['error' => 'Provide company_ids or sector'], 400);
            }

            $results = [];
            foreach ($companies as $company) {
                try {
                    $result = $this->contactEnrichmentService->enrichCompanyContacts($company);
                    $results[] = [
                        'company_id' => $company->getId(),
                        'company_name' => $company->getName(),
                        'contacts_created' => $result['created'] ?? 0,
                        'contacts_updated' => $result['updated'] ?? 0,
                        'success' => true,
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'company_id' => $company->getId(),
                        'company_name' => $company->getName(),
                        'error' => $e->getMessage(),
                        'success' => false,
                    ];
                }
            }

            $totalCreated = array_sum(array_column($results, 'contacts_created'));

            return new JsonResponse([
                'success' => true,
                'companies_processed' => count($results),
                'total_contacts_created' => $totalCreated,
                'results' => $results,
            ]);
        } catch (\Exception $e) {
            $this->logger?->error('Batch enrichment failed', ['exception' => $e]);
            return new JsonResponse([
                'success' => false,
                'error' => 'Batch enrichment failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Parse the discovery log file for pipeline statistics.
     */
    private function parseDiscoveryLogStats(string $logFile): array
    {
        $stats = [
            'log_lines' => 0,
            'llm_accept' => 0,
            'llm_reject' => 0,
            'location_reject' => 0,
            'searches' => 0,
            'saved' => 0,
            'last_activity' => null,
        ];

        if (!file_exists($logFile)) {
            return $stats;
        }

        // Use grep for efficiency (avoid reading entire log into PHP)
        // IMPORTANT: Do NOT use `|| echo 0` with `grep -c`!
        // `grep -c` always outputs a count (even 0) when the file exists,
        // but returns exit code 1 for zero matches. `|| echo 0` would then
        // print an EXTRA "0" line, shifting all subsequent array indices.
        // This was the root cause of the UI showing location-reject count
        // as "saved" count.
        $grepCmd = sprintf(
            'wc -l < %1$s; ' .
            'grep -c "LLM Primary Gate ACCEPT" %1$s 2>/dev/null; true; ' .
            'grep -c "LLM Primary Gate REJECT" %1$s 2>/dev/null; true; ' .
            'grep -c "No location presence" %1$s 2>/dev/null; true; ' .
            'grep -c "LocalSearxngProvider: success" %1$s 2>/dev/null; true; ' .
            'grep -c "Saved company to DB" %1$s 2>/dev/null; true; ' .
            'tail -1 %1$s 2>/dev/null',
            escapeshellarg($logFile)
        );

        $output = shell_exec($grepCmd);
        if ($output) {
            $lines = explode("\n", trim($output));
            $stats['log_lines'] = (int) ($lines[0] ?? 0);
            $stats['llm_accept'] = (int) ($lines[1] ?? 0);
            $stats['llm_reject'] = (int) ($lines[2] ?? 0);
            $stats['location_reject'] = (int) ($lines[3] ?? 0);
            $stats['searches'] = (int) ($lines[4] ?? 0);
            $stats['saved'] = (int) ($lines[5] ?? 0);
            // Last line of log for timestamp
            $lastLine = $lines[6] ?? '';
            if (preg_match('/^(\d{2}:\d{2}:\d{2})\s/', $lastLine, $m)) {
                $stats['last_activity'] = $m[1];
            }
        }

        return $stats;
    }

    private function resolveLocationLabel(?string $location): ?string
    {
        if (!$location) {
            return null;
        }

        $allLocations = CompanyDiscoveryService::getTargetLocations();
        if (isset($allLocations[$location])) {
            return $allLocations[$location];
        }

        return $this->countryService->getRegionName($location);
    }

    /**
     * Expand keywords for better search coverage
     */
    private function expandKeywords(string $sector, string $additionalKeywords): array
    {
        $base = [$sector];

        // Add synonyms
        $synonyms = [
            'Automotive' => ['automotive', 'vehicle', 'car', 'OEM', 'tier 1', 'tier 2', 'EV', 'electric vehicle'],
            'Aerospace' => ['aerospace', 'aviation', 'aircraft', 'defense', 'satellite', 'UAV', 'drone'],
            'Industrial' => ['industrial', 'manufacturing', 'factory', 'production', 'automation', 'robotics'],
            'Rail' => ['railway', 'rail', 'train', 'metro', 'transit', 'rolling stock', 'signalling'],
            'Renewables' => ['renewable', 'solar', 'wind', 'green energy', 'sustainable', 'energy storage', 'EV charger'],
            'Medical' => ['medical device', 'healthcare', 'diagnostic', 'patient monitor', 'surgical', 'life sciences'],
            'Defense' => ['defense', 'military', 'NATO', 'MIL-STD', 'radar', 'electronic warfare', 'C4ISR'],
            'Telecom' => ['telecom', 'telecommunications', '5G', 'network equipment', 'fiber optic', 'IoT'],
            'HVAC' => ['HVAC', 'heating', 'ventilation', 'air conditioning', 'climate control', 'building automation'],
            'Marine' => ['marine', 'shipbuilding', 'naval', 'offshore', 'maritime', 'vessel electronics'],
            'Power Electronics' => ['power electronics', 'inverter', 'converter', 'power supply', 'UPS', 'motor drive'],
            'Consumer Electronics' => ['consumer electronics', 'smart home', 'wearable', 'IoT device', 'appliance'],
            'Data Center' => ['data center', 'server', 'rack', 'cloud infrastructure', 'cooling', 'power distribution'],
            'Energy Storage' => ['energy storage', 'battery', 'BMS', 'lithium-ion', 'grid storage', 'ESS'],
        ];

        if (isset($synonyms[$sector])) {
            $base = array_merge($base, $synonyms[$sector]);
        }

        // Add common terms
        $commonTerms = [
            'supplier',
            'manufacturer',
            'EMS',
            'PCBA',
            'assembly',
            'contract manufacturing',
            'electronics',
        ];

        $base = array_merge($base, $commonTerms);

        // Add user keywords
        if ($additionalKeywords) {
            $userKeywords = array_map('trim', explode(',', $additionalKeywords));
            $base = array_merge($base, $userKeywords);
        }

        return array_unique($base);
    }
}
