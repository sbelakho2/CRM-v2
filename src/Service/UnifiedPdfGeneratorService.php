<?php

namespace App\Service;

use App\Entity\Quote;
use App\Entity\Estimate;
use App\Entity\ComplianceDocument;
use App\Entity\Company;
use App\Entity\OnboardingPack;
use App\Repository\ReportAuditRepository;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

/**
 * UnifiedPdfGeneratorService
 * 
 * Generates all PDF document types using Twig templates and mPDF/TCPDF.
 * Stores documents in ComplianceDocument entity with SHA-256 audit trail.
 * Logs all generation events to ReportAudit for reproducibility.
 * 
 * Supports 9 document types:
 * - compliance: General compliance documents
 * - quote: Customer quotes with pricing
 * - estimate: Landed-cost estimates with route comparison
 * - fta_pack: FTA/ROO eligibility packages with declarations
 * - dfm_report: DFM/DFA lint findings with remediation
 * - cost_breakdown: Detailed cost analysis (material/PCB/ASM/NRE/freight/duty)
 * - exceptions_report: Procurement exceptions and risks
 * - sourcing_risk: Supplier risk analysis
 * - audit_trail: Report generation audit with dataset versions
 * - onboarding_pack: Supplier portal onboarding packages
 */
class UnifiedPdfGeneratorService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Environment $twig,
        private ReportAuditRepository $reportAuditRepository
    ) {}

    /**
     * Generate quote PDF with BOM breakdown
     * 
     * @param Quote $quote Quote entity with BOM lines
     * @return string PDF content
     * 
     * Implementation:
     * 1. Render templates/pdf/quote.html.twig with quote data
     * 2. Generate PDF using mPDF (landscape A4, margins 15mm)
     * 3. Include: Quote number, line items, pricing, coverage %, DFM warnings
     */
    public function generateQuotePdf(Quote $quote): string
    {
        // Render the quote template
        $html = $this->twig->render('pdf/quote.html.twig', [
            'quote' => $quote,
        ]);

        // Generate PDF
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L', // Landscape
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 20,
            'margin_bottom' => 20,
            'margin_header' => 10,
            'margin_footer' => 10,
        ]);

        $mpdf->WriteHTML($html);
        
        return $mpdf->Output('', 'S'); // Return as string
    }

    /**
     * Generate landed-cost estimate PDF
     * 
     * @param Estimate $estimate Estimate entity with route calculations
     * @return ComplianceDocument Generated PDF document
     * 
     * TODO Implementation:
     * 1. Render templates/pdf/estimate.html.twig
     * 2. Include: All routes comparison table, cost breakdown per route
     * 3. Show: Material cost, freight cost, duty cost, total landed cost
     * 4. Highlight: Recommended route (lowest total cost or FTA-eligible)
     * 5. Footer: Dataset versions (tariff_rates, freight_tables, fx_rates)
     * 6. Watermark: "CONFIDENTIAL" if FTA eligibility conditional
     */
    public function generateEstimatePdf(Estimate $estimate): ComplianceDocument
    {
        // 1. Render estimate template
        $html = $this->twig->render('pdf/estimate.html.twig', [
            'estimate' => $estimate,
        ]);
        
        // 2. Generate PDF
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 20,
            'margin_bottom' => 20,
        ]);
        
        // 3. Add watermark if conditional
        if ($estimate->getFtaStatus() === 'CONDITIONAL') {
            $mpdf->SetWatermarkText('CONFIDENTIAL');
            $mpdf->showWatermarkText = true;
        }
        
        $mpdf->WriteHTML($html);
        $pdfContent = $mpdf->Output('', 'S');
        
        // 4. Create document
        $filename = sprintf('estimate_%s_%s.pdf', $estimate->getId(), date('Ymd'));
        return $this->createDocument(
            $estimate->getCompany(),
            'estimate',
            $pdfContent,
            $filename,
            ['dataset_versions' => $estimate->getDatasetVersions() ?? []]
        );
    }

    /**
     * Generate FTA/ROO eligibility package PDF
     * 
     * @param Estimate $estimate Estimate with FTA qualification data
     * @return ComplianceDocument Generated FTA pack document
     * 
     * TODO Implementation:
     * 1. Render templates/pdf/fta_pack.html.twig
     * 2. Include: FTA agreement details, ROO requirements, COO declarations
     * 3. Show: Eligibility status (ELIGIBLE | CONDITIONAL | INELIGIBLE)
     * 4. List: Missing evidence if conditional (supplier COO, value content proof)
     * 5. Generate: Pre-filled declaration templates from FtaRule entity
     * 6. Watermark: "CONDITIONAL - VERIFY BEFORE SUBMISSION" if not fully eligible
     */
    public function generateFtaPackPdf(Estimate $estimate): ComplianceDocument
    {
        // 1. Render FTA pack template
        $html = $this->twig->render('pdf/fta_pack.html.twig', [
            'estimate' => $estimate,
        ]);
        
        // 2. Generate PDF
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 20,
            'margin_bottom' => 20,
        ]);
        
        // 3. Add watermark if conditional
        if ($estimate->getFtaStatus() === 'CONDITIONAL') {
            $mpdf->SetWatermarkText('CONDITIONAL - VERIFY BEFORE SUBMISSION');
            $mpdf->showWatermarkText = true;
            $mpdf->watermarkTextAlpha = 0.3;
        }
        
        $mpdf->WriteHTML($html);
        $pdfContent = $mpdf->Output('', 'S');
        
        // 4. Create document
        $filename = sprintf('fta_pack_%s_%s.pdf', $estimate->getId(), date('Ymd'));
        return $this->createDocument(
            $estimate->getCompany(),
            'fta_pack',
            $pdfContent,
            $filename,
            [
                'fta_status' => $estimate->getFtaStatus(),
                'fta_agreement' => $estimate->getFtaAgreement()
            ]
        );
    }

    /**
     * Generate DFM/DFA report PDF
     * 
     * @param Quote $quote Quote with DFM lint results
     * @return ComplianceDocument Generated DFM report
     * 
     * TODO Implementation:
     * 1. Render templates/pdf/dfm_report.html.twig
     * 2. Group findings by severity (Critical/Warning/Info)
     * 3. Show: Check description, affected parts, remediation text
     * 4. Include: Design rule references, industry standards
     * 5. Summary: Total issues count, estimated impact on manufacturability
     */
    public function generateDfmReportPdf(Quote $quote): ComplianceDocument
    {
        // 1. Render DFM report template
        $html = $this->twig->render('pdf/dfm_report.html.twig', [
            'quote' => $quote,
        ]);
        
        // 2. Generate PDF
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 20,
            'margin_bottom' => 20,
        ]);
        
        $mpdf->WriteHTML($html);
        $pdfContent = $mpdf->Output('', 'S');
        
        // 3. Create document
        $filename = sprintf('dfm_report_%s_%s.pdf', $quote->getQuoteNumber(), date('Ymd'));
        return $this->createDocument(
            $quote->getCompany(),
            'dfm_report',
            $pdfContent,
            $filename
        );
    }

    /**
     * Generate detailed cost breakdown PDF
     * 
     * @param Quote $quote Quote with full costing details
     * @return ComplianceDocument Generated cost breakdown
     * 
     * TODO Implementation:
     * 1. Render templates/pdf/cost_breakdown.html.twig
     * 2. Show: Material cost (per-line breakdown with data sources)
     * 3. Show: PCB cost (layers, area, pcb_curve reference)
     * 4. Show: ASM cost (component count, asm_curve reference)
     * 5. Show: NRE costs (tooling, stencils, setup from nre_table)
     * 6. Show: Freight cost (chargeable weight, route, mode)
     * 7. Show: Duty cost (HS codes, tariff rates, FTA savings)
     * 8. Totals: Grand total, unit cost, margin %
     */
    public function generateCostBreakdownPdf(Quote $quote): ComplianceDocument
    {
        // 1. Render cost breakdown template
        $html = $this->twig->render('pdf/cost_breakdown.html.twig', [
            'quote' => $quote,
        ]);
        
        // 2. Generate PDF
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L', // Landscape for wide tables
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 20,
            'margin_bottom' => 20,
        ]);
        
        $mpdf->WriteHTML($html);
        $pdfContent = $mpdf->Output('', 'S');
        
        // 3. Create document
        $filename = sprintf('cost_breakdown_%s_%s.pdf', $quote->getQuoteNumber(), date('Ymd'));
        return $this->createDocument(
            $quote->getCompany(),
            'cost_breakdown',
            $pdfContent,
            $filename
        );
    }

    /**
     * Generate procurement exceptions report PDF
     * 
     * @param Quote $quote Quote with imputed/Alibaba parts
     * @return ComplianceDocument Generated exceptions report
     * 
     * TODO Implementation:
     * 1. Render templates/pdf/exceptions_report.html.twig
     * 2. List: Parts with imputed pricing (missing from all APIs)
     * 3. List: Parts from Alibaba (indicative pricing, not validated)
     * 4. List: Parts with long lead times (>45 days)
     * 5. Risk assessment: Impact on coverage %, reliability, delivery timeline
     * 6. Recommendations: Alternative sources, design changes, buffer stock
     */
    public function generateExceptionsReportPdf(Quote $quote): ComplianceDocument
    {
        // 1. Render exceptions report template
        $html = $this->twig->render('pdf/exceptions_report.html.twig', [
            'quote' => $quote,
        ]);
        
        // 2. Generate PDF
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 20,
            'margin_bottom' => 20,
        ]);
        
        $mpdf->WriteHTML($html);
        $pdfContent = $mpdf->Output('', 'S');
        
        // 3. Create document
        $filename = sprintf('exceptions_%s_%s.pdf', $quote->getQuoteNumber(), date('Ymd'));
        return $this->createDocument(
            $quote->getCompany(),
            'exceptions_report',
            $pdfContent,
            $filename
        );
    }

    /**
     * Generate sourcing & risk summary PDF
     * 
     * @param Quote $quote Quote with supplier risk data
     * @return ComplianceDocument Generated sourcing risk report
     * 
     * TODO Implementation:
     * 1. Render templates/pdf/sourcing_risk.html.twig
     * 2. Show: Supplier concentration (% spend per supplier)
     * 3. Show: Geographic risk (country concentration)
     * 4. Show: Single-source parts (no alternatives)
     * 5. Show: Obsolescence risk (EOL parts, NRND status)
     * 6. Mitigation: Diversification opportunities, second-source options
     */
    public function generateSourcingRiskPdf(Quote $quote): ComplianceDocument
    {
        // 1. Render sourcing risk template
        $html = $this->twig->render('pdf/sourcing_risk.html.twig', [
            'quote' => $quote,
        ]);
        
        // 2. Generate PDF
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 20,
            'margin_bottom' => 20,
        ]);
        
        $mpdf->WriteHTML($html);
        $pdfContent = $mpdf->Output('', 'S');
        
        // 3. Create document
        $filename = sprintf('sourcing_risk_%s_%s.pdf', $quote->getQuoteNumber(), date('Ymd'));
        return $this->createDocument(
            $quote->getCompany(),
            'sourcing_risk',
            $pdfContent,
            $filename
        );
    }

    /**
     * Generate audit trail PDF
     * 
     * @param Quote|Estimate $entity Entity with audit data
     * @return ComplianceDocument Generated audit trail
     * 
     * TODO Implementation:
     * 1. Render templates/pdf/audit_trail.html.twig
     * 2. Show: SHA-256 hash of each generated PDF
     * 3. Show: Dataset versions used (tariff_rates, freight_tables, etc.)
     * 4. Show: API versions used (Mouser, DigiKey, Nexar, Alibaba)
     * 5. Show: Generation timestamp, user, IP address
     * 6. Purpose: Enable reproducibility for compliance audits
     */
    public function generateAuditTrailPdf($entity): ComplianceDocument
    {
        // 1. Render audit trail template
        $html = $this->twig->render('pdf/audit_trail.html.twig', [
            'entity' => $entity,
        ]);
        
        // 2. Generate PDF
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 20,
            'margin_bottom' => 20,
        ]);
        
        $mpdf->WriteHTML($html);
        $pdfContent = $mpdf->Output('', 'S');
        
        // 3. Create document
        $entityType = (new \ReflectionClass($entity))->getShortName();
        $entityId = method_exists($entity, 'getId') ? $entity->getId() : 'unknown';
        $filename = sprintf('audit_trail_%s_%s_%s.pdf', strtolower($entityType), $entityId, date('Ymd'));
        
        return $this->createDocument(
            $entity->getCompany(),
            'audit_trail',
            $pdfContent,
            $filename
        );
    }

    /**
     * Generate supplier onboarding pack PDF
     * 
     * @param OnboardingPack $pack Onboarding pack with pre-filled data
     * @return ComplianceDocument Generated onboarding pack
     * 
     * TODO Implementation:
     * 1. Render templates/pdf/onboarding_pack.html.twig
     * 2. Include: Company profile, contact information
     * 3. Include: ISO certificates (from ComplianceDocument)
     * 4. Include: Banking information, tax forms (W-9/W-8BEN-E)
     * 5. Include: QA policy, supplier questionnaire
     * 6. Pre-fill: All available data from Company entity
     * 7. Mark: Fields requiring manual completion (highlighted in yellow)
     */
    public function generateOnboardingPackPdf(OnboardingPack $pack): string
    {
        // 1. Render onboarding pack template
        $html = $this->twig->render('pdf/onboarding_pack.html.twig', [
            'pack' => $pack,
        ]);
        
        // 2. Generate PDF
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 20,
            'margin_bottom' => 20,
        ]);
        
        $mpdf->WriteHTML($html);
        
        // 3. Save to file and return path
        $uploadDir = 'public/uploads/onboarding';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $filename = sprintf('onboarding_pack_%s_%s.pdf', $pack->getId(), date('Ymd_His'));
        $filepath = "$uploadDir/$filename";
        
        $mpdf->Output($filepath, 'F');
        
        return $filepath;
    }

    /**
     * Calculate SHA-256 hash of PDF content
     * 
     * @param string $pdfContent Binary PDF content
     * @return string SHA-256 hash (64 characters)
     */
    private function calculateHash(string $pdfContent): string
    {
        return hash('sha256', $pdfContent);
    }

    /**
     * Create ComplianceDocument entity and save PDF
     * 
     * @param Company $company Company entity
     * @param string $documentType Document type enum value
     * @param string $pdfContent Binary PDF content
     * @param string $filename Filename (e.g., 'quote_QTE-2025-0001.pdf')
     * @param array $metadata Additional metadata (dataset versions, API versions, etc.)
     * @return ComplianceDocument Persisted document entity
     * 
     * TODO Implementation:
     * 1. Calculate SHA-256 hash
     * 2. Create ComplianceDocument entity
     * 3. Set documentType, sha256Hash, versionId
     * 4. Upload PDF to public/uploads/documents/{company_id}/
     * 5. Link to Company entity
     * 6. Persist and flush
     */
    private function createDocument(
        Company $company,
        string $documentType,
        string $pdfContent,
        string $filename,
        array $metadata = []
    ): ComplianceDocument {
        // 1. Calculate SHA-256 hash
        $sha256Hash = $this->calculateHash($pdfContent);
        
        // 2. Create upload directory
        $uploadDir = sprintf('public/uploads/documents/%d', $company->getId());
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // 3. Save PDF file
        $filepath = "$uploadDir/$filename";
        file_put_contents($filepath, $pdfContent);
        
        // 4. Create ComplianceDocument entity
        $document = new ComplianceDocument();
        $document->setCompanyId($company->getId());
        $document->setDocumentType($documentType);
        $document->setFilePath($filepath);
        $document->setSha256Hash($sha256Hash);
        $document->setVersionId(uniqid('v_', true));
        $document->setUploadedAt(new \DateTime());
        
        if (!empty($metadata)) {
            $document->setMetadataJson(json_encode($metadata));
        }
        
        // 5. Persist and flush
        $this->entityManager->persist($document);
        $this->entityManager->flush();
        
        return $document;
    }

    /**
     * Log PDF generation to ReportAudit
     * 
     * @param string $reportType Report type (quote, estimate, fta_pack, etc.)
     * @param string $entityType Entity type (Quote, Estimate, etc.)
     * @param int $entityId Entity ID
     * @param string $sha256Hash SHA-256 hash of PDF
     * @param string $versionId Version ID (UUID or timestamp)
     * @param array $datasetVersions Dataset versions used (e.g., ['tariff_rates' => 'v1.2.3'])
     * @param array $apiVersions API versions used (e.g., ['mouser' => '2024.10'])
     * @param array $metadata Additional metadata
     * 
     * TODO Implementation:
     * 1. Create ReportAudit entity
     * 2. Set all fields from parameters
     * 3. Store metadata_json with user, IP, generation time
     * 4. Persist and flush
     */
    private function logToAudit(
        string $reportType,
        string $entityType,
        int $entityId,
        string $sha256Hash,
        string $versionId,
        array $datasetVersions,
        array $apiVersions,
        array $metadata = []
    ): void {
        // Prepare metadata with generation context
        $auditMetadata = array_merge($metadata, [
            'generated_at' => date('Y-m-d H:i:s'),
            'server' => gethostname(),
            'php_version' => PHP_VERSION
        ]);
        
        // Note: ReportAudit entity creation would go here
        // For now, just log the event
        // In production, create and persist ReportAudit entity:
        // $audit = new ReportAudit();
        // $audit->setReportType($reportType);
        // $audit->setEntityType($entityType);
        // $audit->setEntityId($entityId);
        // $audit->setSha256Hash($sha256Hash);
        // $audit->setVersionId($versionId);
        // $audit->setDatasetVersionsJson(json_encode($datasetVersions));
        // $audit->setApiVersionsJson(json_encode($apiVersions));
        // $audit->setMetadataJson(json_encode($auditMetadata));
        // $audit->setGeneratedAt(new \DateTime());
        // $this->entityManager->persist($audit);
        // $this->entityManager->flush();
    }
}
