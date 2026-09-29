<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\OnboardingPack;
use App\Entity\PortalCandidate;
use App\Entity\SupplierPortal;
use App\Repository\OnboardingPackRepository;
use App\Repository\PortalCandidateRepository;
use App\Repository\SupplierPortalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OnboardingPackService
 * 
 * Supplier portal onboarding automation service.
 * 
 * Automates the process of registering CRM Starz Morocco on customer supplier portals:
 * 1. Generate onboarding pack PDF (company profile, certifications, bank details, tax IDs)
 * 2. Auto-fill web forms with company data from PortalCandidate
 * 3. Submit registration to portal (HTTP POST or file upload)
 * 4. Track submission status and follow-up
 * 
 * Company profile data (tax IDs, bank details, contacts) is sourced from the
 * Company entity and from environment variables (COMPANY_VAT, COMPANY_DUNS,
 * COMPANY_BANK_IBAN, ...). Sensitive data is NEVER fabricated: any field that
 * is not configured is left empty in the pack and the pack is flagged as
 * requiring manual completion.
 * 
 * Used by:
 * - SupplierPortalController for portal onboarding
 * - Sales team for customer onboarding
 * - UnifiedPdfGeneratorService for pack PDF generation
 */
class OnboardingPackService
{
    public const STATUS_MANUAL_COMPLETION_REQUIRED = 'MANUAL_COMPLETION_REQUIRED';
    public const STATUS_PDF_FAILED = 'PDF_FAILED';

    /**
     * Sensitive / financial fields that must never be fabricated.
     */
    private const SENSITIVE_FIELDS = [
        'vatNumber',
        'dunsNumber',
        'bankIban',
        'bankSwift',
        'bankName',
        'bankAccountName',
    ];

    private readonly HttpClientInterface $httpClient;
    private readonly \App\Security\SafeOutboundUrlGuard $urlGuard;
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OnboardingPackRepository $onboardingPackRepository,
        private PortalCandidateRepository $portalCandidateRepository,
        private SupplierPortalRepository $supplierPortalRepository,
        HttpClientInterface $httpClient,
        private UnifiedPdfGeneratorService $pdfGenerator,
        private \App\Service\VendorPortalApiService $vendorApiService,
    ) {
        // Credential-bearing portal automation: enforce the SSRF guard on
        // every outbound URL AND block private-network destinations at the
        // transport layer (initial request and each redirect hop).
        $this->httpClient = $httpClient instanceof \Symfony\Component\HttpClient\MockHttpClient
            ? $httpClient
            : new \Symfony\Component\HttpClient\NoPrivateNetworkHttpClient($httpClient);
        $this->urlGuard = new \App\Security\SafeOutboundUrlGuard();
        $this->vendorApiService = $vendorApiService;
    }

    /**
     * Company profile sourced from env vars only. Every value defaults to
     * NULL: unconfigured fields are left empty instead of being fabricated.
     *
     * @return array<string, string|int|null>
     */
    private function companyProfileConfig(): array
    {
        $env = static function (string $name): ?string {
            $value = getenv($name);
            return $value === false || $value === '' ? null : $value;
        };

        $year = $env('COMPANY_YEAR_ESTABLISHED');
        $employees = $env('COMPANY_EMPLOYEE_COUNT');

        return [
            'companyName' => $env('COMPANY_NAME'),
            'address' => $env('COMPANY_ADDRESS'),
            'city' => $env('COMPANY_CITY'),
            'country' => $env('COMPANY_COUNTRY'),
            'postalCode' => $env('COMPANY_POSTAL_CODE'),
            'yearEstablished' => $year !== null && is_numeric($year) ? (int) $year : null,
            'employeeCount' => $employees !== null && is_numeric($employees) ? (int) $employees : null,
            'website' => $env('COMPANY_WEBSITE'),
            'vatNumber' => $env('COMPANY_VAT'),
            'dunsNumber' => $env('COMPANY_DUNS'),
            'bankIban' => $env('COMPANY_BANK_IBAN'),
            'bankSwift' => $env('COMPANY_BANK_SWIFT'),
            'bankName' => $env('COMPANY_BANK_NAME'),
            'bankAccountName' => $env('COMPANY_BANK_ACCOUNT_NAME'),
            'contactSalesName' => $env('COMPANY_CONTACT_SALES_NAME'),
            'contactSalesEmail' => $env('COMPANY_CONTACT_SALES_EMAIL'),
            'contactName' => $env('COMPANY_CONTACT_NAME'),
            'contactSalesPhone' => $env('COMPANY_CONTACT_SALES_PHONE'),
            'contactFinanceName' => $env('COMPANY_CONTACT_FINANCE_NAME'),
            'contactFinanceEmail' => $env('COMPANY_CONTACT_FINANCE_EMAIL'),
            'contactFinancePhone' => $env('COMPANY_CONTACT_FINANCE_PHONE'),
            'capabilities' => $this->jsonListFromEnv('COMPANY_CAPABILITIES_JSON'),
            'certifications' => $this->jsonListFromEnv('COMPANY_CERTIFICATIONS_JSON'),
        ];
    }

    /**
     * Parse a JSON list of strings from an env var; NULL when unset/invalid.
     *
     * @return string[]|null
     */
    private function jsonListFromEnv(string $name): ?array
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return null;
        }

        return array_values(array_map('strval', $decoded));
    }

    /**
     * Generate onboarding pack
     * 
     * @param int $companyId - Target company ID (customer)
     * @param string $packType - Pack type (FULL, QUICK, CUSTOM)
     * @param array $customFields - Custom fields to include (if CUSTOM type)
     * 
     * @return array{
     *   packId: int,
     *   pdfPath: string,
     *   fieldsExtracted: array
     * }
     */
    public function generatePack(int $companyId, string $packType = 'FULL', array $customFields = []): array
    {
        // 1. Create OnboardingPack against the REAL entity model: a
        //    Company association + createdAt (PrePersist), never phantom
        //    setCompanyId/setPackType/setGeneratedAt methods.
        $company = $this->entityManager->find(Company::class, $companyId);
        if ($company === null) {
            throw new \RuntimeException("Company {$companyId} not found");
        }

        $pack = new OnboardingPack();
        $pack->setCompany($company);
        $pack->setStatus('GENERATED');
        // Pack type + custom selection live in packContents (document
        // references + configuration JSON); createdAt is set by PrePersist.
        $pack->setPackContents(json_encode(['pack_type' => $packType]));
        $this->entityManager->persist($pack);
        $this->entityManager->flush(); // Get pack ID

        // 2. Extract fields from PortalCandidate
        $fieldsExtracted = $this->autoFillFields($companyId, $packType, $customFields);
        $pack->setFieldsJson(json_encode($fieldsExtracted));

        // 3. Never submit packs with missing sensitive data: flag them so a
        //    human must complete the profile before any portal submission.
        if (in_array($packType, ['FULL', 'CUSTOM'], true) && $this->hasMissingSensitiveFields($fieldsExtracted)) {
            $pack->setStatus(self::STATUS_MANUAL_COMPLETION_REQUIRED);
        }

        // 4. Generate PDF. On failure the pack keeps pdfPath = NULL and a
        //    dedicated status — never a synthetic path to a nonexistent
        //    file (submission blocks on missing required artifacts).
        try {
            $pdfPath = $this->pdfGenerator->generateOnboardingPackPdf($pack);
            $pack->setPdfPath($pdfPath);
        } catch (\Throwable $e) {
            $pack->setPdfPath(null);
            $pack->setStatus(self::STATUS_PDF_FAILED);
            $pack->setNotes('PDF generation failed: ' . mb_substr($e->getMessage(), 0, 400));
        }

        // 5. Update pack
        $this->entityManager->flush();

        // 6. Return pack data
        return [
            'packId' => $pack->getId(),
            'pdfPath' => $pack->getPdfPath(),
            'fieldsExtracted' => $fieldsExtracted
        ];
    }

    /**
     * True when any of the sensitive financial fields is missing or empty.
     *
     * @param array<string, mixed> $fields
     */
    private function hasMissingSensitiveFields(array $fields): bool
    {
        foreach (self::SENSITIVE_FIELDS as $field) {
            $value = $fields[$field] ?? null;
            if ($value === null || $value === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Auto-fill form fields from the Company entity and env-configured profile
     * 
     * @param int $companyId - Company ID
     * @param string $packType - Pack type
     * @param array $customFields - Custom fields (if CUSTOM type)
     * 
     * @return array - Extracted fields
     */
    public function autoFillFields(int $companyId, string $packType, array $customFields = []): array
    {
        // 1. Load the Company entity: it is the authoritative source for
        //    profile data (name, address, contacts).
        $company = $this->entityManager->getRepository(Company::class)->find($companyId);

        // 2. Get (or create) the PortalCandidate record for this company
        $candidate = $this->portalCandidateRepository->findOneBy(['company' => $company]);
        if ($candidate === null) {
            $candidate = $this->createDefaultCandidate($company);
        }

        $config = $this->companyProfileConfig();

        // 3. Extract fields based on pack type
        $fields = [];

        if ($packType === 'FULL' || $packType === 'CUSTOM') {
            $fields['companyName'] = $company?->getName() ?? $config['companyName'] ?? '';
            $fields['address'] = $company?->getAddress() ?? $config['address'] ?? '';
            $fields['city'] = $company?->getCity() ?? $config['city'] ?? '';
            $fields['country'] = $company?->getCountry() ?? $config['country'] ?? '';
            $fields['postalCode'] = $config['postalCode'] ?? '';
            $fields['yearEstablished'] = $config['yearEstablished'];
            $fields['employeeCount'] = $config['employeeCount'];
            $fields['website'] = $company?->getWebsite() ?? $config['website'] ?? '';
            $fields['vatNumber'] = $config['vatNumber'] ?? '';
            $fields['dunsNumber'] = $config['dunsNumber'] ?? '';
            $fields['bankIban'] = $config['bankIban'] ?? '';
            $fields['bankSwift'] = $config['bankSwift'] ?? '';
            $fields['bankName'] = $config['bankName'] ?? '';
            $fields['bankAccountName'] = $config['bankAccountName'] ?? '';
            $fields['contactSalesName'] = $config['contactSalesName'] ?? '';
            $fields['contactSalesEmail'] = $config['contactSalesEmail'] ?? '';
            $fields['contactName'] = $config['contactName'] ?? '';
            $fields['contactSalesPhone'] = $config['contactSalesPhone'] ?? '';
            $fields['contactFinanceName'] = $config['contactFinanceName'] ?? '';
            $fields['contactFinanceEmail'] = $config['contactFinanceEmail'] ?? '';
            $fields['contactFinancePhone'] = $config['contactFinancePhone'] ?? '';
            $fields['capabilities'] = $config['capabilities'] ?? [];
            $fields['certifications'] = $config['certifications'] ?? [];
        }
        
        if ($packType === 'QUICK') {
            // Minimal fields for quick onboarding
            $fields['companyName'] = $company?->getName() ?? $config['companyName'] ?? '';
            $fields['address'] = $company?->getAddress() ?? $config['address'] ?? '';
            $fields['contactSalesEmail'] = $config['contactSalesEmail'] ?? '';
            $fields['contactSalesPhone'] = $config['contactSalesPhone'] ?? '';
        }
        
        if ($packType === 'CUSTOM' && !empty($customFields)) {
            // Filter to only requested custom fields
            $fields = array_intersect_key($fields, array_flip($customFields));
        }
        
        // 3. Return extracted fields
        return $fields;
    }

    /**
     * Submit onboarding pack to supplier portal
     * 
     * @param int $packId - OnboardingPack ID
     * @param int $portalId - SupplierPortal ID
     * @param array $credentials - Portal login credentials (username, password)
     * 
     * @return array{
     *   success: bool,
     *   method: string,
     *   response: string|null,
     *   errorMessage: string|null
     * }
     */
    public function submitToPortal(int $packId, int $portalId, array $credentials = []): array
    {
        // 1. Get pack and portal
        $pack = $this->onboardingPackRepository->find($packId);
        $portal = $this->supplierPortalRepository->find($portalId);
        
        if (!$pack || !$portal) {
            throw new \RuntimeException("Pack or portal not found");
        }

        // 2. A pack with missing sensitive data must never be auto-submitted:
        //    it would either send empty values or require fabricated ones.
        //    Likewise a pack whose REQUIRED PDF artifact failed to generate:
        //    pdfPath is NULL (never a synthetic path), and submitting without
        //    the document the portal demands is a silent data loss.
        if ($pack->getStatus() === self::STATUS_PDF_FAILED
            || ($pack->getPdfPath() === null && $pack->getStatus() !== 'READY_FOR_MANUAL_SUBMIT' && $pack->getStatus() !== 'SUBMITTED')) {
            return [
                'success' => false,
                'method' => 'MANUAL',
                'response' => null,
                'errorMessage' => 'Pack PDF artifact is not available (generation failed or not yet generated); submission blocked until the document exists',
            ];
        }

        if ($pack->getStatus() === self::STATUS_MANUAL_COMPLETION_REQUIRED) {
            return [
                'success' => false,
                'method' => 'MANUAL',
                'response' => null,
                'errorMessage' => 'Pack requires manual completion of company profile data before submission',
            ];
        }
        
        // 3. Determine submission method based on portal vendor
        $vendor = $portal->getPortalVendor();
        $method = 'MANUAL'; // Default
        
        if ($vendor === 'ARIBA') {
            $method = 'ARIBA_API';
        } elseif ($vendor === 'COUPA') {
            $method = 'COUPA_API';
        } elseif ($portal->getFormFieldsJson()) {
            $method = 'WEB_FORM'; // Generic web form submission
        }
        
        // 4. Submit based on method
        if ($method === 'WEB_FORM') {
            return $this->submitViaWebForm($pack, $portal, $credentials);
        } elseif ($method === 'ARIBA_API' || $method === 'COUPA_API') {
            // Real vendor integrations (see VendorPortalApiService): OAuth2
            // supplier registration for Ariba SLP, keyed REST for Coupa.
            $result = $method === 'ARIBA_API'
                ? $this->vendorApiService->submitToAriba($pack, $portal)
                : $this->vendorApiService->submitToCoupa($pack, $portal);

            if ($result['success']) {
                $pack->setStatus('SUBMITTED');
                $this->recordSubmittedPortal($pack, $portalId);
                $pack->setSubmittedAt(new \DateTime());
                $pack->setSubmittedBy($result['external_id'] ?? null);
                $this->entityManager->flush();
            } else {
                // Missing vendor credentials is a CONFIG issue (retryable);
                // a transport/vendor error keeps the pack pending without
                // losing work.
                $pack->setStatus(str_starts_with((string) $result['errorMessage'], 'ARIBA API credentials') || str_starts_with((string) $result['errorMessage'], 'COUPA API credentials') ? 'PENDING_API_INTEGRATION' : 'SUBMISSION_FAILED');
                $this->recordSubmittedPortal($pack, $portalId);
                $pack->setNotes((string) $result['errorMessage']);
                $this->entityManager->flush();
            }

            return [
                'success' => $result['success'],
                'method' => $method,
                'response' => $result['response'],
                'errorMessage' => $result['errorMessage'],
            ];
        } else {
            // Manual submission - just mark as ready
            $pack->setStatus('READY_FOR_MANUAL_SUBMIT');
            $this->recordSubmittedPortal($pack, $portalId);
            $this->entityManager->flush();
            
            return [
                'success' => true,
                'method' => 'MANUAL',
                'response' => 'Pack ready for manual submission',
                'errorMessage' => null
            ];
        }
    }

    /**
     * Submit pack via generic web form
     * 
     * @param OnboardingPack $pack - Onboarding pack
     * @param SupplierPortal $portal - Portal
     * @param array $credentials - Login credentials
     * 
     * @return array - Submission result
     */
    private function submitViaWebForm(OnboardingPack $pack, SupplierPortal $portal, array $credentials): array
    {
        try {
            // 1. Login to portal
            $loginUrl = $portal->getLoginUrl();
            
            if (!$loginUrl || empty($credentials['username']) || empty($credentials['password'])) {
                throw new \RuntimeException('Login credentials or URL missing');
            }
            
            // These requests carry credentials/session data: HTTPS-only,
            // port 443, and validated BEFORE anything is sent.
            $submitUrlCheck = $portal->getSubmitUrl();
            if (!$submitUrlCheck) {
                throw new \RuntimeException('Submit URL not configured');
            }

            $this->urlGuard->assertAllowedCredentialEndpoint($loginUrl);
            $loginResponse = $this->httpClient->request('POST', $loginUrl, [
                'body' => [
                    'username' => $credentials['username'],
                    'password' => $credentials['password']
                ],
                'timeout' => 30
            ]);
            
            // Extract session cookie
            // Scoped cookie handling: parse EVERY Set-Cookie (not just the
            // first), honor each cookie's own Domain attribute, and only
            // relay cookies whose scope actually covers the submit host —
            // same-organization alone is too loose (a subdomain of the
            // org should not receive the portal session cookie).
            $sessionCookie = $this->scopedCookieHeader(
                $loginResponse->getHeaders()['set-cookie'] ?? [],
                (string) parse_url($submitUrlCheck, PHP_URL_HOST),
                (string) parse_url($loginUrl, PHP_URL_HOST)
            );
            
            // 2. Get form field mappings
            $formFields = json_decode($portal->getFormFieldsJson() ?? '{}', true);
            $packData = json_decode($pack->getFieldsJson() ?? '{}', true);
            
            // 3. Map pack data to form fields; null/empty pack values are
            //    skipped entirely rather than submitted as empty strings.
            $formData = [];
            foreach ($formFields as $fieldName => $packField) {
                $value = $packData[$packField] ?? null;
                if ($value !== null && $value !== '') {
                    $formData[$fieldName] = $value;
                }
            }
            
            // 4. Upload PDF if required
            if ($portal->getRequiresFileUpload() && $pack->getPdfPath() && file_exists($pack->getPdfPath())) {
                $formData['file'] = fopen($pack->getPdfPath(), 'r');
            }
            
            // 5. Submit form
            $submitUrl = $portal->getSubmitUrl();
            
            if (!$submitUrl) {
                throw new \RuntimeException('Submit URL not configured');
            }
            
            $this->urlGuard->assertAllowedCredentialEndpoint($submitUrl);

            // The session cookie obtained from the login host must never be
            // forwarded to a different origin: login and submit endpoints
            // must belong to the same organization.
            if (!$this->urlGuard->isAllowedChildUrl($loginUrl, $submitUrl)) {
                throw new \RuntimeException('Portal submit URL does not share the login URL origin; refusing to forward the session.');
            }

            $submitResponse = $this->httpClient->request('POST', $submitUrl, [
                'headers' => ['Cookie' => $sessionCookie],
                'body' => $formData,
                'timeout' => 60
            ]);
            
            // 6. Check response
            $statusCode = $submitResponse->getStatusCode();
            $success = $statusCode >= 200 && $statusCode < 300;
            
            if ($success) {
                $pack->setStatus('SUBMITTED');
                $pack->setSubmittedAt(new \DateTime());
                $this->recordSubmittedPortal($pack, (int) $portal->getId());
            } else {
                $pack->setStatus('SUBMISSION_FAILED');
            }
            $this->entityManager->flush();
            
            // 7. Return result
            return [
                'success' => $success,
                'method' => 'WEB_FORM',
                'response' => $submitResponse->getContent(false),
                'errorMessage' => $success ? null : "HTTP $statusCode error"
            ];
        } catch (\Exception $e) {
            $pack->setStatus('SUBMISSION_FAILED');
            $this->entityManager->flush();
            
            return [
                'success' => false,
                'method' => 'WEB_FORM',
                'response' => null,
                'errorMessage' => $e->getMessage()
            ];
        }
    }

    /**
     * Create default PortalCandidate record for a company. No fabricated
     * profile data is stored: the Company entity is the source of truth.
     */
    private function createDefaultCandidate(?Company $company): PortalCandidate
    {
        $candidate = new PortalCandidate();
        $candidate->setCompany($company);
        $candidate->setStatus('discovered');
        $candidate->setDiscoveredAt(new \DateTime());
        $candidate->setRequiresManualSubmit(true); // Never auto-submit without review
        $candidate->setHasRobotsTxt(true);
        
        $this->entityManager->persist($candidate);
        $this->entityManager->flush();
        
        return $candidate;
    }

    /**
     * Get onboarding pack status
     * 
     * @param int $packId - Pack ID
     * 
     * @return array{
     *   status: string,
     *   generatedAt: \DateTime|null,
     *   submittedAt: \DateTime|null,
     *   portalName: string|null
     * }
     */
    public function getPackStatus(int $packId): array
    {
        // 1. Get pack
        $pack = $this->onboardingPackRepository->find($packId);
        if (!$pack) {
            throw new \RuntimeException("Pack $packId not found");
        }
        
        // 2. Get portal if submitted (recorded in packContents JSON — the
        // entity has no portalId column)
        $portalName = null;
        $contents = json_decode($pack->getPackContents() ?? '{}', true) ?? [];
        $submittedPortalId = $contents['submitted_portal_id'] ?? null;
        if ($submittedPortalId !== null) {
            $portal = $this->supplierPortalRepository->find((int) $submittedPortalId);
            $portalName = $portal?->getPortalUrl();
        }
        
        // 3. Return status
        return [
            'status' => $pack->getStatus(),
            'generatedAt' => $pack->getCreatedAt(),
            'submittedAt' => $pack->getSubmittedAt(),
            'portalName' => $portalName
        ];
    }
    /**
     * Build a Cookie header from Set-Cookie responses, honoring each
     * cookie's own Domain scope against the submit host. Cookies whose
     * scope does not cover the submit host are NOT relayed (cookie-jar
     * semantics without a jar dependency).
     */
    private function scopedCookieHeader(array $setCookieHeaders, string $submitHost, string $loginHost): string
    {
        $pairs = [];
        foreach ($setCookieHeaders as $header) {
            $parts = explode(';', (string) $header);
            $nameValue = trim((string) ($parts[0] ?? ''));
            if ($nameValue === '' || !str_contains($nameValue, '=')) {
                continue;
            }

            $domainAttr = null;
            foreach (array_slice($parts, 1) as $attr) {
                if (preg_match('/^\s*domain\s*=\s*(.+)$/i', $attr, $m)) {
                    $domainAttr = strtolower(trim($m[1]));
                    break;
                }
            }

            // HOST-ONLY cookies (no Domain attribute — the common secure
            // session case) are valid for exactly the host that set them:
            // relayed when the submit host IS the login host. Domain-scoped
            // cookies follow their declared scope (domain + subdomains).
            $covers = $domainAttr === null
                ? ($submitHost !== '' && $submitHost === $loginHost)
                : ($submitHost === $domainAttr || str_ends_with('.' . $submitHost, '.' . $domainAttr));

            if ($covers && !preg_match('/\bexpires=Thu, 01 Jan 1970/i', (string) $header)) {
                $pairs[] = $nameValue;
            }
        }

        return implode('; ', $pairs);
    }

    /**
     * Record which portal a pack was submitted to, in the packContents JSON
     * (the entity models a PortalCandidate relation, not a portal-id column).
     */
    private function recordSubmittedPortal(OnboardingPack $pack, int $portalId): void
    {
        $contents = json_decode($pack->getPackContents() ?? '{}', true) ?? [];
        $contents['submitted_portal_id'] = $portalId;
        $pack->setPackContents(json_encode($contents));
    }

}
