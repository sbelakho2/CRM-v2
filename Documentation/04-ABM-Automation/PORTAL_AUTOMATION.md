# Supplier Portal Automation System

**Version:** 1.0  
**Last Updated:** October 29, 2025  
**Services:** `PortalCrawlerService`, `OnboardingPackService`

---

## Overview

The Supplier Portal Automation System discovers, catalogs, and automates supplier onboarding across manufacturer portals. It reduces manual data entry from hours to minutes by pre-filling forms and auto-submitting credentials.

### Business Problem
- **Manual Onboarding:** Each supplier portal requires 30-60 minutes of form filling
- **Data Duplication:** Same company data entered 50+ times across different portals
- **Human Error:** Typos in company name, address, tax ID lead to rejection
- **Scale Challenge:** Electronics sourcing requires accounts at 100+ portals

### Solution
1. **Portal Discovery:** Crawl manufacturer websites to find supplier portals
2. **Portal Cataloging:** Map form fields, submission endpoints, authentication methods
3. **Auto-Fill:** Pre-populate forms with company data from CRM
4. **Auto-Submit:** Submit onboarding applications programmatically
5. **Status Tracking:** Monitor approval status, store credentials securely

---

## Architecture

### Components

1. **SupplierPortal:** Portal metadata (URL, form structure, submission endpoint)
2. **PortalCandidate:** Discovered portal URL pending review
3. **OnboardingPack:** Generated application package for a specific company
4. **CompanyCanonical:** Master company data (legal name, address, tax ID)

### Data Flow

```
Web Crawler (Symfony Panther + Goutte)
    ↓
Discover Portal URLs (robots.txt, sitemap.xml)
    ↓
Create PortalCandidate
    ↓
Manual Review by Operator
    ↓
Approve → Create SupplierPortal
    ↓
Map Form Fields
    ↓
Generate OnboardingPack
    ↓
Auto-Submit Application
    ↓
Track Approval Status
```

---

## Portal Discovery

### Crawler Strategy

**Libraries:**
- **Symfony Panther:** JavaScript-capable headless browser (Chrome/Firefox)
- **Goutte:** Lightweight scraper for simple HTML sites
- **DomCrawler:** Parse HTML, extract links

**Installation:**
```bash
composer require symfony/panther
composer require fabpot/goutte
```

### Discovery Methods

#### 1. Robots.txt Analysis

**Purpose:** Find sitemap URLs and restricted areas

**Implementation:**
```php
public function analyzeRobotsTxt(string $domain): array
{
    $robotsUrl = "https://{$domain}/robots.txt";
    
    try {
        $content = file_get_contents($robotsUrl);
    } catch (\Exception $e) {
        return []; // No robots.txt
    }
    
    $sitemaps = [];
    $disallowed = [];
    
    foreach (explode("\n", $content) as $line) {
        if (preg_match('/Sitemap:\s*(.+)/i', $line, $matches)) {
            $sitemaps[] = trim($matches[1]);
        }
        if (preg_match('/Disallow:\s*(.+)/i', $line, $matches)) {
            $disallowed[] = trim($matches[1]);
        }
    }
    
    return [
        'sitemaps' => $sitemaps,
        'disallowed' => $disallowed
    ];
}
```

**Example Output:**
```php
[
    'sitemaps' => [
        'https://www.ti.com/sitemap.xml',
        'https://www.ti.com/sitemap-suppliers.xml'
    ],
    'disallowed' => [
        '/admin/',
        '/private/'
    ]
]
```

#### 2. Sitemap Crawling

**Purpose:** Extract all URLs from sitemap, filter for portal-related paths

**Implementation:**
```php
public function crawlSitemap(string $sitemapUrl): array
{
    $xml = simplexml_load_file($sitemapUrl);
    $urls = [];
    
    foreach ($xml->url as $url) {
        $loc = (string) $url->loc;
        
        // Filter for portal-related keywords
        if (preg_match('/(supplier|vendor|partner|portal|onboard|register)/i', $loc)) {
            $urls[] = $loc;
        }
    }
    
    return $urls;
}
```

**Example Output:**
```php
[
    'https://www.ti.com/suppliers/supplier-portal.html',
    'https://www.ti.com/partners/partner-registration.html',
    'https://www.ti.com/onboarding/vendor-application.html'
]
```

#### 3. Homepage Link Extraction

**Purpose:** Find portal links in footer or navigation

**Implementation:**
```php
use Symfony\Component\Panther\Client;

public function extractPortalLinks(string $url): array
{
    $client = Client::createChromeClient();
    $crawler = $client->request('GET', $url);
    
    $portalLinks = [];
    
    // Find links containing portal keywords
    $crawler->filter('a')->each(function ($node) use (&$portalLinks) {
        $href = $node->attr('href');
        $text = $node->text();
        
        if (preg_match('/(supplier|vendor|partner|portal)/i', $text)) {
            $portalLinks[] = [
                'url' => $href,
                'text' => $text
            ];
        }
    });
    
    $client->quit();
    
    return $portalLinks;
}
```

**Example Output:**
```php
[
    ['url' => '/suppliers/portal', 'text' => 'Supplier Portal'],
    ['url' => '/partners/register', 'text' => 'Partner Registration']
]
```

---

## Portal Candidate Review

### PortalCandidate Entity

**Fields:**
- `url` (e.g., "https://www.ti.com/suppliers/portal")
- `manufacturer` (e.g., "Texas Instruments")
- `discoveredAt` (timestamp)
- `status` (PENDING, APPROVED, REJECTED)
- `reviewNotes` (operator comments)

### Manual Review UI

**Route:** `/supplier-portal/candidates`

**Features:**
- List all discovered portal URLs
- Show manufacturer name, URL, discovery date
- Preview portal in iframe
- Buttons: Approve, Reject, Visit Site

**Approval Flow:**
```php
#[Route('/supplier-portal/candidate/{id}/approve', methods: ['POST'])]
public function approveCandidate(int $id): Response
{
    $candidate = $this->portalCandidateRepo->find($id);
    $candidate->setStatus('APPROVED');
    
    // Create SupplierPortal entity
    $portal = new SupplierPortal();
    $portal->setManufacturer($candidate->getManufacturer());
    $portal->setUrl($candidate->getUrl());
    $portal->setStatus('DISCOVERED');
    $portal->setFormFieldsJson([]); // To be mapped later
    
    $this->entityManager->persist($portal);
    $this->entityManager->flush();
    
    $this->addFlash('success', 'Portal approved and added to catalog');
    
    return $this->redirectToRoute('supplier_portal_index');
}
```

---

## Form Field Mapping

### Purpose
Map portal form fields to CRM company data for auto-fill.

### SupplierPortal.formFieldsJson Structure

**Example:**
```json
{
    "fields": [
        {
            "label": "Company Legal Name",
            "selector": "#company_name",
            "type": "text",
            "mapping": "company.legalName",
            "required": true
        },
        {
            "label": "Tax ID / VAT Number",
            "selector": "#tax_id",
            "type": "text",
            "mapping": "company.taxId",
            "required": true
        },
        {
            "label": "Street Address",
            "selector": "#address_line1",
            "type": "text",
            "mapping": "company.address.street",
            "required": true
        },
        {
            "label": "City",
            "selector": "#address_city",
            "type": "text",
            "mapping": "company.address.city",
            "required": true
        },
        {
            "label": "Country",
            "selector": "#address_country",
            "type": "select",
            "mapping": "company.address.country",
            "required": true
        },
        {
            "label": "Contact Name",
            "selector": "#contact_name",
            "type": "text",
            "mapping": "contact.fullName",
            "required": true
        },
        {
            "label": "Contact Email",
            "selector": "#contact_email",
            "type": "email",
            "mapping": "contact.email",
            "required": true
        },
        {
            "label": "Contact Phone",
            "selector": "#contact_phone",
            "type": "tel",
            "mapping": "contact.phone",
            "required": false
        }
    ],
    "submitButton": "#btn_submit",
    "successIndicator": ".success-message",
    "requiresCaptcha": false,
    "authMethod": "none"
}
```

### Mapping UI

**Route:** `/supplier-portal/{id}/map-fields`

**Features:**
- Load portal URL in iframe
- Right panel: Form field inspector
- For each detected input field:
  - Show label, name, type
  - Dropdown to select CRM field mapping
  - Checkbox for "required"
- Save mapping to `formFieldsJson`

**Auto-Detection:**
```php
public function detectFormFields(string $portalUrl): array
{
    $client = Client::createChromeClient();
    $crawler = $client->request('GET', $portalUrl);
    
    $fields = [];
    
    $crawler->filter('input, select, textarea')->each(function ($node) use (&$fields) {
        $fields[] = [
            'selector' => '#' . $node->attr('id'),
            'label' => $this->findLabel($node),
            'type' => $node->attr('type') ?? 'text',
            'name' => $node->attr('name'),
            'required' => $node->attr('required') !== null
        ];
    });
    
    $client->quit();
    
    return $fields;
}

private function findLabel($inputNode): string
{
    // Find associated <label> tag
    $id = $inputNode->attr('id');
    $label = $inputNode->closest('form')->filter("label[for='{$id}']")->first();
    
    if ($label->count() > 0) {
        return $label->text();
    }
    
    // Fallback: use placeholder or name attribute
    return $inputNode->attr('placeholder') ?? $inputNode->attr('name') ?? 'Unknown';
}
```

---

## Onboarding Pack Generation

### OnboardingPack Entity

**Fields:**
- `company` (ManyToOne → Company)
- `portal` (ManyToOne → SupplierPortal)
- `dataJson` (pre-filled form data)
- `status` (DRAFT, SUBMITTED, APPROVED, REJECTED)
- `submittedAt` (timestamp)
- `credentialsJson` (username, password - encrypted)

### Generation Process

**Method:** `OnboardingPackService::generatePack(Company $company, SupplierPortal $portal): OnboardingPack`

**Implementation:**
```php
public function generatePack(Company $company, SupplierPortal $portal): OnboardingPack
{
    $formFields = $portal->getFormFieldsJson()['fields'] ?? [];
    $data = [];
    
    foreach ($formFields as $field) {
        $mapping = $field['mapping'];
        $data[$field['selector']] = $this->extractDataFromCompany($company, $mapping);
    }
    
    $pack = new OnboardingPack();
    $pack->setCompany($company);
    $pack->setPortal($portal);
    $pack->setDataJson($data);
    $pack->setStatus('DRAFT');
    $pack->setGeneratedAt(new \DateTime());
    
    $this->entityManager->persist($pack);
    $this->entityManager->flush();
    
    return $pack;
}

private function extractDataFromCompany(Company $company, string $mapping): ?string
{
    // Parse mapping path (e.g., "company.address.city")
    $parts = explode('.', $mapping);
    
    return match($mapping) {
        'company.legalName' => $company->getName(), // Assuming legal name = name
        'company.taxId' => $company->getTaxId(),
        'company.address.street' => $company->getAddress(),
        'company.address.city' => $company->getCity(),
        'company.address.country' => $company->getCountry(),
        'contact.fullName' => $this->getMainContact($company)?->getFullName(),
        'contact.email' => $this->getMainContact($company)?->getEmail(),
        'contact.phone' => $this->getMainContact($company)?->getPhone(),
        default => null
    };
}

private function getMainContact(Company $company): ?Contact
{
    // Return first contact or contact with isPrimary flag
    return $company->getContacts()->first() ?: null;
}
```

---

## Auto-Submit Process

### Submission Strategy

**Approach:** Use Symfony Panther to fill form and submit

**Implementation:**
```php
public function autoSubmitPack(OnboardingPack $pack): bool
{
    $portal = $pack->getPortal();
    $data = $pack->getDataJson();
    $formConfig = $portal->getFormFieldsJson();
    
    $client = Client::createChromeClient();
    $crawler = $client->request('GET', $portal->getUrl());
    
    // Fill each field
    foreach ($formConfig['fields'] as $field) {
        $selector = $field['selector'];
        $value = $data[$selector] ?? '';
        
        if (empty($value) && $field['required']) {
            $this->logger->error('Missing required field', [
                'portal' => $portal->getManufacturer(),
                'field' => $field['label']
            ]);
            return false;
        }
        
        try {
            if ($field['type'] === 'select') {
                $crawler->filter($selector)->selectOption($value);
            } else {
                $crawler->filter($selector)->sendKeys($value);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to fill field', [
                'selector' => $selector,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    // Handle CAPTCHA
    if ($formConfig['requiresCaptcha']) {
        $this->logger->warning('CAPTCHA required, manual submission needed', [
            'portal' => $portal->getManufacturer()
        ]);
        $pack->setStatus('PENDING_CAPTCHA');
        $this->entityManager->flush();
        return false;
    }
    
    // Submit form
    $submitButton = $formConfig['submitButton'];
    $crawler->filter($submitButton)->click();
    
    // Wait for response
    $client->waitFor($formConfig['successIndicator'] ?? '.success', 10);
    
    // Check for success indicator
    if ($crawler->filter($formConfig['successIndicator'])->count() > 0) {
        $pack->setStatus('SUBMITTED');
        $pack->setSubmittedAt(new \DateTime());
        $this->entityManager->flush();
        
        $this->logger->info('Portal application submitted successfully', [
            'portal' => $portal->getManufacturer(),
            'company' => $pack->getCompany()->getName()
        ]);
        
        return true;
    } else {
        $pack->setStatus('SUBMISSION_FAILED');
        $this->entityManager->flush();
        
        return false;
    }
    
    $client->quit();
}
```

---

## Credentials Storage

### Security Requirements
- **Encryption:** AES-256 encryption for passwords
- **Key Management:** Store encryption key in `.env`, not in database
- **Access Control:** Only ROLE_ADMIN can view credentials

### Implementation

**Encryption:**
```php
public function encryptCredentials(array $credentials): string
{
    $key = $_ENV['PORTAL_CREDENTIALS_KEY']; // 32-byte key
    $iv = openssl_random_pseudo_bytes(16);
    
    $encrypted = openssl_encrypt(
        json_encode($credentials),
        'aes-256-cbc',
        $key,
        0,
        $iv
    );
    
    // Store IV with encrypted data (IV is not secret)
    return base64_encode($iv . $encrypted);
}

public function decryptCredentials(string $encryptedData): array
{
    $key = $_ENV['PORTAL_CREDENTIALS_KEY'];
    $data = base64_decode($encryptedData);
    
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    
    $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
    
    return json_decode($decrypted, true);
}
```

**Usage:**
```php
// After successful submission, store credentials
$credentials = [
    'username' => $data['#username'],
    'password' => $generatedPassword, // Auto-generated or user-provided
    'accountUrl' => $portal->getUrl() . '/my-account'
];

$encrypted = $this->encryptCredentials($credentials);
$pack->setCredentialsJson($encrypted);
$this->entityManager->flush();
```

---

## Terms of Service Compliance

### Legal Considerations
- Many portals prohibit automated submissions in TOS
- **Mitigation:** Review TOS manually before enabling auto-submit
- **Best Practice:** Use automation for pre-fill only, require human click for submit

### TOS Review Workflow

**1. Flag for Review:**
```php
$portal->setTosReviewed(false);
$portal->setAutoSubmitEnabled(false);
```

**2. Manual Review by Legal/Operator:**
- Read portal TOS
- Check for "no bots" or "no automated access" clauses
- Document findings in `reviewNotes`

**3. Enable Auto-Submit (if allowed):**
```php
$portal->setTosReviewed(true);
$portal->setTosAllowsAutomation(true);
$portal->setAutoSubmitEnabled(true);
$portal->setReviewNotes('TOS allows automated submissions for business purposes');
```

**4. Semi-Automated (if TOS prohibits):**
```php
$portal->setTosAllowsAutomation(false);
$portal->setAutoSubmitEnabled(false);
$portal->setReviewNotes('TOS prohibits automation. Use pre-fill + manual submit');
```

---

## Domain Canonicalization

### Problem
Manufacturers may have multiple domains:
- www.ti.com
- ti.com
- suppliers.ti.com
- texasinstruments.com (redirects to ti.com)

### Solution: CompanyCanonical Entity

**Fields:**
- `canonicalDomain` (e.g., "ti.com")
- `aliases` (JSON array: ["www.ti.com", "texasinstruments.com"])
- `companyName` (e.g., "Texas Instruments")

**Deduplication:**
```php
public function getCanonicalDomain(string $url): string
{
    $parsedUrl = parse_url($url);
    $host = $parsedUrl['host'] ?? $url;
    
    // Check if alias exists
    $canonical = $this->companyCanonicalRepo->createQueryBuilder('c')
        ->where('JSON_CONTAINS(c.aliases, :host)')
        ->setParameter('host', json_encode($host))
        ->getQuery()
        ->getOneOrNullResult();
    
    if ($canonical) {
        return $canonical->getCanonicalDomain();
    }
    
    // Default: remove www. prefix
    return preg_replace('/^www\./', '', $host);
}
```

---

## Monitoring & Alerts

### Submission Success Rate

**Metric:** Percentage of packs successfully submitted

**Query:**
```sql
SELECT
    p.manufacturer,
    COUNT(*) AS total_submissions,
    SUM(CASE WHEN op.status = 'SUBMITTED' THEN 1 ELSE 0 END) AS successful,
    (SUM(CASE WHEN op.status = 'SUBMITTED' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)) AS success_rate
FROM onboarding_pack op
JOIN supplier_portal p ON op.portal_id = p.id
WHERE op.submitted_at >= DATE('now', '-30 days')
GROUP BY p.manufacturer
ORDER BY success_rate DESC;
```

### Alerts

**Email Notification:**
```php
if ($successRate < 80) {
    $this->mailer->send(
        (new Email())
            ->to('admin@crm-starz.com')
            ->subject("Portal submission success rate dropped: {$portal->getManufacturer()}")
            ->text("Success rate: {$successRate}%. Please review portal for changes.")
    );
}
```

---

## Testing

### Unit Tests

**Test: Form Field Extraction**
```php
public function testExtractDataFromCompany(): void
{
    $company = new Company();
    $company->setName('Acme Electronics');
    $company->setTaxId('123456789');
    $company->setAddress('123 Main St');
    $company->setCity('Casablanca');
    $company->setCountry('MA');
    
    $service = new OnboardingPackService(...);
    
    $this->assertEquals('Acme Electronics', $service->extractDataFromCompany($company, 'company.legalName'));
    $this->assertEquals('123456789', $service->extractDataFromCompany($company, 'company.taxId'));
    $this->assertEquals('Casablanca', $service->extractDataFromCompany($company, 'company.address.city'));
}
```

**Test: Credentials Encryption**
```php
public function testEncryptDecryptCredentials(): void
{
    $service = new OnboardingPackService(...);
    
    $credentials = [
        'username' => 'acme_electronics',
        'password' => 'SecureP@ssw0rd123'
    ];
    
    $encrypted = $service->encryptCredentials($credentials);
    $decrypted = $service->decryptCredentials($encrypted);
    
    $this->assertEquals($credentials, $decrypted);
}
```

---

## Roadmap

### Phase 1 (Current)
- ✅ Portal discovery (robots.txt, sitemap, homepage)
- ✅ Manual review workflow
- ✅ Form field mapping
- ✅ Onboarding pack generation
- ✅ Auto-submit (basic)

### Phase 2 (Q1 2026)
- ⏳ CAPTCHA solving (2Captcha API integration)
- ⏳ Multi-page forms support
- ⏳ File upload handling (certificates, W-9 forms)
- ⏳ Approval status polling (check portal dashboard)

### Phase 3 (Q2 2026)
- ⏳ AI-powered field mapping (GPT-4 Vision to analyze forms)
- ⏳ Portal change detection (alert if form structure changes)
- ⏳ Bulk onboarding (submit to 20+ portals at once)

---

**Document End**
