<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Quote;
use App\Entity\ComplianceDocument;
use App\Entity\Company;
use App\Entity\OnboardingPack;
use App\Repository\ReportAuditRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * UnifiedPdfGeneratorService
 * 
 * Generates all PDF document types using Twig templates and mPDF/TCPDF.
 * Stores documents in ComplianceDocument entity with SHA-256 audit trail.
 * Logs all generation events to ReportAudit for reproducibility.
 * 
 * Supports 7 document types:
 * - compliance: General compliance documents
 * - quote: Customer quotes with pricing
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
        private ReportAuditRepository $reportAuditRepository,
        private IssuingCompanyService $issuingCompanyService,
        private ?string $projectDir = null,
        private ?LoggerInterface $logger = null,
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
        // Resolve the issuing company profile for this quote
        $issuingProfile = $this->issuingCompanyService->getCompanyProfile(
            $quote->getIssuingCompany()
        );

        // Safely resolve company name (company row may have been deleted)
        try {
            $companyName = $quote->getCompany()?->getName() ?? 'N/A';
        } catch (\Doctrine\ORM\EntityNotFoundException $e) {
            $companyName = 'N/A';
        }

        // Render the quote template
        $html = $this->twig->render('pdf/quote.html.twig', [
            'quote' => $quote,
            'company_name' => $companyName,
            'issuer' => $issuingProfile,
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

        try {
            $mpdf->WriteHTML($html);
        } catch (\Exception $e) {
            $this->logger?->warning('Mpdf WriteHTML failed', ['exception' => $e]);
            throw $e;
        }
        
        $pdfContent = $mpdf->Output('', 'S'); // Return as string
        
        // Audit trail: one ReportAudit row per generated PDF (only for
        // persisted quotes — a transient entity has no stable ID to audit).
        $quoteId = $quote->getId();
        if ($quoteId !== null) {
            $this->logToAudit(
                'quote',
                'Quote',
                $quoteId,
                $this->calculateHash($pdfContent),
                bin2hex(random_bytes(12)), // version id: random, not time-enumerable
                null,
                strlen($pdfContent),
                [],
                [],
                ['quote_number' => $quote->getQuoteNumber()]
            );
        }
        
        return $pdfContent;
    }

    /**
     * Generate DFM/DFA report PDF
     * 
     * @param Quote $quote Quote with DFM lint results
     * @return ComplianceDocument Generated DFM report
     * 
     * Implementation:
     * 1. Render templates/pdf/dfm_report.html.twig
     * 2. Group findings by severity (Critical/Warning/Info)
     * 3. Show: Check description, affected parts, remediation text
     * 4. Include: Design rule references, industry standards
     * 5. Summary: Total issues count, estimated impact on manufacturability
     */
    public function generateDfmReportPdf(Quote $quote): ComplianceDocument
    {
        // 1. Load real DFM findings for this quote
        /** @var \App\Repository\DfmFindingRepository $dfmRepo */
        $dfmRepo = $this->entityManager->getRepository(\App\Entity\DfmFinding::class);
        $findings = $dfmRepo->findByQuote((int) $quote->getId());

        $severityCounts = ['CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0];
        $issues = [];
        $sectionChecks = ['placement' => [], 'solder' => [], 'bom' => []];

        $score = 100;
        foreach ($findings as $finding) {
            $severity = strtoupper((string) $finding->getSeverity());
            $severityCounts[$severity] = ($severityCounts[$severity] ?? 0) + 1;

            $type = strtoupper((string) $finding->getFindingType());
            $section = 'placement';
            if (str_contains($type, 'SOLDER') || str_contains($type, 'MASK') || str_contains($type, 'STENCIL')) {
                $section = 'solder';
            } elseif (str_contains($type, 'LIFE') || str_contains($type, 'BOM') || str_contains($type, 'SOURCE')) {
                $section = 'bom';
            }

            $status = in_array($severity, ['CRITICAL', 'HIGH'], true) ? 'fail' : ('MEDIUM' === $severity ? 'warn' : 'info');
            $sectionChecks[$section][] = [
                'status' => $status,
                'name' => $finding->getFindingType() ?? 'Finding',
                'description' => $finding->getDescription() ?? '',
            ];

            $issues[] = [
                'severity' => $severity,
                'category' => $finding->getFindingType() ?? 'General',
                'description' => $finding->getDescription() ?? '',
                'recommendation' => $finding->getRemediation() ?? '',
            ];

            // Penalty scoring: CRITICAL -25, HIGH -15, MEDIUM -5, LOW -1
            $score -= 'CRITICAL' === $severity ? 25 : ('HIGH' === $severity ? 15 : ('MEDIUM' === $severity ? 5 : 1));
        }
        $score = max(0, min(100, $score));

        // 2. Render DFM report template
        $issuingProfile = $this->issuingCompanyService->getCompanyProfile(
            $quote->getIssuingCompany()
        );
        $html = $this->twig->render('pdf/dfm_report.html.twig', [
            'quote' => $quote,
            'issuer' => $issuingProfile,
            'dfmData' => [
                'overallScore' => $score,
                'criticalCount' => ($severityCounts['CRITICAL'] ?? 0) + ($severityCounts['HIGH'] ?? 0),
                'warningCount' => $severityCounts['MEDIUM'] ?? 0,
                'infoCount' => $severityCounts['LOW'] ?? 0,
                'findingCount' => count($findings),
                'issues' => $issues,
                'placementChecks' => $sectionChecks['placement'],
                'solderChecks' => $sectionChecks['solder'],
                'bomChecks' => $sectionChecks['bom'],
            ],
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
        try {
            $company = $quote->getCompany();
            $company?->getId(); // force proxy init
        } catch (\Doctrine\ORM\EntityNotFoundException) {
            $company = null;
        }

        // Audit trail: one ReportAudit row per generated PDF
        $quoteId = $quote->getId();
        if ($quoteId !== null) {
            $this->logToAudit(
                'dfm_report',
                'Quote',
                $quoteId,
                $this->calculateHash($pdfContent),
                bin2hex(random_bytes(12)), // version id: random, not time-enumerable
                $filename,
                strlen($pdfContent)
            );
        }

        return $this->createDocument(
            $company,
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
     * Implementation:
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
        $issuingProfile = $this->issuingCompanyService->getCompanyProfile(
            $quote->getIssuingCompany()
        );
        $html = $this->twig->render('pdf/cost_breakdown.html.twig', [
            'quote' => $quote,
            'issuer' => $issuingProfile,
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
        try {
            $company = $quote->getCompany();
            $company?->getId(); // force proxy init
        } catch (\Doctrine\ORM\EntityNotFoundException) {
            $company = null;
        }
        return $this->createDocument(
            $company,
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
     * Implementation:
     * 1. Render templates/pdf/exceptions_report.html.twig
     * 2. List: Parts with imputed pricing (missing from all APIs)
     * 3. List: Parts from Alibaba (indicative pricing, not validated)
     * 4. List: Parts with long lead times (>45 days)
     * 5. Risk assessment: Impact on coverage %, reliability, delivery timeline
     * 6. Recommendations: Alternative sources, design changes, buffer stock
     */
    public function generateExceptionsReportPdf(Quote $quote): ComplianceDocument
    {
        // 1. Load real procurement exceptions for this quote
        $exceptions = $this->entityManager->getRepository(\App\Entity\ProcurementException::class)
            ->createQueryBuilder('e')
            ->join('e.bomLine', 'b')
            ->andWhere('b.quote = :quoteId')
            ->setParameter('quoteId', (int) $quote->getId())
            ->getQuery()
            ->getResult();

        $exceptionList = [];
        $categories = [];
        $criticalCount = 0;
        $warningCount = 0;
        $totalImpact = 0.0;
        $maxLeadImpact = 0;

        foreach ($exceptions as $exception) {
            $severity = strtoupper((string) $exception->getSeverity());
            $type = (string) $exception->getExceptionType();
            $bomLine = $exception->getBomLine();

            $criticalCount += in_array($severity, ['CRITICAL', 'HIGH'], true) ? 1 : 0;
            $warningCount += 'MEDIUM' === $severity ? 1 : 0;

            $lineCost = (float) ($bomLine?->getExtendedPrice() ?? 0);
            $totalImpact += $lineCost;

            $leadDays = $bomLine?->getLeadTimeDays();
            if ($leadDays !== null) {
                $maxLeadImpact = max($maxLeadImpact, $leadDays);
            }

            $categoryType = $this->mapExceptionType($type);
            if (!isset($categories[$type])) {
                $categories[$type] = [
                    'type' => $categoryType,
                    'name' => str_replace('_', ' ', ucwords(mb_strtolower($type))),
                    'count' => 0,
                    'impact' => in_array($severity, ['CRITICAL', 'HIGH'], true) ? 'high' : ('MEDIUM' === $severity ? 'medium' : 'low'),
                    'costImpact' => 0.0,
                    'leadImpact' => 0,
                ];
            }
            ++$categories[$type]['count'];
            $categories[$type]['costImpact'] += $lineCost;
            $categories[$type]['leadImpact'] = max($categories[$type]['leadImpact'], $leadDays ?? 0);

            $exceptionList[] = [
                'lineNumber' => $bomLine?->getLineNumber(),
                'mpn' => $bomLine?->getMpn() ?? '',
                'type' => $categoryType,
                'typeName' => str_replace('_', ' ', ucwords(mb_strtolower($type))),
                'manufacturer' => $bomLine?->getManufacturer() ?? '',
                'requiredQty' => $bomLine?->getQuantity() ?? 0,
                'description' => (string) $exception->getMessage(),
                'severity' => mb_strtolower($severity),
                'issue' => (string) $exception->getMessage(),
            ];
        }

        // 2. Render exceptions report template
        $issuingProfile = $this->issuingCompanyService->getCompanyProfile(
            $quote->getIssuingCompany()
        );
        $html = $this->twig->render('pdf/exceptions_report.html.twig', [
            'quote' => $quote,
            'issuer' => $issuingProfile,
            'exceptions' => $exceptionList,
            'exceptionData' => [
                'criticalCount' => $criticalCount,
                'warningCount' => $warningCount,
                'totalExceptions' => count($exceptionList),
                'impactValue' => $totalImpact,
                'actionRequired' => $criticalCount > 0,
                'actionSummary' => $criticalCount > 0
                    ? sprintf('%d critical exception(s) require immediate sourcing action.', $criticalCount)
                    : '',
                'categories' => array_values($categories),
            ],
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
        try {
            $company = $quote->getCompany();
            $company?->getId(); // force proxy init
        } catch (\Doctrine\ORM\EntityNotFoundException) {
            $company = null;
        }
        return $this->createDocument(
            $company,
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
     * Implementation:
     * 1. Render templates/pdf/sourcing_risk.html.twig
     * 2. Show: Supplier concentration (% spend per supplier)
     * 3. Show: Geographic risk (country concentration)
     * 4. Show: Single-source parts (no alternatives)
     * 5. Show: Obsolescence risk (EOL parts, NRND status)
     * 6. Mitigation: Diversification opportunities, second-source options
     */
    public function generateSourcingRiskPdf(Quote $quote): ComplianceDocument
    {
        // 1. Build real risk data from the quote's BOM lines
        $availabilityRisks = [];
        $suppliers = [];
        $supplierMap = [];
        $singleSourceParts = 0;
        $longLeadParts = 0;
        $eolParts = 0;
        $factoryStockParts = 0;
        $totalBomValue = 0.0;

        foreach ($quote->getBomLines() as $line) {
            $mpn = (string) ($line->getMpn() ?? $line->getOriginalMpn() ?? '');
            $manufacturer = (string) ($line->getManufacturer() ?? '');
            $availability = (string) ($line->getAvailability() ?? '');
            $leadTime = $line->getLeadTimeDays();
            $lifecycle = mb_strtolower((string) ($line->getLifecycleStatus() ?? ''));
            $source = (string) ($line->getProcurementSource() ?? '');
            $extended = (float) ($line->getExtendedPrice() ?? 0);
            $totalBomValue += $extended;

            if ('' === $source || 'manual' === mb_strtolower($source) || 'not found' === mb_strtolower($source)) {
                ++$singleSourceParts;
            }
            if ($leadTime !== null && $leadTime > 28) {
                ++$longLeadParts;
            }
            if (in_array($lifecycle, ['obsolete', 'nrnd', 'eol'], true)) {
                ++$eolParts;
            }
            if ('factory' === mb_strtolower($availability)) {
                ++$factoryStockParts;
            }

            $riskNotes = [];
            $level = 'low';
            if (in_array($lifecycle, ['obsolete', 'nrnd', 'eol'], true)) {
                $level = 'critical';
                $riskNotes[] = 'EOL/NRND lifecycle';
            }
            if ('factory' === mb_strtolower($availability)) {
                $level = 'medium' === $level ? 'high' : ('critical' === $level ? $level : 'medium');
                $riskNotes[] = 'Factory stock only';
            } elseif ('' === $availability) {
                $level = 'high' === $level ? $level : 'medium';
                $riskNotes[] = 'Availability unknown';
            }
            if ($leadTime !== null && $leadTime > 28) {
                $level = 'medium' === $level ? 'high' : ('low' === $level ? 'medium' : $level);
                $riskNotes[] = sprintf('%d day lead time', $leadTime);
            }
            if ($line->hasException()) {
                $level = 'high' === $level || 'critical' === $level ? $level : 'high';
                $riskNotes[] = (string) ($line->getExceptionReason() ?? 'Procurement exception');
            }

            if ('low' !== $level) {
                $availabilityRisks[] = [
                    'mpn' => $mpn ?: 'common.n_a',
                    'manufacturer' => $manufacturer ?: 'common.n_a',
                    'stock' => $availability ?: 'common.n_a',
                    'leadTime' => $leadTime !== null ? $leadTime . 'd' : 'common.n_a',
                    'sourceCount' => '' === $source ? 0 : 1,
                    'level' => $level,
                    'notes' => implode('; ', $riskNotes),
                ];
            }

            // Supplier concentration by manufacturer
            $supplierKey = $manufacturer ?: 'common.unknown';
            if (!isset($supplierMap[$supplierKey])) {
                $supplierMap[$supplierKey] = ['partCount' => 0, 'value' => 0.0];
            }
            ++$supplierMap[$supplierKey]['partCount'];
            $supplierMap[$supplierKey]['value'] += $extended;
        }

        foreach ($supplierMap as $name => $data) {
            $share = $totalBomValue > 0 ? (int) round($data['value'] / $totalBomValue * 100) : 0;
            $suppliers[] = [
                'name' => $name,
                'partCount' => $data['partCount'],
                'percentage' => $share,
                'region' => 'common.unknown',
                'riskLevel' => $share >= 50 ? 'high' : ($share >= 25 ? 'medium' : 'low'),
            ];
        }
        usort($suppliers, static fn (array $a, array $b): int => $b['percentage'] <=> $a['percentage']);

        // Data-driven mitigations
        $mitigations = [];
        if ($eolParts > 0) {
            $mitigations[] = [
                'title' => 'Dual-source EOL/NRND components',
                'description' => sprintf('%d part(s) are end-of-life or NRND. Source drop-in alternatives before stock runs out.', $eolParts),
                'impact' => 'high',
                'impactLabel' => 'High impact',
            ];
        }
        if ($singleSourceParts > 0) {
            $mitigations[] = [
                'title' => 'Add second sources for single-sourced parts',
                'description' => sprintf('%d part(s) have no confirmed automated source. Qualify alternative manufacturers.', $singleSourceParts),
                'impact' => 'high',
                'impactLabel' => 'High impact',
            ];
        }
        if ($longLeadParts > 0) {
            $mitigations[] = [
                'title' => 'Place long-lead orders early',
                'description' => sprintf('%d part(s) exceed 28-day lead times. Trigger purchase orders ahead of production.', $longLeadParts),
                'impact' => 'medium',
                'impactLabel' => 'Medium impact',
            ];
        }
        if ($factoryStockParts > 0) {
            $mitigations[] = [
                'title' => 'Secure buffer stock for factory-only parts',
                'description' => sprintf('%d part(s) are factory-stock only and carry allocation risk. Consider buffer inventory.', $factoryStockParts),
                'impact' => 'medium',
                'impactLabel' => 'Medium impact',
            ];
        }

        // Risk score: start at 100, subtract weighted penalties
        $riskScore = 100
            - min(50, $eolParts * 10)
            - min(40, $singleSourceParts * 2)
            - min(30, $longLeadParts * 2)
            - min(20, $factoryStockParts * 3);
        $riskScore = max(0, min(100, $riskScore));

        // 2. Render sourcing risk template
        $issuingProfile = $this->issuingCompanyService->getCompanyProfile(
            $quote->getIssuingCompany()
        );
        $html = $this->twig->render('pdf/sourcing_risk.html.twig', [
            'quote' => $quote,
            'issuer' => $issuingProfile,
            'riskData' => [
                'overallScore' => $riskScore,
                'singleSourceParts' => $singleSourceParts,
                'longLeadParts' => $longLeadParts,
                'eolParts' => $eolParts,
                'allocationParts' => $factoryStockParts,
                'availabilityRisks' => $availabilityRisks,
                'topSuppliers' => $suppliers,
                'geoRisks' => [],
                'mitigations' => $mitigations,
                'availabilityScore' => $riskScore,
                'availabilityLevel' => $riskScore < 25 ? 'low' : ($riskScore < 50 ? 'medium' : ($riskScore < 75 ? 'high' : 'critical')),
                'concentrationScore' => $suppliers[0]['percentage'] ?? 0,
                'concentrationLevel' => ($suppliers[0]['percentage'] ?? 0) >= 50 ? 'high' : (($suppliers[0]['percentage'] ?? 0) >= 25 ? 'medium' : 'low'),
            ],
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
        try {
            $company = $quote->getCompany();
            $company?->getId(); // force proxy init
        } catch (\Doctrine\ORM\EntityNotFoundException) {
            $company = null;
        }
        return $this->createDocument(
            $company,
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
     * Implementation:
     * 1. Render templates/pdf/audit_trail.html.twig
     * 2. Show: SHA-256 hash of each generated PDF
     * 3. Show: Dataset versions used (tariff_rates, freight_tables, etc.)
     * 4. Show: API versions used (Mouser, DigiKey, Nexar, Alibaba)
     * 5. Show: Generation timestamp, user, IP address
     * 6. Purpose: Enable reproducibility for compliance audits
     */
    public function generateAuditTrailPdf($entity): ComplianceDocument
    {
        // Safely resolve company name (company row may have been deleted)
        try {
            $companyName = method_exists($entity, 'getCompany')
                ? ($entity->getCompany()?->getName() ?? 'N/A')
                : 'N/A';
        } catch (\Doctrine\ORM\EntityNotFoundException $e) {
            $companyName = 'N/A';
        }

        // 1. Build audit data from real AuditLog entries for this entity
        $entityType = (new \ReflectionClass($entity))->getShortName();
        $entityId = method_exists($entity, 'getId') ? $entity->getId() : null;

        /** @var \App\Repository\AuditLogRepository $auditRepo */
        $auditRepo = $this->entityManager->getRepository(\App\Entity\AuditLog::class);
        $logs = $entityId !== null
            ? $auditRepo->findByEntity($entityType, (int) $entityId)
            : [];

        $events = array_map(fn (\App\Entity\AuditLog $log): array => $this->auditEventToArray($log), array_reverse($logs));

        $users = array_values(array_unique(array_filter(array_map(
            fn (\App\Entity\AuditLog $log): ?string => $log->getUser()?->getFullName() ?? $log->getUser()?->getEmail(),
            $logs
        ))));

        $auditData = [
            'events' => $events,
            'totalEvents' => count($logs),
            'revisions' => count(array_filter($logs, fn (\App\Entity\AuditLog $log): bool => $log->getAction() === 'update')),
            'approvals' => count(array_filter($logs, fn (\App\Entity\AuditLog $log): bool => stripos((string) $log->getAction(), 'approv') !== false)),
            'users' => count($users),
            'documentHash' => hash('sha256', json_encode([
                'entity' => $entityType,
                'entityId' => $entityId,
                'events' => $events,
            ])),
            'auditHash' => hash('sha256', json_encode($events)),
        ];

        // 2. Render audit trail template
        $html = $this->twig->render('pdf/audit_trail.html.twig', [
            'entity' => $entity,
            'quote' => $entity,
            'company_name' => $companyName,
            'auditData' => $auditData,
        ]);
        
        // 3. Generate PDF
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
        
        // 4. Create document
        $filename = sprintf('audit_trail_%s_%s_%s.pdf', strtolower($entityType), $entityId ?? 'unknown', date('Ymd'));
        
        // Audit trail: one ReportAudit row per generated PDF
        if ($entityId !== null) {
            $this->logToAudit(
                'audit_trail',
                $entityType,
                $entityId,
                $this->calculateHash($pdfContent),
                bin2hex(random_bytes(12)), // version id: random, not time-enumerable
                $filename,
                strlen($pdfContent),
                [],
                [],
                ['audit_document_hash' => $auditData['auditHash'] ?? null]
            );
        }
        
        try {
            $auditCompany = method_exists($entity, 'getCompany') ? $entity->getCompany() : null;
            $auditCompany?->getId(); // force proxy init
        } catch (\Doctrine\ORM\EntityNotFoundException) {
            $auditCompany = null;
        }
        return $this->createDocument(
            $auditCompany,
            'audit_trail',
            $pdfContent,
            $filename
        );
    }

    /**
     * Convert an AuditLog entry into the template's event shape.
     *
     * @return array<string, mixed>
     */
    private function auditEventToArray(\App\Entity\AuditLog $log): array
    {
        $fields = $log->getChangedFields() ?? [];
        $oldValues = $log->getOldValues() ?? [];
        $newValues = $log->getNewValues() ?? [];

        $changes = [];
        foreach ($fields as $field) {
            $changes[] = [
                'field' => $field,
                'oldValue' => is_array($oldValues) && array_key_exists($field, $oldValues) ? $this->stringifyAuditValue($oldValues[$field]) : '-',
                'newValue' => is_array($newValues) && array_key_exists($field, $newValues) ? $this->stringifyAuditValue($newValues[$field]) : '-',
            ];
        }

        $user = $log->getUser();

        return [
            'type' => $log->getAction() ?? 'modified',
            'title' => ucfirst($log->getAction() ?? 'Event'),
            'user' => $user?->getFullName() ?? $user?->getEmail() ?? 'System',
            'timestamp' => $log->getCreatedAt()?->format('Y-m-d H:i') ?? 'N/A',
            'description' => $log->getNotes(),
            'changes' => $changes,
        ];
    }

    private function stringifyAuditValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value) || is_object($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string) $value;
    }

    /**
     * Generate supplier onboarding pack PDF
     * 
     * @param OnboardingPack $pack Onboarding pack with pre-filled data
     * @return ComplianceDocument Generated onboarding pack
     * 
     * Implementation:
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
        $uploadDir = ($this->projectDir ?? '') . '/public/uploads/onboarding';
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
     * Implementation:
     * 1. Calculate SHA-256 hash
     * 2. Create ComplianceDocument entity
     * 3. Set documentType, sha256Hash, versionId
     * 4. Upload PDF to public/uploads/documents/{company_id}/
     * 5. Link to Company entity
     * 6. Persist and flush
     */
    private /**
 * @param array<string|int, mixed> $metadata
 */
function createDocument(
        ?Company $company,
        string $documentType,
        string $pdfContent,
        string $filename,
        array $metadata = []
    ): ComplianceDocument {
        // 1. Calculate SHA-256 hash
        $sha256Hash = $this->calculateHash($pdfContent);
        
        // 2. Safely resolve company ID (company may have been deleted)
        try {
            $companyId = $company?->getId();
        } catch (\Doctrine\ORM\EntityNotFoundException) {
            $companyId = null;
        }

        // 3. Create upload directory
        $uploadDir = sprintf('public/uploads/documents/%s', $companyId ?? 'orphaned');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // 4. Save PDF file
        $filepath = "$uploadDir/$filename";
        file_put_contents($filepath, $pdfContent);
        
        // 5. Create ComplianceDocument against the REAL model: Company
        // association + polymorphic entity reference for generated docs.
        $document = new ComplianceDocument();
        if ($companyId) {
            $company = $this->entityManager->find(\App\Entity\Company::class, $companyId);
            if ($company !== null) {
                $document->setCompany($company);
                $document->setEntityId($companyId);
                $document->setEntityType('company');
            }
        }
        $document->setDocumentType($documentType);
        $document->setFilePath($filepath);
        $document->setSha256Hash($sha256Hash);
        $document->setVersionId(bin2hex(random_bytes(12))); // random, not time-enumerable
        $document->setUploadedAt(new \DateTime());

        if (!empty($metadata)) {
            $document->setMetadataJson(is_array($metadata) ? $metadata : ['raw' => $metadata]);
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
     * @param string|null $fileName Generated file name
     * @param int|null $fileSize Generated file size in bytes
     * @param array $datasetVersions Dataset versions used (e.g., ['tariff_rates' => 'v1.2.3'])
     * @param array $apiVersions API versions used (e.g., ['mouser' => '2024.10'])
     * @param array $metadata Additional metadata
     */
    private /**
 * @param array<string|int, mixed> $datasetVersions
 * @param array<string|int, mixed> $apiVersions
 * @param array<string|int, mixed> $metadata
 */
function logToAudit(
        string $reportType,
        string $entityType,
        int $entityId,
        string $sha256Hash,
        string $versionId,
        ?string $fileName = null,
        ?int $fileSize = null,
        array $datasetVersions = [],
        array $apiVersions = [],
        array $metadata = []
    ): void {
        try {
            $audit = new \App\Entity\ReportAudit();
            $audit->setReportType($reportType);
            $audit->setEntityType($entityType);
            $audit->setEntityId($entityId);
            $audit->setSha256Hash($sha256Hash);
            $audit->setVersionId($versionId);
            $audit->setFileName($fileName);
            $audit->setFileSize($fileSize);
            $audit->setDatasetVersions($datasetVersions ?: null);
            $audit->setApiVersions($apiVersions ?: null);
            $audit->setMetadata(array_merge($metadata, [
                'generated_at' => date('Y-m-d H:i:s'),
                'server' => gethostname(),
                'php_version' => PHP_VERSION,
            ]));

            $this->entityManager->persist($audit);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            // A failed audit row must never break PDF generation itself.
            $this->logger?->error('Failed to persist ReportAudit entry', [
                'report_type' => $reportType,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map a procurement exception type to a CSS class suffix for the exceptions report.
     */
    private function mapExceptionType(string $type): string
    {
        return match (mb_strtoupper($type)) {
            'NOT_FOUND', 'ALT_REQUIRED', 'NO_API' => 'alt-required',
            'NO_STOCK' => 'no-stock',
            'OBSOLETE', 'EOL', 'NRND' => 'eol',
            'LONG_LEAD', 'LEAD_TIME' => 'long-lead',
            'MOQ' => 'moq',
            'PRICE_SPIKE', 'PRICE' => 'price',
            default => 'custom',
        };
    }
}
