<?php

namespace App\Service\WebCrawler;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Main service to discover companies based on sectors and criteria from Tracker.xlsx
 */
class CompanyDiscoveryService
{
    // AFRICA
    private const MOROCCAN_FREE_ZONES = [
        'TAC' => 'Tanger Automotive City',
        'TFZ' => 'Tanger Free Zone',
        'AFZ Kenitra' => 'Atlantic Free Zone Kenitra',
        'Casablanca' => 'Casablanca/Midparc',
        'Bouskoura' => 'Bouskoura',
        'Nouaceur' => 'Nouaceur',
    ];

    private const SOUTH_AFRICA_REGIONS = [
        'Johannesburg' => 'Johannesburg, South Africa',
        'Cape Town' => 'Cape Town, South Africa',
        'Durban' => 'Durban, South Africa',
        'Pretoria' => 'Pretoria, South Africa',
        'Gqeberha' => 'Gqeberha (Port Elizabeth), South Africa',
    ];

    // EUROPE
    private const EU_REGIONS = [
        'Germany' => 'Germany',
        'Poland' => 'Poland',
        'Czech Republic' => 'Czech Republic',
        'France' => 'France',
        'Italy' => 'Italy',
        'Spain' => 'Spain',
        'Netherlands' => 'Netherlands',
        'Belgium' => 'Belgium',
        'Austria' => 'Austria',
        'Hungary' => 'Hungary',
    ];

    private const UK_REGIONS = [
        'London' => 'London, UK',
        'Midlands' => 'Midlands, UK',
        'Manchester' => 'Manchester, UK',
        'Yorkshire' => 'Yorkshire, UK',
        'Scotland' => 'Scotland, UK',
    ];

    // UNITED STATES
    private const US_EAST_COAST = [
        'New York' => 'New York, NY',
        'New Jersey' => 'New Jersey, NJ',
        'Pennsylvania' => 'Pennsylvania, PA',
        'Massachusetts' => 'Massachusetts, MA',
        'Connecticut' => 'Connecticut, CT',
        'Virginia' => 'Virginia, VA',
        'North Carolina' => 'North Carolina, NC',
        'Florida' => 'Florida, FL',
    ];

    private const US_TEXAS = [
        'Houston' => 'Houston, TX',
        'Dallas' => 'Dallas, TX',
        'Austin' => 'Austin, TX',
        'San Antonio' => 'San Antonio, TX',
        'Fort Worth' => 'Fort Worth, TX',
    ];

    private const US_PACIFIC_NORTHWEST = [
        'Seattle' => 'Seattle, WA',
        'Portland' => 'Portland, OR',
        'Spokane' => 'Spokane, WA',
        'Eugene' => 'Eugene, OR',
        'Boise' => 'Boise, ID',
    ];

    private const TARGET_SECTORS = [
        'Automotive',
        'Industrial',
        'Aerospace',
        'Rail',
        'Renewables',
        'Power Electronics',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private CompanyRepository $companyRepo,
        private LinkedInScraperService $linkedInScraper,
        private GoogleDorkService $googleDork,
        private LoggerInterface $logger
    ) {}

    /**
     * Discover companies in a specific sector and location
     */
    public function discoverCompanies(string $sector, ?string $location = null): array
    {
        $this->logger->info("Starting company discovery", [
            'sector' => $sector,
            'location' => $location
        ]);

        $discovered = [];

        // Use Google Dorks to find companies
        $googleResults = $this->googleDork->searchCompanies($sector, $location);
        $discovered = array_merge($discovered, $googleResults);

        // Use LinkedIn to find companies
        $linkedInResults = $this->linkedInScraper->searchCompanies($sector, $location);
        $discovered = array_merge($discovered, $linkedInResults);

        // Deduplicate and save
        $savedCompanies = $this->saveDiscoveredCompanies($discovered, $sector, $location);

        $this->logger->info("Company discovery completed", [
            'sector' => $sector,
            'location' => $location,
            'discovered' => count($discovered),
            'saved' => count($savedCompanies)
        ]);

        return $savedCompanies;
    }

    /**
     * Discover companies across all target sectors and regions
     * Searches all documented regions:
     * - Africa: Morocco (6 zones), South Africa (5 regions)
     * - Europe: EU (10 countries), UK (5 regions)
     * - USA: East Coast (8 states), Texas (5 cities), Pacific Northwest (5 cities)
     */
    public function discoverAllSectors(): array
    {
        $allCompanies = [];

        foreach (self::TARGET_SECTORS as $sector) {
            // AFRICA: Morocco
            foreach (self::MOROCCAN_FREE_ZONES as $code => $name) {
                $companies = $this->discoverCompanies($sector, $name);
                $allCompanies = array_merge($allCompanies, $companies);
                sleep(2);
            }

            // AFRICA: South Africa
            foreach (self::SOUTH_AFRICA_REGIONS as $code => $name) {
                $companies = $this->discoverCompanies($sector, $name);
                $allCompanies = array_merge($allCompanies, $companies);
                sleep(2);
            }

            // EUROPE: EU Countries
            foreach (self::EU_REGIONS as $code => $name) {
                $companies = $this->discoverCompanies($sector, $name);
                $allCompanies = array_merge($allCompanies, $companies);
                sleep(2);
            }

            // EUROPE: UK Regions
            foreach (self::UK_REGIONS as $code => $name) {
                $companies = $this->discoverCompanies($sector, $name);
                $allCompanies = array_merge($allCompanies, $companies);
                sleep(2);
            }

            // USA: East Coast
            foreach (self::US_EAST_COAST as $code => $name) {
                $companies = $this->discoverCompanies($sector, $name);
                $allCompanies = array_merge($allCompanies, $companies);
                sleep(2);
            }

            // USA: Texas
            foreach (self::US_TEXAS as $code => $name) {
                $companies = $this->discoverCompanies($sector, $name);
                $allCompanies = array_merge($allCompanies, $companies);
                sleep(2);
            }

            // USA: Pacific Northwest
            foreach (self::US_PACIFIC_NORTHWEST as $code => $name) {
                $companies = $this->discoverCompanies($sector, $name);
                $allCompanies = array_merge($allCompanies, $companies);
                sleep(2);
            }
        }

        return $allCompanies;
    }

    /**
     * Save discovered companies to database, avoiding duplicates
     */
    private function saveDiscoveredCompanies(array $discoveredData, string $sector, ?string $location): array
    {
        $savedCompanies = [];

        foreach ($discoveredData as $data) {
            // Check if company already exists
            $existing = $this->companyRepo->findOneBy(['name' => $data['name']]);
            
            if ($existing) {
                $this->logger->debug("Company already exists", ['name' => $data['name']]);
                continue;
            }

            // Create new company
            $company = new Company();
            $company->setName($data['name']);
            $company->setSector($sector);
            $company->setPhysicalSite($location);
            $company->setWebsite($data['website'] ?? null);
            $company->setLinkedinCompanyUrl($data['linkedin_url'] ?? null);
            $company->setPipelineStage('Prospect');
            $company->setAccountTier('C'); // Default to C tier
            $company->setSourceNotes('Auto-discovered by webcrawler on ' . date('Y-m-d'));
            $company->setCreatedAt(new \DateTime());
            $company->setUpdatedAt(new \DateTime());

            $this->em->persist($company);
            $savedCompanies[] = $company;

            $this->logger->info("New company discovered", ['name' => $data['name']]);
        }

        $this->em->flush();

        return $savedCompanies;
    }

    /**
     * Enrich existing company data with additional web research
     */
    public function enrichCompanyData(Company $company): void
    {
        // Find LinkedIn profile if missing
        if (!$company->getLinkedinCompanyUrl()) {
            $linkedInUrl = $this->linkedInScraper->findCompanyProfile($company->getName());
            if ($linkedInUrl) {
                $company->setLinkedinCompanyUrl($linkedInUrl);
            }
        }

        // Find website if missing
        if (!$company->getWebsite()) {
            $website = $this->googleDork->findCompanyWebsite($company->getName());
            if ($website) {
                $company->setWebsite($website);
            }
        }

        $this->em->flush();
    }
}
