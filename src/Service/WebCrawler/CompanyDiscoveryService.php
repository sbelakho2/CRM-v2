<?php

namespace App\Service\WebCrawler;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use App\Service\CompetitorLearnerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Main service to discover companies based on sectors and criteria from Tracker.xlsx
 * 
 * Enhanced with automatic competitor learning from scraped content.
 * Uses Google Search API for company discovery.
 */
class CompanyDiscoveryService
{
    private const MOROCCAN_FREE_ZONES = [
        'TAC' => 'Tanger Automotive City',
        'TFZ' => 'Tanger Free Zone',
        'AFZ Kenitra' => 'Atlantic Free Zone Kenitra',
        'Casablanca' => 'Casablanca/Midparc',
        'Bouskoura' => 'Bouskoura',
        'Nouaceur' => 'Nouaceur',
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
        private GoogleDorkService $googleDork,
        private LoggerInterface $logger,
        private ?CompetitorLearnerService $competitorLearner = null
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
     * Discover companies across all target sectors
     */
    public function discoverAllSectors(): array
    {
        $allCompanies = [];

        foreach (self::TARGET_SECTORS as $sector) {
            foreach (self::MOROCCAN_FREE_ZONES as $code => $name) {
                $companies = $this->discoverCompanies($sector, $name);
                $allCompanies = array_merge($allCompanies, $companies);
                
                // Sleep to avoid rate limiting
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
            // Skip if not a proper company data array
            if (!is_array($data) || !isset($data['name'])) {
                continue;
            }
            
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
     * Learn competitors from discovery results
     * Called automatically during company discovery
     */
    private function learnCompetitorsFromResults(array $results): void
    {
        if (!$this->competitorLearner) {
            return;
        }

        $this->competitorLearner->learnFromGoogleResults($results);
        
        $this->logger->debug('Competitor learning from discovery results', [
            'resultCount' => count($results),
        ]);
    }

    /**
     * Enrich existing company data with additional web research
     */
    public function enrichCompanyData(Company $company): void
    {
        // Find website if missing
        if (!$company->getWebsite()) {
            $website = $this->googleDork->findCompanyWebsite($company->getName());
            if ($website) {
                $company->setWebsite($website);
            }
        }

        $this->em->flush();
    }

    /**
     * Analyze company website for competitor mentions
     * Returns array of discovered competitors
     */
    public function analyzeForCompetitors(Company $company): array
    {
        if (!$this->competitorLearner) {
            return [];
        }

        $content = '';
        
        // Gather available text content about the company
        $content .= $company->getName() . ' ';
        $content .= $company->getSourceNotes() ?? '';
        
        // If we had website scraping capability, we'd add that content here
        // For now, use what we have in the database
        
        $sourceUrl = $company->getWebsite() ?? 'company_analysis';
        
        return $this->competitorLearner->learnFromContent($content, $sourceUrl);
    }
}
