<?php

namespace App\Controller;

use App\Entity\Quote;
use App\Entity\BomLine;
use App\Service\QuoteCoPilotService;
use App\Service\UnifiedPdfGeneratorService;
use App\Service\CountryService;
use App\Service\CurrencyPreferenceService;
use App\Service\IssuingCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Psr\Log\LoggerInterface;

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
#[IsGranted('ROLE_USER')]
class QuoteCoPilotController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private QuoteCoPilotService $copilotService,
        private UnifiedPdfGeneratorService $pdfGenerator,
        private CountryService $countryService,
        private CurrencyPreferenceService $currencyPreferenceService,
        private IssuingCompanyService $issuingCompanyService,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private TranslatorInterface $translator
    ) {}

    /**
     * Show BOM upload form with destination country selection
     */
    #[Route('', name: 'quote_copilot_index', methods: ['GET'])]
    public function index(): Response
    {
        $companyRepository = $this->entityManager->getRepository(\App\Entity\Company::class);
        $companies = $companyRepository->findAll();
        $countries = $this->countryService->getCountryList();

        return $this->render('quote_copilot/index.html.twig', [
            'companies' => $companies,
            'countries' => $countries,
            'issuingCompanies' => $this->issuingCompanyService->getAllCompanies(),
            'defaultIssuer' => IssuingCompanyService::DEFAULT_COMPANY,
        ]);
    }

    /**
     * List all quotes
     */
    #[Route('/quotes', name: 'quote_copilot_list', methods: ['GET'])]
    public function list(): Response
    {
        $quotes = $this->entityManager->getRepository(Quote::class)
            ->createQueryBuilder('q')
            ->leftJoin('q.company', 'c')
            ->addSelect('c')
            ->addSelect('COALESCE(c.name, :missingCompany) AS company_name')
            ->setParameter('missingCompany', $this->translator->trans('common.n_a'))
            ->orderBy('q.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('quote_copilot/list.html.twig', [
            'quotes' => $quotes,
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

        // Validate file format (CSV and Excel supported)
        $ext = strtolower($bomFile->getClientOriginalExtension());
        if (!in_array($ext, ['csv', 'xlsx', 'xls'])) {
            $this->addFlash('error', 'Unsupported file format. Please upload a CSV or Excel (.xlsx, .xls) file.');
            return $this->redirectToRoute('quote_copilot_index');
        }

        // Get form parameters
        $companyId = $request->request->get('company_id');
        $shipToCountry = $request->request->get('ship_to_country');
        $quantity = (int)$request->request->get('quantity', 100);
        $incoterms = $request->request->get('incoterms', 'FCA');
        $notes = $request->request->get('notes', '');
        $issuingCompany = $request->request->get('issuing_company', IssuingCompanyService::DEFAULT_COMPANY);

        if (!$companyId || !$shipToCountry) {
            $this->addFlash('error', 'Please select a company and destination country');
            return $this->redirectToRoute('quote_copilot_index');
        }

        try {
            // Parse BOM file (pass original extension since temp file has no extension)
            $originalExtension = $bomFile->getClientOriginalExtension();
            $bomData = $this->copilotService->parseBom($bomFile->getPathname(), $originalExtension);

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
            $quote->setIssuingCompany($issuingCompany);
            $quote->setBomDataJson(json_encode($bomData));
            $quote->setCurrency($this->currencyPreferenceService->getDisplayCurrency());
            
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
        $row = $this->entityManager->getRepository(Quote::class)
            ->createQueryBuilder('q')
            ->leftJoin('q.company', 'c')
            ->addSelect('c')
            ->addSelect('COALESCE(c.name, :missingCompany) AS company_name')
            ->setParameter('missingCompany', $this->translator->trans('common.n_a'))
            ->andWhere('q.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$row) {
            throw $this->createNotFoundException('Quote not found');
        }

        $quote = $row[0] ?? $row;
        $companyName = is_array($row) ? ($row['company_name'] ?? null) : null;

        return $this->render('quote_copilot/results.html.twig', [
            'quote' => $quote,
            'company_name' => $companyName,
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

        try {
            // Generate PDF using the unified PDF generator service
            $pdfContent = $this->pdfGenerator->generateQuotePdf($quote);
            
            $response = new Response($pdfContent);
            $response->headers->set('Content-Type', 'application/pdf');
            $response->headers->set('Content-Disposition', sprintf(
                'attachment; filename="quote-%s.pdf"',
                $quote->getQuoteNumber() ?? $quote->getId()
            ));
            
            return $response;
        } catch (\Exception $e) {
            // Log the error and show user-friendly message
            $this->logger->error('PDF Generation failed for Quote ' . $id, [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->addFlash('error', 'Error generating PDF: ' . $e->getMessage());
            return $this->redirectToRoute('quote_copilot_results', ['id' => $id]);
        }
    }

    /**
     * Export CUSTOMER-FACING quote to CSV/Excel
     * 
     * Contains only safe data: MPN, Manufacturer, Description, Qty, Unit Price, Extended Price.
     * NO supplier names, sourcing URLs, procurement sources, or internal data.
     */
    #[Route('/{id}/excel', name: 'quote_copilot_customer_excel', methods: ['GET'])]
    public function downloadCustomerExcel(int $id): Response
    {
        $quote = $this->entityManager->getRepository(Quote::class)->find($id);
        if (!$quote) {
            throw $this->createNotFoundException('Quote not found');
        }

        // Resolve issuing company for branding
        $issuer = $this->issuingCompanyService->getCompanyProfile($quote->getIssuingCompany());

        $csv = [];
        $csv[] = ['Quote Number', $quote->getQuoteNumber() ?? 'Q-' . $quote->getId()];
        $csv[] = ['Issued By', $issuer['name']];
        $csv[] = ['Company', $quote->getCompany()->getName()];
        $csv[] = ['Date', $quote->getCreatedAt()->format('Y-m-d')];
        $csv[] = ['Quantity', $quote->getQuantity()];
        $csv[] = ['Ship To', $quote->getShipToCountry()];
        $csv[] = ['Incoterms', $quote->getIncoterms()];
        $csv[] = ['Currency', $quote->getCurrency()];
        $csv[] = [];

        // BOM Lines — customer-safe columns only
        $csv[] = ['Line', 'MPN', 'Manufacturer', 'Description', 'Qty', 'Unit Price', 'Extended Price'];

        foreach ($quote->getBomLines() as $line) {
            $csv[] = [
                $line->getLineNumber() ?? '',
                $line->getMpn() ?? '',
                $line->getManufacturer() ?? '',
                $line->getDescription() ?? '',
                $line->getQuantity() ?? '',
                $line->getUnitPrice() !== null ? number_format($line->getUnitPrice(), 4) : '',
                $line->getExtendedPrice() !== null ? number_format($line->getExtendedPrice(), 2) : '',
            ];
        }

        $csv[] = [];
        $csv[] = ['Total Cost', $quote->getTotalCost() !== null ? number_format($quote->getTotalCost(), 2) : ''];
        $csv[] = ['Coverage', $quote->getCoveragePercent() . '%'];
        $csv[] = [];
        $csv[] = ['This quote is valid for 30 days from the date of issue.'];
        $csv[] = [$issuer['name'] . ' | ' . $issuer['location'] . ' | ' . $issuer['email']];

        $output = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        $response = new Response($csvContent);
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="quote-%s.csv"',
            $quote->getQuoteNumber() ?? $quote->getId()
        ));

        return $response;
    }

    /**
     * Export FULL INTERNAL quote to XLSX with all sourcing data, clickable links,
     * confidence badges, and styled formatting (PhpSpreadsheet).
     * 
     * FOR INTERNAL USE ONLY — never send to customer.
     */
    #[Route('/{id}/full-excel', name: 'quote_copilot_full_excel', methods: ['GET'])]
    public function downloadFullExcel(int $id): Response
    {
        $quote = $this->entityManager->getRepository(Quote::class)->find($id);
        if (!$quote) {
            throw $this->createNotFoundException('Quote not found');
        }

        $issuer = $this->issuingCompanyService->getCompanyProfile($quote->getIssuingCompany());
        try {
            $companyName = $quote->getCompany()?->getName() ?? 'N/A';
        } catch (\Doctrine\ORM\EntityNotFoundException) {
            $companyName = 'N/A';
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Full Sourcing');

        // ── Header info ──
        $sheet->setCellValue('A1', 'INTERNAL — FULL SOURCING QUOTATION');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Quote: ' . ($quote->getQuoteNumber() ?? 'Q-' . $quote->getId()));
        $sheet->setCellValue('B2', 'Issued By: ' . $issuer['name']);
        $sheet->setCellValue('A3', 'Company: ' . $companyName);
        $sheet->setCellValue('B3', 'Date: ' . $quote->getCreatedAt()->format('Y-m-d'));
        $sheet->setCellValue('A4', 'Coverage: ' . $quote->getCoveragePercent() . '%');
        $sheet->setCellValue('B4', 'Currency: ' . $quote->getCurrency());

        // ── Column headers (row 6) ──
        $headers = [
            '#', 'MPN', 'Matched MPN', 'Source', 'Qty', 'Unit Price', 'Extended Price',
            'Confidence', 'Score', 'Supplier', 'Lifecycle', 'Listing Link',
        ];
        $headerRow = 6;
        foreach ($headers as $col => $header) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1) . $headerRow;
            $sheet->setCellValue($cell, $header);
        }
        // Style header row
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $headerRange = "A{$headerRow}:{$lastCol}{$headerRow}";
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2937']],
        ]);

        // ── Data rows ──
        $dataRow = $headerRow + 1;
        $totalExtPrice = 0;
        foreach ($quote->getBomLines() as $line) {
            $col = 1;
            $sheet->setCellValueByColumnAndRow($col++, $dataRow, $line->getLineNumber());
            $sheet->setCellValueByColumnAndRow($col++, $dataRow, $line->getMpn());
            $sheet->setCellValueByColumnAndRow($col++, $dataRow, $line->getMatchedMpn());
            $sheet->setCellValueByColumnAndRow($col++, $dataRow, $line->getProcurementSource());
            $sheet->setCellValueByColumnAndRow($col++, $dataRow, $line->getQuantity());
            $sheet->setCellValueByColumnAndRow($col++, $dataRow, $line->getUnitPrice() ? (float)$line->getUnitPrice() : '');
            $sheet->setCellValueByColumnAndRow($col++, $dataRow, $line->getExtendedPrice() ? (float)$line->getExtendedPrice() : '');
            $sheet->setCellValueByColumnAndRow($col++, $dataRow, $line->getConfidenceLevel() ?? '');
            $sheet->setCellValueByColumnAndRow($col++, $dataRow, $line->getConfidenceScore() ?? '');
            $sheet->setCellValueByColumnAndRow($col++, $dataRow, $line->getSupplierName() ?? '');
            $sheet->setCellValueByColumnAndRow($col++, $dataRow, $line->getLifecycleStatus() ?? 'Active');

            // Clickable listing link (column L)
            $linkUrl = $line->getSupplierProductUrl() ?? $line->getDistributorSearchUrl() ?? null;
            if ($linkUrl) {
                $linkCell = 'L' . $dataRow;
                $sheet->setCellValue($linkCell, 'View Listing ↗');
                $sheet->getCell($linkCell)->getHyperlink()->setUrl($linkUrl);
                $sheet->getStyle($linkCell)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF4472C4'))->setUnderline(true);
            }

            // Confidence color coding
            $confCell = 'H' . $dataRow;
            $confLevel = $line->getConfidenceLevel() ?? '';
            if ($confLevel === 'HIGH') {
                $sheet->getStyle($confCell)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF16A34A'));
                $sheet->getStyle($confCell)->getFont()->setBold(true);
            } elseif ($confLevel === 'MEDIUM') {
                $sheet->getStyle($confCell)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFCA8A04'));
            } elseif ($confLevel === 'LOW' || $confLevel === 'VERY_LOW') {
                $sheet->getStyle($confCell)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFDC2626'));
                $sheet->getStyle($confCell)->getFont()->setBold(true);
            }

            // Alternate row shading
            if ($dataRow % 2 === 0) {
                $sheet->getStyle("A{$dataRow}:{$lastCol}{$dataRow}")->applyFromArray([
                    'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F4F6']],
                ]);
            }

            $totalExtPrice += (float)($line->getExtendedPrice() ?? 0);
            $dataRow++;
        }

        // ── Totals row ──
        $sheet->setCellValue("E{$dataRow}", 'TOTAL:');
        $sheet->setCellValue("G{$dataRow}", $totalExtPrice);
        $sheet->getStyle("E{$dataRow}:G{$dataRow}")->getFont()->setBold(true);

        // ── Formatting ──
        $sheet->getStyle("F{$headerRow}:G{$dataRow}")->getNumberFormat()
            ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2);
        foreach (range('A', $lastCol) as $colLetter) {
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }
        $sheet->freezePane('A' . ($headerRow + 1));
        $sheet->setAutoFilter($headerRange);

        // ── Generate response ──
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        // Use a proper temp file for PhpSpreadsheet
        $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        $writer->save($tmpFile);
        $xlsxContent = file_get_contents($tmpFile);
        unlink($tmpFile);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $response = new Response($xlsxContent);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="quote-%s-FULL-INTERNAL.xlsx"',
            $quote->getQuoteNumber() ?? $quote->getId()
        ));

        return $response;
    }

    /**
     * Get contacts for quote company
     */
    #[Route('/{id}/contacts', name: 'quote_copilot_contacts', methods: ['GET'])]
    public function getContacts(int $id): Response
    {
        $quote = $this->entityManager->getRepository(Quote::class)->find($id);
        if (!$quote) {
            return $this->json(['success' => false, 'message' => 'Quote not found'], 404);
        }

        try {
            $company = $quote->getCompany();
            if (!$company) {
                return $this->json(['success' => false, 'message' => $this->translator->trans('quote.copilot.company_deleted')], 400);
            }
            $contacts = $company->getContacts();
        } catch (\Doctrine\ORM\EntityNotFoundException $e) {
            return $this->json(['success' => false, 'message' => $this->translator->trans('quote.copilot.company_deleted')], 400);
        }
        
        $contactsData = [];
        foreach ($contacts as $contact) {
            if ($contact->getEmail()) {
                $contactsData[] = [
                    'id' => $contact->getId(),
                    'name' => $contact->getFirstName() . ' ' . $contact->getLastName(),
                    'email' => $contact->getEmail(),
                    'role' => $contact->getJobTitle() ?? 'Contact',
                ];
            }
        }

        return $this->json([
            'success' => true,
            'contacts' => $contactsData,
            'count' => count($contactsData)
        ]);
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

        // Get contact ID from request
        $data = json_decode($request->getContent(), true);
        $contactId = $data['contactId'] ?? null;

        // Safely resolve company (may have been deleted)
        try {
            $company = $quote->getCompany();
            // Force proxy initialization to detect missing entity
            $company?->getName();
        } catch (\Doctrine\ORM\EntityNotFoundException $e) {
            return $this->json(['success' => false, 'message' => $this->translator->trans('quote.copilot.company_deleted')], 400);
        }

        if (!$company) {
            return $this->json(['success' => false, 'message' => $this->translator->trans('quote.copilot.company_deleted')], 400);
        }

        // Update quote status
        $quote->setStatus('sent');
        $quote->setAutoPublished(true);
        $this->entityManager->flush();

        // Send email notification to customer
        try {
            $toEmail = $this->sendQuoteEmail($quote, $contactId);
        } catch (\Exception $e) {
            // Log error but don't fail the request - Quote is already marked as sent
            $this->logger->error('Email sending failed for Quote ' . $quote->getId(), [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->json([
                'success' => false,
                'message' => 'Error sending email: ' . $e->getMessage()
            ], 500);
        }

        // Create Activity record for quote publication
        // Note: Activity requires a User, so we only create if user is authenticated
        $user = $this->getUser();
        if ($user && $company) {
            $activity = new \App\Entity\Activity();
            $activity->setType('QUOTE_PUBLISHED');
            $activity->setDescription(sprintf('Quote %s published and emailed to %s', 
                $quote->getQuoteNumber() ?? $quote->getId(), $toEmail));
            $activity->setCompany($company);
            $activity->setUser($user);
            $activity->setActivityDate(new \DateTime());
            $activity->setCreatedAt(new \DateTime());
            $this->entityManager->persist($activity);
        }
        
        // Create Notification for sales team
        // Find admin/sales users to notify
        $userRepository = $this->entityManager->getRepository(\App\Entity\User::class);
        $adminUsers = $userRepository->findBy(['roles' => 'ROLE_ADMIN']);
        
        // If no specific admins, try to notify current user or skip
        $notifyUsers = !empty($adminUsers) ? $adminUsers : ($user ? [$user] : []);
        
        foreach ($notifyUsers as $notifyUser) {
            $notification = new \App\Entity\Notification();
            $notification->setUser($notifyUser);
            $notification->setType('quote_published');
            $notification->setEntityType('Quote');
            $notification->setEntityId($quote->getId());
            $notification->setMessage(sprintf('Quote %s has been published', 
                $quote->getQuoteNumber() ?? $quote->getId()));
            try {
                $compName = $company?->getName();
            } catch (\Doctrine\ORM\EntityNotFoundException) {
                $compName = null;
            }
            $notification->setData([
                'quote_number' => $quote->getQuoteNumber(),
                'company_name' => $compName,
                'to_email' => $toEmail
            ]);
            $notification->setCreatedAt(new \DateTime());
            $this->entityManager->persist($notification);
        }
        
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => sprintf('Quote %s published successfully', $quote->getQuoteNumber() ?? $quote->getId())
        ]);
    }

    /**
     * Send quote email to customer
     */
    private function sendQuoteEmail(Quote $quote, ?int $contactId = null): string
    {
        try {
            $company = $quote->getCompany();
            if (!$company) {
                throw new \RuntimeException('Company not found for this quote');
            }
            // Force proxy init to detect deleted companies early
            $company->getName();
        } catch (\Doctrine\ORM\EntityNotFoundException $e) {
            throw new \RuntimeException('Company has been deleted — cannot send email');
        }
        
        // Get contact email - either specified contact or first available
        $toEmail = null;
        $contactName = null;
        $contacts = $company->getContacts();
        
        if ($contactId) {
            // Find specific contact by ID
            $contactRepo = $this->entityManager->getRepository(\App\Entity\Contact::class);
            $contact = $contactRepo->find($contactId);
            
            if ($contact && $contact->getEmail() && $contact->getCompany() === $company) {
                $toEmail = $contact->getEmail();
                $contactName = $contact->getFirstName() . ' ' . $contact->getLastName();
            } else {
                throw new \RuntimeException('Invalid contact ID or contact does not belong to this company');
            }
        } else {
            // Get first contact with an email
            if ($contacts->count() > 0) {
                foreach ($contacts as $contact) {
                    if ($contact->getEmail()) {
                        $toEmail = $contact->getEmail();
                        $contactName = $contact->getFirstName() . ' ' . $contact->getLastName();
                        break;
                    }
                }
            }
        }
        
        if (!$toEmail) {
            throw new \RuntimeException('No email address found for company or its contacts');
        }

        // Generate PDF attachment
        $pdfContent = $this->pdfGenerator->generateQuotePdf($quote);
        
        // Resolve issuing company for email branding
        $issuer = $this->issuingCompanyService->getCompanyProfile($quote->getIssuingCompany());
        
        // Create email
        $email = (new Email())
            ->from($issuer['email'])
            ->to($toEmail)
            ->subject(sprintf('Quote %s - %s', $quote->getQuoteNumber() ?? ('Q-' . $quote->getId()), $issuer['name']))
            ->html($this->renderView('emails/quote_notification.html.twig', [
                'quote' => $quote,
                'company' => $company,
                'contactName' => $contactName,
                'issuer' => $issuer,
            ]))
            ->attach($pdfContent, sprintf('quote-%s.pdf', $quote->getQuoteNumber() ?? $quote->getId()), 'application/pdf');

        // Send email
        $this->mailer->send($email);

        return $toEmail;
    }

}

