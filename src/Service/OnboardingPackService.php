<?php

namespace App\Service;

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
 * Onboarding pack contents:
 * - Company profile (name, address, year established, employee count)
 * - Capabilities (PCB fab, SMT/THT assembly, testing, design services)
 * - Certifications (ISO 9001, ISO 14001, IATF 16949, IPC-A-610)
 * - Bank details (IBAN, SWIFT, bank name, account name)
 * - Tax IDs (VAT number, DUNS, etc.)
 * - Contact information (sales, engineering, finance)
 * - Sample products/past projects
 * 
 * Portal submission methods:
 * - Web form POST (username/password auth, form field mapping)
 * - File upload (PDF upload via multipart/form-data)
 * - Email submission (send pack to procurement email)
 * - Manual (generate pack for manual submission)
 * 
 * Used by:
 * - SupplierPortalController for portal onboarding
 * - Sales team for customer onboarding
 * - UnifiedPdfGeneratorService for pack PDF generation
 */
class OnboardingPackService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OnboardingPackRepository $onboardingPackRepository,
        private PortalCandidateRepository $portalCandidateRepository,
        private SupplierPortalRepository $supplierPortalRepository,
        private HttpClientInterface $httpClient,
        private UnifiedPdfGeneratorService $pdfGenerator
    ) {}

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
        // 1. Create OnboardingPack entity
        $pack = new OnboardingPack();
        $pack->setCompanyId($companyId);
        $pack->setPackType($packType);
        $pack->setGeneratedAt(new \DateTime());
        $pack->setStatus('GENERATED');
        $this->entityManager->persist($pack);
        $this->entityManager->flush(); // Get pack ID
        
        // 2. Extract fields from PortalCandidate
        $fieldsExtracted = $this->autoFillFields($companyId, $packType, $customFields);
        $pack->setFieldsJson(json_encode($fieldsExtracted));
        
        // 3. Generate PDF using UnifiedPdfGeneratorService
        try {
            $pdfPath = $this->pdfGenerator->generateOnboardingPackPdf($pack);
            $pack->setPdfPath($pdfPath);
        } catch (\Exception $e) {
            // PDF generation might not be implemented yet
            $pack->setPdfPath('/tmp/pending_' . $pack->getId() . '.pdf');
            $pack->setStatus('PENDING_PDF');
        }
        
        // 4. Update pack
        $this->entityManager->flush();
        
        // 5. Return pack data
        return [
            'packId' => $pack->getId(),
            'pdfPath' => $pack->getPdfPath(),
            'fieldsExtracted' => $fieldsExtracted
        ];
    }

    /**
     * Auto-fill form fields from PortalCandidate data
     * 
     * @param int $companyId - Company ID
     * @param string $packType - Pack type
     * @param array $customFields - Custom fields (if CUSTOM type)
     * 
     * @return array - Extracted fields
     */
    public function autoFillFields(int $companyId, string $packType, array $customFields = []): array
    {
        // 1. Get PortalCandidate data
        $candidate = $this->portalCandidateRepository->findOneBy(['companyId' => $companyId]);
        
        if (!$candidate) {
            // Create default candidate with CRM Starz Morocco data
            $candidate = $this->createDefaultCandidate($companyId);
        }
        
        // 2. Extract fields based on pack type
        $fields = [];
        
        if ($packType === 'FULL' || $packType === 'CUSTOM') {
            $fields['companyName'] = $candidate->getCompanyName();
            $fields['address'] = $candidate->getAddress();
            $fields['city'] = $candidate->getCity();
            $fields['country'] = $candidate->getCountry();
            $fields['postalCode'] = $candidate->getPostalCode();
            $fields['yearEstablished'] = $candidate->getYearEstablished();
            $fields['employeeCount'] = $candidate->getEmployeeCount();
            $fields['website'] = $candidate->getWebsite();
            $fields['vatNumber'] = $candidate->getVatNumber();
            $fields['dunsNumber'] = $candidate->getDunsNumber();
            $fields['bankIban'] = $candidate->getBankIban();
            $fields['bankSwift'] = $candidate->getBankSwift();
            $fields['bankName'] = $candidate->getBankName();
            $fields['bankAccountName'] = $candidate->getBankAccountName();
            $fields['contactSalesName'] = $candidate->getContactSalesName();
            $fields['contactSalesEmail'] = $candidate->getContactSalesEmail();
            $fields['contactSalesPhone'] = $candidate->getContactSalesPhone();
            $fields['contactFinanceName'] = $candidate->getContactFinanceName();
            $fields['contactFinanceEmail'] = $candidate->getContactFinanceEmail();
            $fields['contactFinancePhone'] = $candidate->getContactFinancePhone();
            $fields['capabilities'] = json_decode($candidate->getCapabilitiesJson() ?? '[]', true);
            $fields['certifications'] = json_decode($candidate->getCertificationsJson() ?? '[]', true);
        }
        
        if ($packType === 'QUICK') {
            // Minimal fields for quick onboarding
            $fields['companyName'] = $candidate->getCompanyName();
            $fields['address'] = $candidate->getAddress();
            $fields['contactSalesEmail'] = $candidate->getContactSalesEmail();
            $fields['contactSalesPhone'] = $candidate->getContactSalesPhone();
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
        
        // 2. Determine submission method based on portal vendor
        $vendor = $portal->getPortalVendor();
        $method = 'MANUAL'; // Default
        
        if ($vendor === 'ARIBA') {
            $method = 'ARIBA_API'; // Note: Ariba API integration not yet implemented
        } elseif ($vendor === 'COUPA') {
            $method = 'COUPA_API'; // Note: Coupa API integration not yet implemented
        } elseif ($portal->getFormFieldsJson()) {
            $method = 'WEB_FORM'; // Generic web form submission
        }
        
        // 3. Submit based on method
        if ($method === 'WEB_FORM') {
            return $this->submitViaWebForm($pack, $portal, $credentials);
        } elseif ($method === 'ARIBA_API' || $method === 'COUPA_API') {
            // API integrations not yet implemented - mark as pending
            $pack->setStatus('PENDING_API_INTEGRATION');
            $pack->setPortalId($portalId);
            $this->entityManager->flush();
            
            return [
                'success' => false,
                'method' => $method,
                'response' => null,
                'errorMessage' => "$method integration not yet implemented"
            ];
        } else {
            // Manual submission - just mark as ready
            $pack->setStatus('READY_FOR_MANUAL_SUBMIT');
            $pack->setPortalId($portalId);
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
            
            $loginResponse = $this->httpClient->request('POST', $loginUrl, [
                'body' => [
                    'username' => $credentials['username'],
                    'password' => $credentials['password']
                ],
                'timeout' => 30
            ]);
            
            // Extract session cookie
            $sessionCookie = $loginResponse->getHeaders()['set-cookie'][0] ?? null;
            
            // 2. Get form field mappings
            $formFields = json_decode($portal->getFormFieldsJson() ?? '{}', true);
            $packData = json_decode($pack->getFieldsJson() ?? '{}', true);
            
            // 3. Map pack data to form fields
            $formData = [];
            foreach ($formFields as $fieldName => $packField) {
                $formData[$fieldName] = $packData[$packField] ?? '';
            }
            
            // 4. Upload PDF if required
            if ($portal->getRequiresFileUpload() && file_exists($pack->getPdfPath())) {
                $formData['file'] = fopen($pack->getPdfPath(), 'r');
            }
            
            // 5. Submit form
            $submitUrl = $portal->getSubmitUrl();
            
            if (!$submitUrl) {
                throw new \RuntimeException('Submit URL not configured');
            }
            
            $submitResponse = $this->httpClient->request('POST', $submitUrl, [
                'headers' => ['Cookie' => $sessionCookie ?? ''],
                'body' => $formData,
                'timeout' => 60
            ]);
            
            // 6. Check response
            $statusCode = $submitResponse->getStatusCode();
            $success = $statusCode >= 200 && $statusCode < 300;
            
            if ($success) {
                $pack->setStatus('SUBMITTED');
                $pack->setSubmittedAt(new \DateTime());
                $pack->setPortalId($portal->getId());
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
     * Create default PortalCandidate with CRM Starz Morocco data
     * 
     * @param int $companyId - Company ID
     * 
     * @return PortalCandidate
     */
    private function createDefaultCandidate(int $companyId): PortalCandidate
    {
        // 1. Create PortalCandidate with CRM Starz Morocco info
        $candidate = new PortalCandidate();
        $candidate->setCompanyId($companyId);
        $candidate->setCompanyName('CRM Starz Morocco');
        $candidate->setAddress('Industrial Zone, Tangier Free Zone');
        $candidate->setCity('Tangier');
        $candidate->setCountry('Morocco');
        $candidate->setPostalCode('90000');
        $candidate->setYearEstablished(2018);
        $candidate->setEmployeeCount(50);
        $candidate->setWebsite('https://crmstarz.ma');
        $candidate->setVatNumber('MA123456789');
        $candidate->setDunsNumber('987654321');
        $candidate->setBankIban('MA12345678901234567890123456');
        $candidate->setBankSwift('BCMAMAMC');
        $candidate->setBankName('Bank of Africa');
        $candidate->setBankAccountName('CRM Starz Morocco SARL');
        $candidate->setContactSalesName('Sales Department');
        $candidate->setContactSalesEmail('sales@crmstarz.ma');
        $candidate->setContactSalesPhone('+212 5 39 XX XX XX');
        $candidate->setContactFinanceName('Finance Department');
        $candidate->setContactFinanceEmail('finance@crmstarz.ma');
        $candidate->setContactFinancePhone('+212 5 39 XX XX XX');
        
        $candidate->setCapabilitiesJson(json_encode([
            'PCB Fabrication (2-16 layers)',
            'SMT Assembly (0201-BGA)',
            'THT Assembly',
            'Testing (ICT, FCT, AOI)',
            'Design Services (DFM, layout)'
        ]));
        
        $candidate->setCertificationsJson(json_encode([
            'ISO 9001:2015',
            'ISO 14001:2015',
            'IATF 16949:2016',
            'IPC-A-610 Class 3'
        ]));
        
        $this->entityManager->persist($candidate);
        $this->entityManager->flush();
        
        // 2. Return candidate
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
        
        // 2. Get portal if submitted
        $portalName = null;
        if ($pack->getPortalId()) {
            $portal = $this->supplierPortalRepository->find($pack->getPortalId());
            $portalName = $portal?->getPortalUrl();
        }
        
        // 3. Return status
        return [
            'status' => $pack->getStatus(),
            'generatedAt' => $pack->getGeneratedAt(),
            'submittedAt' => $pack->getSubmittedAt(),
            'portalName' => $portalName
        ];
    }
}
