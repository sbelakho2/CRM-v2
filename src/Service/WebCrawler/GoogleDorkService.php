<?php

namespace App\Service\WebCrawler;

use App\Service\GoogleSearchService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DomCrawler\Crawler;
use Psr\Log\LoggerInterface;

/**
 * Service to use Google Dorks for finding companies and supplier portals
 * 
 * Enhanced to actually execute searches through GoogleSearchService API
 * instead of just logging URLs for manual review.
 */
class GoogleDorkService
{
    public function __construct(
        private HttpClientInterface $httpClient, 
        private LoggerInterface $logger,
        private ?GoogleSearchService $googleSearchService = null
    )
    {
    }
    
    /**
     * Set the Google Search Service (allows injection after construction)
     */
    public function setGoogleSearchService(GoogleSearchService $service): void
    {
        $this->googleSearchService = $service;
    }

    /**
     * Search for companies using Google Dorks
     * 
     * Now actually executes searches through Google Custom Search API
     * when GoogleSearchService is available.
     * 
     * @param string $sector The industry sector to search
     * @param string|null $location The geographic location (e.g., "Tanger Free Zone")
     * @param bool $executeSearch Whether to actually execute via API (costs money)
     * @return array Search results with company data
     */
    public function searchCompanies(string $sector, ?string $location = null, bool $executeSearch = true): array
    {
        $this->logger->info("Google Dork search for companies", [
            'sector' => $sector,
            'location' => $location,
            'execute_search' => $executeSearch
        ]);

        $searchQueries = $this->buildGoogleDorkQueries($sector, $location);
        $discovered = [];
        $allResults = [];

        // If we have GoogleSearchService and should execute, use it
        if ($executeSearch && $this->googleSearchService !== null) {
            $this->logger->info("Executing searches via Google Custom Search API");
            
            foreach ($searchQueries as $query) {
                try {
                    $results = $this->googleSearchService->searchCompanies($query, 10);
                    
                    if (!empty($results['results'])) {
                        foreach ($results['results'] as $result) {
                            // Deduplicate by domain
                            $domain = $result['displayLink'] ?? '';
                            if (!isset($allResults[$domain])) {
                                $allResults[$domain] = [
                                    'name' => $this->extractCompanyName($result['title'] ?? ''),
                                    'website' => $this->extractWebsiteFromResult($result),
                                    'title' => $result['title'] ?? '',
                                    'snippet' => $result['snippet'] ?? '',
                                    'link' => $result['link'] ?? '',
                                    'displayLink' => $domain,
                                    'source_query' => $query,
                                    'sector' => $sector,
                                    'location' => $location,
                                ];
                            }
                        }
                        
                        $this->logger->debug("Query returned results", [
                            'query' => $query,
                            'count' => count($results['results'])
                        ]);
                    }
                    
                    // Respect rate limits
                    usleep(200000); // 200ms between requests
                    
                } catch (\Exception $e) {
                    $this->logger->warning("Search query failed", [
                        'query' => $query,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            $discovered = array_values($allResults);
            
            $this->logger->info("Google Dork search completed", [
                'sector' => $sector,
                'location' => $location,
                'total_unique_results' => count($discovered)
            ]);
            
        } else {
            // Fallback: Log URLs for manual review (original behavior)
            $this->logger->info("=" . str_repeat("=", 70));
            $this->logger->info("GOOGLE SEARCH URLS - Copy and paste these into your browser:");
            $this->logger->info("=" . str_repeat("=", 70));
            
            foreach ($searchQueries as $query) {
                $searchUrl = "https://www.google.com/search?q=" . urlencode($query);

                $this->logger->debug('Google search URL', [
                    'query' => $query,
                    'url' => $searchUrl,
                ]);
                $this->logger->info("🔍 " . $searchUrl);

                $discovered[] = [
                    'query' => $query,
                    'url' => $searchUrl,
                    'manual_only' => true,
                ];
            }
            
            $this->logger->info("=" . str_repeat("=", 70));
            $this->logger->info("💡 TIP: Visit these URLs, find companies, then add them manually at /companies/new");
            $this->logger->info("💡 OR: Configure GOOGLE_SEARCH_API_KEY and GOOGLE_SEARCH_ENGINE_ID for automated search");
            $this->logger->info("=" . str_repeat("=", 70));
        }

        return $discovered;
    }
    
    /**
     * Extract company name from search result title
     */
    private function extractCompanyName(string $title): string
    {
        // Remove common suffixes
        $name = preg_replace('/\s*[-|–]\s*.*(LinkedIn|Facebook|Twitter|Homepage|Home|About).*$/i', '', $title);
        $name = preg_replace('/\s*\|\s*.*$/', '', $name);
        $name = trim($name);
        
        return $name ?: $title;
    }
    
    /**
     * Extract clean website URL from search result
     */
    private function extractWebsiteFromResult(array $result): ?string
    {
        if (!empty($result['link'])) {
            $parsed = parse_url($result['link']);
            if ($parsed && isset($parsed['host'])) {
                return sprintf('%s://%s', $parsed['scheme'] ?? 'https', $parsed['host']);
            }
        }
        return null;
    }

    /**
     * Find company website
     */
    public function findCompanyWebsite(string $companyName): ?string
    {
        $query = $companyName . ' Morocco official website';
        $searchUrl = "https://www.google.com/search?q=" . urlencode($query);
        
        $this->logger->debug("Website search", [
            'company' => $companyName,
            'url' => $searchUrl
        ]);

        // In production, use Google Custom Search API to fetch results
        return null;
    }

    /**
     * Find supplier portal registration pages
     */
    public function findSupplierPortal(string $companyName): ?string
    {
        $dorkQueries = [
            "site:{$companyName}.com inurl:supplier inurl:portal",
            "site:{$companyName}.com \"supplier registration\"",
            "site:{$companyName}.com \"vendor portal\"",
            "\"{$companyName}\" supplier portal registration",
        ];

        foreach ($dorkQueries as $query) {
            $searchUrl = "https://www.google.com/search?q=" . urlencode($query);
            $this->logger->debug("Portal search", [
                'company' => $companyName,
                'query' => $query,
                'url' => $searchUrl
            ]);
        }

        // In production, parse results and extract portal URLs
        return null;
    }

    /**
     * Find procurement/purchasing contact emails using Google Dorks
     */
    public function findContactEmails(string $companyName, ?string $domain = null): array
    {
        $emails = [];

        if (!$domain) {
            $domain = $this->guessCompanyDomain($companyName);
        }

        if (!$domain) {
            return [];
        }

        // Google Dorks for finding emails
        $dorkQueries = [
            "site:{$domain} intext:\"procurement\" OR intext:\"purchasing\" email",
            "site:{$domain} \"buyer\" OR \"commodity manager\" contact",
            "site:linkedin.com \"{$companyName}\" procurement engineer email",
        ];

        foreach ($dorkQueries as $query) {
            $searchUrl = "https://www.google.com/search?q=" . urlencode($query);
            $this->logger->debug("Email search", [
                'company' => $companyName,
                'query' => $query,
                'url' => $searchUrl
            ]);
        }

        // In production, parse results and extract emails
        // Use email verification service to validate
        return $emails;
    }

    /**
     * Build Google Dork queries for company discovery
     */
    private function buildGoogleDorkQueries(string $sector, ?string $location = null): array
    {
        $queries = [];
        $locationTerm = $location ? " {$location}" : " Morocco";

        // General queries
        $queries[] = "\"{$sector}\" manufacturing{$locationTerm}";
        $queries[] = "{$sector} supplier{$locationTerm}";
        $queries[] = "{$sector} electronics{$locationTerm}";

        // LinkedIn-specific dorks
        $queries[] = "site:linkedin.com \"{$sector}\"{$locationTerm} company";

        // Industry directory dorks
        $queries[] = "site:moroccanindustry.com {$sector}";
        $queries[] = "site:zawya.com {$sector} Morocco";

        // Free zone specific
        if ($location) {
            $queries[] = "\"{$location}\" \"{$sector}\" companies list";
            $queries[] = "site:tanger-free-zone.com {$sector}";
        }

        // Sector-specific queries
        switch ($sector) {
            case 'Automotive':
                $queries[] = "automotive tier 1 Morocco suppliers";
                $queries[] = "electronics manufacturing services Morocco automotive";
                $queries[] = "IATF 16949 Morocco";
                break;
            case 'Aerospace':
                $queries[] = "AS9100 Morocco aerospace";
                $queries[] = "aircraft components manufacturing Morocco";
                break;
            case 'Industrial':
                $queries[] = "industrial automation Morocco";
                $queries[] = "control panels Morocco";
                break;
            case 'Rail':
                $queries[] = "railway electronics Morocco";
                $queries[] = "train components Morocco";
                break;
            case 'Renewables':
                $queries[] = "solar inverter Morocco";
                $queries[] = "renewable energy components Morocco";
                break;
            case 'Power Electronics':
                $queries[] = "power electronics Morocco";
                $queries[] = "inverter manufacturer Morocco";
                break;
        }

        return $queries;
    }

    /**
     * Guess company domain from name
     */
    private function guessCompanyDomain(string $companyName): ?string
    {
        // Clean company name
        $clean = strtolower($companyName);
        $clean = preg_replace('/[^a-z0-9]+/', '', $clean);
        
        // Common TLDs for Moroccan companies
        $possibleDomains = [
            $clean . '.ma',
            $clean . '.com',
            $clean . 'morocco.com',
        ];

        // In production, verify which domains exist
        return $possibleDomains[0] ?? null;
    }

    /**
     * Search for company certifications (ISO, IATF, AS9100, etc.)
     */
    public function findCertifications(string $companyName): array
    {
        $certQueries = [
            "\"{$companyName}\" ISO 9001",
            "\"{$companyName}\" IATF 16949",
            "\"{$companyName}\" AS9100",
            "site:{$companyName}.* certification",
        ];

        $certifications = [];

        foreach ($certQueries as $query) {
            $searchUrl = "https://www.google.com/search?q=" . urlencode($query);
            $this->logger->debug("Certification search", [
                'company' => $companyName,
                'query' => $query,
                'url' => $searchUrl
            ]);
        }

        // In production, parse results and extract certification info
        return $certifications;
    }
}
