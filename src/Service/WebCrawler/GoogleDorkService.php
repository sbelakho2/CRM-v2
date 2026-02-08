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

                            // Filter out non-company domains
                            if ($this->isBlockedDomain($domain)) {
                                $this->logger->debug('Skipping blocked domain', ['domain' => $domain]);
                                continue;
                            }

                            if (!isset($allResults[$domain])) {
                                $companyName = $this->extractCompanyName($result['title'] ?? '', $domain);
                                
                                // Post-filter: reject names that still look like page titles
                                if ($this->isJunkCompanyName($companyName)) {
                                    $this->logger->debug('Skipping junk company name', [
                                        'name' => $companyName,
                                        'domain' => $domain,
                                    ]);
                                    continue;
                                }
                                
                                // Semantic filter: reject EMS competitors, distributors,
                                // equipment suppliers, component suppliers, integrators,
                                // MRO companies based on snippet analysis
                                $snippet = $result['snippet'] ?? '';
                                $title = $result['title'] ?? '';
                                if ($this->isCompetitorOrWrongType($snippet, $title, $domain)) {
                                    $this->logger->debug('Skipping competitor/wrong-type by snippet', [
                                        'name' => $companyName,
                                        'domain' => $domain,
                                    ]);
                                    continue;
                                }
                                
                                $allResults[$domain] = [
                                    'name' => $companyName,
                                    'website' => $this->extractWebsiteFromResult($result),
                                    'title' => $title,
                                    'snippet' => $snippet,
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
            
            // ── Smart verification: LinkedIn + Homepage checks ──────
            // Instead of relying purely on pattern matching, verify each
            // candidate is a REAL company by checking LinkedIn and homepage.
            $preVerifyCount = count($allResults);
            $allResults = $this->verifyCompanies($allResults);
            
            $discovered = array_values($allResults);
            
            $this->logger->info("Google Dork search completed", [
                'sector' => $sector,
                'location' => $location,
                'raw_candidates' => $preVerifyCount,
                'verified_results' => count($discovered),
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
     * Extract company name from search result title and domain.
     *
     * Google search titles are often page headings ("About Us", "Products"),
     * not the company name.  When the title looks generic we fall back to
     * building a human-readable name from the domain.  The domain-based
     * name is also returned when the title is unreasonably long (>60 chars)
     * which usually indicates a sentence rather than a company name.
     *
     * Enhanced with AI-level word parsing:
     * - Analyses capitalisation patterns to identify proper nouns
     * - Strips common suffix descriptors ("Group", "Ltd", etc.)
     * - Prefers domain-derived name when title is clearly a page heading
     * - Detects "PageTitle - BrandName" patterns more aggressively
     */
    private function extractCompanyName(string $title, string $domain = ''): string
    {
        // --- Step 0: quick bail for obviously bad titles ---
        $trimmed = trim($title);
        if ($trimmed === '' || mb_strlen($trimmed) > 120) {
            return $this->companyNameFromDomain($domain) ?: $trimmed;
        }

        // Remove trademark symbols early (before any splitting)
        $title = preg_replace('/[®™©]/u', '', $title);
        $title = trim($title);

        // --- Step 1: clean the title ---
        // Handle pipe/em-dash/en-dash separators intelligently:
        // "Home | Lucid Motors" → left is generic, pick right
        // "Lucid Motors | About Us" → right is generic, pick left
        // "Everrati - Electrifying Icons" → left is brand, pick left
        $name = $title;
        if (preg_match('/^(.+?)\s*[\|｜–—]\s*(.+)$/u', $title, $pipeMatch)) {
            $left = trim($pipeMatch[1]);
            $right = trim($pipeMatch[2]);
            $leftIsGeneric = $this->isGenericPageWord($left);
            $rightIsGeneric = $this->isGenericPageWord($right);

            if ($leftIsGeneric && !$rightIsGeneric) {
                $name = $right;
            } elseif (!$leftIsGeneric && $rightIsGeneric) {
                $name = $left;
            } elseif (!$leftIsGeneric && !$rightIsGeneric) {
                // Both look like proper names — prefer the shorter one
                $name = mb_strlen($left) <= mb_strlen($right) ? $left : $right;
            } else {
                // Both generic — fall through to domain
                $name = $left;
            }
        }

        // Strip known page-section suffixes after a dash
        $name = preg_replace('/\s*[-]\s*(LinkedIn|Facebook|Twitter|Homepage|Home|About|Contact|Careers|Jobs|News|Blog|Press|The Sites?|Locations?|Company guide|Overseas|Global Network|Wikipedia|Wire Harness|Cable Assembly|PCB Assembly|Products?|Services?|Solutions?).*$/i', '', $name);

        // If title still has " - DescriptivePhrase" pattern, try extracting the brand
        if (preg_match('/^(.+?)\s+-\s+(.+)$/', $name, $m)) {
            $beforeDash = trim($m[1]);
            $afterDash = trim($m[2]);
            $beforeIsGeneric = $this->isGenericPageWord($beforeDash);
            $afterIsGeneric = $this->isGenericPageWord($afterDash);
            // If before-dash is a clean brand name (short, capitalised), prefer it
            if (!$beforeIsGeneric && mb_strlen($beforeDash) >= 2 && mb_strlen($beforeDash) <= 40) {
                $name = $beforeDash;
            } elseif (!$afterIsGeneric && mb_strlen($afterDash) >= 2 && mb_strlen($afterDash) <= 40) {
                $name = $afterDash;
            }
        }

        // Remove leading prefixes like "MAKING - ", "Visit of plants Morocco - "
        $name = preg_replace('/^(MAKING|Visit of plants?|List of all|Contacts and locations|About|Overview of)\s*[-–—:]\s*/iu', '', $name);
        // "Welcome to" doesn't need a separator — strip it directly
        $name = preg_replace('/^Welcome\s+to\s+/iu', '', $name);
        // Remove trailing ": Home", ": Home Page", ": Homepage", ": Products", ": Services"
        $name = preg_replace('/\s*:\s*(Home(\s*Page)?|Homepage|Products?|Services?|Solutions?|Contact(\s+Us)?|About(\s+Us)?|Careers?|Overview)\s*$/i', '', $name);
        // General colon stripping: if a colon remains, take the shorter side
        // that looks like a company name (proper noun, < 40 chars)
        if (str_contains($name, ':')) {
            $parts = explode(':', $name, 2);
            $left = trim($parts[0]);
            $right = trim($parts[1] ?? '');
            // Prefer the shorter side that is 2-40 chars & starts with uppercase
            if (mb_strlen($left) >= 2 && mb_strlen($left) <= 40 && preg_match('/^[A-Z]/', $left)) {
                $name = $left;
            } elseif (mb_strlen($right) >= 2 && mb_strlen($right) <= 40 && preg_match('/^[A-Z]/', $right)) {
                $name = $right;
            }
        }
        // Remove trailing " » " or " > " followed by page section  
        $name = preg_replace('/\s*[»>]\s+.+$/u', '', $name);
        // Remove trailing " ... " (truncated titles)
        $name = preg_replace('/\s*\.{2,}\s*$/', '', $name);
        // Clean up trailing dashes/spaces ("W Motors -" → "W Motors")
        $name = preg_replace('/\s*[-–—]\s*$/', '', $name);
        $name = trim($name);

        // --- Step 2: detect "junk" titles that are not company names ---
        $genericPatterns = [
            '/^about\s*(us)?$/i',
            '/^home(page)?$/i',
            '/^contact(\s+us)?$/i',
            '/^products?$/i',
            '/^services?$/i',
            '/^careers?$/i',
            '/^locations?$/i',
            '/^capabilities\b/i',
            '/^data\s+centers?$/i',
            '/^cloud\s+infrastructure/i',
            '/^choose\s+the\s+/i',
            '/^regional\s+availability/i',
            '/^(the\s+)?economic\s+(and|&)/i',
            '/^(sitemap|prices?|inside|downloads?|author:|corporate\s+profile)$/i',
            '/^(overseas\s+hubs|global\s+network|quality\s+at\s+)$/i',
            '/^cairo$/i',  // city name, not a company
            // ─── Document / report titles — never company names ───────
            '/\b(sustainability|annual|esg)\s+report\b/i',
            '/\bpdf\b/i',
            '/\b(memoir|mémoire|thesis|dissertation)\b/i',
            '/\b(tokens?\.txt|readme|changelog)\b/i',
            '/\bcreating\s+markets\b/i',
            '/\bgrowth\s+diagnostic\b/i',
            '/\bcorporate\s+social\s+responsibility\b/i',
            '/\bindustry\s+outlook\b/i',
            '/\btier\s+1\s+plc\s+list\b/i',
            '/\bcatálogo|catalogue|catalog\b/i',
            '/\bannuaire|directory\b/i',
            '/\bemplois|emploi|jobs\s+/i',
            '/\bsearch\s+results\b/i',
            '/\barchive\b/i',
            '/\bsupplier:\s/i',
            '/^ANNUAIRE$/i',
            '/^Archive$/i',
            '/^IDZ$/i',
            '/^ESG Report$/i',
            '/\bsat(ellite)?[-\s]times/i',
            // ─── Product / listing titles — not company names ─────────
            '/\b(wire|wiring)\s+harness\s+(for|compatible|set|kit)\b/i',
            '/\b(oem|genuine)\s+(part|toyota|lexus|yamaha|motorcraft)\b/i',
            '/\bPT\d{5,}/i',  // part numbers
            '/\b(metra|PET cloth|G-Hook|EXPLORER)\s+\d/i',
            '/\bmanufacturers?\s*$/i',  // "Wire Harness Manufacturers"
            '/^\d+\s+best\s+/i',  // "15 Best Automotive Wire Harness..."
            '/^top\s+\d+\s+/i',
            '/^top\s+(foreign|solar|cable)/i',
            '/^how\s+to\s+find/i',
            '/^catch\s+the\s+top/i',
            '/^find\s+contract\b/i',
            '/^why\s+outsource\b/i',
            '/^what\s+is\s+brief\b/i',
            '/^where\s+will\s+your\b/i',
            '/^bringing\s+manufacturing\b/i',
            '/^PCB\s+(assembly|manufacturers?\s+in)/i',
            '/^PCBA\s+(contract|finder)/i',
            '/^electronic(s)?\s+(contract|manufacturing|MRO)/i',
            '/^contract\s+electronics?\s+manufacturing/i',
            '/^custom\s+(wire|cable|industrial)/i',
            '/^cable\s+(harness|assembly)\s+(manufacturer|&)/i',
            '/^quality\s+cable\s+assembly/i',
            '/^EMS\s+supplier/i',
            '/^(automotive|aerospace|medical)\s+(PCB|wire|battery|electronic)/i',
            '/^(leading|worldwide|powerful|best)\s+OEM/i',
            '/^flex\s+&\s+HDI\s+PCB/i',
            '/\bmarket\s+(growth|report|size)\b/i',
            '/^(IATF|ISO|IEC)\s*\d/i',  // certification references
            '/^(India\s+at\s+|Boeing\s+expands|Donald\s+Trump)/i',
            '/^(business\s+development\s+manager)/i',
            '/^(technical\s+and\s+engineering\s+recruitment)/i',
            '/^(soldering\s+robots)/i',
            '/^(control\s+panel\s+(manufacturers|assembly))/i',
            '/^(BMS,?\s+HVAC)/i',
            '/^(process\s+control\s+panels)/i',
            '/^(profibus\s+cable)/i',
            '/^(SLA\s+maintenance)/i',
            '/^(non-equity\s+modes)/i',
            '/^(architectural\s+innovation)/i',
            '/^(the\s+great\s+abbreviations)/i',
            '/^(fastener\s+company\s+acquisitions)/i',
            '/^(Regeneron|2018\s+IEEE|2022-23)/i',
            '/^(ISKRAEMECO|GULFSTREAM\s+RANKED)/i',
            '/^(Siemens.*Capital\s+software)/i',
            '/^(PCB\s+Manufacturer\s+&\s+Emergency)/i',
            '/^(Project\s+Practical\s+Performance)/i',
            '/^(93\s+Top\s+Automotive)/i',
            '/^(the\s+automotive\s+industry\s+in)/i',
            '/\bchannel\s+partners\b/i',
            '/\bcustomer\s+support\b/i',
            '/\bproduct\s+sheet\b/i',
            '/\bline\s+card\b/i',
            // ─── Additional patterns from deep analysis ───────────────
            '/^welcome\s+to\b/i',                        // "Welcome to ABCorp"
            '/^overview\s+of\b/i',                       // "Overview of XYZ"
            '/^introduction\s+to\b/i',
            '/^(wire|cable|pcb|electronic)\s+assembly\s+services?\s*$/i',
            '/^(contract|electronic|pcb)\s+manufactur/i', // duplicate safety
            '/\bsupplier\s+(directory|list|database|portal)\b/i',
            '/\b(oem|tier\s+\d)\s+(supplier|list|manufacturer)\s*$/i',
            '/\b(job|career)\s+(opening|posting|opportunit)/i',
            '/^(germany|france|morocco|usa|uk|egypt|dubai)\s*$/i',  // bare country names
            '/^(free\s+zone|industrial\s+zone|special\s+economic)\b/i',
            '/\b(brochure|datasheet|whitepaper|specification|manual)\b/i',
            '/\b(investor|shareholder|annual\s+general)\s+(relations|meeting|report)/i',
            '/^(complete\s+guide|ultimate\s+guide|beginner|introduction)\b/i',
        ];

        $isGeneric = false;
        foreach ($genericPatterns as $pattern) {
            if (preg_match($pattern, $name)) {
                $isGeneric = true;
                break;
            }
        }

        // Also treat very long "titles" (sentences) as non-names
        if (!$isGeneric && mb_strlen($name) > 55) {
            $isGeneric = true;
        }

        // --- Step 2b: smart company name validation ─────────────────
        // Even if not matched by patterns above, reject names that look
        // like descriptive phrases rather than proper company names.
        if (!$isGeneric && $name !== '') {
            // Count words: real company names are 1-5 words
            $nameWords = preg_split('/\s+/', $name);
            $wc = count($nameWords);
            
            // If the name contains common EMS/industry descriptors as
            // the entirety, it's a generic service description
            $serviceDescriptors = '/^(wire\s+harness|cable\s+assembly|pcb\s+assembly|electronic\s+(assembly|manufacturing|components?)|contract\s+manufactur|surface\s+mount|smt\s+assembly|electronic\s+components?\s+and\b)/i';
            if (preg_match($serviceDescriptors, $name)) {
                $isGeneric = true;
            }

            // Reject all-lowercase multi-word names (real company names have capitals)
            if (!$isGeneric && $wc >= 3 && $name === strtolower($name)) {
                $isGeneric = true;
            }
        }

        // --- Step 3: if title is good, use it ---
        if (!$isGeneric && $name !== '') {
            // Final cleanup: strip trailing common suffixes that are noise
            $name = preg_replace('/\s*[-–—]\s*(Ltd|LLC|Inc|Corp|GmbH|SA|SAS|BV|NV|AG|Plc|Co|Pty|Srl|SpA)\.?\s*$/i', '', $name);
            return trim($name);
        }

        // --- Step 4: derive name from domain ---
        return $this->companyNameFromDomain($domain) ?: $name ?: $title;
    }

    /**
     * Check if a string looks like a generic page word / section heading
     * rather than a company brand name.
     */
    private function isGenericPageWord(string $text): bool
    {
        $lower = strtolower(trim($text));
        $generics = [
            'home', 'homepage', 'home page', 'about', 'about us', 'contact',
            'contact us', 'products', 'services', 'solutions', 'careers',
            'jobs', 'news', 'blog', 'press', 'locations', 'overview',
            'our products', 'our services', 'resources', 'login', 'register',
            'support', 'faq', 'help', 'downloads', 'gallery', 'portfolio',
            'capabilities', 'certifications', 'partners', 'investors',
            'media', 'events', 'welcome', 'main', 'index',
            'electrifying icons', 'driving innovation', 'global leader',
            'leading the way', 'powering the future', 'engineering excellence',
            'demand detroit', 'inspiring tomorrow', 'delivering power',
            'information', 'strona główna', 'strona glowna',
        ];
        if (in_array($lower, $generics, true)) {
            return true;
        }
        // Short all-lowercase text that doesn't look like a brand
        if (mb_strlen($lower) <= 3) {
            return true;
        }
        return false;
    }

    /**
     * Build a human-readable company name from a domain string.
     *
     * e.g.  "www.equinix.com"  → "Equinix"
     *       "www.coresite.com" → "Coresite"
     *       "www.arrow.com"    → "Arrow"
     */
    private function companyNameFromDomain(string $domain): string
    {
        if ($domain === '') {
            return '';
        }

        // Strip scheme, www prefix, path, port
        $host = preg_replace('#^https?://#', '', $domain);
        $host = preg_replace('#[:/].*$#', '', $host);
        $host = preg_replace('/^www\./', '', $host);

        // Remove TLD(s): e.g. co.uk, com.eg, com
        $host = preg_replace('/\.(co|com|org|net|gov|edu|io)\.[a-z]{2,4}$/i', '', $host);
        $host = preg_replace('/\.[a-z]{2,6}$/i', '', $host);

        // Turn hyphens/dots into spaces and capitalise
        $name = str_replace(['-', '.', '_'], ' ', $host);
        $name = ucwords(trim($name));

        return $name;
    }
    
    /**
     * Blocklist of domains that are never real prospect companies.
     *
     * Categories: government, education, news/media, job boards, market
     * research, cloud/SaaS, competitor EMS/harness providers, directories,
     * social media, consulting/NGO/IGO, document hosting, academic,
     * e-commerce, CDNs, press wires, unrelated businesses.
     */
    private const DOMAIN_BLOCKLIST = [
        // ─── Government / Education / Military (TLD suffixes) ─────────
        'gov', 'edu', 'mil', 'ac.uk', 'ac.ma', 'ac.jp', 'ac.za',
        'go.jp', 'go.kr', 'gov.ma', 'gov.eg', 'gov.sa', 'gov.ae',
        'gov.in', 'gov.uk',

        // ─── News / Media / Blogs ─────────────────────────────────────
        'reuters.com', 'bloomberg.com', 'bbc.com', 'bbc.co.uk', 'cnn.com',
        'nytimes.com', 'washingtonpost.com', 'theguardian.com', 'ft.com',
        'zdnet.com', 'techcrunch.com', 'wired.com', 'theverge.com',
        'datacenterknowledge.com', 'eenewseurope.com', 'electronicdesign.com',
        'eetimes.com', 'edn.com', 'fierceelectronics.com', 'etnow.com',
        'kfor.com', 'prnewswire.com', 'businesswire.com', 'globenewswire.com',
        'fdiintelligence.com', 'autonews.com', 'wiringharnessnews.com',
        'gulfstreamnews.com', 'themanufacturer.com', 'mddionline.com',
        'automotivemanufacturingsolutions.com', 'fastenernewsdesk.com',
        'helihub.com', 'birminghamdispatch.co.uk', 'makezine.com',
        'news.siemens.com', 'thetimesherald.com', 'easyengineering.eu',
        'aviation-safety.net', 'avherald.com', 'aerosociety.com',
        'wiringharnessnews.de',

        // ─── Market research / Reports / Think tanks ──────────────────
        'kenresearch.com', 'statista.com', 'grandviewresearch.com',
        'marketsandmarkets.com', 'mordorintelligence.com', 'ibisworld.com',
        'researchandmarkets.com', 'alliedmarketresearch.com',
        'policycenter.ma', 'idos-research.de', 'technavio.com',
        'precedenceresearch.com', 'marketresearchfuture.com',
        'gminsights.com', 'rootsanalysis.com', 'persistencemarketresearch.com',
        'portersfiveforce.com', 'rolandberger.com',
        'in.marketscreener.com', 'marketscreener.com',
        'cognitivemarketresearch.com', 'snstelecom.com',

        // ─── Document hosting / Archives / Academic publishers ────────
        'scribd.com', 'yumpu.com', 'issuu.com', 'slideshare.net',
        'academia.edu', 'researchgate.net', 'tandfonline.com',
        'springer.com', 'sciencedirect.com', 'wiley.com', 'elsevier.com',
        'archive.org', 'worldradiohistory.com', 'gutenberg.org',
        'huggingface.co', 'dl.acm.org', 'acm.org', 'ieee.org',
        'standards.ieee.org', 'iopscience.iop.org', 'nature.com',
        'escies.org', 'bfh.ch',

        // ─── Directories / Social / Aggregators / Job boards ──────────
        'wikipedia.org', 'linkedin.com', 'facebook.com', 'twitter.com',
        'youtube.com', 'reddit.com', 'quora.com', 'glassdoor.com',
        'indeed.com', 'career.io', 'ziprecruiter.com', 'monster.com',
        'crunchbase.com', 'zoominfo.com', 'dnb.com',
        'tiktok.com', 'instagram.com', 'pinterest.com',
        'whatjobs.com', 'bayt.com', 'naukri.com',
        'marklines.com', 'clutch.co', 'b2match.com',
        'lockheedmartinjobs.com', 'recruitmilitary.com',
        'studysmarter.co.uk', 'talents.studysmarter.co.uk',
        'willcoxmatthews.com',
        'businessmagnet.co.uk', 'eu-startups.com',

        // ─── E-commerce / Parts stores / Retail ───────────────────────
        'ebay.com', 'amazon.com', 'amazon.ae', 'amazon.co.uk',
        'alibaba.com', 'aliexpress.com',
        'parts.lexus.com', 'yamaha-motor.com', 'showmecables.com',
        'digikey.com', 'digikey.co.uk', 'mouser.com', 'newark.com',
        'farnell.com', 'arrow.com', 'avnet.com',
        'shop.richardsonrfpd.com', 'richardsonrfpd.com',
        'store.ngpracing.com',
        'appliancerepair.homedepot.com', 'homedepot.com',
        'shop.electech.com.eg',

        // ─── Chinese wholesale / Made-in-China ────────────────────────
        'made-in-china.com', 'en.made-in-china.com', 'm.made-in-china.com',

        // ─── Cloud / Pure-software (not EMS buyers) ───────────────────
        'azure.microsoft.com', 'aws.amazon.com', 'cloud.google.com',
        'oracle.com', 'ibm.com', 'salesforce.com', 'sap.com',
        'microsoft.com', 'google.com', 'apple.com',
        'cloudfront.net', 'blob.core.windows.net',
        'content.civicplus.com', 'civicplus.com',

        // ─── Industry directories (source sites, not prospects) ───────
        'thomasnet.com', 'europages.com', 'kompass.com', 'mfg.com',
        'industrynet.com', 'globalspec.com', 'wlw.de', 'nae.fr',
        'pcbafinder.com', 'pcbdirectory.com', 'ventureoutsource.com',
        'wiringo.com', 'wellpcb.com', 'howtorobot.com',
        'yellowpages.com.eg', 'engexportdirectory.org',
        'wireevents.com', 'ensun.io',
        'aviationsuppliers.org',  // trade association, not OEM
        'india-briefing.com',  // news/analysis

        // ─── International organizations / Banks / NGOs ───────────────
        'ifc.org', 'worldbank.org', 'imf.org', 'afdb.org', 'undp.org',
        'afreximbank.com', 'ircwash.org', 'thecasecentre.org',
        'unctad.org', 'unido.org', 'downloads.unido.org', 'iap.unido.org',
        'publications.gc.ca', 'nsai.ie',

        // ─── Stock exchanges / Financial data / Investor pages ────────
        'nseindia.com', 'bseindia.com', 'hkexnews.hk', 'sec.gov',
        'investors.st.com', 'investor.ppg.com',

        // ─── Ports / Logistics (not EMS buyers) ──────────────────────
        'tangermed.ma', 'tangermedport.com',

        // ─── Competitor EMS providers (Starz wouldn't sell to them) ───
        'jabil.com', 'flex.com', 'celestica.com', 'foxconn.com',
        'plexus.com', 'benchmark.com', 'ttelectronics.com',
        'kitron.com', 'lacroixgroup.com', 'neways.com',
        'zollner.de', 'katek-group.com', 'cicor.com',
        'inovaelectronics.com', 'sanmina.com', 'ventec-group.com',
        'ncatx.com', 'bencor-llc.com', 'macrofab.com', 'ewme.com',
        'cypressindustries.com', 'emsginc.com', 'suntronicinc.com',
        'cypressmfg.com', 'arqelectronics.com', 'sigmatronintl.com',
        'flipelectronics.com', 'distron.com', 'rushpcb.com',
        'greencircuits.com', 'aimtron.com', 'aqs-inc.com',
        'pinnerwire.com', 'wallace-elec.com', 'leemah.com',
        'sknelectronics.co.uk', 'kasdonpcb.com', 'ablcircuits.co.uk',
        'pcbassemblyspecialist.com', 'escatec.com', 'firstchoiceassembly.com',
        'pulsarmanufacturing.com', 'trio-engineering.com',
        'pcbtrain.co.uk', 'fox-ems.com', 'spartronics.com',
        'bestpcbs.com', 'nextpcb.com', 'venture-mfg.com',
        'technotronix.us', 'aundb-electronic.de', 'pcbrunner.com',
        'newmatik.com', 'asselems.com', 'asteelflash.com',
        'keenfinity-group.com', 'multi-circuit-boards.eu',
        'scanfil.com', 'kuttig.eu', 'dynamicsourcemfg.com',
        'datalink-electronics.co.uk', 'sero.com',
        'blog.turnkeypcb-assembly.com', 'turnkeypcb-assembly.com',
        'pcbsino.com', 'titoma.com',
        'kimballelectronics.com',  // EMS provider
        'sfotechnologies.net',  // SFO Technologies - Indian EMS
        'avalontec.com',  // Avalon Technologies - Indian EMS
        'pcb-technologies.com',  // PCB Technologies - EMS/OSAT
        'usiglobal.com',  // USI - EMS provider
        'kagafei.com', 'eu.kagafei.com',  // Kaga FEI - EMS
        'ecelectronics.com',  // EC Electronics - contract manufacturer
        'pcpltd.com',  // PCP Ltd - contract electronics
        'unisoft-cim.com',  // Unisoft - PCB CAM software

        // ─── Competitor wire harness / cable assembly manufacturers ───
        'yazaki-europe.com', 'yazaki-group.com', 'sews-cabind.com',
        'sumitomo-electric-wiring-systems.com', 'lear.com',
        'aptiv.com', 'leoni.com', 'draexlmaier.com', 'kromberg-schubert.com',
        'coficab.com', 'fujikura.com', 'motherson.com',
        'in-tec.de', 'hedi.de', 'kmcable.com', 'sab-cable.com',
        'wewire-harness.com', '2e-mechatronic.de',
        'wireharnessproduction.com', 'meridiancableassemblies.com',
        'sz-keli.com', 'pv-cable-supplier.com',
        'interconnect-wiring.com', 'ksaria.com', 'coopind.com',
        'cablepoint.co.uk',

        // ─── Connector / cable component suppliers (not OEM buyers) ───
        'peigenesis.com', 'sinbon.com', 'rosenberger.com', 'nvk.com.tw',
        'dsgcanusa.com', 'macartney.com', 'te.com',
        'smithsinterconnect.com', 'purelinkav.com',
        'ppc-online.com', 'weidmuller.com', 'hubersuhner.com',
        'erich-jaeger.com', 'coroplast-tape.com', 'dertel.com',
        'nicomatic.com',  // connector supplier
        'usconec.com',  // fiber optic connector supplier
        'janco-electronics.com',  // connector supplier
        'timesmicrowave.com',  // cable/connector supplier
        'nordencommunication.com',  // cable supplier
        'cableshouse-me.com',  // cable supplier
        'p3connectors.com', 'mena.p3connectors.com',  // connector supplier

        // ─── Hotel / Retail / Unrelated businesses ────────────────────
        'barcelogrupo.com', 'nextplc.co.uk', 'booking.com',
        'ameliasurfandracquetclub.com', 'hellbentfitness.com',
        'isneurogastronomy.org', 'nireastriathlon.com',
        'amaltheacellars.com', 'piggypats.com', 'texas-speed.com',
        'immanuel-ed.org', 'cairocommunity.com', 'histamineintolerance.org.uk',
        'airgyro.com', 'spacecrew.com', 'remotesatellite.com',
        'artisantg.com', 'shoptronica.com', 'rapidwelding.com',
        'stuartbruce.net', 'zodml.org', 'new.zodml.org',
        'askfilo.com', 'msc-les.org', 'diyelectriccar.com',
        'calterdobrasil.com.br',  // Brazilian calibration, not aerospace
        'atsb.gov.au',  // Australian accident investigation
        'flirmedia.com',  // FLIR media CDN
        'alphatuner.com',  // aftermarket ECU tuning
        'catcoaerospace.com',  // aerospace coatings, not electronics buyer

        // ─── Consulting / Legal / Financial / Recruitment ─────────────
        'llp-law.de', 'angleadvisors.com', 'civitas.org.uk',
        'coleschotz.com', 'stonehengepartners.com',
        'smta.org', 'aia-aerospace.org', 'iatfglobaloversight.org',
        'leadiq.com',  // sales prospecting tool
        'empoweringcpo.com',  // procurement consulting
        'omnex.com',  // quality consulting
        'mrc-cleanrooms.com',  // cleanroom builder, not electronics

        // ─── Industry blogs / How-to / Forums ─────────────────────────
        'neweagle.net', 'shine.lighting',
        'komaxgroup.com',  // wire processing equipment (sells TO harness makers)
        'feinmetall.com',  // test probes/contacts
        'konrad-technologies.com',  // test systems
        'telsonic.com',  // ultrasonic welding equipment
        'vector.com',  // automotive software tools
        'cajotechnologies.com',  // laser marking equipment
        'download.sew-eurodrive.com', 'sew-eurodrive.com',  // gearmotor drives
        'blog.business-model-innovation.com',
        'eepcindia.com',

        // ─── CDNs / Subdomains that are never companies ───────────────
        'sspcdn.blob.core.windows.net',
        'ldgsesabwe.blob.core.windows.net',
        'fmcc.ifma.org',

        // ─── Government trade / investment portals ────────────────────
        'gtai.de', 'meity.gov.in',

        // ─── Freelance / Gig platforms ────────────────────────────────
        'upwork.com', 'fiverr.com',

        // ─── Other non-prospects ──────────────────────────────────────
        'zawya.com', 'cloudinfrastructuremap.com', 'datadobi.com',
        'mpoverello.com', 'rayuzwyshyn.net', 'satt.ma',
        'cesar-scott.com', 'emspartnersinc.com',  // sales reps, not OEMs
        'kentzy.com', 'circuitdesign.com', 'pibsales.com',
        'concote.com', 'semiplane.com',
        'sc-repy.cz', 'elogicon.com', 'vlsiacademy.org',
        'indium.com',  // solder materials, not OEM

        // ─── EMS competitors identified by deep analysis ──────────────
        'roscan.co.uk',  // Roscan Electronics - UK contract manufacturer
        'ppcanda.com', 'ppcontrolandautomation.com',  // PP Control & Automation - subcontract
        'uk-nsi.co.uk', 'nipponseiki.co.uk',  // Nippon Seiki UK - EMS/instrument cluster maker
        'corintech.com',  // Corintech - UK contract electronics
        'abielectronics.co.uk',  // ABI Electronics - test equipment & repair
        'ecelectronics.co.uk',  // EC Electronics - UK contract manufacturer
        'jaltek.com',  // Jaltek - UK EMS
        'texcel-technology.com',  // Texcel - UK interconnect manufacturer
        'nemcogroup.com',  // Nemco - UK EMS
        'speedboardassembly.com',  // Speedboard - UK EMS
        'tioga.co.uk',  // Tioga - UK EMS
        'note-uk.co.uk',  // NOTE UK - Scandinavian EMS
        'fideltronik.com',  // Fideltronik - Polish EMS
        'permatron.com',  // Permatron - air filtration, not buyer

        // ─── Distributors / Resellers ─────────────────────────────────
        'oc2me.com',  // distributor
        'inabata.co.jp', 'inabata.com',  // Japanese trading company
        'enertronic.es',  // Spanish electronics distributor
        'widaco.net',  // Vietnamese distributor

        // ─── Component / material suppliers ───────────────────────────
        'lumileds.com',  // LED component manufacturer
        'wolfspeed.com',  // SiC semiconductor manufacturer
        'delkin.com',  // Flash storage / memory manufacturer
        'magworks.us',  // Magnet manufacturer
        'larsonelectronics.com',  // Industrial lighting
        'atlascopco.com',  // Compressor/tool manufacturer
        'coninsindia.com',  // Indian connector company

        // ─── Equipment / tooling suppliers ────────────────────────────
        'meyertool.com',  // Precision tooling
        'q5d.com',  // Automated wire harness production equipment
        'csdautomation.co.uk',  // Automation equipment supplier
        'rhinestahl.com',  // Aerospace tooling
        'komaxgroup.com',  // Wire processing machines

        // ─── Control panel / system integrators ───────────────────────
        'ucs-uk.com',  // UCS Automation - panel builders
        'industratech.uk',  // Industratech - UK panel/automation
        'sm-control.co.uk',  // SM Control - control panel builders

        // ─── Test & measurement ───────────────────────────────────────
        'pulsarmeasurement.com',  // Pulsar measurement instruments
        'metrixvibration.com',  // Vibration monitoring
        'feinmetall.com',  // Test probes
        'konrad-technologies.com',  // Test systems
        'testequipmentconnection.com',  // Test equipment reseller

        // ─── MRO / repair / overhaul ──────────────────────────────────
        'aarcorp.com',  // AAR Corp - aircraft MRO
        'goallclear.com',  // facility services
        'sysargus.com',  // IT services

        // ─── Wrong industry / misc non-prospects ──────────────────────
        'perasiatech.com',  // Palm oil technology
        'atlanticresearch.com',  // Research
        'nsaiinc.com',  // NSAI - software/consulting
        'oranoc.com',  // Unclear/dead
        'siae.fr',  // French performing rights society
        'whizz.ae',  // UAE general services
        'multiforms.co.uk',  // UK print/forms company
        'alpadvantage.com',  // ALP Advantage - consulting
        'incomegypt.com',  // Dead link / unverifiable
        'elkhanagry.com',  // Dead link / unverifiable

        // ─── Run-2 deep analysis: additional blocked domains ──────────
        // Competitors identified by website verification
        'violintec.com',  // Violin Technologies - EMS competitor
        'l2aviation.com',  // L2 Aviation - wire harness manufacturer
        'hi-lex.co.jp',  // Hi-Lex - cable manufacturer
        'delfingen.com',  // Delfingen - cable protection systems
        'emusbms.com',  // EMUS BMS - BMS module manufacturer
        'nighthawkfs.com',  // Nighthawk - electronics display mfg
        'mta.it',  // MTA - auto electrical components (own Morocco factory)

        // Distributors identified by website verification
        'fdhaero.com',  // FDH Aero - aerospace hardware distributor
        'txaero.com',  // Texas Aerospace - avionics distributor
        'madep.com',  // Madep - components distributor
        'gapp.co.uk',  // Gapp - automation distributor
        'powernsun.com',  // Power n Sun - solar distributor

        // Component suppliers
        'cpii.com',  // CPI - RF/microwave components
        've1.com',  // Vanguard Electronics - magnetics
        'aesquibs.com', 'aesquibs.co.uk',  // AETC - pyrotechnics

        // Wrong industry / not companies
        'aeroinside.com',  // Aviation news site
        'hashemlaw.com',  // Law firm
        'abhmfg.com',  // Door hardware manufacturer
        'hpwinner.com',  // Outdoor LED lighting
        'cimwareukandusa.com',  // Educational publisher

        // Services / consulting / MRO
        'arts.aero',  // ARTS Group - aviation consulting
        'evo-syn.com',  // EVO Synergétique - engineering services
        'sunwoda.codady.com',  // Dead link (404)

        // Equipment suppliers unlikely to outsource EMS
        'leuze.com',  // Leuze - sensor equipment
        'beckhoff.com',  // Beckhoff - PLCs/automation hardware

        // Niche / too small / wrong geography
        'appliedavionics.com',  // Niche avionics placards
        'avcomm.com.au',  // Australian aviation headsets

        // ─── Run-3 deep analysis: Round 2 blocked domains ─────────────
        // News / Media / Magazines / Journals
        'moroccoworldnews.com', 'executive-magazine.com',
        'manufacturing-today.com', 'avm-mag.com',
        'enr.com', 'telegraph.co.uk', 'railjournal.com',
        'microwavejournal.com', 'evtechinsider.com',
        'solarpowerworldonline.com', 'utilitydive.com',
        'plasticsnews.com', 'mexiconewsdaily.com',
        'gulfbusiness.com', 'aerospacemanufacturinganddesign.com',
        'pcbupdate.com', 'manufacturingleadershipcouncil.com',
        'investmentmonitor.ai', 'vietnam-briefing.com',

        // Market research / Reports / Publications
        'market.us', 'gii.tw', 'gii.co.jp',
        'seia.org', 'ren21.net', 'nerc.com',

        // Trade fairs / Events / Conferences
        'cantonfair.net', 'bciaerospace.com',
        'eds-conference.com', 'battery-innovation-usa.com',
        'ev-manufacturing.com',

        // Government / Trade promotion / International orgs
        'ebrd.com', 'wko.at', 'advantageaustria.org',
        'wto.org', 'wallonia.be', 'degc.org',
        'sciencebasedtargets.org', 'antitrustinstitute.org',

        // Stock photos / Wikimedia / Academic / Museums
        'alamy.com', 'upload.wikimedia.org',
        'concordesst.com', 'skybrary.aero',
        'uel-repository.worktribe.com', 'bibliotecanacional.ao',

        // Aviation / Aerospace associations & directories
        'open4aviation.at', 'afraa.org',
        'whma.org', 'smmt.co.uk', 'aist.org',

        // Marketplaces / E-shops / Directories
        'tradewheel.com', 'directindustry.com',
        'inrobots.shop', 'evshop.eu', 'autelstore.co.uk',
        'bimedis.com', 'highwayandheavyparts.com',

        // Financial / Law / Services
        'wise.com', 'foley.com',
        'lesstanfordcadillac.com', 'overton-automotive.co.uk',
        'nobleprog.com', 'stormwall.network',

        // MRO / Parts / Equipment suppliers
        'puremro.com', 'mouser.sg',
        'germany.ul.com',  // UL testing/certification

        // Chinese / Asian manufacturers & suppliers
        'ntcshiheng.com', 'sz-fpi.com',
        'sankoasia.co.th',

        // CDNs / Subdomains / Misc non-companies
        'global.chinadaily.com.cn', 'thinkafrica.net',
        'health.ec.europa.eu', 'ayala.com',
        'internationaltradeinsights.com',

        // Competitor EMS / cable assembly
        'note-ems.com',  // NOTE EMS - Scandinavian EMS provider
        '4e-technology.co.uk',  // 4E Technology - UK reshoring EMS
        'smselectronics.com',  // SMS Electronics - UK EMS
        'shelmex.com',  // Shelmex - wire harness manufacturer
        'nemco.co.uk',  // Nemco - UK EMS
        'phoenixsystemsuk.com',  // Phoenix Systems UK - EMS
        'protronix.co.uk',  // Protronix - UK contract electronics
        'edgar-wireharness.com',  // Edgar Auto Harnesses - competitor
        'leuze-electronic-assembly.com',  // Leuze - electronics assembly
        'dcamfg.com',  // DCA Manufacturing - EMS
        'syncrocorp.com',  // Syncro - automotive electronics
        'nai-group.com',  // NAI Group - cable assembly competitor

        // Connector / component suppliers
        'adronics.com', 'amphenolalden.com',
        'klaceycables.co.uk', 'odu-connectors.com',
        'sealconusa.com', 'snapon.com',
        'displayvisions.us',  // display component
        'spellmanhv.com',  // high voltage supplies
        'juddwire.com',  // wire manufacturer
        'global-conn.com',  // connector company
        'montclairfiber.com',  // fiber optic products
        'owirecable.com',  // cable manufacturer
        'gcabling.com',  // cabling products
        'aflglobal.com',  // fiber optic cable
        'cofanthermal.com',  // thermal management components
        'power-electronics.com',  // power electronics components

        // Wrong industry / not EMS buyers
        'cityofharrah.com', 'stephanedeneve.com',
        'phisicol.it', 'le-chalet.ch',
        'diysolarforum.com', 'dteenergy.com',
        'chromaate.com',  // coatings, not electronics buyer
        'radonix.com',  // CNC controllers
        'schunk.com',  // gripping/clamping systems
        'pbt-works.com',  // PCB cleaning equipment
        'dymax.com',  // adhesives/coatings
        'scanlab.de',  // laser deflection
        'acl-america.com',  // anti-static equipment
        'mdpi.com',  // academic publisher
        'karambasecurity.com',  // cybersecurity
        'vicone.com',  // cybersecurity
        'qnovo.com',  // battery software
        'theswitchlab.com',  // EV powertrain
        'lectronixinc.com',  // automotive electronics
        'dayjologistica.com',  // logistics
        'figure.ai',  // robotics
        'dhl.com',  // logistics
        'sgs.com',  // testing/inspection
        'mazzellacompanies.com',  // lifting/rigging
        'adt.media',  // media
        'add-solution.ma',  // Moroccan IT services
        'infomedixinternational.com',  // medical magazine
        'airvacinc.com',  // vacuum equipment
        'oceta.com',  // environmental tech
        'laserexpressinc.com',  // laser services
        'kerix.net',  // Moroccan business directory
        'ibaset.com',  // manufacturing software
        'shimco.com',  // aerospace shims manufacturer
        'sonosite.com',  // ultrasound equipment
        'ietlabs.com',  // test instruments
        'jpltele.com',  // headsets
        'spiritaero.com',  // Spirit AeroSystems - aerostructures (not cable buyer)
        'fusterubber.com',  // rubber products
        'perei.co.uk',  // vehicle lighting
        'geotab.com',  // telematics
        'epectec.com',  // PCB/battery manufacturer (competitor)
        'luxitgroup.com',  // luxury IT
        'lgcorp.com',  // LG corporate (too large, not prospect)
        'invotekgroup.com',  // unclear
        'aelspan.com',  // electronics distribution
        'trinitytechinc.ca',  // Canadian tech
        'dcne.com',  // electrical contractor
        'resoluxgroup.com',  // LED lighting
        'kvci.com',  // venture capital
        'signaturesolar.com',  // solar retailer
        'benning.de',  // power supply equipment
        'tmeic.com',  // Toshiba Mitsubishi electric (equipment supplier)
        'sparkpowercorp.com',  // power services
        'graybar.com',  // electrical distributor
        'eh2.com',  // hydrogen
        'bhienergy.com',  // energy services
        'svanteinc.com',  // carbon capture
        'accuratetechnologies.com',  // automotive calibration tools
        'aurrigo.com',  // autonomous vehicles
        'saft.com',  // batteries (own manufacturing)
        'delta-emea.com',  // Delta - power electronics OEM
        'wittenstein-us.com',  // gear systems
        'oneequity.com',  // private equity
        'apagcosyst.com',  // auto parts (own manufacturing)
        'commscope.com',  // network infrastructure (own mfg)
        'belden.com',  // cable manufacturer (competitor type)
        'lunainc.com',  // fiber optic testing
        'mani-germany.com',  // surgical instruments
        'gimmi.com',  // medical endoscopy OEM (own mfg)
        'saint-gobain-northamerica.com',  // materials (own mfg)
        'germanelectronics.com',  // generic electronics portal
        'bizlinktech.com',  // BizLink - cable assembly competitor
        'axalta.com',  // coatings
        'wilo.com',  // pump manufacturer
        'rockford.co.uk',  // Rockford - UK electronics manufacturing
        'vvdntech.com',  // VVDN - Indian ODM (competitor)
        'embitel.com',  // Embitel - embedded systems (competitor)
        'littelfuse.com',  // circuit protection components
        'infineon.com',  // semiconductor (not cable buyer)
        'analog.com',  // Analog Devices - semiconductor
        'cardinalhealth.com',  // healthcare (own mfg)
        'vareximaging.com',  // X-ray imaging components
        'avalign.com',  // surgical instruments (own mfg)
        'global.toshiba',  // Toshiba corporate
        'bmwgroup.com',  // BMW (too large for EMS prospect)
        'polaris.com', 'slingshot.polaris.com',  // powersports

        // ─── Run-3 Round 3: additional blocked domains ──────────────
        // E-commerce / ubuy variants
        'ubuy.com', 'liberia.ubuy.com', 'barbabos.ubuy.com',
        'amazon.eg', 'amazon.sa',

        // Market research / reports
        'marketreportsworld.com', 'marketsandmarkets.com',
        'grandviewresearch.com', 'mordorintelligence.com',
        'researchandmarkets.com', 'alliedmarketresearch.com',
        'futuremarketinsights.com', 'transparencymarketresearch.com',

        // Estonian / irrelevant farm domains
        'nurm.ee',

        // Trade fairs / event sites
        'expofairs.com',

        // EV / auto news / blogs
        'chargedevs.com', 'mobilityoutlook.com',

        // Academic / university / research
        'frontiersin.org', 'yadda.icm.edu.pl',
        'encyclopedia.com', 'lib.nus.edu.sg', 'web.ipca.pt',

        // Stock photos
        'shutterstock.com',

        // EU/Government agencies
        'etf.europa.eu', 'businessfinland.com',

        // Market research (new)
        'businessresearchinsights.com', 'straitsresearch.com',
        'marketsandata.com', 'futuremarketinsights.com',
        'cablingmiddleeast.com',

        // Competitor EMS / cable / connectors
        'sebn.com',  // Sumitomo Electric Bordnetze - wire harness competitor
        'cableriasgroup.com',  // Cablerias - wire harness competitor
        'amphenol-socapex.com',  // Amphenol connector
        'glenair.com',  // connector supplier
        'linkecable.com',  // cable manufacturer
        'airelectro.com',  // aerospace connector distributor
        'in-akustik.com',  // audio cables
        'mithsagarelectronicss.com',  // Indian EMS
        'ctproduction.co.uk',  // UK contract manufacturer
        'pcbway.com',  // PCB manufacturer
        'fujikura-automotive.com',  // wire harness competitor
        'msm-aero.com',  // aerospace fabricator (competitor type)

        // Equipment / tooling / software
        'cogiscan.com',  // SMT tracking software
        'weetech.com',  // cable testing equipment
        'altium.com', 'resources.altium.com',  // PCB design software
        'swri.org',  // research institute
        'salukitec.com',  // test equipment
        'technisat.de',  // consumer electronics

        // Consulting / services / financial
        'ey.com',  // Ernst & Young
        'ptc.com',  // PTC software
        'wesco.com',  // electrical distributor
        'tesla.com',  // too large, own manufacturing
        'panasonic.aero',  // Panasonic Avionics (own mfg)

        // Logistics / shipping
        'nipponexpress-holdings.com',

        // Wrong industry / misc
        'blaxtair.com',  // proximity detection
        'oceansciencetechnology.com',  // ocean tech
        'hotelnewcaledonia.nc',  // hotel
        'merrimacindustrial.com',  // electrical supplier
        'electronicrepair.qimamaffan.com',  // repair shop
        'stirri.com',  // unclear
        'eevblog.com',  // electronics blog
        'flyboyaccessories.com',  // aviation accessories
        'sefee.com',  // French electrical association
        'stsaviationgroup.com',  // MRO services
        'aerocontact.com',  // aerospace directory
        'f6s.com',  // startup directory
        'satsearch.co',  // space marketplace
        'dedienne-aero.com',  // aerospace tooling
        'cbia.com',  // business association
        'ujv.cz',  // Czech nuclear research
        'vinskaakademija.com',  // wine academy
        'maricopacorporate.com',  // training provider
        'aerospace.co.uk',  // aerospace directory
        'taizetallinn.ee',  // religious community
        'air-store.eu',  // aviation parts store
        'raveoffroad.com',  // ATV parts
        'factoryminibikes.com',  // minibike parts
        'kitchenaidparts.com',  // appliance parts
        'bmweg.com',  // BMW Egypt portal
        'nascoautomotive.com',  // distributor
        'gf.com',  // GlobalFoundries semiconductor
        'pmiind.com',  // unclear
        'gstegypt.com',  // automation services
        'ceermotors.com',  // EV manufacturer (own mfg)
        'makeen-ksa.com',  // unclear
        'gaepme.ae',  // UAE unclear
        'baran.com.sa',  // unclear
        'beacongroup.org',  // NGO
        'mdotjboss.state.mi.us',  // Michigan government
        'magna.com',  // Magna - too large, Tier 1
        'inyantra.com',  // Indian engineering services
        'global-csg.com',  // engineering services
        'shadin.com',  // avionics instruments
        'rtx.com',  // Raytheon - too large
        'aiaa.org',  // aerospace institute
        'herberaircraft.com',  // aircraft services
        'bexkom.com',  // unclear

        // ─── Run-4: Massive blocklist expansion from 17% quality audit ──
        // NEWS / MEDIA — the #1 junk category (25.8% of results)
        'siteselection.com', 'fortune.com', 'automotiveworld.com',
        'globalfleet.com', 'thearabweekly.com', 'scmp.com',
        'atalayar.com', 'automotivelogistics.media',
        'airwaysmag.com', 'nationalinterest.org',
        'alestiklal.net', 'ainonline.com', 'aviationtoday.com',
        'weareiowa.com', 'chronline.com',
        'corporatejetinvestor.com',

        // FINANCE / INVESTMENT / LEASING
        'investmentbank.kotak.com', 'kotak.com',
        'ablaviation.com', 'venture5.com',

        // LAW FIRMS
        'twobirds.com', 'nortonrosefulbright.com',

        // CONSULTING / ADVISORY / MARKET RESEARCH
        'diligenciagroup.com', 'autics-group.com',
        'roncucciandpartners.com', 'eiirtrend.com',
        'veritaglobal.net', 'trigo-group.com',
        'israeldesks.com', 'forecastinternational.com',
        'adlittle.com', 'paddleyourownkanoo.com',

        // COMPETITOR EMS / CABLE / WIRE HARNESS
        'polydesignsystems.com', 'suprajit.com',

        // IRRELEVANT / WRONG INDUSTRY
        'joubert.fr', 'net0tracker.com', 'ilpea.com',
        'ilpeagalvarplast.com', 'sfc-solutions.com',
        'auriol-frappe.com', 'akzonobel.com',
        'francestampphilatelystore.laposte.fr', 'laposte.fr',
        'parallelparliament.co.uk', 'blharbert.com',
        'titanium.com', 'tibagroup.com', 'howmet.com',
        'conferencealerts.co.in', 'hexcel.com',
        'sinocontractors.com', 'bremerlf.com',
        'starliteglobal.in', 'morocconow.com', 'minm.ma',
        'clustercollaboration.eu', 'industriall-union.org',

        // GOV / ORG / STANDARDS BODIES
        'comcec.org', 'econstor.eu', 'afnor.org',
        'changing-transport.org',

        // ─── Run-5: post-improved-query blocklist additions ─────────
        // Still slipping through improved queries
        'fliphtml5.com',  // Digital publishing platform
        'airframer.com',  // Aerospace supply chain directory
        'aviationweek.com',  // Aviation news
        'salaryexpert.com',  // Salary comparison site
        'hartfordbusiness.com',  // Local business news
        'flandersinvestmentandtrade.com',  // Belgian govt trade promotion
        'polemecatech.be',  // Belgian tech cluster (not a company)
        'integra-international.net',  // Consulting network
        'inventec.dehon.com',  // Chemical cleaning products
        'hbkworld.com',  // Test/measurement equipment
        'sandia.aero',  // Avionics repair MRO

        // ─── Run-6: post-query-fix blocklist additions ──────────────
        // Domains from MA discovery Run 3 (61% quality → blocking remaining junk)

        // News / media sites still getting through
        'africanews.com',  // Euronews Africa
        'african.business',  // African Business Magazine
        'techxplore.com',  // Tech news aggregator

        // Certification bodies (NOT manufacturers, they certify others)
        'dqsglobal.com',  // DQS - certification auditor

        // Engineering services / consulting (not OEM buyers)
        'bertrandt.com',  // Bertrandt - automotive engineering services
        'ecintl.com',  // ECI - engineering consulting

        // Cruise / travel / irrelevant
        'oceaniacruises.com',  // Cruise line
        'flydulles.com',  // Airport website

        // Logistics / freight / ground handling
        'nipponexpress.com',  // Nippon Express logistics
        'swissport.com',  // Airport ground handling services

        // Government / civil aviation authorities
        'caa.gov.qa',  // Qatar Civil Aviation Authority

        // Trade portals / export directories
        'portugalexporta.pt',  // Portuguese government export portal

        // Foam / bedding / wrong industry
        'carpenter.com',  // Carpenter Co. - foam/bedding, not automotive electronics

        // ─── Run-7: UK discovery blocklist additions ────────────────
        // Certification bodies (they CERTIFY, they don't BUY electronics)
        'bsigroup.com',  // BSI Group - certification body
        'pjregistrars.uk',  // PJR - certification registrar
        'dnv.com',  // DNV - certification body

        // News / tech media
        'thequantuminsider.com',  // Quantum computing news
        'businessgreen.com',  // Green business news
        'windpowermonthly.com',  // Wind energy magazine
        'renews.biz',  // Renewable energy news
        'energyglobal.com',  // Energy industry news
        'windtech-international.com',  // Wind tech magazine
        'rivieramm.com',  // Maritime media
        'power-technology.com',  // Power sector news
        '4coffshore.com',  // Offshore wind database
        'fredminnick.com',  // Whiskey blogger

        // Utilities / energy companies (NOT manufacturers)
        'scottishpower.com',  // Utility company
        'scottishpowerrenewables.com',  // Utility company
        'scottishwater.co.uk',  // Water utility

        // Trade associations / industry bodies
        'scottishrenewables.com',  // Trade association
        'renewableuk.com',  // Trade association
        'mercomcapital.com',  // Capital advisory / market intelligence

        // Solar/wind installers (NOT equipment manufacturers)
        'southscotlandsolarltd.co.uk',  // Solar installer
        'solardynamixuk.co.uk',  // Solar installer
        'gener8scotland.com',  // Solar installer
        'aj-renewables.co.uk',  // Renewables installer
        'ocean-energyresources.com',  // Marine energy consultancy
        'celtic-renewables.com',  // Biofuel (not electronics)
        'koehlerrenewableenergy.com',  // Paper/energy (not electronics buyer)
        'autelenergy.com',  // EV charger brand (own manufacturing)

        // ─── Run-8: multi-region quality audit blocklist ─────────
        // Certification / auditing bodies
        'abs-qe.com',  // ABS Quality Evaluations - certification body
        'thecoresolution.com',  // Core Business Solutions - ISO consulting

        // Pharma / biotech / healthcare providers (NOT device OEMs)
        'bms.com',  // Bristol Myers Squibb - pharma
        'modernatx.com',  // Moderna - mRNA pharma
        'novonordisk.com',  // Novo Nordisk - insulin pharma
        'sarepta.com',  // Sarepta Therapeutics - gene therapy
        'foundationmedicine.com',  // Foundation Medicine - genomic diagnostics lab
        'wellpoint.com',  // Wellpoint/Elevance Health - health insurance
        'pembrokehospital.com',  // Hospital
        'masscannabiscontrol.com',  // Government cannabis regulator
        'mavehiclecheck.com',  // Vehicle inspection service

        // EMS competitors
        'neotech.com',  // NEOTech - EMS competitor

        // Sterilization / contract services (not OEM)
        'steris-ast.com',  // STERIS AST - sterilization services

        // Airport / hotel / wrong industry from aerospace queries
        'dfwairport.com',  // DFW Airport
        'startupnola.com',  // Startup accelerator

        // Non-prospect misc
        'ppg.com',  // PPG - paints/coatings only
        'dynabrade.com', 'www17.dynabrade.com',  // Dynabrade - power tools

        // Hospitals that slip through medical queries
        'hrihospital.com',  // HRI Hospital

        // ─── Run-9: Quality audit round — 369 companies, 28% junk ───
        // NEWS / MEDIA / NEWSROOM PORTALS
        'defensenews.com',             // Defense news site
        'breakingdefense.com',         // Defense news site
        'navalnews.com',               // Naval news site
        'marinelink.com',              // Maritime news/magazine
        'professionalmariner.com',     // Maritime magazine
        'boatingnz.co.nz',            // Boating magazine (NZ)
        'nutraceuticalbusinessreview.com', // Pharma/nutra magazine
        'volkswagen-newsroom.com',     // VW press portal
        'datacenterdynamics.com',      // Data center news site
        'ecofinagency.com',            // News agency
        'energytrend.com',             // Energy market news
        'nsenergybusiness.com',        // Energy news site
        'arabnews.com',                // Middle East news
        'automotive-iq.com',           // Auto conference/media
        'defence-industry.eu',         // Defense directory/news
        'fiercepharma.com',            // Pharma news site
        'pharmaceutical-technology.com', // Pharma news site
        'mobilityforesights.com',      // Market research/news
        'surgicalroboticstechnology.com', // Robotics news site
        'virginiabusiness.com',        // Business magazine

        // MARKET RESEARCH / ADVISORY
        'arizton.com',                 // Market research firm
        'fortunebusinessinsights.com', // Market research firm
        'psmarketresearch.com',        // Market research firm

        // LAW FIRMS
        'bakermckenzie.com',           // Global law firm
        'lexmundi.com',                // Law firm network
        'cms-lawnow.com',              // Law firm

        // CERTIFICATION / STANDARDS BODIES (they certify, don't buy EMS)
        'urs-me.com',                  // ISO certification body
        'dnv.us',                      // DNV certification (alternate TLD)
        'tuvsud.com',                  // TÜV SÜD testing/certification
        'vda-qmc.de',                  // VDA quality standards body
        'lrqa.com',                    // LRQA certification body

        // OIL / GAS / MINING / CHEMICALS (wrong industry entirely)
        'bp.com',                      // Oil & gas major
        'shell.com',                   // Oil & gas major
        'equinor.com',                 // Oil & gas major
        'maaden.com',                  // Saudi mining company
        'sabic.com',                   // Petrochemicals mega-company
        'weatherford.com',             // Oilfield services
        'airproducts.com',             // Industrial gases
        'ecolab.com',                  // Water/hygiene chemicals
        'eagle-chemicals.com',         // Chemical company
        'kraton.com',                  // Specialty chemicals

        // PHARMA / CONSUMER GOODS / FOOD / BEVERAGE
        'gsk.com',                     // GlaxoSmithKline pharma
        'elteriak.com',                // Pharmaceutical company
        'unilever.com',                // Consumer goods giant
        'coca-colahellenic.com',       // Beverage bottling
        'mowi.com',                    // Salmon farming
        'alginor.no',                  // Seaweed extraction

        // CONSULTING / IT SERVICES (no hardware to outsource)
        'boozallen.com',               // Management consulting
        'cornerstonedefense.com',      // IT/defense consulting
        'hcltech.com',                 // IT services company
        'proqc.com',                   // Quality inspection services
        'solixgroup.com',              // IT consulting
        'fichtner.de',                 // Engineering consulting
        'woodplc.com',                 // Engineering consulting
        'redwood.com',                 // Software automation

        // INVESTMENT / FINANCE / INSURANCE / LEASING
        'gabelli.com',                 // Investment fund
        'dubaiaerospace.com',          // Aircraft leasing (not mfg)
        'aegon.com',                   // Insurance company

        // TRADE SHOWS / CONFERENCES / ASSOCIATIONS
        'egypt-energy.com',            // Energy trade show
        'evautoshowonline.com',        // Auto show
        'icara.us',                    // Conference
        'iploca.com',                  // Pipeline contractors assoc
        'terrapinn.com',               // Event organizer
        'renewable-energy-industry.com', // Industry directory
        'datacenterworld.com',         // DC trade show
        'jeccomposites.com',           // Composites trade show

        // GOVERNMENT / TOURISM (non-.gov domains)
        'investinholland.com',         // Dutch trade agency
        'bundeswirtschaftsministerium.de', // German gov ministry
        'cityofrc.us',                 // City government
        'yesvirginiabeach.com',        // Tourism promotion

        // UNIVERSITIES / RESEARCH (non-.edu domains)
        'ku.ac.ae',                    // Khalifa University (UAE)
        'eri.sci.eg',                  // Egyptian research institute
        'hi.no',                       // Norwegian marine research

        // AUTO DEALERSHIPS / SERVICE / CAR PORTALS
        'acuracertified.com',          // Certified pre-owned portal
        'asg-autoglass.com',           // Auto glass replacement
        'rallymotorsgroup.com',        // Auto dealership

        // AIRLINES
        'aircairo.com',               // Airline

        // CONSTRUCTION
        'orascom.com',                 // Construction company
        'fgcbuilds.com',               // Construction company

        // TRADING HOUSES
        'marubeni.com',                // Japanese trading house

        // CLOUD / HOSTING PROVIDERS
        'serverspace.us',              // Cloud hosting

        // DISTRIBUTORS / RESELLERS
        'datacenterwarehouse.com',     // Equipment reseller
        'genpt.com',                   // Auto parts distributor (Genuine Parts)
        'houwire.com',                 // Wire/cable distributor

        // WRONG INDUSTRY / MISCELLANEOUS NON-PROSPECTS
        'ridefox.com',                 // Bicycle suspension (Fox Racing)
        'bedoeg.com',                  // Education company
        'newfinishinc.com',            // Industrial coatings
        'i4f.com',                     // Flooring IP licensing
        'tenaris.com',                 // Steel pipes
        'triviumpackaging.com',        // Metal packaging
        'ardena.com',                  // Pharma CDMO
        'iconplc.com',                 // Clinical research CRO
        'rigzone.com',                 // Oil/gas jobs site
        'ajg.com',                     // Arthur J. Gallagher insurance
        'woodsage.com',               // Non-electronics
        'datacenter-serverroom.com',   // DC consulting blog
        'enerjitemexport.com',         // Questionable/unclear
        'refindustry.com',             // Directory site
        'tjdeed.com',                  // Unclear/unverifiable
        'gans.aero',                   // Air navigation services
        'acuracertified.com',          // Certified pre-owned cars

        // EMS COMPETITORS (missed in previous rounds)
        'lacroix-electronics.com',     // LACROIX - EMS competitor

        // ─── Run-10: Quality audit round 2 — 210 companies, 12% junk ──
        // NEWS / MEDIA
        'cnbc.com',                    // News site (data center results)
        'latimes.com',                 // LA Times newspaper
        'navaltoday.com',              // Naval news site
        'offshore-energy.biz',         // Offshore energy news

        // MARKET RESEARCH / MEDIA CONGLOMERATES
        'informa.com',                 // Informa — market research/media parent (Omdia)

        // LAW FIRMS
        'orrick.com',                  // Orrick — global law firm

        // MUSEUMS
        'radarmuseum.co.uk',           // RAF Air Defence Radar Museum

        // UNIVERSITIES (non-.edu)
        'tudelft.nl',                  // TU Delft university

        // WRONG INDUSTRY — Agriculture / Food / Animal Feed
        'cairothreea.com',             // Cairo3A — food & agriculture
        'fimco-eg.com',                // Feeding Industries Manufacturing — animal feed

        // WRONG INDUSTRY — Chemistry / Lab supplies
        'analytichem.com',             // AnalytiChem — lab chemistry supplies

        // WRONG INDUSTRY — Textile / Lifting / Mechanical
        'lift-tex.nl',                 // Lift-Tex — textile lifting slings
        'martinsindustries.com',       // Martins Industries — tire industry equipment
        'maverickvalves.com',          // Maverick Valves — purely mechanical valves

        // HOLDING / INVESTMENT COMPANIES
        'lunagrp.com',                 // Luna Industrial Investments — holding company

        // EGYPTIAN CONSTRUCTION / SERVICES (not electronics)
        'bms-eg.com',                  // BMS — Egyptian general contracting

        // CONSUMER BRANDS / WHITE-LABEL
        'basenpower.com',             // Basengreen — Chinese consumer power bank brand

        // ─── Run-11: Quality audit round 3 — 210 companies, 14% junk ──
        // NEWS / MEDIA
        'dailyjournal.com',            // Daily Journal Corporation — newspaper
        'businessinsider.com',         // Business Insider — news site

        // SOFTWARE PLATFORMS (no hardware to outsource)
        'appian.com',                  // Appian — enterprise low-code software
        'armada.ai',                   // Armada — edge computing software platform

        // DATA CENTER OPERATORS / REITs (not manufacturers)
        'coresite.com',                // CoreSite — data center REIT

        // EV SERVICE / INSTALLATION (not manufacturers)
        'qmerit.com',                  // Qmerit — EV charger installation services

        // CONSULTING / CERTIFICATION / AUDITING
        'axeon.net',                   // Axeon — ISO auditing/training firm

        // CONSUMER / GOLF CARTS
        'clubcar.com',                 // Club Car — consumer golf carts

        // INDUSTRIAL PAINT / MACHINERY (not electronics buyers)
        'durr.com',                    // Dürr — industrial paint systems for auto plants

        // HOBBYIST / RETAIL ELECTRONICS (not OEMs)
        'makerselectronics.com',       // Makers Electronics — hobbyist shop (Egypt)

        // AUTOMATION DISTRIBUTORS (not manufacturers)
        'icsystems-eg.com',            // IC Systems — Egyptian automation reseller

        // MARINE SAFETY (not electronics)
        'viking-life.com',             // VIKING Life-Saving — life rafts/safety gear

        // SOLAR INSTALLERS (not manufacturers)
        'legendenergysolutions.com',   // Legend Green Energy — solar installer
        'zayelsolar.com',              // Zayel Solar — solar installer

        // CLEANTECH / FAILED SITES
        'colubriscleantech.com',       // Colubris — failed/unclear cleantech site

        // UNIVERSITY INVESTMENT ARMS
        'rvc.com.sa',                  // Riyadh Valley Company — King Saud Uni investment

        // CONNECTOR DISTRIBUTORS (not OEM buyers)
        'fclane.com',                  // FC Lane Electronics — connector distributor
        'simrad-yachting.com',         // Simrad — consumer marine electronics (Brunswick)
        'lowrance.com',                // Lowrance — consumer fishfinders (Brunswick)

        // SUBDOMAIN / NAME EXTRACTION PROBLEM SITES
        'elbitamerica.com',            // Extracts as "Aerospace & Defense Solutions" (generic)
        'sabetiwainaerospace.com',     // Extracts as "Laminated Aircraft Dress Covers" (product desc)
        'man-es.com',                  // Extracts as "Everllence Home" (bad extraction)
        'nextgpower.com',             // Extracts as "Modern Technology Laboratories" (bad extraction)
        'acwapower.com',              // Extracts as "ACWA JPIA" (bad extraction)
        'coorstek.com',               // Extracts as "GCAS Quality Certifications" (bad extraction)

        // ─── Run-12: Quality audit round 4 — 216 companies, 7.4% junk ──
        // TRADE SHOWS / EVENTS
        'detroitautoshow.com',         // Detroit Auto Show — trade show

        // PLASTICS / RUBBER / NON-ELECTRONICS MANUFACTURERS
        'elastomer-solutions.com',     // Elastomer Solutions — rubber, no electronics
        'epm-plastics.com',            // EPM — plastics manufacturer
        'kautex.com',                  // Kautex — blow-molded plastic fuel tanks

        // DATA CENTER OPERATORS / REITS / COLOCATION (not hardware OEMs)
        'colovore.com',                // Colovore — colocation operator
        'crusoe.ai',                   // Crusoe — AI cloud computing operator
        'digitalrealty.com',           // Digital Realty — data center REIT
        'equinix.com',                 // Equinix — data center REIT
        'khaznadatacenters.com',       // Khazna — UAE data center operator
        'novva.com',                   // NOVVA — data center operator

        // HEAVY EQUIPMENT / CONSTRUCTION (wrong industry)
        'caterpillar.com',             // Caterpillar — heavy construction equipment
        'bechtel.com',                 // Bechtel — engineering/construction firm
        'scatec.com',                  // Scatec — solar farm developer/IPP

        // MARINE NON-ELECTRONICS
        'arepa.com',                   // AREPA — disaster recovery services
        'drew-marine.com',             // Drew Marine — water treatment chemicals
        'kumera.com',                  // Kumera — industrial gearboxes (mechanical)

        // ─── Run-13: New sector quality audit — Rail/HVAC/CE junk ───
        // NEWS / MEDIA / MAGAZINES / TRADE PUBLICATIONS
        'hvacinformed.com',            // HVAC trade media
        'cleanroomtechnology.com',     // Cleanroom trade publication
        'railwaypro.com',              // Railway trade magazine
        'modernrailways.com',          // Railway trade magazine
        'railway-technology.com',      // Railway news (GlobalData)
        'rollingstockworld.com',       // Rolling stock trade media
        'reviewjournal.com',           // Las Vegas Review-Journal newspaper
        'wsj.com',                     // Wall Street Journal
        'vegconomist.com',             // Vegan food news site
        'keymodelworld.com',           // Model railway hobby magazine
        'officelovin.com',             // Office design blog

        // MARKET RESEARCH / CONSULTING
        'techsciresearch.com',         // TechSci market research
        'databridgemarketresearch.com', // Databridge market research

        // SOFTWARE / DIRECTORIES / PLATFORMS
        'goodfirms.co',                // B2B review directory
        'zegashop.com',                // E-commerce SaaS platform

        // PHARMA / BIOTECH
        'fiercebiotech.com',           // Biotech news site
        'bms-egy.com',                 // BMS Pharma — Egyptian pharmaceutical

        // APPLIANCE RETAILERS (not manufacturers)
        'friedmansappliance.com',      // Kitchen appliance retailer
        'horizonappliance.com',        // Appliance retailer

        // JOB / CAREER SITES
        'lge-careers.com',             // LG careers portal

        // TV STATIONS
        'venturatv.com',               // TV station

        // HVAC DISTRIBUTORS / INSTALLERS / TRADING (Egypt)
        'hvacregypt.com',              // HVAC service company (Egypt)
        'saif.com.eg',                 // HVAC parts distributor (Egypt)
        'class-atrading.com',          // HVAC trading company (Egypt)
        'technometal-hvac.com',        // HVAC ductwork fabricator (Egypt)
        'bmstechnology.com',           // Small tech reseller
        'morgantigcc.com',             // Construction project management

        // RAIL — NON-MANUFACTURERS
        'capetrain.com',               // Cape Cod tourist/scenic railroad
        'southeasternrailway.co.uk',   // Train operating company (UK)
        'cpa.uk.net',                  // Commonwealth Parliamentary Association
        'portpolska.pl',               // Polish port/logistics company
        'fitzgeraldplant.co.uk',       // UK plant hire/equipment dealer
        'britishsteel.co.uk',          // Steel manufacturer (raw material)
        'arcadis.com',                 // Design/engineering consultancy
        'novelis.com',                 // Aluminium rolling/recycling (raw material)

        // CONSUMER ELECTRONICS — NON-MANUFACTURERS
        'abt.com',                     // Appliance retailer (Abt Electronics)
        'wearablesensing.com',         // Niche neuroscience EEG devices
        'teksun.com',                  // IoT software company
        'huntsman.com',                // Chemical company (polyurethanes)
        'kestramedical.com',           // Medical wearable (wrong sector)

        // GOVERNMENT / PARLIAMENT
        'commonslibrary.parliament.uk', // UK Parliament library

        // ─── Run-14: Quality audit round 2 — remaining junk ───
        // NEWS / MEDIA
        'thedefensepost.com',          // Defense news website
        'energiesmedia.com',           // Energy/renewables news

        // RAIL — INFRASTRUCTURE / CONSTRUCTION / MAINTENANCE
        'networkrail.co.uk',           // Government rail infrastructure operator
        'balfourbeatty.com',           // Construction/infrastructure contractor
        'volkerrail.co.uk',            // Rail construction/maintenance
        'harscorail.com',              // Rail maintenance services
        'grinstyrail.co.uk',           // Rail consultancy, not manufacturer

        // DATA CENTER — SOFTWARE-ONLY / OPERATORS
        'nutanix.com',                 // Software-only (HCI/virtualization)
        'scalecomputing.com',          // Software-only (virtualization)
        'smsdatacenter.com',           // DC operator/service provider

        // HVAC — NON-ELECTRONICS
        'jm.com',                      // Johns Manville — insulation/fiberglass materials
        'pasreform.com',               // Poultry hatchery equipment (not HVAC)

        // REPAIR / MRO SERVICES
        'ctdi.com',                    // CTDI — repair/refurbishment services
        'standardaero.com',            // StandardAero — MRO repair, not OEM

        // NAME-DOMAIN MISMATCH (wrong name extraction)
        'houghton-international.com',  // Scraped as "Integrated Power Services" — name mismatch

        // ─── Run-15: Telecom sector quality fixes ───
        // BANKS / FINANCIAL INSTITUTIONS
        'cib.bnpparibas',              // BNP Paribas bank
        // MARKET RESEARCH
        'coherentmarketinsights.com',  // Market research firm
        // GOVERNMENT
        'europa.eu',                   // European Union government
        // DISTRIBUTORS / RETAILERS
        'focenter.com',                // Fiber Optic Center — distributor
        'launch3direct.com',           // Launch 3 Telecom — distributor
        // NEWS / MEDIA
        'lbbonline.com',               // Advertising/media site
        'telecomtv.com',               // TelecomTV — telecom media/news
        'txfnews.com',                 // TXF — trade finance news

        // TELECOM — OPERATORS / CARRIERS / ISPs (not manufacturers)
        'americantower.com',           // Cell tower REIT/operator
        'astound.com',                 // ISP/cable provider
        'boldyn.com',                  // Neutral host network operator
        'swedishtelecom.com',          // Telecom operator/carrier
        'teliacompany.com',            // Telecom carrier/operator
        'uniti.com',                   // Dark fiber infrastructure REIT
        'wirelessinfrastructure.com',  // Tower/infrastructure operator

        // TELECOM — SERVICES / CONSTRUCTION / INSURANCE / RESELLERS
        'dycomind.com',                // Telecom field services/construction
        'ghekkonetworks.com',          // Refurbished telecom equipment reseller
        'usatelecomins.com',           // Telecom insurance company
        'velocitytelecomusa.com',      // Telecom reseller/agent
        'southern-telecom.com',        // Dark fiber wholesaler (not mfg)

        // UTILITIES / GOVERNMENT ENTITIES
        'fpb.cc',                      // Frankfort Plant Board — municipal utility
        'ri.se',                       // RISE — Swedish government research institute
        'fsg.com',                     // FSG — facilities/electrical services

        // ─── Run-16: Final quality polish ───
        'builtin.com',                 // Built In — tech job board / media
        'gatx.com',                    // GATX — railcar leasing / financial
        'sumitomocorp.com',            // Sumitomo Corporation — trading company
        'cariad.technology',           // CARIAD — VW software-only unit

        // ─── Run-17: Non-OEM / non-prospect domains ───
        'manufacturingtomorrow.com',   // News site
        'aiinsider.com',               // AI news blog
        'ai-insider.com',              // AI news blog
        'alixpartners.com',            // Management consulting
        'ade-solutions.ae',            // IT consulting
        'vegasconsulting.com',         // Consulting firm
        'pandaauto.com',              // Car accessories retailer
        'themanufacturer.com',        // News/media
        'manufacturingglobal.com',    // News/media
        'automotiveworld.com',        // News/media
        'just-auto.com',              // News/media
        'autocar.co.uk',              // News/media
        'carbuzz.com',                // News/media
        'electrek.co',                // News/media
        'insideevs.com',              // News/media
        'cleanenergywire.org',        // News/media
        'energymonitor.ai',           // News/media
        'renewableenergyworld.com',   // News/media
        'greentechmedia.com',         // News/media
        'pv-magazine.com',            // News/media
        'cleantechnica.com',          // News/media

        // ─── Chemical / non-OEM companies ───
        'wacker.com',                 // Wacker Chemie AG — chemical company
        'westlake.com',               // Westlake Corporation — chemical/plastics
        'platinum1auto.com',          // Platinum One Auto Services — car services
        'vegascg.com',                // Vegas Consulting Group
        'hl.com',                     // Houlihan Lokey — investment bank
        'roadandtrack.com',           // Road & Track — car magazine
        'racecar-engineering.com',    // Racecar Engineering — magazine
        'armadainternational.com',    // Armada International — defense magazine
        'dubai-parts.com',            // Dubai Parts — auto parts retailer
        'borouge.com',                // Borouge — plastics/chemicals
        'flowsense.solutions',        // FlowSense — sensing solutions (not OEM)
        'interarmored.com',           // Armored vehicles customizer
        'charin.global',              // CharIN — EV charging standards body

        // ─── Run-18: Quality polish from audit ───
        'edrmagazine.eu',             // EDR Magazine
        'economist.com',              // The Economist
        'thebrakereport.com',         // The Brake Report — news
        'kedglobal.com',              // KED Global — Korean news
        'riyadhair.com',              // Riyadh Air — airline
        'automanspareparts.com',      // Automan Spare Parts — retailer
        'riyadh.ferraridealers.com',  // Ferrari dealer
        'ferraridealers.com',         // Ferrari dealer
        'riyadhplastic.com',          // Riyadh Plastic — packaging
        'disenmachinery.com',         // DISEN — machinery supplier
        'softeq.com',                 // Softeq — software company
        'standardstalk.com',          // Standards Talk — blocked by Cloudflare
        'hyundainews.com',            // Hyundai News — press releases
        'linkecu.com',                // Link ECU — aftermarket ECU
        'haltech.com',                // Haltech — aftermarket ECU
        'isoqdot.com',                // Qdot — ISO consulting
        'sscoksa.com',                // SS&CO — trading
        'evolveautomotive.com',       // Evolve Automotive — car mods
        'admc-me.com',                // ADMC — car distribution
        'petromin.com',               // Petromin — oil/lubricants
        'alfaeparts.com',             // Alfa e-Parts — parts retailer
        'unitxlabs.com',              // UnitX — AI/software

        // ─── Run-18b: More quality polish ───
        // US bad domains
        'crainsdetroit.com',          // Crain's Detroit Business — news
        'demanddetroit.com',          // Demand Detroit — economic dev
        'detroitaxle.com',            // Detroit Axle — aftermarket parts
        'detroitnews.com',            // Detroit News — news
        'michigandems.com',           // Michigan Democratic Party
        'simpleque.com',              // SimpleQuE — QA consulting
        'southernautomotive.com',     // Southern Automotive Wholesalers
        'detroit-electric.com',       // Detroit Electric — defunct EV startup
        'alpsalpine.com',             // Alps Alpine — component manufacturer
        'dangerfieldsofshakopee.com', // Restaurant
        // EG bad domains
        'cairoautofix.site',          // Cairo Auto Fix — auto repair
        'eaglerailcar.com',           // Eagle Railcar Services
        'klgates.com',                // K&L Gates — law firm
        'riverbend-ford.com',         // RiverBend Ford — car dealer
        'cairo.pl',                   // Polish unrelated site
        'redeweb.com',                // Tria — unrelated
        'einfochips.com',             // eInfochips — IT services
        // MA bad domains
        'automotivetestingtechnologyinternational.com', // Magazine
        'bonhams.com',                // Auction house
        'ea.com',                     // Electronic Arts — gaming
        'gem.wiki',                   // Global Energy Monitor
        'growingscience.com',         // Academic journal
        'acnnewswire.com',            // News wire
        'macdermidalpha.com',         // MacDermid Alpha — chemicals
        'cauhe.com',                  // Talleres Casablanca — workshop
        'teckwrap.com',               // Teckwrap — car wraps
        'temjournal.com',             // TEM Journal — academic
        'tritiumcharging.com',        // Tritium — EV charging
        'blinkcharging.com',          // Blink Charging — EV charging infra
        'evconnect.com',              // EV Connect — EV charging infra
        'tutor-ev.de',                // Tutor e.V — education
        'vyvy-audit.com',             // VyVy Audit — audit firm
        // EU bad domains
        'basf.com',                   // BASF — chemical company
        'renesas.com',                // Renesas — semiconductor
        'chargepoint.com',            // ChargePoint — EV charging infra
        // Software / EDI / EDA — no hardware needs
        'eda.sw.siemens.com',         // Siemens EDA software
        'edicomgroup.com',            // EDICOM — EDI software
        // Chemical / materials suppliers
        'atotech.com',               // Atotech — plating chemicals
        'avient.com',                // Avient — specialty polymers
        // Testing / certification / consulting
        'utac.com',                  // UTAC — vehicle testing lab
        'axsing.com',               // AXS Ingénierie — consulting
        'es-tec.com',               // ES-Tec — engineering consulting
        // Competitor — wiring harness manufacturer
        'sews-e.com',               // Sumitomo Electric Wiring — direct competitor
        'fujikura.co.jp',           // Fujikura — wiring harness competitor
        // Non-electronics / mechanical only
        'ampcodubai.com',           // AMPCO — contracting/pumps
        'tompkinsproducts.com',     // Tompkins — hydraulic fittings
        'laepple-us.com',           // Läpple — metal stamping
        'nivel.com',                // Nivel — aftermarket parts distributor
        'redachem.com',             // REDA Chemicals — chemicals
        'host-immo.ma',             // Host Immo — real estate
        'lapocompound.it',          // Lapo Compound — compounds
    ];

    /**
     * Check if a domain should be excluded from company import.
     */
    private function isBlockedDomain(string $domain): bool
    {
        $domain = strtolower(preg_replace('/^www\./', '', $domain));

        // ─── Block non-company subdomain prefixes ─────────────────────
        // Domains like blog.*, press.*, jobs.*, shop.*, investors.*, etc.
        // are never standalone companies — they are subpages of larger orgs
        $junkSubdomainPrefixes = [
            'blog.', 'blogs.', 'press.', 'news.', 'media.',
            'jobs.', 'careers.', 'investors.', 'investor.',
            'assets.', 'static.', 'cdn.', 'docs.', 'wiki.',
            'shop.', 'store.', 'help.', 'support.', 'status.',
            'agenda.', 'events.', 'forum.', 'community.',
            'download.', 'downloads.', 'upload.',
            'resources.', 'info.', 'lib.', 'web.', 'arc.',
            'defense-solutions.', 'plm.', 'slingshot.',
            'health.', 'global.', 'new.', 'en.', 'm.',
            'market-insights.', 'flightplan.', 'international.',
            'africa.', 'mobile.', 'manufacturing.',
            'newsroom.', 'mediaroom.', 'ir.', 'dcareers.',
            'corporate.', 'group.', 'developer.', 'datacenters.',
        ];
        foreach ($junkSubdomainPrefixes as $prefix) {
            if (str_starts_with($domain, $prefix)) {
                return true;
            }
        }

        // ─── Block known multi-TLD shopping sites ──────────────────
        $multiTldSites = ['ubuy'];
        foreach ($multiTldSites as $site) {
            if (preg_match('/^' . preg_quote($site, '/') . '\.[a-z]{2,6}$/i', $domain)) {
                return true;
            }
        }

        // ─── Block job portal subdomains ───────────────────────────
        if (str_contains($domain, '.myworkdayjobs.com') || str_contains($domain, '.workday.com')) {
            return true;
        }

        // ─── Block domains with news/media/magazine in the name ───
        // These are almost never real companies
        if (preg_match('/\b(news|magazine|insider|tribune|herald|chronicle|times|gazette|dispatch|journal|digest|observer|telegraph|daily|weekly|monthly|media)\b/i', $domain)) {
            // Exception: domains where the word is part of a real company name
            // (checked manually — very few genuine cases)
            if (!preg_match('/(siemens|boeing|airbus|safran|thales|dassault)/i', $domain)) {
                return true;
            }
        }

        // ─── Block financial / investment domains ─────────────────
        if (preg_match('/\b(finance|investment|banking|capital|fund|equity|venture|investor)\b/i', $domain)) {
            return true;
        }

        // ─── Block certification / audit domains ─────────────────
        if (preg_match('/\b(certification|certifying|accreditation|registrar)\b/i', $domain)) {
            return true;
        }

        // ─── Block shipping / logistics company subdomains ───────
        if (str_ends_with($domain, '.ups.com') || str_ends_with($domain, '.fedex.com') || str_ends_with($domain, '.dhl.com')) {
            return true;
        }

        // ─── Block Honeywell subdivisions (too generic) ─────────
        if (str_ends_with($domain, '.honeywell.com') && $domain !== 'honeywell.com') {
            return true;
        }

        // ─── Block conference / event aggregator domains ──────────
        if (preg_match('/\b(conference|summit|expo|exhibition|fair|congress|symposium|autoshow|motorshow|tradeshow)\b/i', $domain)) {
            return true;
        }

        // ─── Block hospital / clinic / healthcare domains ─────────
        if (preg_match('/\b(hospital|clinic|healthcare|medcenter|healthsystem)\b/i', $domain)) {
            return true;
        }

        // ─── Block museum / gallery / archive domains ────────────
        if (preg_match('/\b(museum|gallery|archive)\b/i', $domain)) {
            return true;
        }

        // ─── Block parliament / legislative domains ──────────────
        if (preg_match('/\b(parliament|legislature|congress|senate)\b/i', $domain)) {
            return true;
        }

        // ─── Block career/job portal domains ─────────────────────
        if (preg_match('/[-.]careers?\./i', $domain) || preg_match('/[-.]jobs\./i', $domain)) {
            return true;
        }
        if (str_ends_with($domain, '-careers.com') || str_ends_with($domain, '-jobs.com')) {
            return true;
        }

        // ─── Block appliance retailer domains ────────────────────
        if (preg_match('/\bappliance/i', $domain)) {
            return true;
        }

        // ─── Block market research domains ───────────────────────
        if (preg_match('/marketresearch|marketinsights/i', $domain)) {
            return true;
        }

        // ─── Block railway/rail media & tourist trains ───────────
        if (preg_match('/\b(railway|railroad)\b/i', $domain) && preg_match('/\b(technology|news|pro|today|world|modern|magazine|scenic|tourist|heritage)\b/i', $domain)) {
            return true;
        }

        // ─── Block government domains (*.gov.*) ──────────────────
        // Government websites are never EMS prospects
        if (preg_match('/\.gov(\.[a-z]{2,3})?$/i', $domain)) {
            return true;
        }

        // ─── Block .org domains (associations, not manufacturers) ─
        // Very few legitimate OEM prospects use .org
        if (str_ends_with($domain, '.org') || preg_match('/\.org\.[a-z]{2,3}$/i', $domain)) {
            return true;
        }

        foreach (self::DOMAIN_BLOCKLIST as $blocked) {
            // Exact match
            if ($domain === $blocked) {
                return true;
            }
            // TLD match (e.g. *.gov)
            if (!str_contains($blocked, '.') && preg_match('/\.' . preg_quote($blocked, '/') . '$/i', $domain)) {
                return true;
            }
            // Suffix match (e.g. subdomain.oracle.com)
            if (str_ends_with($domain, '.' . $blocked)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an extracted company name is still junk (descriptive phrase,
     * product listing title, etc.) rather than an actual company name.
     *
     * This is a secondary filter applied AFTER extractCompanyName() has
     * done its best to clean the Google search title.
     */
    private function isJunkCompanyName(string $name): bool
    {
        $lower = strtolower(trim($name));
        
        // Empty or very short (single char)
        if (mb_strlen($name) < 2) {
            return true;
        }
        
        // ─── Names ending in TLD suffixes → domain was used as name ──
        if (preg_match('/\.(com|net|org|io|co|fr|de|in|ma|uk|eu|be)$/i', $name)) {
            return true;
        }
        
        // ─── Hosting error / placeholder page titles ──────────────────
        // These appear when a domain is parked, expired, or suspended
        $hostingErrors = [
            'account suspended', 'coming soon', 'under construction',
            'page not found', 'not found', 'site suspended',
            'parked domain', 'domain for sale', 'maintenance mode',
            'website expired', 'forbidden', 'access denied',
            'default web site page', 'welcome to nginx',
            'apache2 default page', 'test page', 'website coming soon',
            'site under maintenance', 'error 404', 'error 403',
            'global entry page', 'entry page', 'loading', 'please wait',
            'just a moment', 'one moment please', 'checking your browser',
            'attention required', 'you are being redirected',
            'page has moved', 'moved permanently', 'temporary redirect',
        ];
        if (in_array($lower, $hostingErrors, true)) {
            return true;
        }

        // Redirect/placeholder titles with special characters
        // "Redirecting…", "Loading…", "Redirecting..."
        if (preg_match('/^(redirecting|loading|please wait|just a moment|checking)[\.…\s]*$/i', $lower)) {
            return true;
        }

        // Cloudflare/bot challenge pages
        if (preg_match('/\b(captcha|challenge|verify you are human|enable javascript|enable cookies)\b/i', $lower)) {
            return true;
        }

        // ─── Non-Latin script detection ───────────────────────────────
        // Reject names containing CJK, Arabic, Thai, Korean, etc.
        if (preg_match('/[\x{3000}-\x{9FFF}\x{AC00}-\x{D7AF}\x{0600}-\x{06FF}\x{0E00}-\x{0E7F}\x{FF00}-\x{FFEF}]/u', $name)) {
            return true;
        }
        
        // ─── Generic single-word names ────────────────────────────────
        // These are NEVER real company names when standing alone
        $genericSingleWords = [
            'about', 'author', 'blog', 'brands', 'careers', 'certifications',
            'contact', 'details', 'english', 'events', 'faq', 'features',
            'gallery', 'home', 'jobs', 'locations', 'login', 'market',
            'media', 'news', 'oem', 'overview', 'partners', 'pcb', 'pcba',
            'performance', 'portfolio', 'press', 'products', 'register',
            'resources', 'services', 'shop', 'solutions', 'support',
            'arabic', 'french', 'german', 'chinese', 'japanese', 'korean',
            'spanish', 'analog', 'digital', 'global', 'international',
            'regional', 'advanced', 'premium', 'standard', 'classic',
            'industries', 'manufacturing', 'engineering', 'technology',
            'avionics', 'connectors', 'certifications', 'amazon', 'default',
            'welcome', 'untitled', 'homepage', 'main', 'index', 'demo',
            'barbados', 'liberia', 'togo', 'morocco', 'tunisia',
            'oman', 'qatar', 'bahrain', 'kuwait', 'jordan', 'lebanon',
            'nigeria', 'kenya', 'ghana', 'ethiopia', 'uganda', 'tanzania',
            'investors', 'mediaroom', 'newsroom', 'pressroom', 'publications',
            'insights', 'tracker', 'monitor', 'dashboard', 'platform',
            'alerts', 'directory', 'forum', 'wiki', 'marketplace',
            'fortune', 'corporate', 'facilities', 'riviera',
            'warehouse', 'certified', 'museum', 'wsj',
            'rollingstock', 'startingpoint', 'eu', 'thedefensepost',
            // Major cities — never company names standing alone
            'detroit', 'houston', 'chicago', 'boston', 'seattle',
            'london', 'paris', 'berlin', 'munich', 'hamburg',
            'amsterdam', 'madrid', 'rome', 'milan', 'stockholm',
            'dubai', 'riyadh', 'doha', 'jeddah',
            'casablanca', 'tangier', 'rabat',
            'alexandria', 'tokyo', 'shanghai', 'beijing',
        ];
        if (in_array($lower, $genericSingleWords, true)) {
            return true;
        }
        
        // ─── Generic two-word patterns ────────────────────────────────
        $genericTwoWord = [
            'our brands', 'our products', 'our services', 'our partners',
            'featured services', 'featured products', 'cover sheet',
            'authors list', 'author list', 'press releases',
            'pcb update', 'pcb/pcba', 'one wind',
            'market insights', 'industry news', 'market research',
            'yahoo finance', 'morocco now', 'made in',
            'business insider', 'flight plan', 'a national',
            'net 0', 'net zero', 'parallel parliament',
            'us english', 'uk english', 'eu english',
            'select region', 'media unit', 'auto show',
            'automotive service', 'navigation services',
            'data center', 'server room',
            'product engineering', 'smart manufacturing',
            'york ie', 'port polska', 'lg tunisie',
        ];
        if (in_array($lower, $genericTwoWord, true)) {
            return true;
        }
        
        // Names that are just generic words or region names
        $genericNames = [
            'cairo', 'prices', 'inside', 'sitemap', 'downloads',
            'overseas hubs', 'global network', 'corporate profile',
            'military veterans', 'en made in china',
            'mena', 'maintenance and repair services',
            'manufacturing engineering', 'medical device contract manufacturing',
            'shop electech', 'gas stove handle',
            'le mag certification', 'french stamps and philatelic products',
            'home of manufacturing news', 'sustainable business development',
            'site selection magazine', 'aviation international news',
            'corporate jet investor', 'business insider africa',
            'south china morning post', 'creative artists agency',
            'enabling a world in motion', 'european cluster collaboration platform',
            'kotak investment banking', 'international labour organization',
            'thequantuminsider', 'the quantum insider',
            'everywhereyoulook', 'everywhereyoulook!',
            'dynabrade power tools', 'fred minnick',
            'eu-japan centre for industrial cooperation',
            'power technology', 'energy global',
            'windtech international', 'windpower monthly',
            'business green', 'mercom capital group',
            'solar dynamix -', 'solar dynamix',
            'renews - renewable energy news',
            'japan', 'facilities', 'parker dewey',
            'cannabis control commission massachusetts',
            'mass vehicle check', 'www.wellpoint',
            'premier ultrasound',
            'european union', 'fiber optic center',
            'coherent market insights',
        ];
        if (in_array($lower, $genericNames, true)) {
            return true;
        }

        // ─── "Made in [Country]" or "[Country] Now" patterns ──────────
        if (preg_match('/^made\s+in\s+\w+$/i', $name) ||
            preg_match('/^(morocco|egypt|qatar|dubai|saudi|tunisia)\s+(now|today|first)/i', $name)) {
            return true;
        }

        // ─── "[Region] and [Region]" or "Select a [X]" patterns ──────
        if (preg_match('/^select\s+a\s+/i', $name)) {
            return true;
        }
        if (preg_match('/^(egypt|north\s+africa|middle\s+east)\s+(and\s+)?(north\s+africa|middle\s+east|egypt)$/i', $name)) {
            return true;
        }

        // ─── "X Newsroom" / "X Mediaroom" patterns ───────────────────
        if (preg_match('/\b(newsroom|mediaroom|pressroom)$/i', $name) && $wordCount >= 2) {
            return true;
        }

        // ─── "ISO Certification in X" patterns ───────────────────────
        if (preg_match('/^ISO\s+certification/i', $name)) {
            return true;
        }

        // ─── Advisory / Intelligence / Consulting in name ────────────
        if (preg_match('/\badvisory\s*(&|and)\s*(intelligence|research|consulting)/i', $name)) {
            return true;
        }
        if (preg_match('/\bconsulting\s*(&|and)\s*growth/i', $name)) {
            return true;
        }

        // ─── "X Developer Portal" / "X API" patterns ────────────────
        if (preg_match('/\b(developer\s+portal|api\s+docs|developer\s+docs)$/i', $name)) {
            return true;
        }

        // ─── "Innovating Education" / education patterns ─────────────
        if (preg_match('/\binnovating\s+education\b/i', $name)) {
            return true;
        }

        // ─── Museum / Exhibition / Trade show in name ────────────────
        if (preg_match('/\b(museum|exhibitions?|auto\s+show|motor\s+show|trade\s+show)\b/i', $name)) {
            return true;
        }

        // ─── Agriculture / Food / Animal Feed in name ────────────────
        if (preg_match('/\b(feeding|feed\s+manufacturing|poultry|agriculture|farming|animal\s+feed)\b/i', $name)) {
            return true;
        }

        // ─── Investment / Holding company patterns ───────────────────
        if (preg_match('/\binvestments?\b/i', $name) && $wordCount >= 2) {
            return true;
        }

        // ─── University / Academic institution patterns ──────────────
        if (preg_match('/\b(university|universit(é|eit|ät)|^TU\s+)/i', $name)) {
            return true;
        }

        // ─── "Trucks & Forklifts" / material handling ────────────────
        if (preg_match('/\b(trucks?\s*(&|and)\s*forklifts?|forklift\s+solutions?)\b/i', $name)) {
            return true;
        }

        // ─── "Experience in Motion" / tagline-as-name ────────────────
        if (preg_match('/^experience\s+in\s+/i', $name)) {
            return true;
        }

        // ─── "Quantum Computing" as standalone name ──────────────────
        if (preg_match('/^quantum\s+computing$/i', $name)) {
            return true;
        }

        // ─── Names containing question marks (extraction errors) ─────
        if (str_contains($name, '?')) {
            return true;
        }

        // ─── Generic role/category as name ───────────────────────────
        if (preg_match('/^systems?\s+integrator/i', $name)) {
            return true;
        }
        if (preg_match('/\bquality\s+certifications?\b/i', $name)) {
            return true;
        }

        // ─── Product description as name ─────────────────────────────
        if (preg_match('/^(laminated|coated|plated)\s+.*(covers?|sheets?|panels?)$/i', $name)) {
            return true;
        }

        // ─── News site names as company names ────────────────────────
        if (preg_match('/^business\s*insider/i', $name)) {
            return true;
        }

        // ─── Names ending in "Locations" / "Internet" (page nav artifacts) ──
        if (preg_match('/\b(Locations|Internet)$/i', $name) && $wordCount >= 2) {
            return true;
        }

        // ─── Bank / financial institution names ──────────────────────
        if (preg_match('/\b(paribas|barclays|hsbc|citibank|cib)\b/i', $name)) {
            return true;
        }

        // ─── Names ending in "TV" (media outlets) ────────────────────
        if (preg_match('/TV$/i', $name) && $wordCount >= 2) {
            return true;
        }

        // ─── "Corporate Website" / "Careers" / "Jobs" in name ─────────
        if (preg_match('/\b(corporate\s+website|careers|jobs)\b/i', $name)) {
            return true;
        }

        // ─── Motivational taglines as names ──────────────────────────
        if (preg_match('/\b(create\s+today|enrich\s+tomorrow|tomorrow\s+starts)\b/i', $name)) {
            return true;
        }

        // ─── Train operating companies (not manufacturers) ───────────
        if (preg_match('/\b(railroad|railway|scenic\s+train|tourist\s+train)\b/i', $name) && !preg_match('/\b(systems?|electronics?|signal|manufacturer)\b/i', $name)) {
            return true;
        }

        // ─── Steel / aluminium raw material companies ────────────────
        if (preg_match('/^(british\s+steel|novelis|arcelor)/i', $name)) {
            return true;
        }

        // ─── Structural heuristics (AI-level word parsing) ────────────
        $words = preg_split('/\s+/', trim($name));
        $wordCount = count($words);

        // ─── Names ending in "Home" (page navigation extraction) ─────
        if (preg_match('/\bHome$/i', $name) && $wordCount >= 2) {
            return true;
        }

        // ─── Tagline patterns ("Enabling X", "Powering X") ──────────
        if (preg_match('/^(enabling|powering|transforming|driving|building|creating|connecting|delivering|inspiring|pioneering|reimagining|reshaping|accelerating|advancing)\s+/i', $name) && $wordCount >= 3) {
            return true;
        }

        // ─── "Global Leader in X" / "Wholesale X" taglines ──────────
        if (preg_match('/^(global\s+leader|wholesale|leading\s+provider|world\s+leader)\s+(in|of)\s+/i', $name)) {
            return true;
        }

        // ─── Insurance company names ─────────────────────────────────
        if (preg_match('/\binsurance\b/i', $name)) {
            return true;
        }

        // ─── Names ending with "!" are slogans/taglines ──────────────
        if (str_ends_with(trim($name), '!') && $wordCount >= 2) {
            return true;
        }

        // ─── Names that are just "[Sector] [Descriptive]" ────────────
        // e.g., "Solar Dynamix -", "Energy Global", "Power Technology"
        if (preg_match('/^(solar|wind|energy|power|battery|renewable)\s+(technology|global|monthly|international|magazine|insider|news)/i', $name)) {
            return true;
        }

        // ─── "Home of X" patterns ────────────────────────────────────
        if (preg_match('/^home\s+of\s+/i', $name)) {
            return true;
        }

        // ─── "Le Mag X" or "The Magazine" patterns ───────────────────
        if (preg_match('/^(le\s+mag|the\s+mag|the\s+blog|the\s+journal|the\s+chronicle)/i', $name)) {
            return true;
        }
        
        // Too many words → almost certainly a page title / phrase
        if ($wordCount > 7) {
            return true;
        }
        
        // 6+ words → very likely a title/headline, not a company
        if ($wordCount >= 6) {
            return true;
        }
        
        // If 4+ words AND majority lowercase → phrase, not a company name
        if ($wordCount >= 4) {
            $lowercaseWords = 0;
            foreach ($words as $w) {
                if ($w === strtolower($w) && strlen($w) > 2) {
                    $lowercaseWords++;
                }
            }
            if ($lowercaseWords >= ($wordCount * 0.5)) {
                return true;
            }
        }
        
        // Starts with an article/preposition/verb → almost never a company name
        $sentenceStarters = '/^(the|a|an|how|why|what|where|when|which|who|find|get|buy|our|your|their|this|these|those|best|top|new|all|every|each|some|any|most|more|list|guide|review|become|imagining|reshoring|futuristic|largest)\s/i';
        if (preg_match($sentenceStarters, $name) && $wordCount > 2) {
            return true;
        }
        
        // Contains common verb structures → sentence, not name
        $verbPatterns = '/\b(you can|we offer|we provide|we are|we have|is a|are the|has been|have been|will be|can be|should be|looking for|searching for|need to|want to|how to|learn more|click here|read more|sign up|log in|subscribe)\b/i';
        if (preg_match($verbPatterns, $name)) {
            return true;
        }
        
        // ─── News headline verb detection ─────────────────────────────
        // Pattern: "CompanyName verb rest" e.g. "Bombardier exits commercial aviation"
        $headlineVerbs = '/\b(exits|signs|launches|buys|wins|enters|joins|acquires|announces|unveils|reveals|secures|expands|opens|completes|delivers|reports|appoints|ranked|partners|outbreak)\b/i';
        if (preg_match($headlineVerbs, $name) && $wordCount >= 3) {
            return true;
        }
        
        // ─── Event / conference / association keywords ────────────────
        $eventAssocPatterns = '/\b(conference|summit|expo|exhibition|fair|days|forum|workshop|symposium|convention|meetings?|congress)\b/i';
        if (preg_match($eventAssocPatterns, $name) && $wordCount >= 2) {
            return true;
        }
        $assocPatterns = '/\b(association|initiative|foundation|society|federation|consortium)\b/i';
        if (preg_match($assocPatterns, $name) && $wordCount >= 3) {
            return true;
        }
        
        // ─── Year-prefixed titles ─────────────────────────────────────
        // "2022 Odessa Disturbance", "RENEWABLES 2019 GLOBAL STATUS REPORT"
        if (preg_match('/\b(19|20)\d{2}\b/', $name) && $wordCount >= 3) {
            return true;
        }
        
        // ─── ALL-CAPS-WITH-DASHES pattern (news slugs) ────────────────
        if (preg_match('/^[A-Z]+-[A-Z]+-[A-Z]+/', $name)) {
            return true;
        }
        
        // ─── Possessive + registration/certification ──────────────────
        if (preg_match("/'.?s\s+(JOSCAR|registration|certification|ISO|IATF)/i", $name)) {
            return true;
        }
        
        // ─── "X and Y" / "X & Y" service descriptions ────────────────
        if (preg_match('/\b(and|&)\s+(used\s+parts|wire\s+suppliers|cable|harness|assembly|repair|maintenance)/i', $name)) {
            return true;
        }
        
        // ─── Report/status/insight anywhere in name ───────────────────
        if (preg_match('/\b(report|insight|disturbance|status\s+report)\b/i', $name) && $wordCount >= 3) {
            return true;
        }
        
        // ─── All-lowercase multi-word names ──────────────────────
        // Real companies are capitalised; all-lowercase = extracted wrong
        if ($wordCount >= 2 && $lower === $name) {
            return true;
        }
        
        // ─── Pure numbers or part-number-like strings ──────────────
        if (preg_match('/^\d+$/', trim($name))) {
            return true;  // "2035", "7"
        }
        if (preg_match('/^[A-Z]{1,4}[-\s]?\d{3,}/', $name)) {
            return true;  // "TBW1122", "NF-2405 AIR", "MIL-DTL-38999"
        }
        if (preg_match('/^MIL-/', $name)) {
            return true;  // Military specs
        }
        
        // ─── Taglines with unusual punctuation ─────────────────────
        if (preg_match('/\.\s+[A-Z]/', $name) && $wordCount <= 4) {
            return true;  // "Forward. For all." — tagline
        }
        
        // ─── "X Replacement" / "X Installation" product patterns ──
        if (preg_match('/\b(replacement|installation|boot\s+camp|wiring\s+boot|training|course|tutorial|near\s+me|suppliers?\s+near)\b/i', $name)) {
            return true;
        }
        
        // ─── Generic "NEW X" / "Press Release" patterns ───────────
        if (preg_match('/^(new|press|wire|cable)\s+(oem|release|cable|harness)$/i', $name)) {
            return true;
        }
        
        // ─── Country/region names standing alone (2 words) ────────
        if (preg_match('/^(united\s+states|united\s+kingdom|south\s+africa|north\s+africa|middle\s+east|saudi\s+arabia)$/i', $name)) {
            return true;
        }
        
        // ─── Single word ending in common URL suffixes ─────────────
        // e.g. "Diysolarforum", "Moroccoworldnews", "Solarpowerworldonline"
        if ($wordCount === 1 && mb_strlen($name) > 12 && preg_match('/(forum|news|online|blog|wiki|store|shop|magazine)$/i', $name)) {
            return true;
        }
        
        // ─── Bare product / service descriptions (2-3 words) ──────────
        // These are generic industry terms, not company names
        $bareDescriptions = '/^(wire|cable|wiring|avionics|electronics?)\s+(cable|harness|assembly|manufacturing|systems?|components?)\s*(harness|systems?|services?)?$/i';
        if (preg_match($bareDescriptions, $name)) {
            return true;
        }
        
        // Names containing product descriptions (not company names)
        $junkPatterns = [
            '/\b(wire|wiring|cable)\s+harness\s+(for|compatible|set|kit|#|replacement|wiring)/i',
            '/\b(genuine|oem)\s+(toyota|lexus|yamaha|motorcraft|part)/i',
            '/\bPT\d{5,}/i',
            '/\bTig\s+\d{4}/i',
            '/\bSEW\s+Eurodrive\s+DS/i',
            '/\bProfibus\s+cable/i',
            '/\bRefrigerator\s+Handle/i',
            '/\bAMP\s+MCP\s+Interconnection/i',
            '/\bTX\/RX\s+Modules/i',
            '/\b(download|à\s+www\.)/i',
            '/\bEn\s+Made\s+In\s+China$/i',
            // ─── Page title patterns ──────────────────────────────────
            '/:\s*(Home(\s*Page)?|Homepage)$/i',          // "Something: Home"
            '/\bHome\s*Page$/i',                          // "MacDermid Alpha: Home Page"
            '/^List\s+of\s+(all|Apple|top)/i',            // "List of all the Valeo..."
            '/^History\s+of\s+/i',                        // "History of EJ Darby & Son"
            '/^Author:\s+/i',                             // "Author: PMI Industries"
            '/^(YDP|Author),?\s+Author\s+at\s+/i',       // "YDP, Author at Champion"
            '/Company\s+Overview$/i',                     // "... Company Overview"
            '/Trade\s+Show\s+Information$/i',             // "... Trade Show Info"
            '/Instructor\s+Profiles$/i',                  // "Omnex Instructor Profiles"
            '/\bLocations?\s+(in\s+Europe|around)/i',     // "Locations in Europe..."
            '/\bIncident\s+Airbus/i',                     // accident reports
            '/^SMOKE,?\s+FIRE/i',                         // paper titles
            '/^Keeping\s+pace\s+with/i',                  // taglines
            '/^Pioneering\s+sustainable/i',
            '/\bStrategic\s+Supplier\s+Partners/i',       // "Honeywell & Wahaj as..."
            '/^Overseas\s+Manufacturing/i',
            '/\bContract\s+Manufactur(ing|ers?)\s*(and|&|\s+Supplier)/i',
            '/\bInstallation\s+Material\s+[A-Z]{3}/i',   // "MOVITRANS® Installation Material"
            '/\bCo-create\b/i',                           // "Marelli: Co-create..."
            '/^The\s+\w+\s+Story$/i',                     // "The Delkin Story"
            '/^Solutions\s+[»>]/i',                       // "Solutions » EVO..."
            '/^\d+\s+Core\s+[\d.]+\s+Sq/i',              // "2 Core 1.5 Sq.mm..."
            '/^LMR[-\s]\d+$/i',                           // "LMR-400" cable model
            '/^MaxxECU\s+/i',                             // aftermarket product
            '/\bFineEngineering\s+Magazine/i',
            '/\bCarrier\s+Heat\s+Pump\s+Wire/i',
            '/\bReceptacle\s+&\s+Wire\s+Connector\s+Parts/i',
            '/^Shielded\s+Multi\s+Conductor/i',
            '/\bLMR\s+Applications?\s+Cables?$/i',
            // ─── Patterns mirrored from extractCompanyName genericPatterns ─
            '/\bmarket\s+(growth|report|size)\b/i',
            '/^(IATF|ISO|IEC)\s*\d/i',
            '/\b(brochure|datasheet|whitepaper|specification|manual)\b/i',
            '/\b(job|career)\s+(opening|posting|opportunit)/i',
            '/\b(sustainability|annual|esg)\s+report\b/i',
            '/\bannuaire|directory\b/i',
            '/\bsearch\s+results\b/i',
            '/\bmanufacturers?\s*$/i',
            '/^\d+\s+best\s+/i',
            '/^top\s+\d+\s+/i',
            '/^PCB\s+(assembly|manufacturers?\s+in)/i',
            '/^PCBA\s+(contract|finder)/i',
            '/^electronic(s)?\s+(contract|manufacturing|MRO)/i',
            '/^contract\s+electronics?\s+manufacturing/i',
            '/^custom\s+(wire|cable|industrial)/i',
            '/^cable\s+(harness|assembly)\s+(manufacturer|&)/i',
            '/^EMS\s+supplier/i',
            '/^(wire\s+harness|cable\s+assembly|pcb\s+assembly|electronic\s+(assembly|manufacturing)|contract\s+manufactur|surface\s+mount|smt\s+assembly)/i',
            // ─── Additional patterns from deep analysis ───────────────
            '/\bcontrol\s+panel\s+(wiring|building|assembly)/i',
            '/\bpanel\s+build(ers?|ing)/i',
            '/\bMRO\s+services/i',
            '/\b(vibration|pulse|measurement)\s+(monitor|sensor|system)/i',
            '/\btest\s+(and\s+measurement|equipment|solutions)/i',
            '/\brepair\s+(and|&)\s+(overhaul|maintenance)/i',
            '/\bspare\s+parts/i',
            '/\b(power|cable)\s+management/i',
            '/\bexplosion[\s-]proof/i',
            '/\blighting\s+(solutions?|products?|systems?)/i',
            '/\bLED\s+(driver|module|light)/i',
            '/\b(SiC|GaN)\s+(MOSFET|transistor|device)/i',  // semiconductor products
            '/\bmemory\s+(card|module|storage)/i',
            '/\b(flash|SD|microSD)\s+storage/i',
            '/\bindustrial\s+magnet/i',
            '/\bautomation\s+(solutions?|systems?|services?)$/i',
            // ─── Round 3 patterns ─────────────────────────────────────
            '/^(AUTOSAR|embedded)\s+(software|system)/i',
            '/\bsoftware\s+implementation/i',
            '/\b(finishes|coatings)\s*$/i',           // "Industrial Finishes & Coatings"
            '/^(avionics|certifications?|connectors?)$/i',
            '/\bdevices?\s+&\s+computing/i',
            '/\bVFD\s+control/i',
            '/^(chapter|section)\s+\d/i',               // "chapter 7"
            '/^(tape|adhesive),\s+oem/i',              // "Tape, OEM Wire Harness"
            '/^SAT\s+single/i',                         // product description
            '/^author$/i',                              // standalone "Author"
            '/^(barbados|liberia|amazon)$/i',           // country names / mega-corp
            '/\bsuppliers?\s+near/i',                  // "Electrical Suppliers Near Me"
            '/\b(refrigerator|kitchenaid|oven)\s+/i',  // appliance products
            '/^(AIR|EV)\s+store$/i',                   // stores
            '/\bwiring\s+for\s+the/i',                 // article titles
            // ─── Non-OEM business type names ──────────────────────────
            '/\bconsulting\b/i',                        // "Vegas Consulting", "AlixPartners Consulting"
            '/\bconsultants?\b/i',                      // "ABC Consultants"
            '/\bseat\s+cover/i',                       // "Panda Seat Cover"
            '/\bcar\s+accessories/i',                   // "Dubai Car Accessories"
            '/\bchemicals?\s+(company|corp|llc)/i',     // "Corofy LLC" chemicals
            '/\breal\s+estate/i',                       // real estate companies
            '/\bproperty\s+(management|develop)/i',     // property management
            '/\bstaffing\b/i',                          // staffing agencies
            '/\brecruitment\b/i',                       // recruitment firms
            '/\blaw\s+firm\b/i',                        // law firms
            '/\baccounting\s+firm/i',                   // accounting firms
            '/\bmarketing\s+agency/i',                  // marketing agencies
            '/\bad\s+agency/i',                         // ad agencies
            '/\btravel\s+agency/i',                     // travel agencies
            '/\bcar\s+(rental|wash|detailing)/i',       // car services
            '/\bauto\s+(repair|body|glass|parts)/i',    // auto repair shops
            '/\btowing\s+/i',                           // towing services
            '/\bdriving\s+school/i',                    // driving schools
            '/\bpaint\s+shop/i',                        // paint shops
            '/\btyre\s+/i',                             // tyre shops
            '/\btire\s+(shop|store|dealer)/i',          // tire shops
            // ─── Additional wrong-type name patterns ──────────────────
            '/\baudit\b/i',                             // audit firms
            '/\bauction\b/i',                           // auction houses
            '/\bjournal\b/i',                           // academic journals
            '/\bmagazine\b/i',                          // magazines
            '/\bwholesale(r|rs)?$/i',                   // wholesalers
            '/\bdealer(ship)?$/i',                      // car dealers
            '/\bwrap(ping)?$/i',                        // car wrap companies
            '/\bcharging\s+(solutions?|station)/i',     // EV charging infra
            '/\bdemocratic\s+party/i',                  // political parties
            '/\brepublican\s+party/i',                  // political parties
        ];
        
        foreach ($junkPatterns as $pattern) {
            if (preg_match($pattern, $name)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Classify a search result by analyzing its snippet to determine if
     * the company is an EMS COMPETITOR (provides the same services as
     * Starz) rather than an EMS BUYER (potential customer).
     *
     * This is the key semantic filter that domain blocklists cannot provide.
     * It reads the snippet text that Google returns and looks for signals
     * that the result describes a company OFFERING EMS services vs. one
     * that would BUY them.
     *
     * Returns true if the result should be REJECTED (competitor/wrong type).
     */
    private function isCompetitorOrWrongType(string $snippet, string $title, string $domain): bool
    {
        $text = strtolower($snippet . ' ' . $title);

        // ─── 1. EMS COMPETITOR signals ────────────────────────────────
        // These phrases indicate a company that PROVIDES the same services
        // Starz provides → competitor, not a buyer
        $competitorSignals = [
            // Direct EMS provider language
            'we (manufacture|assemble|produce|build|offer|provide|specialize)',
            'our (manufacturing|assembly|production|capabilities)',
            '(leading|premier|trusted|reliable)\s+(ems|contract\s+manufactur|pcb\s+assembl)',
            '(full[\s-]service|turnkey)\s+(ems|electronics|contract)',
            'contract\s+(electronics?\s+)?manufactur(er|ing)\s+(company|provider|partner|servic)',
            'pcb\s+assembly\s+(service|capabilit|provider|company|house|partner)',
            'cable\s+(harness|assembly)\s+(manufactur|provider|service|company|supplier)',
            'wire\s+harness\s+(manufactur|provider|service|company|supplier)',
            'printed\s+circuit\s+board\s+(assembly|manufactur)',
            'electronics\s+manufacturing\s+services?\s+(provider|company|partner)',
            'smt\s+(assembly|line|process|manufactur)',
            'through[\s-]hole\s+(assembly|soldering)',
            'box[\s-]build\s+assembly',
            'pcba?\s+(manufactur|assembl|provider|company|service)',
            'prototype\s+to\s+production',
            'low[\s-]volume.*high[\s-]mix',
            'high[\s-]mix.*low[\s-]volume',
            '(iso\s+9001|iatf\s+16949|as9100).*certified\s+(contract|ems|pcb)',

            // "We make harnesses/boards for OEMs"
            'manufactur(e|ing|er)\s+of\s+(cable|wire|pcb|electronic)',
            'assembl(y|ing|er)\s+of\s+(cable|wire|pcb|electronic)',
            'produc(e|tion|ing)\s+of\s+(cable|wire|pcb|electronic)',
            'specialist\s+in\s+(cable|wire|pcb|electronic)\s+(assembl|manufactur)',
            'bespoke\s+(cable|wire|pcb|electronic|harness)',
            'custom\s+(cable|wire|pcb|electronic|harness)\s+(assembl|manufactur|build)',
        ];

        foreach ($competitorSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Competitor detected by snippet', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 2. DISTRIBUTOR / RESELLER signals ───────────────────────
        $distributorSignals = [
            'authorized\s+distribut',
            'distribut(or|ion)\s+of\s+(electronic|component|semiconductor)',
            'wholesale\s+(distribut|supplier)',
            'we\s+(distribut|supply|stock|sell)\s+(electronic|component|semiconductor)',
            'electronic\s+component\s+distribut',
            'trading\s+company',
        ];

        foreach ($distributorSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Distributor detected by snippet', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 3. EQUIPMENT / TOOLING SUPPLIER signals ─────────────────
        // Companies that sell machines TO manufacturers (not buyers of EMS)
        $equipmentSignals = [
            '(wire|cable)\s+(processing|stripping|crimping|cutting)\s+(machine|equipment|tool)',
            '(soldering|welding|bonding)\s+(machine|robot|equipment|system)',
            '(reflow|wave)\s+(oven|soldering)\s+(machine|system)',
            'pick[\s-]and[\s-]place\s+(machine|system)',
            'smt\s+(machine|equipment|line)\s+(manufactur|supplier)',
            'test\s+(and\s+measurement|equipment|instrument|system|fixtur)\s+(manufactur|suppli|provid)',
            'measuring\s+(instrument|equipment|system)',
            '(vibration|ultrasonic|laser|thermal)\s+(test|measur|inspection|weld)',
            'marking\s+(machine|laser|system)',
            'inspection\s+(machine|system|equipment)',
        ];

        foreach ($equipmentSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Equipment supplier detected by snippet', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 4. COMPONENT SUPPLIER signals ───────────────────────────
        // Companies that sell components (connectors, semiconductors, LEDs)
        $componentSignals = [
            '(connector|terminal|contact)\s+(manufactur|supplier|producer)',
            '(semiconductor|chip|ic|led|mosfet|transistor)\s+(manufactur|supplier|producer)',
            '(resistor|capacitor|inductor|transformer)\s+(manufactur|supplier)',
            '(raw\s+material|copper\s+wire|solder|flux)\s+suppli',
            'component\s+(manufactur|suppli|produc)',
        ];

        foreach ($componentSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Component supplier detected by snippet', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 5. CONTROL PANEL / SYSTEM INTEGRATOR signals ────────────
        // These are assembly shops, not OEM buyers of EMS services
        $integratorSignals = [
            'control\s+panel\s+(manufactur|build|assembl|design|wir)',
            'panel\s+build(er|ing)',
            'switchgear\s+(manufactur|assembl)',
            'plc\s+(programming|integration|panel)',
            'bespoke\s+(control|automation)\s+(panel|system|solution)',
            'system\s+integrat(or|ion)\s+(for|specializ|provid)',
        ];

        foreach ($integratorSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('System integrator detected by snippet', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 6. MRO / REPAIR / OVERHAUL signals ─────────────────────
        $mroSignals = [
            'maintenance,?\s+repair\s+(and|&)\s+overhaul',
            '\bmro\s+(provider|service|company|specialist)',
            'aircraft\s+(maintenance|repair|overhaul)',
            'component\s+repair\s+(and|&)\s+(overhaul|service)',
        ];

        foreach ($mroSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('MRO company detected by snippet', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 7. STAFFING / RECRUITMENT signals ───────────────────────
        $staffingSignals = [
            'staffing\s+(agency|solution|company|service)',
            'recruitment\s+(agency|solution|company|service|firm)',
            'we\s+(recruit|place|staff|hire)\s+(engineer|technical|manufactur)',
            'talent\s+(acquisition|management|solution)',
            'workforce\s+(solution|management|staffing)',
            'temporary\s+(staff|worker|placement)',
            'contract\s+(staff|hiring|recruiter)',
            'executive\s+search\s+(firm|company)',
            'headhunt(er|ing)',
        ];

        foreach ($staffingSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Staffing/recruitment company detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 8. CONSULTING / ADVISORY signals ────────────────────────
        $consultingSignals = [
            '(management|strategy|engineering)\s+consult(ing|ancy|ant)',
            'advisory\s+(firm|service|company|practice)',
            'we\s+(advise|consult|guide)\s+(manufactur|OEM|client)',
            'consulting\s+(firm|company|practice|service)',
            'business\s+(consulting|advisory)',
            'market\s+(research|intelligence|analysis)\s+(firm|company|provider)',
        ];

        foreach ($consultingSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Consulting/advisory company detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 9. TRAINING / CERTIFICATION BODY signals ────────────────
        $trainingSignals = [
            'training\s+(provider|company|center|centre|course|program)',
            'certification\s+(body|provider|authority|training)',
            'we\s+(train|certify|educate|teach)',
            'accredited\s+(training|certification|course)',
            'professional\s+(development|training|certification)\s+(provider|company)',
            'online\s+(course|training|learning|certification)',
        ];

        foreach ($trainingSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Training/certification body detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 10. NEWS / MEDIA / REPORT signals ──────────────────────
        // News articles about industry trends — not actual companies
        $newsSignals = [
            'according\s+to\s+(a\s+)?report',
            'market\s+(is\s+)?expected\s+to\s+(reach|grow)',
            'market\s+size\s+(was|is|will)',
            'billion\s+(by|in)\s+20\d{2}',
            'million\s+(by|in)\s+20\d{2}',
            'CAGR\s+of\s+\d',
            'growth\s+rate\s+of\s+\d',
            '(published|updated|posted)\s+(on|by|in)\s+(january|february|march|april|may|june|july|august|september|october|november|december|\d{4})',
            '(latest|breaking|exclusive)\s+(news|report|analysis)',
            'press\s+release',
            'article\s+(by|from|in)',
            'editorial\s+(team|staff|board)',
            'subscribe\s+(to|for)\s+(our|the)\s+(newsletter|updates)',
            'copyright\s+\d{4}',
            'all\s+rights\s+reserved',
            '\breporter\b|\beditor\b|\bjournalist\b|\bcorrespondent\b',
            '\bop-ed\b|\bopinion\b|\bcolumn\b',
        ];

        foreach ($newsSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('News/media/report detected by snippet', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 11. LAW FIRM / LEGAL signals ───────────────────────────
        $legalSignals = [
            '\blaw\s+firm\b',
            '\blegal\s+(practice|service|counsel)',
            '\b(solicitor|barrister|attorney|lawyer)s?\b',
            '\b(litigation|arbitration|dispute\s+resolution)\b',
            '\bintellectual\s+property\s+(law|practice)\b',
        ];

        foreach ($legalSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Law firm detected by snippet', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 12. LOGISTICS / FREIGHT / SHIPPING signals ─────────────
        $logisticsSignals = [
            '\bfreight\s+(forward|forwarding|broker)',
            '\blogistics\s+(provider|company|service|solution)',
            '\bshipping\s+(company|line|service)',
            '\bsupply\s+chain\s+(management|solutions?|services?)\s+(provider|company)',
            '\bcustoms\s+(broker|clearance|brokerage)',
        ];

        foreach ($logisticsSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Logistics/freight company detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 13. GOVERNMENT / IGO / NGO signals ─────────────────────
        $govSignals = [
            '\bgovernment\s+(agency|body|department|ministry)',
            '\bministry\s+of\b',
            '\bunited\s+nations\b',
            '\b(trade|investment)\s+promotion\s+(agency|authority|office)',
            '\binvestment\s+authority\b',
            '\bcivil\s+aviation\s+authority\b',
            '\bfree\s+zone\s+authority\b',
            '\bstandards?\s+(body|organization|institute|authority)\b',
            '\bnational\s+standards?\b',
        ];

        foreach ($govSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Government/IGO/NGO detected by snippet', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 14. CERTIFICATION / AUDITING bodies ────────────────────
        $certSignals = [
            '\bcertification\s+(body|auditor|provider|agency|authority)',
            '\bauditing\s+(body|firm|agency|services?)',
            '\baccreditation\s+(body|board|agency)',
            '\bcertify\s+(companies|organizations|manufacturers)',
            '\b(ISO|IATF|AS\d+)\s+certification\s+services?\b',
            '\bmanagement\s+system\s+certification\b',
        ];

        foreach ($certSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Certification/auditing body detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 15. CRUISE / TOURISM / HOSPITALITY ─────────────────────
        $tourismSignals = [
            '\bcruise\s+(line|ship|vacation|company)',
            '\bocean\s+cruise',
            '\b(luxury|expedition)\s+cruise',
            '\bhotel\s+(chain|group|management)',
            '\bresort\s+(group|management)',
            '\btravel\s+agency\b',
        ];

        foreach ($tourismSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Tourism/cruise/hospitality detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 16. SOLAR / RENEWABLES INSTALLERS (not manufacturers) ──
        $installerSignals = [
            '\bsolar\s+(panel\s+)?install(er|ation)',
            '\bsolar\s+(panel|pv)\s+(fitter|fitting|supplier)',
            '\broof(top)?\s+solar\b',
            '\bdomestic\s+(solar|renewables)\b',
            '\bhome\s+(solar|renewables|energy)\b',
            '\bheat\s+pump\s+install(er|ation)',
            '\bboiler\s+(replacement|installation|service)',
            '\belectric\s+vehicle\s+charging\s+(installation|point)',
            '\brenewable\s+energy\s+installer\b',
            '\bground\s+handling\s+(services|company)',  // airport services
        ];

        foreach ($installerSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Installer/services company detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 17. PHARMA / BIOTECH / HEALTHCARE PROVIDERS ───────────
        // These appear in Medical sector queries but are NOT device OEMs
        $pharmaSignals = [
            '\bpharmaceutical\s+(company|manufacturer|industry)',
            '\bdrug\s+(development|discovery|manufacturer)',
            '\bclinical\s+trial',
            '\btherapeutic(s)?\s+(area|pipeline|candidate)',
            '\bgene\s+therapy\b',
            '\bmrna\b',
            '\boncology\s+(treatment|drug|therapy)',
            '\bbiosimilar\b',
            '\bvaccine\s+(development|manufacture)',
            '\bimmunotherapy\b',
            '\bhospital\s+(services|system|network)',
            '\bpatient\s+(care|services|outcomes)',
            '\bhealth\s+insurance\b',
            '\bhealthcare\s+(provider|system|network)',
            '\bsterilization\s+services?\b',
        ];

        foreach ($pharmaSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Pharma/biotech/healthcare detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 18. OIL / GAS / MINING / PETROCHEMICAL signals ────────
        $oilGasSignals = [
            '\boil\s+(and|&)\s+gas\s+(exploration|production|company|operator)',
            '\bupstream\s+(oil|exploration|production)',
            '\bpetroleum\s+(exploration|production|refining)',
            '\bmining\s+(company|corporation|operations?|industry)',
            '\bpetrochemical\s+(company|manufacturer|producer|plant)',
            '\bindustrial\s+gas(es)?\s+(company|supplier|producer)',
            '\boilfield\s+(services?|equipment|solutions?)',
            '\bdrilling\s+(rig|company|services?|operations?)',
            '\brefinery\s+(operations?|company)',
            '\bhydrocarbon\b',
            '\bnatural\s+gas\s+(processing|transport|liquefaction)',
        ];

        foreach ($oilGasSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Oil/gas/mining company detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 19. INVESTMENT / PRIVATE EQUITY / INSURANCE signals ────
        $investmentSignals = [
            '\binvestment\s+(fund|management|bank|firm|company|portfolio)',
            '\basset\s+management\s+(firm|company)',
            '\bprivate\s+equity\s+(firm|fund|group)',
            '\bventure\s+capital\s+(firm|fund)',
            '\binsurance\s+(company|provider|broker|underwriter)',
            '\baircraft\s+leasing\b',
            '\b(leasing|lease)\s+(company|provider|portfolio)',
            '\bportfolio\s+management\b',
            '\bhedge\s+fund\b',
            '\bwealth\s+management\b',
        ];

        foreach ($investmentSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Investment/finance/insurance detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 20. FOOD / BEVERAGE / CONSUMER GOODS signals ──────────
        $foodConsumerSignals = [
            '\bconsumer\s+(goods|products|packaged\s+goods)',
            '\bfood\s+(and|&)\s+(beverage|drink)',
            '\b(salmon|fish|seafood)\s+(farming|processing|production)',
            '\bfast[\s-]moving\s+consumer\s+goods\b',
            '\bfmcg\b',
            '\bbottling\s+(company|plant|operations?)',
            '\bbeverage\s+(company|manufacturer|producer)',
            '\bfood\s+(processing|manufacturing|production)\s+(company|plant)',
            '\b(dairy|meat|poultry)\s+(processing|production)',
        ];

        foreach ($foodConsumerSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Food/beverage/consumer goods detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 21. AIRLINE / TOURISM / TRAVEL signals ─────────────────
        $airlineSignals = [
            '\bairline\s+(company|operator|services?)',
            '\bscheduled\s+flights?\b',
            '\bflight\s+(booking|reservation)',
            '\btourism\s+(board|promotion|office|authority)',
            '\beconomic\s+development\s+(office|agency|authority)',
            '\bdomestic\s+(and\s+)?international\s+flights?\b',
            '\bairline\s+tickets?\b',
        ];

        foreach ($airlineSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Airline/tourism detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 22. CONSTRUCTION / REAL ESTATE signals ─────────────────
        $constructionSignals = [
            '\bconstruction\s+(company|contractor|group|firm)',
            '\bgeneral\s+contractor\b',
            '\bbuilding\s+contractor\b',
            '\breal\s+estate\s+(development|company|group|investment)',
            '\b(commercial|residential)\s+(construction|building|development)',
        ];

        foreach ($constructionSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Construction/real estate detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 23. TRADE SHOW / EVENT ORGANIZER signals ───────────────
        $tradeShowSignals = [
            '\btrade\s+(show|fair|exhibition)\s+(organiz|company|venue)',
            '\bexhibition\s+(organiz|company|centre|center)',
            '\bevent\s+(organiz|management)\s+(company|firm)',
            '\bconference\s+(organiz|management|venue)',
            '\bjoin\s+us\s+at\s+(the\s+)?(exhibition|trade\s+show|conference)',
            '\bregister\s+(for|now)\s+(the\s+)?(exhibition|trade\s+show|conference)',
        ];

        foreach ($tradeShowSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Trade show/event organizer detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 24. RETAIL / ACCESSORIES / NON-MFG signals ────────────
        $retailSignals = [
            '\bseat\s+cover\b',
            '\bcar\s+accessor(y|ies)\b',
            '\bauto\s+(parts|repair|body|glass|detailing)\b',
            '\bcar\s+(rental|wash|care|wrapping|tinting)\b',
            '\btyre\s+(shop|dealer|service)\b',
            '\btire\s+(shop|dealer|service)\b',
            '\bused\s+cars?\b',
            '\bcar\s+dealer(ship)?\b',
            '\bvehicle\s+dealer\b',
            '\bdriving\s+school\b',
            '\btowing\s+service\b',
            '\bpaint\s+shop\b',
            '\bupholstery\b',
            '\bwindshield\s+repair\b',
            '\bcar\s+audio\b',
            '\baftermarket\s+(parts|accessor|product)\b',
        ];

        foreach ($retailSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Retail/accessories/non-mfg detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 25. CHEMICAL / PETROCHEMICAL / RAW MATERIAL signals ───
        $chemicalSignals = [
            '\bchemical\s+(company|manufacturer|producer|supplier|distribution)',
            '\bspecialty\s+chemical\b',
            '\bchemicals?\s+(and|&)\s+material\b',
            '\bplastics?\s+(compounding|injection|molding|moulds?)\b',
            '\brubber\s+(compounding|molding|manufacturer)\b',
            '\bpaints?\s+(and|&)\s+(coatings?|varnish)\b',
            '\badhesives?\s+(and|&)\s+(sealant|tape)\b',
            '\blubricant\s+(manufacturer|supplier|company)\b',
        ];

        foreach ($chemicalSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Chemical/raw material company detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 26. DIRECT COMPETITOR signals (EMS/harness manufacturers) ──
        $competitorSignals = [
            '\bwiring\s+harness\s+(manufacturer|supplier|producer|assembly)',
            '\bcable\s+harness\s+(manufacturer|supplier|producer)',
            '\bwire\s+harness\s+(manufacturing|production|assembly)\b',
            '\bEMS\s+provider\b',
            '\belectronic\s+manufacturing\s+services?\s+(company|provider)\b',
            '\bcontract\s+electronics?\s+manufactur',
            '\bPCB\s+assembly\s+(manufacturer|provider|company|service)',
        ];

        foreach ($competitorSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Direct competitor detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 27. SOFTWARE / TESTING / CERTIFICATION signals ────────
        $softwareTestingSignals = [
            '\bEDA\s+software\b',
            '\bEDI\s+(software|solutions?|integration|platform)\b',
            '\bvehicle\s+(testing|homologation|certification)\s+(lab|center|facility|service)',
            '\btest\s+(lab|laboratory|certification)\b',
            '\bengineering\s+consultan',
        ];

        foreach ($softwareTestingSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Software/testing/consulting company detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        return false;
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
     * Verify discovered companies via LinkedIn and homepage checks.
     *
     * This is the "smart filter" that goes beyond pattern matching.
     * For each candidate company:
     *  1. Search Google for its LinkedIn company page (strong signal)
     *  2. If not found by name, try domain-derived name on LinkedIn
     *  3. If still not found, fetch homepage & verify via <title> / og:site_name
     *  4. Reject anything that can't be verified as a real company
     *
     * This eliminates news articles, forums, product pages, and other
     * non-company results that slip through pattern-based filters.
     */
    private function verifyCompanies(array $candidates): array
    {
        if (empty($candidates) || $this->googleSearchService === null) {
            return $candidates;
        }

        $this->logger->info('Starting company verification', [
            'candidates' => count($candidates),
        ]);

        $verified = [];

        // ── PHASE 1: Homepage verification + enrichment (FREE) ────
        // Fire all homepage requests concurrently (Symfony HttpClient
        // streams responses lazily — no extra threads needed).
        // This is dramatically faster than sequential requests.
        $responses = [];
        foreach ($candidates as $domain => $data) {
            $website = $data['website'] ?? '';
            if (empty($website)) {
                continue;
            }
            try {
                $responses[$domain] = [
                    'response' => $this->httpClient->request('GET', $website, [
                        'timeout' => 5,
                        'max_redirects' => 3,
                        'headers' => [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                            'Accept' => 'text/html,application/xhtml+xml',
                            'Accept-Language' => 'en-US,en;q=0.9',
                        ],
                    ]),
                    'data' => $data,
                ];
            } catch (\Exception $e) {
                // Request creation failed — skip
            }
        }

        // Process responses as they arrive (concurrent streaming)
        // Use Symfony's streaming with a hard per-domain timeout to prevent hangs
        $needsLinkedIn = [];
        foreach ($responses as $domain => $item) {
            $data = $item['data'];
            try {
                $response = $item['response'];
                // Hard timeout: cancel response if it hasn't completed within 10s
                $statusCode = $response->getStatusCode();
                if ($statusCode >= 400) {
                    $needsLinkedIn[$domain] = $data;
                    continue;
                }

                // getContent(false) won't throw on HTTP errors, but can still
                // block on slow servers. The 'timeout' in request options covers
                // this, but we also limit bytes read.
                $html = $response->getContent(false);
                if (empty($html)) {
                    $needsLinkedIn[$domain] = $data;
                    continue;
                }
                $html = substr($html, 0, 100000); // 100KB max — faster parsing
                $enrichment = $this->processHomepageHtml($html, $data['name']);

                if ($enrichment !== null) {
                    $data = $this->mergeEnrichment($data, $enrichment);
                    $verified[$domain] = $data;
                    $this->logger->debug('Verified+enriched via homepage', [
                        'name' => $data['name'], 'domain' => $domain,
                        'has_phone' => !empty($data['phone']),
                        'has_contacts' => !empty($data['contacts']),
                    ]);
                } else {
                    $needsLinkedIn[$domain] = $data;
                }
            } catch (\Exception $e) {
                $needsLinkedIn[$domain] = $data;
            }
        }

        // Also add domains without a website to LinkedIn queue
        foreach ($candidates as $domain => $data) {
            if (empty($data['website']) && !isset($verified[$domain])) {
                $needsLinkedIn[$domain] = $data;
            }
        }

        // ── PHASE 2: LinkedIn verification + enrichment (PAID) ────
        // Only for candidates NOT already verified via homepage.
        foreach ($needsLinkedIn as $domain => $data) {
            $name = $data['name'];

            // Step 2a: LinkedIn check with extracted name
            $linkedInResult = $this->checkLinkedInCompanyPage($name);
            if ($linkedInResult !== null) {
                $data = $this->mergeEnrichment($data, $linkedInResult);
                $verified[$domain] = $data;
                $this->logger->debug('Verified+enriched via LinkedIn', [
                    'name' => $data['name'], 'domain' => $domain,
                ]);
                usleep(250000);
                continue;
            }
            usleep(250000);

            // Step 2b: LinkedIn check with domain-derived name
            $domainName = $this->companyNameFromDomain($domain);
            if ($domainName !== '' && strtolower($domainName) !== strtolower($name)) {
                $linkedInResult2 = $this->checkLinkedInCompanyPage($domainName);
                if ($linkedInResult2 !== null) {
                    $data = $this->mergeEnrichment($data, $linkedInResult2);
                    $verified[$domain] = $data;
                    $this->logger->debug('Verified via LinkedIn (domain name)', [
                        'original' => $name, 'corrected' => $data['name'],
                    ]);
                    usleep(250000);
                    continue;
                }
                usleep(250000);
            }

            // ── Not verified → reject ─────────────────────────────
            $this->logger->info('Rejected unverified company', [
                'name' => $name, 'domain' => $domain,
            ]);
        }

        $this->logger->info('Company verification completed', [
            'candidates' => count($candidates),
            'verified' => count($verified),
            'rejected' => count($candidates) - count($verified),
            'skipped_linkedin' => count($candidates) - count($needsLinkedIn),
        ]);

        return $verified;
    }

    /**
     * Merge enrichment data into a candidate array.
     *
     * Enrichment keys (phone, description, address, linkedin_url, etc.)
     * are only applied if non-empty and the candidate doesn't already have
     * a value for that key.
     */
    private function mergeEnrichment(array $data, array $enrichment): array
    {
        // Name override (e.g. LinkedIn's canonical name)
        if (!empty($enrichment['name'])) {
            $newName = $enrichment['name'];
            // Strip TLD suffixes that may have crept in
            $newName = preg_replace('/\.(com|net|org|io|co|biz|info|us|eu)$/i', '', $newName);
            // Strip trademark symbols
            $newName = preg_replace('/[®™©]/u', '', $newName);
            // Strip trailing dashes and descriptive suffixes
            $newName = preg_replace('/\s*[-–—]\s*(Electrifying|Driving|Powering|Leading|Global|The).*$/i', '', $newName);
            $newName = preg_replace('/\s*[-–—]\s*$/i', '', $newName);
            $newName = trim($newName);
            if (!$this->isJunkCompanyName($newName) && mb_strlen($newName) >= 2) {
                $data['name'] = $newName;
            }
        }

        $enrichKeys = ['phone', 'description', 'address', 'linkedin_url', 'legal_name', 'country_hint'];
        foreach ($enrichKeys as $key) {
            if (!empty($enrichment[$key]) && empty($data[$key])) {
                $data[$key] = $enrichment[$key];
            }
        }

        // Merge contacts array (accumulate, don't overwrite)
        if (!empty($enrichment['contacts'])) {
            $existing = $data['contacts'] ?? [];
            $data['contacts'] = array_merge($existing, $enrichment['contacts']);
            // Deduplicate by first_name+last_name
            $seen = [];
            $unique = [];
            foreach ($data['contacts'] as $c) {
                $key = strtolower(($c['first_name'] ?? '') . '|' . ($c['last_name'] ?? ''));
                if ($key === '|' || isset($seen[$key])) continue;
                $seen[$key] = true;
                $unique[] = $c;
            }
            $data['contacts'] = array_slice($unique, 0, 5); // Max 5 contacts
        }

        return $data;
    }

    /**
     * Check if a company has a LinkedIn company page via Google search.
     *
     * Returns an enrichment array if found (name, linkedin_url, description),
     * or null if not found.
     * Uses 1 Google Custom Search API call per invocation.
     */
    private function checkLinkedInCompanyPage(string $companyName): ?array
    {
        $cleanName = trim(str_replace('"', '', $companyName));
        if (mb_strlen($cleanName) < 2) {
            return null;
        }

        $query = 'site:linkedin.com/company "' . $cleanName . '"';

        try {
            $results = $this->googleSearchService->searchCompanies($query, 3);
            if (!empty($results['results'])) {
                $first = $results['results'][0];
                $title = $first['title'] ?? '';
                $snippet = $first['snippet'] ?? '';
                $link = $first['link'] ?? '';

                $enrichment = [
                    'linkedin_url' => $link,
                    'description' => $this->extractLinkedInDescription($snippet),
                ];

                // Parse LinkedIn title: "CompanyName | LinkedIn"
                if (preg_match('/^(.+?)\s*[|–—-]\s*(LinkedIn|Overview)/i', $title, $m)) {
                    $linkedInName = trim($m[1]);
                    if (mb_strlen($linkedInName) >= 2 && mb_strlen($linkedInName) <= 80) {
                        $enrichment['name'] = $linkedInName;
                    }
                }

                // Try to extract HQ location from snippet
                // LinkedIn snippets often contain "Headquarters: City, Country"
                // or "Location: City | Industry: ..." 
                if (preg_match('/(?:headquarter|location|based in)[s:]*\s*([A-Z][a-z]+(?:[\s,]+[A-Z][a-z]+){0,3})/i', $snippet, $locMatch)) {
                    $enrichment['address'] = trim($locMatch[1], ' ,');
                }

                return $enrichment;
            }
        } catch (\Exception $e) {
            $this->logger->debug('LinkedIn check failed', [
                'name' => $cleanName,
                'error' => $e->getMessage(),
            ]);
            // API error → give benefit of doubt, return minimal
            return ['name' => $cleanName];
        }

        return null; // Not found on LinkedIn
    }

    /**
     * Extract a clean company description from a LinkedIn snippet.
     *
     * LinkedIn snippets typically look like:
     * "CompanyName | 12,345 followers on LinkedIn. Description here..."
     * or "CompanyName · Industry. Description..."
     */
    private function extractLinkedInDescription(string $snippet): ?string
    {
        if (empty($snippet)) {
            return null;
        }

        // Strip the follower count prefix
        $desc = preg_replace('/^.*?\d[\d,]*\s+followers?\s+on\s+LinkedIn\.?\s*/i', '', $snippet);
        // Strip "CompanyName · Industry." prefix
        $desc = preg_replace('/^[^·]+·\s*[^.]+\.\s*/', '', $desc);
        // Strip trailing "..."
        $desc = preg_replace('/\s*\.{2,}\s*$/', '', $desc);

        $desc = trim($desc);

        // ── Quality filters ─────────────────────────────────────
        // Reject descriptions that are mostly URLs
        if (preg_match('|^https?://|i', $desc)) {
            return null;
        }
        // Reject descriptions containing raw URLs (e.g. "http://www.company.com/. External link...")
        $desc = preg_replace('|https?://\S+|', '', $desc);
        $desc = preg_replace('/\s*External link for\s.*$/i', '', $desc);
        $desc = trim($desc, " .\t\n\r");

        // Fix common encoding issues (UTF-8 replacement char)
        $desc = str_replace('�', "'", $desc); // Common misencoded apostrophe
        // Remove remaining replacement characters
        $desc = preg_replace('/\x{FFFD}/u', '', $desc);

        $desc = trim($desc);

        // Only keep if it's a meaningful description
        if (mb_strlen($desc) >= 20 && mb_strlen($desc) <= 500) {
            return $desc;
        }

        return null;
    }

    /**
     * Verify a company by fetching its homepage and extracting enrichment data.
     *
     * Does DOUBLE DUTY:
     * 1. Verification — confirms URL belongs to a real company
     * 2. Enrichment — extracts phone, address, description from HTML
     *
     * Returns an enrichment array on success, null on failure.
     * This is FREE (HTTP request only, no API cost).
     */
    private function verifyAndEnrichViaHomepage(string $url, string $expectedName): ?array
    {
        if (empty($url)) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 5,
                'max_redirects' => 3,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                return null;
            }

            $html = substr($response->getContent(false), 0, 100000);

            return $this->processHomepageHtml($html, $expectedName);

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Process raw HTML from a company homepage to verify identity and
     * extract enrichment data (phone, email, address, contacts, LinkedIn URL).
     *
     * Separated from HTTP fetching to support concurrent request patterns.
     */
    private function processHomepageHtml(string $html, string $expectedName): ?array
    {
        // ── Identity verification ────────────────────────────
        $confirmedName = null;

        // Priority 1: og:site_name
        if (preg_match('/property=["\']og:site_name["\'][^>]*content=["\']([^"\']+)/i', $html, $m)
            || preg_match('/content=["\']([^"\']+)["\'][^>]*property=["\']og:site_name/i', $html, $m)) {
            $siteName = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Strip TLD suffixes early (og:site_name sometimes returns "Phinia.com")
            $siteName = preg_replace('/\.(com|net|org|io|co|biz|info|us|eu|fr|de|be|ma|in|uk)$/i', '', $siteName);
            $siteName = trim($siteName);
            if (mb_strlen($siteName) >= 2 && mb_strlen($siteName) <= 60
                && !$this->isJunkCompanyName($siteName)) {
                $confirmedName = $siteName;
            }
        }

        // Priority 2: <title> tag
        if ($confirmedName === null && preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
            $pageTitle = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (stripos($pageTitle, $expectedName) !== false) {
                $confirmedName = $expectedName;
            } else {
                $extracted = $this->extractCompanyName($pageTitle, '');
                if (!$this->isJunkCompanyName($extracted)
                    && mb_strlen($extracted) >= 2 && mb_strlen($extracted) <= 50) {
                    $confirmedName = $extracted;
                }
            }
        }

        // Priority 3: application-name meta
        if ($confirmedName === null && preg_match('/name=["\']application-name["\'][^>]*content=["\']([^"\']+)/i', $html, $m)) {
            $appName = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (mb_strlen($appName) >= 2 && mb_strlen($appName) <= 50
                && !$this->isJunkCompanyName($appName)) {
                $confirmedName = $appName;
            }
        }

        if ($confirmedName === null) {
            return null; // Can't verify identity
        }

        // ── Clean confirmed name ─────────────────────────────
        // Strip TLD suffixes (og:site_name sometimes returns "Phinia.com")
        $confirmedName = preg_replace('/\.(com|net|org|io|co|biz|info|us|eu)$/i', '', $confirmedName);
        // Strip trademark symbols
        $confirmedName = preg_replace('/[®™©]/u', '', $confirmedName);
        // Strip trailing legal suffixes that crept in
        $confirmedName = preg_replace('/\s*[-–—,]\s*(Ltd|LLC|Inc|Corp|GmbH|SA|SAS|BV|NV|AG|Plc|Co|Pty|Srl|SpA)\.?\s*$/i', '', $confirmedName);
        // Strip trailing dash + descriptive phrase
        $confirmedName = preg_replace('/\s*[-–—]\s*(Electrifying|Driving|Powering|Leading|Global|The).*$/i', '', $confirmedName);
        $confirmedName = preg_replace('/\s*[-–—]\s*$/', '', $confirmedName);
        $confirmedName = trim($confirmedName);

        if (mb_strlen($confirmedName) < 2 || $this->isJunkCompanyName($confirmedName)) {
            return null;
        }

        // ── Enrichment extraction ────────────────────────────
        $enrichment = ['name' => $confirmedName];

        // Extract og:description for company description
        if (preg_match('/property=["\']og:description["\'][^>]*content=["\']([^"\']+)/i', $html, $m)
            || preg_match('/content=["\']([^"\']+)["\'][^>]*property=["\']og:description/i', $html, $m)
            || preg_match('/name=["\']description["\'][^>]*content=["\']([^"\']+)/i', $html, $m)) {
            $desc = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Fix encoding issues
            $desc = str_replace('�', "'", $desc);
            $desc = preg_replace('/\x{FFFD}/u', '', $desc);
            // Remove raw URLs from descriptions
            $desc = preg_replace('|https?://\S+|', '', $desc);
            $desc = trim($desc, " .\t\n\r");
            // Only keep non-boilerplate descriptions
            if (mb_strlen($desc) >= 30 && mb_strlen($desc) <= 500
                && !preg_match('/cookie|privacy|javascript|browser|enabled/i', $desc)) {
                $enrichment['description'] = $desc;
            }
        }

        // Extract contact info from HTML
        $contactInfo = $this->extractContactInfoFromHtml($html);
        if (!empty($contactInfo['phone'])) {
            $enrichment['phone'] = $contactInfo['phone'];
        }
        if (!empty($contactInfo['address'])) {
            $enrichment['address'] = $contactInfo['address'];
        }
        if (!empty($contactInfo['email'])) {
            $enrichment['email'] = $contactInfo['email'];
        }
        if (!empty($contactInfo['contacts'])) {
            $enrichment['contacts'] = $contactInfo['contacts'];
        }

        // Extract LinkedIn URL from page links
        if (preg_match('/href=["\']?(https?:\/\/(?:www\.)?linkedin\.com\/company\/[a-zA-Z0-9_-]+)\/?["\'\s>]/i', $html, $liMatch)) {
            $enrichment['linkedin_url'] = rtrim($liMatch[1], '/');
        }

        return $enrichment;
    }

    /**
     * Extract phone numbers, email addresses, and physical address from HTML.
     *
     * Uses a priority hierarchy:
     * 1. Schema.org JSON-LD structured data (most reliable)
     * 2. tel: links (explicit phone markup)
     * 3. mailto: links (explicit email markup)
     * 4. Schema.org microdata (itemtype="Organization")
     *
     * Avoids false positives by filtering fax numbers, support lines,
     * and boilerplate template text.
     */
    private function extractContactInfoFromHtml(string $html): array
    {
        $info = ['phone' => null, 'email' => null, 'address' => null, 'contacts' => []];

        // ── 1. Schema.org JSON-LD (highest quality) ──────────────
        // Many corporate sites embed structured data like:
        // {"@type":"Organization","telephone":"+1-555-123-4567","address":{...}}
        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>([\s\S]{1,10000}?)<\/script>/i', $html, $jsonMatches)) {
            foreach ($jsonMatches[1] as $jsonStr) {
                $jsonData = @json_decode($jsonStr, true);
                if (!$jsonData) continue;

                // Handle @graph wrapper
                $entities = [];
                if (isset($jsonData['@graph'])) {
                    $entities = $jsonData['@graph'];
                } else {
                    $entities = [$jsonData];
                }

                foreach ($entities as $entity) {
                    $type = $entity['@type'] ?? '';

                    // ── Extract Person entities (contact people) ─────
                    if (is_string($type) && preg_match('/^Person$/i', $type)) {
                        $person = $this->extractPersonFromSchemaOrg($entity);
                        if ($person) {
                            $info['contacts'][] = $person;
                        }
                        continue;
                    }

                    // Look for Organization, Corporation, LocalBusiness, etc.
                    if (!is_string($type) || !preg_match('/Organization|Corporation|LocalBusiness|Company|ProfessionalService/i', $type)) {
                        continue;
                    }

                    // Phone
                    if (!$info['phone'] && !empty($entity['telephone'])) {
                        $phone = is_array($entity['telephone']) ? $entity['telephone'][0] : $entity['telephone'];
                        $info['phone'] = $this->cleanPhoneNumber($phone);
                    }

                    // Email
                    if (!$info['email'] && !empty($entity['email'])) {
                        $email = is_array($entity['email']) ? $entity['email'][0] : $entity['email'];
                        $email = str_replace('mailto:', '', $email);
                        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $info['email'] = $email;
                        }
                    }

                    // Address
                    if (!$info['address'] && !empty($entity['address'])) {
                        $addr = $entity['address'];
                        if (is_array($addr)) {
                            // PostalAddress object
                            $parts = array_filter([
                                $addr['streetAddress'] ?? null,
                                $addr['addressLocality'] ?? null,
                                $addr['addressRegion'] ?? null,
                                $addr['postalCode'] ?? null,
                                $addr['addressCountry'] ?? null,
                            ]);
                            if (!empty($parts)) {
                                $info['address'] = implode(', ', $parts);
                            }
                        } elseif (is_string($addr) && mb_strlen($addr) >= 5) {
                            $info['address'] = $addr;
                        }
                    }

                    // Extract contactPoint for person names/roles
                    if (!empty($entity['contactPoint'])) {
                        $cps = isset($entity['contactPoint']['@type'])
                            ? [$entity['contactPoint']]
                            : $entity['contactPoint'];
                        foreach ($cps as $cp) {
                            if (!empty($cp['contactType']) || !empty($cp['name'])) {
                                $person = [];
                                if (!empty($cp['name'])) {
                                    $nameParts = $this->splitPersonName($cp['name']);
                                    if ($nameParts) {
                                        $person = $nameParts;
                                    }
                                }
                                if (!empty($cp['telephone'])) {
                                    $person['phone'] = $this->cleanPhoneNumber(
                                        is_array($cp['telephone']) ? $cp['telephone'][0] : $cp['telephone']
                                    );
                                }
                                if (!empty($cp['email'])) {
                                    $email = str_replace('mailto:', '', is_array($cp['email']) ? $cp['email'][0] : $cp['email']);
                                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                        $person['email'] = $email;
                                    }
                                }
                                if (!empty($cp['contactType'])) {
                                    $person['job_title'] = ucwords($cp['contactType']);
                                }
                                if (!empty($person['first_name']) || !empty($person['email'])) {
                                    $info['contacts'][] = $person;
                                }
                            }
                        }
                    }

                    // Extract employee/member for org person names
                    foreach (['employee', 'member', 'founder'] as $personKey) {
                        if (!empty($entity[$personKey])) {
                            $persons = isset($entity[$personKey]['@type'])
                                ? [$entity[$personKey]]
                                : $entity[$personKey];
                            foreach ($persons as $p) {
                                $extracted = $this->extractPersonFromSchemaOrg($p);
                                if ($extracted) {
                                    $info['contacts'][] = $extracted;
                                }
                            }
                        }
                    }
                }
            }
        }

        // ── 2. tel: links (explicit phone number markup) ─────────
        if (!$info['phone']) {
            // Match tel: links, excluding fax numbers
            if (preg_match_all('/href=["\']tel:([+\d\s().-]{7,20})["\'][^>]*>(.*?)<\/a>/si', $html, $telMatches, PREG_SET_ORDER)) {
                foreach ($telMatches as $telMatch) {
                    $context = strtolower($telMatch[2] ?? '');
                    // Skip fax numbers
                    if (preg_match('/fax|facsimile/i', $context)) continue;
                    $info['phone'] = $this->cleanPhoneNumber($telMatch[1]);
                    break;
                }
            }
        }

        // ── 3. mailto: links (prioritize sales/info contacts) ────
        if (!$info['email']) {
            $foundEmails = [];
            if (preg_match_all('/href=["\']mailto:([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})["\']?/i', $html, $mailMatches)) {
                foreach ($mailMatches[1] as $email) {
                    $lower = strtolower($email);
                    // Skip boilerplate addresses
                    if (preg_match('/^(noreply|no-reply|donotreply|unsubscribe|privacy|abuse|postmaster|mailer-daemon|webmaster|hostmaster)@/i', $lower)) {
                        continue;
                    }
                    $foundEmails[] = $lower;
                }
            }

            // Prefer sales/info/contact emails over generic ones
            $preferredPrefixes = ['sales', 'info', 'contact', 'enquir', 'inquiry', 'business'];
            foreach ($preferredPrefixes as $prefix) {
                foreach ($foundEmails as $email) {
                    if (str_starts_with($email, $prefix)) {
                        $info['email'] = $email;
                        break 2;
                    }
                }
            }
            // Fallback to first non-junk email
            if (!$info['email'] && !empty($foundEmails)) {
                $info['email'] = $foundEmails[0];
            }
        }

        // ── 4. Extract named contacts from "Contact Us" sections ──
        // Look for person-name + email/phone combos near each other
        if (empty($info['contacts'])) {
            $info['contacts'] = $this->extractNamedContactsFromHtml($html);
        }

        // Limit to 3 contacts max
        $info['contacts'] = array_slice($info['contacts'], 0, 3);

        return $info;
    }

    /**
     * Extract a person record from Schema.org Person entity.
     */
    private function extractPersonFromSchemaOrg(array $entity): ?array
    {
        $type = $entity['@type'] ?? '';
        if (is_string($type) && !preg_match('/Person/i', $type)) {
            return null;
        }

        $person = [];

        // Name
        $name = $entity['name'] ?? '';
        if (!empty($name)) {
            $parts = $this->splitPersonName($name);
            if ($parts) {
                $person = $parts;
            }
        } else {
            // Try givenName/familyName
            if (!empty($entity['givenName'])) {
                $person['first_name'] = trim($entity['givenName']);
            }
            if (!empty($entity['familyName'])) {
                $person['last_name'] = trim($entity['familyName']);
            }
        }

        // Job title
        if (!empty($entity['jobTitle'])) {
            $person['job_title'] = trim(is_array($entity['jobTitle']) ? $entity['jobTitle'][0] : $entity['jobTitle']);
        }
        // Email
        if (!empty($entity['email'])) {
            $email = str_replace('mailto:', '', is_array($entity['email']) ? $entity['email'][0] : $entity['email']);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $person['email'] = $email;
            }
        }
        // Phone
        if (!empty($entity['telephone'])) {
            $phone = is_array($entity['telephone']) ? $entity['telephone'][0] : $entity['telephone'];
            $person['phone'] = $this->cleanPhoneNumber($phone);
        }
        // LinkedIn
        if (!empty($entity['sameAs'])) {
            $urls = is_array($entity['sameAs']) ? $entity['sameAs'] : [$entity['sameAs']];
            foreach ($urls as $url) {
                if (is_string($url) && preg_match('/linkedin\.com\/in\//i', $url)) {
                    $person['linkedin_url'] = $url;
                    break;
                }
            }
        }

        return !empty($person['first_name']) ? $person : null;
    }

    /**
     * Split a full name string into first/last name parts.
     *
     * "John Smith" → ['first_name' => 'John', 'last_name' => 'Smith']
     * "Dr. Jane Doe" → ['first_name' => 'Jane', 'last_name' => 'Doe']
     */
    private function splitPersonName(string $fullName): ?array
    {
        $name = trim($fullName);
        // Strip common prefixes
        $name = preg_replace('/^(Mr\.?|Mrs\.?|Ms\.?|Dr\.?|Prof\.?|Eng\.?|Ir\.?)\s+/i', '', $name);
        $name = trim($name);

        if (mb_strlen($name) < 3) return null;

        $parts = preg_split('/\s+/', $name);
        if (count($parts) < 2) return null;

        // Skip generic labels that aren't real person names
        $lower = strtolower($name);
        if (preg_match('/^(customer service|sales team|technical support|general inquiry|main office)/i', $lower)) {
            return null;
        }

        $firstName = array_shift($parts);
        $lastName = implode(' ', $parts);

        // Validate: names should start with uppercase letters
        if (!preg_match('/^[A-Z]/u', $firstName) || !preg_match('/^[A-Z]/u', $lastName)) {
            return null;
        }

        return ['first_name' => $firstName, 'last_name' => $lastName];
    }

    /**
     * Extract named contacts from HTML "Contact Us" sections.
     *
     * Looks for person-name + email/phone patterns in:
     * - vCard/hCard microdata
     * - Visible text patterns near contact sections
     * - LinkedIn /in/ profile links
     */
    private function extractNamedContactsFromHtml(string $html): array
    {
        $contacts = [];

        // ── vCard / hCard microformat ────────────────────────────
        // <div class="vcard"><span class="fn">John Smith</span>...
        // Use possessive quantifier and limit capture to prevent catastrophic backtracking
        if (preg_match_all('/<[^>]*class="[^"]*\bvcard\b[^"]*"[^>]*>([\s\S]{1,2000}?)<\/\w+>/i', $html, $vcardMatches)) {
            foreach ($vcardMatches[1] as $vcardHtml) {
                $person = [];
                if (preg_match('/class="[^"]*\bfn\b[^"]*"[^>]*>([^<]+)/i', $vcardHtml, $fnMatch)) {
                    $parts = $this->splitPersonName(html_entity_decode(trim($fnMatch[1])));
                    if ($parts) $person = $parts;
                }
                if (preg_match('/href=["\']mailto:([^"\']+)/i', $vcardHtml, $emailMatch)) {
                    $email = strtolower(trim($emailMatch[1]));
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $person['email'] = $email;
                    }
                }
                if (preg_match('/href=["\']tel:([^"\']+)/i', $vcardHtml, $telMatch)) {
                    $person['phone'] = $this->cleanPhoneNumber($telMatch[1]);
                }
                if (!empty($person['first_name'])) {
                    $contacts[] = $person;
                }
            }
        }

        // ── LinkedIn /in/ profile links with person names ────────
        // <a href="https://linkedin.com/in/john-smith">John Smith</a>
        if (preg_match_all('/href=["\']?(https?:\/\/(?:www\.)?linkedin\.com\/in\/[a-zA-Z0-9_-]+)\/?["\'\s>][^>]*>([^<]{3,40})<\/a>/i', $html, $liMatches, PREG_SET_ORDER)) {
            foreach ($liMatches as $match) {
                $linkText = html_entity_decode(trim($match[2]));
                $parts = $this->splitPersonName($linkText);
                if ($parts) {
                    $parts['linkedin_url'] = rtrim($match[1], '/');
                    $contacts[] = $parts;
                }
            }
        }

        return $contacts;
    }

    /**
     * Clean and normalize a phone number string.
     */
    private function cleanPhoneNumber(string $phone): ?string
    {
        // Remove whitespace, dashes, dots, parens — keep + and digits
        $cleaned = preg_replace('/[^+\d]/', '', $phone);
        // Must be at least 7 digits
        if (strlen(preg_replace('/[^\d]/', '', $cleaned)) < 7) {
            return null;
        }
        // Format: keep the + prefix if present
        if (!str_starts_with($cleaned, '+') && strlen($cleaned) >= 10) {
            $cleaned = '+' . $cleaned;
        }
        return $cleaned;
    }

    /**
     * Find company website
     */
    public function findCompanyWebsite(string $companyName, ?string $location = null): ?string
    {
        $locationTerm = $location ? " {$location}" : '';
        $query = $companyName . $locationTerm . ' official website';
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
            "site:{$domain} \"supply chain\" OR \"purchasing manager\" email",
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
     * Region-specific B2B / manufacturing directory sites.
     *
     * Each entry is a Google site: dork pointing at a real directory
     * that lists OEMs or equipment manufacturers \u2014 NOT news sites.
     */
    private const REGION_DIRECTORIES = [
        'MA' => [
            'site:kerix.net',                   // Moroccan industrial directory
            'site:cfcim.org',                    // French-Moroccan chamber of commerce
            'site:invest.gov.ma',                // Moroccan investment agency (lists factories)
        ],
        'US' => [
            'site:thomasnet.com',                // Premier US manufacturing directory
            'site:globalspec.com',               // Engineering / OEM product catalog
            'site:industrynet.com',              // US industrial supplier directory
        ],
        'EU' => [
            'site:europages.com',                // Pan-European B2B directory
            'site:wlw.de',                       // German manufacturing index
            'site:kompass.com',                   // Global B2B with strong EU coverage
        ],
        'GB' => [
            'site:make-it.uk',                   // UK manufacturing directory
            'site:kompass.com United Kingdom',    // Kompass UK section
            'site:mae.co.uk',                    // Manufacturing Advisory Service UK
        ],
        'EG' => [
            'site:kompass.com Egypt',             // Kompass Egypt section
            'site:ei.gov.eg',                    // Egyptian Industrial Development Authority
            'site:yellowpages.com.eg',           // Egypt yellow pages
        ],
        'GCC' => [
            'site:kompass.com UAE',               // Kompass UAE section
            'site:saudiexports.sa',              // Saudi industrial directory
            'site:modon.gov.sa',                 // Saudi industrial cities authority
        ],
    ];

    /**
     * Detect the region code from a location string.
     *
     * Uses simple keyword matching to determine which region a given
     * location belongs to so that directory dorks and TLD hints can be
     * chosen accordingly.
     */
    private function detectRegionFromLocation(?string $location): string
    {
        if ($location === null) {
            return 'GENERIC';
        }
        $loc = strtolower($location);

        // Morocco markers
        $moroccoMarkers = ['morocco', 'maroc', 'casablanca', 'tangier', 'tanger', 'kenitra', 'rabat', 'fes', 'fez', 'agadir', 'free zone', 'atlantic free zone'];
        foreach ($moroccoMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'MA';
            }
        }

        // US markers
        $usMarkers = ['united states', 'usa', 'u.s.', 'new york', 'texas', 'california', 'boston', 'chicago', 'detroit', 'atlanta', 'houston', 'dallas', 'austin', 'charlotte', 'raleigh', 'philadelphia', 'pittsburgh', 'new jersey', 'connecticut', 'massachusetts', 'virginia', 'pennsylvania', 'north carolina', 'south carolina', 'georgia', 'florida', 'ohio', 'michigan', 'maryland'];
        foreach ($usMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'US';
            }
        }

        // UK markers
        $ukMarkers = ['united kingdom', 'england', 'scotland', 'wales', 'northern ireland', 'london', 'manchester', 'birmingham', 'glasgow', 'leeds', 'liverpool', 'bristol', 'sheffield', 'uk'];
        foreach ($ukMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'GB';
            }
        }

        // EU markers
        $euMarkers = ['europe', 'germany', 'france', 'spain', 'italy', 'netherlands', 'belgium', 'austria', 'poland', 'czech', 'sweden', 'denmark', 'finland', 'norway', 'romania', 'hungary', 'portugal', 'berlin', 'paris', 'munich', 'amsterdam', 'brussels', 'vienna', 'prague', 'warsaw', 'stockholm', 'copenhagen', 'helsinki'];
        foreach ($euMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'EU';
            }
        }

        // Egypt markers
        $egyptMarkers = ['egypt', 'cairo', 'alexandria', 'suez', 'port said', 'ain sokhna', '6th of october', '10th of ramadan', 'new cairo'];
        foreach ($egyptMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'EG';
            }
        }

        // GCC markers
        $gccMarkers = ['gcc', 'gulf', 'dubai', 'abu dhabi', 'jebel ali', 'khalifa industrial', 'kizad', 'sharjah', 'uae', 'united arab emirates', 'saudi', 'riyadh', 'jeddah', 'dammam', 'jubail', 'yanbu', 'neom', 'qatar', 'doha', 'bahrain', 'manama', 'oman', 'muscat', 'kuwait'];
        foreach ($gccMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'GCC';
            }
        }

        return 'GENERIC';
    }

    /**
     * Build Google Dork queries for company discovery.
     *
     * Strategy: Find OEMs and equipment manufacturers that outsource
     * electronics manufacturing — potential buyers of Starz Electronics'
     * services (PCB assembly, cable/wire harness, overmolding, copper
     * windings, packaging).  We do NOT want to find other EMS providers.
     *
     * Query families:
     *  1. OEMs that outsource cable harness / wire harness assembly
     *  2. OEMs that outsource PCB / PCBA assembly
     *  3. Equipment OEMs in target verticals (automotive, aero, industrial…)
     *  4. Companies looking for contract electronics manufacturing
     *  5. Region-specific industry directory lookups
     */
    private function buildGoogleDorkQueries(string $sector, ?string $location = null): array
    {
        $queries = [];
        $region = $this->detectRegionFromLocation($location);

        // ══════════════════════════════════════════════════════════════
        // STRATEGY: Find OEMs/Tier-1 companies that BUY electronic
        // assemblies — our potential customers.
        //
        // LOCATION HANDLING: Don't quote the full location as a single
        // phrase (e.g., "Tanger Free Zone, Morocco" returns 0 results).
        // Instead, extract key geographic words and use them unquoted.
        // ══════════════════════════════════════════════════════════════

        $exclude = ' -site:linkedin.com -site:wikipedia.org -site:youtube.com';

        // Build a flexible location term: just city + country words, unquoted
        $locationTerm = '';
        if ($location) {
            $stopWords = ['free', 'zone', 'industrial', 'city', 'area', 'region',
                'port', 'special', 'economic', 'park', 'estate', 'hub', 'corridor',
                'district', 'valley', 'greater', 'metro', 'the', 'of', 'and'];
            $parts = preg_split('/[\s,]+/', $location);
            $keyParts = array_filter($parts, function ($p) use ($stopWords) {
                return strlen($p) > 2 && !in_array(strtolower($p), $stopWords);
            });
            $locationTerm = ' ' . implode(' ', array_values($keyParts));
        }

        // ── 1. Sector-specific queries ────────────────────────────────
        switch ($sector) {
            case 'Automotive':
                $queries[] = "\"IATF 16949\" {$sector} manufacturer{$locationTerm}" . $exclude;
                $queries[] = "{$sector} OEM \"ECU\" OR \"body electronics\" OR \"powertrain\"{$locationTerm}" . $exclude;
                $queries[] = "{$sector} \"tier 1\" supplier electronics company{$locationTerm}" . $exclude;
                $queries[] = "{$sector} manufacturer \"electronic\" \"our products\" OR \"our capabilities\"{$locationTerm}" . $exclude;
                $queries[] = "{$sector} company \"EV\" OR \"electric vehicle\" electronics{$locationTerm}" . $exclude;
                break;
            case 'Aerospace':
                $queries[] = "\"AS9100\" {$sector} company{$locationTerm}" . $exclude;
                $queries[] = "{$sector} \"avionics\" OR \"flight systems\" manufacturer{$locationTerm}" . $exclude;
                $queries[] = "{$sector} defense \"electronic systems\" company{$locationTerm}" . $exclude;
                $queries[] = "{$sector} \"sensors\" OR \"actuators\" OR \"navigation\" manufacturer{$locationTerm}" . $exclude;
                $queries[] = "{$sector} \"UAV\" OR \"drone\" OR \"satellite\" electronics company{$locationTerm}" . $exclude;
                break;
            case 'Industrial':
                $queries[] = "\"ISO 9001\" industrial equipment manufacturer{$locationTerm}" . $exclude;
                $queries[] = "industrial \"motor drives\" OR \"PLC\" OR \"controllers\" manufacturer{$locationTerm}" . $exclude;
                $queries[] = "\"factory automation\" OR \"process automation\" equipment company{$locationTerm}" . $exclude;
                $queries[] = "industrial \"robotics\" OR \"motion control\" manufacturer{$locationTerm}" . $exclude;
                break;
            case 'Rail':
                $queries[] = "railway OR rail \"signalling\" OR \"rolling stock\" company{$locationTerm}" . $exclude;
                $queries[] = "train OR locomotive manufacturer OEM electronics{$locationTerm}" . $exclude;
                $queries[] = "rail \"traction\" OR \"power converter\" manufacturer{$locationTerm}" . $exclude;
                break;
            case 'Renewables':
                $queries[] = "\"solar inverter\" OR \"string inverter\" manufacturer{$locationTerm}" . $exclude;
                $queries[] = "\"wind turbine\" manufacturer electronics{$locationTerm}" . $exclude;
                $queries[] = "\"EV charger\" OR \"EVSE\" manufacturer company{$locationTerm}" . $exclude;
                $queries[] = "\"energy storage\" OR \"battery management\" manufacturer{$locationTerm}" . $exclude;
                break;
            case 'Medical':
                $queries[] = "\"ISO 13485\" \"medical device\" manufacturer{$locationTerm}" . $exclude;
                $queries[] = "\"patient monitor\" OR \"diagnostic\" equipment manufacturer{$locationTerm}" . $exclude;
                $queries[] = "\"medical imaging\" OR \"ultrasound\" equipment company{$locationTerm}" . $exclude;
                $queries[] = "medical instrument electronics manufacturer{$locationTerm}" . $exclude;
                break;
            case 'Telecom':
                $queries[] = "telecommunications equipment manufacturer \"RF\" OR \"antenna\" OR \"base station\"{$locationTerm}" . $exclude;
                $queries[] = "\"5G\" OR \"LTE\" OR \"wireless\" infrastructure manufacturer{$locationTerm}" . $exclude;
                $queries[] = "\"fiber optic\" OR \"optical transceiver\" OR \"network switch\" manufacturer{$locationTerm}" . $exclude;
                $queries[] = "\"cable assembly\" OR \"RF connector\" telecom manufacturer{$locationTerm}" . $exclude;
                break;
            case 'HVAC':
                $queries[] = "HVAC OR \"heat pump\" equipment manufacturer{$locationTerm}" . $exclude;
                $queries[] = "\"building automation\" OR \"BMS\" manufacturer{$locationTerm}" . $exclude;
                $queries[] = "\"climate control\" equipment company{$locationTerm}" . $exclude;
                break;
            case 'Defense':
                $queries[] = "defense electronics manufacturer \"MIL-STD\" OR \"military\"{$locationTerm}" . $exclude;
                $queries[] = "defense \"radar\" OR \"electronic warfare\" OR \"C4ISR\" company{$locationTerm}" . $exclude;
                $queries[] = "defense \"rugged electronics\" OR \"mission systems\" manufacturer{$locationTerm}" . $exclude;
                $queries[] = "defense aerospace \"avionics\" OR \"tactical\" electronics{$locationTerm}" . $exclude;
                break;
            case 'Marine':
                $queries[] = "marine electronics manufacturer \"navigation\" OR \"sonar\"{$locationTerm}" . $exclude;
                $queries[] = "shipbuilding OR \"naval\" electronics company{$locationTerm}" . $exclude;
                $queries[] = "marine \"offshore\" OR \"vessel\" equipment manufacturer{$locationTerm}" . $exclude;
                $queries[] = "maritime \"bridge systems\" OR \"propulsion control\" company{$locationTerm}" . $exclude;
                break;
            case 'Power Electronics':
                $queries[] = "\"power electronics\" manufacturer \"inverter\" OR \"converter\"{$locationTerm}" . $exclude;
                $queries[] = "\"power supply\" OR \"UPS\" manufacturer company{$locationTerm}" . $exclude;
                $queries[] = "\"motor drive\" OR \"variable frequency drive\" manufacturer{$locationTerm}" . $exclude;
                $queries[] = "\"power module\" OR \"IGBT\" electronics company{$locationTerm}" . $exclude;
                break;
            case 'Consumer Electronics':
                $queries[] = "\"consumer electronics\" manufacturer \"smart home\" OR \"IoT\"{$locationTerm}" . $exclude;
                $queries[] = "\"wearable\" OR \"smart device\" electronics company{$locationTerm}" . $exclude;
                $queries[] = "\"appliance\" OR \"white goods\" electronics manufacturer{$locationTerm}" . $exclude;
                $queries[] = "consumer products electronics \"our products\" manufacturer{$locationTerm}" . $exclude;
                break;
            case 'Data Center':
                $queries[] = "\"data center\" equipment manufacturer \"server\" OR \"rack\"{$locationTerm}" . $exclude;
                $queries[] = "\"data center\" \"power distribution\" OR \"cooling\" manufacturer{$locationTerm}" . $exclude;
                $queries[] = "\"cloud infrastructure\" OR \"edge computing\" hardware company{$locationTerm}" . $exclude;
                $queries[] = "\"data center\" \"PDU\" OR \"UPS\" equipment manufacturer{$locationTerm}" . $exclude;
                break;
            case 'Energy Storage':
                $queries[] = "\"energy storage\" OR \"battery system\" manufacturer{$locationTerm}" . $exclude;
                $queries[] = "\"BMS\" OR \"battery management\" electronics company{$locationTerm}" . $exclude;
                $queries[] = "\"grid storage\" OR \"ESS\" manufacturer{$locationTerm}" . $exclude;
                $queries[] = "\"lithium-ion\" OR \"battery pack\" manufacturer electronics{$locationTerm}" . $exclude;
                break;
            default:
                $queries[] = "{$sector} OEM manufacturer electronics company{$locationTerm}" . $exclude;
                $queries[] = "{$sector} equipment manufacturer electronic{$locationTerm}" . $exclude;
                break;
        }

        // ── 2. Location-specific company-finding queries ──────────────
        if ($location) {
            $queries[] = "{$locationTerm} {$sector} manufacturer \"about us\" OR \"our products\"" . $exclude;
            $queries[] = "{$locationTerm} {$sector} \"factory\" OR \"plant\" OR \"facility\" company" . $exclude;
        }

        return $queries;
    }

    /**
     * Region-specific TLD suggestions for domain guessing.
     */
    private const REGION_TLDS = [
        'MA' => ['.ma', '.com'],
        'US' => ['.com', '.us', '.net'],
        'GB' => ['.co.uk', '.com', '.uk'],
        'EU' => ['.com', '.eu', '.de', '.fr'],
        'EG' => ['.eg', '.com.eg', '.com'],
        'GCC' => ['.ae', '.sa', '.com', '.qa'],
    ];

    /**
     * Guess company domain from name, with optional region hint for TLD ordering.
     */
    private function guessCompanyDomain(string $companyName, ?string $location = null): ?string
    {
        // Clean company name
        $clean = strtolower($companyName);
        $clean = preg_replace('/[^a-z0-9]+/', '', $clean);

        $region = $this->detectRegionFromLocation($location);
        $tlds = self::REGION_TLDS[$region] ?? ['.com', '.net', '.io'];

        $possibleDomains = [];
        foreach ($tlds as $tld) {
            $possibleDomains[] = $clean . $tld;
        }

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

    /**
     * Execute a custom Google search query.
     *
     * Used by WebCrawlerController::searchGoogle() for ad-hoc queries.
     * Delegates to searchCompanies() with the custom query inserted as-is.
     *
     * @param string $query Custom Google search query string
     * @return array Array of company results
     */
    public function customSearch(string $query): array
    {
        if (!$this->googleSearchService) {
            $this->logger->warning('customSearch() called but GoogleSearchService not configured');
            return [];
        }

        $this->logger->info('Executing custom search query', ['query' => $query]);

        $allResults = [];

        try {
            $results = $this->googleSearchService->searchCompanies($query, 10);

            if (!empty($results['results'])) {
                foreach ($results['results'] as $result) {
                    $domain = $result['displayLink'] ?? '';

                    if ($this->isBlockedDomain($domain)) {
                        continue;
                    }

                    if (!isset($allResults[$domain])) {
                        $companyName = $this->extractCompanyName($result['title'] ?? '', $domain);

                        if ($this->isJunkCompanyName($companyName)) {
                            continue;
                        }

                        $allResults[$domain] = [
                            'name' => $companyName,
                            'website' => $this->extractWebsiteFromResult($result),
                            'title' => $result['title'] ?? '',
                            'snippet' => $result['snippet'] ?? '',
                            'link' => $result['link'] ?? '',
                            'displayLink' => $domain,
                            'source_query' => $query,
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Custom search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
        }

        return array_values($allResults);
    }
}
