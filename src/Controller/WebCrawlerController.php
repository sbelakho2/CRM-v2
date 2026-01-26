<?php

namespace App\Controller;

use App\Service\WebCrawler\CompanyDiscoveryService;
use App\Service\WebCrawler\GoogleDorkService;
use App\Repository\LeadRepository;
use App\Service\CountryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/webcrawler')]
class WebCrawlerController extends AbstractController
{
    private const TARGET_SECTORS = [
        'Automotive',
        'Industrial',
        'Aerospace',
        'Rail',
        'Renewables',
        'Power Electronics',
    ];

    private const LOCATIONS = [
        'TAC' => 'Tanger Automotive City',
        'TFZ' => 'Tanger Free Zone',
        'AFZ Kenitra' => 'Atlantic Free Zone Kenitra',
        'Casablanca' => 'Casablanca/Midparc',
        'Bouskoura' => 'Bouskoura',
        'Nouaceur' => 'Nouaceur',
    ];

    public function __construct(
        private CompanyDiscoveryService $discoveryService,
        private GoogleDorkService $googleDorkService,
        private LeadRepository $leadRepository,
        private CountryService $countryService
    ) {}

    #[Route('', name: 'app_webcrawler_index', methods: ['GET'])]
    public function index(): Response
    {
        // Get recent discovery stats (webcrawler leads are identified by notesAuto containing discovery info)
        $recentLeads = $this->leadRepository->createQueryBuilder('l')
            ->where('l.notesAuto IS NOT NULL')
            ->andWhere('l.notesAuto LIKE :webcrawler OR l.leadUrl IS NOT NULL')
            ->setParameter('webcrawler', '%discovered%')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        // Count all leads (webcrawler creates leads with notesAuto)
        $allLeads = $this->leadRepository->createQueryBuilder('l')
            ->where('l.notesAuto IS NOT NULL')
            ->getQuery()
            ->getResult();
        
        $stats = [
            'total_leads' => count($allLeads),
            'pending' => count(array_filter($allLeads, fn($l) => $l->getReviewStatus() === 'pending')),
            'approved' => count(array_filter($allLeads, fn($l) => $l->getReviewStatus() === 'approved')),
        ];

        $locations = $this->countryService->getRegionOptions(self::LOCATIONS);
        $regionLabels = $locations;
        foreach ($recentLeads as $lead) {
            $tag = $lead->getRegionTag();
            if ($tag && !isset($regionLabels[$tag])) {
                $regionLabels[$tag] = strtoupper((string) $tag);
            }
        }

        return $this->render('webcrawler/index.html.twig', [
            'sectors' => self::TARGET_SECTORS,
            'locations' => $locations,
            'region_labels' => $regionLabels,
            'recent_leads' => $recentLeads,
            'stats' => $stats,
        ]);
    }

    #[Route('/discover', name: 'app_webcrawler_discover', methods: ['POST'])]
    public function discover(Request $request): JsonResponse
    {
        $sector = $request->request->get('sector');
        $location = $request->request->get('location');
        $keywords = $request->request->get('keywords', '');
        $locationLabel = $this->resolveLocationLabel($location);

        if (!$sector) {
            return new JsonResponse(['error' => 'Sector is required'], 400);
        }

        try {
            // Run discovery
            $companies = $this->discoveryService->discoverCompanies($sector, $locationLabel);

            return new JsonResponse([
                'success' => true,
                'discovered' => count($companies),
                'message' => sprintf('Discovered %d companies in %s', count($companies), $sector),
                'companies' => array_map(fn($c) => [
                    'id' => $c->getId(),
                    'name' => $c->getName(),
                    'website' => $c->getWebsite(),
                ], $companies)
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    #[Route('/search-google', name: 'app_webcrawler_search_google', methods: ['POST'])]
    public function searchGoogle(Request $request): JsonResponse
    {
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
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
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
            'locations' => self::LOCATIONS,
        ]);
    }

    private function resolveLocationLabel(?string $location): ?string
    {
        if (!$location) {
            return null;
        }

        if (isset(self::LOCATIONS[$location])) {
            return self::LOCATIONS[$location];
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
            'Automotive' => ['automotive', 'vehicle', 'car', 'OEM', 'tier 1', 'tier 2'],
            'Aerospace' => ['aerospace', 'aviation', 'aircraft', 'defense'],
            'Industrial' => ['industrial', 'manufacturing', 'factory', 'production'],
            'Rail' => ['railway', 'rail', 'train', 'metro', 'transit'],
            'Renewables' => ['renewable', 'solar', 'wind', 'green energy', 'sustainable'],
            'Power Electronics' => ['power electronics', 'inverter', 'converter', 'power supply'],
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
