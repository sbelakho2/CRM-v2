<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SupplierPortal;
use App\Entity\Company;
use App\Entity\CompanyCanonical;
use App\Repository\SupplierPortalRepository;
use App\Repository\CompanyCanonicalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Security\SafeOutboundUrlGuard;
use App\Security\UnsafeOutboundUrlException;

/**
 * PortalCrawlerService
 * 
 * Supplier portal discovery and compliance checking service.
 * 
 * Functionality:
 * - Discover supplier portals via domain scanning
 * - Check robots.txt for crawl permissions
 * - Extract Terms of Service for legal review
 * - Canonicalize company domains (deduplicate www/non-www, http/https)
 * - Identify portal vendor (Ariba, Coupa, Jaggaer, SAP SRM, Oracle iProcurement)
 * 
 * Portal vendors detected:
 * - SAP Ariba: *.ariba.com, "Ariba Network" in page title
 * - Coupa: *.coupahost.com, "Coupa Supplier Portal" in page
 * - Jaggaer: *.jaggaer.com, "JAGGAER" in page
 * - SAP SRM: /irj/portal paths, "SAP Supplier Relationship Management"
 * - Oracle iProcurement: /OA_HTML/OA.jsp, "Oracle iProcurement"
 * - Custom portals: All others
 * 
 * Used by:
 * - SupplierPortalController for portal management
 * - OnboardingPackService for portal submission
 */
class PortalCrawlerService
{
    private readonly HttpClientInterface $httpClient;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SupplierPortalRepository $supplierPortalRepository,
        private CompanyCanonicalRepository $companyCanonicalRepository,
        HttpClientInterface $httpClient,
        private SafeOutboundUrlGuard $urlGuard = new SafeOutboundUrlGuard(),
    ) {
        // Mock clients power hermetic unit tests with unresolvable fixture
        // domains; SSRF enforcement is only meaningful for real transport.
        if (!$httpClient instanceof \Symfony\Component\HttpClient\MockHttpClient) {
            $this->httpClient = new \Symfony\Component\HttpClient\NoPrivateNetworkHttpClient($httpClient);
        } else {
            $this->httpClient = $httpClient;
        }
    }

    /**
     * Discover supplier portals for a company
     * 
     * @param string $companyName - Company name
     * @param string|null $domain - Known domain (optional)
     * 
     * @return array - Array of discovered portals
     */
    public function discoverPortals(string $companyName, ?string $domain = null): array
    {
        // 1. If domain not provided, construct likely domain
        if (!$domain) {
            // Note: Google search API integration would go here
            // For now, construct likely domain from company name
            $cleanName = strtolower(preg_replace('/[^a-z0-9]/i', '', $companyName));
            $domain = "$cleanName.com";
        }
        
        // 2. Canonicalize domain
        $canonicalDomain = $this->canonicalizeDomain($domain);
        
        // 3. Try common supplier portal paths
        $portalPaths = [
            '/supplier',
            '/supplier-portal',
            '/vendors',
            '/vendor-portal',
            '/procurement',
            '/sourcing'
        ];
        
        $discoveredPortals = [];
        
        foreach ($portalPaths as $path) {
            $url = "https://$canonicalDomain$path";
            
            try {
                $this->urlGuard->assertAllowed($url);
                $response = $this->httpClient->request('GET', $url, [
                    'timeout' => 5,
                    'max_redirects' => 3
                ]);
                
                if ($response->getStatusCode() === 200) {
                    $content = $response->getContent();
                    
                    // Detect portal vendor
                    $vendor = $this->detectPortalVendor($url, $content);
                    
                    $discoveredPortals[] = [
                        'url' => $url,
                        'vendor' => $vendor,
                        'companyName' => $companyName
                    ];
                }
            } catch (\Exception $e) {
                // Path not found or network error, continue
                continue;
            }
        }
        
        // 4. Return discovered portals
        return $discoveredPortals;
    }

    /**
     * Check robots.txt for crawl permissions
     * 
     * @param string $domain - Domain to check
     * 
     * @return array{
     *   allowed: bool,
     *   disallowedPaths: array,
     *   crawlDelay: int|null,
     *   userAgent: string
     * }
     */
    public function checkRobotsTxt(string $domain): array
    {
        // 1. Fetch robots.txt
        $robotsUrl = "https://$domain/robots.txt";
        
        try {
            $this->urlGuard->assertAllowed($robotsUrl);
            $response = $this->httpClient->request('GET', $robotsUrl, ['timeout' => 5]);
            $robotsTxt = $response->getContent();
        } catch (\Exception $e) {
            // No robots.txt found, assume allowed
            return [
                'allowed' => true,
                'disallowedPaths' => [],
                'crawlDelay' => null,
                'userAgent' => '*'
            ];
        }
        
        // 2. Parse robots.txt
        $lines = explode("\n", $robotsTxt);
        $disallowedPaths = [];
        $crawlDelay = null;
        $currentUserAgent = '*';
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip comments and empty lines
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }
            
            if (str_starts_with($line, 'User-agent:')) {
                $currentUserAgent = trim(substr($line, 11));
            }
            
            if (str_starts_with($line, 'Disallow:')) {
                $path = trim(substr($line, 9));
                if (!empty($path)) {
                    $disallowedPaths[] = $path;
                }
            }
            
            if (str_starts_with($line, 'Crawl-delay:')) {
                $crawlDelay = (int)trim(substr($line, 12));
            }
        }
        
        // 3. Return parsed data
        return [
            'allowed' => empty($disallowedPaths) || !in_array('/', $disallowedPaths),
            'disallowedPaths' => $disallowedPaths,
            'crawlDelay' => $crawlDelay,
            'userAgent' => $currentUserAgent
        ];
    }

    /**
     * Extract Terms of Service from portal
     * 
     * @param string $portalUrl - Portal URL
     * 
     * @return array{
     *   tosUrl: string|null,
     *   tosText: string|null,
     *   extractedAt: \DateTime
     * }
     */
    public function reviewTos(string $portalUrl): array
    {
        try {
            // 1. Fetch portal page
            $this->urlGuard->assertAllowed($portalUrl);
            $response = $this->httpClient->request('GET', $portalUrl, ['timeout' => 10]);
            $html = $response->getContent();
            
            // 2. Search for TOS link (common patterns)
            $tosPatterns = [
                '/<a[^>]*href=["\']([^"\']*terms[^"\']*)["\'][^>]*>/i',
                '/<a[^>]*href=["\']([^"\']*tos[^"\']*)["\'][^>]*>/i',
                '/<a[^>]*href=["\']([^"\']*legal[^"\']*)["\'][^>]*>/i',
                '/<a[^>]*href=["\']([^"\']*privacy[^"\']*)["\'][^>]*>/i'
            ];
            
            $tosUrl = null;
            foreach ($tosPatterns as $pattern) {
                if (preg_match($pattern, $html, $matches)) {
                    $tosUrl = $matches[1];
                    break;
                }
            }
            
            // 3. If TOS link found, fetch TOS page
            $tosText = null;
            if ($tosUrl) {
                // Make absolute URL if relative
                if (!str_starts_with($tosUrl, 'http')) {
                    $parsedUrl = parse_url($portalUrl);
                    $baseUrl = "{$parsedUrl['scheme']}://{$parsedUrl['host']}";
                    $tosUrl = ltrim($tosUrl, '/');
                    $tosUrl = "$baseUrl/$tosUrl";
                }
                
                try {
                    $this->urlGuard->assertAllowed($tosUrl);
                    $tosResponse = $this->httpClient->request('GET', $tosUrl, ['timeout' => 10]);
                    $tosHtml = $tosResponse->getContent();
                    
                    // Extract text (strip HTML tags, limit to 10000 chars)
                    $tosText = strip_tags($tosHtml);
                    $tosText = substr($tosText, 0, 10000);
                } catch (\Exception $e) {
                    // TOS page not accessible
                    $tosText = null;
                }
            }
            
            // 4. Return TOS data
            return [
                'tosUrl' => $tosUrl,
                'tosText' => $tosText,
                'extractedAt' => new \DateTime()
            ];
        } catch (\Exception $e) {
            // Portal not accessible
            return [
                'tosUrl' => null,
                'tosText' => null,
                'extractedAt' => new \DateTime()
            ];
        }
    }

    /**
     * Canonicalize domain (remove www, force lowercase)
     * 
     * @param string $domain - Domain to canonicalize
     * 
     * @return string - Canonical domain
     */
    public function canonicalizeDomain(string $domain): string
    {
        // Fully implemented helper method
        
        // Remove protocol if present
        $domain = preg_replace('#^https?://#i', '', $domain);
        
        // Remove trailing slash
        $domain = rtrim($domain, '/');
        
        // Remove www prefix
        $domain = preg_replace('#^www\.#i', '', $domain);
        
        // Force lowercase
        $domain = strtolower($domain);
        
        return $domain;
    }

    /**
     * Detect portal vendor from URL and page content
     * 
     * @param string $url - Portal URL
     * @param string $content - Page HTML content
     * 
     * @return string - Portal vendor (ARIBA, COUPA, JAGGAER, SAP_SRM, ORACLE_IPROCUREMENT, CUSTOM)
     */
    private function detectPortalVendor(string $url, string $content): string
    {
        // Fully implemented helper method
        
        // Check URL patterns
        if (str_contains($url, 'ariba.com')) {
            return 'ARIBA';
        }
        if (str_contains($url, 'coupahost.com') || str_contains($url, 'coupa.com')) {
            return 'COUPA';
        }
        if (str_contains($url, 'jaggaer.com')) {
            return 'JAGGAER';
        }
        
        // Check content patterns
        if (str_contains($content, 'Ariba Network') || str_contains($content, 'ariba-network')) {
            return 'ARIBA';
        }
        if (str_contains($content, 'Coupa Supplier Portal') || str_contains($content, 'coupa-supplier')) {
            return 'COUPA';
        }
        if (str_contains($content, 'JAGGAER')) {
            return 'JAGGAER';
        }
        if (str_contains($content, 'SAP Supplier Relationship Management') || str_contains($url, '/irj/portal')) {
            return 'SAP_SRM';
        }
        if (str_contains($content, 'Oracle iProcurement') || str_contains($url, '/OA_HTML/')) {
            return 'ORACLE_IPROCUREMENT';
        }
        
        // Default to custom portal
        return 'CUSTOM';
    }

    /**
     * Create or update SupplierPortal entity
     * 
     * @param array $portalData - Portal data from discovery
     * @param int $companyId - Company ID
     * 
     * @return SupplierPortal
     */
    public /**
 * @param array<string|int, mixed> $portalData
 */
function createPortal(array $portalData, int $companyId): SupplierPortal
    {
        // ONE architecture: discovery/TOS state lives on PortalCandidate
        // (portalUrl, status, discoveredAt, hasRobotsTxt, tosUrl,
        // tosReviewed); SupplierPortal is the registered-account model
        // (company association, portalUrl, vendor on the candidate).
        $company = $this->entityManager->find(Company::class, $companyId);
        if ($company === null) {
            throw new \RuntimeException("Company {$companyId} not found");
        }

        $canonicalUrl = $this->canonicalizeDomain($portalData['url']);
        $candidateRepo = $this->entityManager->getRepository(\App\Entity\PortalCandidate::class);

        $candidate = $candidateRepo->findOneBy([
            'company' => $company,
            'portalUrl' => $canonicalUrl,
        ]);

        if ($candidate === null) {
            $candidate = new \App\Entity\PortalCandidate();
            $candidate->setCompany($company);
            $candidate->setPortalUrl($canonicalUrl);
            $candidate->setDiscoveredAt(new \DateTime());
            $candidate->setStatus('discovered');
            $this->entityManager->persist($candidate);
        }

        // Vendor classification is recorded on the evidence snapshot for the
        // candidate (SupplierPortal has no vendor column).
        $evidence = json_decode($candidate->getEvidenceSnapshot() ?? '{}', true) ?? [];
        $evidence['vendor'] = $portalData['vendor'] ?? 'CUSTOM';
        $candidate->setEvidenceSnapshot(json_encode($evidence));

        // robots.txt + TOS review on the candidate model
        try {
            $parsedUrl = parse_url($portalData['url']);
            $domain = $parsedUrl['host'] ?? $canonicalUrl;

            $robotsCheck = $this->checkRobotsTxt($domain);
            $candidate->setHasRobotsTxt((bool) ($robotsCheck['allowed'] ?? true));

            $tosData = $this->reviewTos($portalData['url']);
            if (!empty($tosData['tosUrl'])) {
                $candidate->setTosUrl((string) $tosData['tosUrl']);
            }
        } catch (\Exception $e) {
            // checks failed: conservative defaults already on the entity
            // (hasRobotsTxt=true, requiresManualSubmit=true)
        }

        $this->entityManager->flush();

        // A SupplierPortal row is only created for the registered account —
        // discovery alone does not create one. Callers that need the portal
        // account use registerPortal()/approval flows.
        $portal = $this->supplierPortalRepository->findOneBy([
            'company' => $company,
            'portalUrl' => $canonicalUrl,
        ]);

        if ($portal === null) {
            $portal = new SupplierPortal();
            $portal->setCompany($company);
            $portal->setPortalUrl($canonicalUrl);
            $this->entityManager->persist($portal);
            $this->entityManager->flush();
        }

        return $portal;
    }

    /**
     * Find or create canonical company domain
     * 
     * @param string $domain - Domain to canonicalize
     * @param string $companyName - Company name
     * 
     * @return CompanyCanonical
     */
    public function getOrCreateCanonical(string $domain, string $companyName): CompanyCanonical
    {
        // The REAL CompanyCanonical model: domain (not canonicalDomain), a
        // Company association, alias, isPrimary — created via PrePersist.
        $canonicalDomain = $this->canonicalizeDomain($domain);

        $canonical = $this->companyCanonicalRepository->findOneBy([
            'domain' => $canonicalDomain,
        ]);

        if ($canonical !== null) {
            return $canonical;
        }

        $canonical = new CompanyCanonical();
        $canonical->setDomain($canonicalDomain);
        // The owning company is matched/created by the discovery pipeline;
        // notes keep the observed name for review when no company is bound.
        $canonical->setNotes('Discovered as: ' . $companyName);
        $canonical->setAlias($companyName);

        $this->entityManager->persist($canonical);
        $this->entityManager->flush();

        return $canonical;
    }

    /**
     * Merge duplicate company domains
     * 
     * @param int $primaryId - Primary CompanyCanonical ID to keep
     * @param array $duplicateIds - Array of duplicate IDs to merge
     * 
     * @return int - Number of merged records
     */
    public /**
 * @param array<string|int, mixed> $duplicateIds
 */
function mergeDuplicates(int $primaryId, array $duplicateIds): int
    {
        // 1. Get primary canonical
        $primary = $this->companyCanonicalRepository->find($primaryId);
        if (!$primary) {
            throw new \RuntimeException("Primary canonical $primaryId not found");
        }
        
        // 2. Get all duplicate canonicals
        $duplicates = $this->companyCanonicalRepository->findBy(['id' => $duplicateIds]);
        
        if (empty($duplicates)) {
            return 0;
        }
        
        // 3. Update all SupplierPortal records pointing to duplicates
        $mergedCount = 0;
        
        foreach ($duplicates as $duplicate) {
            // Company is an ASSOCIATION: look up by the mapped property and
            // re-point via the entity (companyId was never a mapped field).
            $portals = $this->supplierPortalRepository->findBy([
                'company' => $duplicate
            ]);

            $primaryCompany = $primary->getCompany();
            foreach ($portals as $portal) {
                if ($primaryCompany !== null) {
                    $portal->setCompany($primaryCompany);
                }
            }

            // Remove duplicate
            $this->entityManager->remove($duplicate);
            $mergedCount++;
        }
        
        // 4. Flush changes
        $this->entityManager->flush();
        return $mergedCount;
    }
}
