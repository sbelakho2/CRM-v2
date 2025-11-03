<?php

namespace App\Service\WebCrawler;

use App\Entity\Company;
use App\Entity\Contact;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\DomCrawler\Crawler;
use Psr\Log\LoggerInterface;

/**
 * Service to scrape LinkedIn for company profiles and contacts
 * Note: For production, integrate with LinkedIn Sales Navigator API or RocketReach
 */
class LinkedInScraperService
{
    private const PROCUREMENT_TITLES = [
        'Procurement Engineer',
        'Purchasing Engineer',
        'Commodity Manager',
        'Buyer',
        'Supply Chain Manager',
        'Supplier Quality Engineer',
        'Category Manager',
    ];

    public function __construct(
        private LoggerInterface $logger
    ) {}

    /**
     * Search for companies in a specific sector and location
     * Returns array of company data
     */
    public function searchCompanies(string $sector, ?string $location = null): array
    {
        $this->logger->info("LinkedIn search for companies", [
            'sector' => $sector,
            'location' => $location
        ]);

        // Build search query
        $searchTerms = $this->buildCompanySearchTerms($sector, $location);
        
        // In production, this would use LinkedIn Sales Navigator API
        // For now, we return search URLs for manual review
        $searchUrls = [];
        foreach ($searchTerms as $term) {
            $searchUrls[] = $this->generateLinkedInSearchUrl($term, 'companies');
        }

        $this->logger->info("Generated LinkedIn search URLs", [
            'count' => count($searchUrls),
            'urls' => $searchUrls
        ]);

        // Return empty for now - manual data entry or API integration needed
        // In production, integrate with:
        // - LinkedIn Sales Navigator API
        // - RocketReach API
        // - Apollo.io API
        return [];
    }

    /**
     * Find LinkedIn profile for a company
     */
    public function findCompanyProfile(string $companyName): ?string
    {
        // Generate search URL
        $searchUrl = $this->generateLinkedInSearchUrl($companyName, 'companies');
        
        $this->logger->debug("LinkedIn company search URL", [
            'company' => $companyName,
            'url' => $searchUrl
        ]);

        // In production, use API to fetch actual URL
        // For now, return null - requires manual verification
        return null;
    }

    /**
     * Search for contacts at a company with procurement/purchasing roles
     */
    public function findContactsAtCompany(Company $company): array
    {
        $this->logger->info("Searching for contacts at company", [
            'company' => $company->getName()
        ]);

        $searchUrls = [];
        
        foreach (self::PROCUREMENT_TITLES as $title) {
            $searchUrl = $this->generateContactSearchUrl($company->getName(), $title);
            $searchUrls[] = [
                'title' => $title,
                'url' => $searchUrl
            ];
        }

        $this->logger->info("Generated contact search URLs", [
            'company' => $company->getName(),
            'count' => count($searchUrls),
            'urls' => $searchUrls
        ]);

        // Return search URLs for manual processing
        // In production, use LinkedIn Sales Navigator API
        return $searchUrls;
    }

    /**
     * Generate LinkedIn search URL for companies
     */
    private function generateLinkedInSearchUrl(string $query, string $type = 'companies'): string
    {
        $encodedQuery = urlencode($query);
        
        if ($type === 'companies') {
            return "https://www.linkedin.com/search/results/companies/?keywords={$encodedQuery}";
        } else {
            return "https://www.linkedin.com/search/results/people/?keywords={$encodedQuery}";
        }
    }

    /**
     * Generate LinkedIn search URL for contacts at a specific company
     */
    private function generateContactSearchUrl(string $companyName, string $jobTitle): string
    {
        $query = urlencode($companyName . ' ' . $jobTitle);
        return "https://www.linkedin.com/search/results/people/?keywords={$query}";
    }

    /**
     * Build search terms for company discovery
     */
    private function buildCompanySearchTerms(string $sector, ?string $location = null): array
    {
        $terms = [];

        // Base sector term
        $baseTerm = $sector;
        
        if ($location) {
            $baseTerm .= ' ' . $location;
        }

        // Add Morocco context
        $terms[] = $baseTerm . ' Morocco';
        $terms[] = $baseTerm . ' Tanger';
        $terms[] = $baseTerm . ' Casablanca';

        // Add industry-specific terms
        switch ($sector) {
            case 'Automotive':
                $terms[] = 'Automotive Supplier Morocco';
                $terms[] = 'Electronics Manufacturing Morocco Automotive';
                $terms[] = 'Tier 1 Supplier Morocco';
                break;
            case 'Aerospace':
                $terms[] = 'Aerospace Manufacturing Morocco';
                $terms[] = 'Aircraft Components Morocco';
                break;
            case 'Industrial':
                $terms[] = 'Industrial Electronics Morocco';
                $terms[] = 'Manufacturing Morocco';
                break;
            case 'Rail':
                $terms[] = 'Railway Electronics Morocco';
                $terms[] = 'Rail Components Morocco';
                break;
            case 'Renewables':
                $terms[] = 'Solar Manufacturing Morocco';
                $terms[] = 'Renewable Energy Morocco';
                break;
            case 'Power Electronics':
                $terms[] = 'Power Electronics Morocco';
                $terms[] = 'Inverter Manufacturing Morocco';
                break;
        }

        return $terms;
    }

    /**
     * Extract contact data from LinkedIn profile URL
     * In production, use API to get actual data
     */
    public function extractContactData(string $linkedInProfileUrl): ?array
    {
        $this->logger->info("Extracting contact data", [
            'url' => $linkedInProfileUrl
        ]);

        // In production, integrate with:
        // - LinkedIn Sales Navigator API
        // - RocketReach API for email finding
        // - Apollo.io for enrichment
        
        // For now, return structure for manual entry
        return [
            'linkedin_url' => $linkedInProfileUrl,
            'name' => null,
            'title' => null,
            'email' => null,
            'company' => null,
            'source' => 'LinkedIn'
        ];
    }
}
