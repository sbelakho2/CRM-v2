<?php

namespace App\Controller;

use App\Entity\Lead;
use App\Message\LeadDeepScrapeMessage;
use App\Service\GoogleSearchService;
use App\Service\WebCrawler\GoogleDorkService;
use App\Service\WebCrawler\CompanyDiscoveryService;
use App\Service\CountryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
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
class DiscoveryPipelineController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GoogleSearchService $googleSearchService,
        private GoogleDorkService $googleDorkService,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private CountryService $countryService
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
        
        return $this->render('discovery_pipeline/index.html.twig', [
            'sectors' => $this->getSectors(),
            'locations' => $this->getLocations(),
            'stats' => $stats,
        ]);
    }

    /**
     * Run automated discovery pipeline for a sector/location
     */
    #[Route('/run', name: 'discovery_pipeline_run', methods: ['POST'])]
    public function runPipeline(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $sector = $data['sector'] ?? null;
        $location = $data['location'] ?? null;
        $locationLabel = $this->resolveLocationLabel($location);
        $enableDeepScrape = $data['enable_deep_scrape'] ?? true;
        $enableLlm = $data['enable_llm'] ?? false;
        $autoImport = $data['auto_import'] ?? true;
        
        if (!$sector) {
            return new JsonResponse(['error' => 'Sector is required'], 400);
        }
        
        $this->logger->info('Starting discovery pipeline', [
            'sector' => $sector,
            'location' => $location,
            'deep_scrape' => $enableDeepScrape,
            'llm' => $enableLlm
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
                foreach ($searchResults as $result) {
                    $importResult = $this->importAsLead($result, $sector, $location);
                    
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
                                ['use_llm' => $enableLlm, 'max_pages' => 5]
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
                'error' => 'Pipeline failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Preview what a pipeline run would find (without importing)
     */
    #[Route('/preview', name: 'discovery_pipeline_preview', methods: ['POST'])]
    public function previewPipeline(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $sector = $data['sector'] ?? null;
        $location = $data['location'] ?? null;
        $locationLabel = $this->resolveLocationLabel($location);
        
        if (!$sector) {
            return new JsonResponse(['error' => 'Sector is required'], 400);
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
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Trigger deep scrape for existing leads without contact info
     */
    #[Route('/enrich-existing', name: 'discovery_pipeline_enrich', methods: ['POST'])]
    public function enrichExistingLeads(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $limit = min($data['limit'] ?? 50, 100);
        $enableLlm = $data['enable_llm'] ?? false;
        
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
                ['use_llm' => $enableLlm, 'max_pages' => 5]
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
     */
    private function importAsLead(array $result, string $sector, ?string $location): array
    {
        $website = $result['website'] ?? null;
        $name = $result['name'] ?? $this->extractCompanyNameFromTitle($result['title'] ?? '');
        
        if (!$name) {
            return ['status' => 'skipped', 'reason' => 'No company name'];
        }
        
        // Check for duplicate by website
        if ($website) {
            $existing = $this->entityManager->getRepository(Lead::class)
                ->findOneBy(['websiteRoot' => $website]);
            
            if ($existing) {
                return ['status' => 'duplicate', 'lead' => $existing];
            }
        }
        
        // Check for duplicate by name (fuzzy)
        $existingByName = $this->entityManager->getRepository(Lead::class)
            ->createQueryBuilder('l')
            ->where('LOWER(l.companyName) = :name')
            ->setParameter('name', strtolower($name))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        if ($existingByName) {
            return ['status' => 'duplicate', 'lead' => $existingByName];
        }
        
        // Create new lead
        $lead = new Lead();
        $lead->setCompanyName($name);
        $lead->setWebsiteRoot($website);
        $lead->setLeadUrl($result['link'] ?? null);
        $lead->setSectorTags([$sector]);
        $lead->setSiteLocation($this->resolveLocationLabel($location));
        $lead->setRegionTag($this->determineRegion($location));
        $lead->setNotesAuto(sprintf(
            "[%s] Auto-discovered via pipeline\nSource query: %s\nSnippet: %s",
            date('Y-m-d H:i'),
            $result['source_query'] ?? 'N/A',
            $result['snippet'] ?? ''
        ));
        $lead->setReviewStatus('pending');
        $lead->setLeadScore(30); // Base score for discovered leads
        $lead->setCreatedAt(new \DateTimeImmutable());
        
        // Generate dupe key for future deduplication
        $lead->setDupeKey($this->generateDupeKey($name, $website));
        
        $this->entityManager->persist($lead);
        
        return ['status' => 'imported', 'lead' => $lead];
    }

    /**
     * Extract company name from search result title
     */
    private function extractCompanyNameFromTitle(string $title): string
    {
        // Remove common suffixes
        $name = preg_replace('/\s*[-|–]\s*.*(LinkedIn|Facebook|Homepage|Home|About|Contact).*$/i', '', $title);
        $name = preg_replace('/\s*\|\s*.*$/', '', $name);
        
        return trim($name);
    }

    /**
     * Determine region tag from location
     */
    private function determineRegion(?string $location): string
    {
        $normalized = $this->countryService->normalizeRegionCode($location);

        return $normalized ?? 'unknown';
    }

    private function resolveLocationLabel(?string $location): ?string
    {
        if (!$location) {
            return null;
        }

        $legacy = [
            'Tanger Free Zone' => 'Tanger Free Zone',
            'Tanger Automotive City' => 'Tanger Automotive City',
            'Atlantic Free Zone Kenitra' => 'Atlantic Free Zone Kenitra',
            'Casablanca' => 'Casablanca',
            'Nouaceur' => 'Nouaceur',
            'Europe' => 'Europe',
        ];

        if (isset($legacy[$location])) {
            return $legacy[$location];
        }

        return $this->countryService->getRegionName($location);
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
            'Power Electronics',
            'Medical',
            'Defense',
            'Consumer Electronics',
        ];
    }

    /**
     * Get available locations
     */
    private function getLocations(): array
    {
        return $this->countryService->getRegionOptions([
            'Tanger Free Zone' => 'Tanger Free Zone',
            'Tanger Automotive City' => 'Tanger Automotive City',
            'Atlantic Free Zone Kenitra' => 'Atlantic Free Zone Kenitra',
            'Casablanca' => 'Casablanca',
            'Nouaceur' => 'Nouaceur',
            'Europe' => 'Europe',
        ]);
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
