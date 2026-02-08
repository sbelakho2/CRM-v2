<?php

namespace App\Service\WebCrawler;

use App\Entity\Company;
use App\Entity\Contact;
use App\Repository\CompanyRepository;
use App\Service\CompetitorLearnerService;
use App\Service\CountryService;
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
    /**
     * Target locations per region for bulk discovery.
     * Each entry maps a short code to a human-readable location name
     * passed into GoogleDorkService queries.
     */
    private const TARGET_LOCATIONS = [
        'MA' => [
            'TAC' => 'Tanger Automotive City',
            'TFZ' => 'Tanger Free Zone',
            'AFZ' => 'Atlantic Free Zone Kenitra',
            'CAS' => 'Casablanca',
            'NOU' => 'Nouaceur',
        ],
        'US' => [
            'NY'  => 'New York',
            'TX'  => 'Texas',
            'MA'  => 'Massachusetts',
            'MI'  => 'Michigan Detroit',
            'NC'  => 'North Carolina',
            'PA'  => 'Pennsylvania',
        ],
        'EU' => [
            'DE'  => 'Germany',
            'FR'  => 'France',
            'NL'  => 'Netherlands',
            'CZ'  => 'Czech Republic',
            'PL'  => 'Poland',
            'RO'  => 'Romania',
        ],
        'GB' => [
            'ENG' => 'England',
            'SCT' => 'Scotland',
            'WLS' => 'Wales',
        ],
        'EG' => [
            'CAI' => 'Cairo',
            'ALX' => 'Alexandria',
            'SUZ' => 'Suez',
            'OCT' => '6th of October City',
            'RAM' => '10th of Ramadan City',
        ],
        'GCC' => [
            'DXB' => 'Dubai',
            'AUH' => 'Abu Dhabi',
            'RUH' => 'Riyadh',
            'JED' => 'Jeddah',
            'DOH' => 'Doha',
        ],
    ];

    /**
     * Target verticals where OEMs outsource cable harness, PCB assembly,
     * and other EMS services that Starz Electronics provides.
     */
    private const TARGET_SECTORS = [
        'Automotive',
        'Aerospace',
        'Industrial',
        'Rail',
        'Renewables',
        'Medical',
        'Defense',
        'Telecom',
        'HVAC',
        'Marine',
        'Power Electronics',
        'Consumer Electronics',
        'Data Center',
        'Energy Storage',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private CompanyRepository $companyRepo,
        private GoogleDorkService $googleDork,
        private LoggerInterface $logger,
        private ?CountryService $countryService = null,
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
     * Discover companies across all target sectors.
     *
     * @param string|null $regionCode  Limit to a specific region (e.g. 'MA', 'US', 'EU', 'GB').
     *                                  null = all regions.
     */
    public function discoverAllSectors(?string $regionCode = null): array
    {
        $allCompanies = [];

        $regions = $regionCode
            ? [$regionCode => self::TARGET_LOCATIONS[$regionCode] ?? []]
            : self::TARGET_LOCATIONS;

        foreach (self::TARGET_SECTORS as $sector) {
            foreach ($regions as $locations) {
                foreach ($locations as $code => $name) {
                    $companies = $this->discoverCompanies($sector, $name);
                    $allCompanies = array_merge($allCompanies, $companies);

                    // Sleep to avoid rate limiting
                    sleep(2);
                }
            }
        }

        return $allCompanies;
    }

    /**
     * Get all target location names for a specific region (or all regions).
     */
    public static function getTargetLocations(?string $regionCode = null): array
    {
        if ($regionCode) {
            return self::TARGET_LOCATIONS[$regionCode] ?? [];
        }

        $all = [];
        foreach (self::TARGET_LOCATIONS as $locations) {
            foreach ($locations as $code => $name) {
                $all[$code] = $name;
            }
        }
        return $all;
    }

    /**
     * Resolve region code and country from a location string.
     *
     * Returns ['region' => 'US', 'country' => 'US', 'city' => 'Houston'] etc.
     */
    private function resolveGeo(?string $location): array
    {
        $geo = ['region' => null, 'country' => null, 'city' => null];
        if (!$location || !$this->countryService) {
            return $geo;
        }

        $code = $this->countryService->normalizeRegionCode($location);
        if ($code === null) {
            return $geo;
        }

        // Map meta-codes to region tags
        $regionMap = [
            'EU_REGION' => 'EU',
            'GCC_REGION' => 'GCC',
        ];

        // GCC individual country codes → region GCC
        $gccCountries = ['AE', 'SA', 'QA', 'KW', 'OM', 'BH'];

        if (isset($regionMap[$code])) {
            $geo['region'] = $regionMap[$code];
        } elseif (in_array($code, $gccCountries, true)) {
            $geo['region'] = 'GCC';
            $geo['country'] = $code;
        } elseif (strlen($code) === 2) {
            // Standard ISO country code
            $geo['country'] = $code;
            // Derive region from country
            $euCountries = ['DE','FR','IT','ES','NL','BE','AT','PL','CZ','SE','DK','FI','NO','RO','HU','PT','GR','IE','SK','BG','HR','SI','LT','LV','EE','LU','MT','CY'];
            if ($code === 'MA') {
                $geo['region'] = 'MA';
            } elseif ($code === 'US') {
                $geo['region'] = 'US';
            } elseif ($code === 'GB') {
                $geo['region'] = 'GB';
            } elseif ($code === 'EG') {
                $geo['region'] = 'EG';
            } elseif (in_array($code, $euCountries, true)) {
                $geo['region'] = 'EU';
            }
        } elseif (str_starts_with($code, 'US-')) {
            $geo['region'] = 'US';
            $geo['country'] = 'US';
        }

        // Use the original location string as the city hint
        // (only when it looks like a city name, not a region label)
        $loc = trim($location);
        if (!in_array(strtolower($loc), ['europe','gcc','gulf','united states','united kingdom'], true)) {
            $geo['city'] = $loc;
        }

        return $geo;
    }

    /**
     * Extract the root domain from a website URL for deduplication.
     *
     * e.g. "https://www.equinix.com" → "equinix.com"
     */
    private function extractRootDomain(?string $website): ?string
    {
        if (!$website) {
            return null;
        }
        $host = parse_url($website, PHP_URL_HOST);
        if (!$host) {
            return null;
        }
        return preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * Save discovered companies to database, avoiding duplicates.
     *
     * Deduplication is now performed on the website domain (primary)
     * AND the company name (case-insensitive fallback).  Country,
     * city and region are populated from the search location.
     */
    private function saveDiscoveredCompanies(array $discoveredData, string $sector, ?string $location): array
    {
        $savedCompanies = [];
        $geo = $this->resolveGeo($location);

        // Pre-fetch existing website domains for fast dedup
        $existingDomains = [];
        foreach ($this->companyRepo->findAll() as $existing) {
            $d = $this->extractRootDomain($existing->getWebsite());
            if ($d) {
                $existingDomains[$d] = true;
            }
        }

        foreach ($discoveredData as $data) {
            // Skip if not a proper company data array
            if (!is_array($data) || !isset($data['name'])) {
                continue;
            }

            $name = trim($data['name']);
            $website = $data['website'] ?? null;
            $rootDomain = $this->extractRootDomain($website);

            // --- Domain-level deduplication ---
            if ($rootDomain && isset($existingDomains[$rootDomain])) {
                $this->logger->debug('Company domain already exists', ['domain' => $rootDomain]);
                continue;
            }

            // --- Case-insensitive name deduplication ---
            $existingByName = $this->companyRepo->createQueryBuilder('c')
                ->where('LOWER(c.name) = :name')
                ->setParameter('name', strtolower($name))
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($existingByName) {
                $this->logger->debug('Company name already exists', ['name' => $name]);
                continue;
            }

            // Create new company with full geo data
            $company = new Company();
            $company->setName($name);
            $company->setSector($sector);
            $company->setPhysicalSite($location);
            $company->setWebsite($website);
            $company->setPipelineStage('Prospect');
            $company->setAccountTier('C');
            $company->setSourceNotes('Auto-discovered by webcrawler on ' . date('Y-m-d'));
            $company->setCreatedAt(new \DateTime());
            $company->setUpdatedAt(new \DateTime());

            // Populate geographic fields
            if ($geo['region']) {
                $company->setRegion($geo['region']);
            }
            if ($geo['country']) {
                $company->setCountry($geo['country']);
            }
            if ($geo['city']) {
                $company->setCity($geo['city']);
            }

            // ── Enrichment data from verify pipeline ──────────────
            // The verify pipeline extracts LinkedIn URL, description,
            // phone, address, and email from homepage + LinkedIn.
            if (!empty($data['linkedin_url'])) {
                $company->setLinkedinCompanyUrl($data['linkedin_url']);
            }
            if (!empty($data['description'])) {
                $company->setNotes($data['description']);
            }
            if (!empty($data['address'])) {
                $company->setAddress($data['address']);
            }
            if (!empty($data['phone'])) {
                // Store phone in notes if no direct phone field on Company
                // Prepend to existing notes
                $existingNotes = $company->getNotes() ?? '';
                $phoneNote = '📞 ' . $data['phone'];
                if (!empty($data['email'])) {
                    $phoneNote .= ' | ✉ ' . $data['email'];
                }
                $company->setNotes(
                    $phoneNote . ($existingNotes ? "\n" . $existingNotes : '')
                );
            } elseif (!empty($data['email'])) {
                $existingNotes = $company->getNotes() ?? '';
                $company->setNotes(
                    '✉ ' . $data['email'] . ($existingNotes ? "\n" . $existingNotes : '')
                );
            }

            $this->em->persist($company);
            $savedCompanies[] = $company;

            // ── Auto-create Contact entities from enrichment ─────
            // The verify pipeline extracts named contacts (person name,
            // email, phone, LinkedIn URL) from Schema.org data, vCards,
            // and LinkedIn profile links on the company's homepage.
            if (!empty($data['contacts'])) {
                foreach ($data['contacts'] as $contactData) {
                    if (empty($contactData['first_name']) || empty($contactData['last_name'])) {
                        continue;
                    }
                    $contact = new Contact();
                    $contact->setCompany($company);
                    $contact->setFirstName(trim($contactData['first_name']));
                    $contact->setLastName(trim($contactData['last_name']));
                    if (!empty($contactData['email'])) {
                        $contact->setEmail($contactData['email']);
                    }
                    if (!empty($contactData['phone'])) {
                        $contact->setPhone($contactData['phone']);
                    }
                    if (!empty($contactData['job_title'])) {
                        $contact->setJobTitle($contactData['job_title']);
                    }
                    if (!empty($contactData['linkedin_url'])) {
                        $contact->setLinkedInUrl($contactData['linkedin_url']);
                    }
                    $contact->setSource('Webcrawler');
                    $contact->setCreatedAt(new \DateTime());
                    $this->em->persist($contact);

                    $this->logger->info('Auto-created contact', [
                        'name' => $contactData['first_name'] . ' ' . $contactData['last_name'],
                        'company' => $name,
                    ]);
                }
            }

            // Track domain so subsequent results in the same batch are deduped
            if ($rootDomain) {
                $existingDomains[$rootDomain] = true;
            }

            $this->logger->info('New company discovered', [
                'name' => $name,
                'website' => $website,
                'region' => $geo['region'],
                'country' => $geo['country'],
            ]);
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
            $website = $this->googleDork->findCompanyWebsite(
                $company->getName(),
                $company->getPhysicalSite()
            );
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
