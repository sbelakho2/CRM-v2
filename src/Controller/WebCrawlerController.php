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

    public function __construct(
        private CompanyDiscoveryService $discoveryService,
        private GoogleDorkService $googleDorkService,
        private CountryService $countryService,
        private ?ContactEnrichmentService $contactEnrichmentService = null,
        private ?CompanyRepository $companyRepository = null
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
        set_time_limit(300);

        $sector = $request->request->get('sector');
        $location = $request->request->get('location');
        $keywords = $request->request->get('keywords', '');
        $locationLabel = $this->resolveLocationLabel($location);
        $sector = is_string($sector) && trim($sector) !== '' ? trim($sector) : null;

        try {
            // Run discovery
            $companies = $this->discoveryService->discoverCompanies($sector, $locationLabel);

            $message = $sector
                ? sprintf('Discovered %d companies in %s', count($companies), $sector)
                : sprintf('Discovered %d companies', count($companies));

            return new JsonResponse([
                'success' => true,
                'discovered' => count($companies),
                'message' => $message,
                'companies' => array_map(fn($c) => [
                    'id' => $c->getId(),
                    'name' => $c->getName(),
                    'website' => $c->getWebsite(),
                    'sector' => $c->getSector(),
                    'region' => $c->getRegion(),
                    'country' => $c->getCountry(),
                    'city' => $c->getCity(),
                    'linkedin_url' => $c->getLinkedinCompanyUrl(),
                    'address' => $c->getAddress(),
                    'notes' => $c->getNotes(),
                    'pipeline_stage' => $c->getPipelineStage(),
                    'account_tier' => $c->getAccountTier(),
                    'source_notes' => $c->getSourceNotes(),
                    'legal_name' => $c->getLegalName(),
                    'physical_site' => $c->getPhysicalSite(),
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
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
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
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
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
