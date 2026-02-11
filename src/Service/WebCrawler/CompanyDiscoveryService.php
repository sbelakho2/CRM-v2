<?php

namespace App\Service\WebCrawler;

use App\Entity\Company;
use App\Entity\Contact;
use App\Repository\CompanyRepository;
use App\Service\CompetitorLearnerService;
use App\Service\ContactEnrichmentService;
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
            'TAC' => 'Tanger Automotive City Morocco',
            'TFZ' => 'Tanger Free Zone Morocco',
            'AFZ' => 'Atlantic Free Zone Kenitra Morocco',
            'CAS' => 'Casablanca Morocco',
            'NOU' => 'Nouaceur Morocco',
        ],
        'US' => [
            'NY'  => 'New York',
            'TX'  => 'Texas',
            'MASS' => 'Massachusetts',  // NB: 'MA' is Morocco's ISO code — don't use it here
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
        'TN' => [
            'TUN' => 'Tunis Tunisia',
            'SFX' => 'Sfax Tunisia',
            'SOU' => 'Sousse Tunisia',
            'BIZ' => 'Bizerte Tunisia',
            'NAB' => 'Nabeul Tunisia',
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
        private ?CompetitorLearnerService $competitorLearner = null,
        private ?CompanyClassifierService $classifier = null,
        private ?ContactEnrichmentService $contactEnrichment = null,
    ) {}

    /**
     * Discover companies in a specific sector and location
     */
    public function discoverCompanies(?string $sector, ?string $location = null): array
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
     * Get target region labels for UI filters.
     *
     * @return array<string, string>
     */
    public static function getTargetRegionLabels(): array
    {
        return [
            'MA' => 'Morocco',
            'US' => 'United States',
            'EU' => 'Europe',
            'GB' => 'United Kingdom',
            'TN' => 'Tunisia',
            'EG' => 'Egypt',
            'GCC' => 'GCC',
        ];
    }

    /**
     * Resolve region code and country from a location string.
     *
     * Returns ['region' => 'US', 'country' => 'US', 'city' => 'Houston'] etc.
     */
    private function resolveGeo(?string $location): array
    {
        $geo = ['region' => null, 'country' => null, 'city' => null];
        if (!$location) {
            return $geo;
        }

        // Try CountryService first
        $code = null;
        if ($this->countryService) {
            $code = $this->countryService->normalizeRegionCode($location);
        }

        // Fallback: keyword-based geo resolution when CountryService
        // doesn't recognize the location string
        if ($code === null) {
            $code = $this->fallbackGeoResolve($location);
        }
        if ($code === null) {
            // Last resort: set city to the location string itself
            $geo['city'] = trim($location);
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
            // Countries with their own region code
            $ownRegion = ['MA', 'TN', 'US', 'GB', 'EG', 'DE', 'FR', 'PL', 'NL', 'IT', 'ES', 'BE', 'AT', 'CZ', 'SE', 'DK', 'FI', 'NO', 'CH', 'RO', 'HU', 'PT', 'IE'];
            if (in_array($code, $ownRegion, true)) {
                $geo['region'] = $code;
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
            // Strip country names from city string to avoid redundancy
            // e.g. "Tunis Tunisia" → "Tunis", "Casablanca Morocco" → "Casablanca"
            $countryNames = [
                'morocco', 'maroc', 'tunisia', 'tunisie', 'egypt',
                'germany', 'france', 'spain', 'italy', 'netherlands',
                'united arab emirates', 'uae', 'saudi arabia',
                'qatar', 'bahrain', 'oman', 'kuwait',
                'poland', 'czech republic', 'czechia', 'sweden',
                'austria', 'belgium', 'romania', 'denmark',
                'portugal', 'hungary', 'greece',
            ];
            $cityClean = $loc;
            foreach ($countryNames as $cn) {
                $cityClean = trim(preg_replace('/\b' . preg_quote($cn, '/') . '\b/i', '', $cityClean));
            }
            // Remove trailing commas/spaces left after stripping
            $cityClean = trim($cityClean, ", \t\n\r\0\x0B");
            $geo['city'] = !empty($cityClean) ? $cityClean : $loc;
        }

        return $geo;
    }

    /**
     * Keyword-based fallback when CountryService can't normalize the location.
     */
    private function fallbackGeoResolve(string $location): ?string
    {
        $loc = strtolower(trim($location));
        $map = [
            // Morocco
            'morocco' => 'MA', 'maroc' => 'MA', 'casablanca' => 'MA', 'tangier' => 'MA',
            'tanger' => 'MA', 'kenitra' => 'MA', 'rabat' => 'MA', 'fes' => 'MA',
            'agadir' => 'MA', 'free zone' => 'MA', 'atlantic free zone' => 'MA',
            'nouaceur' => 'MA', 'midparc' => 'MA',
            // US
            'texas' => 'US', 'california' => 'US', 'new york' => 'US', 'michigan' => 'US',
            'detroit' => 'US', 'boston' => 'US', 'houston' => 'US', 'chicago' => 'US',
            'north carolina' => 'US', 'pennsylvania' => 'US', 'massachusetts' => 'US',
            'usa' => 'US', 'united states' => 'US', 'ohio' => 'US', 'florida' => 'US',
            'georgia' => 'US', 'virginia' => 'US', 'connecticut' => 'US',
            // EU
            'germany' => 'DE', 'france' => 'FR', 'netherlands' => 'NL', 'spain' => 'ES',
            'italy' => 'IT', 'poland' => 'PL', 'czech' => 'CZ', 'sweden' => 'SE',
            'austria' => 'AT', 'belgium' => 'BE', 'romania' => 'RO', 'denmark' => 'DK',
            'europe' => 'EU_REGION',
            // Tunisia
            'tunisia' => 'TN', 'tunisie' => 'TN', 'tunis' => 'TN', 'sfax' => 'TN',
            'sousse' => 'TN', 'monastir' => 'TN', 'bizerte' => 'TN', 'gabes' => 'TN',
            'nabeul' => 'TN', 'ben arous' => 'TN', 'enfidha' => 'TN',
            // Egypt
            'egypt' => 'EG', 'cairo' => 'EG', 'alexandria' => 'EG', 'suez' => 'EG',
            '6th of october' => 'EG', '10th of ramadan' => 'EG',
            // GCC
            'dubai' => 'AE', 'abu dhabi' => 'AE', 'uae' => 'AE', 'sharjah' => 'AE',
            'riyadh' => 'SA', 'jeddah' => 'SA', 'saudi' => 'SA',
            'doha' => 'QA', 'qatar' => 'QA', 'bahrain' => 'BH', 'kuwait' => 'KW',
            'oman' => 'OM', 'muscat' => 'OM', 'gcc' => 'GCC_REGION', 'gulf' => 'GCC_REGION',
        ];

        foreach ($map as $keyword => $code) {
            if (str_contains($loc, $keyword)) {
                return $code;
            }
        }
        return null;
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
    private function saveDiscoveredCompanies(array $discoveredData, ?string $sector, ?string $location): array
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
            // Clean trailing punctuation from company name
            $name = rtrim($name, ' ,;:.-|/\\');
            $company->setName($name);
            $company->setSector($sector ?? ($data['sector'] ?? null));
            $company->setPhysicalSite($location);
            $company->setWebsite($website);
            $company->setPipelineStage('Prospect');
            $company->setAccountTier('C');
            $company->setCompanyStatus(Company::STATUS_DISCOVERED);
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

            // ── iter15: Validate address doesn't belong to a wrong country ──
            // Reject addresses mentioning cities/countries clearly outside the
            // target region (e.g. "Niamey" for Morocco, "Romania" for Tunisia)
            $addrText = strtolower($company->getAddress() ?? '');
            if (!empty($addrText) && $geo['region']) {
                $wrongCountryCities = $this->getWrongCountryIndicators($geo['region']);
                foreach ($wrongCountryCities as $indicator) {
                    if (str_contains($addrText, $indicator)) {
                        $this->logger->debug('Address-region mismatch — clearing address', [
                            'company' => $name,
                            'address' => $company->getAddress(),
                            'region' => $geo['region'],
                            'matched' => $indicator,
                        ]);
                        $company->setAddress(null); // Clear bad address
                        break;
                    }
                }
            }

            // Use country_hint from enrichment to override geo country
            if (!empty($data['country_hint'])) {
                $hint = strtoupper(trim($data['country_hint']));
                // ISO 2-letter code or full name → set country
                if (strlen($hint) === 2) {
                    $company->setCountry($hint);
                } elseif ($this->countryService) {
                    $hintCode = $this->countryService->normalizeRegionCode($hint);
                    if ($hintCode && strlen($hintCode) === 2) {
                        $company->setCountry($hintCode);
                    }
                }
            }
            // Fallback address: construct from city + country if no street address
            // Use full country name to avoid ambiguity (e.g. 'MA' = Morocco ISO, not Massachusetts)
            if (empty($company->getAddress()) && ($company->getCity() || $company->getCountry())) {
                $countryLabel = $company->getCountry();
                if ($countryLabel && strlen($countryLabel) === 2 && $this->countryService) {
                    $countryLabel = $this->countryService->getRegionName($countryLabel) ?? $countryLabel;
                }
                $parts = array_filter([$company->getCity(), $countryLabel]);
                $company->setAddress(implode(', ', $parts));
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

                    // ── Validate this looks like a REAL PERSON name ──
                    // The Schema.org extraction sometimes pulls company names,
                    // department names, or job titles instead of person names.
                    // Use the classifier's isLikelyPersonName() to reject garbage.
                    $firstName = trim($contactData['first_name']);
                    $lastName = trim($contactData['last_name']);

                    // Reject fake "General Contact" fallback entries
                    if ($firstName === 'General' && $lastName === 'Contact') {
                        continue;
                    }

                    if ($this->classifier !== null) {
                        if (!$this->classifier->isLikelyPersonName($firstName, $lastName, $name)) {
                            $this->logger->debug('Rejected non-person contact name', [
                                'first' => $firstName,
                                'last' => $lastName,
                                'company' => $name,
                            ]);
                            continue;
                        }
                    } else {
                        // Fallback: basic rejection without classifier
                        $fullContactName = strtolower($firstName . ' ' . $lastName);
                        $companyLower = strtolower($name);
                        // Reject if contact name looks like the company name
                        $similarity = 0;
                        similar_text($fullContactName, $companyLower, $similarity);
                        if ($similarity > 60) {
                            $this->logger->debug('Rejected contact name (too similar to company name)', [
                                'name' => $firstName . ' ' . $lastName,
                                'company' => $name,
                            ]);
                            continue;
                        }
                        // Reject obvious non-person words
                        $badWords = ['technologies', 'technology', 'systems', 'electronics',
                                     'corporation', 'group', 'editorial', 'staff', 'manager',
                                     'director', 'support', 'sales', 'expo', 'llc', 'inc', 'ltd'];
                        $skipContact = false;
                        foreach ($badWords as $bw) {
                            if (strtolower($firstName) === $bw || strtolower($lastName) === $bw) {
                                $skipContact = true;
                                break;
                            }
                        }
                        if ($skipContact) {
                            $this->logger->debug('Rejected contact name (non-person word)', [
                                'name' => $firstName . ' ' . $lastName,
                            ]);
                            continue;
                        }
                    }

                    $contact = new Contact();
                    $contact->setCompany($company);
                    $contact->setFirstName($firstName);
                    $contact->setLastName($lastName);
                    if (!empty($contactData['email'])) {
                        // ── Last-resort: reject banking/consulting email domains ──
                        $emailDomain = strtolower(explode('@', $contactData['email'])[1] ?? '');
                        $rejectDomains = [
                            'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com',
                            'socgen.com', 'bnpparibas.com', 'credit-agricole.com', 'cic.fr',
                            'hsbc.com', 'barclays.com', 'jpmorgan.com', 'goldmansachs.com',
                            'morganstanley.com', 'ubs.com', 'db.com', 'citi.com', 'rbc.com',
                            'tpicap.com', 'oddo-bhf.com', 'natixis.com', 'lazard.com',
                            'pwc.com', 'deloitte.com', 'ey.com', 'kpmg.com', 'mckinsey.com',
                            'bcg.com', 'bain.com', 'accenture.com', 'capgemini.com',
                        ];
                        if (in_array($emailDomain, $rejectDomains, true)) {
                            $this->logger->debug('Rejected contact with banking/consulting email', [
                                'name' => $firstName . ' ' . $lastName,
                                'email' => $contactData['email'],
                                'company' => $name,
                            ]);
                            continue;
                        }
                        // Strip mismatched email but KEEP the contact (name+title still valuable)
                        $emailOk = true;
                        if (!empty($website)) {
                            $companyHost = parse_url($website, PHP_URL_HOST);
                            if ($companyHost) {
                                $companyDom = strtolower(preg_replace('/^www\./', '', $companyHost));
                                $companyRoot = implode('.', array_slice(explode('.', $companyDom), -2));
                                $emailRoot = implode('.', array_slice(explode('.', $emailDomain), -2));
                                if ($companyRoot !== $emailRoot) {
                                    $cw = explode('.', $companyRoot)[0];
                                    $ew = explode('.', $emailRoot)[0];
                                    if (strlen($cw) >= 3 && strlen($ew) >= 3
                                        && !str_contains($ew, $cw)
                                        && !str_contains($cw, $ew)) {
                                        $this->logger->debug('Stripped mismatched email from contact (keeping contact)', [
                                            'name' => $firstName . ' ' . $lastName,
                                            'email' => $contactData['email'],
                                            'company_domain' => $companyDom,
                                        ]);
                                        $emailOk = false;
                                    }
                                }
                            }
                        }
                        if ($emailOk) {
                            $contact->setEmail($contactData['email']);
                        }
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

            // ── Flush PER company to avoid cascading EM failures ─
            try {
                $this->em->flush();
            } catch (\Throwable $flushErr) {
                $this->logger->warning('Flush failed for {company}: {msg}', [
                    'company' => $name,
                    'msg' => $flushErr->getMessage(),
                ]);
                // Detach the failed entity and remove from saved list
                try {
                    $this->em->detach($company);
                } catch (\Throwable $ignore) {}
                array_pop($savedCompanies);
            }
        }

        // ── Auto-enrich contacts for newly discovered companies ──
        // Trigger the contact enrichment pipeline to find decision-maker
        // contacts (procurement, engineering, executive) for each company.
        // This runs Google→LinkedIn, website scraping, and email discovery.
        // DISABLED: subpage scraping + fallback contacts now handle this
        // without burning Google API quota. Re-enable for premium enrichment.
        if (false && $this->contactEnrichment !== null && !empty($savedCompanies)) {
            $this->logger->info('Starting auto-contact-enrichment for {n} new companies', [
                'n' => count($savedCompanies),
            ]);

            $totalCreated = 0;
            foreach ($savedCompanies as $company) {
                try {
                    $enrichResult = $this->contactEnrichment->enrichCompanyContacts($company, 5);
                    $totalCreated += $enrichResult['created'] ?? 0;
                    $this->logger->info('Auto-enriched contacts for {company}', [
                        'company' => $company->getName(),
                        'created' => $enrichResult['created'] ?? 0,
                        'sources' => $enrichResult['sources'] ?? [],
                    ]);
                    // Small delay to respect API rate limits
                    usleep(500000); // 500ms
                } catch (\Throwable $e) {
                    $this->logger->warning('Auto-enrichment failed for {company}: {msg}', [
                        'company' => $company->getName(),
                        'msg' => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('Auto-contact-enrichment completed', [
                'companies' => count($savedCompanies),
                'total_contacts_created' => $totalCreated,
            ]);
        }

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

    /**
     * Get a list of location keywords that indicate an address is in the
     * WRONG country/region for the current search.
     *
     * iter15: prevents addresses like "Road, Niamey" (Niger) from being
     * assigned to a Moroccan company, or "Str. Piatra Craiului" (Romania)
     * to a Tunisian company.
     */
    private function getWrongCountryIndicators(string $region): array
    {
        // Each region defines cities/country names that definitely DON'T belong
        return match ($region) {
            'MA' => ['niamey', 'niger', 'nigeria', 'lagos', 'senegal', 'dakar',
                      'ivory coast', 'abidjan', 'cameroon', 'douala',
                      'romania', 'bucharest', 'india', 'mumbai', 'delhi',
                      'china', 'beijing', 'shanghai', 'pakistan', 'karachi',
                      'lebanon', 'beirut', 'mudu town', 'norway', 'oslo',
                      'netherlands', 'hyderabad', 'foshan', 'guangdong',
                      'new york', 'california', 'texas', 'florida'],
            'TN' => ['niamey', 'niger', 'nigeria', 'lagos', 'senegal', 'dakar',
                      'romania', 'bucharest', 'piatra', 'cluj', 'timisoara',
                      'india', 'mumbai', 'delhi', 'china', 'beijing',
                      'pakistan', 'karachi', 'cameroon', 'douala',
                      'france', 'marseille', 'paris', 'lyon', 'toulouse', 'bordeaux',
                      'switzerland', 'zurich', 'bern', 'geneva', 'basel',
                      'germany', 'berlin', 'munich', 'hamburg', 'frankfurt',
                      'spain', 'madrid', 'barcelona',
                      'new york', 'california', 'texas', 'florida',
                      'uae', 'abu dhabi'],
            'EG' => ['niamey', 'niger', 'nigeria', 'lagos', 'senegal', 'dakar',
                      'romania', 'bucharest', 'india', 'mumbai', 'delhi',
                      'china', 'beijing', 'shanghai', 'pakistan', 'karachi',
                      'cameroon', 'douala', 'morocco', 'casablanca',
                      'new york', 'california', 'texas', 'florida', 'hawaii',
                      'turkey', 'istanbul', 'ankara'],
            'GCC' => ['niamey', 'niger', 'nigeria', 'lagos', 'senegal', 'dakar',
                       'romania', 'bucharest', 'india', 'mumbai',
                       'pakistan', 'karachi', 'cameroon'],
            'DE' => ['china', 'beijing', 'shanghai', 'shenzhen', 'india', 'mumbai',
                      'delhi', 'pakistan', 'karachi', 'nigeria', 'lagos',
                      'new york', 'california', 'texas', 'florida',
                      'morocco', 'casablanca', 'tunisia', 'egypt', 'cairo'],
            'FR' => ['china', 'beijing', 'shanghai', 'shenzhen', 'india', 'mumbai',
                      'delhi', 'pakistan', 'karachi', 'nigeria', 'lagos',
                      'new york', 'california', 'texas', 'florida',
                      'morocco', 'casablanca', 'tunisia', 'egypt', 'cairo'],
            'PL' => ['china', 'beijing', 'shanghai', 'shenzhen', 'india', 'mumbai',
                      'delhi', 'pakistan', 'karachi', 'nigeria', 'lagos',
                      'new york', 'california', 'texas', 'florida',
                      'morocco', 'casablanca', 'tunisia', 'egypt', 'cairo'],
            'NL' => ['china', 'beijing', 'shanghai', 'shenzhen', 'india', 'mumbai',
                      'delhi', 'pakistan', 'karachi', 'nigeria', 'lagos',
                      'new york', 'california', 'texas', 'florida',
                      'morocco', 'casablanca', 'tunisia', 'egypt', 'cairo'],
            // All other EU countries share a common wrong-country list
            'IT', 'ES', 'BE', 'AT', 'CZ', 'SE', 'DK', 'FI', 'NO', 'CH',
            'RO', 'HU', 'PT', 'IE' => ['china', 'beijing', 'shanghai', 'shenzhen', 'india', 'mumbai',
                      'delhi', 'pakistan', 'karachi', 'nigeria', 'lagos',
                      'new york', 'california', 'texas', 'florida',
                      'morocco', 'casablanca', 'tunisia', 'egypt', 'cairo'],
            default => [],
        };
    }
}
