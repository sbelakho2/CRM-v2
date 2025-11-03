<?php

namespace App\Controller;

use App\Entity\Quote;
use App\Entity\BomLine;
use App\Service\QuoteCoPilotService;
use App\Service\DfmLintService;
use App\Service\CostingEngineService;
use App\Service\UnifiedPdfGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Annotation\Route;

/**
 * QuoteCoPilotController
 * 
 * Automated quote generation from BOM files.
 * 
 * Features:
 * - BOM upload (CSV/Excel format)
 * - API waterfall for component pricing (Mouser→DigiKey→Nexar→Alibaba→Pricebook→Imputation)
 * - DFM/DFA linting for manufacturability issues
 * - PCB/ASM/NRE cost calculation
 * - Coverage metrics (% of BOM with pricing)
 * - Exception report (missing/problematic components)
 * - Auto-publish when >90% coverage
 * 
 * Routes:
 * - GET  /quote-copilot              - Upload form
 * - POST /quote-copilot/process      - Process BOM file
 * - GET  /quote-copilot/results/{id} - Show quote results
 * - GET  /quote-copilot/{id}/pdf     - Download quote PDF
 * - POST /quote-copilot/{id}/publish - Publish quote to customer
 */
#[Route('/quote-copilot')]
class QuoteCoPilotController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private QuoteCoPilotService $copilotService,
        private DfmLintService $dfmLintService,
        private CostingEngineService $costingEngine,
        private UnifiedPdfGeneratorService $pdfGenerator
    ) {}

    /**
     * Show BOM upload form with destination country selection
     */
    #[Route('', name: 'quote_copilot_index', methods: ['GET'])]
    public function index(): Response
    {
        $companyRepository = $this->entityManager->getRepository(\App\Entity\Company::class);
        $companies = $companyRepository->findAll();
        $countries = $this->getCountryList();

        return $this->render('quote_copilot/index.html.twig', [
            'companies' => $companies,
            'countries' => $countries,
        ]);
    }

    /**
     * Process uploaded BOM file
     */
    #[Route('/process', name: 'quote_copilot_process', methods: ['POST'])]
    public function process(Request $request): Response
    {
        // Validate file upload
        /** @var UploadedFile $bomFile */
        $bomFile = $request->files->get('bom_file');
        if (!$bomFile) {
            $this->addFlash('error', 'Please upload a BOM file');
            return $this->redirectToRoute('quote_copilot_index');
        }

        // Validate CSV format
        $ext = strtolower($bomFile->getClientOriginalExtension());
        if ($ext !== 'csv') {
            $this->addFlash('error', 'Only CSV files are currently supported');
            return $this->redirectToRoute('quote_copilot_index');
        }

        // Get form parameters
        $companyId = $request->request->get('company_id');
        $shipToCountry = $request->request->get('ship_to_country');
        $quantity = (int)$request->request->get('quantity', 100);
        $incoterms = $request->request->get('incoterms', 'FCA');
        $notes = $request->request->get('notes', '');

        if (!$companyId || !$shipToCountry) {
            $this->addFlash('error', 'Please select a company and destination country');
            return $this->redirectToRoute('quote_copilot_index');
        }

        try {
            // Parse BOM file
            $bomData = $this->copilotService->parseBom($bomFile->getPathname());

            // Create Quote entity
            $companyRepository = $this->entityManager->getRepository(\App\Entity\Company::class);
            $company = $companyRepository->find($companyId);
            
            if (!$company) {
                $this->addFlash('error', 'Company not found');
                return $this->redirectToRoute('quote_copilot_index');
            }

            $quote = new Quote();
            $quote->setCompany($company);
            $quote->setShipToCountry($shipToCountry);
            $quote->setQuantity($quantity);
            $quote->setIncoterms($incoterms);
            $quote->setNotes($notes);
            $quote->setStatus('draft');
            $quote->setBomDataJson(json_encode($bomData));
            
            $this->entityManager->persist($quote);
            $this->entityManager->flush(); // Get quote ID

            // Process BOM through pricing service
            $result = $this->copilotService->processBom($bomData, $quote->getId());

            // Add success flash message
            $this->addFlash('success', sprintf(
                'BOM processed successfully! Coverage: %.1f%% (%d/%d parts sourced)',
                $result['coverage'],
                $result['sourcedCount'],
                $result['totalCount']
            ));

            // Redirect to results page
            return $this->redirectToRoute('quote_copilot_results', ['id' => $quote->getId()]);

        } catch (\Exception $e) {
            $this->addFlash('error', 'Error processing BOM: ' . $e->getMessage());
            return $this->redirectToRoute('quote_copilot_index');
        }
    }
        //        return $this->redirectToRoute('quote_copilot_index');
        //    }
        //    
        //    $allowedExtensions = ['csv', 'xlsx', 'xls'];
        //    if (!in_array($bomFile->getClientOriginalExtension(), $allowedExtensions)) {
        //        $this->addFlash('error', 'Invalid file format. Please upload CSV or Excel file');
        //        return $this->redirectToRoute('quote_copilot_index');
        //    }
        // 
        // 2. Get form parameters:
        //    $params = [
        //        'quantity' => (int) $request->request->get('quantity', 100),
        //        'destinationCountry' => $request->request->get('destination_country'), // REQUIRED for landed-cost
        //        'originCountry' => $request->request->get('origin_country', 'MA'), // Morocco default
        //        'pcbLayers' => (int) $request->request->get('pcb_layers', 2),
        //        'pcbDimensionX' => (float) $request->request->get('pcb_dimension_x', 100),
        //        'pcbDimensionY' => (float) $request->request->get('pcb_dimension_y', 100),
        //        'runDfm' => (bool) $request->request->get('run_dfm', true),
        //        'autoPublish' => (bool) $request->request->get('auto_publish', false)
        //    ];
        // 
        // 2a. Validate destination country:
        //    if (!$params['destinationCountry']) {
        //        $this->addFlash('error', 'Please select a destination country');
        //        return $this->redirectToRoute('quote_copilot_index');
        //    }
        // 
        // 3. Process BOM with Quote Co-Pilot:
        //    $result = $this->copilotService->processBom(
        //        $bomFile->getPathname(),
        //        $params['quantity']
        //    );
        // 
        // 4. Create Quote entity:
        //    $quote = new Quote();
        //    $quote->setQuantity($params['quantity']);
        //    $quote->setPcbLayers($params['pcbLayers']);
        //    $quote->setPcbDimensionX($params['pcbDimensionX']);
        //    $quote->setPcbDimensionY($params['pcbDimensionY']);
        //    $quote->setCoverage($result['coverage']);
        //    $quote->setStatus($result['canAutoPublish'] && $params['autoPublish'] ? 'PUBLISHED' : 'DRAFT');
        //    $quote->setCreatedAt(new \DateTime());
        //    
        //    $this->entityManager->persist($quote);
        // 
        // 5. Create BomLine entities:
        //    foreach ($result['bomLines'] as $lineData) {
        //        $bomLine = new BomLine();
        //        $bomLine->setQuote($quote);
        //        $bomLine->setRefDes($lineData['refDes']);
        //        $bomLine->setMpn($lineData['mpn']);
        //        $bomLine->setManufacturer($lineData['manufacturer']);
        //        $bomLine->setDescription($lineData['description']);
        //        $bomLine->setQuantity($lineData['quantity']);
        //        $bomLine->setUnitPrice($lineData['unitPrice']);
        //        $bomLine->setExtPrice($lineData['extPrice']);
        //        $bomLine->setSource($lineData['source']);
        //        $bomLine->setLeadTimeDays($lineData['leadTimeDays']);
        //        $bomLine->setStockStatus($lineData['stockStatus']);
        //        
        //        $this->entityManager->persist($bomLine);
        //    }
        // 
        // 6. Run DFM linting if requested:
        //    if ($params['runDfm']) {
        //        $dfmFindings = $this->dfmLintService->lintBom(
        //            $quote->getId(),
        //            $params['pcbLayers'],
        //            $params['pcbDimensionX'],
        //            $params['pcbDimensionY']
        //        );
        //        $quote->setDfmFindings(json_encode($dfmFindings));
        //    }
        // 
        // 7. Calculate PCB/ASM/NRE costs:
        //    $costs = $this->costingEngine->calculatePcbCost(
        //        $params['pcbLayers'],
        //        $params['pcbDimensionX'] * $params['pcbDimensionY'] / 10000, // convert mm² to dm²
        //        $params['quantity']
        //    );
        //    $quote->setPcbCost($costs['totalCost']);
        //    
        //    $asmCost = $this->costingEngine->calculateAsmCost(
        //        $quote->getId(),
        //        $params['quantity']
        //    );
        //    $quote->setAsmCost($asmCost['totalCost']);
        //    
        //    $nreCost = $this->costingEngine->calculateNre($quote->getId());
        //    $quote->setNreCost($nreCost['totalNre']);
        // 
        // 8. Calculate total quote value:
        //    $bomCost = array_sum(array_column($result['bomLines'], 'extPrice'));
        //    $totalCost = $bomCost + $quote->getPcbCost() + $quote->getAsmCost() + $quote->getNreCost();
        //    $quote->setTotalCost($totalCost);
        // 
        // 9. Save to database:
        //    $this->entityManager->flush();
        // 
        // 10. Redirect to results:
        //     return $this->redirectToRoute('quote_copilot_results', ['id' => $quote->getId()]);

    /**
     * Show quote results with coverage metrics
     */
    #[Route('/results/{id}', name: 'quote_copilot_results', methods: ['GET'])]
    public function results(int $id): Response
    {
        $quote = $this->entityManager->getRepository(Quote::class)->find($id);
        if (!$quote) {
            throw $this->createNotFoundException('Quote not found');
        }

        return $this->render('quote_copilot/results.html.twig', [
            'quote' => $quote,
        ]);
    }

    /**
     * Download quote PDF
     */
    #[Route('/{id}/pdf', name: 'quote_copilot_pdf', methods: ['GET'])]
    public function downloadPdf(int $id): Response
    {
        $quote = $this->entityManager->getRepository(Quote::class)->find($id);
        if (!$quote) {
            throw $this->createNotFoundException('Quote not found');
        }

        // For MVP: Return JSON with quote data (PDF generation can be added later)
        // Production: Use TCPDF or Dompdf to generate proper PDF
        $this->addFlash('info', 'PDF generation will be implemented in the next iteration');
        return $this->redirectToRoute('quote_copilot_results', ['id' => $id]);
    }

    /**
     * Publish quote to customer
     */
    #[Route('/{id}/publish', name: 'quote_copilot_publish', methods: ['POST'])]
    public function publish(int $id, Request $request): Response
    {
        $quote = $this->entityManager->getRepository(Quote::class)->find($id);
        if (!$quote) {
            return $this->json(['success' => false, 'message' => 'Quote not found'], 404);
        }

        // Check coverage threshold
        if ((float)$quote->getCoveragePercent() < 60.0) {
            return $this->json([
                'success' => false,
                'message' => 'Cannot publish quote with <60% coverage. Manual pricing required for missing parts.'
            ], 400);
        }

        // Update quote status
        $quote->setStatus('sent');
        $quote->setAutoPublished(true);
        $this->entityManager->flush();

        // TODO: Send email notification to customer
        // TODO: Create Activity record
        // TODO: Create Notification for sales team

        return $this->json([
            'success' => true,
            'message' => sprintf('Quote %s published successfully', $quote->getQuoteNumber())
        ]);
    }

    /**
     * Get comprehensive list of supported countries for shipping
     * 
     * Used for destination country selection in BOM processing.
     * Allows operator to calculate landed costs for any destination.
     * 
     * @return array - Associative array of country codes => names
     */
    private function getCountryList(): array
    {
        // Shared country list for consistency across Quote Estimator and Quote Co-Pilot
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

