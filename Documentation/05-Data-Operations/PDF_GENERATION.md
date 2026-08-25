# PDF Generation System

**Version:** 1.0  
**Last Updated:** October 29, 2025  
**Service:** `UnifiedPdfGeneratorService`

---

## Overview

The PDF Generation System creates 9 different types of professional documents used throughout the CRM workflow. All PDFs are generated with consistent branding, SHA-256 integrity hashing, and stored in the ComplianceDocument table for auditing.

### Document Types

1. **Quote PDF** - Customer quote with line items, pricing, terms
2. **Estimate PDF** - Landed-cost estimate with duty, freight, FX breakdown
3. **FTA Pack** - Free Trade Agreement documentation package
4. **DFM Report** - Design for Manufacturing analysis with findings
5. **Cost Breakdown** - Detailed PCB/ASM/NRE cost calculation
6. **Exceptions Report** - BOM lines that couldn't be priced/sourced
7. **Sourcing Risk** - Supply chain risk assessment report
8. **Audit Trail** - Complete activity log for compliance
9. **Onboarding Pack** - Supplier portal application package

---

## PDF Library Selection

### mPDF vs TCPDF Comparison

| Feature | mPDF | TCPDF |
|---------|------|-------|
| **HTML/CSS Support** | Excellent (modern CSS3) | Good (basic CSS2) |
| **Performance** | Fast | Moderate |
| **File Size** | Smaller | Larger |
| **Unicode/Fonts** | Native UTF-8 support | Requires configuration |
| **Learning Curve** | Easy | Moderate |
| **Symfony Integration** | Simple | Simple |
| **License** | GPL v2 | LGPL v3 |
| **Maintenance** | Active | Active |

**Recommendation:** **mPDF** for better HTML/CSS support and smaller file sizes.

### Installation

```bash
composer require mpdf/mpdf
```

---

## Base Template Structure

### Shared Components

All PDFs share common elements:
- **Header:** Company logo, document type, document number
- **Footer:** Page numbers, generation date, disclaimer
- **Branding:** Geist/Claude color scheme (--claude-cream, --claude-tan, --claude-purple)
- **Typography:** Georgia serif for headers, system sans-serif for body

### Base CSS

**File:** `templates/pdf/base.css.twig`

```css
@page {
    margin: 20mm 15mm;
    
    @top-left {
        content: element(header);
    }
    
    @bottom-center {
        content: "Page " counter(page) " of " counter(pages);
    }
}

body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    font-size: 10pt;
    color: #1a1a1a;
    background: #F5F3EE; /* --claude-cream */
}

h1, h2, h3 {
    font-family: Georgia, serif;
    color: #9B6B9E; /* --claude-purple */
}

h1 {
    font-size: 20pt;
    margin-top: 0;
}

h2 {
    font-size: 16pt;
    border-bottom: 2px solid #E8E3D8; /* --claude-tan */
    padding-bottom: 5pt;
}

h3 {
    font-size: 12pt;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin: 10pt 0;
}

th {
    background: #E8E3D8; /* --claude-tan */
    color: #1a1a1a;
    font-weight: 600;
    text-align: left;
    padding: 8pt;
    border: 1px solid #d0cbc0;
}

td {
    padding: 6pt 8pt;
    border: 1px solid #d0cbc0;
}

tr:nth-child(even) {
    background: #faf9f7;
}

.header {
    position: running(header);
    border-bottom: 3px solid #9B6B9E; /* --claude-purple */
    padding-bottom: 10pt;
    margin-bottom: 20pt;
}

.logo {
    float: left;
    max-height: 50pt;
}

.doc-info {
    float: right;
    text-align: right;
    font-size: 9pt;
}

.footer {
    font-size: 8pt;
    color: #666;
    text-align: center;
    border-top: 1px solid #E8E3D8;
    padding-top: 5pt;
}

.total-row {
    background: #9B6B9E !important; /* --claude-purple */
    color: white;
    font-weight: bold;
}

.warning-box {
    background: #fff3cd;
    border-left: 4px solid #ffc107;
    padding: 10pt;
    margin: 10pt 0;
}

.info-box {
    background: #d1ecf1;
    border-left: 4px solid #17a2b8;
    padding: 10pt;
    margin: 10pt 0;
}
```

---

## Document Templates

### 1. Quote PDF

**Purpose:** Customer-facing quote with line items, total pricing, payment terms

**Template:** `templates/pdf/quote.html.twig`

**Data Required:**
- `quote` (Quote entity)
- `bomLines` (array of BomLine entities)
- `company` (customer Company entity)
- `totals` (calculated subtotal, tax, total)

**Sample Structure:**
```twig
<!DOCTYPE html>
<html>
<head>
    <style>
        {% include 'pdf/base.css.twig' %}
    </style>
</head>
<body>
    <div class="header">
        <img src="{{ logo_path }}" class="logo" alt="Logo">
        <div class="doc-info">
            <strong>QUOTE</strong><br>
            Quote #: {{ quote.quoteNumber }}<br>
            Date: {{ quote.createdAt|date('Y-m-d') }}<br>
            Valid Until: {{ quote.validUntil|date('Y-m-d') }}
        </div>
        <div style="clear: both;"></div>
    </div>
    
    <h1>Quote for {{ company.name }}</h1>
    
    <div style="margin-bottom: 20pt;">
        <strong>Bill To:</strong><br>
        {{ company.name }}<br>
        {{ company.address }}<br>
        {{ company.city }}, {{ company.country }}
    </div>
    
    <h2>Line Items</h2>
    <table>
        <thead>
            <tr>
                <th>Line</th>
                <th>MPN</th>
                <th>Manufacturer</th>
                <th>Description</th>
                <th>Qty</th>
                <th>Unit Price</th>
                <th>Ext. Price</th>
            </tr>
        </thead>
        <tbody>
            {% for line in bomLines %}
            <tr>
                <td>{{ loop.index }}</td>
                <td>{{ line.mpn }}</td>
                <td>{{ line.manufacturer }}</td>
                <td>{{ line.description }}</td>
                <td>{{ line.quantity }}</td>
                <td>${{ line.unitPrice|number_format(2) }}</td>
                <td>${{ (line.quantity * line.unitPrice)|number_format(2) }}</td>
            </tr>
            {% endfor %}
            
            <tr>
                <td colspan="6" style="text-align: right;"><strong>Subtotal</strong></td>
                <td><strong>${{ totals.subtotal|number_format(2) }}</strong></td>
            </tr>
            <tr>
                <td colspan="6" style="text-align: right;">Tax ({{ totals.taxRate }}%)</td>
                <td>${{ totals.tax|number_format(2) }}</td>
            </tr>
            <tr class="total-row">
                <td colspan="6" style="text-align: right;">TOTAL</td>
                <td>${{ totals.total|number_format(2) }}</td>
            </tr>
        </tbody>
    </table>
    
    <h2>Payment Terms</h2>
    <p>{{ quote.paymentTerms }}</p>
    
    <h2>Notes</h2>
    <p>{{ quote.notes }}</p>
    
    <div class="footer">
        Generated on {{ "now"|date('Y-m-d H:i:s') }} | CRM Starz Morocco
    </div>
</body>
</html>
```

### 2. Estimate PDF

> **Correction (2026-08):** The `Estimate` entity and `templates/pdf/estimate.html.twig` were removed during the 2026 refactoring. This section is retained for historical reference only; landed-cost estimates are now produced via `Quote`/`QuotePartBreakdown`.

**Purpose:** Landed-cost estimate with duty, freight, FX breakdown

**Template:** `templates/pdf/estimate.html.twig`

**Data Required:**
- `estimate` (Estimate entity)
- `dutyCosts` (array of duty calculations)
- `freightCost` (freight calculation)
- `fxRate` (exchange rate)

**Key Sections:**
- Incoterm selection
- Freight cost breakdown (weight, mode, carrier)
- Duty calculation (HTS code, rate, amount)
- FX conversion (MAD → USD)
- Total landed cost

### 3. FTA Pack

**Purpose:** Free Trade Agreement documentation for customs clearance

**Template:** `templates/pdf/fta_pack.html.twig`

**Data Required:**
- `estimate` (Estimate entity)
- `ftaRules` (array of FTA eligibility rules)
- `cooDeclarations` (Certificate of Origin declarations)

**Key Sections:**
- Certificate of Origin
- HS code classification
- FTA rule applied (e.g., "Morocco-US FTA, 0% duty")
- Country of origin declarations
- Importer/exporter information

### 4. DFM Report

**Purpose:** Design for Manufacturing analysis with findings and remediation

**Template:** `templates/pdf/dfm_report.html.twig`

**Data Required:**
- `bomLines` (array with DFM findings)
- `dfmFindings` (array of DfmRule violations)
- `severityCounts` (count by CRITICAL, HIGH, MEDIUM, LOW)

**Key Sections:**
- Executive summary (pass/fail, severity breakdown)
- Findings by severity (table with line #, rule, remediation)
- Detailed analysis per BOM line
- Recommendations

### 5. Cost Breakdown

**Purpose:** Detailed PCB/ASM/NRE cost calculation

**Template:** `templates/pdf/cost_breakdown.html.twig`

**Data Required:**
- `pcbCost` (PCB manufacturing cost)
- `asmCost` (Assembly cost)
- `nreCost` (Non-recurring engineering cost)
- `partsCost` (Component costs from BOM)

**Key Sections:**
- PCB specs (layers, dimensions, quantity)
- Assembly specs (SMT, THT, inspection)
- NRE breakdown (stencil, fixtures, setup)
- Parts cost (BOM line items)
- Total project cost

### 6. Exceptions Report

**Purpose:** BOM lines that couldn't be priced or sourced

**Template:** `templates/pdf/exceptions_report.html.twig`

**Data Required:**
- `exceptions` (array of BOM lines with status=NOT_FOUND)
- `coverage` (percentage of BOM priced)

**Key Sections:**
- Summary (total exceptions, coverage %)
- Exception table (line #, MPN, reason)
- Recommended actions (manual sourcing, alternate parts)

### 7. Sourcing Risk

**Purpose:** Supply chain risk assessment

**Template:** `templates/pdf/sourcing_risk.html.twig`

**Data Required:**
- `bomLines` (with risk scores)
- `riskFactors` (obsolescence, single-source, lead time)

**Key Sections:**
- Risk score (0-100)
- High-risk components (table)
- Mitigation strategies
- Alternate suppliers

### 8. Audit Trail

**Purpose:** Complete activity log for compliance

**Template:** `templates/pdf/audit_trail.html.twig`

**Data Required:**
- `activities` (array of Activity entities)
- `dateRange` (start, end)

**Key Sections:**
- Timeline (chronological activity list)
- Activity types (call, email, quote sent, etc.)
- User attribution (who performed each action)

### 9. Onboarding Pack

**Purpose:** Supplier portal application package

**Template:** `templates/pdf/onboarding_pack.html.twig`

**Data Required:**
- `company` (supplier Company entity)
- `portal` (SupplierPortal entity)
- `formData` (pre-filled form fields)

**Key Sections:**
- Company information (legal name, tax ID, address)
- Contact details
- Capabilities (certifications, equipment)
- Pre-filled form data for operator review

---

## PDF Generation Process

### Service Method

**Method:** `UnifiedPdfGeneratorService::generatePdf(string $type, array $data): ComplianceDocument`

**Implementation:**
```php
use Mpdf\Mpdf;

public function generatePdf(string $type, array $data): ComplianceDocument
{
    // Validate type
    $validTypes = [
        'quote', 'estimate', 'fta_pack', 'dfm_report', 'cost_breakdown',
        'exceptions_report', 'sourcing_risk', 'audit_trail', 'onboarding_pack'
    ];
    
    if (!in_array($type, $validTypes)) {
        throw new \InvalidArgumentException("Invalid PDF type: {$type}");
    }
    
    // Render HTML from Twig template
    $html = $this->twig->render("pdf/{$type}.html.twig", $data);
    
    // Configure mPDF
    $mpdf = new Mpdf([
        'format' => 'A4',
        'margin_left' => 15,
        'margin_right' => 15,
        'margin_top' => 20,
        'margin_bottom' => 20,
        'margin_header' => 10,
        'margin_footer' => 10,
        'default_font' => 'dejavusans'
    ]);
    
    // Write HTML to PDF
    $mpdf->WriteHTML($html);
    
    // Generate PDF binary
    $pdfContent = $mpdf->Output('', 'S'); // 'S' = return as string
    
    // Calculate SHA-256 hash
    $sha256Hash = hash('sha256', $pdfContent);
    
    // Save to file system
    $uploadDir = $this->parameterBag->get('kernel.project_dir') . '/public/uploads/documents/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $filename = sprintf('%s_%s_%s.pdf', $type, date('Ymd_His'), substr($sha256Hash, 0, 8));
    $filePath = $uploadDir . $filename;
    file_put_contents($filePath, $pdfContent);
    
    // Create ComplianceDocument
    $document = new ComplianceDocument();
    $document->setDocumentType(strtoupper($type));
    $document->setFilename($filename);
    $document->setFilePath('/uploads/documents/' . $filename);
    $document->setSha256Hash($sha256Hash);
    $document->setGeneratedAt(new \DateTime());
    $document->setGeneratedBy($this->security->getUser());
    
    // Link to related entities (if available)
    if (isset($data['quote'])) {
        $document->setQuote($data['quote']);
    }
    if (isset($data['estimate'])) {
        $document->setEstimate($data['estimate']);
    }
    if (isset($data['company'])) {
        $document->setCompany($data['company']);
    }
    
    $this->entityManager->persist($document);
    $this->entityManager->flush();
    
    return $document;
}
```

---

## SHA-256 Integrity Hashing

### Purpose
- Verify PDF hasn't been tampered with
- Detect corruption during file transfer
- Compliance requirement for audit trails

### Verification

**Method:** `UnifiedPdfGeneratorService::verifyDocument(ComplianceDocument $document): bool`

**Implementation:**
```php
public function verifyDocument(ComplianceDocument $document): bool
{
    $filePath = $this->parameterBag->get('kernel.project_dir') . '/public' . $document->getFilePath();
    
    if (!file_exists($filePath)) {
        $this->logger->error('Document file not found', ['path' => $filePath]);
        return false;
    }
    
    $fileContent = file_get_contents($filePath);
    $actualHash = hash('sha256', $fileContent);
    $expectedHash = $document->getSha256Hash();
    
    if ($actualHash !== $expectedHash) {
        $this->logger->error('Document hash mismatch', [
            'document' => $document->getId(),
            'expected' => $expectedHash,
            'actual' => $actualHash
        ]);
        return false;
    }
    
    return true;
}
```

---

## Async Generation with Symfony Messenger

### Problem
Large PDFs (100+ page DFM reports) can take 10+ seconds to generate, causing request timeouts.

### Solution: Background Job

**Message:**
```php
namespace App\Message;

class GeneratePdfMessage
{
    public function __construct(
        private string $type,
        private array $data,
        private ?int $userId = null
    ) {}
    
    public function getType(): string { return $this->type; }
    public function getData(): array { return $this->data; }
    public function getUserId(): ?int { return $this->userId; }
}
```

**Handler:**
```php
namespace App\MessageHandler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class GeneratePdfMessageHandler
{
    public function __construct(
        private UnifiedPdfGeneratorService $pdfGenerator
    ) {}
    
    public function __invoke(GeneratePdfMessage $message): void
    {
        $this->pdfGenerator->generatePdf($message->getType(), $message->getData());
    }
}
```

**Dispatching:**
```php
// In controller
$this->messageBus->dispatch(new GeneratePdfMessage('dfm_report', [
    'bomLines' => $bomLines,
    'dfmFindings' => $findings
]));

$this->addFlash('success', 'PDF generation started. You will be notified when complete.');
```

---

## Email Delivery

### Attach PDF to Email

**Example: Send Quote PDF to Customer**
```php
use Symfony\Component\Mime\Email;

public function sendQuotePdf(Quote $quote): void
{
    // Generate PDF
    $document = $this->pdfGenerator->generatePdf('quote', [
        'quote' => $quote,
        'bomLines' => $quote->getBomLines(),
        'company' => $quote->getCompany(),
        'totals' => $this->calculateTotals($quote)
    ]);
    
    // Send email
    $email = (new Email())
        ->from('sales@crm-starz.com')
        ->to($quote->getCompany()->getEmail())
        ->subject("Quote #{$quote->getQuoteNumber()} from CRM Starz")
        ->html($this->twig->render('email/quote_ready.html.twig', ['quote' => $quote]))
        ->attachFromPath(
            $this->parameterBag->get('kernel.project_dir') . '/public' . $document->getFilePath(),
            $document->getFilename(),
            'application/pdf'
        );
    
    $this->mailer->send($email);
}
```

---

## Testing

### Unit Tests

**Test: PDF Generation**
```php
public function testGenerateQuotePdf(): void
{
    $quote = new Quote();
    $quote->setQuoteNumber('Q-2025-001');
    $quote->setCreatedAt(new \DateTime());
    
    $service = new UnifiedPdfGeneratorService(...);
    $document = $service->generatePdf('quote', [
        'quote' => $quote,
        'bomLines' => [],
        'company' => new Company(),
        'totals' => ['subtotal' => 1000, 'tax' => 100, 'total' => 1100]
    ]);
    
    $this->assertInstanceOf(ComplianceDocument::class, $document);
    $this->assertFileExists($this->projectDir . '/public' . $document->getFilePath());
}
```

**Test: SHA-256 Verification**
```php
public function testVerifyDocument(): void
{
    $document = $this->generateSampleDocument();
    
    $service = new UnifiedPdfGeneratorService(...);
    $this->assertTrue($service->verifyDocument($document));
    
    // Tamper with file
    file_put_contents($this->projectDir . '/public' . $document->getFilePath(), 'corrupted');
    
    $this->assertFalse($service->verifyDocument($document));
}
```

---

## Performance Optimization

### Caching Rendered HTML

For frequently generated documents (e.g., standard quote templates), cache the rendered HTML.

```php
$cacheKey = "pdf_html_{$type}_" . md5(json_encode($data));

if ($cachedHtml = $this->cache->get($cacheKey)) {
    $html = $cachedHtml;
} else {
    $html = $this->twig->render("pdf/{$type}.html.twig", $data);
    $this->cache->set($cacheKey, $html, 3600); // 1 hour TTL
}
```

### Font Subsetting

mPDF includes full font files by default. Enable subsetting to reduce file size:

```php
$mpdf = new Mpdf([
    'fontdata' => [
        'dejavusans' => [
            'R' => 'DejaVuSans.ttf',
            'useOTL' => 0xFF,
            'useKashida' => 75,
        ]
    ],
    'default_font' => 'dejavusans'
]);
```

---

## Roadmap

### Phase 1 (Current)
- ✅ mPDF integration
- ✅ 9 document templates
- ✅ SHA-256 hashing
- ✅ ComplianceDocument storage

### Phase 2 (Q1 2026)
- ⏳ Async generation (Symfony Messenger)
- ⏳ Email delivery automation
- ⏳ PDF/A compliance (archival format)
- ⏳ Digital signatures (PKI)

### Phase 3 (Q2 2026)
- ⏳ Template customization UI (WYSIWYG)
- ⏳ Multi-language support (FR, AR)
- ⏳ Watermarking ("DRAFT", "CONFIDENTIAL")

---

**Document End**
