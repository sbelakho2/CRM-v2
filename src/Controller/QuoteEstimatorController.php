<?php

namespace App\Controller;

use App\Entity\Estimate;
use App\Service\HtsClassificationService;
use App\Service\RouteSelectionService;
use App\Service\FtaEligibilityService;
use App\Service\DutyCalculationService;
use App\Service\FreightPricingService;
use App\Service\UnifiedPdfGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * QuoteEstimatorController
 * 
 * Landed-cost estimator for quick customer quotes.
 * 
 * Features:
 * - Input: Destination country, weight, volume, HTS code, goods value
 * - Output: Freight cost, duty, VAT, total landed cost
 * - Route comparison with multiple shipping options
 * - FTA eligibility check with potential savings
 * - PDF export of estimate
 * 
 * Routes:
 * - GET  /quote-estimator          - Show input form
 * - POST /quote-estimator/calculate - Calculate landed cost
 * - GET  /quote-estimator/results/{id} - Show saved estimate
 * - GET  /quote-estimator/{id}/pdf - Download estimate PDF
 */
#[Route('/quote-estimator')]
class QuoteEstimatorController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HtsClassificationService $htsClassificationService,
        private RouteSelectionService $routeSelectionService,
        private FtaEligibilityService $ftaEligibilityService,
        private DutyCalculationService $dutyCalculationService,
        private FreightPricingService $freightPricingService,
        private UnifiedPdfGeneratorService $pdfGenerator
    ) {}

    /**
     * Show quote estimator form with country selection
     */
    #[Route('', name: 'quote_estimator_index', methods: ['GET'])]
    public function index(): Response
    {
        // TODO: Implement form display
        // 
        // Steps:
        // 1. Render Twig template with form:
        //    return $this->render('quote_estimator/index.html.twig', [
        //        'countries' => $this->getCountryList(),
        //        'originCountries' => $this->getCountryList(), // Same list for origin
        //        'incoterms' => ['EXW', 'FOB', 'CIF', 'DDP'],
        //        'defaultOrigin' => 'MA' // Morocco as default origin
        //    ]);
        // 
        // Form fields:
        // - Origin Country (dropdown, default: MA)
        // - Destination Country (dropdown, REQUIRED - operator selects)
        // - Weight (kg, numeric input)
        // - Volume (m³, numeric input)
        // - HTS Code (text input, optional - can be classified automatically)
        // - Goods Value (USD, numeric input)
        // - Incoterm (dropdown: EXW, FOB, CIF, DDP)
        
        return $this->render('quote_estimator/index.html.twig', [
            'pageTitle' => 'Landed-Cost Estimator',
            'countries' => $this->getCountryList(),
            'incoterms' => ['EXW', 'FOB', 'CIF', 'DDP'],
            'defaultOrigin' => 'MA'
        ]);
    }

    /**
     * List all estimates
     */
    #[Route('/list', name: 'quote_estimator_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $queryBuilder = $this->entityManager->getRepository(Estimate::class)
            ->createQueryBuilder('e')
            ->leftJoin('e.company', 'c')
            ->addSelect('c')
            ->orderBy('e.createdAt', 'DESC');
        
        // Search filter
        $search = $request->query->get('search');
        if ($search) {
            $queryBuilder
                ->andWhere('e.estimateNumber LIKE :search OR e.notes LIKE :search OR c.name LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }
        
        // Status filter
        $status = $request->query->get('status');
        if ($status) {
            // Parse notes JSON to check status
            // For now, show all as we don't have a dedicated status field
        }
        
        $estimates = $queryBuilder->getQuery()->getResult();

        // Decode notes JSON for each estimate to avoid relying on unsupported Twig filters
        $quoteDataMap = [];
        foreach ($estimates as $e) {
            $notes = $e->getNotes();
            $decoded = [];
            if ($notes) {
                $decoded = json_decode($notes, true) ?: [];
            }
            $quoteDataMap[$e->getId()] = $decoded;
        }

        return $this->render('quote_estimator/list.html.twig', [
            'pageTitle' => 'Quote Estimates',
            'estimates' => $estimates,
            'search' => $search,
            'quoteDataMap' => $quoteDataMap,
        ]);
    }

    /**
     * Calculate landed cost or save for later
     */
    #[Route('/calculate', name: 'quote_estimator_calculate', methods: ['POST'])]
    public function calculate(Request $request): Response
    {
        $action = $request->request->get('action');
        
        // Extract form data
        $customerName = $request->request->get('customer_name');
        $partNumber = $request->request->get('part_number');
        $quantity = $request->request->get('quantity');
        $deliveryDate = $request->request->get('delivery_date');
        $specialRequirements = $request->request->get('special_requirements');
        
        // Validate basic fields
        if (!$customerName || !$partNumber || !$quantity) {
            $this->addFlash('error', 'Please fill in Customer Name, Part Number, and Quantity');
            return $this->redirectToRoute('quote_estimator_index');
        }
        
        // Create estimate with data stored in notes field
        $estimateData = [
            'customer_name' => $customerName,
            'part_number' => $partNumber,
            'quantity' => $quantity,
            'delivery_date' => $deliveryDate,
            'special_requirements' => $specialRequirements,
            'created_by' => $this->getUser() ? $this->getUser()->getId() : null,
        ];
        
        // For now, use a placeholder company (first one in DB) or null
        $companyRepo = $this->entityManager->getRepository(\App\Entity\Company::class);
        $defaultCompany = $companyRepo->findOneBy([]);
        
        $estimate = new Estimate();
        if ($defaultCompany) {
            $estimate->setCompany($defaultCompany);
        }
        $estimate->setOriginCountry('MA'); // Morocco default
        $estimate->setDestinationCountry('US'); // Default
        $estimate->setCurrency('USD');
        $estimate->setNotes(json_encode($estimateData));
        
        // Set placeholder costs (will be calculated later)
        $estimate->setMaterialCost('0.00');
        $estimate->setLaborCost('0.00');
        $estimate->setFreightCost('0.00');
        $estimate->setDutyCost('0.00');
        $estimate->setTotalLandedCost('0.00');
        $estimate->setCreatedAt(new \DateTime());
        
        $this->entityManager->persist($estimate);
        $this->entityManager->flush();
        
        if ($action === 'save') {
            $this->addFlash('success', sprintf('Quote saved as draft %s. You can return to it later.', $estimate->getEstimateNumber()));
            return $this->redirectToRoute('quote_estimator_index');
        }
        
        // Action is 'estimate' - show results (pricing calculation coming soon)
        $this->addFlash('info', 'Quote request created. Full pricing calculation coming soon!');
        return $this->redirectToRoute('quote_estimator_results', ['id' => $estimate->getId()]);
    }

    /**
     * Show estimate results
     */
    #[Route('/results/{id}', name: 'quote_estimator_results', methods: ['GET'])]
    public function results(int $id): Response
    {
        $estimate = $this->entityManager->getRepository(Estimate::class)->find($id);
        if (!$estimate) {
            throw $this->createNotFoundException('Estimate not found');
        }
        
        // Decode the notes field to get quote request data
        $quoteData = json_decode($estimate->getNotes() ?? '{}', true);
        
        return $this->render('quote_estimator/results.html.twig', [
            'pageTitle' => 'Estimate Results',
            'estimate' => $estimate,
            'quoteData' => $quoteData,
        ]);
    }

    /**
     * Download estimate PDF
     */
    #[Route('/{id}/pdf', name: 'quote_estimator_pdf', methods: ['GET'])]
    public function downloadPdf(int $id): Response
    {
        // TODO: Implement PDF download
        // 
        // Steps:
        // 1. Get estimate:
        //    $estimate = $this->entityManager->getRepository(Estimate::class)->find($id);
        //    if (!$estimate) {
        //        throw $this->createNotFoundException('Estimate not found');
        //    }
        // 
        // 2. Generate PDF:
        //    $pdfPath = $this->pdfGenerator->generateEstimatePdf($estimate);
        // 
        // 3. Return PDF response:
        //    return $this->file($pdfPath, "estimate_{$id}.pdf");
        
        throw new \RuntimeException('Feature not yet implemented');
    }

    /**
     * Delete estimate
     */
    #[Route('/{id}/delete', name: 'quote_estimator_delete', methods: ['POST'])]
    public function delete(int $id): Response
    {
        $estimate = $this->entityManager->getRepository(Estimate::class)->find($id);
        if (!$estimate) {
            return $this->json(['success' => false, 'message' => 'Estimate not found'], 404);
        }

        try {
            $this->entityManager->remove($estimate);
            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'message' => 'Estimate deleted successfully'
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Error deleting estimate: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get comprehensive list of supported countries for shipping
     * 
     * Organized by region for better UX in dropdown.
     * Operator can select any destination country for landed-cost calculation.
     * 
     * @return array - Associative array of country codes => names
     */
    private function getCountryList(): array
    {
        // TODO: Move to dedicated service or load from database
        // Consider using ISO 3166-1 alpha-2 standard country codes
        
        return [
            // North America
            'US' => 'United States',
            'CA' => 'Canada',
            'MX' => 'Mexico',
            
            // Europe
            'FR' => 'France',
            'DE' => 'Germany',
            'GB' => 'United Kingdom',
            'IT' => 'Italy',
            'ES' => 'Spain',
            'NL' => 'Netherlands',
            'BE' => 'Belgium',
            'PL' => 'Poland',
            'SE' => 'Sweden',
            'NO' => 'Norway',
            'CH' => 'Switzerland',
            'AT' => 'Austria',
            'IE' => 'Ireland',
            'DK' => 'Denmark',
            'FI' => 'Finland',
            'PT' => 'Portugal',
            'CZ' => 'Czech Republic',
            'RO' => 'Romania',
            'GR' => 'Greece',
            
            // Middle East & Africa
            'MA' => 'Morocco',
            'EG' => 'Egypt',
            'ZA' => 'South Africa',
            'AE' => 'United Arab Emirates',
            'SA' => 'Saudi Arabia',
            'IL' => 'Israel',
            'TR' => 'Turkey',
            'KE' => 'Kenya',
            'NG' => 'Nigeria',
            
            // Asia Pacific
            'CN' => 'China',
            'JP' => 'Japan',
            'KR' => 'South Korea',
            'IN' => 'India',
            'SG' => 'Singapore',
            'MY' => 'Malaysia',
            'TH' => 'Thailand',
            'VN' => 'Vietnam',
            'ID' => 'Indonesia',
            'PH' => 'Philippines',
            'TW' => 'Taiwan',
            'HK' => 'Hong Kong',
            'AU' => 'Australia',
            'NZ' => 'New Zealand',
            
            // South America
            'BR' => 'Brazil',
            'AR' => 'Argentina',
            'CL' => 'Chile',
            'CO' => 'Colombia',
            'PE' => 'Peru',
        ];
    }
}
