<?php

namespace App\Controller;

use App\Entity\Lead;
use App\Message\LeadDeepScrapeMessage;
use App\Repository\LeadRepository;
use App\Service\GoogleSearchService;
use App\Service\LeadAnalysisService;
use App\Service\WebCrawler\GoogleDorkService;
use App\Service\WebCrawler\CompanyDiscoveryService;
use App\Service\WebCrawler\LeadScoringService;
use App\Service\CountryService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMapping;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Psr\Log\LoggerInterface;

/**
 * Automated Lead Discovery Pipeline Controller
 * 
 * Provides a unified interface for:
 * 1. Running Google Dork searches via the API (not manual)
 * 2. Auto-importing unique domains as leads
 * 3. Triggering deep scraping for contact enrichment
 * 4. Monitoring pipeline status
 * 
 * Workflow: Dork Query → Search API → Dedupe → Create Leads → Deep Scrape Queue
 */
#[Route('/discovery-pipeline')]
#[IsGranted('ROLE_USER')]
class DiscoveryPipelineController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GoogleSearchService $googleSearchService,
        private GoogleDorkService $googleDorkService,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private CountryService $countryService,
        private LeadAnalysisService $leadAnalysisService,
        private LeadRepository $leadRepository,
        private LeadScoringService $leadScoringService
    ) {
        // Inject GoogleSearchService into GoogleDorkService for automated searches
        $this->googleDorkService->setGoogleSearchService($googleSearchService);
    }

    /**
     * Show the automated discovery dashboard
     */
    #[Route('', name: 'discovery_pipeline_index', methods: ['GET'])]
    public function index(): Response
    {
        // Get recent pipeline runs stats
        $stats = $this->getPipelineStats();
        
        // Pipeline status (running if scraped in last 5 minutes)
        $fiveMinAgo = new \DateTime('-5 minutes');
        $recentlyScraped = $this->entityManager->getRepository(Lead::class)
            ->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.lastScrapedAt >= :time')
            ->setParameter('time', $fiveMinAgo)
            ->getQuery()
            ->getSingleScalarResult();
        
        $pipelineStatus = ((int)$recentlyScraped) > 0 ? 'running' : 'idle';
        
        // Get pipeline run history (grouped by creation date)
        // Using native SQL because DATE() is a MySQL-specific function not registered in Doctrine DQL
        $emptyJson = '[]';
        $rsm = new ResultSetMapping();
        $rsm->addScalarResult('run_date', 'run_date');
        $rsm->addScalarResult('leads_found', 'leads_found');
        $rsm->addScalarResult('contacts_enriched', 'contacts_enriched');

        $sql = "SELECT DATE(l.created_at) AS run_date, COUNT(l.id) AS leads_found,
                       SUM(CASE WHEN l.contact_emails_public IS NOT NULL AND l.contact_emails_public != :empty THEN 1 ELSE 0 END) AS contacts_enriched
                FROM leads l
                GROUP BY run_date
                ORDER BY run_date DESC
                LIMIT 5";

        $pipelineHistory = $this->entityManager
            ->createNativeQuery($sql, $rsm)
            ->setParameter('empty', $emptyJson)
            ->getResult();
        
        // Compute time taken for each run (estimate based on lead count)
        foreach ($pipelineHistory as &$run) {
            $run['time_taken'] = max(30, min(300, (int)$run['leads_found'] * 3)); // 3s per lead, capped 30-300s
            $run['status'] = 'completed';
        }
        unset($run);
        
        // Total contacts found (leads with emails)
        $contactsFound = $this->entityManager->getRepository(Lead::class)
            ->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.contactEmailsPublic IS NOT NULL')
            ->andWhere('l.contactEmailsPublic != :empty')
            ->setParameter('empty', $emptyJson)
            ->getQuery()
            ->getSingleScalarResult();
        
        $funnelAnalytics = $this->leadAnalysisService->getFunnelAnalytics();
        $qualityTrend = $this->leadAnalysisService->getLeadQualityTrend();
        $actionableInsights = $this->leadAnalysisService->getActionableInsights();

        return $this->render('discovery_pipeline/index.html.twig', [
            'sectors' => $this->getSectors(),
            'locations' => $this->getLocations(),
            'stats' => $stats,
            'pipeline_status' => $pipelineStatus,
            'pipeline_history' => $pipelineHistory,
            'contacts_found' => (int)$contactsFound,
            'funnel_analytics' => $funnelAnalytics,
            'quality_trend' => $qualityTrend,
            'actionable_insights' => $actionableInsights,
        ]);
    }

    /**
     * Run automated discovery pipeline for a sector/location
     */
    #[Route('/run', name: 'discovery_pipeline_run', methods: ['POST'])]
    public function runPipeline(Request $request): JsonResponse
    {
        /** @var array<string, mixed>|null $data */
        $data = json_decode($request->getContent(), true);
        if (!$this->isCsrfTokenValid('discovery_pipeline_run', $data['_csrf_token'] ?? '')) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }
        
        $sector = $data['sector'] ?? null;
        $location = $data['location'] ?? null;
        $locationLabel = $this->resolveLocationLabel($location);
        $enableDeepScrape = $data['enable_deep_scrape'] ?? true;
        $autoImport = $data['auto_import'] ?? true;
        
        if (!$sector) {
            return new JsonResponse(['error' => 'Sector is required'], 400);
        }
        
        // Validate sector against allowed values
        $allowedSectors = array_map('strtolower', $this->getSectors());
        if (!in_array(strtolower($sector), $allowedSectors, true)) {
            return new JsonResponse([
                'error' => 'Invalid sector. Allowed: ' . implode(', ', $this->getSectors())
            ], 400);
        }
        
        $this->logger->info('Starting discovery pipeline', [
            'sector' => $sector,
            'location' => $location,
            'deep_scrape' => $enableDeepScrape,
        ]);
        
        try {
            // Step 1: Run Google Dork searches through the API
            $searchResults = $this->googleDorkService->searchCompanies(
                $sector, 
                $locationLabel, 
                executeSearch: true
            );
            
            if (empty($searchResults)) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'No results found',
                    'stats' => [
                        'searched' => 0,
                        'imported' => 0,
                        'duplicates' => 0,
                        'queued_for_scrape' => 0
                    ]
                ]);
            }
            
            // Step 2: Deduplicate and import as leads
            $importStats = [
                'searched' => count($searchResults),
                'imported' => 0,
                'duplicates' => 0,
                'queued_for_scrape' => 0,
                'leads' => []
            ];
            
            if ($autoImport) {
                // Build an in-memory dedupe index (dupeKey / website / name /
                // domain buckets) once, so duplicate detection is O(1) instead
                // of O(n) similar_text against every stored lead.
                $dupeIndex = $this->buildDupeIndex();

                foreach ($searchResults as $result) {
                    $importResult = $this->importAsLead($result, $sector, $location, $dupeIndex);
                    
                    if ($importResult['status'] === 'imported') {
                        $importStats['imported']++;
                        $importStats['leads'][] = [
                            'id' => $importResult['lead']->getId(),
                            'name' => $importResult['lead']->getCompanyName(),
                            'website' => $importResult['lead']->getWebsiteRoot()
                        ];
                        
                        // Step 3: Queue for deep scraping if enabled
                        if ($enableDeepScrape && $importResult['lead']->getWebsiteRoot()) {
                            $this->messageBus->dispatch(new LeadDeepScrapeMessage(
                                $importResult['lead']->getId(),
                                $importResult['lead']->getWebsiteRoot(),
                                ['use_llm' => false, 'max_pages' => 5]
                            ));
                            $importStats['queued_for_scrape']++;
                        }
                    } elseif ($importResult['status'] === 'duplicate') {
                        $importStats['duplicates']++;
                    }
                }
            }
            
            $this->entityManager->flush();
            
            $this->logger->info('Discovery pipeline completed', $importStats);
            
            return new JsonResponse([
                'success' => true,
                'message' => sprintf(
                    'Pipeline completed: %d found, %d imported, %d duplicates, %d queued for enrichment',
                    $importStats['searched'],
                    $importStats['imported'],
                    $importStats['duplicates'],
                    $importStats['queued_for_scrape']
                ),
                'stats' => $importStats
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Discovery pipeline failed', [
                'sector' => $sector,
                'error' => $e->getMessage()
            ]);
            
            return new JsonResponse([
                'error' => 'Pipeline operation failed. Please try again.'
            ], 500);
        }
    }

    /**
     * Preview what a pipeline run would find (without importing)
     */
    #[Route('/preview', name: 'discovery_pipeline_preview', methods: ['POST'])]
    public function previewPipeline(Request $request): JsonResponse
    {
        /** @var array<string, mixed>|null $data */
        $data = json_decode($request->getContent(), true);
        $csrfToken = is_array($data) ? ($data['_csrf_token'] ?? null) : null;
        $csrfToken = $csrfToken ?? $request->headers->get('X-CSRF-Token');
        if (!$this->isCsrfTokenValid('discovery_pipeline_preview', (string) $csrfToken)) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }
        
        $sector = $data['sector'] ?? null;
        $location = $data['location'] ?? null;
        $locationLabel = $this->resolveLocationLabel($location);
        
        if (!$sector) {
            return new JsonResponse(['error' => 'Sector is required'], 400);
        }
        
        // Validate sector against allowed values
        $allowedSectors = array_map('strtolower', $this->getSectors());
        if (!in_array(strtolower($sector), $allowedSectors, true)) {
            return new JsonResponse([
                'error' => 'Invalid sector. Allowed: ' . implode(', ', $this->getSectors())
            ], 400);
        }
        
        try {
            $searchResults = $this->googleDorkService->searchCompanies(
                $sector, 
                $locationLabel, 
                executeSearch: true
            );
            
            // Check for duplicates
            $preview = [];
            foreach ($searchResults as $result) {
                $website = $result['website'] ?? null;
                $isDuplicate = false;
                
                if ($website) {
                    $existing = $this->entityManager->getRepository(Lead::class)
                        ->findOneBy(['websiteRoot' => $website]);
                    $isDuplicate = $existing !== null;
                }
                
                $preview[] = [
                    'name' => $result['name'] ?? $result['title'] ?? 'Unknown',
                    'website' => $website,
                    'snippet' => $result['snippet'] ?? '',
                    'is_duplicate' => $isDuplicate
                ];
            }
            
            return new JsonResponse([
                'success' => true,
                'total' => count($preview),
                'new_leads' => count(array_filter($preview, fn($p) => !$p['is_duplicate'])),
                'duplicates' => count(array_filter($preview, fn($p) => $p['is_duplicate'])),
                'results' => $preview
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Preview operation failed. Please try again.'], 500);
        }
    }

    /**
     * Trigger deep scrape for existing leads without contact info
     */
    #[Route('/enrich-existing', name: 'discovery_pipeline_enrich', methods: ['POST'])]
    public function enrichExistingLeads(Request $request): JsonResponse
    {
        /** @var array<string, mixed>|null $data */
        $data = json_decode($request->getContent(), true);
        if (!$this->isCsrfTokenValid('discovery_pipeline_enrich', $data['_csrf_token'] ?? '')) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }

        $limit = min($data['limit'] ?? 50, 100);
        
        // Find leads with websites but no emails
        $leads = $this->entityManager->getRepository(Lead::class)
            ->createQueryBuilder('l')
            ->where('l.websiteRoot IS NOT NULL')
            ->andWhere('l.contactEmailsPublic IS NULL OR l.contactEmailsPublic = :empty')
            ->setParameter('empty', '[]')
            ->setMaxResults($limit)
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
        
        $queued = 0;
        foreach ($leads as $lead) {
            $this->messageBus->dispatch(new LeadDeepScrapeMessage(
                $lead->getId(),
                $lead->getWebsiteRoot(),
                ['use_llm' => false, 'max_pages' => 5]
            ));
            $queued++;
        }
        
        return new JsonResponse([
            'success' => true,
            'message' => sprintf('Queued %d leads for enrichment', $queued),
            'queued' => $queued
        ]);
    }

    /**
     * Import a search result as a Lead
     * @param array<string|int, mixed> $result
     */
    private /**
 * @param array<string|int, mixed> $result
 */
function importAsLead(array $result, string $sector, ?string $location, array &$dupeIndex): array
    {
        $website = $result['website'] ?? null;
        $name = $result['name'] ?? $this->extractCompanyNameFromTitle(
            $result['title'] ?? '',
            $result['displayLink'] ?? $result['website'] ?? ''
        );
        
        if (!$name) {
            return ['status' => 'skipped', 'reason' => 'No company name'];
        }

        $dupeKey = $this->generateDupeKey($name, $website);
        $domainKey = $website ? $this->domainKeyFromWebsite($website) : '';

        // O(1) duplicate detection via the in-memory index
        if ($website && isset($dupeIndex['website'][$website])) {
            return ['status' => 'duplicate', 'lead' => $dupeIndex['website'][$website]];
        }
        if ($dupeKey && isset($dupeIndex['dupeKey'][$dupeKey])) {
            return ['status' => 'duplicate', 'lead' => $dupeIndex['dupeKey'][$dupeKey]];
        }
        
        // Fuzzy name match — catch variants like "Acme Corp" vs "Acme Corporation".
        // Only run against the few candidates sharing the same domain, instead
        // of scanning every stored lead.
        if ($domainKey !== '' && isset($dupeIndex['domain'][$domainKey])) {
            $newName = strtolower(trim($name));
            foreach ($dupeIndex['domain'][$domainKey] as $existingRow) {
                $existingName = strtolower(trim((string) ($existingRow['companyName'] ?? '')));
                if ($existingName === '' || $newName === '') {
                    continue;
                }
                $similarity = similar_text($existingName, $newName);
                $maxLen = max(strlen($existingName), strlen($newName));
                if ($maxLen > 0 && ($similarity / $maxLen) >= 0.85) {
                    return ['status' => 'duplicate', 'lead' => $existingRow];
                }
            }
        }
        
        // Check for duplicate by name (exact)
        $nameKey = strtolower(trim($name));
        if ($nameKey !== '' && isset($dupeIndex['name'][$nameKey])) {
            return ['status' => 'duplicate', 'lead' => $dupeIndex['name'][$nameKey]];
        }
        
        // Create new lead
        $lead = new Lead();
        $lead->setCompanyName($name);
        $lead->setWebsiteRoot($website);
        $lead->setLeadUrl($result['link'] ?? null);
        
        // Multi-sector analysis
        $sectors = [$sector]; // Start with primary sector
        $snippet = $result['snippet'] ?? '';
        $sectorKeywords = [
            'automotive' => 'Automotive',
            'aerospace' => 'Aerospace', 'aviation' => 'Aerospace',
            'medical' => 'Medical', 'healthcare' => 'Medical',
            'defense' => 'Defense', 'military' => 'Defense',
            'telecom' => 'Telecom', 'telecommunication' => 'Telecom',
            'industrial' => 'Industrial',
            'renewable' => 'Renewables', 'solar' => 'Renewables', 'wind' => 'Renewables',
            'rail' => 'Rail', 'railway' => 'Rail',
            'hvac' => 'HVAC',
            'marine' => 'Marine', 'maritime' => 'Marine',
            'consumer' => 'Consumer Electronics',
            'data center' => 'Data Center',
            'energy storage' => 'Energy Storage', 'battery' => 'Energy Storage',
        ];
        $lowerSnippet = mb_strtolower($snippet);
        foreach ($sectorKeywords as $keyword => $sectorName) {
            if (str_contains($lowerSnippet, $keyword) && !in_array($sectorName, $sectors)) {
                $sectors[] = $sectorName;
            }
        }
        $lead->setSectorTags($sectors);
        
        $lead->setSiteLocation($this->resolveLocationLabel($location));
        $lead->setRegionTag($this->determineRegion($location));
        $notes = sprintf(
            "[%s] Auto-discovered via pipeline\nSource query: %s\nSnippet: %s",
            date('Y-m-d H:i'),
            $result['source_query'] ?? 'N/A',
            $result['snippet'] ?? ''
        );
        $lead->setNotesAuto(mb_substr($notes, 0, 500));
        $lead->setReviewStatus('pending');
        
        // Use the scoring engine for actual lead quality assessment
        $scoreData = $this->leadScoringService->scoreLead([
            'company_name' => $name,
            'website_root' => $website,
            'lead_url' => $result['link'] ?? null,
            'region_tag' => $this->determineRegion($location),
            'site_location' => $this->resolveLocationLabel($location),
            'sector_tags' => $sectors,
            'page_content' => $snippet,
            'address' => $snippet,
        ]);
        $lead->setLeadScore($scoreData['score'] ?? 30);
        
        $lead->setCreatedAt(new \DateTimeImmutable());
        
        // Generate dupe key for future deduplication
        $lead->setDupeKey($dupeKey);
        
        $this->entityManager->persist($lead);

        // Register the new lead in the in-batch index so subsequent results in
        // the same run are deduplicated against it without DB round-trips.
        $indexRow = ['id' => null, 'companyName' => $name, 'websiteRoot' => $website, 'dupeKey' => $dupeKey];
        if ($website) {
            $dupeIndex['website'][$website] = $indexRow;
            if ($domainKey !== '') {
                $dupeIndex['domain'][$domainKey][] = $indexRow;
            }
        }
        if ($dupeKey) {
            $dupeIndex['dupeKey'][$dupeKey] = $indexRow;
        }
        $nameKey = strtolower(trim($name));
        if ($nameKey !== '') {
            $dupeIndex['name'][$nameKey] = $indexRow;
        }
        
        return ['status' => 'imported', 'lead' => $lead];
    }

    /**
     * Build an in-memory dedupe index over all stored leads:
     *   website  => exact websiteRoot match
     *   dupeKey  => normalized name + domain key match
     *   name     => exact (lowercased) company name match
     *   domain   => list of leads sharing a domain (candidates for fuzzy check)
     */
    private function buildDupeIndex(): array
    {
        $rows = $this->entityManager->getRepository(Lead::class)
            ->createQueryBuilder('l')
            ->select('l.id, l.companyName, l.websiteRoot, l.dupeKey')
            ->getQuery()
            ->getResult();

        $index = ['website' => [], 'dupeKey' => [], 'name' => [], 'domain' => []];
        foreach ($rows as $row) {
            $website = $row['websiteRoot'] ?? null;
            if ($website) {
                $index['website'][$website] = $row;
                $domain = $this->domainKeyFromWebsite($website);
                if ($domain !== '') {
                    $index['domain'][$domain][] = $row;
                }
            }
            $dupeKey = $row['dupeKey'] ?? null;
            if ($dupeKey) {
                $index['dupeKey'][$dupeKey] = $row;
            }
            $nameKey = strtolower(trim((string) ($row['companyName'] ?? '')));
            if ($nameKey !== '') {
                $index['name'][$nameKey] = $row;
            }
        }

        return $index;
    }

    /**
     * Extract the bare domain (no scheme, port, or www prefix) from a website
     */
    private function domainKeyFromWebsite(string $website): string
    {
        $host = parse_url($website, PHP_URL_HOST);
        return (string) preg_replace('/^www\./', '', $host ?? '');
    }

    /**
     * Extract company name from search result title.
     *
     * Falls back to domain-derived name when the title looks generic.
     */
    private function extractCompanyNameFromTitle(string $title, string $domain = ''): string
    {
        // Remove common suffixes
        $name = preg_replace('/\s*[-|–]\s*.*(LinkedIn|Facebook|Homepage|Home|About|Contact|Careers|Jobs|News|Blog|Press).*$/i', '', $title);
        $name = preg_replace('/\s*\|\s*.*$/', '', $name);
        $name = trim($name);

        // Detect generic / junk titles
        $genericPatterns = [
            '/^about\s*(us)?$/i',
            '/^home(page)?$/i',
            '/^contact(\s+us)?$/i',
            '/^products?$/i',
            '/^services?$/i',
            '/^careers?$/i',
            '/^locations?$/i',
            '/^capabilities\b/i',
        ];

        $isGeneric = mb_strlen($name) > 60;
        if (!$isGeneric) {
            foreach ($genericPatterns as $pattern) {
                if (preg_match($pattern, $name)) {
                    $isGeneric = true;
                    break;
                }
            }
        }

        if ($isGeneric && $domain !== '') {
            $host = preg_replace('#^https?://#', '', $domain);
            $host = preg_replace('#[:/].*$#', '', $host);
            $host = preg_replace('/^www\./', '', $host);
            $host = preg_replace('/\.(co|com|org|net|gov|edu|io)\.[a-z]{2,4}$/i', '', $host);
            $host = preg_replace('/\.[a-z]{2,6}$/i', '', $host);
            $domainName = ucwords(str_replace(['-', '.', '_'], ' ', trim($host)));
            if ($domainName !== '') {
                return $domainName;
            }
        }

        return $name;
    }

    /**
     * Determine region tag from location.
     *
     * Maps the CountryService result to standard region tags used in
     * crawler_config.yaml. EU_REGION (the value CountryService returns
     * for generic 'Europe' input) is mapped to 'EU'.
     */
    private function determineRegion(?string $location): string
    {
        $normalized = $this->countryService->normalizeRegionCode($location);

        if ($normalized === null) {
            return 'unknown';
        }

        // Map country-level ISO codes to broader region tags when needed
        return match ($normalized) {
            'EU_REGION' => 'EU',
            default => $normalized,
        };
    }

    private function resolveLocationLabel(?string $location): ?string
    {
        if (!$location) {
            return null;
        }

        // Check against all known target locations
        $allLocations = CompanyDiscoveryService::getTargetLocations();
        if (isset($allLocations[$location])) {
            return $allLocations[$location];
        }

        // Return as-is if it's already a label rather than a code
        return $this->countryService->getRegionName($location) ?? $location;
    }

    /**
     * Generate deduplication key
     */
    private function generateDupeKey(string $name, ?string $website): string
    {
        $nameKey = preg_replace('/[^a-z0-9]/', '', strtolower($name));
        
        $domainKey = '';
        if ($website) {
            $host = parse_url($website, PHP_URL_HOST);
            $domainKey = preg_replace('/^www\./', '', $host ?? '');
        }
        
        return $nameKey . ':' . $domainKey;
    }

    /**
     * Get available sectors
     */
    private function getSectors(): array
    {
        return [
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
    }

    /**
     * Get available locations across all regions.
     */
    private function getLocations(): array
    {
        return $this->countryService->getRegionOptions(
            CompanyDiscoveryService::getTargetLocations()
        );
    }

    /**
     * Get pipeline statistics
     */
    private function getPipelineStats(): array
    {
        $repo = $this->entityManager->getRepository(Lead::class);
        
        // Total leads
        $totalLeads = $repo->count([]);
        
        // Leads discovered today
        $today = new \DateTime('today');
        $todayLeads = $repo->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.createdAt >= :today')
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleScalarResult();
        
        // Leads with emails
        $withEmails = $repo->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.contactEmailsPublic IS NOT NULL')
            ->andWhere('l.contactEmailsPublic != :empty')
            ->setParameter('empty', '[]')
            ->getQuery()
            ->getSingleScalarResult();
            
        // Leads with contact forms
        $withContactForms = $repo->count(['hasContactForm' => true]);
        
        // Pending review
        $pendingReview = $repo->count(['reviewStatus' => 'pending']);
        
        // Recently scraped (last hour)
        $oneHourAgo = new \DateTime('-1 hour');
        $recentlyScraped = $repo->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.lastScrapedAt >= :time')
            ->setParameter('time', $oneHourAgo)
            ->getQuery()
            ->getSingleScalarResult();
        
        return [
            'total_leads' => $totalLeads,
            'today_leads' => $todayLeads,
            'with_emails' => $withEmails,
            'with_contact_forms' => $withContactForms,
            'pending_review' => $pendingReview,
            'recently_scraped' => $recentlyScraped,
            'enrichment_rate' => $totalLeads > 0 ? round(($withEmails / $totalLeads) * 100, 1) : 0
        ];
    }
    
    /**
     * Get real-time enrichment progress for polling
     */
    #[Route('/progress', name: 'discovery_pipeline_progress', methods: ['GET'])]
    public function getProgress(Request $request): JsonResponse
    {
        $sessionId = $request->query->get('session', 'default');
        
        $repo = $this->entityManager->getRepository(Lead::class);
        
        // Get counts for progress calculation
        $fiveMinAgo = new \DateTime('-5 minutes');
        
        // Recently updated leads (likely being processed)
        $recentlyUpdated = $repo->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.updatedAt >= :time')
            ->setParameter('time', $fiveMinAgo)
            ->getQuery()
            ->getSingleScalarResult();
        
        // Leads scraped in last 5 minutes
        $recentlyScraped = $repo->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.lastScrapedAt >= :time')
            ->setParameter('time', $fiveMinAgo)
            ->getQuery()
            ->getSingleScalarResult();
        
        // Get leads pending enrichment (have website but no emails/form and not recently scraped)
        $pendingEnrichment = $repo->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.websiteRoot IS NOT NULL')
            ->andWhere('(l.contactEmailsPublic IS NULL OR l.contactEmailsPublic = :empty)')
            ->andWhere('l.hasContactForm = false')
            ->andWhere('l.lastScrapedAt IS NULL OR l.lastScrapedAt < :time')
            ->setParameter('empty', '[]')
            ->setParameter('time', $fiveMinAgo)
            ->getQuery()
            ->getSingleScalarResult();
        
        // Get total leads with websites
        $totalWithWebsites = $repo->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.websiteRoot IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
        
        // Calculate progress
        $enriched = $totalWithWebsites - $pendingEnrichment;
        $progress = $totalWithWebsites > 0 ? round(($enriched / $totalWithWebsites) * 100, 1) : 100;
        
        return new JsonResponse([
            'progress' => $progress,
            'recently_scraped' => $recentlyScraped,
            'recently_updated' => $recentlyUpdated,
            'pending_enrichment' => $pendingEnrichment,
            'total_with_websites' => $totalWithWebsites,
            'enriched' => $enriched,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
    }
}
