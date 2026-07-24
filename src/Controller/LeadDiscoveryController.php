<?php

namespace App\Controller;

use App\Entity\Lead;
use App\Repository\LeadRepository;
use App\Service\GoogleSearchService;
use App\Service\CountryService;
use App\Service\LeadAnalysisService;
use App\Service\WebCrawler\LeadScoringService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/lead-discovery')]
#[IsGranted('ROLE_USER')]
class LeadDiscoveryController extends AbstractController
{
    public function __construct(
        private GoogleSearchService $googleSearchService,
        private EntityManagerInterface $entityManager,
        private CountryService $countryService,
        private LeadAnalysisService $leadAnalysisService,
        private LeadRepository $leadRepository,
        private LeadScoringService $leadScoringService
    ) {}

    #[Route('/', name: 'lead_discovery_index', methods: ['GET'])]
    public function index(): Response
    {
        $leadRepo = $this->entityManager->getRepository(Lead::class);

        // Total leads discovered this week / month
        $now = new \DateTime();
        $weekStart = (clone $now)->modify('monday this week')->setTime(0, 0, 0);
        $monthStart = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);

        $thisWeek = (int) $leadRepo->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.createdAt >= :start')
            ->setParameter('start', $weekStart)
            ->getQuery()
            ->getSingleScalarResult();

        $thisMonth = (int) $leadRepo->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.createdAt >= :start')
            ->setParameter('start', $monthStart)
            ->getQuery()
            ->getSingleScalarResult();

        // Leads by sector (sectorTags is JSON array; group in PHP)
        $allSectorRows = $leadRepo->createQueryBuilder('l')
            ->select('l.sectorTags')
            ->where('l.sectorTags IS NOT NULL')
            ->getQuery()
            ->getScalarResult();

        $sectorCounts = [];
        foreach ($allSectorRows as $row) {
            $tags = $row['sectorTags'];
            if (is_array($tags)) {
                foreach ($tags as $tag) {
                    $tag = trim((string) $tag);
                    if ($tag === '') continue;
                    $sectorCounts[$tag] = ($sectorCounts[$tag] ?? 0) + 1;
                }
            }
        }
        arsort($sectorCounts);
        $leadsBySector = [];
        foreach ($sectorCounts as $sector => $count) {
            $leadsBySector[] = ['sector' => $sector, 'count' => $count];
        }

        // Leads by region
        $leadsByRegion = $leadRepo->createQueryBuilder('l')
            ->select('l.regionTag AS region, COUNT(l.id) AS count')
            ->where('l.regionTag IS NOT NULL')
            ->groupBy('l.regionTag')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();

        // Pending review
        $pendingReview = (int) $leadRepo->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.reviewStatus = :status')
            ->setParameter('status', 'pending')
            ->getQuery()
            ->getSingleScalarResult();

        // Enrichment statuses
        $enriched = (int) $leadRepo->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.contactEmailsPublic IS NOT NULL')
            ->andWhere('l.contactEmailsPublic != :empty')
            ->setParameter('empty', '[]')
            ->getQuery()
            ->getSingleScalarResult();

        $needsEnrichment = (int) $leadRepo->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.websiteRoot IS NOT NULL')
            ->andWhere('(l.contactEmailsPublic IS NULL OR l.contactEmailsPublic = :empty)')
            ->andWhere('l.hasContactForm = false')
            ->setParameter('empty', '[]')
            ->getQuery()
            ->getSingleScalarResult();

        // Recent discoveries timeline (last 10)
        $recentDiscoveries = $leadRepo->createQueryBuilder('l')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $qualityDistribution = $this->leadAnalysisService->getLeadQualityDistribution();
        $sourceEffectiveness = $this->leadAnalysisService->getSourceEffectiveness();
        $geographicDistribution = $this->leadAnalysisService->getGeographicDistribution();
        $sectorBreakdown = $this->leadAnalysisService->getSectorBreakdown();
        $actionableInsights = $this->leadAnalysisService->getActionableInsights();

        return $this->render('lead_discovery/index.html.twig', [
            'paid_tier_cost' => 5,
            'paid_tier_currency' => 'USD',
            'total_leads' => $leadRepo->count([]),
            'this_week' => $thisWeek,
            'this_month' => $thisMonth,
            'leads_by_sector' => $leadsBySector,
            'leads_by_region' => $leadsByRegion,
            'pending_review' => $pendingReview,
            'enriched' => $enriched,
            'needs_enrichment' => $needsEnrichment,
            'recent_discoveries' => $recentDiscoveries,
            'quality_distribution' => $qualityDistribution,
            'source_effectiveness' => $sourceEffectiveness,
            'geographic_distribution' => $geographicDistribution,
            'sector_breakdown' => $sectorBreakdown,
            'actionable_insights' => $actionableInsights,
        ]);
    }

    #[Route('/search', name: 'lead_discovery_search', methods: ['POST'])]
    public function search(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('lead_discovery_search', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $query = $request->request->get('query');
        $limit = (int)$request->request->get('limit', 10);
        $sector = $request->request->get('sector');
        $location = $request->request->get('location');

        if (empty($query)) {
            $this->addFlash('error', 'lead_discovery.flash.error.query_required');
            return $this->redirectToRoute('lead_discovery_index');
        }

        try {
            // Perform search — use caller-supplied location (never hardcoded)
            if ($sector && $sector !== 'all') {
                $results = $this->googleSearchService->searchBySector($sector, $location ?: 'all', $limit);
            } else {
                $results = $this->googleSearchService->searchCompanies($query, min($limit, 10));
            }

            // Calculate quota
            $quota = $this->googleSearchService->estimateQuota(1, $limit);

            // Store results + metadata in session for import
            $request->getSession()->set('search_results', $results['results']);
            $request->getSession()->set('search_query', $query);
            $request->getSession()->set('search_location', $location);
            $request->getSession()->set('search_sector', $sector);

            return $this->render('lead_discovery/results.html.twig', [
                'results' => $results['results'],
                'totalResults' => $results['totalResults'] ?? 0,
                'searchTime' => $results['searchTime'] ?? 0,
                'query' => $query,
                'quota' => $quota,
            ]);

        } catch (\Exception $e) {
            $this->addFlash('error', 'lead_discovery.flash.error.search_failed');
            return $this->redirectToRoute('lead_discovery_index');
        }
    }

    #[Route('/import', name: 'lead_discovery_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('lead_discovery_import', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $results = $request->getSession()->get('search_results', []);
        $query = $request->getSession()->get('search_query', 'Unknown');
        $location = $request->getSession()->get('search_location');
        $sector = $request->getSession()->get('search_sector');
        $selectedIndices = $request->request->all()['selected'] ?? [];

        if (empty($results) || empty($selectedIndices)) {
            $this->addFlash('warning', 'lead_discovery.flash.warning.no_results');
            return $this->redirectToRoute('lead_discovery_index');
        }

        $imported = 0;
        $skipped = 0;

        foreach ($selectedIndices as $index) {
            if (!isset($results[$index])) {
                continue;
            }

            $result = $results[$index];
            $website = $this->googleSearchService->extractWebsite($result);

            // Check for duplicates
            if ($website) {
                $existing = $this->entityManager->getRepository(Lead::class)
                    ->findOneBy(['websiteRoot' => $website]);

                if ($existing) {
                    $skipped++;
                    continue;
                }
            }

            // Create lead with region metadata
            $lead = new Lead();
            $lead->setCompanyName($this->cleanCompanyName($result['title']));
            $lead->setWebsiteRoot($website);
            $lead->setSource('Google Search: ' . $query);
            $lead->setDescription($result['snippet']);
            $lead->setReviewStatus('pending');
            $lead->setCreatedAt(new \DateTimeImmutable());
            $lead->setSiteLocation($location);
            $lead->setRegionTag($this->countryService->normalizeRegionCode($location) ?? 'unknown');
            if ($sector && $sector !== 'all') {
                $lead->setSectorTags([$sector]);
            }

            // Apply scoring engine for quality assessment
            $scoreData = $this->leadScoringService->scoreLead([
                'company_name' => $lead->getCompanyName(),
                'website_root' => $website,
                'region_tag' => $lead->getRegionTag(),
                'site_location' => $location,
                'sector_tags' => $sector ? [$sector] : [],
                'page_content' => $result['snippet'] ?? '',
                'address' => $result['snippet'] ?? '',
            ]);
            $lead->setLeadScore($scoreData['score'] ?? 30);

            $this->entityManager->persist($lead);
            $imported++;
        }

        $this->entityManager->flush();

        // Clear session
        $request->getSession()->remove('search_results');
        $request->getSession()->remove('search_query');

        if ($imported > 0) {
            $this->addFlash('success', 'lead_discovery.flash.success.imported');
        }
        if ($skipped > 0) {
            $this->addFlash('info', 'lead_discovery.flash.info.skipped');
        }

        return $this->redirectToRoute('app_lead_index');
    }

    #[Route('/import-all', name: 'lead_discovery_import_all', methods: ['POST'])]
    public function importAll(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('lead_discovery_import_all', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $results = $request->getSession()->get('search_results', []);
        $query = $request->getSession()->get('search_query', 'Unknown');
        $location = $request->getSession()->get('search_location');
        $sector = $request->getSession()->get('search_sector');

        if (empty($results)) {
            $this->addFlash('warning', 'lead_discovery.flash.warning.no_results');
            return $this->redirectToRoute('lead_discovery_index');
        }

        $imported = 0;
        $skipped = 0;

        foreach ($results as $result) {
            $website = $this->googleSearchService->extractWebsite($result);

            if ($website) {
                $existing = $this->entityManager->getRepository(Lead::class)
                    ->findOneBy(['websiteRoot' => $website]);

                if ($existing) {
                    $skipped++;
                    continue;
                }
            }

            $lead = new Lead();
            $lead->setCompanyName($this->cleanCompanyName($result['title']));
            $lead->setWebsiteRoot($website);
            $lead->setSource('Google Search: ' . $query);
            $lead->setDescription($result['snippet']);
            $lead->setReviewStatus('pending');
            $lead->setCreatedAt(new \DateTimeImmutable());
            $lead->setSiteLocation($location);
            $lead->setRegionTag($this->countryService->normalizeRegionCode($location) ?? 'unknown');
            if ($sector && $sector !== 'all') {
                $lead->setSectorTags([$sector]);
            }

            // Apply scoring engine for quality assessment
            $scoreData = $this->leadScoringService->scoreLead([
                'company_name' => $lead->getCompanyName(),
                'website_root' => $website,
                'region_tag' => $lead->getRegionTag(),
                'site_location' => $location,
                'sector_tags' => $sector ? [$sector] : [],
                'page_content' => $result['snippet'] ?? '',
                'address' => $result['snippet'] ?? '',
            ]);
            $lead->setLeadScore($scoreData['score'] ?? 30);

            $this->entityManager->persist($lead);
            $imported++;
        }

        $this->entityManager->flush();

        $request->getSession()->remove('search_results');
        $request->getSession()->remove('search_query');

        if ($imported > 0) {
            $this->addFlash('success', 'lead_discovery.flash.success.imported');
        }
        if ($skipped > 0) {
            $this->addFlash('info', 'lead_discovery.flash.info.skipped');
        }

        return $this->redirectToRoute('app_lead_index');
    }

    private function cleanCompanyName(string $title): string
    {
        $title = preg_replace('/\s*[-|]\s*.+$/', '', $title);
        return trim($title);
    }
}
