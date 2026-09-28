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
use Symfony\Component\Process\Process;
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

    /**
     * Hard cap on how long a launched discovery may be considered "running"
     * from its PID alone: beyond this, the PID is assumed recycled and the
     * run finished. Discovery batches complete well within this budget.
     */
    private const MAX_RUN_SECONDS = 21600; // 6 hours

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
        if (!$this->isCsrfTokenValid('webcrawler_discover', $request->request->get('_token'))) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

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

        // Check if a discovery is already running. PID liveness is probed
        // portably AND capped by a maximum run duration, so a recycled PID
        // can never make a dead run look immortal.
        $pidFile = $statusDir . '/discovery.pid';
        if (file_exists($pidFile)) {
            $existingPid = (int) file_get_contents($pidFile);
            $startedAt = filemtime($pidFile) ?: time();
            if ($this->isProcessRunning($existingPid) && time() - $startedAt < self::MAX_RUN_SECONDS) {
                return new JsonResponse([
                    'success' => true,
                    'async' => true,
                    'status' => 'already_running',
                    'message' => 'A discovery run is already in progress.',
                ]);
            }
            // Stale PID file — remove it
            unlink($pidFile);
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

        // Build the command as an ARGV ARRAY: Symfony\Component\Process
        // executes it without a shell, so there is no command string to
        // inject into and no escapeshellarg juggling. Process::start()
        // detaches the child from this request lifecycle.
        $command = [PHP_BINARY, $consolePath, 'app:discover-companies'];

        if ($sector) {
            $command[] = '--sector=' . $sector;
        }

        if ($locationLabel && !$sector) {
            // When no sector is specified, use --all with --region
            $command[] = '--all';
            if ($regionCode) {
                $command[] = '--region=' . $regionCode;
            }
        } elseif ($locationLabel) {
            $command[] = '--location=' . $locationLabel;
        }

        $command[] = '--no-interaction';
        $command[] = '-vvv';
        $command[] = '--env=prod';

        $process = new Process($command, $this->projectDir, null, null, null);
        $process->setTimeout(null);

        $logHandle = fopen($logFile, 'ab');
        if ($logHandle === false) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Unable to open discovery log file.',
            ], 500);
        }

        $process->start(static function (string $type, string $output) use ($logHandle): void {
            fwrite($logHandle, $output);
        });

        $pid = (int) $process->getPid();

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

        // Check if process is still running (portable liveness probe plus a
        // hard run-duration cap so a reused PID cannot fake liveness).
        $running = false;
        $pid = null;
        if (file_exists($pidFile)) {
            $pid = (int) file_get_contents($pidFile);
            $startedAt = filemtime($pidFile) ?: time();
            $running = $pid > 0
                && time() - $startedAt < self::MAX_RUN_SECONDS
                && $this->isProcessRunning($pid);
            if (!$running) {
                // Process finished — clean up PID file
                unlink($pidFile);
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
    public function discoverStop(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('webcrawler_discover_stop', $request->request->get('_token'))) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

        $statusDir = $this->projectDir . '/' . self::DISCOVERY_STATUS_DIR;
        $pidFile = $statusDir . '/discovery.pid';

        if (!file_exists($pidFile)) {
            return new JsonResponse(['success' => false, 'message' => 'No discovery running.']);
        }

        $pid = (int) file_get_contents($pidFile);
        if ($pid > 0 && $this->isProcessRunning($pid)) {
            // Send SIGTERM, then force-kill if still alive
            posix_kill($pid, 15);
            usleep(500000);
            if ($this->isProcessRunning($pid)) {
                posix_kill($pid, 9);
            }
        }

        if (file_exists($pidFile)) {
            unlink($pidFile);
        }

        return new JsonResponse(['success' => true, 'message' => 'Discovery stopped.']);
    }

    #[Route('/search-google', name: 'app_webcrawler_search_google', methods: ['POST'])]
    public function searchGoogle(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('webcrawler_search_google', $request->request->get('_token'))) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
        }

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
            if (!$this->isCsrfTokenValid('webcrawler_keyword_expansion', $request->request->get('_token'))) {
                return new JsonResponse(['success' => false, 'error' => 'Invalid CSRF token.'], 403);
            }

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
        if (!$this->isCsrfTokenValid('webcrawler_enrich_contacts', $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }

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
        if (!$this->isCsrfTokenValid('webcrawler_enrich_batch', $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }

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

        // Stream the log in PHP instead of assembling a shell pipeline:
        // no shell string, no escapeshellarg, identical accounting for the
        // same markers (single pass over the file).
        $markers = [
            'llm_accept' => 'LLM Primary Gate ACCEPT',
            'llm_reject' => 'LLM Primary Gate REJECT',
            'location_reject' => 'No location presence',
            'searches' => 'LocalSearxngProvider: success',
            'saved' => 'Saved company to DB',
        ];

        $stats['log_lines'] = 0;
        $lastLine = '';
        $handle = fopen($logFile, 'rb');
        if ($handle !== false) {
            while (($line = fgets($handle)) !== false) {
                $stats['log_lines']++;
                foreach ($markers as $key => $marker) {
                    if (str_contains($line, $marker)) {
                        $stats[$key]++;
                    }
                }
                if (trim($line) !== '') {
                    $lastLine = $line;
                }
            }
            fclose($handle);
        }

        if (preg_match('/^(\d{2}:\d{2}:\d{2})\s/', $lastLine, $m)) {
            $stats['last_activity'] = $m[1];
        }

        return $stats;
    }

    /**
     * Portable process-liveness probe. On Linux /proc is authoritative; on
     * macOS/BSD a zero-signal posix probe is used. A positive result does
     * NOT prove the PID still belongs to our discovery run (PIDs are
     * recycled) — callers combine it with a run-duration cap.
     */
    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Linux') {
            return file_exists('/proc/' . $pid);
        }

        return function_exists('posix_kill') && posix_kill($pid, 0);
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
