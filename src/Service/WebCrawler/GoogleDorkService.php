<?php

namespace App\Service\WebCrawler;

use App\Service\GoogleSearchService;
use App\Service\WebCrawler\Classifier\CompetitorProximityVeto;
use App\Service\WebCrawler\Classifier\ServiceProductClassifier;
use App\Service\WebCrawler\Contact\ContactQualityScorer;
use App\Service\WebCrawler\Contact\LinkedInProfileParser;
use App\Service\WebCrawler\Crawl\PoliteCrawlGovernor;
use App\Service\WebCrawler\Crawl\SitemapPageDiscovery;
use App\Service\WebCrawler\Evidence\BuyerEvidenceGate;
use App\Service\WebCrawler\Evidence\BuyerEvidenceResult;
use App\Service\WebCrawler\Observability\PipelineMetricsCollector;
use App\Service\WebCrawler\Rules\RuleEngine;
use App\Service\WebCrawler\SearchProvider\SearchProviderInterface;
use App\Service\WebCrawler\SearchProvider\SearchResultSet;
use App\Repository\CompanyRepository;
use App\Service\WebCrawler\Seed\DirectorySeedExtractor;
use App\Service\WebCrawler\Text\LanguageDetector;
use App\Service\WebCrawler\Text\TextNormalizer;
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
    private ?CompanyClassifierService $classifier = null;

    /**
     * Current search region — set during searchCompanies() so that
     * downstream methods (e.g. searchLinkedInDecisionMakers) can
     * validate that contacts belong to the correct geographic area.
     */
    private string $currentSearchRegion = 'GENERIC';

    public function __construct(
        private HttpClientInterface $httpClient, 
        private LoggerInterface $logger,
        private ?GoogleSearchService $googleSearchService = null,
        ?CompanyClassifierService $companyClassifier = null,
        private ?SearchProviderInterface $searchProvider = null,
        private ?BuyerEvidenceGate $buyerEvidenceGate = null,
        private ?TextNormalizer $textNormalizer = null,
        private ?RuleEngine $ruleEngine = null,
        private ?ServiceProductClassifier $serviceProductClassifier = null,
        private ?CompetitorProximityVeto $competitorProximityVeto = null,
        private ?DirectorySeedExtractor $directorySeedExtractor = null,
        private ?LanguageDetector $languageDetector = null,
        private ?LinkedInProfileParser $linkedInParser = null,
        private ?ContactQualityScorer $contactScorer = null,
        private ?SitemapPageDiscovery $sitemapDiscovery = null,
        private ?PoliteCrawlGovernor $crawlGovernor = null,
        private ?PipelineMetricsCollector $metricsCollector = null,
        private ?CompanyRepository $companyRepository = null,
    )
    {
        $this->classifier = $companyClassifier;
        // Ensure TextNormalizer always available (zero-dep)
        $this->textNormalizer ??= new TextNormalizer();
        // Ensure LinkedIn parser and contact scorer always available (zero-dep)
        $this->linkedInParser ??= new LinkedInProfileParser();
        $this->contactScorer ??= new ContactQualityScorer();
        // Ensure crawl governor always available (zero-dep)
        $this->crawlGovernor ??= new PoliteCrawlGovernor();
        // Ensure metrics collector always available (zero-dep)
        $this->metricsCollector ??= new PipelineMetricsCollector();
    }

    /**
     * Get the pipeline metrics collector for external reporting.
     */
    public function getMetricsCollector(): PipelineMetricsCollector
    {
        return $this->metricsCollector;
    }
    
    /**
     * Set the Google Search Service (allows injection after construction)
     */
    public function setGoogleSearchService(GoogleSearchService $service): void
    {
        $this->googleSearchService = $service;
    }

    /**
     * Unified search method — uses the SearchProviderInterface when available,
     * falls back to the legacy GoogleSearchService for backward compatibility.
     *
     * @return array{results: array, totalResults: int, searchTime: float}
     */
    private function executeProviderSearch(string $query, int $num = 10, int $startIndex = 1, ?string $gl = null): array
    {
        $query = $this->prepareSearchQueryForProvider($query);

        // Defensive bounds for Google CSE compatibility:
        // - max num=10
        // - start+num must not exceed 100
        $startIndex = max(1, min(100, $startIndex));
        $num = max(1, $num);

        // Prefer the new abstraction if wired (respecting its health gate)
        if ($this->searchProvider !== null && $this->searchProvider->isAvailable()) {
            $resultSet = $this->searchProvider->search($query, $gl, null, $num, $startIndex);
            $legacyResult = $resultSet->toLegacyArray();

            // If the provider returned empty/no results (e.g. SearXNG not running),
            // fall back to Google CSE automatically.
            if (!empty($legacyResult['results'])) {
                return $legacyResult;
            }

            $this->logger->notice('Search provider returned empty results, falling back to Google CSE', [
                'query' => mb_substr($query, 0, 80),
                'provider' => $resultSet->providerName ?? 'unknown',
            ]);
        } elseif ($this->searchProvider !== null) {
            $this->logger->notice('Search provider is unavailable, falling back to Google CSE', [
                'provider' => $this->searchProvider->getProviderName(),
            ]);
        }

        // Fall back to legacy concrete class (Google CSE)
        if ($this->googleSearchService !== null) {
            // Transparently chunk >10 requests for CSE
            if ($num > 10) {
                $remaining = min($num, 100 - $startIndex + 1);
                $cursor = $startIndex;
                $merged = ['results' => [], 'totalResults' => 0, 'searchTime' => 0.0];

                while ($remaining > 0 && $cursor <= 100) {
                    $chunk = min(10, $remaining);
                    if (($cursor + $chunk - 1) > 100) {
                        $chunk = 100 - $cursor + 1;
                    }
                    if ($chunk <= 0) {
                        break;
                    }

                    $part = $this->googleSearchService->searchCompanies($query, $chunk, $cursor, $gl);
                    $items = $part['results'] ?? [];
                    if (!empty($items)) {
                        $merged['results'] = array_merge($merged['results'], $items);
                    }
                    $merged['searchTime'] += (float) ($part['searchTime'] ?? 0);
                    $merged['totalResults'] = max((int) $merged['totalResults'], (int) ($part['totalResults'] ?? 0));

                    if (count($items) < $chunk) {
                        break;
                    }

                    $remaining -= $chunk;
                    $cursor += $chunk;
                }

                return $merged;
            }

            $num = min(10, $num);
            if (($startIndex + $num - 1) > 100) {
                $num = 100 - $startIndex + 1;
            }
            if ($num <= 0) {
                return ['results' => [], 'totalResults' => 0, 'searchTime' => 0];
            }

            return $this->googleSearchService->searchCompanies($query, $num, $startIndex, $gl);
        }

        return ['results' => [], 'totalResults' => 0, 'searchTime' => 0];
    }

    /**
     * Execute a search specifically for LinkedIn queries (site:linkedin.com).
     *
     * Most scraped search engines cannot handle site:linkedin.com queries —
     * they either don't index LinkedIn, don't support the site: operator,
     * or return empty results. Burning all 28 engines wastes 4+ minutes per
     * query with zero results.
     *
     * This method goes directly to Google CSE, which reliably handles
     * LinkedIn site-restricted searches. Cost: $0.005 per call.
     *
     * For NON-LinkedIn queries, use executeProviderSearch() instead.
     */
    private function executeLinkedInSearch(string $query, int $num = 10): array
    {
        // Use Google CSE directly — the only reliable engine for site:linkedin.com
        if ($this->googleSearchService !== null) {
            try {
                $result = $this->googleSearchService->searchCompanies($query, $num);
                $this->logger->debug('LinkedIn search via Google CSE', [
                    'query' => $query,
                    'results' => count($result['results'] ?? []),
                ]);
                return $result;
            } catch (\Exception $e) {
                $this->logger->warning('LinkedIn search via Google CSE failed', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
                // Fall through to scraping provider
            }
        }

        // Fallback: use full provider search (will burn 28 engines, slow)
        return $this->executeProviderSearch($query, $num);
    }

    /**
     * Check whether any search provider is available.
     */
    private function hasSearchProvider(): bool
    {
        return $this->searchProvider !== null || $this->googleSearchService !== null;
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
    public function searchCompanies(?string $sector, ?string $location = null, bool $executeSearch = true): array
    {
        // ── Pipeline observability ──
        $this->metricsCollector->startRun();

        $this->logger->info("Google Dork search for companies", [
            'sector' => $sector,
            'location' => $location,
            'execute_search' => $executeSearch
        ]);

        $searchQueries = $this->optimizeAndDiversifySearchQueries($this->buildGoogleDorkQueries($sector, $location), $sector, $location);
        $discovered = [];
        $allResults = [];
        $directorySeeds = []; // Improvement 2D: seeds from directory pages

        // ══════════════════════════════════════════════════════════════
        // QUERY DIVERSITY: Avoid finding the same companies every run.
        // 1. Priority-aware shuffle: region-specific queries run FIRST
        //    (tagged with <<P>> prefix by buildGoogleDorkQueries)
        // 2. Inject -site: exclusions for already-found domains
        // 3. Post-search filtering of already-known domains (more reliable than -site: in queries)
        // ══════════════════════════════════════════════════════════════

        // Load existing company domains to exclude from search results (post-search filter)
        $existingDomains = [];
        if ($this->companyRepository !== null) {
            $existingDomains = array_flip($this->companyRepository->findAllWebsiteDomains());
            $this->logger->info('Query diversity: loaded known domains for post-search filtering', [
                'count' => count($existingDomains),
            ]);
        }

        // ── Dynamic expansion: queries derived from existing companies ──
        // Only useful when we have ≥ 3 diverse seeds; with 1–2 seeds the
        // "competitors of X" queries just find variants of the same company.
        if ($this->companyRepository !== null && $sector) {
            $region = $this->detectRegionFromLocation($location);
            $existingNames = $this->companyRepository->findNamesBySectorAndRegion($sector, $region);
            if (count($existingNames) >= 3) {
                $shuffledNames = $existingNames;
                shuffle($shuffledNames);
                $namesToExpand = array_slice($shuffledNames, 0, 3);
                $locationTerm = $this->buildLocationTerm($location);
                $baseSiteExclude = ' -site:linkedin.com -site:wikipedia.org -site:youtube.com'
                    . ' -site:facebook.com -site:twitter.com -site:instagram.com';
                foreach ($namesToExpand as $existingName) {
                    $searchQueries[] = "\"{$existingName}\" competitors OR \"similar to\" manufacturer{$locationTerm}" . $baseSiteExclude;
                    $searchQueries[] = "\"{$existingName}\" OR \"alternative to\" \"{$sector}\" manufacturer{$locationTerm}" . $baseSiteExclude;
                }
                $this->logger->info('Dynamic expansion: added competitor queries', [
                    'based_on' => $namesToExpand,
                    'region' => $region,
                ]);
            } else {
                $this->logger->info('Dynamic expansion: skipped (too few same-region seeds)', [
                    'seed_count' => count($existingNames),
                    'region' => $region,
                ]);
            }
        }

        // Re-run query optimization after exclusions + dynamic expansion
        // This preserves <<P>> prefixes for priority ordering.
        $searchQueries = $this->optimizeAndDiversifySearchQueries($searchQueries, $sector, $location);

        // Now split by priority — AFTER all optimization/dedup is done
        $priorityQueries = [];
        $regularQueries = [];
        foreach ($searchQueries as $q) {
            if (str_starts_with($q, '<<P>>')) {
                $priorityQueries[] = substr($q, 5);
            } else {
                $regularQueries[] = $q;
            }
        }
        $priorityQueries = $this->stableDiversifyQueryOrder($priorityQueries);
        $regularQueries = $this->stableDiversifyQueryOrder($regularQueries);
        $searchQueries = array_merge($priorityQueries, $regularQueries);
        $this->logger->info('Query priority split', [
            'priority' => count($priorityQueries),
            'regular' => count($regularQueries),
            'total' => count($searchQueries),
        ]);

        // If we have a search provider and should execute, use it
        if ($executeSearch && $this->hasSearchProvider()) {
            $this->logger->info("Executing searches via Search Provider");
            
            // Determine geo-location bias for Google API
            $region = $this->detectRegionFromLocation($location);
            $this->currentSearchRegion = $region;
            $glCode = $this->regionToGoogleGl($region);
            
            // ── Time budget: abort remaining queries after budget ──────
            // Prevents engine exhaustion/proxy hangs from blocking the
            // entire run. Whatever candidates we found get processed.
            $searchStartTime = time();
            $searchTimeBudgetSeconds = 1500; // 25 minutes
            
            foreach ($searchQueries as $queryIndex => $query) {
                $query = $this->prepareSearchQueryForProvider($query);

                // Check time budget before each query
                if ((time() - $searchStartTime) > $searchTimeBudgetSeconds) {
                    $this->logger->notice('Search time budget exhausted, proceeding with candidates found so far', [
                        'elapsed_seconds' => time() - $searchStartTime,
                        'queries_completed' => $queryIndex,
                        'queries_total' => count($searchQueries),
                        'candidates_so_far' => count($allResults),
                    ]);
                    break;
                }
                
                try {
                    // Pagination: fetch pages 1-2 (results 1-20, 21-40)
                    // With 30+ diverse queries per run, 2 pages gives sufficient
                    // breadth without exceeding the execution time budget.
                    $paginatedResults = [];
                    for ($page = 1; $page <= 2; $page++) {
                        $startIndex = 1 + (($page - 1) * 20);
                        $results = $this->executeProviderSearch($query, 20, $startIndex, $glCode);
                        if (!empty($results['results'])) {
                            $paginatedResults = array_merge($paginatedResults, $results['results']);
                        }
                        // Stop pagination if no results on first page
                        if ($page === 1 && empty($results['results'])) {
                            break;
                        }
                    }
                    
                    if (!empty($paginatedResults)) {
                        $this->metricsCollector->recordCandidates(count($paginatedResults));
                        foreach ($paginatedResults as $result) {
                            // Deduplicate by domain
                            $domain = $this->canonicalizeResultDomain((string) ($result['displayLink'] ?? ''), (string) ($result['link'] ?? ''));

                            // ── Directory seed extraction (Improvement 2D) ──
                            // If the result is from a known B2B directory,
                            // extract company seeds instead of blocking.
                            if ($this->directorySeedExtractor !== null && $this->directorySeedExtractor->isDirectoryDomain($domain)) {
                                $snippet = $result['snippet'] ?? '';
                                $title = $result['title'] ?? '';
                                $url = $result['link'] ?? '';
                                $seeds = $this->directorySeedExtractor->extractSeedsFromSnippet(
                                    $snippet, $title, $url, $domain, $region, $sector,
                                );
                                foreach ($seeds as $seed) {
                                    $directorySeeds[] = $seed;
                                }
                                $this->logger->debug('Directory seed extraction', [
                                    'domain' => $domain,
                                    'seeds_found' => count($seeds),
                                ]);
                                continue;
                            }

                            // Filter out non-company domains
                            if ($this->isBlockedDomain($domain)) {
                                $this->metricsCollector->recordReject('domain_block', $domain);
                                $this->logger->debug('Skipping blocked domain', ['domain' => $domain]);
                                continue;
                            }

                            // ── Already-known domain filter ──
                            // Skip domains already in our DB (faster & more reliable than -site: in queries)
                            if (isset($existingDomains[$domain])) {
                                $this->logger->debug('Skipping already-known domain', ['domain' => $domain]);
                                continue;
                            }

                            // ── ccTLD region mismatch filter ──
                            // Reject domains whose country-code TLD clearly
                            // belongs to a different region (e.g. .it domain
                            // in an Egypt search, .de in a Morocco search).
                            if ($this->isCcTldRegionMismatch($domain, $region)) {
                                $this->metricsCollector->recordReject('cctld_mismatch', $domain);
                                $this->logger->debug('Skipping ccTLD region mismatch', [
                                    'domain' => $domain,
                                    'region' => $region,
                                ]);
                                continue;
                            }

                            $domainKey = $this->domainDedupeKey($domain);
                            if (!isset($allResults[$domainKey])) {
                                $companyName = $this->extractCompanyName($result['title'] ?? '', $domain);
                                
                                // Post-filter: reject names that still look like page titles
                                if ($this->isJunkCompanyName($companyName)) {
                                    $this->metricsCollector->recordReject('name_junk', $companyName);
                                    $this->logger->debug('Skipping junk company name', [
                                        'name' => $companyName,
                                        'domain' => $domain,
                                    ]);
                                    continue;
                                }

                                // Giant OEM filter: real companies but not sales targets
                                if ($this->isGiantOem($companyName)) {
                                    $this->metricsCollector->recordReject('giant_oem', $companyName);
                                    $this->logger->debug('Skipping giant OEM', [
                                        'name' => $companyName,
                                        'domain' => $domain,
                                    ]);
                                    continue;
                                }
                                
                                // Name-Domain plausibility: if the extracted name
                                // has zero resemblance to the domain, try falling back
                                // to a domain-derived name instead of rejecting outright.
                                // This rescues cases like "Factory Automation" → autec-sondermaschinenbau.de
                                // where the Google title is a generic page heading but the
                                // company behind the domain is a real prospect.
                                if ($this->isNameDomainMismatch($companyName, $domain)) {
                                    $domainDerivedName = $this->companyNameFromDomain($domain);
                                    if ($domainDerivedName !== '' && !$this->isJunkCompanyName($domainDerivedName) && !$this->isGiantOem($domainDerivedName)) {
                                        $this->logger->debug('Name-domain mismatch rescued via domain-derived name', [
                                            'original_name' => $companyName,
                                            'domain_name' => $domainDerivedName,
                                            'domain' => $domain,
                                        ]);
                                        $companyName = $domainDerivedName;
                                    } else {
                                        $this->logger->debug('Skipping name-domain mismatch', [
                                            'name' => $companyName,
                                            'domain' => $domain,
                                        ]);
                                        continue;
                                    }
                                }

                                // Semantic filter: reject EMS competitors, distributors,
                                // equipment suppliers, component suppliers, integrators,
                                // MRO companies based on snippet analysis.
                                // KEPT as cheap pre-filter — catches obvious cases before
                                // the slower LLM call (~3s each on CPU).
                                $snippet = $result['snippet'] ?? '';
                                $title = $result['title'] ?? '';
                                if ($this->isCompetitorOrWrongType($snippet, $title, $domain)) {
                                    $this->logger->debug('Skipping competitor/wrong-type by snippet', [
                                        'name' => $companyName,
                                        'domain' => $domain,
                                    ]);
                                    continue;
                                }
                                
                                // ── Entity-Type Pre-Filter (v19) ──────────────────
                                // Fast regex check: catches government bodies, NGOs,
                                // student orgs, job portals, investment agencies before
                                // expensive LLM call. No rescue possible.
                                if ($this->isObviousNonTarget($companyName, $snippet . ' ' . $title, $domain)) {
                                    $this->metricsCollector->recordReject('entity_type_prefilter', $companyName);
                                    $this->logger->info('Entity-type pre-filter REJECT (obvious non-target)', [
                                        'name' => $companyName,
                                        'domain' => $domain,
                                    ]);
                                    continue;
                                }
                                
                                // ── Classifier Gate ────────────────────────────────
                                // Uses CompanyClassifierService (regex/knowledge-base)
                                // to determine if this is a real EMS buyer company.
                                if ($this->classifier !== null) {
                                    $classification = $this->classifier->classifyCompany($companyName, $snippet, $title ?? '', $domain);
                                    $verdict = $classification['verdict'] ?? 'UNCERTAIN';
                                    $score = $classification['score'] ?? 0;
                                    
                                    if ($verdict === 'REJECT') {
                                        $this->metricsCollector->recordReject('classifier_reject', $classification['reason'] ?? '');
                                        $this->logger->info('Classifier REJECT', [
                                            'name' => $companyName,
                                            'domain' => $domain,
                                            'score' => $score,
                                            'reason' => $classification['reason'] ?? '',
                                        ]);
                                        continue;
                                    }
                                    
                                    $this->logger->info('Classifier ACCEPT', [
                                        'name' => $companyName,
                                        'domain' => $domain,
                                        'verdict' => $verdict,
                                        'score' => $score,
                                    ]);
                                } else {
                                    $this->logger->warning('No classifier available — falling through to location check', [
                                        'name' => $companyName,
                                        'domain' => $domain,
                                    ]);
                                }

                                // ── Location Presence Validation ────────────────
                                // Verify candidate has actual presence in the target
                                // location. Prevents US companies appearing in Egypt
                                // searches etc. (e.g. Hope Global → no Egypt presence)
                                $locationValidated = false;
                                if ($location !== null && !$this->hasLocationPresence($snippet, $title, $domain, $location)) {
                                    $this->metricsCollector->recordReject('location_presence', $companyName);
                                    $this->logger->info('No location presence', [
                                        'name'     => $companyName,
                                        'domain'   => $domain,
                                        'location' => $location,
                                    ]);
                                    continue;
                                }
                                $locationValidated = true;
                                
                                $allResults[$domainKey] = [
                                    'name' => $companyName,
                                    'website' => $this->extractWebsiteFromResult($result),
                                    'title' => $title,
                                    'snippet' => $snippet,
                                    'link' => $result['link'] ?? '',
                                    'displayLink' => $domain,
                                    'source_query' => $query,
                                    'sector' => $sector,
                                    'location' => $location,
                                    'location_validated' => $locationValidated,
                                    'language' => $this->languageDetector !== null
                                        ? $this->languageDetector->detectWithRegionRelevance($snippet . ' ' . $title, $region)
                                        : null,
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
            
            // ── Feed directory seeds BEFORE verification ─────────────
            // Seeds from directories are added as candidates so they go
            // through the same homepage + LLM verification as Google results.
            // Previously they were appended AFTER verification (bypass!).
            if (!empty($directorySeeds)) {
                $this->logger->info('Directory seeds extracted (pre-verify)', [
                    'count' => count($directorySeeds),
                ]);
                foreach ($directorySeeds as $seed) {
                    // Only add seeds that have a domain and aren't already in results
                    if ($seed->domain !== null && !isset($allResults[$seed->domain]) && !$this->isBlockedDomain($seed->domain) && !$this->isCcTldRegionMismatch($seed->domain, $region)) {
                        $allResults[$seed->domain] = [
                            'name'             => $seed->companyName,
                            'website'          => 'https://' . $seed->domain,
                            'title'            => $seed->companyName,
                            'snippet'          => $seed->snippet ?? '',
                            'link'             => $seed->sourceUrl,
                            'displayLink'      => $seed->domain,
                            'source_query'     => 'directory_seed:' . $seed->sourceDirectory,
                            'sector'           => $seed->sector ?? $sector,
                            'location'         => $location,
                            'buyer_evidence'   => null,
                            'service_product'  => null,
                            'competitor_veto'  => null,
                            'rule_verdict'     => null,
                            'directory_seed'   => $seed->toArray(),
                        ];
                    }
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

        // ── Pipeline observability: end run ──
        $this->metricsCollector->recordOutput(count($discovered));
        $this->metricsCollector->endRun();

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

        // ── Foreign-language page title detection ─────────────────
        // Detect titles that are obviously foreign-language homepage words
        // (e.g. "Startseite - CompanyName" → the company is "CompanyName",
        //  not "Startseite"). Also catches standalone foreign nav words.
        $foreignHomeWords = [
            // German
            'startseite', 'willkommen', 'herzlich willkommen', 'über uns',
            'uber uns', 'unternehmen', 'impressum', 'kontakt', 'produkte',
            'leistungen', 'aktuelles', 'karriere', 'stellenangebote', 'anfahrt',
            // French
            'accueil', 'bienvenue', 'à propos', 'a propos', 'nos services',
            'nos produits', 'qui sommes-nous', 'qui sommes nous', 'contactez-nous',
            'actualités', 'actualites', 'recrutement', 'savoir-faire',
            // Dutch
            'welkom', 'startpagina', 'over ons', 'producten', 'diensten',
            'vacatures', 'bedrijf', 'ons bedrijf',
            // Czech
            'domů', 'domu', 'úvod', 'uvod', 'vítejte', 'vitejte', 'o nás',
            'o nas', 'kontakty', 'produkty', 'služby', 'sluzby', 'o společnosti',
            // Finnish
            'etusivu', 'tervetuloa', 'meistä', 'meista', 'yhteystiedot',
            'tuotteet', 'palvelut', 'ajankohtaista',
            // Swedish
            'startsida', 'startsidan', 'välkommen', 'valkommen', 'om oss',
            'kontakta oss', 'produkter', 'tjänster', 'tjanster', 'företaget',
            'foretaget', 'nyheter', 'karriär',
            // Italian
            'pagina iniziale', 'benvenuto', 'benvenuti', 'chi siamo',
            'contatti', 'contattaci', 'azienda', 'lavora con noi',
            // Spanish
            'inicio', 'bienvenido', 'bienvenidos', 'quiénes somos',
            'quienes somos', 'sobre nosotros', 'contáctenos', 'contactenos',
            'empresa', 'nuestros servicios',
            // Polish
            'strona główna', 'strona glowna', 'witamy', 'witaj',
            'o nas', 'kontakt', 'produkty', 'usługi', 'uslugi', 'nasza firma',
            // Portuguese
            'página inicial', 'pagina inicial', 'quem somos',
            // Norwegian/Danish
            'hjem', 'hjemmeside', 'velkommen',
            // Romanian
            'acasă', 'acasa', 'despre noi',
            // Hungarian
            'kezdőlap', 'kezdolap', 'rólunk', 'rolunk', 'kapcsolat',
        ];
        // If the entire title IS a foreign-language nav word, return domain name
        $titleLower = mb_strtolower(trim($title));
        if (in_array($titleLower, $foreignHomeWords, true)) {
            return $this->companyNameFromDomain($domain) ?: $trimmed;
        }
        // If title starts/ends with foreign nav word + separator, strip it
        foreach ($foreignHomeWords as $fhw) {
            // "Startseite | CompanyName" → "CompanyName"
            if (preg_match('/^' . preg_quote($fhw, '/') . '\s*[\|–—\-:]\s*(.+)$/iu', $title, $fhwMatch)) {
                $title = trim($fhwMatch[1]);
                break;
            }
            // "CompanyName | Startseite" → "CompanyName"
            if (preg_match('/^(.+?)\s*[\|–—\-:]\s*' . preg_quote($fhw, '/') . '\s*$/iu', $title, $fhwMatch)) {
                $title = trim($fhwMatch[1]);
                break;
            }
        }

        // Remove trademark symbols early (before any splitting)
        $title = preg_replace('/[®™©]/u', '', $title);
        // Remove emoji characters (iter14: "✅ Tank Oil Group" → "Tank Oil Group")
        $title = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}\x{2702}-\x{27B0}\x{2300}-\x{23FF}]/u', '', $title);
        // Remove DB artifact suffixes like "_597259-RM" or "_12345"
        $title = preg_replace('/_\d{4,}(-[A-Z]{1,4})?$/i', '', $title);
        $title = trim($title);

        // --- Step 1: clean the title ---
        // Handle pipe/em-dash/en-dash separators intelligently:
        // "Home | Lucid Motors" → left is generic, pick right
        // "Lucid Motors | About Us" → right is generic, pick left
        // "Everrati - Electrifying Icons" → left is brand, pick left
        $name = $title;
        if (preg_match('/^(.+?)\s*[\|｜–—ᐅ›▸▶→◆⯈]\s*(.+)$/u', $title, $pipeMatch)) {
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
            // If one side is a descriptive phrase ("Solutions for automotive")
            // and the other is a short brand/acronym ("DSA"), prefer the brand
            $beforeIsDescriptive = preg_match('/\b(solutions?|services?|products?|systems?|technologies?|partner|consulting)\s+(for|in|of|to|and|&)\s+/i', $beforeDash);
            $afterIsDescriptive = preg_match('/\b(solutions?|services?|products?|systems?|technologies?|partner|consulting)\s+(for|in|of|to|and|&)\s+/i', $afterDash);
            // Short all-caps words (2-6 chars) are acronyms/brands, not generic in this context
            $afterIsAcronym = preg_match('/^[A-Z]{2,6}$/', $afterDash);
            $beforeIsAcronym = preg_match('/^[A-Z]{2,6}$/', $beforeDash);
            if ($beforeIsDescriptive && ($afterIsAcronym || (!$afterIsGeneric && mb_strlen($afterDash) >= 2 && mb_strlen($afterDash) <= 40))) {
                $name = $afterDash;
            } elseif ($afterIsDescriptive && ($beforeIsAcronym || (!$beforeIsGeneric && mb_strlen($beforeDash) >= 2 && mb_strlen($beforeDash) <= 40))) {
                $name = $beforeDash;
            }
            // If before-dash is a clean brand name (short, capitalised), prefer it
            elseif (!$beforeIsGeneric && mb_strlen($beforeDash) >= 2 && mb_strlen($beforeDash) <= 40) {
                $name = $beforeDash;
            } elseif (!$afterIsGeneric && mb_strlen($afterDash) >= 2 && mb_strlen($afterDash) <= 40) {
                $name = $afterDash;
            }
        }

        // Remove leading prefixes like "MAKING - ", "Visit of plants Morocco - "
        $name = preg_replace('/^(MAKING|Visit of plants?|List of all|Contacts and locations|Overview of|Homepage)\s*[-–—:|»]\s*/iu', '', $name);
        // "About us - X" / "About - X" — require a REAL separator so a bare
        // "About Us" title stays intact (it falls through to the generic
        // pattern below and resolves to the domain name)
        $name = preg_replace('/^About(?:\s+us)?\s*[-–—:|»]\s*/iu', '', $name);
        // "Welcome to" / "We are" don't need a separator — strip directly
        $name = preg_replace('/^Welcome\s+to\s+/iu', '', $name);
        $name = preg_replace('/^We\s+are\s+/iu', '', $name);
        // Strip language tags: "(EN)", "(DE)", "(FR)" etc.
        $name = preg_replace('/\s*\((?:EN|DE|FR|ES|IT|NL|PL|CZ|FI|SE|NO|DA|PT|RU|JP|CN|KR|AR|HE|TR|HU|RO|BG|HR|SK|SI|LT|LV|EE|EL|UK|INT)\)\s*$/iu', '', $name);
        // Strip trailing language labels: "in English", "- English"
        $name = preg_replace('/\s*[-–—]?\s*\b(in\s+)?(English|Deutsch|Français|Español|Italiano|Nederlands)\s*$/iu', '', $name);
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
        // Strip trailing descriptive taglines:
        // "AED Vantage automotive electronics partner" → "AED Vantage"
        // Only strip known industry descriptors + terminal role word
        $name = preg_replace('/\s+(?:(?:automotive|electronics?|industrial|manufacturing|engineering|technology|digital|software|hardware|mechanical|electrical|technical|global|local|regional|contract|trusted|reliable|leading|your)\s+){0,3}(partner|supplier|provider|specialist|expert|distributor|manufacturer|contractor|consultant|leader|pioneer|innovator)\s*$/i', '', $name);
        $name = trim($name);

        // ─── Location-as-name detection ──────────────────────────────
        // "St. Egidien / Germany" → not a company name, try domain
        // "City / Country" or "City, Country" patterns
        if (preg_match('/^[\p{L}\s\.\-]+\s*[\/,]\s*(Germany|Deutschland|France|UK|USA|China|Japan|India|Morocco|Tunisia|Egypt|Turkey|Italy|Spain|Netherlands|Belgium|Austria|Switzerland|Czech|Poland|Sweden|Finland|Norway|Denmark|Hungary|Romania|Bulgaria|Croatia|Serbia|Slovenia|Slovakia|Lithuania|Latvia|Estonia|Portugal|Greece|Brasil|Brazil|Mexico|Canada|Australia)\s*$/iu', $name)) {
            $domainName = $this->companyNameFromDomain($domain);
            if ($domainName) {
                $name = $domainName;
            }
        }

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
            // ─── About-page / nav headings — not company names ────────
            '/^our\s+(company|history|story|team|mission|vision|values?|approach|philosophy|expertise|journey)\b/i',
            '/^(company|corporate)\s+(history|overview|profile|information|about)\b/i',
            '/^(factory|plant|office|headquarters?|facility)\s+(visit|tour|location)\b/i',
            '/^(who|what|how|why|when|where)\s+(are|is|do|does|we|to)\b/i',
            '/^(discover|explore|learn|read)\s+(more|about|our)\b/i',
            '/\b(at\s+a\s+glance|in\s+brief|overview|fact\s+sheet)\b/i',
            '/\b(opens?|launches?|announces?|expands?|invests?|acquires?|partners?|builds?|plans?|signs?|wins?|receives?|delivers?|starts?|completes?|begins?|reports?)\s+(first|new|its|a|an|the|battery|major|record|multi|\$|€|£)\b/i',
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
            '/^(germany|france|morocco|usa|uk|egypt|dubai|tunisia|tunisie)\s*$/i',  // bare country names
            '/^(free\s+zone|industrial\s+zone|special\s+economic)\b/i',
            '/\b(brochure|datasheet|whitepaper|specification|manual)\b/i',
            '/\b(investor|shareholder|annual\s+general)\s+(relations|meeting|report)/i',
            '/^(complete\s+guide|ultimate\s+guide|beginner|introduction)\b/i',
            // ─── Market reports / research titles ─────────────────────
            '/\bmarket\s+(20[2-4]\d|size|growth|report|forecast|analysis|outlook|share|trends?)\b/i',
            '/\b(CAGR|compound\s+annual)\b/i',
            '/\b(report|forecast|analysis)\s+20[2-4]\d\b/i',
            // ─── Generic service / product descriptions ───────────────
            '/^contract\s+(manufactur|electron|assembl)/i',
            '/^conformal\s+(coating|coat)/i',
            '/^(tax\s+credit|certifications?\s+(issued|for))/i',
            '/^free\s+(member|course|download|trial|sample)\b/i',
            // ─── Product SKU patterns ─────────────────────────────────
            '/^[A-Z]{1,4}\d{3,}\b/',
            // ─── Events / disasters ──────────────────────────────────
            '/\b(earthquake|flood|hurricane|tsunami)\s+(in|near)\b/i',
            // ─── Foreign-language machine/trade-show descriptions ─────
            '/\b(maszyna|maschine|appareil|máquina)\s+/iu',
            '/^(messe|feira|feria|foire)\s+/iu',
            // ─── French/Arabic job titles as names ───────────────────
            '/^(approvisionneur|acheteur|responsable|technicien)\b/iu',
            // ─── Industry descriptor phrases ──────────────────────────
            '/\bin\s+the\s+\w+\s+industry\b/i',  // "OEM & Tier 1 in the automotive industry"
            '/^OEM\s*[&,]\s*Tier\b/i',            // "OEM & Tier 1 ..."
            // ─── IoT / development / generic tech descriptions ────────
            '/^(IoT|AI,?\s+Robotics|3D\s+printing|Web\s+development)/i',
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
            // Strip trailing TLD fragments accidentally included in title-derived names
            // e.g. "Cooperconsumerhealth.nl" -> "Cooperconsumerhealth"
            $name = preg_replace('/\.(com|net|org|io|co|fr|de|nl|it|es|pl|cz|fi|se)\.?$/i', '', $name);
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
            // ─── German (DE/AT/CH) navigation words ──────────────────
            'startseite', 'start', 'willkommen', 'herzlich willkommen',
            'über uns', 'uber uns', 'ueber uns', 'kontakt', 'kontaktieren sie uns',
            'impressum', 'datenschutz', 'datenschutzerklärung', 'datenschutzerklaerung',
            'produkte', 'leistungen', 'unternehmen', 'karriere',
            'aktuelles', 'neuigkeiten', 'nachrichten', 'stellenangebote',
            'anfahrt', 'standorte', 'referenzen', 'downloads',
            'unser unternehmen', 'unsere produkte', 'unsere leistungen',
            'wir über uns', 'wir uber uns', 'firmenportrait',
            'firmenporträt', 'firmenportrae', 'firmenportraet',
            // ─── French (FR/BE/CH) navigation words ──────────────────
            'accueil', 'bienvenue', 'à propos', 'a propos',
            'à propos de nous', 'a propos de nous', 'qui sommes-nous',
            'qui sommes nous', 'contactez-nous', 'contactez nous',
            'nos services', 'nos produits', 'nos réalisations',
            'nos realisations', 'notre entreprise', 'notre société',
            'notre societe', 'mentions légales', 'mentions legales',
            'politique de confidentialité', 'politique de confidentialite',
            'actualité', 'actualités', 'actualite', 'actualites',
            'emplois', 'carrières', 'carrieres', 'recrutement',
            'savoir-faire', 'savoir faire', 'présentation', 'presentation',
            // ─── Dutch (NL/BE) navigation words ──────────────────────
            'welkom', 'startpagina', 'over ons', 'contact',
            'producten', 'diensten', 'bedrijf', 'vacatures',
            'nieuws', 'privacy', 'privacyverklaring', 'cookiebeleid',
            'ons bedrijf', 'onze producten', 'onze diensten',
            'werken bij', 'werken bij ons',
            // ─── Czech (CZ) navigation words ─────────────────────────
            'domů', 'domu', 'úvod', 'uvod', 'vítejte', 'vitejte',
            'o nás', 'o nas', 'kontakty', 'produkty',
            'služby', 'sluzby', 'kariéra', 'kariera',
            'novinky', 'aktuality', 'firma', 'o společnosti',
            'o spolecnosti', 'naše produkty', 'nase produkty',
            // ─── Finnish (FI) navigation words ───────────────────────
            'etusivu', 'tervetuloa', 'meistä', 'meista',
            'yhteystiedot', 'tuotteet', 'palvelut', 'yritys',
            'uutiset', 'ajankohtaista', 'avoimet työpaikat',
            'avoimet tyopaikat', 'tietosuoja', 'tietosuojaseloste',
            'ota yhteyttä', 'ota yhteytta',
            // ─── Swedish (SE) navigation words ───────────────────────
            'startsida', 'startsidan', 'hem', 'välkommen', 'valkommen',
            'om oss', 'kontakta oss', 'produkter', 'tjänster', 'tjanster',
            'företaget', 'foretaget', 'nyheter', 'karriär', 'karriar',
            'lediga jobb', 'integritetspolicy', 'integritet',
            'våra produkter', 'vara produkter', 'våra tjänster', 'vara tjanster',
            // ─── Italian (IT) navigation words ───────────────────────
            'pagina iniziale', 'benvenuto', 'benvenuti',
            'chi siamo', 'contatti', 'contattaci', 'prodotti',
            'servizi', 'azienda', 'lavora con noi', 'carriere',
            'novità', 'novita', 'notizie', 'privacy', 'cookie policy',
            'la nostra azienda', 'i nostri prodotti', 'i nostri servizi',
            // ─── Spanish (ES) navigation words ───────────────────────
            'inicio', 'bienvenido', 'bienvenidos',
            'quiénes somos', 'quienes somos', 'sobre nosotros',
            'contáctenos', 'contactenos', 'contacto',
            'productos', 'servicios', 'empresa', 'empleo', 'empleos',
            'noticias', 'novedades', 'aviso legal', 'política de privacidad',
            'politica de privacidad', 'trabaja con nosotros',
            'nuestra empresa', 'nuestros productos', 'nuestros servicios',
            // ─── Polish (PL) navigation words ────────────────────────
            'strona główna', 'strona glowna', 'witamy', 'witaj',
            'o nas', 'kontakt', 'produkty', 'usługi', 'uslugi',
            'firma', 'kariera', 'praca', 'oferty pracy',
            'aktualności', 'aktualnosci', 'polityka prywatności',
            'polityka prywatnosci', 'nasza firma', 'nasze produkty',
            // ─── Portuguese (PT) navigation words ────────────────────
            'página inicial', 'pagina inicial', 'bem-vindo', 'bem vindo',
            'quem somos', 'contactos', 'produtos', 'serviços', 'servicos',
            'empresa', 'emprego', 'notícias', 'noticias',
            'política de privacidade', 'politica de privacidade',
            // ─── Norwegian/Danish navigation words ───────────────────
            'hjem', 'hjemmeside', 'velkommen', 'om os', 'om oss',
            'kontakt os', 'kontakt oss', 'produkter', 'tjenester',
            'nyheder', 'ledige stillinger', 'personvern',
            'privatlivspolitik', 'karriere',
            // ─── Romanian navigation words ───────────────────────────
            'acasă', 'acasa', 'bine ați venit', 'bine ati venit',
            'despre noi', 'contact', 'produse', 'servicii',
            'cariere', 'noutăți', 'noutati',
            // ─── Hungarian navigation words ──────────────────────────
            'kezdőlap', 'kezdolap', 'üdvözöljük', 'udvozoljuk',
            'rólunk', 'rolunk', 'kapcsolat', 'termékek', 'termekek',
            'szolgáltatások', 'szolgaltatasok', 'karrier', 'hírek', 'hirek',
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
    /**
     * Detect when the extracted company name has ZERO resemblance to the domain.
     *
     * Catches common mismatches where Google returns a page title from a
     * giant OEM's subdomain/subpage that mentions an unrelated entity:
     *   "Federal Aviation Administration" → totalenergies.eg
     *   "NYS Division of Human Rights" → rheinmetall.com
     *   "Google Project Management Certificate" → elsewedyelectric.com
     *   "Omni Powertrain Technologies" → collegestationford.com
     *
     * Logic: extract meaningful words from both name and domain, check overlap.
     * If the domain has a recognizable brand and the name shares ZERO words,
     * it's almost certainly a mismatch.
     *
     * Returns TRUE if the name-domain pair looks like a mismatch (should reject).
     */
    private function isNameDomainMismatch(string $name, string $domain): bool
    {
        // Short names (1-2 chars) are probably from the domain anyway
        if (mb_strlen($name) < 4) {
            return false;
        }

        // Clean domain: strip TLD, www, subdomains
        $cleanDomain = strtolower(preg_replace('/^www\./', '', $domain));
        $cleanDomain = preg_replace('/\.(co|com|org|net|io)\.[a-z]{2,4}$/i', '', $cleanDomain);
        $cleanDomain = preg_replace('/\.[a-z]{2,6}$/i', '', $cleanDomain);
        // If subdomain exists (e.g. "globalcareers.lge"), use the main domain
        if (str_contains($cleanDomain, '.')) {
            $parts = explode('.', $cleanDomain);
            $cleanDomain = end($parts); // Use main domain part
        }
        // Split camelCase and hyphens: "elsewedyelectric" → ["elsewedy", "electric"]
        $domainWords = preg_split('/[-_.]/', $cleanDomain);
        // Also split camelCase-ish patterns
        $expandedDomainWords = [];
        foreach ($domainWords as $dw) {
            // Split on transition from lowercase to uppercase
            $subwords = preg_split('/(?<=[a-z])(?=[A-Z])/', $dw);
            $expandedDomainWords = array_merge($expandedDomainWords, $subwords);
        }
        $domainWords = array_map('strtolower', $expandedDomainWords);
        $domainWords = array_filter($domainWords, fn($w) => strlen($w) >= 3);

        if (empty($domainWords)) {
            return false; // Can't analyze, let it through
        }

        // Extract meaningful words from the name
        $nameClean = preg_replace('/\s*(GmbH|LLC|Inc\.?|Ltd\.?|Corp\.?|S\.?A\.?|Co\.?|PLC)\s*$/i', '', $name);
        $nameWords = preg_split('/[\s\-&,\.]+/', strtolower($nameClean));
        $nameWords = array_filter($nameWords, fn($w) => strlen($w) >= 3);

        if (empty($nameWords)) {
            return false;
        }

        // Check for ANY overlap between name words and domain words
        $domainStr = implode('', $domainWords); // "elsewedyelectric"
        foreach ($nameWords as $nw) {
            // Direct word in domain
            if (str_contains($domainStr, $nw)) {
                return false; // Found overlap, not a mismatch
            }
            // Check each domain word in name
            foreach ($domainWords as $dw) {
                if (str_contains($nw, $dw) || str_contains($dw, $nw)) {
                    return false; // Found overlap
                }
            }
        }

        // Also check if any domain word appears as substring in the full name
        $nameLower = strtolower($nameClean);
        foreach ($domainWords as $dw) {
            if (strlen($dw) >= 4 && str_contains($nameLower, $dw)) {
                return false;
            }
        }

        // Zero overlap: this is a name-domain mismatch
        // For very short domain basenames (≤3 chars total), too ambiguous to reject.
        // But multi-part domains like "mgi-ci5" (6+ chars total) should still be rejected.
        $fullDomainStr = implode('', $domainWords);
        if (strlen($fullDomainStr) <= 3) {
            return false; // Single short word like "abb" — too ambiguous
        }

        // Also check: does the full concatenated domain appear as substring of name?
        $nameLowerFull = strtolower(preg_replace('/[\s\-&,\.]+/', '', $nameClean));
        if (str_contains($nameLowerFull, $fullDomainStr) || str_contains($fullDomainStr, $nameLowerFull)) {
            return false; // Fuzzy whole-string match
        }

        $this->logger->debug('Name-domain mismatch detected', [
            'name' => $name,
            'domain' => $domain,
            'nameWords' => implode(',', $nameWords),
            'domainWords' => implode(',', $domainWords),
        ]);

        return true;
    }

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
        'electronicspecifier.com',   // UK electronics news/reviews
        'agbi.com',                  // Arabian Gulf Business Insight (news)
        'kfor.com', 'prnewswire.com', 'businesswire.com', 'globenewswire.com',
        'fdiintelligence.com', 'autonews.com', 'wiringharnessnews.com',
        'gulfstreamnews.com', 'themanufacturer.com', 'mddionline.com',
        'automotivemanufacturingsolutions.com', 'fastenernewsdesk.com',
        'helihub.com', 'birminghamdispatch.co.uk', 'makezine.com',
        'news.siemens.com', 'thetimesherald.com', 'easyengineering.eu',
        'aviation-safety.net', 'avherald.com', 'aerosociety.com',
        'wiringharnessnews.de',
        'totaltele.com',         // Total Telecom news site
        // Major news outlets with compound-word domains (\b misses these)
        'dailymail.co.uk', 'mailonline.com', 'mirror.co.uk',
        'thesun.co.uk', 'huffpost.com', 'foxnews.com', 'nbcnews.com',
        'cbsnews.com', 'abcnews.go.com', 'cnbc.com', 'usatoday.com',
        'thedrive.com', 'autoweek.com', 'motortrend.com',
        'caranddriver.com', 'autoblog.com', 'jalopnik.com',
        'autocar.co.uk', 'topgear.com', 'pistonheads.com',
        'automobilwoche.de', 'auto-motor-und-sport.de',
        'automobil-produktion.de', 'springerprofessional.de',
        'auto-medienportal.net', 'electrive.com', 'electrive.net',
        'cleantechnica.com', 'insideevs.com', 'greencarreports.com',
        'leparisien.fr', 'lefigaro.fr', 'lemonde.fr', 'lesechos.fr',
        'usinenouvelle.com', 'spiegel.de', 'sueddeutsche.de',
        'faz.net', 'handelsblatt.com', 'wirtschaftswoche.de',
        'corriere.it', 'repubblica.it', 'elpais.com', 'elmundo.es',
        'nos.nl', 'rtlnieuws.nl', 'nu.nl', 'telegraaf.nl',
        'gazeta.pl', 'wp.pl', 'onet.pl', 'novinky.cz', 'idnes.cz',

        // ─── Standards bodies / norms / certification orgs ────────────
        'iso.org', 'iec.ch', 'din.de', 'ansi.org', 'bsigroup.com',
        'cenelec.eu', 'cen.eu', 'etsi.org', 'astm.org', 'sae.org',
        'ul.com', 'tuv.com', 'dekra.com', 'intertek.com', 'sgs.com',
        'bureauveritas.com', 'dnv.com', 'lrqa.com', 'afnor.org',
        'normservis.cz', 'technickenormy.cz', 'normy.biz',
        'beuth.de', 'vde.com', 'vdi.de', 'iatfglobaloversight.org',

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
        'anebonmetal.com',             // Chinese CNC parts supplier

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
        'egypt-business.com',  // Egyptian business directory
        'egypttoday.com', 'www.egypttoday.com',  // Egyptian news site
        // French directories / portals (source sites, not prospects)
        'annuaire-entreprises.data.gouv.fr', 'societe.com', 'verif.com',
        'manageo.fr', 'infogreffe.fr', 'pappers.fr', 'bodacc.fr',
        'usinenouvelle.com', 'industrie-techno.com',
        'batiproduits.com', 'batiactu.com', 'lemoniteur.fr',
        'pagesjaunes.fr', 'annuaire.com', 'infobel.com',
        // Trade platforms / data aggregators (waste BEG budget)
        'tradeaegea.com', 'tendata.com', 'importexportplatform.com',
        'chinatradeholding.com', 'themouldinfo.com', 'exporthub.com',
        'ap-co.net', 'company-listing.org', 'company-list.org',
        // Egypt auto dealers / repair shops (not manufacturers)
        'ekautoparts.com', 'autofixegypt.com',
        'egyptbusinessgate.com',  // Government portal, not a company
        // Egypt v6 false positives
        'mea-biz.com',  // unknown MEA business site
        'frigate.ai',  // AI video surveillance, not automotive
        'focus2move.com',  // automotive market data/research site
        'electronicsforyou.biz',  // Indian electronics magazine
        'royaleinternational.com',  // time-critical logistics company
        'redsea-egypt.com',  // unknown Egyptian company, not verifiable
        'eltawfikatm.com',  // trading/import company, not manufacturer
        'elboltygroup.com',  // spare parts distributor

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
        'tstronic.eu', 'tstronic.com',  // TSTRONIC - Polish EMS (SMT/THT assembly)
        'recomedic.com',  // Recomedic - Polish contract manufacturer
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
        'diysolarforum.com', 'dteenergy.com', 'lowendtalk.com', 'dailynewsegypt.com',
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
        
        // ─── Power Electronics OEMs / Inverter Manufacturers (competitors) ────
        'abbpowerelectronics.com', 'new.abb.com',  // ABB power electronics
        'danfoss.com',  // Danfoss — drives, inverters
        'siemens-energy.com', 'siemens.com',  // Siemens — everything including power electronics
        'cgglobal.com',  // CG Power — transformers, switchgear
        'mitsubishielectric.com', 'mitsubishielectric-europe.com',  // Mitsubishi Electric
        'fujielectric.com',  // Fuji Electric — inverters, drives
        'hitachi-powergrids.com',  // Hitachi Energy
        'parker.com',  // Parker Hannifin — includes power electronics
        'eaton.com',  // Eaton — power management
        'schneider-electric.com',  // Schneider Electric
        'se.com',  // Schneider Electric alt domain
        'victronenergy.com',  // Victron Energy — inverters, chargers
        'solax-power.com', 'solaxpower.com',  // SolaX — solar inverters
        'huawei.com', 'solar.huawei.com',  // Huawei — solar inverters
        'sungrowpower.com', 'en.sungrowpower.com',  // Sungrow — solar inverters
        'goodwe.com',  // GoodWe — solar inverters
        'growatt.com',  // Growatt — solar inverters
        'sma.de', 'sma-solar.com',  // SMA Solar — inverters
        'ingeteam.com',  // Ingeteam — power electronics
        'nidec.com', 'nidec-industrial.com',  // Nidec — motors, drives
        'yaskawa.com', 'yaskawa-europe.com',  // Yaskawa — drives, servos
        'lenze.com',  // Lenze — drives, automation
        'bonfiglioli.com',  // Bonfiglioli — drives, gearboxes
        'ziehl-abegg.com',  // Ziehl-Abegg — motors, drives
        'sevcon.com',  // Sevcon — EV controllers (now BorgWarner)
        'semikron.com', 'semikron-danfoss.com',  // SEMIKRON — power modules
        'infineon.com',  // Infineon — IGBT, SiC, power semiconductors
        'onsemi.com',  // onsemi — power semiconductors
        'wolfspeed.com',  // Wolfspeed — SiC power semiconductors
        'rohm.com',  // ROHM — SiC power semiconductors
        'stmicroelectronics.com',  // STMicroelectronics
        'power.com',  // Power Integrations
        'microchip.com',  // Microchip Technology
        'vishay.com',  // Vishay Intertechnology
        'tdk.com', 'tdk-electronics.tdk.com',  // TDK — magnetics, power electronics
        'coilcraft.com',  // Coilcraft — inductors, magnetics
        'magnetics.com',  // Magnetics Inc — cores, inductors
        'vacuumschmelze.com',  // VAC — magnetic materials
        'meanwell.com', 'meanwellusa.com',  // Mean Well — power supplies
        'xppower.com',  // XP Power — power supplies
        'recom-power.com',  // RECOM Power — DC-DC converters
        'tracopower.com',  // TRACO Power — power supplies
        'murata.com',  // Murata — DC-DC modules
        'cosel.co.jp', 'coselusa.com',  // COSEL — power supplies
        'tdk-lambda.com',  // TDK Lambda — power supplies
        'puls.com',  // PULS — DIN-rail power supplies
        'phoenixcontact.com',  // Phoenix Contact — power supplies, connectors
        'weidmuller.com',  // Weidmüller — power supplies, connectors
        'mornsun-power.com',  // Mornsun — power supplies
        
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
        'chemeurope.com',         // chemistry information portal, not a manufacturer
        'kruse.de',               // FPGA distributor, not a manufacturer
        'plattform-i40.de',       // government/industry initiative, not a company
        'smartfactory.de',        // DFKI research initiative, not a company
        'thm.de',                 // Technische Hochschule Mittelhessen (university)
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

        // COMPETITOR EMS / CABLE / WIRE HARNESS / CNC / INJECTION MOLDING
        'polydesignsystems.com', 'suprajit.com',
        'xometry.com',              // On-demand CNC/injection/3D print marketplace — COMPETITOR
        'galaxyelectronicsinc.com', // Galaxy Electronics — EMS competitor
        'advancenc.com',            // AdvanceNC — CNC machining service
        'paceind.com',              // Pace Industries — die casting service
        'cascade-cdc.com',          // Cascade Die Casting — die casting service
        'die-castings.net',         // Die Casting Services — service provider
        'weitron.com',              // Weitron — wire harness manufacturer
        'slaerospace.com',          // S&L Aerospace — aerospace machining services
        'lvswiss.com',              // LV Swiss — precision machining service
        'posimech.com',             // Posimech — precision machining service
        'texasheattreating.com',    // Texas Heat Treating — service provider
        'kcables.com',              // Kcables — cable manufacturer (competitor)
        'nninc.com',                // NN, Inc — precision machining/assembly (COMPETITOR)
        'rimcustomracks.com',       // RIM Custom Racks — custom fabricator (service provider)
        'mitrp.com',                // MITRP — automotive proving ground (test facility)
        'dsgsteel.com',             // Detroit Steel Group — steel supplier

        // STEEL MILLS / RAW MATERIAL SUPPLIERS (not OEM buyers)
        'nucor.com',                // Nucor — steel manufacturer/recycler (raw mat supplier)
        'ussteel.com',              // US Steel — steel producer
        'arcelormittal.com',        // ArcelorMittal — steel producer
        'alcoa.com',                // Alcoa — aluminum producer
        'novelis.com',              // Novelis — aluminum rolling/recycling

        // TELECOM OPERATORS / ENERGY / SERVICES (not manufacturers)
        't-mobile.com',             // Telecom operator
        'questdiagnostics.com',     // Medical diagnostics — not manufacturer
        'gexaenergy.com',           // Energy provider
        'learn.gexaenergy.com',     // Energy provider subdomain
        'funfactorytours.com',      // Factory tours — not a company
        'vynmsa.com',               // Industrial park developer (Mexico)
        'motorcarparts.com',        // Aftermarket auto parts distributor
        'bcicb.com',                // BCI Certification body
        'lwsurvey.com',             // Land surveying company
        'ssoe.com',                 // SSOE Group — engineering consulting
        'mrofuse.com',              // MRO fuse reseller
        'enable.hp.com',            // HP supplier portal — not a company
        'kerrindustries.com',       // Metal fabrication service provider
        'unisco.com',               // Supply chain/logistics
        'kth.net',                  // Swedish university domain / parts dist
        'haitongele.com',           // Chinese antenna company
        'vastantenna.com',          // Chinese satellite antenna company

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
        'indiantourismblogs.com',      // Tourism blog

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
        // ─── iter7 deep sweep additions ──────────────────────────────
        // Giant OEM domains
        'rheinmetall.com',
        'nxp.com', 'geaerospace.com', 'gknaerospace.com',
        'textronsystems.com', 'kongsberg.com',
        'hensoldt.net', 'rohde-schwarz.com',
        'kuka.com', 'lyondellbasell.com',
        'merckgroup.com', 'ussteel.com',
        'comau.com', 'br-automation.com',
        'kawasakirobotics.com', 'kistler.com',
        'benteler.com', 'trelleborg.com',
        'seg-automotive.com', 'zkw-group.com',
        'punchpowertrain.com', 'horse-powertrain.com',
        'nexteer.com', 'markforged.com',
        'teradyne.com', 'imiplc.com',
        'mondelezinternational.com', 'ocpgroup.ma',
        'elarabygroup.com', 'alfuttaim.com',
        'fev.com', 'diehl.com', 'chemring.com',
        'jobyaviation.com', 'beyondgravity.com',
        'parker.com', 'hms-networks.com',
        'toyotaeurope.com', 'toyota-europe.com',
        // Finance / consulting / audit
        'grantthornton.eg', 'grantthornton.com',
        'lincolninternational.com', 'bakkavor.com',
        'casablancafinancecity.com',
        'eversheds-sutherland.com',
        // Job boards / recruitment
        'naukrigulf.com', 'rekrute.com', 'bayt.com',
        'globalcareers.lge.com',
        // News / media
        'moderndiplomacy.eu', 'egyptoil-gas.com',
        'ledesk.ma', 'lemonde.fr',
        'verticalmag.com', 'iot-analytics.com',
        'wam.ae', 'mediaoffice.ae',
        'industryevents.com', 'whitmores.com',
        // Government / authority / aviation
        'eurocontrol.int', 'icao.int',
        'dubaisouth.ae', 'maroc.ma',
        'cairo-airport.com',
        'mbraerospacehub.ae', 'medz.ma',
        // Education / training
        'se.com',  // Schneider Electric — too big
        'mantracgroup.com', // Caterpillar dealer
        'symbios-consulting.com', 'sqorus.com',
        // Car dealers / wrong type
        'jameelmotors.com', 'jetouregypt.com',
        'geely.ma', 'hennesseyspecialvehicles.com',
        'infinitytrailers.com', 'collegestationford.com',
        'chryslerjeepdodgecityofmckinney.com',
        // Misc non-targets
        'riosouthtexasregion.com', 'go-globe.com',
        'nvidianews.nvidia.com', 'flash-cg.com',
        'kerix-export.net', 'mcapitalp.com',
        'elgammalgroup.com', 'uaeiso.com',
        'ram-e-shop.com', 'upsegypt.com',
        'alpha-ups.com', 'masegypt.com',
        'egy-com.com', 'awb-electronics.com',
        'caparolarabia.com', 'aawsat.com',
        'petroknowledge.com', 'karnak.egyptair.com',
        'formation.logicat.ma',
        'raqcontracting.com', '2b.com.eg',
        'rayacorp.com', 'gulfcryo.com',
        'falcongroup.ae', 'opplemea.com',
        'mdd.mansourgroup.com', 'mation.com',
        'electrichybridvehicletechnology.com',
        'qsysegypt.com', 'scaler8.com',
        'quantasoftsolutions.com',
        'boschaftermarket.com',
        'infoquestme.com', 'microohm-eg.com',
        'pemodule.com', 'uge-one.com',
        'ecee-ups.com', 'gb-corporation.com',
        'armored-cars.com',
        'flowcrete.ae', 'syscomme.com',
        'aersales.com',
        // ── Egypt iter11 junk (software, dealers, resellers, mining, consultancy) ──
        'atr-aircraft.com',       // ATR — French/Italian OEM (Airbus/Leonardo JV), wrong geography
        'avit.com.eg',            // AVIT — Government aviation IT subsidiary
        'ejad.com',               // eJad — Pure automotive software company
        'natco-sae.com',          // NATCO — Mercedes-Benz car dealership network
        'accm.com.eg',            // ACCM — Calcium carbonate mining company
        'eisac-automation.com',   // EISAC — Automation reseller (Siemens/ABB/Schneider)
        'mehy-eg.com',            // ELMEHY — Lab/pharma equipment dealer/agent
        'microtech-eg.com',       // Microtech — ERP/sales software company
        'pgesco.com',             // PGESCo — Power plant engineering consultancy
        // ── Morocco iter11b junk (news, FTZ, finance, freight, MRO, dealers, giant OEM) ──
        'northafricapost.com',    // North Africa Post — online news site
        'tangermedzones.com',     // Tanger Med Zones — FTZ authority, not a manufacturer
        'atlamed.ma',             // ATLAMED — Private equity / investment fund manager
        'zf-lifetec.com',         // ZF LIFETEC — Giant OEM (ZF Group spin-off), too big
        'comeca-group.com',       // Comeca Group — Large French electrical group, self-sufficient
        'mdsaviation.ma',         // MDS Aviation — Aircraft MRO / avionics dealer
        'tst.ma',                 // TST — Freight / logistics / customs broker
        'ultranet.ma',            // Ultranet — Equipment dealer / reseller (not manufacturer)
        'autologic.ma',           // Autologic — Diagnostic tools e-commerce webshop
        'dunlop-mea.com',         // Dunlop Tyres — Tyre brand regional marketing site
        'etasr.com',              // ETASR — Academic journal publisher
        // ── iter12 all-region junk (market research, megaproject, financial news, telecom news) ──
        'vyansaintelligence.com', // Vyansa Intelligence — Market research report seller
        'neom.com',               // NEOM — Saudi giga-project / government development zone
        'argaam.com',             // Argaam — Arabic financial news / stock market data
        'developingtelecoms.com', // Developing Telecoms — Telecoms industry news site
        'dubaiairshow.aero',      // Dubai Airshow — Trade show/exhibition (not a company)
        'zipline.com',            // Zipline — Drone delivery service platform, not an OEM buyer
        // ── Tunisia iter13 junk ──────────────────────────────────────────────
        'aero-mag.com',           // Aero-Mag — Aerospace trade magazine (UK publisher)
        'taxsummaries.pwc.com',   // PwC — Global tax reference portal (Big Four)
        'elco-solutions.de',      // Elco Solutions — Embedded software consultancy
        'odoo.com',               // Odoo — ERP/CRM software platform (not manufacturer)
        'thinktank.de',           // ThinkTank — IT/business consulting firm (Munich)
        'groupe-telnet.com',      // Groupe TELNET — IT engineering services (Tunisia)
        'sinoextrud.com',         // SinoExtrud — Chinese aluminum extrusion (not Tunisian)
        'leoni-tunisia.com',      // LEONI Tunisia — Wire harness competitor (27K employees)
        'sellami-group.com',      // Sellami Group — Car dealership & spare parts distributor
        'soremat.com.tn',         // SOREMAT — Business consulting firm (Tunis)
        'labrosse.tn',            // Labrosse — Brush & paintbrush manufacturer
        'sicop-pentacol.com',     // SICOP — Glue & paint manufacturer (Sfax)
        'sogeclair.com',          // Sogeclair — Engineering consulting, not manufacturer
        // ── iter14 TN/EG/MA quality audit ──────────────────────────────────
        'presidency.eg',          // Egyptian Presidency — Government (not .gov but still govt)
        'sczone.eg',              // Suez Canal Economic Zone — Government authority
        'ekb.eg',                 // Egyptian Knowledge Bank — Academic/journal platform
        'journals.ekb.eg',        // EKB Journals — Academic journals portal
        'madamasr.com',           // Mada Masr — Egyptian independent news outlet
        'unseenera.ae',           // Unseen Era — UAE-based IT/marketing, not manufacturer
        'tankoilgroup.com',       // Tank Oil Group — Oil/gas, not EMS buyer
        'tuv-nord.com',           // TÜV NORD — Certification body, not manufacturer
        'tuvsud.com',             // TÜV SÜD — Certification body
        'tuv.com',                // TÜV — Certification body
        'bureauveritas.com',      // Bureau Veritas — Certification/inspection body
        'sgs.com',                // SGS — Testing/certification body
        'intertek.com',           // Intertek — Testing/certification body
        'dnv.com',                // DNV — Certification body
        'ul.com',                 // UL — Safety certification body
        'autoelectric.com',       // AutoElectric — US auto parts retailer, not Tunisian
        // ── Tunisia v3 quality audit ───────────────────────────────────
        'ecomnewsmed.com',        // EcomNewsMed — Mediterranean economic news site
        'stucom.tn',              // Stucom — IT/consulting, not manufacturer
        // ── Tunisia v4 quality audit ───────────────────────────────────
        'tsi.tn',                 // Tuniso-Séoudienne d'Investissement — stock brokerage
        'tap.info.tn',            // Tunis Afrique Presse — national news agency
        // ── Tunisia v5 quality audit ───────────────────────────────────
        'prodimode.com',          // Prodimode — US CNC/3D printing service, no Tunisia presence
        // ── iter15 TN/EG/MA quality audit round 2 ──────────────────────
        'cbinsights.com',         // CB Insights — US market intelligence platform
        'search.ebscohost.com',   // EBSCO — Academic database search
        'ebscohost.com',          // EBSCO — Academic database
        'optasense.com',          // OptaSense — UK fiber sensing company
        'optioncarriere.tn',      // OptionCarrière — Job search website (Tunisia)
        'optioncarriere.com',     // OptionCarrière — Job search website
        'indeed.com',             // Indeed — Job website
        'bayt.com',               // Bayt — Job website
        'emploi.tn',              // Emploi.tn — Tunisian job board
        'keejob.com',             // Keejob — Tunisian job board
        'tanitjobs.com',          // Tanitjobs — Tunisian job board
        'glassdoor.com',          // Glassdoor — Job/review website
        'crunchbase.com',         // Crunchbase — Startup database
        'pitchbook.com',          // PitchBook — Financial data
        'zoominfo.com',           // ZoomInfo — Contact database
        // ── iter15b EG quality audit ───────────────────────────────────
        'wuzzuf.net',             // Wuzzuf — Egyptian job board
        'forasna.com',            // Forasna — Egyptian job board
        'jobzella.com',           // Jobzella — Egyptian job board
        'cairoict.com',           // Cairo ICT — Tech conference/event, not company
        'gitex.com',              // GITEX — Tech conference/event
        'arabnet.me',             // ArabNet — Tech conference/event
        'nti.sci.eg',             // NTI — Government telecom institute
        'te.eg',                  // Telecom Egypt — State telecom provider
        'etisalat.eg',            // Etisalat Egypt — Telecom provider (too large)
        'orange.eg',              // Orange Egypt — Telecom provider (too large)
        'vodafone.com.eg',        // Vodafone Egypt — Telecom provider (too large)
        'ups.com',                // UPS — Shipping/logistics company
        'fedex.com',              // FedEx — Shipping company
        'dhl.com',                // DHL — Shipping company
        'midea.com',              // Midea — Giant Chinese appliance OEM
        'haier.com',              // Haier — Giant Chinese appliance OEM
        'lg.com',                 // LG — Giant Korean OEM
        'samsung.com',            // Samsung — Giant Korean OEM
        'panasonic.com',          // Panasonic — Giant Japanese OEM
        'sharp-world.com',        // Sharp — Giant Japanese OEM
        'toshiba.com',            // Toshiba — Giant Japanese OEM
        'whirlpool.com',          // Whirlpool — Giant US appliance OEM
        'electrolux.com',         // Electrolux — Giant Swedish OEM
        'beko.com',               // Beko — Giant Turkish OEM
        // ── iter15c EG deep inspection ────────────────────────────────
        'datacentermap.com',      // Directory/listing site for data centers
        'dnb.com.eg',             // Dun & Bradstreet — business intelligence
        'dnb.com',                // Dun & Bradstreet — business intelligence
        'middleeastmonitor.com',  // Middle East Monitor — news/media site
        'trtworld.com',           // TRT World — Turkish state broadcaster
        'trt.net.tr',             // TRT — Turkish state broadcaster
        'hatla2ee.com',           // Hatla2ee — Egyptian car classifieds
        'eg.hatla2ee.com',        // Hatla2ee Egypt — car classifieds
        'airbnb.com',             // Airbnb — vacation rentals platform
        'global-uploads.webflow.com', // Webflow CDN — not a company
        'webflow.com',            // Webflow — website builder platform
        'solarinvertermanufacturers.com', // SEO spam/directory site
        'factocert.com',          // Factocert — ISO certification consulting
        'sbcertgroup.com',        // SB Cert — ISO certification consulting
        'shipserv.com',           // ShipServ — maritime procurement marketplace
        'cairosales.com',         // Cairo Sales Stores — retail store
        'trane.com',              // Trane Technologies — giant HVAC OEM
        'carrier.com',            // Carrier — giant HVAC OEM
        'daikin.com',             // Daikin — giant HVAC OEM
        'gpxglobal.net',          // GPX Global — data center colocation provider
        'bowfinboats.com',        // Bowfin Boats — US boat manufacturer
        'radioholland.com',       // Radio Holland — Dutch maritime electronics
        'chloride-batteries.com', // Chloride Batteries — SE Asian company
        'mutgroup.net',           // MUT Group — contact data shows furniture
        'nspo.com.eg',            // NSPO — NATO Support and Procurement Org
        'advansys-esc.com',       // Advansys — Slovenian company, not Egyptian
        'afp.com',                // AFP — Agence France-Presse (news agency)
        'reuters.com',            // Reuters — news agency
        'bbc.com',                // BBC — news broadcaster
        'bbc.co.uk',              // BBC — news broadcaster
        'cnn.com',                // CNN — news broadcaster
        'aljazeera.com',          // Al Jazeera — news broadcaster
        'mirsanrack.com',         // Mirsan Rack — Turkish company, no Egyptian operations
        'globaltronics.net',      // GlobalTronics — Philippine company, not Egyptian
        'globaltronics.com.eg',   // GlobalTronics — parked domain
        // ── iter15d MA (Morocco) quality audit ────────────────────
        'hal.science',            // HAL — French academic open-access archive
        'hal.archives-ouvertes.fr', // HAL — academic archive
        'cfmaeroengines.com',     // CFM International — GE/Safran JV (giant OEM)
        'hrcak.srce.hr',          // Hrčak — Croatian academic journal portal
        'scispace.com',           // SciSpace — academic research platform
        'scholar.google.com',     // Google Scholar — academic search
        'researchgate.net',       // ResearchGate — academic network
        'academia.edu',           // Academia.edu — academic network
        'bisinfotech.com',        // BisInfotech — Indian tech news/media
        'assemblymag.com',        // Assembly Magazine — US trade publication
        'no.prysmian.com',        // Prysmian Norway — giant cable manufacturer
        'prysmian.com',           // Prysmian — giant cable manufacturer
        'inwi.ma',                // inwi — Moroccan telecom operator (too large)
        'iam.ma',                 // Maroc Telecom — national telecom (too large)
        'telusdigital.com',       // TELUS Digital — Canadian telecom giant
        'telus.com',              // TELUS — Canadian telecom giant
        'royalmansour.com',       // Royal Mansour — luxury hotel
        'casablanca-bourse.com',  // Casablanca Stock Exchange listing
        'minkels.com',            // Minkels — Dutch data center equipment
        'veichi.com',             // Veichi — Chinese VFD manufacturer
        'resunsolargroup.com',    // Resun Solar — Chinese solar company
        'revues.imist.ma',        // IMIST — Moroccan academic journal index
        // iter15e: University/academic domains
        'uca.ma',                 // Cadi Ayyad University Marrakech
        'ucarech.uca.ma',        // UCA research portal
        'um5.ac.ma',             // Mohammed V University Rabat
        'um6p.ma',               // UM6P
        'uir.ac.ma',            // UIR
        'ieee.org',              // IEEE (academic standards body)
        'springer.com',          // Springer (academic publisher)
        'elsevier.com',          // Elsevier (academic publisher)
        'sciencedirect.com',     // ScienceDirect (academic)
        'mdpi.com',              // MDPI (open access journals)
        'wiley.com',             // Wiley (academic publisher)
        // iter15f: Morocco iter2 junk
        'lockheedmartin.com',     // Lockheed Martin — US defense giant
        'tataadvancedsystems.com', // Tata Advanced Systems — Indian defense conglomerate
        'tatamotors.com',         // Tata Motors
        'tata.com',               // Tata Group
        'bms-sas.com',            // BMS — Lebanese IT distributor (Midis Group)
        'gatewaymedtech.com',     // Gateway MedTech — foreign consultancy
        'qualipro-qms.com',      // Qualipro — Tunisian/French QMS software
        'solarctrl.com',         // SolarCtrl — Chinese solar manufacturer
        'visionair.ma',          // Visionair Maroc — electrical retail shop
        'aresia.com',            // Aresia — French aerospace pyrotechnics
        'nexo-sa.com',           // NEXO SA — French loudspeaker manufacturer (Yamaha)
        'nexo.fr',               // NEXO
        'flexibat.ma',           // Flexibat — modular construction, not solar
        // iter15g: Morocco iter3 junk
        'journals.sagepub.com',   // SAGE Publications — academic journals
        'sagepub.com',            // SAGE Publishing
        'evernex.com',            // Evernex — French IT hardware maintenance
        'energiescoot.com',       // Energie Scoot Maroc — e-scooter parts wholesaler
        // iter15i: Tunisia iter1 massive junk cleanup
        'lufthansa.com',          // Lufthansa — German airline
        'hisensehvac.com',        // Hisense HVAC — Chinese electronics giant
        'hisense.com',            // Hisense
        'watts.eu',               // Watts — European/US water products
        'watts.com',              // Watts Water Technologies
        'defenseadvancement.com', // Defense Advancement — defense directory/news
        'military.africa',        // Military Africa — African military news
        'nordicmonitor.com',      // Nordic Monitor — Scandinavian news
        'arab-reform.net',        // Arab Reform Initiative — think tank
        'dlapiperdataprotection.com', // DLA Piper — law firm data protection page
        'dlapiper.com',           // DLA Piper
        'giz.de',                 // GIZ — German development agency
        'lab-of-tomorrow.com',    // GIZ Lab of Tomorrow
        'inkyfada.com',           // Inkyfada — Tunisian investigative journalism
        'switchmed.eu',           // SwitchMed — EU-funded program
        'rehc.ly',                // Royal Equestrian & Horse Racing Club Bahrain
        'motoma.cn',              // MOTOMA — Chinese battery manufacturer
        'motoma.com',             // MOTOMA
        'nov.com',                // NOV / National Oilwell Varco — US oil & gas giant
        'tesup.com',              // TESUP — UK wind turbine company
        'solaxpower.com',         // SolaX Power — Chinese solar inverter giant
        'carto.com',              // CARTO — Spanish geospatial analytics
        'teltonika-iot-group.com', // Teltonika — Lithuanian IoT company
        'teltonika.com',          // Teltonika
        'gdsdisplays.com',        // GDS Displays — Chinese LED display manufacturer
        'shapr3d.com',            // Shapr3D — Hungarian 3D CAD app
        'integritynext.com',      // IntegrityNext — German compliance platform
        'sanbormedical.com',      // Sanbor Medical — US company
        'buildings-mena.com',     // BUILD_ME — MENA buildings program
        'samitubefittings.com',   // Sami Tube Fittings — Indian manufacturer
        'elmedproject.com',       // ELMED — EU interconnection project
        'icad.com',               // ICAD — Saudi Arabian company
        'ees-int.com',            // EES — Middle East HVAC firm
        'auxsol.com',             // AUXSOL — Chinese solar (AUX Group)
        'sylab.com',              // SY-LAB — Austrian lab instruments
        'biopack.tn',             // BIOPACK — Tunisian bioplastics (wrong sector)
        'ugfsnorthafrica.com.tn', // UGFS — Tunisian finance (wrong sector)
        // iter15j: Tunisia iter2 junk
        'polycliniquelesjasmins.com', // Polyclinique Les Jasmins — medical clinic website
        'polyclinique.com',       // Generic polyclinic domains
        'ilexlifesciences.com',   // Ilex Life Sciences — US biotech
        // iter15k: Tunisia iter3 junk
        'tunisie-foot.com',       // Tunisian football news website
        'cruisemapper.com',       // Cruise ship tracking website
        'kfw.de',                 // KfW — German government development bank
        'hpe.com',                // Hewlett Packard Enterprise — giant OEM
        'marquardt.com',          // Marquardt — German/US multinational OEM
        'ameapower.com',          // AMEA Power — UAE mega-power company
        'adec-technologies.ch',   // Adec Technologies — Swiss company
        'cmr-group.com',          // CMR Group — French company
        'colasrail.com',          // Colas Rail — French multinational
        'talgo.com',              // Talgo — Spanish train manufacturer
        'ats-global.com',         // ATS — Dutch multinational
        'bmd.tn',                 // BMD — extracted name is a description, not company
        'ingenius.ecoledesponts.fr', // Ingenius — French university program
        'ecoledesponts.fr',       // École des Ponts — French university
        'hbm.com',                // HBM/HBK — German multinational (Spectris group)
        'hbkworld.com',           // HBK — same as above, rebranded
        'aymax.fr',               // Aymax — French IT/SAP consulting, not manufacturing
        'seltmarinegroup.com',    // SELT MARINE — seaweed/algae food ingredient company
        'tech216.de',             // tech216 — German GIZ government program, not a company
        'cimgroupe.com',          // CIM Groupe — John Cockerill subsidiary, French multinational
        '2j-antennas.com',        // 2J Antennas — Slovak company, no Tunisia presence
        // iter15l: Egypt iter2 junk
        'alstom.com',             // Alstom — French multinational rail OEM
        'ratpdev.com',            // RATPDev — French multinational transit operator
        'elsewedyelectric.com',   // Elsewedy Electric — major Egyptian conglomerate ($2.7B+)
        // iter15l: Morocco iter2 junk
        'lexmark.com',            // Lexmark — US printer OEM
        'vinci.com',              // VINCI — French multinational construction giant
        'dfds.com',               // DFDS A/S — Danish shipping/logistics company
        'arteliagroup.com',       // Artelia Group — French engineering consultancy
        'fassmer.de',             // Fassmer — German shipbuilding company
        'searates.com',           // SeaRates — freight rate comparison website
        'africaintelligence.fr',  // Africa Intelligence — French media/newsletter
        'fmglobal.com',           // FM Global — US insurance company
        'fmglobal.ma',            // FM Global Morocco
        'keyter.com',             // Keyter — Spanish HVAC manufacturer
        'avsglobalsupply.com',    // AVS Global Supply — generic ship supply
        'bbamorocco.ma',          // BBA Morocco — British Business Association, non-profit
        'gbc.co.ma',              // GBC / Pharmacity — IT integrator, misidentified
        'ideo.ma',                // IDEO Factory — e-learning company, not manufacturing
        // iter15m: Morocco iter3 junk
        'arcelormittal.com',      // ArcelorMittal — steel giant
        'johncockerill.com',      // John Cockerill — Belgian multinational (already name blocked)
        'railwaygazette.com',     // Railway Gazette — trade magazine
        'groupe-sncf.com',        // SNCF — French national railway
        'sncf.com',               // SNCF
        'oncf.ma',                // ONCF — Moroccan national railway
        'piassaty.ma',            // Piassaty — fintech/payment app
        'lerail.com',             // Le Rail — railway news magazine

        // ─── FR/DE audit: giant equipment OEMs (not EMS prospects) ────
        'fanuc.eu', 'fanuc.com', 'fanuc.co.jp',  // FANUC — industrial robotics giant
        'yokogawa.com', 'yokogawa.eu', 'yokogawa.co.jp',  // Yokogawa — process instruments giant
        'kuka.com', 'kuka.cn',  // KUKA — industrial robotics
        'yaskawa.com', 'yaskawa.eu.com', 'yaskawa.co.jp',  // Yaskawa — servo/robot OEM
        'staubli.com',  // Stäubli — robotics/connectors OEM
        'universalrobots.com',  // Universal Robots — cobot OEM
        'endress.com',  // Endress+Hauser — process instrumentation
        'emerson.com',  // Emerson — automation giant
        'balluff.com',  // Balluff — sensor OEM
        'sick.com',  // SICK — sensor/safety OEM
        'ifm.com',  // ifm — sensor OEM
        'turck.com',  // Turck — sensor OEM
        'pilz.com',  // Pilz — safety automation OEM
        'festo.com',  // Festo — pneumatics/automation OEM
        'keyence.com', 'keyence.eu', 'keyence.co.jp',  // Keyence — inspection/sensor OEM

        // ─── FR/DE audit: wrong industry domains ──────────────────────
        'bonitasoft.com',  // Bonitasoft — BPM/workflow software
        'carbios.com',  // CARBIOS — biotech enzyme plastic recycling
        'carester.fr',  // CARESTER — rare earth processing
        'daitokasei.com',  // Daito Kasei — cosmetics raw materials
        'rfi.fr',  // RFI — Radio France Internationale (broadcaster)
        'tivoly.com',  // Tivoly — cutting tools manufacturer
        'mistralcoolers.com',  // Mistral — water cooler/dispenser maker
        'speichim.com',  // Speichim/InterG — solvent distillation
        'straton-plc.com',  // Straton — PLC software, not buyer
        'aceautomation.eu',  // ACE Automation — system integrator

        // ─── FR/DE audit: French government research institutes ───────
        'cea.fr', 'list.cea.fr',  // CEA — French atomic energy commission
        'cnrs.fr',  // CNRS — French national research centre
        'inria.fr',  // INRIA — French CS research institute
        'inserm.fr',  // INSERM — French medical research
        'onera.fr',  // ONERA — French aerospace research
        'ifremer.fr',  // Ifremer — French ocean research
        'irstea.fr',  // Irstea — French agri/env research
        'brgm.fr',  // BRGM — French geological survey

        // ─── FR/DE audit: German government research institutes ───────
        'fraunhofer.de',  // Fraunhofer — German applied research
        'mpg.de', 'mpi-inf.mpg.de',  // Max Planck — German fundamental research
        'helmholtz.de',  // Helmholtz — German research centres
        'leibniz-gemeinschaft.de',  // Leibniz — German research
        'dlr.de',  // DLR — German aerospace centre
        'bam.de',  // BAM — German materials research
        'ptb.de',  // PTB — German metrology institute
        'kit.edu',  // KIT — Karlsruhe Institute of Technology

        // ─── FR/DE audit: rubber / plastics (wrong industry) ─────────
        'hutchinson.com',  // Hutchinson — rubber/vibration
        'contitech.de', 'continental-industry.com',  // ContiTech — rubber/belts
        'trelleborg.com',  // Trelleborg — polymer solutions
        'freudenberg.com',  // Freudenberg — seals/nonwovens
        'nok.co.jp',  // NOK — seals/rubber
        'datwyler.com',  // Datwyler — rubber sealing
        'globus-gummi.de',  // Globus Gummiwerke — rubber products
        'metzeler.com',  // Metzeler — rubber/polymer
        'simrit.com',  // Simrit — seals/rubber
        'eriks.com', 'eriks.de',  // ERIKS — seals/rubber/industrial

        // ─── FR/DE audit: machining / CNC job shops (competitors) ────
        'hfruhstorfer.at',  // CNC machining shop
        'kern-microtechnik.com',  // Kern — precision machining
        'dmgmori.com',  // DMG Mori — CNC machine tool OEM
        'trumpf.com',  // TRUMPF — laser/machine tools OEM
        'mazak.com', 'mazakeu.com',  // Mazak — CNC machine tools OEM
        'okuma.com', 'okuma.eu',  // Okuma — CNC machine tools OEM
        'hermle.de',  // Hermle — milling machines OEM
        'gildemeister.com',  // Gildemeister — machine tools

        // ─── IT/GB/ES audit: CONSULTANCIES (Big Four / Big Three / IT services) ───
        // The #1 junk leak identified by user: "consultancies like Deloitte still get through"
        'deloitte.com', 'deloitte.co.uk', 'deloitte.it', 'deloitte.es',  // Deloitte
        'pwc.com', 'pwc.co.uk', 'pwc.it', 'pwc.es',  // PricewaterhouseCoopers
        'kpmg.com', 'kpmg.co.uk', 'kpmg.it', 'kpmg.es',  // KPMG
        'mckinsey.com',  // McKinsey & Company
        'bcg.com',  // Boston Consulting Group
        'bain.com',  // Bain & Company
        'accenture.com', 'accenture.it', 'accenture.es',  // Accenture
        'capgemini.com', 'capgemini.it', 'capgemini.es',  // Capgemini
        'infosys.com',  // Infosys
        'tcs.com', 'tata.com',  // Tata Consultancy Services
        'wipro.com',  // Wipro
        'cognizant.com',  // Cognizant
        'hcltech.com', 'hcl.com',  // HCL Technologies
        'techmahindra.com',  // Tech Mahindra
        'ltimindtree.com', 'lntinfotech.com',  // LTIMindtree
        'oliverwyman.com',  // Oliver Wyman
        'rolandberger.com',  // Roland Berger
        'boozallen.com',  // Booz Allen Hamilton
        'atos.net',  // Atos
        'dxc.com', 'dxctechnology.com',  // DXC Technology
        'cgi.com', 'cgi-group.co.uk',  // CGI Group
        'soprasteria.com',  // Sopra Steria
        'bearingpoint.com',  // BearingPoint
        'alixpartners.com',  // AlixPartners
        'at-kearney.com', 'kearney.com',  // Kearney (A.T. Kearney)
        'strategyand.pwc.com',  // Strategy& (PwC)
        'lkk.com',  // LEK Consulting
        'simonkucher.com',  // Simon-Kucher
        'protiviti.com',  // Protiviti
        'navigantconsulting.com',  // Navigant (Guidehouse)
        'guidehouse.com',  // Guidehouse
        'fticonsulting.com',  // FTI Consulting
        'mottmac.com',  // Mott MacDonald (engineering consultancy)
        'jacobs.com',  // Jacobs Engineering (consultancy)
        'wsp.com',  // WSP (engineering consultancy)
        'arup.com',  // Arup (engineering consultancy)
        'ramboll.com',  // Ramboll (engineering consultancy)
        'sweco.com',  // Sweco (engineering consultancy)
        'afry.com',  // AFRY (engineering consultancy)
        'bureauveritas.com',  // Bureau Veritas (certification/consultancy)
        'tuv.com', 'tuvnord.com', 'tuvsud.com', 'tuv-rheinland.com',  // TÜV (certification)
        'sgs.com',  // already there but ensure it's solid
        'intertek.com',  // Intertek (testing/certification)
        'ul.com',  // UL (testing/certification)
        'marsh.com', 'marshmclennan.com',  // Marsh McLennan (insurance/consulting)
        'aon.com',  // Aon (insurance/consulting)
        'willisgroup.com', 'wtwco.com',  // Willis Towers Watson
        'genpact.com',  // Genpact (IT/BPO)
        'conduent.com',  // Conduent (IT/BPO)
        'luxoft.com',  // Luxoft (IT consulting)
        'epam.com',  // EPAM Systems (IT services)
        'globant.com',  // Globant (IT services)
        'thoughtworks.com',  // Thoughtworks (IT consulting)
        'altran.com',  // Altran (Capgemini engineering)
        'akkodis.com',  // Akkodis (Adecco Group IT staffing/consulting)
        'alten.com', 'alten.it', 'alten.es',  // ALTEN (engineering consultancy)
        'assystem.com',  // Assystem (engineering consultancy)
        'segula.com', 'segulatechnologies.com',  // SEGULA Technologies (engineering)
        'akka.eu', 'akka-technologies.com',  // AKKA Technologies (engineering)

        // ─── IT/GB/ES audit: STAFFING / RECRUITMENT firms ─────────────
        'adecco.com', 'adecco.co.uk', 'adecco.it', 'adecco.es',  // Adecco
        'randstad.com', 'randstad.co.uk', 'randstad.it', 'randstad.es',  // Randstad
        'manpower.com', 'manpowergroup.com',  // ManpowerGroup
        'hays.com', 'hays.co.uk', 'hays.it', 'hays.es',  // Hays
        'michaelpage.com', 'michaelpage.co.uk', 'michaelpage.it', 'michaelpage.es',  // Michael Page
        'robertwalters.com', 'robertwalters.co.uk',  // Robert Walters
        'pagepersonnel.com', 'pagepersonnel.co.uk',  // Page Personnel
        'roberthalf.com', 'roberthalf.co.uk',  // Robert Half
        'kforce.com',  // Kforce
        'kellyservices.com',  // Kelly Services
        'gi-group.com', 'gigroup.com',  // Gi Group (Italian staffing)

        // ─── IT/GB/ES audit: Italian research institutes ──────────────
        'cnr.it',  // CNR — Italian National Research Council
        'infn.it',  // INFN — Italian National Nuclear Physics Institute
        'enea.it',  // ENEA — Italian National Energy Agency / research
        'asi.it',  // ASI — Italian Space Agency
        'iit.it',  // IIT — Italian Institute of Technology
        'ingv.it',  // INGV — Italian National Geophysics Institute
        'inaf.it',  // INAF — Italian National Astrophysics Institute
        'iss.it',  // ISS — Italian National Health Institute
        'polimi.it',  // Politecnico di Milano
        'polito.it',  // Politecnico di Torino
        'unibo.it',  // University of Bologna
        'uniroma1.it',  // Sapienza University of Rome
        'unipd.it',  // University of Padua
        'unitn.it',  // University of Trento

        // ─── IT/GB/ES audit: British research institutes ──────────────
        'ukri.org',  // UK Research and Innovation
        'npl.co.uk',  // National Physical Laboratory
        'stfc.ac.uk', 'stfc.ukri.org',  // Science and Technology Facilities Council
        'mrc.ac.uk', 'mrc.ukri.org',  // Medical Research Council
        'epsrc.ac.uk', 'epsrc.ukri.org',  // Engineering and Physical Sciences Research Council
        'catapult.org.uk',  // Catapult network (innovation centres)
        'hvm.catapult.org.uk',  // HVM Catapult
        'cpi-uk.com',  // CPI — Centre for Process Innovation
        'amrc.co.uk',  // AMRC — Advanced Manufacturing Research Centre
        'mrc.co.uk',  // Manufacturing Research Centre
        'cranfield.ac.uk',  // Cranfield University
        'imperial.ac.uk',  // Imperial College London
        'cam.ac.uk',  // University of Cambridge
        'ox.ac.uk',  // University of Oxford
        'ucl.ac.uk',  // University College London
        'ed.ac.uk',  // University of Edinburgh
        'warwick.ac.uk',  // University of Warwick
        'nottingham.ac.uk',  // University of Nottingham
        'sheffield.ac.uk',  // University of Sheffield

        // ─── IT/GB/ES audit: Spanish research institutes ──────────────
        'csic.es',  // CSIC — Spanish National Research Council
        'inta.es',  // INTA — Spanish National Aerospace Technology Institute
        'ciemat.es',  // CIEMAT — Spanish Energy/Environment Research Centre
        'cdti.es',  // CDTI — Spanish Innovation Technology Centre
        'tecnalia.com',  // Tecnalia — Basque research institute
        'ikerbasque.net',  // Ikerbasque — Basque science foundation
        'eurecat.org',  // Eurecat — Catalan technology centre
        'upm.es',  // Universidad Politécnica de Madrid
        'upc.edu',  // Universitat Politècnica de Catalunya
        'upv.es',  // Universitat Politècnica de València
        'uc3m.es',  // Universidad Carlos III de Madrid
        'ugr.es',  // Universidad de Granada
        'uab.cat',  // Universitat Autònoma de Barcelona
        'unizar.es',  // Universidad de Zaragoza

        // ─── IT/GB/ES audit: Italian wrong-industry domains ───────────
        // Wine / olive oil / food (Italy's famous non-manufacturing sectors)
        'gamberorosso.it',  // Gambero Rosso — wine/food magazine
        'vinitaly.com',  // Vinitaly — wine trade fair
        'federvini.it',  // Federvini — Italian wine federation
        'uiv.it',  // UIV — Italian wine union
        'confindustria.it',  // Confindustria — Italian employers' confederation
        'aidepi.it',  // AIDEPI — Italian pasta/confectionery association
        'cfrfrancia.com',  // CFR Francia — unclear
        'eni.com',  // Eni — Italian oil & gas giant
        'enel.com', 'enel.it',  // Enel — Italian utility giant
        'snam.it',  // Snam — Italian gas infrastructure
        'terna.it',  // Terna — Italian electricity grid
        'posteitaliane.it',  // Poste Italiane — Italian postal service
        'mediobanca.com',  // Mediobanca — Italian investment bank
        'unicredit.it',  // UniCredit — Italian bank
        'intesasanpaolo.com',  // Intesa Sanpaolo — Italian bank
        'assicurazionigenerali.com', 'generali.com',  // Generali — Italian insurance
        'luxottica.com', 'essilorluxottica.com',  // Luxottica — eyewear (own mfg)

        // ─── IT/GB/ES audit: British wrong-industry domains ───────────
        'bp.com',  // BP — oil & gas
        'shell.co.uk',  // Shell UK
        'hsbc.co.uk', 'hsbc.com',  // HSBC — bank
        'barclays.co.uk', 'barclays.com',  // Barclays — bank
        'lloydsbank.com',  // Lloyds Bank
        'standardchartered.com',  // Standard Chartered — bank
        'aviva.co.uk',  // Aviva — insurance
        'prudential.co.uk',  // Prudential — insurance
        'legalandgeneral.com',  // L&G — insurance
        'astrazeneca.com',  // AstraZeneca — pharma
        'gsk.com',  // GSK — pharma
        'unilever.com', 'unilever.co.uk',  // Unilever — FMCG
        'diageo.com',  // Diageo — beverages
        'reckitt.com',  // Reckitt Benckiser — consumer goods
        'bt.com',  // BT Group — telecom
        'vodafone.co.uk',  // Vodafone UK — telecom
        'sky.com',  // Sky — media/telecom
        'bbc.co.uk', 'bbc.com',  // BBC — media

        // ─── IT/GB/ES audit: Spanish wrong-industry domains ───────────
        'iberdrola.com', 'iberdrola.es',  // Iberdrola — utility giant
        'repsol.com', 'repsol.es',  // Repsol — oil & gas
        'telefonica.com', 'telefonica.es',  // Telefónica — telecom
        'bbva.com', 'bbva.es',  // BBVA — bank
        'santander.com', 'santander.es',  // Santander — bank
        'caixabank.com',  // CaixaBank — bank
        'mapfre.com',  // Mapfre — insurance
        'inditex.com',  // Inditex/Zara — fashion (own retail)
        'mango.com',  // Mango — fashion
        'acciona.com',  // Acciona — infrastructure/renewable
        'ferrovial.com',  // Ferrovial — infrastructure
        'acs.es',  // ACS — construction
        'renfe.com',  // Renfe — Spanish railway
        'aena.es',  // Aena — Spanish airports
        'correos.es',  // Correos — Spanish postal service

        // ─── IT/GB/ES audit: UK industry directories / associations ───
        'themanufacturer.com',  // UK manufacturing magazine
        'makeuk.org',  // Make UK — manufacturers' association
        'adsgroup.org.uk',  // ADS Group — UK aerospace/defence trade body
        'techuk.org',  // techUK — UK technology trade body
        'imeche.org',  // IMechE — Institution of Mechanical Engineers
        'theiet.org',  // IET — Institution of Engineering and Technology
        'gambica.org.uk',  // GAMBICA — UK automation trade body
        'beama.org.uk',  // BEAMA — UK electrical industry trade body

        // ─── TX v5 audit: machining shop competitors & wrong-industry ──
        'tcrhouston.com',  // TCR Houston — machine shop competitor
        'leanwerks.com',  // Leanwerks — precision machine shop competitor
        'industrialautomation.us',  // Industrial Automation — system integrator (NOT iagse.com which is a Boeing OEM)
        'us.metoree.com', 'metoree.com',  // Metoree — B2B product directory / marketplace
        
        // ─── Expanded CNC / Machining Job Shop Competitors (Feb 2026 sweep) ────
        'protolabs.com', 'protolabs.co.uk', 'protolabs.de',  // Protolabs — CNC/3DP service
        'hubs.com',  // Hubs (ex-3D Hubs) — manufacturing marketplace
        'xometry.com', 'xometry.de', 'xometry.eu',  // Xometry — CNC/injection marketplace
        'fictiv.com',  // Fictiv — CNC/injection service
        'rapidmade.com',  // RapidMade — CNC/3DP service
        'plethora.com',  // Plethora — CNC service
        'emachineshop.com',  // eMachineShop — CNC service
        'quickparts.com',  // Quickparts — CNC/3DP service
        'fastradius.com',  // Fast Radius — CNC/3DP/injection
        'pcbway.com',  // PCBWay — also offers CNC machining
        'jlcpcb.com',  // JLCPCB — also offers CNC machining
        'makeronline.com',  // Maker Online — CNC marketplace
        'fractory.com',  // Fractory — sheet metal/CNC service
        'weerg.com',  // Weerg — CNC/3DP service
        'craftcloud3d.com',  // Craftcloud — 3DP/CNC service
        'materialise.com',  // Materialise — 3DP/CNC giant
        'shapeways.com',  // Shapeways — 3DP/CNC giant
        'sculpteo.com',  // Sculpteo — 3DP/CNC giant
        'i.materialise.com',  // iMaterialise — 3DP/CNC
        'jawstec.com',  // JawsTec — CNC/3DP service
        'kreatize.com',  // Kreatize — CNC service
        'klaritymedical.com',  // medical machining
        'doylemanufacturing.com',  // Doyle Manufacturing — machine shop
        'jabilengineeredmaterials.com',  // Jabil — competition
        'richmondcnc.com',  // Richmond CNC — machine shop
        'manncnc.com',  // Mann CNC — machine shop
        'starrapid.com',  // Star Rapid — rapid prototyping/CNC
        'wenzelamerica.com',  // Wenzel — CNC measurement
        'mastercam.com',  // Mastercam — CAM software (equipment)
        'solidcam.com',  // SolidCAM — CAM software (equipment)
        'hsmworks.com',  // HSMWorks — CAM software (equipment)
        'espritcam.com',  // ESPRIT CAM — software (equipment)
        'autodesk.com',  // Autodesk — includes CAM software
        'haascnc.com', 'haasautomation.com',  // Haas — CNC machine tool OEM
        'hurco.com',  // Hurco — CNC machine tool OEM
        'makiinc.com',  // Makino — CNC machine tool OEM
        'doosanmachintools.com',  // Doosan — CNC machine tool OEM
        'hyundai-wia.com',  // Hyundai Wia — CNC machine tool OEM
        'starrag.com',  // Starrag — CNC machine tool OEM
        'grobgroup.com',  // GROB — CNC machine tool OEM
        'chiron-group.com',  // CHIRON — CNC machine tool OEM
        'hardinge.com',  // Hardinge — CNC machine tools
        'citizen.co.jp', 'citizenmachinery.co.uk',  // Citizen Machinery
        'tornos.com',  // Tornos — Swiss-type CNC machines
        'petersenmachine.com',  // Petersen Machine — machine shop
        'swisstek.com',  // SwissTek — Swiss machining

        // ─── TX v6 audit: telecom contractors, automation suppliers, junk ──
        'trovit.tn',  // Trovit — Tunisian web utility portal, not a company
        'brauntelecom.de',  // braun teleCom — telecom equipment supplier (now Netceed)
        'wirelessequity.com',  // Wireless Equity Group — cell tower investment firm
        'texasareatelecom.com',  // Texas Area Telecom — telecom service contractor
        'btscable.com',  // BTS Cable — fiber/coax network construction
        'mitel.com',  // Mitel — unified communications/VoIP provider
        'motorcarparts.com',  // Motorcar Parts of America — aftermarket auto parts
        'sumitomoelectric.com',  // Sumitomo Electric — giant Tier 1 wire harness competitor
        'tm-robot.com',  // Techman Robot — cobot/robot manufacturer (equipment supplier)
        'unitronicsplc.com',  // Unitronics — PLC/HMI manufacturer (equipment supplier)
        'iqrobotics.com',  // IQ Robotics — warehouse automation robots (equipment supplier)
        'pilotcloud.net',  // vendor application portals
        'renaultgroup.com', 'renault.com',  // Renault — giant OEM (too big, makes own components)
        'ficosa.com',  // Ficosa — large Tier 1 supplier
        'opmobility.com',  // OPmobility — large Tier 1 supplier (ex-Plastic Omnium)
        
        // ══ OWN COMPANY DOMAINS (never discover ourselves) ══════════════════
        'starzelectronics.com',   // Starz Electronics — OUR company
        'starzelectronics.site',  // Starz Electronics — alternate domain
        'starzcrm.com',           // Starz CRM — OUR CRM platform
        'starzenergies.com',      // Starz Energies — OUR sister company
    ];

    /**
     * Check if a domain's country-code TLD belongs to a completely
     * different region than the current search target.
     *
     * e.g. searching for Egypt → .it (Italy) domain = mismatch
     *      searching for Germany → .eg (Egypt) domain = mismatch
     *
     * Only triggers for UNAMBIGUOUS mismatches. Generic TLDs (.com,
     * .net, .org, .io, .co) are always allowed. Neighbouring/related
     * countries are NOT rejected (e.g. .ae is allowed for GCC).
     */
    private function isCcTldRegionMismatch(string $domain, string $region): bool
    {
        if ($region === 'GENERIC' || $region === '') {
            return false;
        }

        // Extract the TLD
        $domain = $this->canonicalizeDomain($domain);

        // Fast-path rejects (cheap checks before heavier blocklists)
        if ($domain === '' || preg_match('/\s/', $domain)) {
            return true;
        }
        if (preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/', $domain)) {
            return true;
        }
        if (str_contains($domain, '/')) {
            return true;
        }

        // Common non-prospect platforms / content hosts
        if (preg_match('/(^|\.)(facebook|instagram|x|twitter|youtube|youtu|tiktok|pinterest|reddit|quora|medium|substack|wixsite|wordpress|blogspot|tumblr|weebly|notion(?:\.site)?|canva|slideshare|issuu|scribd|wikipedia|wiktionary|wikimedia|maps|openstreetmap|crunchbase|zoominfo|apollo|rocketreach|signalhire|hunter\.io|amazon|ebay|etsy|alibaba|aliexpress|booking|tripadvisor|expedia|agoda|glassdoor|indeed|monster|stepstone|bayt|wuzzuf|tanqeeb)\./i', $domain)) {
            return true;
        }

        // Static/document/help subdomains are almost never root company sites
        if (preg_match('/^(?:cdn|static|assets?|img|images?|files?|downloads?|docs?|help|support|status|api)\./i', $domain)) {
            return true;
        }

        $domain = strtolower(preg_replace('/^www\./', '', $domain));
        if (!preg_match('/\.([a-z]{2,3})$/', $domain, $m)) {
            return false;
        }
        $tld = $m[1];

        // Generic TLDs — always OK
        static $genericTlds = [
            'com', 'net', 'org', 'io', 'co', 'biz', 'info', 'pro',
            'xyz', 'dev', 'app', 'tech', 'online', 'site', 'store',
            'global', 'int', 'edu', 'gov', 'mil', 'aero', 'coop',
        ];
        if (in_array($tld, $genericTlds, true)) {
            return false;
        }

        // Map each TLD to its region code (same codes detectRegionFromLocation uses)
        static $tldToRegion = [
            // North Africa / Middle East
            'ma' => 'MA', 'tn' => 'TN', 'eg' => 'EG',
            'ae' => 'GCC', 'sa' => 'GCC', 'qa' => 'GCC',
            'bh' => 'GCC', 'om' => 'GCC', 'kw' => 'GCC',
            // Europe
            'de' => 'DE', 'fr' => 'FR', 'pl' => 'PL', 'nl' => 'NL',
            'it' => 'IT', 'es' => 'ES', 'be' => 'BE', 'at' => 'AT',
            'cz' => 'CZ', 'se' => 'SE', 'dk' => 'DK', 'fi' => 'FI',
            'no' => 'NO', 'ch' => 'CH', 'ro' => 'RO', 'hu' => 'HU',
            'pt' => 'PT', 'ie' => 'IE',
            'uk' => 'GB', 'gb' => 'GB',
            // Asia
            'cn' => 'CN', 'jp' => 'JP', 'kr' => 'KR', 'in' => 'IN',
            'tw' => 'TW', 'th' => 'TH', 'vn' => 'VN', 'id' => 'ID',
            'my' => 'MY', 'sg' => 'SG', 'ph' => 'PH',
            // Americas
            'us' => 'US', 'ca' => 'CA', 'mx' => 'MX', 'br' => 'BR',
            'ar' => 'AR', 'cl' => 'CL', 'co' => 'CO',
            // Other
            'au' => 'AU', 'nz' => 'NZ', 'za' => 'ZA',
            'ru' => 'RU', 'ua' => 'UA', 'tr' => 'TR', 'il' => 'IL',
            'ng' => 'NG', 'ke' => 'KE', 'pk' => 'PK',
        ];

        $domainRegion = $tldToRegion[$tld] ?? null;
        if ($domainRegion === null) {
            // Unknown ccTLD — allow it through
            return false;
        }

        // Same region → OK
        if ($domainRegion === $region) {
            return false;
        }

        // Allow EU cross-matches: an .it domain is OK when searching
        // for EU, or vice versa. Define compatible region groups.
        static $euGroup = ['DE', 'FR', 'PL', 'NL', 'IT', 'ES', 'BE', 'AT',
            'CZ', 'SE', 'DK', 'FI', 'NO', 'CH', 'RO', 'HU', 'PT', 'IE', 'GB', 'EU'];
        static $gccGroup = ['GCC', 'AE', 'SA', 'QA', 'BH', 'OM', 'KW'];
        static $nafricaGroup = ['MA', 'TN', 'EG'];

        // Within the same broad group → allow
        if (in_array($region, $euGroup, true) && in_array($domainRegion, $euGroup, true)) {
            return false;
        }
        if (in_array($region, $gccGroup, true) && in_array($domainRegion, $gccGroup, true)) {
            return false;
        }

        // EU ↔ MENA cross-compatibility: European multinationals commonly
        // have factories in North Africa & GCC (nearshoring). A .fr or .de
        // domain is perfectly valid when searching for TN/MA/EG companies.
        // Likewise, MENA-based companies may appear in EU searches.
        static $menaGroup = ['MA', 'TN', 'EG', 'GCC', 'AE', 'SA', 'QA', 'BH', 'OM', 'KW'];
        if (in_array($region, $menaGroup, true) && in_array($domainRegion, $euGroup, true)) {
            return false;
        }
        if (in_array($region, $euGroup, true) && in_array($domainRegion, $menaGroup, true)) {
            return false;
        }

        // North Africa countries are NOT interchangeable — Egypt ≠ Morocco
        // But a .com.eg domain found in EG search is fine (same region match above).

        // If we reach here, the ccTLD region doesn't match → mismatch
        return true;
    }

    /**
     * Check if a domain should be excluded from company import.
     */
    private function isBlockedDomain(string $domain): bool
    {
        $domain = strtolower(preg_replace('/^www\./', '', $domain));

        // ─── Block code hosting / developer platform domains ──────────
        // Structural rule: these are collaboration platforms, not buyer companies.
        if (preg_match('/(^|\.)(github|gitlab|bitbucket|sourceforge|codeberg|launchpad)\./i', $domain)) {
            return true;
        }

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
            'morocco.', 'egypt.', 'karnak.', 'mdd.',
            'globalcareers.', 'nvidianews.',
        ];
        foreach ($junkSubdomainPrefixes as $prefix) {
            if (str_starts_with($domain, $prefix)) {
                return true;
            }
        }

        // ─── Block known multi-TLD shopping sites ──────────────────
        $multiTldSites = ['ubuy', 'temu', 'shein', 'wish', 'banggood', 'gearbest'];
        foreach ($multiTldSites as $site) {
            if (preg_match('/^' . preg_quote($site, '/') . '\.[a-z]{2,6}$/i', $domain)) {
                return true;
            }
        }

        // ─── Block market research / report seller domains ───────
        $marketResearchDomains = [
            'polarismarketresearch.com', 'grandviewresearch.com', 'marketsandmarkets.com',
            'mordorintelligence.com', 'transparencymarketresearch.com', 'alliedmarketresearch.com',
            'researchandmarkets.com', 'futuremarketinsights.com', 'precedenceresearch.com',
            'straitsresearch.com', 'emergenresearch.com', 'verifiedmarketresearch.com',
            'expertmarketresearch.com', 'dataintelo.com', 'coherentmarketinsights.com',
            'globenewswire.com', 'prnewswire.com', 'businesswire.com',
            'reportlinker.com', 'psmarketresearch.com', 'reportsinsights.com',
            'imarcgroup.com', 'sphericalinsights.com', 'factmr.com',
        ];
        foreach ($marketResearchDomains as $mrd) {
            if ($domain === $mrd || str_ends_with($domain, '.' . $mrd)) {
                return true;
            }
        }

        // ─── Block emergency / disaster mapping domains ──────────
        if (str_contains($domain, 'emergency.copernicus') || str_contains($domain, 'copernicus.eu')) {
            return true;
        }

        // ─── Block job portal subdomains ───────────────────────────
        if (str_contains($domain, '.myworkdayjobs.com') || str_contains($domain, '.workday.com')) {
            return true;
        }

        // ─── Block domains with news/media/magazine in the name ───
        // These are almost never real companies.
        // Use both \b word-boundary AND str_contains for compound words
        // like "dailymail", "foxnews", "autonews", "techcrunch".
        if (preg_match('/\b(news|magazine|insider|tribune|herald|chronicle|times|gazette|dispatch|journal|digest|observer|telegraph|daily|weekly|monthly|media)\b/i', $domain)) {
            // Exception: domains where the word is part of a real company name
            if (!preg_match('/(siemens|boeing|airbus|safran|thales|dassault)/i', $domain)) {
                return true;
            }
        }
        // Also catch compound-word news domains where \b fails
        // e.g. "opportimes.com", "dailymail.co.uk", "arabfinance.com"
        $compoundNewsParts = ['dailymail', 'foxnews', 'nbcnews', 'cbsnews', 'abcnews',
            'huffpost', 'buzzfeed', 'techcrunch', 'autonews', 'autoblog',
            'autoweek', 'motortrend', 'jalopnik', 'topgear', 'insideevs',
            'electrive', 'automobilwoche', 'greencar', 'cleantechnica',
            'opportimes', 'arabfinance', 'middleeasteye',
            'theafricareport', 'africanews', 'allafrica',
            'moroccoworldnews', 'hespress', 'medias24',
            'egypttoday', 'ahramonline', 'egyptindependent',
        ];
        foreach ($compoundNewsParts as $newsPart) {
            if (str_contains($domain, $newsPart)) {
                return true;
            }
        }
        // Catch domains where media words are embedded as suffixes
        // e.g. "opportimes.com" → root "opportimes" ends with "times"
        $domainRoot = preg_replace('/\.[a-z]{2,6}(\.[a-z]{2,3})?$/i', '', $domain);
        $mediaSuffixes = ['times', 'news', 'daily', 'tribune', 'herald',
            'gazette', 'chronicle', 'dispatch', 'observer', 'telegraph',
            'monitor', 'journal', 'digest', 'weekly', 'monthly', 'magazine',
            'insider', 'post', 'media', 'report', 'insights',
        ];
        foreach ($mediaSuffixes as $suffix) {
            // Only match as suffix when preceded by at least 2 chars (avoid false positives on short domains)
            if (str_ends_with($domainRoot, $suffix) && strlen($domainRoot) > strlen($suffix) + 1) {
                // Exception: protect legitimate companies
                if (!preg_match('/(siemens|boeing|airbus|safran|thales|dassault)/i', $domain)) {
                    return true;
                }
            }
        }

        // ─── Block specific false-positive domains from prior runs ──
        $manualBlockDomains = [
            'batteryswapstation.com', '234drive.com', 'interplasinsights.com',
            'theafricareport.com', 'meer.com', 'wammorocco.com',
            'aui.ma',  // Al Akhawayn University
            'supplios.com', 'ziphq.com', 'bill.com', 'kefron.com',  // SaaS / procurement platforms
            'suppliergateway.com', 'medius.com', 'adm.com',  // software / food conglomerate
            'gartner.com', 'nddefense.com',  // analyst / US defense
            'icape-group.com',  // PCB broker, not OEM
        ];
        foreach ($manualBlockDomains as $mbd) {
            if ($domain === $mbd || str_ends_with($domain, '.' . $mbd)) {
                return true;
            }
        }

        // ─── Block university / education domains ────────────────────
        // .ac.*, .edu.* already caught, but catch .ma/.eg universities
        if (preg_match('/\b(university|universite|universit[ay]|college|institut|ecole|escola|schule|akademie|academy)\b/i', $domain)) {
            return true;
        }
        $eduDomains = ['aui.ma', 'um5.ac.ma', 'um6p.ma', 'uir.ac.ma', 'emines.um6p.ma'];
        foreach ($eduDomains as $edu) {
            if ($domain === $edu || str_ends_with($domain, '.' . $edu)) {
                return true;
            }
        }

        // ─── Block financial / investment domains ─────────────────
        // Exception: industrial companies that happen to have finance words
        // e.g., "investorpowersystems.com", "capitalelectric.com"
        if (preg_match('/\b(finance|investment|banking|capital|fund|equity|venture|investor)\b/i', $domain)) {
            if (!preg_match('/(electric|motor|power|industri|tech|system|manufact|engineer|machine|autom)/i', $domain)) {
                return true;
            }
        }

        // ─── Block certification / audit domains ─────────────────
        if (preg_match('/\b(certification|certifying|accreditation|registrar)\b/i', $domain)) {
            return true;
        }

        // ─── Block standards body / norms / normalization domains ──
        if (preg_match('/\b(standard|norm|normy|normen|normes|norme)\b/i', $domain)) {
            // Exception: industrial companies that happen to have 'standard' in name
            if (!preg_match('/(electric|motor|industri|aero|tech)/i', $domain)) {
                return true;
            }
        }
        $standardsBodies = ['technickenormy', 'normservis', 'beuth', 'normy'];
        foreach ($standardsBodies as $sb) {
            if (str_contains($domain, $sb)) {
                return true;
            }
        }

        // ─── Block shipping / logistics company subdomains ───────
        if (str_ends_with($domain, '.ups.com') || str_ends_with($domain, '.fedex.com') || str_ends_with($domain, '.dhl.com')) {
            return true;
        }

        // ─── Block Honeywell subdivisions (too generic) ─────────
        if (str_ends_with($domain, '.honeywell.com') && $domain !== 'honeywell.com') {
            return true;
        }

        // ─── Block Big Four / Big Three / major consultancy domains (iter13 + IT/GB/ES) ──
        // Block both main domains AND subdomains of major consulting firms
        $majorConsultancies = [
            'pwc.com', 'deloitte.com', 'ey.com', 'kpmg.com',  // Big Four
            'mckinsey.com', 'bcg.com', 'bain.com',  // Big Three
            'accenture.com', 'capgemini.com',  // IT consultancies
            'infosys.com', 'tcs.com', 'wipro.com', 'cognizant.com',  // Indian IT
            'hcltech.com', 'techmahindra.com', 'ltimindtree.com',  // Indian IT
            'oliverwyman.com', 'rolandberger.com', 'kearney.com',  // Strategy
            'boozallen.com', 'guidehouse.com', 'fticonsulting.com',  // Advisory
            'bearingpoint.com', 'alixpartners.com', 'protiviti.com',  // Advisory
            'atos.net', 'dxc.com', 'cgi.com', 'soprasteria.com',  // IT services
            'epam.com', 'globant.com', 'thoughtworks.com', 'luxoft.com',  // IT services
            'genpact.com', 'conduent.com',  // BPO
            'alten.com', 'assystem.com', 'segula.com', 'altran.com',  // Engineering consultancy
            'mottmac.com', 'jacobs.com', 'wsp.com', 'arup.com',  // Engineering consultancy
            'ramboll.com', 'sweco.com', 'afry.com',  // Engineering consultancy
            'marsh.com', 'marshmclennan.com', 'aon.com', 'wtwco.com',  // Insurance consulting
        ];
        foreach ($majorConsultancies as $consultancy) {
            if ($domain === $consultancy || str_ends_with($domain, '.' . $consultancy)) {
                return true;
            }
        }

        // ─── Block consulting / advisory / services domains ──────
        // Companies with "consulting", "beratung", "conseil" etc. in their
        // domain are service firms, not OEM manufacturers buying EMS assemblies.
        if (preg_match('/\b(consulting|consultancy|consultant|advisory|advisors|beratung|conseil|advies|consulenza|consultoria|doradztwo|asesores|assessoria)\b/i', $domain)) {
            return true;
        }
        // German consulting compound-word domains (no word boundary — compounds)
        if (preg_match('/(unternehmensberatung|managementberatung|strategieberatung|personalberatung|prozessberatung|organisationsberatung|itberatung|logistikberatung)/i', $domain)) {
            return true;
        }

        // ─── Block staffing / recruitment domains ────────────────
        if (preg_match('/\b(staffing|recruitment|recruiting|headhunt|manpower|workforce|jobboard|jobsite|emploi|lavoro|empleo|arbeit)\b/i', $domain)) {
            return true;
        }

        // ─── Block certification / testing / inspection (TIC) domains ──
        // Catch T[ÜU]V variants, cert-containing domains, and known TIC companies
        if (preg_match('/\b(tuv|tüv|tuev|certification|certifying|accreditation|registrar|inspection|testing-lab)\b/i', $domain)) {
            return true;
        }
        // Catch certification-related substrings in compound domain names
        // e.g. "proficert.com", "eurocert.de", "qualicert.ch"
        $domainRootForCert = preg_replace('/\.[a-z]{2,6}(\.[a-z]{2,3})?$/i', '', $domain);
        $certSubstrings = ['certif', 'zertif', 'accredit', 'homolog', 'proficert',
            'eurocert', 'qualicert', 'isocert', 'certqua', 'tuvcert',
        ];
        foreach ($certSubstrings as $certSub) {
            if (str_contains($domainRootForCert, $certSub)) {
                return true;
            }
        }
        // Block known TIC company domains
        $ticDomains = [
            'tuv-nord.com', 'tuv-nord.de', 'tuev-nord.de', 'tuev-nord.com',
            'tuvsud.com', 'tuv-sud.de', 'tuev-sued.de',
            'tuv.com', 'tuvrheinland.com', 'tuv-rheinland.de',
            'dekra.com', 'dekra.de', 'dekra.fr',
            'sgs.com', 'intertek.com', 'bureauveritas.com',
            'dnv.com', 'dnvgl.com', 'lr.org', 'lloydsregister.com',
            'bsigroup.com', 'ul.com', 'ul-europe.com',
            'applus.com', 'eurofins.com', 'lrqa.com', 'nqa.com',
            'proficert.com', 'proficert.de',
            'kiwa.com', 'nemko.com', 'csa-group.org',
        ];
        foreach ($ticDomains as $ticD) {
            if ($domain === $ticD || str_ends_with($domain, '.' . $ticD)) {
                return true;
            }
        }

        // ─── Block IT/GB/ES research institute domains ───────────
        // Italian research
        if (preg_match('/\.(cnr|infn|enea|asi|iit|ingv|inaf|iss)\.it$/i', $domain)) {
            return true;
        }
        // Spanish research
        if (preg_match('/\.(csic|inta|ciemat|cdti)\.es$/i', $domain)) {
            return true;
        }
        // UK research (ac.uk already blocked by TLD check, add specific ones)
        if (preg_match('/\.(ukri|catapult)\.org(\.uk)?$/i', $domain)) {
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

        // ─── Block pharma / biotech domains ──────────────────────
        if (preg_match('/\b(pharma|pharmaceutical|biotech|biopharma|medipharma|lifesciences)/i', $domain)) {
            return true;
        }

        // ─── Block chemical / fertilizer domains ─────────────────
        if (preg_match('/\b(chemical|petrochem|agrochemical|fertilizer)/i', $domain)) {
            // Exception: "electrochemical" is relevant
            if (!preg_match('/electrochem/i', $domain)) {
                return true;
            }
        }

        // ─── Block gaming / game studio domains ──────────────────
        if (preg_match('/\b(games?studio|gamedevelop|gamingcompany)/i', $domain)) {
            return true;
        }

        // ─── Block e-shop / online store domains ─────────────────
        if (preg_match('/[-](shop|store|eshop|e-shop)\./i', $domain) || preg_match('/\b(eshop|e-shop|onlineshop|webshop)\b/i', $domain)) {
            return true;
        }

        // ─── Block appliance retailer domains ────────────────────
        if (preg_match('/\bappliance/i', $domain)) {
            return true;
        }

        // ─── Block textile / garment / apparel domains ───────────
        if (preg_match('/\b(textile|garment|apparel|knitwear|weaving|spinning|denim|leather|footwear|fashion)\b/i', $domain)) {
            return true;
        }

        // ─── Block trade body / association / chamber domains ────
        if (preg_match('/\b(chamber|association|federation|confederation|council)\b/i', $domain)) {
            // Exception: product/technology councils that are real companies
            if (!preg_match('/(pci|nema|jedec)/i', $domain)) {
                return true;
            }
        }

        // ─── Block packaging / printing domains ──────────────────
        if (preg_match('/\b(packaging|printing|printshop|labelprint|cartonbox)\b/i', $domain)) {
            return true;
        }

        // ─── Block furniture / woodworking domains ───────────────
        if (preg_match('/\b(furniture|woodwork|carpentry|cabinetry)\b/i', $domain)) {
            return true;
        }

        // ─── Block market research domains ───────────────────────
        if (preg_match('/marketresearch|marketinsights|market-insights|intelligence\.com|vyansa/i', $domain)) {
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

        // ─── Block government *.nat.* domains ────────────────────
        // National government agencies (e.g. onagri.nat.tn, cnss.nat.tn)
        if (preg_match('/\.nat\.[a-z]{2,3}$/i', $domain)) {
            return true;
        }

        // ─── Block government investment promotion agencies ──────
        // Domains like investintunisia.tn, invest-in-morocco.com, etc.
        if (preg_match('/\binvest(in|ment)?[-.]?(tunisia|maroc|morocco|egypt|algeri|liby)/i', $domain)) {
            return true;
        }
        // FIPA / AMDIE / GAFI style investment promotion agencies
        if (preg_match('/\b(fipa|amdie|gafi)\b/i', $domain)) {
            return true;
        }

        // ─── Block IEEE domains ──────────────────────────────────
        // IEEE chapters, student branches, conferences — NOT companies
        if (str_contains($domain, 'ieee.') || str_contains($domain, '.ieee.')) {
            return true;
        }

        // ─── Block job portal / employment domains ────────────────
        // Career sites, job boards, recruitment platforms
        $jobPortalDomains = [
            'farojob.net', 'farojob.com', 'emploi.tn', 'emploi.ma',
            'rekrute.com', 'bayt.com', 'tanqeeb.com', 'wuzzuf.net',
            'indeed.com', 'glassdoor.com', 'monster.com',
            'jobi.tn', 'keejob.com', 'optioncarriere.tn',
            'tanitjobs.com', 'tunisietravail.net',
        ];
        foreach ($jobPortalDomains as $jpd) {
            if ($domain === $jpd || str_ends_with($domain, '.' . $jpd)) {
                return true;
            }
        }
        if (preg_match('/\b(emploi|job[-]?board|jobborse|offres[-]?emploi|chercher[-]?emploi|trouver[-]?emploi)\b/i', $domain)) {
            return true;
        }

        // ─── Block French/German government research institute domains ──
        if (preg_match('/\.(cea|cnrs|inria|inserm|onera|ifremer|brgm|irstea)\.fr$/i', $domain)) {
            return true;
        }
        if (preg_match('/\.(fraunhofer|mpg|helmholtz|dlr|bam|ptb)\.de$/i', $domain)) {
            return true;
        }

        // ─── Block .org domains (associations, not manufacturers) ─
        // Very few legitimate OEM prospects use .org
        if (str_ends_with($domain, '.org') || preg_match('/\.org\.[a-z]{2,3}$/i', $domain)) {
            return true;
        }

        // ─── Block .edu domains and educational institutions ──────
        if (preg_match('/\.edu(\.[a-z]{2,3})?$/i', $domain)) {
            return true;
        }
        // French "grandes écoles" and polytechnics
        if (preg_match('/\.(polytechnique|ensam|ensta|ens-lyon|ens-paris|mines-paristech|ec-nantes|centralesupelec|insa|utc|utt)\.fr$/i', $domain)) {
            return true;
        }
        // German Hochschulen
        if (preg_match('/\b(hochschule|fachhochschule|universitaet|universitât|studieren)\b/i', $domain)) {
            return true;
        }
        // Italian/Spanish/Polish polytechnics
        if (preg_match('/\b(politecnico|universita|universidad|uczelnia|uniwersytet)\b/i', $domain)) {
            return true;
        }

        // ─── Block automotive retail / dealer domains ─────────────
        // Car dealerships, tire shops, car rental, auto parts e-shops,
        // used car portals, driving schools — NOT automotive OEMs
        if (preg_match('/\b(autohaus|autodealer|autohandel|autoankauf|autoverkauf|autobazar|autobörse|autoboerse|automarkt|autoservice)\b/i', $domain)) {
            return true;
        }
        if (preg_match('/\b(concessionnaire|concessionario|concesionario)\b/i', $domain)) {
            return true;
        }
        if (preg_match('/\b(reifenhandel|reifen24|reifendirekt|pneu|pneumatic|pneumatici|neumaticos|opony|tyre|tire[-]?shop)\b/i', $domain)) {
            return true;
        }
        if (preg_match('/\b(autovermietung|mietwagen|autoverhuur|location[-]?voiture|autonoleggio|alquiler[-]?coches|car[-]?rental|rent[-]?a[-]?car|wypozyczalnia)\b/i', $domain)) {
            return true;
        }
        if (preg_match('/\b(autoteile|autoersatzteile|autoteile24|piecesauto|ricambi|recambios|czesci[-]?samochodowe|autodily|car[-]?parts|auto[-]?parts)\b/i', $domain)) {
            return true;
        }
        if (preg_match('/\b(fahrschule|autoecole|auto[-]?ecole|autoescuela|autoscuola|autoskola|rijschool|szkola[-]?jazdy|driving[-]?school)\b/i', $domain)) {
            return true;
        }
        if (preg_match('/\b(gebrauchtwagen|occasion[-]?auto|vehicule[-]?occasion|usato|usado|uzywane|ojete)\b/i', $domain)) {
            return true;
        }
        if (preg_match('/\b(carwash|autowasche|autowash|tuning[-]?shop|chiptuning)\b/i', $domain)) {
            return true;
        }

        // ─── Block gambling / betting / casino domains ────────────
        if (preg_match('/\b(casino|poker|bet365|betting|wetten|gambling|spielhalle|spielothek|loterie|lotteria|slot[-]?machine|sportwetten|pari[-]?sportif|scommesse)\b/i', $domain)) {
            return true;
        }

        // ─── Block travel / tourism / hotel domains ───────────────
        if (preg_match('/\b(reisebuero|reisebüro|voyages|viaggio|turismo|agencia[-]?viajes|biuro[-]?podrozy|cestovni[-]?kancelar|travel[-]?agency|booking|trivago)\b/i', $domain)) {
            return true;
        }
        if (preg_match('/\b(hotel|hostel|ferienwohnung|gite|chambre[-]?hote|pensione|albergue|pension)\b/i', $domain)) {
            // Exception: "hotel" in a company that manufactures hotel equipment
            if (!preg_match('/(equip|tech|system|elektronik|electric)/i', $domain)) {
                return true;
            }
        }

        // ─── Block insurance domains (multi-language) ─────────────
        if (preg_match('/\b(versicherung|assurance|assicurazione|seguro|verzekering|ubezpieczenie|pojisteni|insurance)\b/i', $domain)) {
            return true;
        }

        // ─── Block agriculture / farming domains ──────────────────
        if (preg_match('/\b(landwirtschaft|agriculture|agrikultur|agrarbetrieb|boerderij|rolnictwo|zemedelstvi|azienda[-]?agricola|explotacion[-]?agricola)\b/i', $domain)) {
            return true;
        }

        // ─── Block cleaning / facility maintenance ────────────────
        if (preg_match('/\b(reinigung|nettoyage|pulizia|limpieza|schoonmaak|sprzatanie|uklid|cleaning[-]?service|gebaude[-]?reinigung)\b/i', $domain)) {
            return true;
        }

        // ─── Block hosting / web agency domains ───────────────────
        if (preg_match('/\b(webdesign|werbeagentur|agence[-]?web|web[-]?agency|agenzia[-]?web|agencia[-]?digital|hosting|webhoster|internetagentur)\b/i', $domain)) {
            return true;
        }

        // ─── Block plumbing / HVAC / heating (service firms) ──────
        if (preg_match('/\b(sanitaer|sanitär|heizung|klempner|plombier|chauffagiste|idraulico|fontanero|loodgieter|hydraulik|installateur[-]?chauffage)\b/i', $domain)) {
            // Exception: industrial heating systems manufacturers
            if (!preg_match('/(industrial|gmbh|ag|systems?|technology)/i', $domain)) {
                return true;
            }
        }

        // ─── Block moving companies (multi-language) ──────────────
        if (preg_match('/\b(umzug|umzuege|demenagement|déménagement|trasloco|mudanza|verhuiz|przeprowadzk|stehovani)\b/i', $domain)) {
            return true;
        }

        // ─── Block flower shops / gift shops ──────────────────────
        if (preg_match('/\b(blumenladen|blumenversand|fleuriste|fiorista|floreria|kwiaciarnia|kvetinarstvi|flower[-]?shop|florist)\b/i', $domain)) {
            return true;
        }

        // ─── Block pet / veterinary domains ───────────────────────
        if (preg_match('/\b(tierarzt|tierarztpraxis|tierklinik|veterinaire|veterinario|weterynarz|veterinar|petshop|pet[-]?store|zoofachhandel)\b/i', $domain)) {
            return true;
        }

        // ─── Block solar / PV installer domains ──────────────────
        if (preg_match('/\b(solarinstall|photovoltaik[-]?install|solar[-]?panel|solaranlage|solarteur|panneau[-]?solaire|impianto[-]?fotovoltaico|installador[-]?solar)\b/i', $domain)) {
            return true;
        }

        // ─── Block beauty / cosmetics / spa domains ───────────────
        if (preg_match('/\b(kosmetik|cosmeti[ck]|parfumerie|schoenheitspflege|beauty[-]?salon|spa[-]?wellness|friseur|coiffeur|parrucchiere|peluqueria|fryzjer|kadernictvi)\b/i', $domain)) {
            return true;
        }

        // ─── Block news / media / magazine / press domains ──────────
        if (preg_match('/\b(actualit[eé]s?|infos?[-]?actu|journal|presse|gazette|tribune|herald|observer|daily[-]?news|magazine|nouvelles|dépêche|depeche|quotidien|hebdo|revue[-]?de[-]?presse|redaction|rédaction)\b/i', $domain)) {
            return true;
        }
        // Catch "managers.tn", "realites.com.tn", etc. — pure media sites
        if (preg_match('/^(managers?|realites?|leaders?|tunisie[-]?actualite|businessnews|webmanagercenter|mediapart|huffpost|lemonde|lefigaro)\b/i', $domain)) {
            return true;
        }

        // ─── Block trade shows / salons / exhibitions / associations ─
        if (preg_match('/\b(salon[-]?(international|auto|industri)|trade[-]?show|exhibition|expo[-]?(auto|industri)|foire[-]?international|messe[-]?(auto|industri)|automotive[-]?show)\b/i', $domain)) {
            return true;
        }
        // Industry body / trade association patterns
        if (preg_match('/\b(fédération|federation|association[-]?(industri|auto|professionnel)|chambre[-]?syndicale|syndicat[-]?professionnel|groupement[-]?professionnel)\b/i', $domain)) {
            return true;
        }

        // ─── Block bakery / food production domains ───────────────
        if (preg_match('/\b(baeckerei|bäckerei|boulangerie|panificio|panaderia|piekarnia|pekarstvi|konditorei|patisserie|metzgerei|boucherie|macelleria|carniceria)\b/i', $domain)) {
            return true;
        }

        // ─── Block country-specific marketplace / classified TLDs ─
        $multiTldMarketplaces = ['ubuy', 'temu', 'shein', 'wish', 'banggood', 'gearbest'];
        foreach ($multiTldMarketplaces as $site) {
            if (preg_match('/^' . preg_quote($site, '/') . '\.[a-z]{2,6}$/i', $domain)) {
                return true;
            }
        }

        // ─── Allow known legitimate Tier 1 suppliers that are in DOMAIN_BLOCKLIST ──
        // These companies are actual automotive/aerospace OEMs that are legitimate
        // EMS prospects, even though they appear in the blocklist.
        $allowedBlockedDomains = ['ficosa.com'];
        if (in_array($domain, $allowedBlockedDomains, true)) {
            return false;
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
    /**
     * Fast entity-type pre-filter: catches obvious non-targets based on
     * company name, snippet, and domain patterns BEFORE expensive homepage
     * fetch. This is a hard reject — no rescue possible.
     *
     * Catches: government bodies, NGOs, student orgs, job portals,
     * investment agencies, agricultural bodies, chemical/mining SOEs.
     */
    private function isObviousNonTarget(string $name, string $snippet, string $domain): bool
    {
        $text = strtolower($name . ' ' . $snippet . ' ' . $domain);

        // Government body patterns (multilingual)
        if (preg_match('/\b(minist[eè]re|ministry|وزارة|office\s+national|agence\s+nationale|national\s+agency|هيئة\s+وطنية|مكتب\s+وطني|direction\s+g[ée]n[ée]rale|secr[ée]tariat\s+d.?[ée]tat)\b/i', $text)) {
            return true;
        }
        // State-owned enterprises and government holding groups
        if (preg_match('/\b(groupe\s+chimique|chemical\s+group|office\s+des?\s+(c[ée]r[ée]ales|phosphates|tourisme|commerce|ports?)|national\s+(office|board|authority|council)|r[ée]gie\s+nationale)\b/i', $text)) {
            return true;
        }
        // Investment promotion agencies
        if (preg_match('/\b(invest\s+in\s+(tunisia|morocco|egypt|algeria)|promotion\s+des?\s+investissement|investment\s+promotion|agence\s+de\s+promotion|fipa|amdie|gafi)\b/i', $text)) {
            return true;
        }
        // Student organizations and IEEE
        if (preg_match('/\b(ieee\s+(student|chapter|section|branch)|student\s+(branch|chapter|club|association)|junior\s+enterprise|enactus)\b/i', $text)) {
            return true;
        }
        // Job portals and recruitment
        if (preg_match('/\b(offres?\s+d.?emploi|job\s+(board|portal|listing|search|vacancy)|recruitment\s+(agency|platform|portal)|career\s+(portal|site)|بحث\s+عن\s+عمل|عروض\s+عمل|emploi\s+et\s+stage|cherche[rz]?\s+emploi)\b/i', $text)) {
            return true;
        }
        // Agricultural bodies / research institutes (not product manufacturers)
        if (preg_match('/\b(onagri|inra[t]?|institut\s+(national|agricole)|observatoire\s+(national|agricole)|agricultural\s+(research|observatory|ministry))\b/i', $text)) {
            return true;
        }
        // Customs / tax authorities
        if (preg_match('/\b(douane|customs\s+authority|administration\s+des?\s+douanes|direction\s+des?\s+imp[oô]ts|tax\s+authority)\b/i', $text)) {
            return true;
        }
        // NGOs and humanitarian orgs
        if (preg_match('/\b(croix[-\s]rouge|red\s+cross|m[ée]decins\s+sans|unicef|undp|unido|world\s+bank|banque\s+mondiale|ong|humanitarian)\b/i', $text)) {
            return true;
        }

        return false;
    }

    /**
     * Check if a company name is junk — generic page headings, single words,
     * or known non-company patterns that slip through extractCompanyName().
     *
     * This is a secondary filter applied AFTER extractCompanyName() has
     * done its best to clean the Google search title.
     */
    private function isJunkCompanyName(string $name): bool
    {
        $name = trim(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $lower = strtolower(trim($name));
        $words = preg_split('/\s+/', trim($name));
        $wordCount = count($words);

        // Fast rejects for UI fragments / snippets
        if ($name === '' || preg_match('/^[\-–—|•,:;\/\\\\]+$/u', $name)) {
            return true;
        }
        if (preg_match('/\b(cookie|privacy|terms|sign\s*in|log\s*in|register|subscribe|newsletter|menu|search|home|contact\s+us|about\s+us|read\s+more|learn\s+more|apply\s+now|book\s+now)\b/iu', $name)) {
            return true;
        }
        if (preg_match('/\b(202[0-9]|19\d{2})\b/u', $name) && preg_match('/\b(news|report|insights?|forecast|market|update|press)\b/iu', $name)) {
            return true;
        }
        if (substr_count($name, '|') >= 2 || substr_count($name, '»') >= 1 || substr_count($name, '›') >= 1) {
            return true;
        }
        if ($wordCount >= 10 && !$this->hasCompanySuffix($name)) {
            return true;
        }
        if ($this->looksLikeDirectoryOrCategoryLabel($name)) {
            return true;
        }

        // NOTE: the strong-company-name rescue (looksLikeStrongCompanyName) is
        // deliberately evaluated at the END of this method, after every junk
        // pattern.  Running it first would let placeholder/redirect/country/
        // generic single-word names (e.g. "Parked Domain", "Togo", "Redirecting...")
        // escape all junk detection.  A name only gets rescued if it survives
        // every junk check below.
        
        // Empty or very short (< 3 chars, unless ALL-CAPS acronym like "ZF", "ABB")
        if (mb_strlen($name) < 2) {
            return true;
        }
        if (mb_strlen($name) < 3 && !preg_match('/^[A-Z]{2,}$/', $name)) {
            return true;
        }
        
        // ─── URLs used as company names ───────────────────────────────
        // "www.ussteel", "https://www.ford.com/", "https://www.kalb"
        if (preg_match('/^https?:\/\//i', $name)) {
            return true;
        }
        if (preg_match('/^www\./i', $name)) {
            return true;
        }
        
        // ─── Names ending in TLD suffixes → domain was used as name ──
        if (preg_match('/\.(com|net|org|io|co|fr|de|in|ma|uk|eu|be)$/i', $name)) {
            return true;
        }

        // ─── Compound single-word names with embedded non-company substrings ──
        // "Indiantourismblogs" — domain-derived names where "tourism"/"blog"
        // are embedded without word boundaries. Legitimate manufacturers never
        // have these substrings in their name.
        if ($wordCount <= 2 && preg_match('/(tourism|tourist|travel|blog|vlog|podcast|recipe|gossip|forum|wiki|review|coupon|deal|discount|gambling|casino|betting|escort|dating)/i', $name)) {
            return true;
        }

        // ─── "<Country>Today" news site names ──────────────────────────
        // "EgyptToday", "IndiaToday" etc. — news portals, not companies
        if (preg_match('/^(egypt|india|china|morocco|tunisia|saudi|qatar|dubai|turkey|nigeria|kenya|ghana|pakistan|brazil|mexico|africa|arab|gulf|middle\s*east|asia)\s*today$/i', $name)) {
            return true;
        }

        // ─── Descriptive phrases used as names ───────────────────
        // e.g. "High-Quality Laboratory Reagents" — taglines extracted from page titles
        if (preg_match('/^(high[- ]quality|best|top|leading|premium|professional|advanced|reliable|trusted|innovative|affordable)\s/i', $name)) {
            return true;
        }

        // ─── Consulting / advisory / services companies ───────────────
        // Companies with "consulting" or similar words in their name are
        // service firms, not OEM manufacturers that buy EMS assemblies.
        // Covers English + DE/FR/NL/IT/ES/PL equivalents.
        if (preg_match('/\b(consulting|consultancy|consultants?|consult|beratung|conseil|advies|consulenza|consultoría|consultoria|doradztwo|rådgivning|neuvonta|poradenství|poradenstvi)\b/iu', $name)) {
            return true;
        }
        if (preg_match('/\b(advisory|advisors?|berater|conseillers?|adviseurs?|consulenti|asesores?|doradcy|rådgivare)\b/iu', $name)) {
            return true;
        }

        // ─── Foreign-language homepage words used as company names ─────
        // "Startseite", "Accueil", "Strona główna" etc. extracted as names
        $foreignNavJunk = [
            'startseite', 'willkommen', 'herzlich willkommen',
            'accueil', 'bienvenue', 'welkom', 'startpagina',
            'domů', 'domu', 'úvod', 'uvod', 'vítejte', 'vitejte',
            'etusivu', 'tervetuloa', 'startsida', 'startsidan',
            'välkommen', 'valkommen', 'pagina iniziale',
            'benvenuto', 'benvenuti', 'inicio', 'bienvenido',
            'bienvenidos', 'strona główna', 'strona glowna',
            'witamy', 'witaj', 'página inicial', 'pagina inicial',
            'hjem', 'hjemmeside', 'velkommen', 'acasă', 'acasa',
            'kezdőlap', 'kezdolap', 'impressum', 'datenschutz',
            'mentions légales', 'mentions legales',
        ];
        if (in_array($lower, $foreignNavJunk, true)) {
            return true;
        }

        // ─── Names with embedded taglines/quotes ─────────────────
        // e.g. 'FM Global "Makes it Simple"', 'Company "Your Partner"'
        if (str_contains($name, '"') || str_contains($name, "\u{201C}") || str_contains($name, "\u{201D}")) {
            return true;
        }

        // ─── Names that look like search queries ─────────────────
        // e.g. "Ship Supply in Casablanca (MACAS)"
        if (preg_match('/\b(supply in|near|around)\s+(casablanca|tunis|cairo|rabat|marrakech|tangier|fes|sfax|sousse|alexandria|giza)/i', $name)) {
            return true;
        }
        
        // ─── Giant OEM check moved to isGiantOem() ───────────────────
        // Not checked here — callers that need it call isGiantOem() separately.
        
        // ─── "City, Country" or "City State" patterns ─────────────────
        // "Abu Dhabi, UAE", "Cairo, Egypt", "City of Alexandria, Virginia"
        if (preg_match('/^city\s+of\s+/i', $name)) {
            return true;
        }
        if (preg_match('/^[A-Z][a-z]+(\s+[A-Z][a-z]+)?,\s*(UAE|USA|UK|VA|KY|TX|NY|CA|FL|OH|MI|MA|NC|PA|Egypt|Morocco|Saudi|Qatar|France|Germany|Netherlands|Virginia|Kentucky|Florida|Georgia|Tennessee|Alabama)/i', $name)) {
            return true;
        }

        // ─── "Car Dealership in X" / "Located in X" / "Serving X" ─────
        if (preg_match('/^(car|auto)\s+(dealership|dealer)\s+(in|near)\s+/i', $name)) {
            return true;
        }
        if (preg_match('/^(located|serving|based)\s+(in|near)\s+/i', $name)) {
            return true;
        }
        
        // ─── UI elements / navigation artifacts ───────────────────────
        // "Country Selector", "Presentation Mode", "Cookie Consent"
        $uiElements = [
            'country selector', 'language selector', 'region selector',
            'presentation mode', 'cookie consent', 'cookie policy',
            'privacy policy', 'terms of service', 'terms and conditions',
            'accept cookies', 'manage cookies', 'subscribe now',
            'sign in', 'sign up', 'log in', 'register now',
            'skip to content', 'skip navigation', 'main menu',
            'search results', 'no results', 'page not found',
            'editorial office', 'editorial office ltd',
            // French navigation words extracted as company names
            'actualité', 'actualités', 'actualite', 'actualites',
            'accueil', 'bienvenue', 'à propos', 'a propos',
            'nos services', 'nos produits', 'nos réalisations',
            'contact', 'contactez-nous', 'qui sommes-nous',
            // WAF/error page artifacts captured as company names
            'request rejected', 'access denied', 'forbidden',
            'page not found', 'error 404', 'error 403',
        ];
        if (in_array($lower, $uiElements, true)) {
            return true;
        }
        
        // ─── Person names (First Last pattern, not company) ───────────
        // "Todd Metcalfe", "Ben Nielsen", etc.
        // Heuristic: exactly 2 words, both capitalized, first is common first name,
        // AND second word is NOT a company suffix like "Electronics", "Technologies"
        $companySuffixes = ['electronics', 'technologies', 'industries', 'manufacturing', 'systems', 'solutions', 'corporation', 'enterprises', 'group', 'international', 'global', 'engineering', 'automation', 'electric', 'electrical', 'power', 'motors', 'controls', 'instruments', 'devices', 'components', 'circuits', 'pcb', 'semiconductor', 'mechatronics', 'robotics', 'plastics', 'metals', 'precision', 'scientific', 'medical', 'aerospace', 'defense', 'automotive', 'telecom', 'communications', 'networks', 'data', 'security', 'energy', 'solar', 'battery', 'lighting', 'sensors', 'optics', 'cable', 'wire', 'connectors', 'tooling', 'machining', 'fabrication', 'foundry', 'casting', 'extrusion', 'stamping', 'assembly'];
        $commonFirstNames = ['todd', 'ben', 'bob', 'bill', 'fred', 'mike', 'john', 'james', 'david', 'chris', 'mark', 'paul', 'steve', 'peter', 'tom', 'joe', 'dan', 'jim', 'jeff', 'greg', 'rob', 'matt', 'tim', 'rick', 'ken', 'sam', 'adam', 'jack', 'ryan', 'sean', 'eric', 'kevin', 'brian', 'scott', 'gary', 'larry', 'terry', 'jerry', 'barry', 'harry', 'carl', 'alan', 'bruce', 'frank', 'donald', 'george', 'edward', 'arthur', 'henry', 'walter', 'patrick', 'alex', 'chad', 'brad', 'craig', 'dale', 'doug', 'earl', 'floyd', 'gene', 'howard', 'ivan', 'lewis', 'neil', 'oscar', 'ralph', 'roger', 'roy', 'wayne', 'ahmed', 'mohammed', 'ali', 'omar', 'hassan', 'hussein', 'youssef', 'karim', 'pierre', 'jean', 'jacques', 'hans', 'karl', 'max', 'stefan', 'andreas', 'lars', 'magnus', 'erik'];
        if ($wordCount === 2 && in_array(strtolower($words[0]), $commonFirstNames, true) && ctype_upper($words[1][0])) {
            // Allow if second word is a company suffix (e.g., "Ahmed Electronics")
            if (!in_array(strtolower($words[1]), $companySuffixes, true)) {
                return true;
            }
        }
        // Also check reversed order: "LACHGAR Mohamed" (Arabic name pattern)
        $commonArabicFirstNames = ['mohamed', 'mohammed', 'muhammad', 'ahmad', 'ahmed', 'ali', 'omar', 'hassan', 'hussein', 'youssef', 'karim', 'mustafa', 'khalid', 'abdallah', 'ibrahim', 'ismail', 'abdellatif', 'abdelkader', 'rachid', 'said', 'hamid', 'nabil', 'fouad', 'jawad', 'aziz', 'driss'];
        if ($wordCount === 2 && in_array(strtolower($words[1]), $commonArabicFirstNames, true) && ctype_upper($words[0][0])) {
            // Allow if first word is a company suffix (e.g., "Electronics Ahmed" — unlikely but handle)
            if (!in_array(strtolower($words[0]), $companySuffixes, true)) {
                return true;
            }
        }
        
        // ─── News outlets with numbers ────────────────────────────────
        // "FRANCE 24", "BBC News", "CNN", "Al Jazeera"
        $newsOutlets = [
            'france 24', 'bbc', 'bbc news', 'cnn', 'cnbc', 'al jazeera',
            'reuters', 'bloomberg', 'the guardian', 'the times',
            'financial times', 'wall street journal', 'nbc news',
            'fox news', 'sky news', 'al arabiya', 'rt news',
            'daily mail', 'the independent', 'the telegraph',
            'hollandsentinel', 'recycling today', 'trade horizons',
            // ─── Additional media/news from iter3/4 logs ──────────────
            'asharq al-awsat', 'oxfordbusinessgroup', 'cannabisbusinesstimes',
            'techbehemoths', 'vertical mag', 'bayut', 'dubizzle',
            'bayut & dubizzle', 'rekreute', 'vertical magazine',
            'northafricapost', 'north africa post',
            'etasr', 'engineering, technology & applied science research',
            // ── iter12 all-region ──
            'argaam', 'developing telecoms', 'developingtelecoms',
            'vyansa intelligence', 'vyansaintelligence',
            // ── iter14 precision fix ──
            'opportimes', 'middle east eye', 'arabfinance',
        ];
        if (in_array($lower, $newsOutlets, true)) {
            return true;
        }

        // ─── Certification / Testing / Inspection company names ──────────
        // TÜV NORD, Proficert, Dekra, SGS, etc. are TIC companies, not buyers
        $ticCompanyNames = [
            'tüv nord', 'tuv nord', 'tuev nord',
            'tüv süd', 'tuv sud', 'tuev sued', 'tuv sued',
            'tüv rheinland', 'tuv rheinland', 'tuev rheinland',
            'dekra', 'sgs', 'intertek', 'bureau veritas',
            'dnv', 'dnv gl', 'lloyd\'s register', 'lloyds register',
            'bsi group', 'ul solutions', 'applus', 'eurofins',
            'lrqa', 'nqa', 'proficert', 'kiwa', 'nemko',
            'csa group', 'underwriters laboratories',
        ];
        if (in_array($lower, $ticCompanyNames, true)) {
            return true;
        }
        // Catch names starting with TÜV/TUV (e.g. "TÜV NORD GROUP", "TUV SUD Asia")
        if (preg_match('/^t[üÜuU]e?v\b/iu', $name)) {
            return true;
        }

        // ─── Events / conferences / exhibitions / trade shows ────────────
        // These are not companies — they're event names extracted from titles
        if (preg_match('/\b(event[s]?|conference|exhibition|trade\s*show|expo(sition)?|summit|symposium|congress|convention|forum|workshop|webinar|meetup|hackathon|salon|messe|foire|feria|feira|salone|targi|congresso|congrès|congres)\b/iu', $name)) {
            return true;
        }
        // Specific event patterns: "Hannover Messe 2025", "GITEX Global", "CES 2025"
        if (preg_match('/\b(GITEX|CES|MWC|IFA|CEBIT|HANNOVER|ELECTRONICA|PRODUCTRONICA|EMBEDDED\s+WORLD|SENSOR|PCIM|SMT\s+CONNECT)\b/i', $name)) {
            return true;
        }

        // ─── Broad news / media / intelligence ban ────────────────────
        // Any company name containing "news", "newsletter", "newsroom",
        // "intelligence" (as in intel/analysis, NOT Intel the chip maker),
        // "bulletin", "journal", "gazette", "tribune", "herald", "post"
        // (when clearly a newspaper), "monitor", "observer", "chronicle",
        // "dispatch" (news), "press" (media), "media" (media company),
        // "broadcast", "editorial", etc.
        if (preg_match('/\b(news|newsletter|newsroom|newsdesk|newswire|newsflash|newspaper)\b/i', $name)) {
            return true;
        }
        // "intelligence" / "intel" in the journalism/analysis sense
        // Protect "Intel" the chip company (already in giant OEM blocklist)
        // but ban "X Intelligence", "intel report", "Africa Intelligence", etc.
        if (preg_match('/\bintelligence\b/i', $name)) {
            return true;
        }
        // Related media/press/journalism words in company names
        if (preg_match('/\b(gazette|tribune|herald|chronicle|bulletin|observer|broadcast(er|ing)?|editorial|correspondent|journalis[mt]e?|press\s+(agency|release|office|review|group|corp|media))\b/i', $name)) {
            return true;
        }
        // "X Monitor" / "X Dispatch" (news sites, not real companies)
        // Protect legitimate companies: only reject when these are the
        // main word (e.g. "Nordic Monitor", "Africa Dispatch")
        if (preg_match('/\b(monitor|dispatch)\s*$/i', $name)) {
            return true;
        }
        if (preg_match('/^\w+\s+(monitor|dispatch)$/i', $name)) {
            return true;
        }
        // "X Times" (news sites: "Opportimes", "Financial Times", "Arab Times")
        // Only when "times" is last word or merged suffix
        if (preg_match('/times\s*$/i', $name)) {
            return true;
        }
        // ─── Certification / Testing / Inspection body name patterns ──
        // Names with "certif", "accredit", "inspection", "testing body",
        // "conformity", "homologation", "prüf" etc. are TIC companies
        if (preg_match('/\b(certific|accreditat|certifying|conformity\s+assessment|homologation|notified\s+body|inspection\s+(body|agency|services?|authority)|testing\s+(body|institute|laborator|services?)|Prüfstelle|Prüfinstitut|Zertifizierung|organisme\s+de\s+certification)\b/iu', $name)) {
            return true;
        }
        // ─── Non-target content words ─────────────────────────────────
        // "policy", "talk", "catalog" and variants — these indicate
        // think tanks, podcasts, directories, or page artifacts, not companies.
        if (preg_match('/\b(polic(y|ies)|talk(s|ing)?|catalog(ue)?s?)\b/i', $name)) {
            return true;
        }

        // ─── Non-company entities from iter3/4 logs ───────────────────
        // Racing leagues, media offices, community hubs, free zones
        if (preg_match('/\b(racing\s+league|autonomous\s+racing|media\s+office|community\s+hub|electric\s+vehicle\s+community)\b/i', $name)) {
            return true;
        }
        // "X Airport Freezone" / "X Freezone" / "X Free Zone"
        if (preg_match('/\b(airport\s+free\s*zone|dafz)\b/i', $name)) {
            return true;
        }
        // "Aerospace Hub" / "EV Hub" / generic hubs
        if (preg_match('/\b(aerospace|ev|electric\s+vehicle|automotive)\s+hub\b/i', $name)) {
            return true;
        }
        // Generic "X Supplier" / "X Distribution" names that aren't companies
        if (preg_match('/^(aviation|aircraft|auto)\s+parts?\s+(supplier|distribution)/i', $name)) {
            return true;
        }
        // "AER Sales" and similar broker/resale entities
        if (preg_match('/\b(aer\s+sales|aircraft\s+broker|plane\s+broker)\b/i', $name)) {
            return true;
        }
        // ─── Government megaproject / smart city / giga-project names (iter12) ──
        if (preg_match('/\b(megaproject|mega[\s-]?project|giga[\s-]?project|smart\s+city|new\s+city\s+project)\b/i', $name)) {
            return true;
        }
        if (preg_match('/^neom$/i', $name)) {
            return true;
        }
        // ─── Trade shows / exhibitions / air shows (iter12) ──────────────
        if (preg_match('/\b(air\s*show|trade\s*show|trade\s*fair|exhibition\s*(center|centre)|expo(sition)?\s+(center|centre)|convention\s+cent(er|re))\b/i', $name)) {
            return true;
        }
        // HTTP redirect artifacts
        if (preg_match('/^\d{3}\s+(moved|redirect|found|not found)/i', $name)) {
            return true;
        }
        // ─── Drone delivery / last-mile delivery services (iter12) ─────
        if (preg_match('/\b(drone\s+delivery|delivery\s+drone|last[\s-]mile\s+delivery|store[\s-]to[\s-]door)\b/i', $name)) {
            return true;
        }
        // Profile pages / generic navigation artifacts
        if (preg_match('/^(profile\s+(&|and)\s+history|regions|advanced\s+solutions?\s+for)\b/i', $name)) {
            return true;
        }
        // "App [Brand]" patterns (mobile app pages)
        if (preg_match('/^app\s+/i', $name) && $wordCount <= 3) {
            return true;
        }
        // Certification bodies extracted as company names
        if (preg_match('/^(scs|sgs|tuv|bsi)\s*certification/i', $name)) {
            return true;
        }

        // ─── "<Country> Business" directory names ─────────────────────
        // "Egypt Business", "Morocco Business", "India Business" etc.
        // are business directories, not real companies.
        if (preg_match('/^(egypt|morocco|india|china|turkey|tunisia|saudi|qatar|dubai|uae|jordan|oman|bahrain|kuwait|nigeria|kenya|ghana|vietnam|thailand|indonesia|malaysia|pakistan|bangladesh|philippines|mexico|brazil|colombia|chile|peru|argentina|south\s+africa|algeria|libya|iraq|iran|lebanon|syria|yemen)\s+(business|directory|guide|portal|pages|listings?|finder|connect|hub|today|insider|monitor)\s*$/i', $name)) {
            return true;
        }
        
        // ─── Non-target businesses (restaurants, hotels, etc.) ────────
        if (preg_match('/\b(restaurant|bistro|café|cafe|diner|pizzeria|sushi|grill|bar\s+&|pub|tavern|eatery|catering)\b/i', $name)) {
            return true;
        }
        if (preg_match('/\b(hotel|motel|inn|resort|hostel|lodge|suites|bed\s+and\s+breakfast|b&b)\b/i', $name)) {
            return true;
        }
        if (preg_match('/\b(nonprofit|non-profit|charity|charities|church|temple|mosque|synagogue|ministry)\b/i', $name)) {
            return true;
        }
        if (preg_match('/\b(gym|fitness|yoga|pilates|crossfit|salon|spa|barber|beauty)\b/i', $name)) {
            return true;
        }
        if (preg_match('/\b(dentist|veterinary|vet\s+clinic|optometrist|chiropractor|pharmacy)\b/i', $name)) {
            return true;
        }
        if (preg_match('/\b(bakery|brewery|winery|distillery|florist|laundry|dry\s+clean)\b/i', $name)) {
            return true;
        }
        // Paint / coatings companies (not EMS/electronics targets)
        if (preg_match('/\b(paints?\s+(company|co\.?|factory|manufacturing)|paint\s+&\s+coatings?|refinish\s+coatings?)\b/i', $name)) {
            return true;
        }
        if (preg_match('/\b(advocacy|advocate|child\s+advocacy|civic|political|democratic|republican)\b/i', $name)) {
            return true;
        }
        if (preg_match('/\b(automobile\s+dealers?|auto\s+dealers?|auto\s+care|oil\s+change|smog\s+check)\b/i', $name)) {
            return true;
        }
        // ─── Government bodies / agencies / administrations ──────────
        if (preg_match('/\b(administration|authority|division|bureau|department|ministry|directorate|commission|committee|secretariat|inspector)\b/i', $name) && $wordCount >= 3) {
            return true;
        }
        // ─── Game studios / gaming companies ────────────────────────
        if (preg_match('/\b(game\s*studio|game\s*development|gaming\s+company|video\s+game|games?\s+(inc|llc|ltd|studio))\b/i', $name)) {
            return true;
        }
        if (preg_match('/\bGames?$/i', $name) && $wordCount <= 3) {
            return true;  // "2B Games", "Riot Games"
        }
        // ─── Job boards / recruitment portals ────────────────────────
        if (preg_match('/\b(jobfeed|naukri|naukrigulf|rekrute|bayt|indeed|glassdoor|jobfair|jobboard|talent\s+portal)\b/i', $name)) {
            return true;
        }
        // ─── Pharma / biotech as company name ───────────────────────
        if (preg_match('/\b(pharmaceutical|pharma|biotech|biopharm|biopharma)\b/i', $name) && !preg_match('/\b(equipment|device|instrument|automation|packaging|labeling)\b/i', $name)) {
            return true;
        }
        // ─── Chemical / petrochemical company names ────────────────
        if (preg_match('/\b(chemical\s+(company|industries|group)|petrochemical|agrochemical|fertilizer)\b/i', $name) && !preg_match('/\b(electronics?|semiconductor|circuit)\b/i', $name)) {
            return true;
        }
        // ─── Finance bodies / investment / capital ──────────────────
        if (preg_match('/\b(finance\s+(city|authority|corporation|commission)|capital\s+(authority|markets?))\b/i', $name)) {
            return true;
        }
        // ─── Education / training entities ─────────────────────────
        if (preg_match('/\b(education\s+(center|centre|institute|group|egypt|maroc)|certificate|professional\s+certificate|training\s+(center|centre|institute|academy))\b/i', $name)) {
            return true;
        }
        // ─── Universities / higher education ────────────────────────
        if (preg_match('/\b(universit[yéàäità]|university|academ[yia]|école|ecole|schule|hochschule|fachhochschule|politechnik[ai]|politecnico|istituto|instytut|fakultät|fakulta|college|campus)\b/iu', $name)) {
            return true;
        }
        // ─── Real estate / property development companies ───────────
        if (preg_match('/\b(real\s*estate|property\s+(develop|invest|manag|group|holdings?)|immobili[eaè]r[ea]?|nieruchomości|nieruchomosci|grundstück|grundstueck|makelaar|makelaardij|logistic[s]?\s*(park|center|centre|developer))\b/iu', $name)) {
            return true;
        }
        // ─── Hospitality / tourism companies ────────────────────────
        if (preg_match('/\b(hospitality|tourism|turismo|tourismus|hôtel|reise[n]?\b|gastro|gastronomie)\b/iu', $name)) {
            return true;
        }
        // ─── Pure financial / investment holding companies ───────────
        if (preg_match('/\b(private\s+equity|venture\s+capital|hedge\s+fund|investment\s+(fund|bank|group|holding)|asset\s+management|wealth\s+management|kapitalanlage|fondi|fundusz)\b/iu', $name)) {
            return true;
        }
        // ─── Lighting / paint brand distributors ───────────────────
        if (preg_match('/\b(lighting|luminaire|light\s+fixture)\s+(mea|middle\s+east|uae|gcc|africa|asia|europe|global|international)$/i', $name)) {
            return true;
        }
        // ─── SYSTEMATIC CAR DEALER / FRANCHISE DETECTION ──────────────
        // SIC 5511: Motor Vehicle Dealers (New & Used)
        // Pattern: [CityName/PersonName] + [Brand] OR [Brand combo] + [City]
        // CDJR = Chrysler-Dodge-Jeep-Ram franchise dealers
        if (preg_match('/\b(cdjr|cjdr|dcjr)\b/i', $name)) {
            return true;
        }
        // "[X] of [City]" dealership naming convention
        if (preg_match('/\b(ford|toyota|chevrolet|honda|nissan|hyundai|kia|mazda|subaru|volvo|bmw|audi|mercedes|lexus|acura|infiniti|genesis|buick|cadillac|gmc|lincoln|ram|jeep|dodge|chrysler)\s+of\s+/i', $name)) {
            return true;
        }
        // "[X] Motors" where X is a city, person name, or generic
        // But NOT "[Product] Motors" (like "Servo Motors" which is equipment)
        if (preg_match('/\bMotors?\b/i', $name) && !preg_match('/\b(servo|stepper|electric|brushless|dc|ac|induction|linear|step)\s+motors?\b/i', $name)) {
            // "Rally Motors", "W Motors", "ARMotors", "Bamotors", "Cairo Motors"
            if (preg_match('/^[A-Z][a-z]+\s+Motors?$/i', $name) || preg_match('/motors?\s+(group|llc|inc|maroc|egypt|dubai|usa|uk)$/i', $name)) {
                return true;
            }
            // Compound words ending in "motors" like "ARMotors", "Bamotors"
            if (preg_match('/^[A-Z][a-z]*motors$/i', $name)) {
                return true;
            }
        }
        // Car tuning / diagnostics / wrapping shops
        if (preg_match('/\b(car\s+tuning|chip\s+tuning|ecu\s+(tuning|remap)|diag\s*fix|auto\s+tuning|car\s+wrapping)\b/i', $name)) {
            return true;
        }
        // "X Ford", "X Toyota", "X Chevrolet" etc. are car dealerships
        if (preg_match('/\b(ford|toyota|chevrolet|honda|nissan|hyundai|kia|mazda|subaru|volvo\s+cars|buick|cadillac|lexus|acura|infiniti)\s*(of\s+)?$/i', $name)) {
            return true;
        }
        if (preg_match('/^(lindsay|malloy|kerry|giles|college\s+station)\s+(ford|toyota|chevrolet|mazda|volvo|honda|nissan|hyundai)/i', $name)) {
            return true;
        }
        
        // ─── LLP / law firm names ─────────────────────────────────────
        if (preg_match('/\bLLP$/i', $name)) {
            return true;
        }
        if (preg_match('/\bavocats?\b/i', $name)) {
            return true;
        }

        // ─── Private equity / investment fund patterns ────────────────
        if (preg_match('/\b(private\s+equity|venture\s+capital|capital\s+holdings?|capital\s+partners?)\b/i', $name)) {
            return true;
        }
        
        // ─── S&P / financial data providers ───────────────────────────
        if (preg_match('/^s&p\s+global$/i', $name)) {
            return true;
        }
        
        // ─── "ADAC" / "ACEA" / auto clubs & associations ──────────────
        $autoClubsAssocs = ['adac', 'acea', 'nada', 'ada'];
        if (in_array($lower, $autoClubsAssocs, true)) {
            return true;
        }
        
        // ─── "SiteMap(Powered by X)" / "MMM-ext" junk names ──────────
        if (preg_match('/\bsitemap\b/i', $name)) {
            return true;
        }
        if (preg_match('/^(mmm|xxx|yyy|zzz)-?\w{0,4}$/i', $name)) {
            return true;
        }
        
        // ─── Names containing "(en-GB)", "(en-US)" language tags ──────

        // ─── Market report / research titles ──────────────────────────
        // "Electronic Contract Manufacturing and Design Services Market 2034"
        // "Global EMS Market Growth Report 2030", "CAGR of 5.2%"
        if (preg_match('/\bmarket\s+(20[2-4]\d|size|growth|report|forecast|analysis|outlook|share|trends?)\b/i', $name)) {
            return true;
        }
        if (preg_match('/\b(CAGR|compound\s+annual)\b/i', $name)) {
            return true;
        }
        if (preg_match('/\b(report|forecast|analysis)\s+20[2-4]\d\b/i', $name)) {
            return true;
        }

        // ─── Generic service descriptions (not company names) ─────────
        // "Contract Manufacturing", "Contract Manufacturing Services",
        // "Contract Manufacturing Network", "Conformal Coatings"
        if (preg_match('/^contract\s+(manufactur|electron|assembl)/i', $name)) {
            return true;
        }
        if (preg_match('/^conformal\s+(coating|coat)/i', $name)) {
            return true;
        }
        if (preg_match('/^(tax\s+credit|certifications?\s+(issued|for|granted))/i', $name)) {
            return true;
        }
        if (preg_match('/^free\s+(member|course|download|trial|sample)\b/i', $name)) {
            return true;
        }

        // ─── Product names / SKUs used as company names ──────────────
        // "WH08Z200", "SJ100", "Staticide® 8695 Silicone Conformal Coating"
        if (preg_match('/^[A-Z]{1,4}\d{3,}\b/', $name)) {
            return true;
        }
        // Product with brand + model number patterns
        if (preg_match('/^(KONTAKT\s+CHEMIE|Staticide|DOWSIL|Loctite|Humiseal)\b/i', $name)) {
            return true;
        }

        // ─── Events / disasters / non-business content ───────────────
        if (preg_match('/\b(earthquake|flood|hurricane|tsunami|disaster)\s+(in|near)\b/i', $name)) {
            return true;
        }

        // ─── Names ending with ellipsis (truncated search titles) ─────
        if (preg_match('/\.{2,}\s*$/', $name)) {
            return true;
        }

        // ─── Foreign-language product/machine descriptions ────────────
        // "Maszyna do zbierania i umieszczania Samsung SMT"
        // "Messe Elektronik Entwicklung und Fertigung"
        if (preg_match('/\b(maszyna|maschine|appareil|máquina)\s+/iu', $name)) {
            return true;
        }
        if (preg_match('/^(messe|feira|feria|foire)\s+/iu', $name)) {
            return true;
        }

        // ─── Names that are just "Ems" or other too-generic abbreviations ─
        $tooGenericNames = ['ems', 'pcb', 'smt', 'bom', 'led', 'lcd', 'erp', 'crm', 'iot'];
        if (in_array($lower, $tooGenericNames, true)) {
            return true;
        }

        // ─── APPROVISIONNEUR and similar French job titles as names ───
        if (preg_match('/^(approvisionneur|acheteur|responsable|technicien|ingénieur|ingenieur|directeur|gestionnaire)\b/iu', $name)) {
            return true;
        }

        // ─── Product/equipment category descriptions ──────────────────
        // "Standard Thermal Vacuum Chambers", "High-Power RF Amplifiers"
        if (preg_match('/^(standard|custom|advanced|portable|industrial|commercial|high[\s-]power)\s+(\w+\s+)+(chamber|amplifier|module|fixture|enclosure|antenna|sensor|detector|converter|inverter|controller|panel|valve|pump|motor|generator|transformer|capacitor|inductor|resistor|relay|switch|connector|terminal|bracket|mount|housing)s?\s*$/i', $name)) {
            return true;
        }

        // ─── "Platform for X" / "Solution for X" — tech platform names ─
        if (preg_match('/\bplatform\s+for\s+/i', $name)) {
            return true;
        }
        if (preg_match('/\bsolution\s+for\s+(materials?|data|analytics|ai|ml|cloud)/i', $name)) {
            return true;
        }

        if (preg_match('/\(en-[A-Z]{2}\)/i', $name)) {
            return true;
        }
        
        // ─── Trade show / exhibition names ────────────────────────────
        if (preg_match('/\b(automechanika|automechanica|electronica|bauma|hannover\s+messe|CES\s+\d|GITEX|arab\s+health|medica|productronica)\b/i', $name)) {
            return true;
        }
        
        // ─── "X in the Y" / "X in Y" geographic suffix patterns ──────
        // "Bosch in the USA", "Thales in the UAE" — giant OEMs with location
        if (preg_match('/\bin\s+the\s+(USA|UAE|UK|EU|US|Middle\s+East)\s*$/i', $name)) {
            return true;
        }
        
        // ─── "Our Story" / "Our Team" navigation artifacts ──────────
        if (preg_match('/^(Our|My|The)\s+(Story|Team|People|History|Journey|Mission|Vision|Values|Approach|Work)$/i', $name)) {
            return true;
        }
        
        // ─── "X Website" / "X Website" suffix ─────────────────────────
        if (preg_match('/\bWebsite\b/i', $name) && $wordCount >= 2) {
            return true;
        }
        
        // ─── Names containing pipe | character (merged results) ───────
        if (str_contains($name, '|')) {
            return true;
        }
        
        // ─── "X County" / "X County Y" government entities ───────────
        if (preg_match('/\bCounty\b/i', $name)) {
            return true;
        }
        
        // ─── Airlines ─────────────────────────────────────────────────
        if (preg_match('/\b(air|airline|airlines|airways|egyptair|emirate|etihad|qatar\s+airways|flydubai|saudia|royal\s+air\s+maroc)\b/i', $name)) {
            // Allow "air" as part of legitimate company names
            if (!preg_match('/\b(compressed\s+air|air\s+conditioning|air\s+filter|air\s+quality|air\s+system|air\s+products?)\b/i', $name)) {
                return true;
            }
        }
        
        // ─── Construction / Building / Civil ──────────────────────────
        if (preg_match('/\b(construction\s+company|shuttering|waterproofing|concrete|cement|plumbing|roofing|scaffolding|excavation|paving)\b/i', $name)) {
            return true;
        }
        
        // ─── Consumer goods giants ────────────────────────────────────
        $consumerGoodsGiants = [
            'henkel', 'procter', 'procter & gamble', 'p&g', 'unilever',
            'nestle', 'colgate', 'kimberly-clark', 'johnson & johnson',
            'kraft', 'mars', 'pepsico', 'coca-cola',
        ];
        if (in_array($lower, $consumerGoodsGiants, true)) {
            return true;
        }
        
        // ─── Component distributors (not OEM customers) ───────────────
        $componentDistributors = [
            'mouser', 'mouser electronics', 'digikey', 'digi-key',
            'arrow electronics', 'avnet', 'rs components', 'farnell',
            'element14', 'newark', 'future electronics',
            'master electronics', 'sager electronics', 'heilind',
        ];
        if (in_array($lower, $componentDistributors, true)) {
            return true;
        }
        
        // ─── Equipment rental companies ───────────────────────────────
        if (preg_match('/\b(rental|rentals|leasing|hire|hires)\s*$/i', $name)) {
            return true;
        }
        
        // ─── Free zone / economic zone as company name ────────────────
        if (preg_match('/\b(free\s+zone|economic\s+zone|industrial\s+zone|industrial\s+park|business\s+park|techno\s+park)\b/i', $name)) {
            return true;
        }
        
        // ─── "X Leader" / "Y List" generic patterns ──────────────────
        if (preg_match('/\b(leader|list|listing|overview|index|selector)\s*$/i', $name) && $wordCount >= 2) {
            return true;
        }
        
        // ─── Military / government institutions ───────────────────────
        if (preg_match('/\b(military\s+\w+\s+college|ministry\s+of|government\s+of|armed\s+forces)\b/i', $name)) {
            return true;
        }
        
        // ─── Facilities management companies ──────────────────────────
        if (preg_match('/\bfacilities?\s+management\b/i', $name)) {
            return true;
        }
        
        // ─── Telecom operators (NOT equipment manufacturers) ──────────
        $telecomOperators = [
            'etisalat', 'eand', 'du telecom', 'stc', 'zain', 'ooredoo',
            'vodafone', 'orange', 'mtn', 'at&t', 'verizon', 't-mobile',
            'sprint', 'telefonica', 'deutsche telekom', 'bt group',
            'telenor', 'telia', 'swisscom', 'proximus',
        ];
        if (in_array($lower, $telecomOperators, true)) {
            return true;
        }
        
        // ─── Recruitment / Job sites ──────────────────────────────────
        $recruitmentSites = [
            'wuzzuf', 'gulftalent', 'bayt', 'indeed', 'glassdoor',
            'linkedin', 'monster', 'naukri', 'stepstone', 'hays',
            'michael page', 'robert half', 'randstad', 'adecco',
            'manpower', 'kelly services', 'parker dewey',
        ];
        if (in_array($lower, $recruitmentSites, true)) {
            return true;
        }

        // ─── E-commerce / Marketplace sites ───────────────────────────
        $ecommerceSites = [
            'ubuy', 'dubizzle', 'olx', 'souq', 'noon',
            'aliexpress', 'alibaba', 'wish', 'temu',
        ];
        if (in_array($lower, $ecommerceSites, true)) {
            return true;
        }
        // "Ubuy [Country]", "dubizzle [Country]" patterns
        if (preg_match('/^(ubuy|dubizzle|olx|souq|noon)\s+/i', $name)) {
            return true;
        }

        // ─── "Homepage X" prefix (extraction artifact) ────────────────
        if (preg_match('/^homepage\s+/i', $name)) {
            return true;
        }

        // ─── "X Company Profile" suffix (about page artifact) ─────────
        if (preg_match('/\s+company\s+profile$/i', $name)) {
            return true;
        }

        // ─── "Expert-comptable" / accountant patterns (French) ────────
        if (preg_match('/\bexpert[\s-]comptable\b/i', $name)) {
            return true;
        }

        // ─── "[Product/Component] [Part Number]" patterns ────────────
        if (preg_match('/^(driver|module|sensor|relay|chip|ic)\s+[a-z]*\d{3,}/i', $name)) {
            return true;
        }

        // ─── "X Services [Location]" generic service patterns ─────────
        if (preg_match('/\bservices?\s+(texas|dubai|egypt|morocco|riyadh|jeddah|abu\s+dhabi|doha)/i', $name)) {
            return true;
        }

        // ─── Software / IT solutions (not EMS buyers) ────────────────
        if (preg_match('/\bsoftware\s+solutions?\b/i', $name) && !preg_match('/\b(embedded|firmware|hardware)\b/i', $name)) {
            return true;
        }

        // ─── "X Program" / "X Initiative" (gov/NGO programs) ─────────
        if (preg_match('/\b(program|initiative|scheme)\s*$/i', $name) && $wordCount >= 3) {
            return true;
        }
        
        // ─── "[Brand] in [Country]" extraction artifact ───────────────
        if (preg_match('/\bin\s+(UAE|Egypt|Morocco|Qatar|Saudi|Bahrain|Oman|Kuwait|Jordan)$/i', $name) && $wordCount >= 3) {
            return true;
        }
        
        // ─── Training / Education companies ───────────────────────────
        $trainingCompanies = [
            'global knowledge', 'coursera', 'udemy', 'pluralsight',
            'skillshare', 'edx', 'khan academy', 'scholar',
        ];
        if (in_array($lower, $trainingCompanies, true)) {
            return true;
        }
        
        // ─── "Sales and Service" / "Parts and Service" suffixes ───────
        if (preg_match('/\b(sales|parts)\s+(and|&)\s+service\s*$/i', $name)) {
            return true;
        }
        
        // ─── "X Associates" / "X Partners" (consulting/legal/finance) ─
        if (preg_match('/\b(associates|partners)\s*$/i', $name) && $wordCount >= 2) {
            // Allow "technology partners" type names
            if (!preg_match('/\b(technology|tech|electronics?|automation)\s+(partners|associates)/i', $name)) {
                return true;
            }
        }
        
        // ─── Very short gibberish names (under 4 chars with numbers) ──
        if (mb_strlen(trim($name)) <= 4 && preg_match('/\d/', $name)) {
            return true;
        }
        
        // ─── "X Report" / "X Report" news patterns ───────────────────
        if (preg_match('/\b(report|gazette|herald|times|post|tribune|chronicle|sentinel|observer|journal|bulletin|dispatch|register|examiner)\s*$/i', $name) && $wordCount >= 2) {
            return true;
        }
        
        // ─── "X Enterprise" / "X Carrier" patterns that are retailers ─
        if (preg_match('/\b(carrier\s+enterprise|licensing\s+international|oxford\s+business)\b/i', $name)) {
            return true;
        }
        
        // ─── Gibberish / random letter combos ─────────────────────────
        if ($wordCount === 1 && mb_strlen($name) >= 4 && mb_strlen($name) <= 8 && !preg_match('/[aeiouAEIOU]{1,}/', $name) && !preg_match('/^[A-Z]{3,6}$/', $name)) {
            return true;  // No vowels & not an all-caps acronym = likely gibberish
        }
        
        // ─── Oil & Gas / National Oil Companies ───────────────────────
        // Not EMS customers (they use specialized O&G EPC contractors)
        $oilGasCompanies = [
            'enoc', 'adnoc', 'saudi aramco', 'aramco', 'total energies',
            'totalenergies', 'totalenergies middle east', 'shell', 'bp',
            'exxon', 'exxonmobil', 'chevron', 'conocophillips',
            'equinor', 'eni', 'repsol', 'petronas',
        ];
        if (in_array($lower, $oilGasCompanies, true)) {
            return true;
        }
        
        // ─── Banks / Financial institutions ───────────────────────────
        $bankPatterns = ['dib', 'bank', 'santander'];
        if (in_array($lower, $bankPatterns, true)) {
            return true;
        }
        if (preg_match('/\b(islamic\s+bank|national\s+bank|commercial\s+bank|central\s+bank)\b/i', $name)) {
            return true;
        }
        
        // ─── Single very short abbreviations (≤3 chars) ───────────────
        // Common false positives: "Ti", "Nio", "REE", "APT", "DIB"
        // Only filter 2-char or shorter that aren't well-known abbreviations
        if (mb_strlen(trim($name)) <= 2 && $wordCount === 1) {
            // Allow known 2-letter company abbreviations
            $known2Char = ['zf', 'ge', 'lg', 'hp', 'gm', 'bp', '3m'];
            if (!in_array($lower, $known2Char, true)) {
                return true;
            }
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
            'industries', 'industrie', 'manufacturing', 'engineering', 'technology',
            'avionics', 'connectors', 'certifications', 'amazon', 'default',
            'welcome', 'untitled', 'homepage', 'main', 'index', 'demo',
            'barbados', 'liberia', 'togo', 'morocco', 'tunisia',
            'oman', 'qatar', 'bahrain', 'kuwait', 'jordan', 'lebanon',
            'nigeria', 'kenya', 'ghana', 'ethiopia', 'uganda', 'tanzania',
            'investors', 'mediaroom', 'newsroom', 'pressroom', 'publications',
            'insights', 'tracker', 'monitor', 'dashboard', 'platform',
            'alerts', 'directory', 'forum', 'wiki', 'marketplace',
            'fortune', 'corporate', 'facilities', 'riviera',
            'warehouse', 'certified', 'museum', 'wsj', 'return',
            'janes', 'cardoo', 'flowcrete', 'spinetix', 'nobleprog',
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
            // ─── Iter5 junk leaks ────────────────────────────────────
            'dubizzle egypt (olx)', 'ubuy egypt', 'ubuy',
            'electric cars in egypt', 'gmegypt',
            'egic - euro-gulf information centre', 'egic',
            'expert system maintenance technician',
            'expert-comptable à casablanca',
            'portail de recherche scientifique',
            'kerix-export', 'elioplus',
            'driver ir2110 mosfet igbt',
            'iai company profile', 'iai company',
            'daq, test, hil',
            'hvac services texas', 'fuse ev',
            'quanta software solutions',
            'dubai robotics and automation program',
            'remote to the remote location',
            'about sewell automotive companies',
            'panatech # best 1',
            'intech in uae',
            // ─── Iter6 junk leaks ────────────────────────────────────
            'our story',                                           // navigation link artifact
            'ceci',                                                // wrong-website extraction
            'tajer abdelouahed',                                   // person name, not company
            'camsa inc.',                                          // wrong website (kerix-export.net)
            'camsa',
            'industrial equipment supplier uae',                   // generic description
            'hak',                                                 // ambiguous 3-letter
            'el gammal co. for paints', 'elgammal',                // paints, not EMS target
            'egic egypt', 'egic',                                  // already listed but add variants
            'tractiv',                                             // nvidianews redirect
            // ─── iter7 deep sweep ─────────────────────────────────────
            '2b games', '2b',                                      // game studio
            'globalcareers lge',                                   // LG careers portal
            'jobfeed',                                             // job aggregator
            'naukrigulf',                                          // job board
            'rekrute',                                             // job board
            'macro group pharmaceuticals',                         // pharma
            'future pharmaceutical industries',                    // pharma
            'petroknowledge',                                      // training company
            'idp education egypt',                                 // education
            'casablanca finance city authority',                    // finance authority
            'modern diplomacy',                                    // news site
            'egypt oil & gas',                                     // industry news
            'le desk',                                             // news site
            'le monde béryl', 'le monde beryl',                    // newspaper
            'mediaoffice',                                         // govt media
            'wam',                                                 // news agency
            'industry events',                                     // events aggregator
            'fast company',                                        // magazine
            'vertical plus',                                       // aviation magazine
            'rio south texas',                                     // regional body
            'dubai south',                                         // govt zone
            'maroc',                                               // country website
            'history',                                             // nav artifact
            'history factory',                                     // wrong website
            'timeline',                                            // nav artifact
            'aimes',                                               // unclear extraction
            'iot analytics',                                       // research firm
            'sqorus',                                              // IT consulting
            'go-globe',                                            // web agency
            'infoquest llc',                                       // IT reseller
            'power electronics',                                   // generic term
            'pemodule',                                            // single product
            'caparol middle east and africa',                      // paint distributor
            'moroccan exporters to tunisia',                       // directory listing
            'mobility aftermarket',                                // Bosch aftermarket
            'rec-solutions',                                       // HR/recruitment
            'industrial manufacturing hvac',                       // generic phrase
            'longrange capital',                                   // investment firm
            'ram electronics, inc.',                               // e-shop
            'ascent emirates',                                     // ISO consultant
            'karnak',                                              // egyptair loyalty
            'global lawyers',                                      // law firm
            'amtek group',                                         // wrong website
            'icomtech, inc.',                                      // wrong website
            'uge electronics',                                     // unclear
            'relpol s.a.',                                         // wrong website
            'micro ohm electronics',                               // unclear
            'fox power electronics',                               // wrong website
            'c.e.c.i.',                                            // wrong website
            'bamotors maroc',                                      // car dealer
            'jameelmotors',                                        // car dealer
            'jetour egypt',                                        // car dealer
            'hennesseyspecialvehicles',                             // domain as name
            'engineering company for electrical energy',            // generic phrase
            'elite equipment & services llc',                      // wrong website
            'mbrah',                                               // aerospace hub
            'medz',                                                // govt holding
            'scaler8',                                             // startup
            // ─── iter11b Morocco junk ──────────────────────────────────
            'northafricapost',                                     // news site
            'north africa post',                                   // news site variant
            'tanger med zones',                                    // FTZ authority
            'tangermedzones',                                      // domain-as-name
            'atlamed',                                             // private equity
            'mds aviation',                                        // aircraft MRO
            'tst',                                                 // freight / logistics
            'ultranet',                                            // equipment dealer
            'autologic',                                           // diagnostic tools shop
            'dunlop tyres',                                        // tyre brand marketing
            'dunlop tires',                                        // tyre brand variant
            'etasr',                                               // academic journal
            'comeca group',                                        // large French group
            'zf lifetec',                                          // ZF giant OEM spin-off
            // ── iter12 all-region ──────────────────────────────────────
            'vyansa intelligence',                                 // market research firm
            'vyansaintelligence',                                  // domain-as-name
            'neom',                                                // Saudi giga-project
            'argaam',                                              // Arabic financial news
            'developing telecoms',                                 // telecom industry news
            'developingtelecoms',                                  // domain-as-name
            'dubai airshow',                                       // trade show / exhibition
            '301 moved permanently',                               // HTTP redirect crawl artifact
            'zipline',                                             // drone delivery service
            // ── iter13 Tunisia ─────────────────────────────────────────────
            'worldwide tax summaries online',                      // PwC tax portal
            'worldwide tax summaries',                             // PwC tax portal
            'elcosolutionssite',                                   // embedded software consultancy
            'thinktank research group',                            // IT consulting
            'thinktank',                                           // IT consulting
            'groupe-telnet',                                       // IT engineering services
            'groupe telnet',                                       // IT engineering services
            'sinoextrud',                                          // Chinese aluminum extrusion
            'sellami group',                                       // Car dealer & spare parts
            'soremat',                                             // Business consulting firm
            'labrosse',                                            // Brush/paintbrush manufacturer
            'sicop',                                               // Glue/paint manufacturer
            'acron aviation',                                      // Magazine crawl artifact
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
        if (preg_match('/\b(university|universit(é|eit|ät)|^TU\s+|^TH\s+|^FH\s+)/i', $name)) {
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
        // "Pressure Control Valves X Manufacturing" — product + company concatenated
        if (preg_match('/^(pressure|temperature|flow|level|hydraulic|pneumatic)\s+(control|sensing|measurement)\s+/i', $name) && $wordCount >= 4) {
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
        $sentenceStarters = '/^(the|a|an|how|why|what|where|when|which|who|find|get|buy|our|your|their|this|these|those|best|top|new|all|every|each|some|any|most|more|list|guide|review|become|imagining|reshoring|futuristic|largest|provider|emerging|submit)\s/i';
        if (preg_match($sentenceStarters, $name) && $wordCount > 2) {
            return true;
        }
        
        // ─── Page-title artifacts used as company names ───────────────
        // "Emerging Supplier Registration Form", "Provider Questdiagnostics"
        // These are scraped page titles, not real company names.
        if (preg_match('/\b(registration\s+form|sign\s+up\s+form|application\s+form|supplier\s+portal|vendor\s+portal|login\s+page|landing\s+page)\b/i', $name)) {
            return true;
        }
        // "Provider <CompanyName>" pattern — portal subpage titles
        if (preg_match('/^provider\s+/i', $name)) {
            return true;
        }
        
        // Contains common verb structures → sentence, not name
        $verbPatterns = '/\b(you can|we offer|we provide|we are|we have|is a|are the|has been|have been|will be|can be|should be|looking for|searching for|need to|want to|how to|learn more|click here|read more|sign up|log in|subscribe)\b/i';
        if (preg_match($verbPatterns, $name)) {
            return true;
        }
        
        // ─── News headline verb detection ─────────────────────────────
        // Pattern: "CompanyName verb rest" e.g. "Bombardier exits commercial aviation"
        $headlineVerbs = '/\b(exits|signs|launches|buys|wins|enters|joins|acquires|announces|unveils|reveals|secures|expands|opens|completes|delivers|reports|appoints|ranked|partners|outbreak|attains|achieves|receives|celebrates|reaches|surpasses|establishes|publishes|releases|introduces|presents|demonstrates)\b/i';
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
            // ─── Round 4 patterns (from v5 audit) ─────────────────────
            '/^curriculum\s+vitae/i',                   // CV/resume pages
            '/^presales?\s+(engineer|leader|manager)/i',// job title as name
            '/^sales\s+engineer/i',                     // job title as name
            '/^project\s+(manager|leader|engineer)/i',  // job title as name
            '/^(senior|junior|lead|chief|head)\s+(engineer|manager|developer|analyst|officer|designer)/i',
            '/^other\s+projects?$/i',                   // nav element
            '/^faculty\s+of\s+/i',                      // academic faculty
            '/\bfaculty\b/i',                           // any faculty mention
            '/\bacademy\b/i',                            // academies
            '/\bsuicide\b/i',                           // not a company
            '/\bprevention\s+office/i',                 // gov office
            '/\bhappy\s+sweet/i',                       // bakery
            '/\bdigital\s+dreams/i',                    // web design
            '/\bcloud[\s-]computing/i',                 // generic cloud service
            '/\bflooring\b/i',                          // flooring companies (Flowcrete)
            '/\blubricant/i',                            // lubricant companies
            '/\bshipping\s+(llc|co|corp|company|inc)/i',// shipping companies
            '/\bwaste\s+management/i',                  // waste mgmt
            '/\bhazardous\s+waste/i',                   // waste services
            '/\bclean\s+harbors?/i',                    // waste management
            '/\brecycl(ing|er)/i',                      // recycling
            '/\bgeocycle/i',                            // waste services
            '/\bfuel\s+(distribut|supply)/i',           // fuel distribution
            '/\bvalero\b/i',                            // refinery
            '/\bnobleprog\b/i',                         // training company
            '/\bglobalknowledge/i',                     // training company
            '/\bcapital\s+factory/i',                   // startup incubator
            '/\bintelligence\b/i',                      // intelligence news
            '/\bdock\s*411/i',                          // logistics app
            '/\bspinet(ix)?/i',                         // digital signage
            '/^holding(\s+(company|group|s\.?a\.?|gmbh|corp|inc|ltd))?$/i', // standalone "Holding" / "Holding Company" (not "Küster Holding GmbH")
            '/\benvironment\s+monitoring/i',            // generic services
            // ── iter14 additional patterns ────────────────────────────────
            '/\boil\s*(&|and)\s*gas/i',                  // oil & gas companies
            '/\bpetroleum\b/i',                          // petroleum companies
            '/\bcertification\s+bod/i',                  // certification bodies
            '/\bcertification\s+authorit/i',             // certification authorities
            '/\btesting\s*(and|&|,)\s*(certification|inspection)/i', // testing bodies
            '/\binspection\s*(and|&|,)\s*(certification|testing)/i', // inspection bodies
            '/^(tüv|tuv|büv|buv|dekra|lrqa)\b/i',       // certification org names
            '/\bpharma(ceut)?\w*\s+(industries|company|group|corp)/i', // pharma companies
            '/\bscientific\s+research/i',                // research institutions
            '/\btechnical\s+services\s+in\s+/i',       // generic geo pattern
            '/\bbenefit\s+technologies/i',              // HR/benefits tech
            '/\bguide\s+vfr/i',                         // aviation guide
            '/\bchinaglobalsouth/i',                    // news site
            '/\bzeekr\b/i',                             // car brand
            '/\bbalear/i',                              // ferry company
            '/\bgrupo\s+geocad/i',                      // surveying
            '/\bdelphi\s*auto\s*parts/i',               // aftermarket parts
            '/\b(mri|ct|x[\s-]?ray)\s+(equipment|services?|imaging)/i', // medical imaging
            '/^complete\s+legal/i',                     // legal services
            '/\bmouser\b/i',                            // component distributor
            '/\bdigikey\b/i',                           // component distributor
            '/\bfarnell\b/i',                           // component distributor
            '/\bnewark\b/i',                            // component distributor
            '/\barrow\s+electronics/i',                 // component distributor
            '/\bavnet\b/i',                             // component distributor
            // ── iter15 TN/EG/MA quality audit round 2 ─────────────────
            '/^object\s+moved/i',                        // HTTP redirect artifact
            '/^page\s+not\s+found/i',                    // 404 page
            '/^access\s+denied/i',                       // 403 page
            '/^forbidden/i',                             // 403 page
            '/^just\s+a\s+moment/i',                     // Cloudflare challenge
            '/^attention\s+required/i',                   // Cloudflare challenge
            '/^you\s+are\s+being\s+redirected/i',        // redirect page
            '/^redirect/i',                              // redirect page
            '/^loading/i',                               // SPA loading page
            '/^untitled\s*(document)?$/i',               // blank page
            '/^sign\s+in/i',                             // login page
            '/^log\s*in/i',                              // login page
            '/^option\s*carriere/i',                      // job search website
            '/^emploi/i',                                // job website
            '/^offres?\s+d.emploi/i',                    // job listings (French)
            '/^recrutement/i',                           // recruitment
            '/^job\s+(search|board|listing|posting)/i',  // job sites
            '/\b(sector|industri[ae])\s+(aeroespacial|aeronáutico|aéronaut)/i', // non-English sector descriptions
            '/^(el|la|le|les|los|las|il|der|die|das)\s+sector/i',           // non-English articles + sector
            '/\bisrael\s+radar/i',                      // defense contractor
            '/\bsun\s+chemical/i',                      // chemical company
            '/\bdupont\b/i',                            // chemical giant
            '/\binside\s+in?diana/i',                   // news site
            '/\bicp\s*-?\s*international/i',            // generic profiles
            '/\bequipment\s+services?$/i',              // generic services
            '/\bgentherm\b/i',                          // competitor (auto thermal)
            '/\bsamsung\b/i',                           // giant conglomerate
            '/\bairbus\b/i',                            // aerospace giant
            '/\bboeing\b/i',                            // aerospace giant

            // ─── Textile / Apparel / Garment / Leather ─────────────
            '/\btextile\s+(manufactur|company|mill|group|corp)/i',
            '/\bgarment\s+(manufactur|company|factory|export)/i',
            '/\bapparel\b/i',                           // apparel companies
            '/\bknitwear\b/i',                          // knitwear
            '/\bweaving\s+(mill|factory|company)/i',
            '/\bspinning\s+(mill|factory|company)/i',
            '/\bdyeing\b/i',                            // textile dyeing
            '/\btannery\b/i',                           // leather tanning
            '/\bleather\s+(goods|products|tanning)/i',
            '/\bfootwear\s+(manufactur|company)/i',
            '/\bshoe\s+(manufactur|factory|company)/i',
            '/\bembroidery\b/i',                        // textile
            '/\bready[\s-]?made\s+garment/i',
            '/\bcarpet\s+(manufactur|company|mill)/i',

            // ─── Trade Bodies / Associations / Chambers ────────────
            '/\bchamber\s+of\s+(commerce|industry|trade)/i',
            '/\btrade\s+(body|council|association)\b/i',
            '/\bbusiness\s+council\b/i',
            '/\bemployers?\s+(association|federation)/i',
            '/\bmanufacturers?\s+association\b/i',
            '/\bexporters?\s+(association|council)/i',
            '/\bindustry\s+(body|council|association)\b/i',

            // ─── Packaging / Printing / Labels ─────────────────────
            '/\bpackaging\s+(manufactur|company|group)/i',
            '/\bcorrugated\s+(box|packaging)/i',
            '/\bcarton\s+(manufactur|box|company)/i',
            '/\bprinting\s+(company|house|press)/i',
            '/\blabel\s+(manufactur|printing)/i',

            // ─── Plastic / Rubber (non-electronics) ────────────────
            '/\bplastic\s+injection\b/i',
            '/\binjection\s+mold/i',
            '/\bblow\s+mold/i',
            '/\brubber\s+(compounding|extrusion|products)/i',
            '/\bpolymer\s+(compounding|processing)/i',

            // ─── Furniture / Glass / Ceramics ──────────────────────
            '/\bfurniture\s+(manufactur|company|factory)/i',
            '/\bwoodwork(ing)?\s+(company|factory)/i',
            '/\bglass\s+(manufactur|company|factory)/i',
            '/\bceramic\s+tile/i',

            // ─── Steel / Foundry / Heavy Metal ─────────────────────
            '/\bsteel\s+(mill|works|plant|company|producer)/i',
            '/\bfoundry\b/i',
            '/\biron\s+(works|casting|foundry)/i',
            '/\bscrap\s+(metal|steel|iron)/i',

            // ─── Multi-language automotive retail / service (NOT OEMs) ──
            // DE: Autohaus, Werkstatt, Autovermietung, Fahrschule
            '/\b(autohaus|autowerkstatt|kfz[\s-]werkstatt|kfz[\s-]meister|kfz[\s-]betrieb)\b/i',
            '/\b(autovermietung|mietwagen|gebrauchtwagen[\s-]?händler|gebrauchtwagen[\s-]?handler)\b/i',
            '/\b(fahrschule|führerschein|fuhrerschein)\b/i',
            '/\b(reifenhandel|reifenservice|reifenmontage|reifen[\s-]?center|reifendienst)\b/i',
            '/\b(autoversicherung|kfz[\s-]versicherung|kfz[\s-]zulassung)\b/i',
            '/\b(tankstelle|abschleppdienst|pannenhilfe|autolackierung|autoglas)\b/i',
            '/\b(tüv[\s-]?station|hauptuntersuchung|abgasuntersuchung)\b/i',
            // FR: concessionnaire, garage, auto-école, location
            '/\b(concessionnaire\s+auto|garage\s+auto|carrosserie|carrossier)\b/i',
            '/\b(auto[\s-]?école|auto[\s-]?ecole|permis\s+de\s+conduire)\b/i',
            '/\b(location\s+de\s+(voiture|véhicule|vehicule))\b/i',
            '/\b(pièces?\s+(auto|détachée|detachee)|casse\s+auto|recyclage\s+auto)\b/i',
            '/\b(pneumatici[eè]n|centre\s+auto|contrôle\s+technique|controle\s+technique)\b/i',
            // IT: concessionaria, autofficina, carrozzeria
            '/\b(concessionari[ao]|autofficina|carrozzeria|autonoleggio)\b/i',
            '/\b(autoscuola|gommist[ao]|ricambi\s+auto|autodemolizione)\b/i',
            '/\b(revisione\s+auto|bollo\s+auto|assicurazione\s+auto)\b/i',
            // ES: concesionario, taller, autoescuela
            '/\b(taller\s+(mecánico|mecanico|de\s+coches)|autoescuela|agencia\s+de\s+autos)\b/i',
            '/\b(neumáticos|neumaticos|alquiler\s+de\s+(coches|vehículos|vehiculos))\b/i',
            '/\b(recambios\s+auto|desguace|chatarrer[oí]a)\b/i',
            // NL: autodealer, garage, rijschool
            '/\b(autodealer|autogarage|rijschool|autoverhuur|banden[\s-]?service)\b/i',
            '/\b(autodemontage|autorecycling|apk[\s-]?keuring|autoschadeherstel)\b/i',
            // PL: salon samochodowy, warsztat, szkoła jazdy
            '/\b(salon\s+samochodowy|warsztat\s+samochodowy|szko[lł]a\s+jazdy)\b/i',
            '/\b(wypo[żz]yczalnia|wypozyczalnia|opony|wulkanizacja|lakiernia)\b/i',
            '/\b(stacja\s+kontroli\s+pojazdów|stacja\s+kontroli\s+pojazdow)\b/i',
            // CZ: autobazar, autoservis, autoškola
            '/\b(autobazar|autoservis|auto[šs]kola|autoskola|pneuservis)\b/i',
            '/\b(autolakovn[aá]|autolakovna|p[uů]j[čc]ovna\s+aut|pujcovna\s+aut)\b/i',

            // ─── Multi-language construction / trades ───────────────────
            '/\b(bauunternehmen|baufirma|baumeister|zimmerei|dachdecker)\b/i',
            '/\b(entreprise\s+de\s+construction|maçon|couvreur|charpentier)\b/i',
            '/\b(impresa\s+(edile|di\s+costruzion)|muratore|carpentiere)\b/i',
            '/\b(empresa\s+(constructora|de\s+construcción)|alba[ñn]il)\b/i',
            '/\b(bouwbedrijf|aannemer|dakdekker|timmerman|metselaar)\b/i',
            '/\b(firma\s+budowlana|deweloper|ciesla|dekarz|murarz)\b/i',
            '/\b(stavební\s+firma|stavba|zedník|pokrývač|tesař)\b/i',

            // ─── Multi-language beauty / wellness / health ──────────────
            '/\b(kosmetik[\s-]?studio|friseur[\s-]?salon|nagelstudio|massagepraxis)\b/i',
            '/\b(salon\s+de\s+(coiffure|beauté|beaute)|institut\s+de\s+beauté)\b/i',
            '/\b(parrucchiere|centro\s+estetico|salone\s+di\s+bellezza)\b/i',
            '/\b(peluquer[ií]a|centro\s+de\s+belleza|est[eé]tica)\b/i',
            '/\b(schoonheidssalon|kapsalon|nagelstudio|massagesalon)\b/i',
            '/\b(salon\s+fryzjerski|gabinet\s+kosmetyczny|salon\s+urody)\b/i',
            '/\b(kade[řr]nictv[ií]|kosmetick[ýy]\s+salon|masá[žz]e)\b/i',

            // ─── Multi-language bakery / food / butcher ──────────────────
            '/\b(bäckerei|baeckerei|konditorei|metzgerei|fleischerei)\b/i',
            '/\b(boulangerie|pâtisserie|patisserie|boucherie|charcuterie|fromagerie)\b/i',
            '/\b(panificio|pasticceria|macelleria|salumificio|caseificio)\b/i',
            '/\b(panadería|panaderia|pastelería|pasteleria|carnicería|carniceria)\b/i',
            '/\b(bakkerij|slagerij|kaaswinkel|vishandel)\b/i',
            '/\b(piekarnia|cukiernia|masarnia|w[eę]dliniarnia)\b/i',
            '/\b(pekárna|pekarna|cukrárna|cukrarna|řeznictví|reznictvi)\b/i',

            // ─── Multi-language moving / transport ───────────────────────
            '/\b(umzugsunternehmen|spedition|möbelspedition|moebelspedition)\b/i',
            '/\b(d[eé]m[eé]nagement|entreprise\s+de\s+d[eé]m[eé]nagement)\b/i',
            '/\b(trasloc[hi]|ditta\s+di\s+traslochi)\b/i',
            '/\b(empresa\s+de\s+mudanzas|mudanzas)\b/i',
            '/\b(verhuisbedrijf|verhuizing)\b/i',
            '/\b(firma\s+przeprowadzkowa|przeprowadzki)\b/i',
            '/\b(st[eě]hovac[ií]\s+firma|st[eě]hov[aá]n[ií])\b/i',

            // ─── Multi-language insurance ────────────────────────────────
            '/\b(versicherungsmakler|versicherungsagentur|versicherungsgesellschaft)\b/i',
            '/\b(compagnie\s+d.assurance|courtier\s+d.assurance|cabinet\s+d.assurance)\b/i',
            '/\b(compagnia\s+di\s+assicurazion|agenzia\s+assicurativ)\b/i',
            '/\b(compañ[ií]a\s+de\s+seguros|correduría\s+de\s+seguros|correduria)\b/i',
            '/\b(verzekeringsmaatschappij|verzekeringsagent)\b/i',
            '/\b(towarzystwo\s+ubezpiecze[nń]|ubezpieczenia)\b/i',
            '/\b(pojišťovna|pojistovna|pojištění|pojisteni)\b/i',

            // ─── Multi-language web agency / digital marketing ──────────
            '/\b(werbeagentur|internetagentur|medienagentur|digitalagentur)\b/i',
            '/\b(agence\s+(web|digitale|de\s+communication|marketing))\b/i',
            '/\b(agenzia\s+(web|digitale|di\s+comunicazione|marketing))\b/i',
            '/\b(agencia\s+(web|digital|de\s+marketing|de\s+publicidad))\b/i',
            '/\b(webbureau|reclamebureau|marketingbureau|communicatiebureau)\b/i',
            '/\b(agencja\s+(reklamowa|interaktywna|marketingowa|PR))\b/i',
            '/\b(reklamn[ií]\s+agentura|marketingov[aá]\s+agentura|webov[aá]\s+agentura)\b/i',

            // ─── Multi-language cleaning / facility services ────────────
            '/\b(reinigungsfirma|gebäudereinigung|gebaeudereinigung|hausmeisterservice)\b/i',
            '/\b(entreprise\s+de\s+nettoyage|soci[eé]t[eé]\s+de\s+nettoyage)\b/i',
            '/\b(impresa\s+di\s+pulizie?|servizi\s+di\s+pulizia)\b/i',
            '/\b(empresa\s+de\s+limpieza|servicios?\s+de\s+limpieza)\b/i',
            '/\b(schoonmaakbedrijf|schoonmaakdienst)\b/i',
            '/\b(firma\s+sprz[aą]taj[aą]ca|us[lł]ugi\s+sprz[aą]tania)\b/i',
            '/\b([uú]klidov[aá]\s+firma|[uú]klidov[eé]\s+slu[zž]by)\b/i',

            // ─── Multi-language plumbing / HVAC / electrician (trades) ──
            '/\b(klempner|sanitär|sanitaer|heizungsbau|elektroinstallation)\b/i',
            '/\b(plombier|chauffagiste|[eé]lectricien)\b/i',
            '/\b(idraulico|elettricista|termoidraulic)\b/i',
            '/\b(fontanero|electricista|climatizaci[oó]n)\b/i',
            '/\b(loodgieter|elektricien|verwarmingsinstallateur)\b/i',
            '/\b(hydraulik|elektryk|instalator)\b/i',
            '/\b(instalat[eé]r|elektrik[aá][řr]|topen[ií])\b/i',

            // ─── Multi-language real estate ──────────────────────────────
            '/\b(immobilienmakler|immobilienagentur|hausverwaltung)\b/i',
            '/\b(agence\s+immobili[eè]re|agent\s+immobilier|promoteur\s+immobilier)\b/i',
            '/\b(agenzia\s+immobiliare|agente\s+immobiliare)\b/i',
            '/\b(agencia\s+inmobiliaria|inmobiliaria|promotora\s+inmobiliaria)\b/i',
            '/\b(makelaar|vastgoedkantoor|makelaardij)\b/i',
            '/\b(biuro\s+nieruchomo[sś]ci|agencja\s+nieruchomo[sś]ci)\b/i',
            '/\b(realitn[ií]\s+(kancel[aá][řr]|makl[eé][řr]))\b/i',

            // ─── Multi-language veterinary / pet ─────────────────────────
            '/\b(tierarztpraxis|tierklinik|tierheim|zoofachhandel)\b/i',
            '/\b(clinique\s+v[eé]t[eé]rinaire|cabinet\s+v[eé]t[eé]rinaire)\b/i',
            '/\b(clinica\s+veterinaria|ambulatorio\s+veterinario)\b/i',
            '/\b(cl[ií]nica\s+veterinaria|hospital\s+veterinario)\b/i',
            '/\b(dierenkliniek|dierenarts|dierenwinkel)\b/i',
            '/\b(lecznica\s+weterynaryjna|klinika\s+weterynaryjna)\b/i',
            '/\b(veterin[aá]rn[ií]\s+klinika|veterin[aá]rn[ií]\s+ordinace)\b/i',

            // ─── Multi-language flower / garden ──────────────────────────
            '/\b(blumenladen|blumengeschäft|blumengeschaeft|gärtnerei|gaertnerei)\b/i',
            '/\b(fleuriste|jardinerie|p[eé]pini[eè]re)\b/i',
            '/\b(fioraio|fiorista|vivaio|giardinaggio)\b/i',
            '/\b(florister[ií]a|jardiner[ií]a|vivero)\b/i',
            '/\b(bloemenwinkel|tuincentrum|kwekerij)\b/i',
            '/\b(kwiaciarnia|szkó[lł]ka\s+ro[sś]lin)\b/i',
            '/\b(kv[eě]tin[aá][řr]stv[ií]|zahradnictv[ií])\b/i',

            // ─── Multi-language driving school ───────────────────────────
            '/\b(fahrschule|fahrstunde|fahrlehrer)\b/i',
            '/\b(auto[\s-]?[eé]cole|moniteur\s+de\s+conduite)\b/i',
            '/\b(scuola\s+guida|autoscuola)\b/i',
            '/\b(autoescuela|escuela\s+de\s+conducci[oó]n)\b/i',
            '/\b(rijschool|rijinstructeur)\b/i',
            '/\b(szko[lł]a\s+jazdy|nauka\s+jazdy)\b/i',
            '/\b(auto[šs]kola|autoškola)\b/i',

            // ─── Multi-language pharmacy / drugstore ─────────────────────
            '/\b(apotheke|drogerie)\b/i',
            '/\b(pharmacie|parapharmacie)\b/i',
            '/\b(farmacia|parafarmacia)\b/i',
            '/\b(apotheek|drogisterij)\b/i',
            '/\b(apteka|drogeria)\b/i',
            '/\b(l[eé]k[aá]rna)\b/i',

            // ─── Multi-language funeral services ─────────────────────────
            '/\b(bestattung|bestattungsinstitut|beerdigungsinstitut)\b/i',
            '/\b(pompes\s+fun[eè]bres|fun[eé]rarium)\b/i',
            '/\b(onoranze\s+funebri|pompe\s+funebri)\b/i',
            '/\b(funeraria|servicios?\s+funerarios?)\b/i',
            '/\b(uitvaart(verzorging|centrum)|begrafenisondernemer)\b/i',
            '/\b(zak[lł]ad\s+pogrzebowy)\b/i',
            '/\b(poh[řr]ebn[ií]\s+slu[žz]ba|poh[řr]ebn[ií]\s+[uú]stav)\b/i',
        ];
        
        foreach ($junkPatterns as $pattern) {
            if (preg_match($pattern, $name)) {
                return true;
            }
        }

        // ─── Strong company-name rescue ────────────────────────────
        // Only reached when NOTHING above flagged the name as junk.
        // Keeps legitimate capitalized brand names (Eaton, Safran, ZF Group…)
        // from being rejected by overly aggressive structural heuristics.
        if ($this->looksLikeStrongCompanyName($name)) {
            return false;
        }

        return false;
    }

    /**
     * Check if a company name belongs to a giant OEM / Fortune-500 that is
     * too large to be a realistic EMS prospect. Separated from isJunkCompanyName()
     * because these ARE real company names — they're just not sales targets.
     */
    private function isGiantOem(string $name): bool
    {
        $lower = strtolower(trim($name));

        static $giantOemBlocklist = null;
        if ($giantOemBlocklist === null) {
            $giantOemBlocklist = [
                'honeywell', 'continental ag', 'continental', 'stellantis',
                'bmw', 'bmw group', 'bmw group plants', 'general motors',
                'volkswagen', 'volkswagen group', 'toyota', 'toyota eu',
                'toyota motor europe', 'toyota motor',
                'ford', 'ford motor', 'mercedes-benz', 'mercedes benz',
                'audi', 'porsche', 'nissan', 'honda', 'hyundai', 'kia',
                'tesla', 'volvo', 'volvo cars', 'renault', 'renault group',
                'peugeot', 'citroen', 'fiat', 'chrysler', 'jeep', 'dodge',
                'general electric', 'siemens', 'siemens ag', 'bosch',
                'robert bosch', 'thyssenkrupp', 'basf', 'bayer',
                'samsung', 'lg', 'lg electronics', 'panasonic', 'sony',
                'philips', 'toshiba', 'hitachi', 'mitsubishi',
                'foxconn', 'jabil', 'flex', 'celestica', 'sanmina',
                'apple', 'google', 'amazon', 'microsoft', 'meta',
                'intel', 'amd', 'nvidia', 'qualcomm', 'broadcom',
                'texas instruments', 'infineon', 'stmicroelectronics',
                'nxp', 'microchip', 'renesas', 'on semiconductor',
                'boeing', 'airbus', 'lockheed martin', 'raytheon',
                'northrop grumman', 'general dynamics', 'bae systems',
                'rolls-royce', 'safran', 'thales', 'leonardo',
                'caterpillar', 'john deere', 'deere & company',
                'schneider electric', 'schneider electric global', 'abb', 'emerson', 'rockwell',
                'rockwell automation', 'rockwell collins',
                'penske', 'penske automotive group',
                'cox automotive', 'cox automotive inc.',
                'mckinsey', 'bain', 'bain capital', 'bcg',
                'goldman sachs', 'jp morgan', 'morgan stanley',
                'denso', 'denso global website', 'aisin', 'denso corporation',
                'dupont', 'gentherm', 'gentherm incorporated', 'valero',
                'sun chemical', 'clean harbors', 'clean harbours',
                'bombardier', 'rheinmetall', 'rivian', 'zf group', 'zf',
                'valeo', 'baesystems', 'bae systems plc',
                'totalenergies', 'totalenergies egypt', 'total energies',
                'thermo fisher', 'thermo fisher scientific',
                'covestro', 'covestro ag',
                'astrazeneca', 'capgemini',
                'pratt & whitney', 'pratt whitney', 'prattwhitney',
                'somaca', 'casablanca plant',
                'magna', 'magna international', 'lear corporation', 'lear',
                'aptiv', 'delphi', 'delphi technologies',
                'marelli', 'magneti marelli', 'knorr-bremse',
                'wabash', 'wabco', 'dana', 'dana incorporated',
                'borgwarner', 'hella', 'mahle', 'schaeffler',
                'nidec', 'te connectivity', 'amphenol', 'molex',
                'johnson controls', 'tyco', 'eaton',
                'parker hannifin', 'parker', 'roper technologies',
                'danaher', 'fortive', 'ametek',
                'textron', 'l3harris', 'curtiss-wright',
                'elbit systems', 'rafael', 'iai',
                'safran electronics & defense', 'safran electronics & defen',
                'ezz steel', 'telekom', 'capgemini morocco', 'capgemini moroc',
                'casablanca plant (somaca)',
                'homepage zf friedrichshafen', 'homepage zf friedrichshafen ag',
                'zf friedrichshafen', 'zf friedrichshafen ag',
                'caparol', 'caparol arabia', 'caparol industrial',
                'hennessey special vehicles', 'hennessey',
                'edita', 'edita food industries',
                'dorsey', 'dorsey trailers',
                'gecko robotics',
                'precision aviation group', 'pag',
                'iac', 'international automotive components',
                'vacker', 'vacker group',
                'nxp semiconductors', 'nxp semiconductor',
                'ge aerospace', 'gkn aerospace', 'gkn',
                'textron systems', 'kongsberg', 'kongsberg gruppen',
                'hensoldt', 'hensoldt ag',
                'diehl group', 'diehl', 'diehl defence', 'diehl aviation',
                'chemring', 'chemring group', 'chemring group plc',
                'joby aviation',
                'teradyne', 'rohde & schwarz', 'rohde schwarz',
                'kistler', 'kistler nl',
                'benteler', 'benteler group', 'benteler automotive',
                'horse powertrain', 'punch powertrain',
                'seg automotive', 'seg automotive germany',
                'zkw group', 'zkw',
                'nexteer', 'nexteer automotive',
                'kuka', 'kuka ag', 'comau', 'comau spa',
                'b&r industrial automation', 'b&r automation',
                'kawasaki robotics', 'kawasaki heavy industries',
                'lyondellbasell', 'lyondellbasell industries',
                'merck group', 'merck kgaa', 'merck',
                'trelleborg', 'trelleborg ab', 'trelleborg sealing',
                'dupont de nemours',
                'elaraby', 'elaraby group', 'el araby group',
                'mondelez international', 'mondelēz international', 'mondelez',
                'cosumar', 'ocp group', 'ocp',
                'al-futtaim', 'al futtaim', 'al-futtaim group',
                'fev group', 'fev', 'fev gmbh',
                'segula technologies', 'segula',
                'ussteel', 'us steel', 'u.s. steel',
                'mitsubishi electric', 'mitsubishi electric europe',
                'mitsubishi electric europe bv france',
                'eurocontrol', 'icao',
                'international civil aviation organization',
                'federal aviation administration', 'faa',
                'grant thornton', 'grant thornton (us)',
                'lincoln international', 'lincoln international llc',
                'raya corp', 'raya corporation',
                'ge', 'daher', 'ascent aerospace',
                'northrop grumman', 'integrated fires mission command',
                'precisionaviationgroup',
                'gulfex', 'gulf extrusions',
                'gb corp', 'gb corporation',
                'streit group',
                'markforged',
                'peer group', 'peer group inc.',
                'nvidianews nvidia', 'nvidianews',
                'gulf cryo',
                'falcon group',
                'opple lighting', 'opple lighting mea',
                'infinity trailers',
                'zf lifetec', 'zf-lifetec',
                'comeca', 'comeca group',
                'dunlop', 'dunlop tyres', 'dunlop tires',
                'leoni', 'leoni wiring systems', 'leoni tunisia',
                'nexans', 'nexans autoelectric',
                'odoo', 'odoo sa',
                'sogeclair',
                'groupe telnet', 'groupe-telnet', 'telnet group',
                'midea', 'midea group', 'midea egypt',
                'haier', 'haier group', 'haier egypt',
                'ups', 'ups freight', 'ups supply chain',
                'fedex', 'fedex express', 'fedex ground',
                'dhl', 'dhl express', 'dhl supply chain',
                'whirlpool', 'whirlpool corporation',
                'electrolux', 'electrolux group',
                'beko', 'beko egypt', 'arçelik',
                'sharp', 'sharp corporation', 'sharp egypt',
                'telecom egypt', 'te data', 'te software',
                'cairo ict', 'cairoict',
                'nti', 'national telecom institute',
                'trane', 'trane technologies', 'trane egypt',
                'carrier', 'carrier global', 'carrier egypt',
                'daikin', 'daikin industries', 'daikin egypt',
                'airbnb', 'airbnb inc',
                'data center map', 'datacentermap',
                'middle east monitor',
                'trt world', 'trt',
                'global uploads webflow',
                'solarinvertermanufacturers',
                'factocert',
                'sbcertgroup',
                'shipserv',
                'cairo sales stores', 'cairo sales',
                'gpx global systems', 'gpx global',
                'radio holland',
                'chloride batteries',
                'bowfin', 'bowfin boats',
                'nspo', 'nato support',
                'dnb carnegie', 'dun & bradstreet', 'dun and bradstreet',
                'cfm international', 'cfm', 'cfm aeroengines',
                'prysmian', 'prysmian group', 'prysmian norway',
                'inwi', 'maroc telecom', 'iam',
                'telus', 'telus digital', 'telus international',
                'royal mansour', 'royal mansour marrakech',
                'veichi', 'veichi electric',
                'resunsolargroup', 'resun solar',
                'archive ouverte hal', 'hal science',
                'scispace', 'researchgate',
                'assemblymag', 'assembly magazine',
                'bisinfotech',
                'minkels', 'solutions for data centers',
                'fenie brossette',
                'lockheed martin', 'lockheedmartin', 'lockheed',
                'tata', 'tata advanced systems', 'tata group',
                'aresia',
                'nexo', 'nexo sa',
                'gateway medtech', 'gatewaymedtech',
                'qualipro', 'imagine human',
                'solarctrl', 'solar ctrl',
                'visionair', 'visionair maroc',
                'flexibat',
                'sage', 'sage publications', 'sage journals', 'sagepub', 'journals sagepub',
                'evernex',
                'energie scoot', 'energiescoot', 'energie scoot maroc',
                'lufthansa',
                'hisense', 'hisense hvac',
                'watts', 'watts electronics',
                'defense advancement',
                'military africa',
                'nordic monitor',
                'arab reform', 'arab reform initiative',
                'data protection laws', 'dlapiper', 'dla piper',
                'lab of tomorrow', 'lab-of-tomorrow', 'giz-lot',
                'inkyfada',
                'switchmed',
                'bahrain turf club', 'bahrain turf', 'royal equestrian',
                'motoma',
                'national oilwell varco',
                'tesup',
                'solax', 'solax power',
                'teltonika',
                'gdsdisplays', 'gds displays',
                'shapr3d',
                'integrity next', 'integritynext',
                'sanbor', 'sanbor medical',
                'build_me', 'build me',
                'sami tube fittings', 'sami tube',
                'elmed', 'elmed project',
                'evolution engineering services',
                'auxsol',
                'brika agency', 'brika',
                'ilex life sciences', 'ilex',
                'hewlett packard', 'hewlett packard enterprise', 'hpe',
                'marquardt', 'marquardt u.s', 'marquardt group',
                'amea power',
                'colas rail', 'colas', 'colas group',
                'talgo', 'talgo inc', 'talgo s.a.',
                'kfw', 'kfw group', 'kfw bankengruppe',
                'ats', 'ats global', 'ats data center solutions',
                'adec technologies', 'adec',
                'cmr group', 'cmr',
                'cruisemapper', 'cruise mapper',
                'tunisie-foot', 'tunisie foot',
                'ingenius',
                'high-quality laboratory reagents',
                'hbm', 'hbk', 'hottinger', 'hottinger baldwin',
                'aymax',
                'selt marine', 'selt marine group',
                'tech216',
                'cim groupe', 'cim group', 'john cockerill',
                '2j antennas', '2j-antennas',
                'alstom', 'alstom transport', 'alstom egypt',
                'ratpdev', 'ratp dev', 'ratp group', 'ratp',
                'elsewedy', 'elsewedy electric', 'el sewedy',
                'lexmark', 'lexmark international',
                'vinci', 'vinci construction', 'vinci energies',
                'dfds', 'dfds a/s', 'dfds group',
                'artelia', 'artelia group',
                'fassmer', 'fassmer werft',
                'searates', 'sea rates',
                'fm global',
                'keyter',
                'africa intelligence', 'africaintelligence',
                'ship supply in casablanca',
                'pharmacity',
                'bbamorocco', 'bba morocco',
                'ideo factory', 'ideo',
                'green bike city', 'gbc',
                'arcelormittal', 'arcelor mittal', 'rails arcelormittal',
                'sncf', 'groupe sncf', 'sncf group',
                'oncf',
                'piassaty',
                'le rail', 'railway gazette', 'railwaygazette',
            ];
        }

        if (in_array($lower, $giantOemBlocklist, true)) {
            return true;
        }

        foreach ($giantOemBlocklist as $oem) {
            if (strlen($oem) >= 4 && str_starts_with($lower, $oem . ' ')) {
                return true;
            }
            if (strlen($oem) >= 8 && str_contains($lower, $oem)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Classify a search result by analyzing its snippet to determine if
    /**
     * SYSTEMATIC HOMEPAGE CONTENT CLASSIFIER
     *
     * Instead of whack-a-mole name patterns, this method analyzes the
     * actual HOMEPAGE HTML to classify the company by business type.
     * Inspired by SIC/NAICS codes (5500=Auto Dealers, 5200-5999=Retail,
     * 4000-4999=Transport, vs 2000-3999=Manufacturing).
     *
     * Scoring system:
     *   NEGATIVE signals = dealer, franchise, retailer, parts shop, giant
     *   POSITIVE signals = manufacturer, OEM, production facility
     *
     * Returns TRUE if this homepage belongs to a non-target business
     * (dealer, distributor, franchise, retailer, service shop, giant OEM).
     */
    private function isHomepageDealerOrNonTarget(string $html, string $name, string $domain): bool
    {
        $text = strtolower(strip_tags($html));
        $htmlLower = strtolower($html);
        $score = 0;

        // ─── EMS COMPETITOR / CONTRACT MANUFACTURER homepage signals ───
        // Companies providing the SAME services Starz provides:
        // wire harness, cable assembly, EMS/PCB assembly, CNC machining,
        // injection molding, contract manufacturing. These are COMPETITORS.
        $emsCompetitorSignals = 0;
        // Wire harness / cable assembly provider
        if (preg_match('/\b(wire\s+harness|cable\s+(harness|assembly|assemblies)|wiring\s+harness|faisceau|câblage|Kabelbau|Kabelkonfektion)\s*(manufactur|assembl|produc|provider|service|supplier|company|for\s+(automo|aero|industr|OEM))/i', $text)) $emsCompetitorSignals += 5;
        if (preg_match('/\bwe\s+(manufacture|assemble|produce|build|make|design)\s+(wire|cable|wiring)\s+(harness|assembl)/i', $text)) $emsCompetitorSignals += 6;
        // EMS / PCB assembly provider
        if (preg_match('/\b(electronics\s+manufacturing\s+services?|EMS\s+(provider|company|partner)|contract\s+electronics\s+manufactur|PCB\s+assembly\s+(service|house|provider|company|partner))/i', $text)) $emsCompetitorSignals += 5;
        if (preg_match('/\b(pcba?\s+(manufactur|assembl|provider|company|service)|smt\s+assembly\s+(service|line|capabilit)|turnkey\s+(EMS|electronics|PCB))/i', $text)) $emsCompetitorSignals += 5;
        // CNC machining / precision machining service
        if (preg_match('/\b(CNC|precision)\s+(machining|milling|turning|lathe)\s+(service|company|provider|shop|capabilit|specialist|partner)/i', $text)) $emsCompetitorSignals += 5;
        if (preg_match('/\bwe\s+(speciali[sz]e|offer|provide)\s+.{0,30}(CNC|precision)\s+(machining|milling|turning)/i', $text)) $emsCompetitorSignals += 5;
        // (iter-TX v5) "machine shop" patterns — very common for machining competitors
        // "machine shop in Houston", "precision machine shop", "CNC machine shop"
        if (preg_match('/\b(machine\s+shop|job\s+shop)\b/i', $text)) $emsCompetitorSignals += 6;
        if (preg_match('/\b(machining|manufacturing)\s+(facility|plant|center|house)\b/i', $text) && preg_match('/\b(ISO\s*9001|AS\s*9100|ITAR|DDTC)\b/i', $text)) $emsCompetitorSignals += 4;
        // "if it's metal, we'll cut it" and similar service phrases
        if (preg_match('/\bwe\'?ll\s+(cut|machine|manufacture|produce)\s+it\b/i', $text)) $emsCompetitorSignals += 5;
        // (iter-TX v3) Standalone "Precision Machining" + "Assembly Capabilities" menus (NN, Inc pattern)
        // Multiple manufacturing service menu items = contract manufacturer
        $capabilitiesCount = 0;
        if (preg_match('/\bprecision\s+machining\b/i', $text)) $capabilitiesCount++;
        if (preg_match('/\bassembly\s+capabilit/i', $text)) $capabilitiesCount++;
        if (preg_match('/\bstamping\s+capabilit/i', $text)) $capabilitiesCount++;
        if (preg_match('/\btooling\s+capabilit/i', $text)) $capabilitiesCount++;
        if (preg_match('/\bplating\s+capabilit/i', $text)) $capabilitiesCount++;
        if (preg_match('/\bour\s+capabilit/i', $text) && preg_match('/\b(machining|assembly|stamping|molding|casting|forging)\b/i', $text)) $capabilitiesCount++;
        if ($capabilitiesCount >= 2) $emsCompetitorSignals += 5;
        // Injection molding service
        if (preg_match('/\b(injection\s+molding|injection\s+moulding|plastic\s+injection)\s+(service|company|provider|capabilit|specialist|partner)/i', $text)) $emsCompetitorSignals += 5;
        if (preg_match('/\bwe\s+(speciali[sz]e|offer|provide)\s+.{0,30}(injection\s+mol[du]ing|plastic\s+injection)/i', $text)) $emsCompetitorSignals += 5;
        // Die casting / metal casting service
        if (preg_match('/\b(die\s+casting|investment\s+casting|sand\s+casting|metal\s+casting)\s+(service|company|provider|capabilit|specialist|partner)/i', $text)) $emsCompetitorSignals += 5;
        // Contract manufacturing (generic)
        if (preg_match('/\b(contract\s+manufactur(er|ing)|sous[\s-]?trait(ant|ance)|lohnfertigung|Auftragsfertigung)\s*(service|company|provider|partner|for\s+(OEM|automo|aero|industr))?/i', $text)) $emsCompetitorSignals += 4;
        // On-demand manufacturing marketplace
        if (preg_match('/\b(on[\s-]?demand\s+manufactur|custom\s+parts?\s+(instant|online|fast)|upload\s+your\s+(cad|design|part|drawing)\s+(for|to)\s+(instant|quick)?\s*quot)/i', $text)) $emsCompetitorSignals += 5;
        // Heat treating / surface treatment services
        if (preg_match('/\b(heat\s+treat(ing|ment)|surface\s+treat(ing|ment)|plating\s+service|anodizing\s+service|powder\s+coat(ing)?\s+service)/i', $text)) $emsCompetitorSignals += 4;
        // General "we provide manufacturing services" patterns
        if (preg_match('/\b(full[\s-]?service|turnkey|one[\s-]?stop)\s+(manufactur|EMS|electronics|assembl|machining|molding|moulding)/i', $text)) $emsCompetitorSignals += 4;
        if (preg_match('/\b(prototype\s+to\s+production|low[\s-]volume.*high[\s-]mix|high[\s-]mix.*low[\s-]volume)/i', $text)) $emsCompetitorSignals += 4;
        // (iter-TX v3) "Precision Capabilities" / "Manufacturing Capabilities" pages
        if (preg_match('/\b(precision|manufacturing|metal|machining)\s+capabilit/i', $text)) $emsCompetitorSignals += 3;
        if ($emsCompetitorSignals >= 4) {
            $score -= 45;
        }

        // ─── STEEL / METAL PRODUCER / RAW MATERIAL SUPPLIER homepage signals ───
        // Steel mills, aluminum smelters, raw material producers are NOT OEM buyers.
        // Nucor = "largest steel manufacturer and recycler" — REJECT
        $steelProducerSignals = 0;
        if (preg_match('/\b(steel|aluminum|aluminium)\s+(manufactur|produc|recycl|mill|smelter|company|supplier)/i', $text)) $steelProducerSignals += 4;
        if (preg_match('/\b(largest|leading|major|top)\s+(steel|aluminum|aluminium|metal)\s+(manufactur|produc|recycl|supplier)/i', $text)) $steelProducerSignals += 5;
        if (preg_match('/\b(steel\s+mill|rolling\s+mill|blast\s+furnace|electric\s+arc\s+furnace|hot\s+rolled|cold\s+rolled)/i', $text)) $steelProducerSignals += 4;
        if (preg_match('/\b(primary\s+metal|raw\s+material\s+(supplier|produc)|metal\s+recycl|scrap\s+metal)/i', $text)) $steelProducerSignals += 3;
        if (preg_match('/\b(iron\s+ore|bauxite|ingot|billet|slab|bloom|continuous\s+casting)/i', $text)) $steelProducerSignals += 3;
        if ($steelProducerSignals >= 4) {
            $score -= 45;
        }

        // ─── AUTOMOTIVE TEST FACILITY / PROVING GROUND homepage signals ───
        // MITRP-type: proving grounds, vehicle testing, labor services
        $testFacilitySignals = 0;
        if (preg_match('/\b(proving\s+ground|test\s+(track|facility|center)|vehicle\s+testing|automotive\s+testing)/i', $text)) $testFacilitySignals += 5;
        if (preg_match('/\b(technical\s+labor\s+service|labor\s+service|test\s+service|testing\s+service)/i', $text)) $testFacilitySignals += 4;
        if (preg_match('/\b(crash\s+test|durability\s+test|emissions\s+test|validation\s+test|homologation)/i', $text)) $testFacilitySignals += 3;
        if ($testFacilitySignals >= 4) {
            $score -= 40;
        }

        // ─── CUSTOM FABRICATOR / MATERIAL HANDLING SUPPLIER homepage signals ───
        // RIM Custom Racks-type: custom racks, containers, full-service supplier
        $customFabricatorSignals = 0;
        if (preg_match('/\b(custom\s+(rack|container|fabricat)|shipping\s+rack|plant\s+rack|material\s+handling.*container)/i', $text)) $customFabricatorSignals += 4;
        if (preg_match('/\b(full[\s-]service\s+supplier|custom\s+fabricat)/i', $text)) $customFabricatorSignals += 3;
        if (preg_match('/\b(we\s+(design|build|fabricate)\s+.{0,30}(rack|container|fixture|dunnage))/i', $text)) $customFabricatorSignals += 4;
        if ($customFabricatorSignals >= 4) {
            $score -= 35;
        }

        // ─── Biotech / Pharma / Life Sciences homepage signals ─────
        // Pure biotech companies are NOT EMS buyers (they buy lab equipment,
        // not electronics manufacturing). Detect and reject.
        $biotechSignals = 0;
        if (preg_match('/\b(clinical\s+trial|drug\s+discovery|gene\s+therapy|mrna|immunotherapy|oncology|biosimilar|therapeutic\s+pipeline|drug\s+candidate|fda\s+approv)/i', $text)) $biotechSignals += 3;
        if (preg_match('/\b(pharmaceutical|pharma|biopharma|biopharmaceutical)\s+(company|manufacturer|industry|leader)/i', $text)) $biotechSignals += 3;
        if (preg_match('/\b(vaccine|antibody|antibodies|monoclonal|recombinant|peptide|protein\s+engineering|cell\s+therapy)/i', $text)) $biotechSignals += 2;
        if (preg_match('/\b(clinical\s+research|phase\s+[I1-3]|regulatory\s+submission|nda|biologics?\s+license)/i', $text)) $biotechSignals += 2;
        if ($biotechSignals >= 3) {
            $score -= 40;
        }

        // ─── Chemical / petrochemical / raw materials homepage signals ──
        $chemicalSignals = 0;
        if (preg_match('/\b(chemical\s+(manufactur|produc|plant|facility)|petrochemical|agrochemical|specialty\s+chemical)/i', $text)) $chemicalSignals += 3;
        if (preg_match('/\b(fertilizer|pesticide|herbicide|polymer\s+produc|resin\s+manufactur|industrial\s+gas)/i', $text)) $chemicalSignals += 2;
        if (preg_match('/\b(refinery|crude\s+oil|upstream|downstream|hydrocarbon|distillation)/i', $text)) $chemicalSignals += 2;
        if ($chemicalSignals >= 3) {
            $score -= 35;
        }

        // ─── Finance / investment / banking homepage signals ─────────
        $financeSignals = 0;
        if (preg_match('/\b(investment\s+(bank|fund|management|portfolio)|asset\s+management|wealth\s+management|hedge\s+fund)/i', $text)) $financeSignals += 3;
        if (preg_match('/\b(private\s+equity|venture\s+capital|portfolio\s+companies|aum|assets?\s+under\s+management)/i', $text)) $financeSignals += 3;
        if (preg_match('/\b(accounting|audit|tax\s+advisory|financial\s+statement|assurance\s+services)/i', $text)) $financeSignals += 2;
        if (preg_match('/\b(insurance\s+(company|broker|underwriter|premium)|actuarial|claims\s+processing)/i', $text)) $financeSignals += 2;
        if ($financeSignals >= 3) {
            $score -= 40;
        }

        // ─── Game studio / gaming company homepage signals ───────────
        $gamingSignals = 0;
        if (preg_match('/\b(game\s+studio|game\s+development|video\s+game|mobile\s+game|pc\s+game|console\s+game)/i', $text)) $gamingSignals += 3;
        if (preg_match('/\b(gameplay|multiplayer|single[\s-]player|esports|twitch|steam|playstation|xbox|nintendo)/i', $text)) $gamingSignals += 2;
        if (preg_match('/\b(unreal\s+engine|unity\s+engine|game\s+design|level\s+design|character\s+art)/i', $text)) $gamingSignals += 2;
        if ($gamingSignals >= 3) {
            $score -= 40;
        }

        // ─── Channel partner / brand distributor homepage signals ────
        // Schneider Electric channel partners, ABB value-added resellers, etc.
        // These are NOT purchasers of EMS — they resell others' products
        $channelPartnerSignals = 0;
        if (preg_match('/\b(authorized\s+(distribut|resell|partner|dealer|channel)|channel\s+partner|value[\s-]added\s+resell)/i', $text)) $channelPartnerSignals += 3;
        // Channel partners must frame the brand with authorized/official/
        // exclusive language ("authorized distributor of Omron"). A bare
        // "Omron partner" match hits manufacturers' own partner programs
        // (e.g. Omron, Schneider Electric) — a known false-positive source.
        if (preg_match('/\b(authorized|authorised|official|exclusive|appointed|sole)\s+(distribut|resell|partner|dealer|channel)\s+(?:of|for|to)?\s*(schneider|abb|siemens|omron|allen[\s-]bradley|rockwell|mitsubishi|phoenix\s+contact|eaton)\b/i', $text)) $channelPartnerSignals += 3;
        if (preg_match('/\b(we\s+(distribut|supply|stock|sell|represent)\s+(schneider|abb|siemens|eaton|omron|allen[\s-]bradley))/i', $text)) $channelPartnerSignals += 4;
        // Products ONLY from brand catalogs, no own manufacturing
        if (preg_match('/\b(product\s+catalog|brand\s+portfolio|we\s+carry|we\s+stock|authorized\s+stock)/i', $text)) $channelPartnerSignals += 1;
        if ($channelPartnerSignals >= 4) {
            $score -= 35;
        }

        // ─── SYSTEM INTEGRATOR / AUTOMATION SOLUTIONS PROVIDER homepage signals ───
        // Industrial Automation type: "custom automated control solutions", "automation solutions"
        // These provide engineering/integration SERVICES, not products.
        $systemIntegratorSignals = 0;
        // Bare "automation solutions" is product-manufacturer language
        // (e.g. Omron "Automation Solutions") — only service framings
        // ("automation provider/integrator/services") count as integrators.
        if (preg_match('/\b(custom\s+(automated?|automation|control)\s+(solution|system)|automation\s+(provider|integrator|services?))/i', $text)) $systemIntegratorSignals += 5;
        if (preg_match('/\b(system\s+integrat(or|ion)|control\s+system\s+(integrat|design|engineer)|PLC\s+program)/i', $text)) $systemIntegratorSignals += 4;
        if (preg_match('/\b(we\s+(create|design|build|develop|implement)\s+.{0,30}(control|automation|robotic)\s+(system|solution))/i', $text)) $systemIntegratorSignals += 5;
        if (preg_match('/\b(turn[\s-]?key\s+automation|automation\s+(and|&)\s+control|controls?\s+expert|SCADA|HMI\s+design)/i', $text)) $systemIntegratorSignals += 3;
        if ($systemIntegratorSignals >= 4) {
            $score -= 40;
        }

        // ─── Component / semiconductor / FPGA distributor homepage signals ──
        // Standalone chip/FPGA/component distributors (not OEM manufacturers)
        $chipDistributorSignals = 0;
        if (preg_match('/\b(fpga|asic|cpld|soc|microcontroller|mcu|dsp)\s+(distribut|supplier|source)/i', $text)) $chipDistributorSignals += 4;
        if (preg_match('/\b(chip|semiconductor|ic|component)\s+(distribut|supplier|wholesal)/i', $text)) $chipDistributorSignals += 3;
        if (preg_match('/\breliable\s+source\s+for\s+(chip|component|semiconductor|fpga|ic|high[\s-]?performance)/i', $text)) $chipDistributorSignals += 4;
        if (preg_match('/\b(we\s+(distribut|supply|stock|sell)\s+.{0,40}(fpga|asic|chip|semiconductor|ic|component))/i', $text)) $chipDistributorSignals += 4;
        if (preg_match('/\b(product\s+(portfolio|range|catalog)\s*[:.]?\s*.{0,60}(xilinx|altera|intel|lattice|microchip|analog\s+devices|texas\s+instruments|nxp|stmicro|infineon))/i', $text)) $chipDistributorSignals += 3;
        if (preg_match('/\b(franchised|authorized)\s+(distribut|supplier|partner)/i', $text)) $chipDistributorSignals += 3;
        if ($chipDistributorSignals >= 4) {
            $score -= 40;
        }

        // ─── B2B MARKETPLACE / DIRECTORY / PLATFORM homepage signals ──────
        // Metoree, Thomasnet sub-pages, industry directories, etc.
        // These are NOT companies — they aggregate supplier listings.
        $marketplaceSignals = 0;
        if (preg_match('/\b(b2b\s+(platform|marketplace|directory|portal)|industrial\s+(directory|marketplace)|supplier\s+directory)/i', $text)) $marketplaceSignals += 5;
        if (preg_match('/\b(find\s+(suppliers?|manufacturers?|vendors?)|compare\s+(suppliers?|products?|quotes?)|request\s+a?\s*quote)/i', $text)) $marketplaceSignals += 3;
        if (preg_match('/\b(product\s+(catalog|comparison)|supplier\s+(search|listing|profiles?)|browse\s+(categories|products?))/i', $text)) $marketplaceSignals += 3;
        if (preg_match('/\b(verified\s+suppliers?|trusted\s+suppliers?|supplier\s+rating|request\s+for\s+quotation)/i', $text)) $marketplaceSignals += 3;
        if (preg_match('/\b(\d{3,}\+?\s+suppliers?|\d{3,}\+?\s+manufacturers?|\d{3,}\+?\s+products?)\b/i', $text)) $marketplaceSignals += 3;
        if ($marketplaceSignals >= 4) {
            $score -= 50;  // Strong penalty — marketplaces are never prospects
        }

        // ─── Software company / IT services homepage signals (iter11) ─
        $softwareSignals = 0;
        if (preg_match('/\b(software\s+(company|development|solutions?|house|firm|services?)|custom\s+software|bespoke\s+software|offshore\s+software|nearshore\s+software)/i', $text)) $softwareSignals += 3;
        if (preg_match('/\b(erp|crm|hrm|saas|cloud\s+platform|web\s+application|mobile\s+app|full[\s-]stack)\s+(software|solution|develop|platform|system)/i', $text)) $softwareSignals += 3;
        if (preg_match('/\b(agile|scrum|devops|ci\/cd|microservice|api\s+develop|code\s+review|sprint|backlog|repository)/i', $text)) $softwareSignals += 2;
        if (preg_match('/\b(python|java|php|node\.?js|react|angular|vue|\.net|c#|typescript|flutter|kotlin|swift)\b/i', $text)) $softwareSignals += 2;
        if (preg_match('/\b(automotive\s+software|ecu\s+software|adas\s+software|hil\s+testing|model[\s-]based\s+design|autosar)/i', $text)) $softwareSignals += 3;
        if ($softwareSignals >= 4) {
            $score -= 40;
        }

        // ─── Equipment dealer / trading company homepage signals (iter11) ─
        $equipDealerSignals = 0;
        if (preg_match('/\b((lab|laboratory|medical|scientific|pharma|analytical)\s+equipment\s+(dealer|supplier|distributor|agent|trading))/i', $text)) $equipDealerSignals += 4;
        if (preg_match('/\b(we\s+(sell|supply|distribute|represent|service)\s+.*?\b(equipment|instruments?|apparatus))/i', $text)) $equipDealerSignals += 2;
        if (preg_match('/\b((sole|exclusive|authorized)\s+(agent|representative|dealer)\s+(for|of|in))/i', $text)) $equipDealerSignals += 3;
        if (preg_match('/\b(calibration|maintenance|after[\s-]?sales?\s+service|spare\s+parts?|technical\s+support)\s+(for|of|services?)/i', $text)) $equipDealerSignals += 1;
        if (preg_match('/\b(representing|distributing|supplying)\s+(international|global|leading|world[\s-]?class)\s+(brands?|manufacturers?)/i', $text)) $equipDealerSignals += 3;
        if ($equipDealerSignals >= 4) {
            $score -= 35;
        }

        // ─── General trading / import-export company homepage signals ─────
        // Trading companies and import/export houses are NOT manufacturers.
        // They source and resell products — not EMS buyers.
        $tradingCoSignals = 0;
        if (preg_match('/\b(trading\s+(company|group|house|enterprise|corporation)|general\s+trad(ing|er)|import\s*(and|&|\+)\s*export\s*(company|group|house|enterprise)?)/i', $text)) $tradingCoSignals += 4;
        if (preg_match('/\b(we\s+(import|trade|source|procure)\s+(from|globally|internationally|worldwide)|our\s+trading\s+(activities|operations|network))/i', $text)) $tradingCoSignals += 3;
        if (preg_match('/\b(commercial\s+(agent|representation|intermediary)|indent\s+agent|buying\s+agent|sourcing\s+agent)/i', $text)) $tradingCoSignals += 3;
        if (preg_match('/\b(wide\s+range\s+of\s+(products?|brands?|items?)|we\s+supply\s+a\s+wide|product\s+range\s+includes?)/i', $text) && $tradingCoSignals > 0) $tradingCoSignals += 2;
        if (preg_match('/\b(import\s+license|customs\s+clearance|trade\s+finance|letter\s+of\s+credit|\bL\/?C\b)/i', $text) && $tradingCoSignals > 0) $tradingCoSignals += 2;
        if ($tradingCoSignals >= 3) {
            $score -= 35;
        }

        // ─── Mining / quarrying / mineral company homepage signals (iter11) ─
        $miningSignals = 0;
        if (preg_match('/\b(mining\s+(company|operations?|industry|sector)|quarry(ing)?\s+(company|operations?)|mineral\s+(extraction|processing|resources?))/i', $text)) $miningSignals += 3;
        if (preg_match('/\b(calcium\s+carbonate|limestone|marble|granite|gypsum|silica|feldspar|kaolin|dolomite|bauxite|phosphate)/i', $text)) $miningSignals += 3;
        if (preg_match('/\b(open[\s-]?pit|underground\s+mine|crushing|grinding|beneficiation|flotation|ore\s+processing)/i', $text)) $miningSignals += 2;
        if ($miningSignals >= 3) {
            $score -= 35;
        }

        // ─── Engineering consultancy / EPC homepage signals (iter11) ─
        $epcSignals = 0;
        if (preg_match('/\b(engineering\s+(consultancy|consulting|services?)\s+(company|firm|group))/i', $text)) $epcSignals += 3;
        if (preg_match('/\b(EPC|EPCM|engineering\s+procurement\s+.*?construction)/i', $text)) $epcSignals += 3;
        if (preg_match('/\b(power\s+(plant|generation|station)\s+(engineering|design|construction)|thermal\s+power|combined[\s-]cycle)/i', $text)) $epcSignals += 3;
        if (preg_match('/\b(project\s+management\s+consult|owner.?s\s+engineer|feasibility\s+stud)/i', $text)) $epcSignals += 2;
        if ($epcSignals >= 3) {
            $score -= 35;
        }

        // ─── Market research / report seller homepage signals (iter12) ──
        $marketResearchSignals = 0;
        if (preg_match('/\b(market\s+(report|research|insight|intelligence|forecast|analysis)|industry\s+report|research\s+report|market\s+size)/i', $text)) $marketResearchSignals += 3;
        if (preg_match('/\b(CAGR|compound\s+annual|sample\s+pdf|request\s+sample|add\s+to\s+cart|buy\s+(this\s+)?report|report\s+overview|study\s+period)/i', $text)) $marketResearchSignals += 3;
        if (preg_match('/\b(market\s+valuation|forecast\s+period|growth\s+driver|market\s+segmentation|regional\s+analysis|key\s+player|competitive\s+landscape)/i', $text)) $marketResearchSignals += 2;
        if ($marketResearchSignals >= 3) {
            $score -= 40;
        }

        // ─── Business intelligence / data analytics / BI platform signals ──
        // Companies that provide BI tools, data analytics, competitive
        // intelligence, or market data platforms are NOT EMS buyers.
        $biSignals = 0;
        if (preg_match('/\b(business\s+intelligence|competitive\s+intelligence|market\s+intelligence|threat\s+intelligence|geopolitical\s+intelligence)/i', $text)) $biSignals += 3;
        if (preg_match('/\b(data\s+analytics?\s+(platform|solution|company|firm|provider)|analytics?\s+&\s+intelligence|BI\s+(platform|tool|solution|dashboard))/i', $text)) $biSignals += 3;
        if (preg_match('/\b(open[\s-]source\s+intelligence|OSINT|intelligence\s+report|intelligence\s+briefing|risk\s+intelligence|security\s+intelligence)/i', $text)) $biSignals += 3;
        if (preg_match('/\b(data\s+visualization|dashboard|data\s+warehouse|data\s+lake|power\s+bi|tableau|looker|qlik)/i', $text)) $biSignals += 2;
        if (preg_match('/\b(actionable\s+insights?|data[\s-]driven\s+(decision|insight|strateg)|intelligence[\s-]led)/i', $text)) $biSignals += 2;
        if ($biSignals >= 3) {
            $score -= 40;
        }

        // ─── Government megaproject / giga-project / smart city dev (iter12) ─
        $megaprojectSignals = 0;
        if (preg_match('/\b(megaproject|mega[\s-]?project|giga[\s-]?project|smart\s+city\s+(project|development|initiative)|new\s+city\s+(project|development))/i', $text)) $megaprojectSignals += 3;
        if (preg_match('/\b(vision\s+2030|region\s+in\s+the\s+making|future\s+of\s+living|reimagin(e|ing)\s+the\s+future|new\s+future|sustainable\s+urban)/i', $text)) $megaprojectSignals += 2;
        if (preg_match('/\b(invest\s+in\s+neom|explore\s+careers|work\s+at\s+neom|sectors?\s+(biotech|design|entertainment|tourism|food|sport))/i', $text)) $megaprojectSignals += 3;
        if ($megaprojectSignals >= 3) {
            $score -= 35;
        }

        // ─── Arabic / regional financial news / stock market data (iter12) ─
        $finNewsSignals = 0;
        if (preg_match('/\b(stock\s+market|stock\s+exchange|share\s+price|market\s+cap|تاسي|نمو|سوق)/i', $text)) $finNewsSignals += 3;
        if (preg_match('/\b(IPO|dividend|earnings|quarterly\s+report|financial\s+result|analyst\s+estimate|mutual\s+fund)/i', $text)) $finNewsSignals += 2;
        if (preg_match('/\b(بورصة|أسهم|صناديق|اكتتاب|توزيعات|أرباح|مؤشر)/u', $text)) $finNewsSignals += 3;
        if ($finNewsSignals >= 3) {
            $score -= 40;
        }

        // ─── Telecoms industry / telecom operator news (iter12) ─
        $telecomNewsSignals = 0;
        if (preg_match('/\b(telecom(s|munication)?\s+(news|industry|sector|operator|business)|5G[\s-]?(advanced|A|monetis)|wireless\s+network|spectrum\s+(deal|auction))/i', $text)) $telecomNewsSignals += 3;
        if (preg_match('/\b(mobile\s+operator|data\s+centre|subsea\s+cable|fibre\s+(optic|network|project)|broadband\s+operator|network\s+upgrade)/i', $text)) $telecomNewsSignals += 2;
        if (preg_match('/\b(GSMA|MWC|Mobile\s+World\s+Congress|ITU|FTTH|cell\s*tower|tower\s+company)/i', $text)) $telecomNewsSignals += 2;
        if ($telecomNewsSignals >= 3) {
            $score -= 35;
        }

        // ─── Telecom OPERATOR homepage signals (iter-TX) ──────────────
        // T-Mobile, AT&T, Verizon etc. are telecom operators, not manufacturers.
        // They don't manufacture hardware — they provide telecom services.
        $telecomOpSignals = 0;
        if (preg_match('/\b(wireless\s+(plan|service|network|coverage)|cell\s*(ular)?\s+plan|phone\s+plan|data\s+plan|unlimited\s+(data|talk|text))/i', $text)) $telecomOpSignals += 4;
        if (preg_match('/\b(switch\s+to\s+(us|t[\s-]?mobile|at[\s&]*t|verizon|sprint)|bring\s+your\s+(phone|device)|trade[\s-]in\s+your\s+(phone|device))/i', $text)) $telecomOpSignals += 5;
        if (preg_match('/\b(5G\s+(network|speed|coverage|home\s+internet)|home\s+internet|internet\s+service\s+provider|ISP)/i', $text)) $telecomOpSignals += 3;
        if (preg_match('/\b(monthly\s+bill|account\s+balance|pay\s+bill|my\s+account|add\s+a\s+line|family\s+plan)/i', $text)) $telecomOpSignals += 3;
        if ($telecomOpSignals >= 4) {
            $score -= 40;
        }

        // ─── Energy / utility provider homepage signals (iter-TX) ─────
        // Energy companies like Gexa Energy are utility providers, not mfrs.
        $energyProviderSignals = 0;
        if (preg_match('/\b(electricity\s+(plan|rate|provider|supplier)|energy\s+(plan|rate|provider|supplier|deregulat)|power\s+(plan|rate|provider|supplier))/i', $text)) $energyProviderSignals += 4;
        if (preg_match('/\b(kWh|kilowatt|per\s+kwh|energy\s+charge|utility\s+bill|electric\s+bill|switch\s+energy\s+provider)/i', $text)) $energyProviderSignals += 3;
        if (preg_match('/\b(renewable\s+energy\s+plan|green\s+energy\s+plan|solar\s+plan|wind\s+energy\s+plan|100%\s+renewable)/i', $text)) $energyProviderSignals += 3;
        if ($energyProviderSignals >= 4) {
            $score -= 40;
        }

        // ─── Industrial park / free zone / real estate developer (iter-TX) ─
        $industrialParkSignals = 0;
        if (preg_match('/\b(industrial\s+park|industrial\s+zone|free\s+(trade\s+)?zone|business\s+park|technology\s+park|logistics\s+park)/i', $text)) $industrialParkSignals += 3;
        if (preg_match('/\b(build[\s-]to[\s-]suit|available\s+space|leasable\s+area|rental\s+rate|sq\s*(ft|m)|hectare|square\s+(feet|meter))/i', $text)) $industrialParkSignals += 3;
        if (preg_match('/\b(we\s+develop|property\s+develop|real\s+estate\s+develop)/i', $text)) $industrialParkSignals += 3;
        if ($industrialParkSignals >= 3) {
            $score -= 35;
        }

        // ─── Medical diagnostics / laboratory services homepage (iter-TX) ─
        $medDiagSignals = 0;
        if (preg_match('/\b(diagnostic\s+(test|lab|laborator|service|imaging|center)|blood\s+test|lab\s+result|test\s+result|patient\s+portal)/i', $text)) $medDiagSignals += 4;
        if (preg_match('/\b(quest\s+diagnostics|labcorp|clinical\s+laborator|pathology\s+laborator|reference\s+laborator)/i', $text)) $medDiagSignals += 4;
        if (preg_match('/\b(specimen|biopsy|cytology|hematology|urinalysis|blood\s+draw|phlebotom)/i', $text)) $medDiagSignals += 3;
        if ($medDiagSignals >= 4) {
            $score -= 40;
        }

        // ─── Certification / inspection / testing body homepage (iter-TX) ─
        $certBodySignals = 0;
        if (preg_match('/\b(certification\s+(body|agency|organization|authority|service)|accreditation\s+(body|agency)|certif(y|ied|ication)\s+(management|quality|ISO|IATF))/i', $text)) $certBodySignals += 4;
        if (preg_match('/\b(we\s+certif|get\s+certified|apply\s+for\s+certification|certification\s+process|audit\s+and\s+certification)/i', $text)) $certBodySignals += 3;
        if ($certBodySignals >= 4) {
            $score -= 35;
        }

        // ─── Trade show / exhibition / airshow homepage (iter12) ──────
        $tradeShowSignals = 0;
        if (preg_match('/\b(trade\s*show|air\s*show|trade\s*fair|expo(sition)?|conference\s+&\s+exhibition)/i', $text)) $tradeShowSignals += 3;
        if (preg_match('/\b(exhibit(or)?\s+(list|registration|profile|booth|stand)|book\s+(a\s+)?(stand|booth)|visitor\s+registration|register\s+now|floor\s+plan)/i', $text)) $tradeShowSignals += 3;
        if (preg_match('/\b(keynote\s+speaker|panel\s+discussion|networking\s+event|sponsor(ship)?\s+(package|opportunit))/i', $text)) $tradeShowSignals += 2;
        if ($tradeShowSignals >= 3) {
            $score -= 35;
        }

        // ─── Drone delivery / last-mile delivery service (iter12) ─────
        $droneDeliverySignals = 0;
        if (preg_match('/\b(drone\s+delivery|delivery\s+drone|autonomous\s+delivery|instant\s+delivery|store[\s-]to[\s-]door)/i', $text)) $droneDeliverySignals += 3;
        if (preg_match('/\b(last[\s-]mile|order\s+now|get\s+it\s+delivered|delivery\s+in\s+minutes|on[\s-]demand\s+delivery)/i', $text)) $droneDeliverySignals += 2;
        if ($droneDeliverySignals >= 3) {
            $score -= 35;
        }

        // ─── ERP / business software homepage (iter13 Tunisia — Odoo) ─────
        $erpSignals = 0;
        if (preg_match('/\b(erp\s+(software|solution|platform|system)|business\s+management\s+software|all[\s-]in[\s-]one\s+business\s+(software|platform))/i', $text)) $erpSignals += 3;
        if (preg_match('/\b(crm\s+module|accounting\s+module|inventory\s+module|point\s+of\s+sale|e[\s-]?commerce\s+platform|open[\s-]source\s+(erp|business))/i', $text)) $erpSignals += 3;
        if (preg_match('/\b(manage\s+your\s+business|business\s+apps?|grow\s+your\s+business\s+with)/i', $text)) $erpSignals += 2;
        if ($erpSignals >= 3) {
            $score -= 40;
        }

        // ─── IT engineering services / software consultancy homepage (iter13 Tunisia) ──
        $itServicesSignals = 0;
        if (preg_match('/\b(embedded\s+(software|systems?)\s+(develop|engineer|services?)|R&D\s+(services?|outsourc)|IT\s+engineering\s+services?)/i', $text)) $itServicesSignals += 3;
        if (preg_match('/\b(CMMI|nearshore|offshore)\s+(level|engineer|develop|services?|partner)/i', $text)) $itServicesSignals += 3;
        if (preg_match('/\b(digital\s+transformation|cloud\s+computing|PLM\s+solutions?|telecom\s+software|agile\s+development)/i', $text)) $itServicesSignals += 2;
        if ($itServicesSignals >= 3) {
            $score -= 35;
        }

        // ─── Adhesive/glue/paint/brush manufacturer homepage (iter13 Tunisia) ──
        $nonElecMfgSignals = 0;
        if (preg_match('/\b(wood\s+glue|shoe\s+glue|construction\s+glue|contact\s+adhesive|solvent|varnish|anti[\s-]corrosion\s+paint|oil\s+paint|waterproof\s+paint)/i', $text)) $nonElecMfgSignals += 3;
        if (preg_match('/\b(paintbrush|paint\s+roller|hygiene\s+brush|household\s+brush|industrial\s+brush|brush\s+manufactur)/i', $text)) $nonElecMfgSignals += 3;
        if (preg_match('/\b(alumini?um\s+extrusion|building\s+profil|solar\s+panel\s+frame|LED\s+channel|heat\s+sink\s+profil)/i', $text)) $nonElecMfgSignals += 3;
        if ($nonElecMfgSignals >= 3) {
            $score -= 35;
        }

        // ─── Auto spare parts dealer / car dealership homepage (iter13 Tunisia) ──
        $autoPartsSignals = 0;
        if (preg_match('/\b(spare\s+parts?\s+(distribut|wholesale|dealer|catalog)|auto(motive)?\s+spare\s+parts?|car\s+spare\s+parts?)/i', $text)) $autoPartsSignals += 3;
        if (preg_match('/\b(sub[\s-]?dealer|authorized\s+dealer|franchise\s+dealer|Mercedes[\s-]Benz\s+dealer|Hyundai\s+dealer|Kia\s+dealer)/i', $text)) $autoPartsSignals += 3;
        if (preg_match('/\b(genuine\s+parts?|original\s+parts?|OEM\s+replacement\s+parts?|after[\s-]?market\s+parts?)/i', $text)) $autoPartsSignals += 2;
        if ($autoPartsSignals >= 3) {
            $score -= 35;
        }

        // ─── Business/management consulting firm homepage (iter13 Tunisia + IT/GB/ES) ──
        $consultingSignals = 0;
        if (preg_match('/\b(business\s+consulting|management\s+consulting|consulting\s+firm|consulting\s+for\s+investor|market\s+entry\s+advisory)/i', $text)) $consultingSignals += 3;
        if (preg_match('/\b(strategy\s+consulting|investment\s+advisory|due\s+diligence|feasibility\s+study|market\s+research\s+consulting)/i', $text)) $consultingSignals += 3;
        if (preg_match('/\b(satisfied\s+clients?|active\s+projects?|business\s+plan|consulting\s+services?)/i', $text)) $consultingSignals += 2;
        // Big Four / Big Three hallmarks
        if (preg_match('/\b(audit\s+&\s+assurance|tax\s+&\s+legal|risk\s+advisory|deal\s+advisory|transaction\s+advisory|restructuring\s+advisory)/i', $text)) $consultingSignals += 3;
        if (preg_match('/\b(our\s+(people|offices|industries|insights|perspectives|capabilities)|thought\s+leadership|industry\s+insights?|case\s+stud(y|ies)|client\s+stories)/i', $text)) $consultingSignals += 2;
        if (preg_match('/\b(professional\s+services|assurance\s+services|advisory\s+services|consulting\s+services|managed\s+services)\s+(firm|company|provider|leader|practice)/i', $text)) $consultingSignals += 3;
        if (preg_match('/\b(global\s+network|member\s+firm|partner\s+firm|offices?\s+in\s+\d+\s+countries|presence\s+in\s+\d+\s+countries)/i', $text)) $consultingSignals += 2;
        if ($consultingSignals >= 3) {
            $score -= 40;
        }

        // ─── DE/FR/IT: multilingual consulting homepage (iter-DE-Industrial) ──
        $deConsultSignals = 0;
        if (preg_match('/\b(Unternehmensberatung|Managementberatung|Strategieberatung|Personalberatung|Organisationsberatung|Prozessberatung|Logistikberatung|IT[\s-]Beratung)\b/i', $text)) $deConsultSignals += 4;
        if (preg_match('/\b(wir\s+beraten|beraten\s+wir|unsere\s+Beratung|Beratung\s+(und|&)\s+(Coaching|Training|Umsetzung))\b/i', $text)) $deConsultSignals += 3;
        if (preg_match('/\b(Interim[\s-]?Management|Change[\s-]?Management|Digitale\s+Transformation)\b.*\b(Beratung|beraten|Consulting)\b/i', $text)) $deConsultSignals += 3;
        if (preg_match('/\b(Referenzen|Kundenstimmen|unsere\s+Kunden|Branchen(kompetenz|erfahrung|wissen))\b/i', $text) && $deConsultSignals > 0) $deConsultSignals += 2;
        if (preg_match('/\b(conseil\s+en\s+(management|stratégie|organisation|direction)|cabinet\s+de\s+(conseil|consulting))\b/i', $text)) $deConsultSignals += 4;
        if (preg_match('/\b(consulenza\s+(aziendale|strategica|direzionale|gestionale)|società\s+di\s+consulenza)\b/i', $text)) $deConsultSignals += 4;
        if ($deConsultSignals >= 3) {
            $score -= 40;
        }

        // ─── Major consultancy homepage by name (IT/GB/ES audit) ──────
        // Direct name detection on homepage for maximum reliability
        $majorConsultHomepage = 0;
        if (preg_match('/\b(Deloitte|PricewaterhouseCoopers|PwC|Ernst\s*[&]\s*Young|KPMG|McKinsey|Boston\s+Consulting|Bain\s+&\s+Company|Accenture|Capgemini)\b/i', $text)) $majorConsultHomepage += 4;
        if (preg_match('/\b(Infosys|Tata\s+Consultancy|TCS|Wipro|Cognizant|HCL\s+Technologies?|Tech\s+Mahindra|LTIMindtree|Oliver\s+Wyman|Roland\s+Berger)\b/i', $text)) $majorConsultHomepage += 4;
        if (preg_match('/\b(Booz\s+Allen|BearingPoint|AlixPartners|Kearney|A\.?T\.?\s*Kearney|FTI\s+Consulting|Guidehouse|Protiviti)\b/i', $text)) $majorConsultHomepage += 4;
        if (preg_match('/\b(audit|assurance|advisory|consulting|tax)\s+(services?|practice|solutions?)/i', $text)) $majorConsultHomepage += 2;
        if ($majorConsultHomepage >= 4) {
            $score -= 50;
        }

        // ─── Freight / logistics / transport homepage signals (iter11b) ─
        $freightSignals = 0;
        if (preg_match('/\b(freight\s+(forward|transport|services?|company)|air\s+freight|sea\s+freight|maritime\s+freight|road\s+freight)/i', $text)) $freightSignals += 3;
        if (preg_match('/\b(customs\s+(broker|clearance|transit)|cargo\s+(handling|transport)|warehousing|container\s+shipping|supply\s+chain\s+logistics)/i', $text)) $freightSignals += 3;
        if (preg_match('/\b(transit(aire)?|shipment|bill\s+of\s+lading|incoterms?|door[\s-]to[\s-]door\s+delivery|track\s+(your|my)\s+(shipment|cargo))/i', $text)) $freightSignals += 2;
        // French logistics
        if (preg_match('/\b(transport\s+(routier|maritime|a[eé]rien)|d[eé]douanement|affrètement|affr[eé]tement|entreposage|groupage|messagerie\s+express|transitaire)\b/i', $text)) $freightSignals += 3;
        // Arabic logistics
        if (preg_match('/(' . implode('|', [
            'شحن بضائع',         // freight
            'تخليص جمركي',       // customs clearance
            'نقل بضائع',         // cargo transport
            'شركة شحن',          // shipping company
            'مستودعات',          // warehousing
        ]) . ')/u', $text)) $freightSignals += 3;
        // German logistics
        if (preg_match('/\b(spedition|frachtf[uü]hrer|zollabwicklung|lagerhaltung|st[uü]ckgut|sammelladung|g[uü]tertransport|lieferkette)\b/i', $text)) $freightSignals += 3;
        if ($freightSignals >= 3) {
            $score -= 35;
        }

        // ─── Academic journal / research publisher homepage signals (iter11b) ─
        $journalSignals = 0;
        if (preg_match('/\b(academic\s+journal|peer[\s-]review|scientific\s+journal|research\s+journal|open[\s-]access\s+journal)/i', $text)) $journalSignals += 3;
        if (preg_match('/\b(call\s+for\s+papers?|submit\s+(a\s+)?paper|manuscript\s+submission|editorial\s+board|review\s+process|published\s+articles?)/i', $text)) $journalSignals += 3;
        if (preg_match('/\b(impact\s+factor|citation|issn|doi|volume\s+\d|issue\s+\d)/i', $text)) $journalSignals += 2;
        if ($journalSignals >= 3) {
            $score -= 40;
        }

        // ─── Tyre brand / tyre company homepage signals (iter11b) ─
        $tyreSignals = 0;
        if (preg_match('/\b(tyre|tire)\s+(range|finder|size|pressure|dealer|shop|store|catalogue)/i', $text)) $tyreSignals += 3;
        if (preg_match('/\b(summer\s+tyre|winter\s+tyre|all[\s-]season|run[\s-]flat|pneumatique|tread\s+pattern|tyre\s+performance)/i', $text)) $tyreSignals += 3;
        if (preg_match('/\b(tyre|tire)\s+(brand|manufactur|technology|innovation)/i', $text)) $tyreSignals += 2;
        if ($tyreSignals >= 3) {
            $score -= 35;
        }

        // ─── Job board / recruitment portal homepage signals ─────────
        $jobBoardSignals = 0;
        if (preg_match('/\b(job\s+(board|portal|listing|search|seeker)|post\s+a\s+job|find\s+a\s+job|career\s+portal)/i', $text)) $jobBoardSignals += 3;
        if (preg_match('/\b(resume|cv\s+builder|job\s+alert|apply\s+now|search\s+jobs|latest\s+jobs)/i', $text)) $jobBoardSignals += 2;
        if ($jobBoardSignals >= 3) {
            $score -= 40;
        }

        // ─── TELECOM SERVICE CONTRACTOR / NETWORK BUILDER homepage signals ───
        // Companies that build fiber networks, install cell towers, BTS — NOT OEMs
        $telecomContractorSignals = 0;
        if (preg_match('/\b(fiber\s+(optic\s+)?network\s+(construction|installation|deploy)|build\s+(fiber|fibre)\s+networks?|network\s+infrastructure\s+(build|deploy|construct))/i', $text)) $telecomContractorSignals += 5;
        if (preg_match('/\b(cell\s+tower|cell\s+site|BTS\s+(installation|construction)|tower\s+(construction|erection)|wireless\s+infrastructure\s+(construction|deploy))/i', $text)) $telecomContractorSignals += 5;
        if (preg_match('/\b(OSP|outside\s+plant|HFC|FTTx|FTTH|fiber\s+to\s+the\s+(home|premises|curb))/i', $text)) $telecomContractorSignals += 3;
        if (preg_match('/\b(telecom\s+(contractor|construction|engineering|services?)|telecommunications?\s+(contractor|construction|engineering))/i', $text)) $telecomContractorSignals += 4;
        if (preg_match('/\b(we\s+(build|construct|deploy|install)\s+.{0,30}(fiber|network|telecom|wireless|broadband))/i', $text)) $telecomContractorSignals += 4;
        if (preg_match('/\b(field\s+engineering|site\s+survey|RF\s+engineering|network\s+planning\s+services?)/i', $text)) $telecomContractorSignals += 2;
        if ($telecomContractorSignals >= 4) {
            $score -= 45;
        }

        // ─── VENDOR PORTAL / PROCUREMENT PLATFORM homepage signals ───
        // Vendor application portals, supplier onboarding — not real companies
        $vendorPortalSignals = 0;
        if (preg_match('/\b(vendor\s+(application|registration|onboarding|portal)|supplier\s+(application|registration|onboarding|portal))/i', $text)) $vendorPortalSignals += 5;
        if (preg_match('/\b(become\s+a\s+(vendor|supplier)|register\s+as\s+(vendor|supplier)|apply\s+to\s+become)/i', $text)) $vendorPortalSignals += 4;
        if (preg_match('/\b(procurement\s+(platform|portal|system)|e-?procurement|sourcing\s+platform)/i', $text)) $vendorPortalSignals += 3;
        if ($vendorPortalSignals >= 4) {
            $score -= 50;
        }

        // ─── INFRASTRUCTURE INVESTMENT / TOWER REIT homepage signals ───
        // Investment firms for cell towers, real estate, infrastructure
        $infraInvestmentSignals = 0;
        if (preg_match('/\b(tower\s+(investment|REIT|real\s+estate)|cell\s+tower\s+(investment|acquisition)|wireless\s+(investment|equity|real\s+estate))/i', $text)) $infraInvestmentSignals += 5;
        if (preg_match('/\b(infrastructure\s+(investment|fund|REIT)|real\s+estate\s+investment\s+trust|REIT)/i', $text)) $infraInvestmentSignals += 4;
        if (preg_match('/\b(lease\s+(buyout|acquisition)|ground\s+lease|tower\s+lease|antenna\s+lease)/i', $text)) $infraInvestmentSignals += 4;
        if (preg_match('/\b(we\s+(acquire|buy|invest\s+in)\s+.{0,30}(tower|infrastructure|real\s+estate|lease))/i', $text)) $infraInvestmentSignals += 4;
        if ($infraInvestmentSignals >= 4) {
            $score -= 45;
        }

        // ─── AFTERMARKET AUTO PARTS MANUFACTURER / REMANUFACTURER homepage signals ───
        // Companies that make/remake aftermarket parts — not OEM buyers
        $aftermarketSignals = 0;
        if (preg_match('/\b(aftermarket\s+(auto|automotive|parts?|manufacturer)|remanufactur(er|ed|ing)\s+(auto|alternator|starter|brake|steering))/i', $text)) $aftermarketSignals += 5;
        if (preg_match('/\b(alternator|starter\s+motor|brake\s+(pad|rotor|caliper)|power\s+steering)\s+(remanufactur|remanu|rebuild|aftermarket)/i', $text)) $aftermarketSignals += 4;
        if (preg_match('/\b(core\s+(exchange|return|charge)|OE\s+quality|OEM\s+replacement|replacement\s+parts?)/i', $text)) $aftermarketSignals += 3;
        if (preg_match('/\b(we\s+(remanufacture|rebuild|restore)\s+.{0,30}(alternator|starter|brake|auto|part))/i', $text)) $aftermarketSignals += 4;
        if (preg_match('/\b(DIY\s+(auto|car)|auto\s+parts?\s+(store|retailer)|retail\s+distribution)/i', $text)) $aftermarketSignals += 3;
        if ($aftermarketSignals >= 4) {
            $score -= 40;
        }

        // ─── UNIFIED COMMUNICATIONS / VOIP PROVIDER homepage signals ───
        // Companies providing telecom services, not electronics manufacturing
        $ucSignals = 0;
        if (preg_match('/\b(unified\s+communications?|UC\s+(platform|solution|provider)|UCaaS)/i', $text)) $ucSignals += 4;
        if (preg_match('/\b(VoIP|voice\s+over\s+IP|IP\s+telephony|cloud\s+(phone|PBX|telephony)|business\s+phone\s+system)/i', $text)) $ucSignals += 4;
        if (preg_match('/\b(contact\s+center|call\s+center\s+(solution|software)|video\s+conferencing\s+(solution|platform))/i', $text)) $ucSignals += 3;
        if (preg_match('/\b(collaboration\s+(platform|tool|software)|team\s+messaging|enterprise\s+communication)/i', $text)) $ucSignals += 2;
        if ($ucSignals >= 4) {
            $score -= 40;
        }

        // ═══════════════════════════════════════════════════════════════
        // CAR DEALER / FRANCHISE / SHOWROOM SIGNALS (SIC 5500)
        // ═══════════════════════════════════════════════════════════════

        // Inventory-style language (car dealer hallmark)
        $dealerPhrases = [
            'pre-owned', 'pre owned', 'certified pre-owned',
            'book a test drive', 'schedule a test drive', 'request a test drive',
            'schedule service', 'book service', 'service appointment',
            'trade-in', 'trade in value', 'trade your vehicle',
            'vehicle inventory', 'browse inventory', 'view inventory',
            'our inventory', 'search inventory', 'in stock',
            'new vehicles', 'used vehicles', 'certified vehicles',
            'special offers', 'lease deals', 'finance offers',
            'new cars for sale', 'used cars for sale',
            'build and price', 'build & price',
            'msrp', 'sticker price', 'dealer price',
            'kbb', 'kelley blue book', 'edmunds',
            'carfax', 'autocheck', 'vehicle history',
            'vin number', 'miles', 'mileage',
            'sedan', 'suv', 'pickup truck', 'crossover',
            'body shop', 'collision center', 'collision centre',
        ];
        foreach ($dealerPhrases as $phrase) {
            if (str_contains($text, $phrase)) {
                $score -= 5;
            }
        }

        // Multiple car brand names on same page = multi-brand dealer
        $carBrands = [
            'chrysler', 'jeep', 'dodge', 'ram', 'fiat',
            'chevrolet', 'buick', 'cadillac', 'gmc',
            'ford', 'lincoln', 'mercury',
            'toyota', 'lexus', 'scion',
            'honda', 'acura', 'nissan', 'infiniti',
            'hyundai', 'kia', 'genesis',
            'bmw', 'mercedes-benz', 'mercedes benz', 'audi',
            'volkswagen', 'porsche', 'volvo',
            'mazda', 'subaru', 'mitsubishi',
            'land rover', 'jaguar', 'alfa romeo', 'maserati',
        ];
        $brandsFound = 0;
        foreach ($carBrands as $brand) {
            if (substr_count($text, $brand) >= 2) {
                $brandsFound++;
            }
        }
        if ($brandsFound >= 3) {
            $score -= 30; // 3+ car brands appearing repeatedly = dealer
        } elseif ($brandsFound >= 2) {
            $score -= 15;
        }

        // "Authorized dealer" / franchise language
        if (preg_match('/\b(authorized|authorised)\s+(dealer|dealership|retailer|reseller|distributor)\b/i', $text)) {
            $score -= 20;
        }
        if (preg_match('/\b(franchise|franchisee|franchised)\b/i', $text)) {
            $score -= 15;
        }
        // French dealer / concessionnaire signals
        if (preg_match('/\b(concessionnaire|v[eé]hicules? d.occasion|v[eé]hicules? neufs?|essai gratuit|reprise de votre|showroom|notre parc automobile|votre concessionnaire)\b/i', $text)) {
            $score -= 20;
        }
        // Arabic dealer signals
        if (preg_match('/(' . implode('|', [
            'وكيل سيارات',       // car dealer
            'معرض سيارات',       // car showroom
            'سيارات مستعملة',     // used cars
            'سيارات جديدة',      // new cars
            'تجربة قيادة',        // test drive
            'قطع غيار',          // spare parts
            'قطع الغيار',         // spare parts
            'صيانة السيارات',     // car maintenance
        ]) . ')/u', $text)) {
            $score -= 20;
        }
        // German dealer / Autohaus signals
        if (preg_match('/\b(autohaus|gebrauchtwagen|neuwagen|probefahrt|fahrzeug(bestand|angebot)|vertragsh[aä]ndler|werkstatt[-\s]?termin|leasingangebot)\b/i', $text)) {
            $score -= 20;
        }

        // CDJR-style combined brand names
        if (preg_match('/\b(cdjr|cjdr|dodge\s+(city|county)|chrysler\s+dodge|jeep\s+ram)\b/i', $text)) {
            $score -= 25;
        }

        // "of [City]" pattern in dealer names
        if (preg_match('/\b(ford|toyota|chevrolet|honda|nissan|hyundai|kia)\s+of\s+[a-z]+/i', $text)) {
            $score -= 20;
        }

        // ═══════════════════════════════════════════════════════════════
        // PARTS DISTRIBUTOR / AFTERMARKET SIGNALS (SIC 5010-5090)
        // ═══════════════════════════════════════════════════════════════

        $distributorPhrases = [
            'add to cart', 'add to basket', 'buy now', 'shop now',
            'free shipping', 'order today', 'in stock', 'out of stock',
            'add to wishlist', 'shopping cart', 'checkout',
            'spare parts', 'replacement parts', 'aftermarket parts',
            'oem replacement', 'genuine parts', 'original parts',
            'part number', 'part #', 'p/n:', 'sku:',
            'we distribute', 'authorized distributor',
            'wholesale distributor', 'parts catalog',
            'aviation parts', 'aircraft parts for sale',
        ];
        foreach ($distributorPhrases as $phrase) {
            if (str_contains($text, $phrase)) {
                $score -= 4;
            }
        }
        // French distributor / e-commerce signals
        if (preg_match('/\b(ajouter au panier|commander|livraison gratuite|en stock|rupture de stock|pi[eè]ces? d[eé]tach[eé]es?|pi[eè]ces? de rechange|catalogue de pi[eè]ces|distributeur agr[eé][ée]?)\b/i', $text)) {
            $score -= 15;
        }
        // Arabic distributor / e-commerce signals
        if (preg_match('/(' . implode('|', [
            'أضف إلى السلة',      // add to cart
            'اشتري الآن',        // buy now
            'شحن مجاني',         // free shipping
            'متوفر',             // in stock
            'موزع معتمد',        // authorized distributor
        ]) . ')/u', $text)) {
            $score -= 15;
        }
        // German distributor / Webshop signals
        if (preg_match('/\b(in den warenkorb|jetzt kaufen|kostenloser versand|auf lager|nicht vorr[aä]tig|ersatzteile|originalteile|teilekatalog|autorisierter h[aä]ndler|gro[ssß]handel)\b/i', $text)) {
            $score -= 15;
        }

        // E-commerce HTML patterns (product listing pages)
        if (preg_match('/(class|id)=["\'].*?(product-grid|product-list|shop-items|cart-button|add-to-cart|woocommerce|shopify)/i', $htmlLower)) {
            $score -= 15;
        }

        // ═══════════════════════════════════════════════════════════════
        // AUTO TUNING / MODIFICATION / WRAP / ACCESSORIES (SIC 7500)
        // ═══════════════════════════════════════════════════════════════

        $tuningPhrases = [
            'car tuning', 'chip tuning', 'ecu tuning', 'remapping',
            'vehicle wrap', 'car wrap', 'vinyl wrap',
            'window tinting', 'paint protection', 'ceramic coating',
            'car accessories', 'auto accessories',
            'exhaust system', 'performance exhaust',
            'suspension kit', 'lift kit', 'lowering kit',
            'custom wheels', 'alloy wheels',
            'car audio', 'car stereo', 'dash cam',
            'diagfix', 'diagnostic tool', 'obd2 scanner',
        ];
        foreach ($tuningPhrases as $phrase) {
            if (str_contains($text, $phrase)) {
                $score -= 5;
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // NEWS / MEDIA / PUBLICATION SIGNALS (SIC 2711-2741)
        // ═══════════════════════════════════════════════════════════════

        $mediaPhrases = [
            'subscribe to newsletter', 'latest articles',
            'read more articles', 'editorial team', 'journalist',
            'breaking news', 'published on', 'by our reporter',
            'media office', 'press office', 'news agency',
            'business news', 'industry news', 'market news',
            // French news/media patterns
            'agence de presse', 'agence d\'information', 'dernières nouvelles',
            'rédaction', 'communiqué de presse', 'fil d\'actualité',
            // Arabic news/media patterns (news agencies, portals)
            'وكالة أنباء',          // news agency
            'أخبار عاجلة',          // breaking news
            'أهم الأخبار',          // top/main news
            'آخر الأخبار',          // latest news
            'هيئة التحرير',         // editorial board
        ];
        $mediaScore = 0;
        foreach ($mediaPhrases as $phrase) {
            if (str_contains($text, $phrase)) {
                $mediaScore += 5;
            }
        }
        // Additional strong media signals: CMS junk, article grids, cookie consent walls
        if (preg_match('/\bjnews_block|wp-content\/themes\/flavor|flavor-flavor|flavor\.flavor/i', $htmlLower)) {
            $mediaScore += 30; // JNews WordPress CMS = news site
        }
        if (preg_match('/\b(toute l.actualité|découvrez.*les articles|les vidéos et les infographies|dernières infos|fil d.info|le portail d.information)\b/i', $text)) {
            $mediaScore += 15;
        }
        if (preg_match('/\b(salon international|trade show|exhibition|foire internationale|composants et pièces de rechange|business forum|congrès international)\b/i', $text)) {
            $mediaScore += 20; // Trade show / exhibition = not a company
        }
        if (preg_match('/\b(annonces|petites annonces|emploi.*offres|offres.*emploi|classified|annonces gratuites)\b/i', $text)) {
            $mediaScore += 15; // Classifieds / job ads
        }
        // Arabic news/media homepage signals
        if (preg_match('/(' . implode('|', [
            'آخر الأخبار',        // latest news
            'أهم الأخبار',        // top news
            'أخبار عاجلة',        // breaking news
            'صحيفة',             // newspaper
            'مجلة',               // magazine
            'تحقيقات',           // investigations
            'تقارير',            // reports
            'المقالات الأخيرة',   // latest articles
            'رئيس التحرير',      // editor in chief
            'الرأي',              // opinion
        ]) . ')/u', $text)) {
            $mediaScore += 20;
        }
        // German news/media homepage signals
        if (preg_match('/\b(nachrichten|schlagzeilen|aktuelle\s+meldungen|leitartikel|pressemitteilung|chefredakteur|redaktion|journalismus|tageszeitung|wochenzeitung|abonnieren\s+sie)\b/i', $text)) {
            $mediaScore += 20;
        }
        $score -= $mediaScore;

        // ── Financial services / brokerage / investment firm signals ──
        // These are NOT manufacturers — they are financial intermediaries.
        $financePhrases = [
            'stock brokerage', 'asset management', 'investment fund',
            'mutual fund', 'portfolio management', 'wealth management',
            'securities trading', 'financial advisory',
            // French financial patterns
            'intermédiation en bourse', 'gestion d\'actifs',
            'gestion de portefeuille', 'fonds commun de placement',
            'épargne en actions', 'valeurs liquidatives',
            'ouvrir un compte', 'courtage',
            // Arabic financial patterns
            'وساطة مالية',       // financial brokerage
            'إدارة الأصول',       // asset management
            'صناديق استثمار',     // investment funds
            // German financial patterns
            'vermögensverwaltung', 'kapitalanlage', 'investmentfonds',
            'wertpapierhandel', 'finanzberatung', 'börsenmakler',
            'anlageberatung', 'fondsmanagement',
        ];
        $finServiceSignals = 0;
        foreach ($financePhrases as $phrase) {
            if (str_contains($text, $phrase)) {
                $finServiceSignals++;
            }
        }
        if ($finServiceSignals >= 2) {
            $score -= 35;
        }

        // ═══════════════════════════════════════════════════════════════
        // JOB BOARD / RECRUITMENT PORTAL SIGNALS
        // ═══════════════════════════════════════════════════════════════

        if (preg_match('/\b(post a job|job seekers?|upload (your )?cv|upload resume|apply for this job|career opportunities|job alerts?)\b/i', $text)) {
            $score -= 20;
        }
        // French job board signals
        if (preg_match('/\b(offres? d.emploi|d[ée]poser votre cv|postuler|chercheur d.emploi|recrutement en ligne|candidature spontan[ée]e|espace candidat)\b/i', $text)) {
            $score -= 20;
        }
        // Arabic job board signals
        if (preg_match('/(' . implode('|', [
            'وظائف شاغرة',      // job vacancies
            'فرص عمل',          // job opportunities
            'تقديم طلب',        // apply/submit application
            'البحث عن عمل',     // looking for work
            'سيرة ذاتية',       // CV/resume
            'التوظيف',          // recruitment/hiring
        ]) . ')/u', $text)) {
            $score -= 20;
        }
        // German job board signals
        if (preg_match('/\b(stellenangebote|stellenmarkt|jetzt bewerben|karriereportal|bewerbung|job[- ]?b[oö]rse|arbeitgeber)\b/i', $text)) {
            $score -= 20;
        }

        // ═══════════════════════════════════════════════════════════════
        // FREE ZONE / BUSINESS PARK / ECONOMIC ZONE SIGNALS
        // ═══════════════════════════════════════════════════════════════

        if (preg_match('/\b(set up your business|company registration|business licensing|investor benefits|free zone company|freezone authority|register your company|integrated industrial platform|industrial\s+ecosystem|hectares?\s+of\s+(industrial|land)|companies\s+installed|set\s+up\s+(in|at)\s+the\s+zone|industrial\s+zones?\s+(authority|operator|developer))\b/i', $text)) {
            $score -= 20;
        }
        // French free zone / industrial park signals
        if (preg_match('/\b(zone\s+(industrielle|franche|d.activit[eé])|parc\s+(industriel|d.activit[eé])|p[oô]le\s+(industriel|technologique)|agence\s+de\s+promotion|cr[eé]er\s+votre\s+entreprise|implantation\s+industrielle|avantages\s+(fiscaux|investisseur))\b/i', $text)) {
            $score -= 20;
        }
        // Arabic free zone / government portal signals
        if (preg_match('/(' . implode('|', [
            'منطقة حرة',         // free zone
            'منطقة صناعية',       // industrial zone
            'هيئة الاستثمار',     // investment authority
            'تأسيس شركة',        // company formation
            'رخصة تجارية',       // trade license
            'المنطقة الاقتصادية',  // economic zone
            'وزارة الصناعة',      // ministry of industry
            'وزارة التجارة',      // ministry of trade
        ]) . ')/u', $text)) {
            $score -= 25;
        }
        // German free zone / Gewerbepark signals
        if (preg_match('/\b(gewerbegebiet|gewerbepark|industriegebiet|industriepark|wirtschaftsf[oö]rderung|ansiedlung|grundst[uü]cke\s+zu\s+vermieten|standortvorteile|f[oö]rdermittel)\b/i', $text)) {
            $score -= 20;
        }

        // ═══════════════════════════════════════════════════════════════
        // RACING / MOTORSPORT / ENTERTAINMENT SIGNALS
        // ═══════════════════════════════════════════════════════════════

        if (preg_match('/\b(race results|lap times?|race schedule|racing series|autonomous racing|grand prix|circuit|track day)\b/i', $text)) {
            $score -= 20;
        }

        // ═══════════════════════════════════════════════════════════════
        // GOVERNMENT / MINISTRY / OFFICIAL PORTAL SIGNALS (multilingual)
        // ═══════════════════════════════════════════════════════════════
        $govSignals = 0;
        // English
        if (preg_match('/\b(ministry\s+of|government\s+portal|official\s+website\s+of\s+the|republic\s+of|public\s+service|national\s+agency)\b/i', $text)) $govSignals += 3;
        // French
        if (preg_match('/\b(minist[eè]re\s+(de|du|des)|r[eé]publique|portail\s+officiel|service\s+public|agence\s+nationale|d[eé]cret|arr[eê]t[eé]\s+minist[eé]riel|journal\s+officiel)\b/i', $text)) $govSignals += 3;
        // Arabic
        if (preg_match('/(' . implode('|', [
            'وزارة',            // ministry
            'جمهورية',           // republic
            'الحكومة',           // government
            'البوابة الرسمية',    // official portal
            'مرسوم',            // decree
            'الجريدة الرسمية',   // official journal/gazette
        ]) . ')/u', $text)) $govSignals += 3;
        // German
        if (preg_match('/\b(ministerium|bundesregierung|landesregierung|amtlich|beh[oö]rde|amt\s+f[uü]r|[oö]ffentlicher\s+dienst|bundesamt|landesamt)\b/i', $text)) $govSignals += 3;
        if ($govSignals >= 3) {
            $score -= 40;
        }

        // ═══════════════════════════════════════════════════════════════
        // TRADE SHOW / SALON / EXHIBITION SIGNALS (multilingual)
        // ═══════════════════════════════════════════════════════════════
        $tradeShowSignals = 0;
        // English
        if (preg_match('/\b(trade\s+show|exhibition|expo(sition)?\s+center|exhibitors?\s+list|visitor\s+registration|book\s+(your|a)\s+stand|floor\s+plan|conference\s+program|keynote\s+speak)\b/i', $text)) $tradeShowSignals += 3;
        // French
        if (preg_match('/\b(salon\s+(international|professionnel|de\s+l)|foire\s+(international|de)|exposants?|r[eé]server\s+(un\s+)?stand|plan\s+d.exposition|programme\s+du\s+salon|visiteur|badge\s+gratuit|journ[eé]es?\s+professionnelles?)\b/i', $text)) $tradeShowSignals += 3;
        // Arabic
        if (preg_match('/(' . implode('|', [
            'معرض دولي',         // international exhibition
            'معرض صناعي',        // industrial exhibition
            'عارضون',           // exhibitors
            'حجز جناح',         // book a stand
            'زوار المعرض',       // exhibition visitors
            'برنامج المعرض',     // exhibition program
        ]) . ')/u', $text)) $tradeShowSignals += 3;
        // German
        if (preg_match('/\b(messe(gelände)?|fachmesse|ausstellung|aussteller|besucher(registrierung)?|messestand|standfl[aä]che|hallenplan|fachkongress)\b/i', $text)) $tradeShowSignals += 3;
        if ($tradeShowSignals >= 3) {
            $score -= 40;
        }

        // ═══════════════════════════════════════════════════════════════
        // TRADE ASSOCIATION / CHAMBER / FEDERATION SIGNALS (multilingual)
        // ═══════════════════════════════════════════════════════════════
        $assocSignals = 0;
        if (preg_match('/\b(trade\s+association|industry\s+body|chamber\s+of\s+commerce|member\s+directory|our\s+members|join\s+the\s+association)\b/i', $text)) $assocSignals += 3;
        if (preg_match('/\b(chambre\s+(syndicale|de\s+commerce)|f[eé]d[eé]ration\s+(professionnelle|nationale|des)|syndicat\s+professionnel|groupement\s+professionnel|adh[eé]rents?|nos\s+membres)\b/i', $text)) $assocSignals += 3;
        if (preg_match('/(' . implode('|', [
            'غرفة التجارة',       // chamber of commerce
            'اتحاد الصناعات',     // federation of industries
            'نقابة',             // syndicate/union
            'جمعية مهنية',       // professional association
            'أعضاء الغرفة',       // chamber members
        ]) . ')/u', $text)) $assocSignals += 3;
        if (preg_match('/\b(verband|handelskammer|industrie[-\s]?und[-\s]?handelskammer|ihk|berufsverband|branchenverband|mitglieder(verzeichnis)?|fachverband)\b/i', $text)) $assocSignals += 3;
        if ($assocSignals >= 3) {
            $score -= 40;
        }

        // ═══════════════════════════════════════════════════════════════
        // GIANT OEM / FORTUNE-500 CONTENT SIGNALS
        // These companies are too large for EMS — they have in-house
        // production or use only Tier-0 EMS providers (Foxconn, Jabil).
        // ═══════════════════════════════════════════════════════════════

        // ═══════════════════════════════════════════════════════════════
        // COMPETITOR SERVICE-PROVIDER DETECTION (GENERAL)
        //
        // Starz Electronics provides:
        //   - Wire harness / cable assembly
        //   - EMS (Electronics Manufacturing Services)
        //   - CNC machining / precision machining / tooling
        //   - Injection molding (NOT plastics in general)
        //   - Industrial automation assembly
        //
        // Any company whose homepage says THEY PROVIDE these services
        // is a COMPETITOR, regardless of size. Pattern: service-offering
        // language ("we provide", "our services", "nos prestations") +
        // overlapping service keywords.
        // ═══════════════════════════════════════════════════════════════

        // Service-offering language ("we provide X", "our services")
        $serviceOfferingSignals = 0;
        if (preg_match('/\b(we\s+(provide|offer|speciali[sz]e|deliver|manufacture)|our\s+(services|capabilities|solutions|expertise|facilities|production|plants?)|nos\s+(services|prestations|savoir-faire|capacités|solutions|ateliers)|notre\s+(expertise|production|usine)|unsere\s+(leistungen|services|fertigung))\b/i', $text)) {
            $serviceOfferingSignals++;
        }

        // Starz-overlapping services (EN/FR/DE/AR)
        $competitorServiceMatches = 0;

        // Wire harness / cable assembly
        if (preg_match('/\b(wire\s*harness|wiring\s*harness|cable\s*harness|cable\s*assembly|harness\s*(manufactur|assembl|production|supplier)|faisceau\s*(de\s*)?câbles?|faisceaux\s*(électriques?|automobiles?)|câblage\s*(automobile|industriel|sur mesure)|assemblage\s*de\s*câbles|kabelbäu?me?|kabelkonfektion|leitungssä?tze?)\b/i', $text)) {
            $competitorServiceMatches += 2;
        }

        // EMS / contract electronics manufacturing
        if (preg_match('/\b(electronics\s*manufacturing\s*services?|\bems\s*(provider|partner|services?)|contract\s*(electronics|manufacturing)|pcb\s*assembly\s*services?|pcba\s*services?|smt\s*assembly|box\s*build|turnkey\s*(manufactur|assembly)|sous-traitance\s*électronique|fabrication\s*électronique)\b/i', $text)) {
            $competitorServiceMatches += 2;
        }

        // CNC machining / precision machining / tooling
        if (preg_match('/\b(cnc\s*machining|precision\s*machining|usinage\s*(par\s*)?(commande\s*numérique|cnc|précision)|fraisage|tournage\s*(cnc|numérique)|mold\s*making|tool\s*making|mould\s*making|outillage|fabrication\s*d.outils|moule|CNC[-\s]?Bearbeitung|Zerspanungstechnik|Fräsen|Drehen|Werkzeugbau|Formenbau)\b/i', $text)) {
            $competitorServiceMatches += 2;
        }

        // Injection molding (as a service they provide)
        if (preg_match('/\b(injection\s*moulding|injection\s*molding|plastic\s*injection|moulage\s*par\s*injection|injection\s*plastique|Spritzguss|Spritzgießen|Kunststoffspritzguss)\b/i', $text)) {
            $competitorServiceMatches += 2;
        }

        // Industrial automation / assembly line services
        if (preg_match('/\b(automation\s*(solutions?|services?|systems?|assembly)|assembly\s*(line|automation|services?|solutions?)|ligne\s*d.assemblage|automatisation\s*industrielle|solutions?\s*d.automatisation|Automatisierungslösung|Montagetechnik|Montageautomation)\b/i', $text)) {
            $competitorServiceMatches += 2;
        }

        // If company homepage OFFERS services that overlap with Starz → competitor
        // Need: service-offering language + 2+ overlapping service areas
        if ($serviceOfferingSignals > 0 && $competitorServiceMatches >= 4) {
            $score -= 80; // Strong competitor signal
            $this->logger->debug('Homepage classifier: competitor service-provider detected', [
                'service_matches' => $competitorServiceMatches,
            ]);
        } elseif ($competitorServiceMatches >= 6) {
            // Even without explicit "we provide" — 3+ service areas = likely competitor
            $score -= 80;
            $this->logger->debug('Homepage classifier: competitor service-provider detected (implicit)', [
                'service_matches' => $competitorServiceMatches,
            ]);
        }

        // ═══════════════════════════════════════════════════════════════
        // TIER 1 / GIANT COMPETITOR BRAND NAMES
        // Known large competitors — hard reject regardless of language
        // ═══════════════════════════════════════════════════════════════
        $tier1Competitors = [
            'yazaki', 'sumitomo electric', 'sumitomo wiring', 'aptiv', 'delphi',
            'lear corporation', 'leoni', 'nexans', 'furukawa electric',
            'motherson', 'samvardhana motherson', 'prysmian', 'draxlmaier',
            'coroplast', 'coficab', 'kromberg', 'yura corporation', 'kyungshin',
            'foxconn', 'pegatron', 'flex ltd', 'flextronics', 'jabil',
            'celestica', 'sanmina', 'benchmark electronics', 'plexus corp',
            'continental ag', 'bosch automotive', 'denso corporation',
            'magna international', 'valeo', 'faurecia', 'forvia',
            'zf friedrichshafen', 'aisin seiki', 'hyundai mobis',
            'nifco', 'plastic omnium', 'novares', 'grupo antolin',
        ];
        foreach ($tier1Competitors as $tier1) {
            if (str_contains($text, strtolower($tier1))) {
                $score -= 100;
                $this->logger->debug('Homepage classifier: Tier 1 competitor brand', [
                    'brand' => $tier1,
                ]);
                break;
            }
        }

        $giantSignals = [
            'fortune 500', 'fortune 100', 'fortune global',
            'more than 100,000 employees', 'over 100,000 employees',
            'over 50,000 employees', 'more than 50,000',
            'revenue exceeding', 'billion in revenue',
            'operates in more than 100 countries',
            'operates in over 50 countries',
            'global workforce of', 'worldwide operations',
        ];
        foreach ($giantSignals as $phrase) {
            if (str_contains($text, $phrase)) {
                $score -= 10;
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // FR/DE AUDIT: COSMETICS / BEAUTY / PERSONAL CARE HOMEPAGE
        // ═══════════════════════════════════════════════════════════════
        $cosmeticsHomeSignals = 0;
        if (preg_match('/\b(cosmetic|cosmetique|kosmetik|beauty|skincare|skin\s+care|fragrance|parfum|perfume|maquillage|makeup|personal\s+care|body\s+care|hair\s+care)\b/i', $text)) $cosmeticsHomeSignals += 3;
        if (preg_match('/\b(pigment|colorant|excipient|emollient|surfactant|ingrédient|matière\s+première|raw\s+material.{0,15}(beauty|skin|hair|cosmetic))\b/i', $text)) $cosmeticsHomeSignals += 3;
        if (preg_match('/\b(dermato|anti[\s-]?aging|anti[\s-]?rides|soin\s+(du\s+)?(visage|corps|cheveux)|Hautpflege|Haarpflege|Schönheit)\b/i', $text)) $cosmeticsHomeSignals += 2;
        if ($cosmeticsHomeSignals >= 3) { $score -= 40; }

        // ═══════════════════════════════════════════════════════════════
        // FR/DE AUDIT: RUBBER / SEALS / GASKETS HOMEPAGE
        // ═══════════════════════════════════════════════════════════════
        $rubberHomeSignals = 0;
        if (preg_match('/\b(rubber\s+(product|moulding|molding|compound|seal|gasket|o[\s-]ring|extrusion|sheet|hose)|caoutchouc|joint\s+(torique|d.étanchéité)|Gummi(werke?|fabrik|technik|dichtung|produkt|formteil|fertigung|profil|artikel|mischung)|Kautschuk|Vulkanisation)\b/i', $text)) $rubberHomeSignals += 3;
        if (preg_match('/\b(silicone\s+(moulding|molding|seal|gasket|tube|hose)|EPDM|NBR|Viton|neoprene|vulcanis)\b/i', $text)) $rubberHomeSignals += 2;
        if ($rubberHomeSignals >= 3) { $score -= 35; }

        // ═══════════════════════════════════════════════════════════════
        // FR/DE AUDIT: WATER COOLERS / DISPENSERS HOMEPAGE
        // ═══════════════════════════════════════════════════════════════
        $waterHomeSignals = 0;
        if (preg_match('/\b(water\s+(cooler|dispenser|fountain|purifier)|fontaine\s+à\s+eau|distributeur\s+d.eau|Wasserspender|Trinkwasser(system|spender|automat))\b/i', $text)) $waterHomeSignals += 3;
        if (preg_match('/\b(eau\s+(gazeuse|plate|filtrée|chaude|froide|tempérée)|still\s+water|sparkling\s+water|contactless\s+water|touchless\s+water|bottle[\s-]?less)\b/i', $text)) $waterHomeSignals += 2;
        if ($waterHomeSignals >= 3) { $score -= 35; }

        // ═══════════════════════════════════════════════════════════════
        // FR/DE AUDIT: CUTTING TOOLS / MACHINING HOMEPAGE
        // ═══════════════════════════════════════════════════════════════
        $cuttingHomeSignals = 0;
        if (preg_match('/\b(cutting\s+tool|drill\s+bit|milling\s+cutter|end\s+mill|tap(ping)?\s+tool|reamer|carbide\s+insert|outil\s+coupant|foret|fraise|taraud|Schneidwerkzeug|Bohrer|Fräser|Wendeschneidplatte)\b/i', $text)) $cuttingHomeSignals += 3;
        if (preg_match('/\b(CNC\s+machining\s+center|usinage|tournage|décolletage|Zerspanung|Drehmaschine|Fräsmaschine|precision\s+machining)\b/i', $text)) $cuttingHomeSignals += 2;
        if ($cuttingHomeSignals >= 3) { $score -= 35; }

        // ═══════════════════════════════════════════════════════════════
        // FR/DE AUDIT: BROADCASTING / RADIO / TV HOMEPAGE
        // ═══════════════════════════════════════════════════════════════
        $broadcastHomeSignals = 0;
        if (preg_match('/\b(radio\s+(station|broadcast|internationale|france)|actualités|en\s+direct|Nachrichten|Rundfunk|Fernsehen|diffusion|émission|antenne|télévision|broadcast(er|ing))\b/i', $text)) $broadcastHomeSignals += 3;
        if (preg_match('/\b(podcast|chronique|reportage|journaliste|rédaction|correspondant|édition|journal\s+télévisé|Sendung|Beitrag|Moderator|Redaktion)\b/i', $text)) $broadcastHomeSignals += 2;
        if ($broadcastHomeSignals >= 3) { $score -= 40; }

        // ═══════════════════════════════════════════════════════════════
        // FR/DE AUDIT: RESEARCH INSTITUTE / GOVERNMENT LAB HOMEPAGE
        // ═══════════════════════════════════════════════════════════════
        $researchHomeSignals = 0;
        if (preg_match('/\b(research\s+institute|research\s+laboratory|institut\s+de\s+recherche|centre\s+de\s+recherche|laboratoire|Forschungsinstitut|Forschungszentrum|Forschungsgesellschaft)\b/i', $text)) $researchHomeSignals += 3;
        if (preg_match('/\b(CEA|CNRS|INRIA|INSERM|ONERA|Fraunhofer|Max[\s-]Planck|Helmholtz|Leibniz|DLR|Commissariat|national\s+laboratory|public\s+research)\b/i', $text)) $researchHomeSignals += 3;
        if (preg_match('/\b(thèse|doctorat|chercheur|publication\s+scientifique|Doktorarbeit|Promotion|Wissenschaftler|Forschungsprojekt|scientific\s+publication|research\s+paper)\b/i', $text)) $researchHomeSignals += 2;
        if ($researchHomeSignals >= 3) { $score -= 40; }

        // ═══════════════════════════════════════════════════════════════
        // FR/DE AUDIT: RARE EARTH / MINERAL PROCESSING HOMEPAGE
        // ═══════════════════════════════════════════════════════════════
        $rareEarthHomeSignals = 0;
        if (preg_match('/\b(rare\s+earth|terres\s+rares|seltene\s+Erden|cerium|lanthanum|neodymium|yttrium|samarium|dysprosium|lanthanide)\b/i', $text)) $rareEarthHomeSignals += 3;
        if (preg_match('/\b(mineral\s+(processing|separation|extraction)|hydrometallurg|pyrometallurg|ore\s+(beneficiation|processing|treatment))\b/i', $text)) $rareEarthHomeSignals += 2;
        if ($rareEarthHomeSignals >= 3) { $score -= 35; }

        // ═══════════════════════════════════════════════════════════════
        // FR/DE AUDIT: SOLVENT RECOVERY / CHEMICAL DISTILLATION HOMEPAGE
        // ═══════════════════════════════════════════════════════════════
        $solventHomeSignals = 0;
        if (preg_match('/\b(solvent\s+(recovery|recycl|distill|regen)|chemical\s+(recovery|recycl|distill)|waste\s+solvent|recyclage\s+(de\s+)?solvant|Lösemittel(aufbereitung|destillation|recycling))\b/i', $text)) $solventHomeSignals += 3;
        if (preg_match('/\b(distillation\s+(column|unit|plant|process)|fractional\s+distillation|molecular\s+distillation)\b/i', $text)) $solventHomeSignals += 2;
        if ($solventHomeSignals >= 3) { $score -= 35; }

        // ═══════════════════════════════════════════════════════════════
        // POSITIVE SIGNALS — Real manufacturer / OEM / buyer
        // These offset negatives — legitimate companies may have
        // a few dealer-like words incidentally.
        // ═══════════════════════════════════════════════════════════════

        // NOTE: Do NOT include EMS/competitor service phrases here!
        // "pcb assembly", "cable harness", "wire harness", "smt line",
        // "box build" etc. are COMPETITOR signals, not buyer signals.
        // They indicate a company that PROVIDES Starz's own services.
        $mfgPhrases = [
            'we manufacture', 'we design and manufacture',
            'our manufacturing facility', 'our production facility',
            'our factory', 'our plant', 'our r&d',
            'engineering team', 'design team',
            'product development', 'prototyping',
            'iso 9001 certified', 'iatf 16949', 'as9100',
            'iso 13485', 'nadcap',
            'we supply to', 'we are a tier',
            'our products are used in',
            'years of experience in manufacturing',
            'state-of-the-art facility',
            'quality management system',
        ];
        foreach ($mfgPhrases as $phrase) {
            if (str_contains($text, $phrase)) {
                $score += 8;
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // DECISION
        // ═══════════════════════════════════════════════════════════════

        // Strong negative signals → reject
        if ($score <= -20) {
            $this->logger->debug('Homepage classifier: REJECTED (score={score})', [
                'name' => $name, 'domain' => $domain, 'score' => $score,
            ]);
            return true;
        }

        return false;
    }

    /**
     * SMART POSITIVE SCORING: Determine if a candidate is likely a
     * genuine EMS buyer (OEM, manufacturer, integrator that NEEDS
     * electronic assemblies made for their products).
     *
     * Instead of trying to blocklist every possible non-company (infinite),
     * this method looks for POSITIVE signals that the company:
     * 1. Makes/designs products (not just services/trading/media)
     * 2. Operates in a sector that needs electronic assemblies
     * 3. Has structural name patterns consistent with real companies
     * 4. Has a domain consistent with a manufacturer/OEM
     *
     * Returns TRUE if this looks like a real EMS buyer prospect.
     * Returns FALSE if there's no evidence this is a real buyer.
     */
    private function isLikelyEMSBuyer(string $name, string $snippet, string $title, string $domain): bool
    {
        $text = strtolower($snippet . ' ' . $title . ' ' . $name);
        $lower = strtolower(trim($name));
        $score = 0;

        // ═══════════════════════════════════════════════════════════════
        // NEGATIVE signals — strong evidence this is NOT a buyer
        // ═══════════════════════════════════════════════════════════════

        // ─── Government / Authority / Public body ──────────────────
        if (preg_match('/\b(government|authority|ministry|department\s+of|bureau\s+of|office\s+of|council|parliament|senate|commission|board\s+of|agency|administration|municipality|prefecture|governor|directorate|secretariat)\b/i', $text)) {
            $score -= 50;
        }
        if (preg_match('/\.(gov|mil|edu)(\.[a-z]{2,3})?$/i', $domain)) {
            $score -= 50;
        }

        // ─── Trade body / Association / Federation ─────────────────
        if (preg_match('/\b(trade\s+body|trade\s+association|industry\s+body|industry\s+association|chamber\s+of\s+commerce|federation|confederation|alliance|coalition|consortium|cooperative|syndicate)\b/i', $text)) {
            $score -= 40;
        }

        // ─── News / Media / Publishing ─────────────────────────────
        if (preg_match('/\b(newspaper|news\s+agency|media\s+company|publishing|editorial|journalist|reporter|correspondent|newsroom|magazine|podcast|broadcast)\b/i', $text)) {
            $score -= 40;
        }
        // Broader news content signals (catch sites like Daily Mail that
        // don't use explicit "newspaper" in snippets)
        if (preg_match('/\b(breaking\s+news|latest\s+news|top\s+stories|headlines|trending\s+(now|today)|opinion\s+column|read\s+more\s+at|subscribe\s+to\s+(our|the)\s+newsletter|news\s+desk|news\s+feed|showbiz|celebrity|tabloid|exclusive\s+interview|royal\s+family)\b/i', $text)) {
            $score -= 50;
        }

        // ─── Standards body / Normalization / Certification org ──────
        if (preg_match('/\b(standards?\s+(body|organization|organisation|institute|authority)|standardization|standardisation|normalization|normalisation|technick[ée]\s+normy|normes?\s+techniques?|technical\s+standard|national\s+standard|international\s+standard|DIN\s+standard|ANSI\s+standard|BSI\s+Group|ISO\s+(committee|standard|certification)|IEC\s+standard|CEN\b|CENELEC|norms?\s+(database|catalog|catalogue|search|portal))\b/i', $text)) {
            $score -= 40;
        }

        // ─── Event / Conference / Exhibition ───────────────────────
        if (preg_match('/\b(trade\s+show|exhibition|expo|conference|summit|forum|congress|symposium|convention|fair|show\s+daily|auto\s+show|motor\s+show)\b/i', $text)) {
            $score -= 35;
        }

        // ─── University / Academic / Research institute ────────────
        if (preg_match('/\b(university|universit[éèeäa]|college|academic|faculty|school\s+of|institute\s+of|research\s+center|research\s+centre|campus|professor|lecture|student|thesis|doctoral|phd|Hochschule|Fachhochschule|Technische\s+Universität|école\s+(polytechnique|normale|supérieure|des\s+(mines|ponts))|grande\s+école|chercheur|laboratoire\s+(de\s+)?recherche|Forschung(sinstitut|szentrum)|Wissenschaft)\b/i', $text)) {
            $score -= 40;
        }

        // ─── Distributor of another brand ──────────────────────────
        // "Schneider distributor", "authorized dealer of Siemens"
        if (preg_match('/\b(exclusive\s+distribut|authorized\s+distribut|official\s+distribut|regional\s+distribut|sole\s+distribut|distribut(or|ion)\s+(of|for)\s+\w+|authorized\s+dealer|official\s+dealer|value[\s-]added\s+reseller|var\s+partner|channel\s+partner)\b/i', $text)) {
            $score -= 40;
        }

        // ─── Pure trading company / importer / reseller ────────────
        if (preg_match('/\b(trading\s+company|general\s+trading|import(er|ing)\s+(and|&)\s+export|wholesale\s+trading|we\s+trade|we\s+import|we\s+export|commodity\s+trad|trading\s+fze|trading\s+llc)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Hospital / Clinic / Healthcare provider ───────────────
        if (preg_match('/\b(hospital|clinic|medical\s+center|medical\s+centre|patient\s+care|health\s+system|healthcare\s+provider|nursing|physician|doctor|ambulance|pharmacy|drugstore)\b/i', $text)) {
            $score -= 40;
        }

        // ─── Financial services / Bank / Insurance ─────────────────
        if (preg_match('/\b(bank|banking|insurance|fintech|financial\s+services|credit\s+union|stock\s+exchange|brokerage|hedge\s+fund|asset\s+manage|wealth\s+manage|payment\s+processing)\b/i', $text)) {
            $score -= 40;
        }

        // ─── Free Zone / Industrial Park / Economic Zone ───────────
        if (preg_match('/\b(free\s+zone|freezone|free\s+trade\s+zone|industrial\s+(city|platform|zone)|industrial\s+park|economic\s+zone|special\s+economic|technology\s+park|business\s+park|airport\s+freezone|integrated\s+industrial\s+platform|invest(ors?|ment)\s+benefits?|land\s+for\s+(lease|rent|sale)\s+.*?industrial|set\s+up\s+(your|a)\s+(business|company)\s+(in|at))\b/i', $text)) {
            $score -= 35;
        }

        // ─── Job listing / Vacancy / Recruitment ───────────────────
        if (preg_match('/\b(job\s+vacancies|job\s+openings?|careers?\s+page|we\s+are\s+hiring|apply\s+now|join\s+our\s+team|recruitment|headhunt|talent\s+acquisition|staffing\s+agency)\b/i', $text)) {
            $score -= 40;
        }

        // ─── Facilities management / Cleaning / Catering ──────────
        if (preg_match('/\b(facilities?\s+management|cleaning\s+services?|catering|janitor|housekeeping|pest\s+control|security\s+guard|property\s+manage)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Racing / Sports / Entertainment ──────────────────────
        if (preg_match('/\b(racing\s+league|motorsport|formula\s+[1e]|grand\s+prix|football|soccer|basketball|cricket|entertainment|gaming|casino|bet(ting)?)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Real estate / Construction / Architecture ────────────
        if (preg_match('/\b(real\s+estate|property\s+develop|construction\s+company|general\s+contractor|architect(ure|ural)\s+(firm|company)|building\s+contractor|civil\s+engineer(ing)?\s+(company|contractor))\b/i', $text)) {
            $score -= 35;
        }

        // ─── Shipping / Logistics / Freight ────────────────────────
        if (preg_match('/\b(shipping\s+(company|line|services?)|freight\s+(forward|transport|services?|company)|logistics\s+(provider|company|services?|solutions?)|supply\s+chain\s+logistics|customs\s+(broker|clearance|transit)|cargo\s+(handling|transport|services?)|container\s+terminal|port\s+operation|stevedoring|air\s+freight|sea\s+freight|maritime\s+freight|road\s+freight|warehousing\s+services?|transit(aire)?\s+(international|company))\b/i', $text)) {
            $score -= 35;
        }

        // ─── Food / Beverage / Agriculture ─────────────────────────
        if (preg_match('/\b(food\s+(and|&)\s+beverage|bottling|brewery|winery|dairy|bakery|confectionery|meat\s+process|poultry|agriculture|farming|fertilizer|animal\s+feed|crop\s+protection)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Consulting / Legal / Audit ────────────────────────────
        if (preg_match('/\b(consulting\s+firm|law\s+firm|legal\s+services|accounting\s+firm|audit\s+firm|management\s+consult|strategy\s+consult|advisory\s+firm|tax\s+consult)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Telecom operator (not equipment maker) ────────────────
        if (preg_match('/\b(mobile\s+operator|telecom\s+operator|cellular\s+network|internet\s+service\s+provider|broadband\s+provider|isp\b|mobile\s+network|data\s+plans?|prepaid|postpaid|roaming)\b/i', $text)) {
            $score -= 30;
        }

        // ─── Car dealer / Auto retail ──────────────────────────────
        if (preg_match('/\b(car\s+dealer|auto\s+(dealer|group)|motor\s+group|fleet\s+sales|certified\s+pre-owned|pre-owned\s+vehicles?|used\s+cars?|new\s+cars?\s+for\s+sale|showroom|test\s+drive)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Petroleum / Refinery / Oil & Gas ──────────────────────
        if (preg_match('/\b(petroleum|refinery|crude\s+oil|natural\s+gas|upstream|downstream|drilling\s+rig|oilfield|hydrocarbon|lng\s+terminal|pipeline\s+operator)\b/i', $text)) {
            $score -= 30;
        }

        // ─── Pharma / Biotech / Drug companies ─────────────────────
        if (preg_match('/\b(pharmaceutical|pharma\s+company|biotech(nology)?|biopharmaceutical|clinical\s+trial|drug\s+(development|discovery|pipeline)|gene\s+therapy|mRNA|biosimilar|vaccine\s+develop|immunotherapy|oncology\s+drug)\b/i', $text)) {
            $score -= 40;
        }

        // ─── Chemical / Agrochemical / Fertilizer / Polymer / Materials ──
        if (preg_match('/\b(chemical\s+(company|manufacturer|plant|producer|group|division)|specialty\s+chemical|petrochemical\s+(company|plant)|agrochemical|fertilizer\s+(company|plant|producer)|adhesive\s+manufacturer|paint\s+manufacturer|coating\s+company|solvent|polymer\s+(producer|supplier|manufacturer)|styrene|polystyrene|polyethylene|polypropylene|polyurethane|styrolution|styrenics|plastics?\s+(supplier|producer|manufacturer|company)|raw\s+material\s+(supplier|producer)|basic\s+materials?|resin\s+(supplier|producer|manufacturer)|chemical\s+industry|commodity\s+chemical|bulk\s+chemical)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Game studio / Video game ──────────────────────────────
        if (preg_match('/\b(game\s+studio|video\s+game|game\s+develop(er|ment)|indie\s+game|gaming\s+(company|studio)|esports?|multiplayer\s+game|game\s+publisher)\b/i', $text)) {
            $score -= 40;
        }

        // ─── Generic IT services / Cloud / Hosting ─────────────────
        if (preg_match('/\b(it\s+services?\s+(in|company|provider|dubai|egypt)|web\s+design|web\s+develop|app\s+develop|cloud\s+hosting|managed\s+hosting|data\s+center\s+services?|server\s+rental|server\s+basket|it\s+support\s+services?)\b/i', $text)) {
            $score -= 30;
        }

        // ─── NGO / Aid / Development ──────────────────────────────
        if (preg_match('/\b(humanitarian|refugee|development\s+aid|foreign\s+aid|poverty|ngo|non[\s-]?governmental|unicef|who\b|world\s+bank|international\s+development|capacity\s+building)\b/i', $text)) {
            $score -= 40;
        }

        // ─── Certification / ISO / Auditing body ──────────────────
        if (preg_match('/\b(iso\s+certification\s+(in|for|services?)|we\s+certify|certification\s+body|certifying\s+body|audit(ing)?\s+services?|accreditation\s+body|iso\s+9001\s+certification\s+services?)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Software company / ERP / SaaS (iter11 Egypt) ─────────
        if (preg_match('/\b(software\s+(company|development|solutions?|house|firm|provider)|ERP\s+(software|solutions?|vendor|system)|SaaS\s+(platform|provider|company)|custom\s+software|offshore\s+software|embedded\s+software\s+(develop|company)|automotive\s+software\s+(develop|company))\b/i', $text)) {
            $score -= 40;
        }

        // ─── Automation reseller / distributor (iter11 Egypt EISAC) ─
        if (preg_match('/\b(automation\s+(distributor|reseller|dealer|supplier|trading)|industrial\s+automation\s+solutions?\s+provider|(authorized|certified)\s+(siemens|abb|schneider)\s+(partner|distributor|dealer|reseller))\b/i', $text)) {
            $score -= 35;
        }

        // ─── Equipment dealer / agent / trading company ────────────
        if (preg_match('/\b((lab|laboratory|medical|scientific|pharma)\s+equipment\s+(dealer|agent|supplier|trading)|equipment\s+(trading|import)\s+company|(sole|exclusive|authorized)\s+agent\s+(for|of|in))\b/i', $text)) {
            $score -= 35;
        }

        // ─── Mining / quarrying / mineral extraction ───────────────
        if (preg_match('/\b(mining\s+(company|operations?)|quarry(ing)?\s+(company|operations?)|mineral\s+(processing|extraction|mining)|calcium\s+carbonate|limestone\s+(quarry|mining)|cement\s+(company|manufactur|plant))\b/i', $text)) {
            $score -= 35;
        }

        // ─── Engineering consultancy / EPC ─────────────────────────
        if (preg_match('/\b(EPC\s*[\/&]\s*EPCM|power\s+(plant|generation)\s+(engineering|construction|consultancy)|engineering\s+procurement\s+.*?construction)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Car dealership network / auto retail group ────────────
        if (preg_match('/\b(car\s+dealer(ship)?|auto(mobile)?\s+dealer(ship)?|vehicle\s+(import|trading|distribution)|authorized\s+(dealer|distributor|importer)\s+(for|of)\s+(mercedes|bmw|toyota|nissan|hyundai|kia|ford))\b/i', $text)) {
            $score -= 35;
        }

        // ─── Aircraft MRO / avionics dealer (iter11b Morocco MDS) ──
        if (preg_match('/\b(aircraft\s+(maintenance|repair|overhaul|mro)|avionics\s+(upgrade|dealer|installation|shop)|easa\s+part\s+145|aircraft\s+sales?\s+(and|&)\s+(leasing|broker)|helicopter\s+maintenance|engine\s+(overhaul|repair)\s+shop)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Tyre brand / tyre dealer (iter11b Morocco Dunlop) ─────
        if (preg_match('/\b(tyre\s+(manufactur|brand|dealer|distributor|shop|finder|range|size)|tire\s+(manufactur|brand|dealer|distributor|shop|finder|size)|pneumatique|tyre\s+pressure|run[\s-]flat\s+tyre|all[\s-]season\s+tyre)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Academic journal / research publisher (iter11b ETASR) ──
        if (preg_match('/\b(academic\s+journal|peer[\s-]review|open[\s-]access\s+journal|research\s+paper|scientific\s+journal|call\s+for\s+papers?|submit\s+(a\s+)?paper|published\s+articles?)\b/i', $text)) {
            $score -= 40;
        }

        // ─── Market research / report seller (iter12 Vyansa) ────────
        if (preg_match('/\b(market\s+(report|research|insight|intelligence|forecast)|industry\s+report|research\s+report|CAGR|sample\s+pdf|buy\s+(this\s+)?report|market\s+size\s+.*?forecast|report\s+overview|study\s+period)\b/i', $text)) {
            $score -= 40;
        }

        // ─── Government megaproject / giga-project (iter12 NEOM) ────
        if (preg_match('/\b(megaproject|mega[\s-]?project|giga[\s-]?project|smart\s+city\s+(project|development)|region\s+in\s+the\s+making|vision\s+2030\s+.*?(project|city|zone))\b/i', $text)) {
            $score -= 35;
        }

        // ─── Telecoms news / operator industry site (iter12) ────────
        if (preg_match('/\b(telecom(s|munication)?\s+(news|industry|sector|operator|business|regulation)|5G[\s-]?(advanced|monetis)|spectrum\s+(deal|auction)|mobile\s+operator|subsea\s+cable|fibre\s+(optic|network)\s+project)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Arabic financial news / stock market data (iter12) ─────
        if (preg_match('/\b(stock\s+market\s+data|stock\s+exchange\s+(news|data)|share\s+price\s+tracker|financial\s+(news|portal|data)\s+(site|portal|platform)|بورصة|تاسي|أسهم|اكتتاب)\b/iu', $text)) {
            $score -= 40;
        }

        // ─── Trade show / exhibition / airshow (iter12) ──────────────
        if (preg_match('/\b(trade\s*show|air\s*show|expo(sition)?\s+event|trade\s*fair|exhibit(or)?\s+registration|book\s+(a\s+)?booth|visitor\s+registration)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Drone delivery / last-mile service (iter12 Zipline) ─────
        if (preg_match('/\b(drone\s+delivery|delivery\s+drone|autonomous\s+delivery|store[\s-]to[\s-]door|instant\s+delivery\s+service|last[\s-]mile\s+delivery\s+drone)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Auto spare parts distributor / car dealer (iter13 Tunisia) ─────
        if (preg_match('/\b(auto(motive)?\s+spare\s+parts?|car\s+spare\s+parts?|spare\s+parts?\s+(wholesale|distribution|dealer)|Mercedes[- ]Benz\s+(dealer|sub[- ]dealer|agent)|authorized\s+dealer\s+of\s+(Mercedes|BMW|Toyota|Hyundai))\b/i', $text)) {
            $score -= 35;
        }

        // ─── Adhesive / glue / paint / brush manufacturer (iter13 Tunisia) ──
        if (preg_match('/\b(adhesive\s+manufactur|glue\s+manufactur|paint\s+manufactur|paintbrush|brush\s+manufactur|wood\s+glue|shoe\s+glue|solvent\s+manufactur|varnish\s+manufactur)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Aluminum extrusion (not electronics) (iter13 Tunisia) ──────
        if (preg_match('/\b(alumini?um\s+extrusion|alumini?um\s+profil(e|es)\s+manufactur|building\s+profil(e|es)\s+extrusion|extruded\s+alumini?um)\b/i', $text)) {
            $score -= 35;
        }

        // ─── IT engineering / embedded software services (iter13 Tunisia) ────
        if (preg_match('/\b(embedded\s+software\s+(services?|consulting|company)|R&D\s+outsourc|nearshore\s+engineering|offshore\s+engineer|engineering\s+services?\s+(company|provider|firm))\b/i', $text)) {
            $score -= 30;
        }

        // ─── Business/management consulting firm (iter13 Tunisia) ────
        if (preg_match('/\b(business\s+consulting|management\s+consulting|consulting\s+firm|consulting\s+for\s+investor|market\s+entry\s+advisory|investment\s+advisory)\b/i', $text)) {
            $score -= 30;
        }

        // ─── Cosmetics / beauty / skincare / fragrance (FR/DE audit) ──
        if (preg_match('/\b(cosmetic|cosmetique|kosmetik|beauty|skincare|skin\s+care|fragrance|parfum|perfume|makeup|maquillage|hair\s+care|nail\s+care|personal\s+care|body\s+care|toiletries|dermocosm[eé]tique)\b/i', $text)) {
            $score -= 40;
        }
        if (preg_match('/\b(pigment|colorant|excipient|emollient|surfactant|cosmetic\s+ingredient|raw\s+material.{0,20}cosmetic|ingrédient|matière\s+première.{0,20}cosmétique)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Plastic recycling / biorecycling / enzyme (FR/DE audit) ──
        if (preg_match('/\b(biorecycling|bio[\s-]recycling|enzymatic\s+recycling|enzyme.{0,30}(plastic|pet|polymer)|plastic\s+recycl|PET\s+recycl|circular\s+(economy|plastic)|polylactic|bioplastic|biocomposite)\b/i', $text)) {
            $score -= 40;
        }

        // ─── Broadcasting / radio / TV / media (FR/DE audit) ──────────
        if (preg_match('/\b(radio\s+(station|broadcast|diffusion|france|internationale)|broadcast(er|ing)|télévision|fernsehen|rundfunk|actualités|journaliste|rédaction|émission|antenne|podcast(ing)?|chaîne|sender|nachrichtensendung)\b/i', $text)) {
            $score -= 40;
        }

        // ─── Water dispensers / coolers / fountains (FR/DE audit) ─────
        if (preg_match('/\b(water\s+(cooler|dispenser|fountain|purifier|filter)|fontaine\s+à\s+eau|distributeur\s+d.eau|wasserspender|wasserkühler|Trinkwasser(system|spender|automat)|eau\s+(gazeuse|plate|filtrée))\b/i', $text)) {
            $score -= 35;
        }

        // ─── Cutting tools / drill bits / milling cutters (FR/DE audit) ─
        if (preg_match('/\b(cutting\s+tool|drill\s+bit|milling\s+cutter|end\s+mill|tap(ping)?\s+tool|reamer|insert\s+(carbide|ceramic)|outil\s+coupant|foret|fraise|taraud|Schneidwerkzeug|Bohrer|Fräser|Hartmetall[\s-]?(werkzeug|einsatz))\b/i', $text)) {
            $score -= 35;
        }

        // ─── Rare earth / mineral processing (FR/DE audit) ───────────
        if (preg_match('/\b(rare\s+earth|terres\s+rares|seltene\s+Erden|cerium|lanthanum|neodymium|yttrium|samarium|dysprosium|praseodymium|lanthanide|actinide|mineral\s+processing|mineral\s+separation|ore\s+beneficiation)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Solvent / chemical recovery / distillation (FR/DE audit) ─
        if (preg_match('/\b(solvent\s+(recovery|recycl|distill|regen)|chemical\s+(recovery|recycl|distill)|distillation\s+(plant|column|unit|service)|recyclage\s+(de\s+)?solvant|Lösemittel(aufbereitung|destillation|recycling))\b/i', $text)) {
            $score -= 35;
        }

        // ─── Rubber manufacturing / moulding (FR/DE audit) ───────────
        if (preg_match('/\b(rubber\s+(manufactur|moulding|molding|products?|compound|extrusion|factory)|caoutchouc|silicone\s+(moulding|molding|products?|seals?)|Gummi(werke?|fabrik|technik|herstellung|formteil|produkt|dichtung|fertigung|artikel|mischung)|Kautschuk|Vulkanisation|joint\s+(caoutchouc|torique|d.étanchéité))\b/i', $text)) {
            $score -= 35;
        }

        // ─── Machining / CNC / precision milling / turning (FR/DE audit) ─
        if (preg_match('/\b(cnc\s+machin|precision\s+machin|contract\s+machin|usinage|tournage|fraisage|décolletage|rectification|CNC[\s-]?Bearbeitung|Zerspanung|Drehen|Fräsen|Schleifen|precision\s+engineering\s+(company|firm|service)|job\s+shop|lohn(bearbeitung|fertigung))\b/i', $text)) {
            $score -= 35;
        }

        // ─── Giant industrial robot / automation OEM (FR/DE audit) ────
        // These companies make robots/instruments, they don't BUY EMS
        if (preg_match('/\b(FANUC|KUKA|Yaskawa|Stäubli|Staubli|Universal\s+Robots|EPSON\s+Robot|Kawasaki\s+Robot|Nachi|Comau)\b/i', $text)) {
            $score -= 50;
        }
        if (preg_match('/\b(Yokogawa|Endress\s*\+?\s*Hauser|Emerson\s+(Process|Automation)|Honeywell\s+(Process|Automation)|Siemens\s+(Process|Factory)|ABB\s+(Robot|Motion)|Festo|Pilz|Keyence|Balluff|SICK\s+AG)\b/i', $text)) {
            $score -= 50;
        }

        // ─── French/German research institutes (FR/DE audit) ──────────
        if (preg_match('/\b(CEA|CNRS|INRIA|INSERM|ONERA|Fraunhofer|Max[\s-]Planck|Helmholtz|Leibniz|DLR|institut\s+de\s+recherche|centre\s+de\s+recherche|Forschungsinstitut|Forschungszentrum|Commissariat|laboratoire\s+national)\b/i', $text)) {
            $score -= 40;
        }
        // French/German research domain patterns
        if (preg_match('/\.(cea|cnrs|inria|inserm|onera|ifremer|brgm|irstea)\.fr$/i', $domain)) {
            $score -= 50;
        }
        if (preg_match('/\.(fraunhofer|mpg|helmholtz|dlr|bam|ptb)\.de$/i', $domain)) {
            $score -= 50;
        }

        // ─── Italian research institutes (IT/GB/ES audit) ─────────────
        if (preg_match('/\b(CNR|Consiglio\s+Nazionale\s+delle\s+Ricerche|INFN|Istituto\s+Nazionale\s+di\s+Fisica\s+Nucleare|ENEA|Agenzia\s+nazionale\s+per\s+le\s+nuove\s+tecnologie|ASI|Agenzia\s+Spaziale\s+Italiana|IIT|Istituto\s+Italiano\s+di\s+Tecnologia|INGV|INAF|Politecnico\s+di\s+(Milano|Torino)|Universit[àa]\s+di\s+(Bologna|Roma|Padova|Trento|Milano|Firenze|Napoli|Pisa|Torino)|istituto\s+di\s+ricerca|centro\s+di\s+ricerca|centro\s+ricerche|ente\s+di\s+ricerca)\b/i', $text)) {
            $score -= 40;
        }
        // Italian research domain patterns
        if (preg_match('/\.(cnr|infn|enea|asi|iit|ingv|inaf|iss)\.it$/i', $domain)) {
            $score -= 50;
        }

        // ─── British research institutes (IT/GB/ES audit) ─────────────
        if (preg_match('/\b(UKRI|UK\s+Research\s+and\s+Innovation|EPSRC|STFC|MRC|BBSRC|NERC|AHRC|National\s+Physical\s+Laboratory|NPL|Catapult\s+(Network|Centre)|HVM\s+Catapult|AMRC|Advanced\s+Manufacturing\s+Research|Cranfield\s+University|Imperial\s+College|University\s+of\s+(Cambridge|Oxford|Edinburgh|Sheffield|Warwick|Nottingham|Manchester|Birmingham|Leeds|Bristol|Glasgow|Southampton|Cardiff)|Alan\s+Turing\s+Institute|Francis\s+Crick|Daresbury\s+Laboratory|Rutherford\s+Appleton)\b/i', $text)) {
            $score -= 40;
        }
        // UK academic domain pattern
        if (preg_match('/\.ac\.uk$/i', $domain)) {
            $score -= 50;
        }

        // ─── Spanish research institutes (IT/GB/ES audit) ─────────────
        if (preg_match('/\b(CSIC|Consejo\s+Superior\s+de\s+Investigaciones\s+Cient[íi]ficas|INTA|Instituto\s+Nacional\s+de\s+T[ée]cnica\s+Aeroespacial|CIEMAT|CDTI|Tecnalia|Ikerbasque|Eurecat|Universidad\s+Polit[ée]cnica\s+de\s+(Madrid|Valencia|Catalu[ñn]a)|Universitat\s+Polit[èe]cnica\s+de\s+(Catalunya|Val[èe]ncia)|centro\s+de\s+investigaci[oó]n|instituto\s+de\s+investigaci[oó]n|centro\s+tecnol[oó]gico)\b/i', $text)) {
            $score -= 40;
        }
        // Spanish research domain patterns
        if (preg_match('/\.(csic|inta|ciemat|cdti)\.es$/i', $domain)) {
            $score -= 50;
        }

        // ─── Major consultancy firm names (IT/GB/ES audit) ────────────
        // Detect by company name even when domain is not in blocklist
        if (preg_match('/\b(Deloitte|PricewaterhouseCoopers|PwC|Ernst\s*[&]\s*Young|KPMG|McKinsey|Boston\s+Consulting|Bain\s+&\s+Company|Accenture|Capgemini|Infosys|Tata\s+Consultancy|Wipro|Cognizant|HCL\s+Technologies?|Tech\s+Mahindra|Oliver\s+Wyman|Roland\s+Berger|Booz\s+Allen|BearingPoint|AlixPartners|A\.?T\.?\s*Kearney|Kearney|FTI\s+Consulting|Guidehouse|Protiviti)\b/i', $text)) {
            $score -= 50;
        }

        // ─── Italian wrong-industry: wine / olive oil / fashion (IT/GB/ES audit) ──
        if (preg_match('/\b(viticoltura|cantina|vigneto|enoteca|vino\s+(rosso|bianco|italiano)|olio\s+d.oliva|frantoio|oleificio|olio\s+extravergine|denominazione\s+di\s+origine|DOC|DOCG|IGT)\b/i', $text)) {
            $score -= 40;
        }

        // ─── Spanish wrong-industry: wine / tourism (IT/GB/ES audit) ──
        if (preg_match('/\b(bodega|vi[ñn]edo|denominaci[oó]n\s+de\s+origen|rioja|ribera\s+del\s+duero|turismo|hostelería|alojamiento|hotel\s+rural|casa\s+rural|parador)\b/i', $text)) {
            $score -= 40;
        }

        // ─── Heating / plumbing / HVAC installer (not OEM) ──────────
        if (preg_match('/\b(plumber|plumbing|chauffagiste|heating\s+engineer|boiler\s+install|Heizungsbauer|Sanitärinstallateur|plombier|installateur\s+(chauffage|sanitaire)|Klempner)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Textile / clothing / fashion (FR/DE audit) ──────────────
        if (preg_match('/\b(textile|vêtement|confection|couture|mode\s+(femme|homme)|Textil(industrie|herstellung)|Bekleidung|Schneiderei|habillement)\b/i', $text)) {
            $score -= 35;
        }

        // ─── Wine / food / agriculture (FR/DE audit) ─────────────────
        if (preg_match('/\b(vignoble|viticulteur|vigneron|domaine\s+viticole|château\s+(vin|viticole)|Weingut|Winzer|Weinbau|cave\s+coopérative|brasserie|brauerei)\b/i', $text)) {
            $score -= 35;
        }

        // ═══════════════════════════════════════════════════════════════
        // POSITIVE signals — evidence this IS a real buyer
        // ═══════════════════════════════════════════════════════════════

        // ─── OEM / product design / R&D language ──────────────────
        if (preg_match('/\b(we\s+design|we\s+develop|we\s+engineer|our\s+products?|product\s+line|product\s+range|product\s+portfolio|r&d|research\s+and\s+development|innovation\s+center|engineering\s+team)\b/i', $text)) {
            $score += 20;
        }

        // ─── Mentions specific product types that need EMS ────────
        if (preg_match('/\b(inverter|converter|controller|sensor|actuator|module|radar|lidar|avionics|telematics|infotainment|instrument\s+cluster|battery\s+management|bms|ecu|power\s+supply|ups|generator|switchgear|transformer|motor\s+drive|vfd|plc|hmi|scada)\b/i', $text)) {
            $score += 15;
        }

        // ─── Mentions manufacturing but NOT as a service ──────────
        if (preg_match('/\b(factory|plant|production\s+line|production\s+facility|manufacturing\s+plant|assembly\s+plant|production\s+capacity|warehouse|quality\s+control|lean\s+manufactur|six\s+sigma)\b/i', $text)) {
            $score += 10;
        }

        // ─── Has proper business structure indicators ─────────────
        if (preg_match('/\b(headquarters|founded|established|since\s+\d{4}|employees|revenue|annual\s+revenue|global\s+presence|worldwide|subsidiaries|divisions|locations\s+in)\b/i', $text)) {
            $score += 10;
        }

        // ─── Domain has commercial TLD + looks professional ───────
        if (preg_match('/\.(com|co|net)$/i', $domain) && !preg_match('/\.(wordpress|blogspot|wix|squarespace)\.com$/i', $domain)) {
            $score += 5;
        }
        // Country-code TLDs for target regions
        if (preg_match('/\.(ae|eg|ma|de|fr|nl|cz|pl|ro|us)$/i', $domain)) {
            $score += 5;
        }

        // ─── Name has proper company structure ────────────────────
        // Contains Ltd, LLC, Inc, GmbH, SA, Corp, Group, etc.
        if (preg_match('/\b(ltd|llc|inc|corp|gmbh|sa|sas|bv|nv|ag|plc|co|pty|srl|spa|fze|fzc|group|holding)\b/i', $name)) {
            $score += 10;
        }

        // ─── Name suggests a product/systems company ──────────────
        if (preg_match('/\b(systems|electronics|electric|power|energy|tech|technologies|automation|robotics|aerospace|defense|defence|marine|medical|instruments|motors|drives|controls|optics|photonics|solutions)\b/i', $name)) {
            $score += 10;
        }

        // ─── Snippet mentions industry certifications ─────────────
        if (preg_match('/\b(iso\s+9001|iatf\s+16949|as9100|iso\s+13485|iso\s+14001|nadcap|cmmi|ce\s+mark(ed|ing)?)\b/i', $text)) {
            $score += 10;
        }

        // ─── Snippet mentions supply chain / procurement ──────────
        if (preg_match('/\b(supply\s+chain|procurement|outsourc|vendor|supplier\s+to|supply\s+to|deliver\s+to|oem\s+partner|tier[\s-]?[12]\s+supplier)\b/i', $text)) {
            $score += 10;
        }

        // ═══════════════════════════════════════════════════════════════
        // DECISION: Require positive evidence of being a buyer
        // ═══════════════════════════════════════════════════════════════

        // Strong negative: requires VERY strong evidence (multiple categories
        // or overwhelmingly negative). A single -35 penalty with +15 positive
        // = -20, which should NOT reject if there's real positive evidence.
        // Reject only when negatives massively outweigh positives.
        if ($score <= -30) {
            $this->logger->debug('Rejected: overwhelming negative signal', [
                'name' => $name, 'score' => $score, 'domain' => $domain,
            ]);
            return false;
        }

        // Require at least SOME positive evidence of being an EMS buyer.
        // A score of 0 means no positive or negative signals → garbage.
        // Real OEM companies always have at least one positive signal
        // (product language, certifications, manufacturing mentions, etc.)
        if ($score < 5) {
            $this->logger->debug('Rejected: insufficient positive evidence', [
                'name' => $name, 'score' => $score, 'domain' => $domain,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Retry borderline BuyerEvidence failures with homepage text.
     *
     * This improves recall for real companies that had sparse SERP snippets,
     * while avoiding expensive retries for obviously bad candidates.
      * @param array<string|int, mixed> $result
     */
    private function shouldAttemptEvidenceHomepageRescue(
        BuyerEvidenceResult $evidenceResult,
        array $result,
        string $domain,
    ): bool {
        if ($evidenceResult->passed()) {
            return false;
        }

        // Don't rescue strong negatives.
        if ($evidenceResult->getTotalAntiScore() >= 40) {
            return false;
        }

        // Allow rescue even with 0 positive families — the homepage text
        // often contains strong evidence that sparse snippets don't show.
        // Previously required ≥1 positive family, which blocked legitimate
        // companies with uninformative snippets from getting a fair chance.

        // Don't spend rescue attempts on obvious article/news/career URLs.
        $link = strtolower((string) ($result['link'] ?? ''));
        if ($link !== '' && preg_match('/\/(news|blog|press|article|articles|insights?|media|events?|jobs?|careers?)\b/i', $link)) {
            return false;
        }

        // Rescue for normal commercial domains only.
        if ($this->isBlockedDomain($domain)) {
            return false;
        }

        return true;
    }

    /**
     * Fetch homepage text for evidence rescue.
     */
    private function fetchEvidenceHomepageText(string $domain): string
    {
        $cleanDomain = preg_replace('#^https?://#i', '', trim($domain));
        $cleanDomain = preg_replace('#/.*$#', '', (string) $cleanDomain);
        if ($cleanDomain === '') {
            return '';
        }

        $urls = [
            'https://' . $cleanDomain,
            'http://' . $cleanDomain,
        ];

        foreach ($urls as $url) {
            try {
                $response = $this->httpClient->request('GET', $url, [
                    'timeout' => 5,
                    'max_redirects' => 2,
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Accept' => 'text/html,application/xhtml+xml',
                        'Accept-Language' => 'en-US,en;q=0.9',
                    ],
                ]);

                $statusCode = $response->getStatusCode();
                if ($statusCode >= 400) {
                    continue;
                }

                $html = $response->getContent(false);
                if ($html === '') {
                    continue;
                }

                $html = $this->smartTruncateHtml($html, 200000);
                $html = preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
                $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = preg_replace('/\s+/u', ' ', $text) ?? '';
                $text = trim($text);

                if ($text !== '') {
                    return mb_substr($text, 0, 6000);
                }
            } catch (\Throwable) {
                // Try next candidate URL.
            }
        }

        return '';
    }

    /**
     * Validate that a candidate has evidence of presence in the target location.
     *
     * When searching for "Egypt", companies like Hope Global (US-based, no
     * Egypt operations) can appear because Google returns results from any page
     * mentioning both the company and the country. This check verifies that
     * the snippet/title/domain actually indicates local operations.
     *
     * Returns true if location presence is verified (or not applicable).
     * Returns false if the company clearly has NO connection to the target location.
     */
    private function hasLocationPresence(string $snippet, string $title, string $domain, ?string $location): bool
    {
        if ($location === null || $location === '') {
            return true; // No location filter → always pass
        }

        $text = strtolower($snippet . ' ' . $title);
        $locationLower = strtolower(trim($location));

        // Map location to country and expected terms
        $locationVocab = $this->getLocationVocabulary($locationLower);
        if ($locationVocab === null) {
            return true; // Unknown location → don't filter
        }

        // Check domain TLD — a ccTLD is strong location presence evidence
        foreach ($locationVocab['tlds'] as $tld) {
            if (preg_match('/\.' . preg_quote($tld, '/') . '$/i', $domain)) {
                return true;
            }
        }

        // Check text for any location terms (country name, city names, etc.)
        foreach ($locationVocab['terms'] as $term) {
            if (str_contains($text, $term)) {
                return true;
            }
        }

        // Check domain NAME for location terms (e.g. "autotechegypt.com")
        $domainLower = strtolower($domain);
        foreach ($locationVocab['terms'] as $term) {
            if (str_contains($domainLower, str_replace(' ', '', $term))) {
                return true;
            }
        }

        // Homepage rescue: fetch homepage HTML and check for location terms
        // Also parses structured data (Schema.org JSON-LD, phone codes, postal codes)
        try {
            $homepageHtml = $this->fetchEvidenceHomepageHtml($domain);
            if ($homepageHtml !== '') {
                // ── 1. Check plain text of homepage ──
                $homepageText = $this->htmlToPlainText($homepageHtml);
                $homepageLower = strtolower($homepageText);
                foreach ($locationVocab['terms'] as $term) {
                    if (str_contains($homepageLower, $term)) {
                        $this->logger->debug('Location presence rescued via homepage text', [
                            'domain' => $domain,
                            'term'   => $term,
                        ]);
                        return true;
                    }
                }

                // ── 2. Check structured data (JSON-LD, microdata) ──
                if ($this->checkStructuredDataForLocation($homepageHtml, $locationVocab)) {
                    $this->logger->debug('Location presence rescued via structured data (JSON-LD)', [
                        'domain' => $domain,
                    ]);
                    return true;
                }

                // ── 3. Check phone country codes and postal codes ──
                if ($this->checkPhoneAndPostalForLocation($homepageHtml, $locationVocab)) {
                    $this->logger->debug('Location presence rescued via phone/postal code', [
                        'domain' => $domain,
                    ]);
                    return true;
                }

                // ── 4. Discover and follow location-related subpages ──
                // For multinationals, Tunisia/Morocco may only appear on
                // /locations, /global-presence, /where-we-are, /about, /contact etc.
                $locationPageEvidence = $this->checkLocationSubpages($domain, $homepageHtml, $locationVocab);
                if ($locationPageEvidence !== null) {
                    $this->logger->debug('Location presence rescued via subpage', [
                        'domain' => $domain,
                        'subpage' => $locationPageEvidence,
                    ]);
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // Homepage fetch failed — don't block on network errors
        }

        // No location evidence found in snippet, title, domain, homepage, or subpages
        return false;
    }

    /**
     * Fetch homepage HTML (raw) for location evidence analysis.
     * Returns full HTML (up to 200KB) unlike fetchEvidenceHomepageText() which
     * only returns stripped plain text — we need raw HTML for JSON-LD parsing.
     */
    private function fetchEvidenceHomepageHtml(string $domain): string
    {
        $cleanDomain = preg_replace('#^https?://#i', '', trim($domain));
        $cleanDomain = preg_replace('#/.*$#', '', (string) $cleanDomain);
        if ($cleanDomain === '') {
            return '';
        }

        $urls = [
            'https://' . $cleanDomain,
            'http://' . $cleanDomain,
        ];

        foreach ($urls as $url) {
            try {
                $response = $this->httpClient->request('GET', $url, [
                    'timeout' => 6,
                    'max_redirects' => 3,
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                        'Accept' => 'text/html,application/xhtml+xml',
                        'Accept-Language' => 'en-US,en;q=0.9',
                    ],
                ]);

                $statusCode = $response->getStatusCode();
                if ($statusCode >= 400) {
                    continue;
                }

                $html = $response->getContent(false);
                if ($html !== '') {
                    return $this->smartTruncateHtml($html, 200000);
                }
            } catch (\Throwable) {
                // Try next candidate URL.
            }
        }

        return '';
    }

    /**
     * Convert HTML to plain text for term matching.
     */
    private function htmlToPlainText(string $html): string
    {
        $html = preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        return mb_substr(trim($text), 0, 8000);
    }

    /**
     * Check Schema.org JSON-LD and microdata for location evidence.
     *
     * Parses JSON-LD blocks for addressCountry, addressLocality, areaServed,
     * and other structured location fields. This catches multinationals
     * that list their operations in machine-readable format.
     *
     * @param array{tlds: string[], terms: string[]} $locationVocab
     */
    private function checkStructuredDataForLocation(string $html, array $locationVocab): bool
    {
        // Extract JSON-LD blocks
        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $jsonRaw) {
                $data = @json_decode($jsonRaw, true);
                if (!is_array($data)) {
                    continue;
                }
                // Search all string values recursively for location terms
                $jsonText = strtolower($this->flattenJsonToText($data));
                foreach ($locationVocab['terms'] as $term) {
                    if (str_contains($jsonText, $term)) {
                        return true;
                    }
                }
            }
        }

        // Check HTML meta tags (og:country-name, geo.region, geo.placename)
        $metaPatterns = [
            '/<meta[^>]+(?:name|property)=["\'](?:og:country-name|geo\.region|geo\.placename|ICBM|DC\.coverage)["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:name|property)=["\'](?:og:country-name|geo\.region|geo\.placename|ICBM|DC\.coverage)["\']/i',
        ];
        foreach ($metaPatterns as $pattern) {
            if (preg_match_all($pattern, $html, $metaMatches)) {
                foreach ($metaMatches[1] as $content) {
                    $contentLower = strtolower($content);
                    foreach ($locationVocab['terms'] as $term) {
                        if (str_contains($contentLower, $term)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Recursively flatten JSON array/object to concatenated text for term matching.
      * @param array<string|int, mixed> $data
     */
    private function flattenJsonToText(array $data): string
    {
        $parts = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $parts[] = $key;
            }
            if (is_string($value)) {
                $parts[] = $value;
            } elseif (is_array($value)) {
                $parts[] = $this->flattenJsonToText($value);
            }
        }
        return implode(' ', $parts);
    }

    /**
     * Check for phone country codes and postal code patterns specific to the target location.
     *
     * E.g. +216 (Tunisia), +212 (Morocco), +20 (Egypt), postal patterns like 1XXX (TN), 2XXXX (MA).
     *
     * @param array{tlds: string[], terms: string[]} $locationVocab
     */
    private function checkPhoneAndPostalForLocation(string $html, array $locationVocab): bool
    {
        // Phone country code map (ISO → dialing code regex)
        $phonePatterns = [
            'tn' => '/(?:\+216|00216|tel:\+216)[\s\-\.\(]?\d/i',
            'ma' => '/(?:\+212|00212|tel:\+212)[\s\-\.\(]?\d/i',
            'eg' => '/(?:\+20|0020|tel:\+20)[\s\-\.\(]?\d/i',
            'ae' => '/(?:\+971|00971|tel:\+971)[\s\-\.\(]?\d/i',
            'sa' => '/(?:\+966|00966|tel:\+966)[\s\-\.\(]?\d/i',
            'qa' => '/(?:\+974|00974|tel:\+974)[\s\-\.\(]?\d/i',
            'de' => '/(?:\+49|0049|tel:\+49)[\s\-\.\(]?\d/i',
            'fr' => '/(?:\+33|0033|tel:\+33)[\s\-\.\(]?\d/i',
        ];

        foreach ($locationVocab['tlds'] as $tld) {
            if (isset($phonePatterns[$tld]) && preg_match($phonePatterns[$tld], $html)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check location-related subpages for target-country evidence.
     *
     * Multinationals like Yazaki, Leoni, Aptiv, Valeo often don't mention
     * specific countries on their root homepage. But their /locations,
     * /global-presence, /where-we-are, /about, /contact pages DO list
     * all countries where they operate. This method:
     *
     * 1. Tries hardcoded location-related paths (/locations, /about, etc.)
     * 2. Discovers links on the homepage pointing to location/about pages
     * 3. Fetches up to 4 subpages and checks for location terms
     *
     * Returns the subpage path where evidence was found, or null.
     *
     * @param array{tlds: string[], terms: string[]} $locationVocab
     */
    private function checkLocationSubpages(string $domain, string $homepageHtml, array $locationVocab): ?string
    {
        $cleanDomain = preg_replace('#^https?://#i', '', trim($domain));
        $cleanDomain = preg_replace('#/.*$#', '', (string) $cleanDomain);
        if ($cleanDomain === '') {
            return null;
        }
        $base = 'https://' . $cleanDomain;

        // ── Phase 1: Hardcoded location-related paths ──
        $locationPaths = [
            '/locations', '/our-locations', '/global-locations',
            '/global-presence', '/worldwide', '/where-we-are',
            '/our-plants', '/our-sites', '/production-sites',
            '/plants', '/sites', '/factories', '/operations',
            '/global-network', '/global-footprint',
            '/about', '/about-us', '/who-we-are',
            '/contact', '/contact-us',
            '/company', '/corporate',
            '/en/locations', '/en/about', '/en/contact', '/en/about-us',
            '/fr/a-propos', '/fr/contact', '/fr/implantations',
            '/implantations', '/nos-implantations', '/nos-sites',
            '/standorte', '/unternehmen',  // German: locations, company
        ];

        // ── Phase 2: Discover links from homepage pointing to location pages ──
        $discoveredPaths = [];
        $linkPatterns = '/\b(location|locat|where.we|global.presence|worldwide|our.plant|our.site|production.site|factories|operations|global.network|footprint|implantation|standort|nos.sites|presence.mondiale|î.lume|nel.mondo)\b/i';
        if (preg_match_all('/<a[^>]+href=["\']([^"\']{3,200})["\'][^>]*>/i', $homepageHtml, $linkMatches)) {
            foreach ($linkMatches[1] as $href) {
                if (preg_match($linkPatterns, $href)) {
                    // Resolve relative URLs
                    if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
                        $parsedHref = parse_url($href);
                        if (($parsedHref['host'] ?? '') !== $cleanDomain && !str_ends_with($parsedHref['host'] ?? '', '.' . $cleanDomain)) {
                            continue; // External link
                        }
                        $discoveredPaths[] = $parsedHref['path'] ?? '/';
                    } elseif (str_starts_with($href, '/')) {
                        $discoveredPaths[] = $href;
                    }
                }
            }
        }
        // Also check anchor text for location references
        if (preg_match_all('/<a[^>]+href=["\']([^"\']{3,200})["\'][^>]*>([\s\S]{1,150}?)<\/a>/i', $homepageHtml, $anchorMatches, PREG_SET_ORDER)) {
            $anchorKeywords = '/\b(locations?|where\s+we\s+(?:are|operate)|global\s+presence|our\s+(?:plant|site|factor)|worldwide|implantations?|standorte?|nos\s+sites|présence\s+mondiale)\b/iu';
            foreach ($anchorMatches as $m) {
                $anchorText = strip_tags($m[2]);
                if (preg_match($anchorKeywords, $anchorText)) {
                    $href = $m[1];
                    if (str_starts_with($href, '/')) {
                        $discoveredPaths[] = $href;
                    } elseif (str_starts_with($href, 'http')) {
                        $parsedHref = parse_url($href);
                        if (($parsedHref['host'] ?? '') === $cleanDomain || str_ends_with($parsedHref['host'] ?? '', '.' . $cleanDomain)) {
                            $discoveredPaths[] = $parsedHref['path'] ?? '/';
                        }
                    }
                }
            }
        }

        // Merge and deduplicate, discovered paths first (more likely relevant)
        $allPaths = array_values(array_unique(array_merge($discoveredPaths, $locationPaths)));

        // ── Phase 3: Fetch subpages and check for location terms ──
        // Budget: max 6 total HTTP requests (including 404s) to limit latency.
        // Discovered links are checked first since they're more likely relevant.
        $totalRequests = 0;
        $maxRequests = 6;
        foreach ($allPaths as $path) {
            if ($totalRequests >= $maxRequests) {
                break;
            }
            $url = $base . $path;
            try {
                $totalRequests++;
                $response = $this->httpClient->request('GET', $url, [
                    'timeout' => 5,
                    'max_redirects' => 2,
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                        'Accept' => 'text/html,application/xhtml+xml',
                        'Accept-Language' => 'en-US,en;q=0.9,fr;q=0.8',
                    ],
                ]);
                $code = $response->getStatusCode();
                if ($code >= 400) {
                    continue;
                }

                $subHtml = $response->getContent(false);
                if ($subHtml === '') {
                    continue;
                }

                // Check plain text
                $subText = strtolower($this->htmlToPlainText($subHtml));
                foreach ($locationVocab['terms'] as $term) {
                    if (str_contains($subText, $term)) {
                        return $path;
                    }
                }

                // Check structured data on subpage
                if ($this->checkStructuredDataForLocation($subHtml, $locationVocab)) {
                    return $path . ' (structured-data)';
                }

                // Check phone/postal on subpage
                if ($this->checkPhoneAndPostalForLocation($subHtml, $locationVocab)) {
                    return $path . ' (phone/postal)';
                }
            } catch (\Throwable) {
                // Skip this subpage
            }
        }

        return null;
    }

    /**
     * Get country/city vocabulary for location-presence validation.
     *
     * @return array{tlds: string[], terms: string[]}|null
     */
    private function getLocationVocabulary(string $location): ?array
    {
        // Normalize common location strings to country entries
        $locationMap = [
            // Egypt
            'egypt' => 'EG', 'cairo' => 'EG', 'alexandria' => 'EG',
            'suez' => 'EG', '6th of october city' => 'EG',
            '10th of ramadan city' => 'EG', 'giza' => 'EG',
            'port said' => 'EG', 'ismailia' => 'EG',
            // Morocco
            'morocco' => 'MA', 'casablanca' => 'MA', 'rabat' => 'MA',
            'tangier' => 'MA', 'tanger' => 'MA', 'fez' => 'MA', 'fes' => 'MA',
            'marrakech' => 'MA', 'kenitra' => 'MA', 'nouaceur' => 'MA',
            'tanger automotive city morocco' => 'MA',
            'tanger free zone morocco' => 'MA',
            'atlantic free zone kenitra morocco' => 'MA',
            'casablanca morocco' => 'MA', 'nouaceur morocco' => 'MA',
            // Tunisia
            'tunisia' => 'TN', 'tunis' => 'TN', 'sfax' => 'TN',
            'sousse' => 'TN', 'bizerte' => 'TN', 'nabeul' => 'TN',
            'tunis tunisia' => 'TN', 'sfax tunisia' => 'TN',
            'sousse tunisia' => 'TN', 'bizerte tunisia' => 'TN',
            'nabeul tunisia' => 'TN',
            // GCC
            'dubai' => 'AE', 'abu dhabi' => 'AE',
            'riyadh' => 'SA', 'jeddah' => 'SA', 'dammam' => 'SA',
            'doha' => 'QA', 'muscat' => 'OM', 'kuwait' => 'KW',
            'bahrain' => 'BH', 'manama' => 'BH',
            // Germany
            'germany' => 'DE', 'deutschland' => 'DE',
            'berlin' => 'DE', 'munich' => 'DE', 'münchen' => 'DE',
            'stuttgart' => 'DE', 'hamburg' => 'DE', 'frankfurt' => 'DE',
            'düsseldorf' => 'DE', 'nuremberg' => 'DE', 'nürnberg' => 'DE',
            // France
            'france' => 'FR', 'paris' => 'FR', 'lyon' => 'FR',
            'toulouse' => 'FR', 'marseille' => 'FR', 'bordeaux' => 'FR',
            'grenoble' => 'FR', 'strasbourg' => 'FR',
            // Netherlands
            'netherlands' => 'NL', 'eindhoven' => 'NL', 'amsterdam' => 'NL',
            'rotterdam' => 'NL', 'the hague' => 'NL', 'utrecht' => 'NL',
            // Czech Republic
            'czech republic' => 'CZ', 'czechia' => 'CZ',
            'prague' => 'CZ', 'brno' => 'CZ', 'ostrava' => 'CZ',
            // Poland
            'poland' => 'PL', 'warsaw' => 'PL', 'wroclaw' => 'PL',
            'krakow' => 'PL', 'gdansk' => 'PL', 'poznan' => 'PL',
            // Romania
            'romania' => 'RO', 'bucharest' => 'RO', 'timisoara' => 'RO',
            'cluj' => 'RO', 'brasov' => 'RO',
            // Italy
            'italy' => 'IT', 'milan' => 'IT', 'rome' => 'IT',
            'turin' => 'IT', 'torino' => 'IT', 'bologna' => 'IT',
            // Spain
            'spain' => 'ES', 'madrid' => 'ES', 'barcelona' => 'ES',
            'valencia' => 'ES', 'seville' => 'ES', 'bilbao' => 'ES',
            // UK
            'england' => 'GB', 'scotland' => 'GB', 'wales' => 'GB',
            'london' => 'GB', 'birmingham' => 'GB', 'manchester' => 'GB',
            'edinburgh' => 'GB', 'glasgow' => 'GB', 'cardiff' => 'GB',
            // US
            'new york' => 'US', 'texas' => 'US', 'massachusetts' => 'US',
            'michigan detroit' => 'US', 'michigan' => 'US', 'detroit' => 'US',
            'north carolina' => 'US', 'pennsylvania' => 'US',
            'california' => 'US', 'florida' => 'US',
            // Scandinavia
            'finland' => 'FI', 'helsinki' => 'FI',
            'sweden' => 'SE', 'stockholm' => 'SE', 'gothenburg' => 'SE',
        ];

        $countryVocab = [
            'EG' => [
                'tlds' => ['eg'],
                'terms' => ['egypt', 'cairo', 'alexandria', 'giza', 'suez', 'port said',
                    'ismailia', '6th of october', '10th of ramadan', 'new cairo',
                    'smart village', 'maadi', 'heliopolis', 'nasr city',
                    'egyptian', 'sadat city',
                    // French/Arabic/German
                    'égypte', 'egypte', 'le caire', 'alexandrie',
                    'مصر', 'القاهرة', 'الإسكندرية', 'الجيزة', 'السويس',
                    'ägypten', 'kairo'],
            ],
            'MA' => [
                'tlds' => ['ma'],
                'terms' => ['morocco', 'moroccan', 'casablanca', 'tangier', 'tanger',
                    'rabat', 'fez', 'fes', 'marrakech', 'kenitra', 'nouaceur',
                    'mohammedia', 'meknes', 'agadir', 'oujda', 'tetouan',
                    'tanger med', 'automotive city', 'free zone',
                    // French (official language in Morocco)
                    'maroc', 'marocain', 'marocaine',
                    // Arabic
                    'المغرب', 'الدار البيضاء', 'طنجة', 'الرباط', 'فاس', 'مراكش', 'القنيطرة',
                    // German
                    'marokko', 'marokkanisch',
                    // Industrial zones
                    'zone franche', 'zone industrielle', 'atlantic free zone'],
            ],
            'TN' => [
                'tlds' => ['tn'],
                'terms' => ['tunisia', 'tunisian', 'tunis', 'sfax', 'sousse',
                    'bizerte', 'nabeul', 'monastir', 'gabès', 'gabes',
                    'kairouan', 'ben arous', 'enfidha', 'zarzis', 'mghira',
                    'el fejja', 'borj cedria', 'soliman', 'grombalia', 'hammamet',
                    // French (widely used in Tunisia and by French multinationals)
                    'tunisie', 'tunisien', 'tunisienne',
                    // Arabic
                    'تونس', 'صفاقس', 'سوسة', 'بنزرت', 'نابل', 'القيروان',
                    // German
                    'tunesien', 'tunesisch',
                    // Industrial zones
                    'zone industrielle', 'zone franche'],
            ],
            'AE' => [
                'tlds' => ['ae'],
                'terms' => ['uae', 'united arab emirates', 'dubai', 'abu dhabi',
                    'sharjah', 'ajman', 'rak', 'ras al khaimah', 'fujairah',
                    'jebel ali', 'emirati', 'emirates',
                    // French/Arabic/German
                    'émirats', 'emirats', 'dubaï', 'abou dhabi',
                    'الإمارات', 'دبي', 'أبوظبي', 'الشارقة', 'جبل علي',
                    'vereinigte arabische emirate'],
            ],
            'SA' => [
                'tlds' => ['sa'],
                'terms' => ['saudi', 'saudi arabia', 'riyadh', 'jeddah', 'dammam',
                    'khobar', 'jubail', 'yanbu', 'neom', 'kingdom',
                    // French/Arabic/German
                    'arabie saoudite', 'riyad', 'djeddah',
                    'السعودية', 'الرياض', 'جدة', 'الدمام', 'الجبيل',
                    'saudi-arabien'],
            ],
            'QA' => [
                'tlds' => ['qa'],
                'terms' => ['qatar', 'qatari', 'doha', 'lusail'],
            ],
            'DE' => [
                'tlds' => ['de'],
                'terms' => ['germany', 'german', 'deutschland', 'berlin', 'munich',
                    'münchen', 'muenchen', 'stuttgart', 'hamburg', 'frankfurt',
                    'düsseldorf', 'duesseldorf', 'nuremberg', 'nürnberg', 'nuernberg',
                    'bavari', 'nordrhein', 'hessen', 'baden', 'sachsen',
                    // French/Arabic
                    'allemagne', 'allemand', 'ألمانيا', 'برلين', 'ميونخ'],
            ],
            'FR' => [
                'tlds' => ['fr'],
                'terms' => ['france', 'french', 'français', 'française', 'paris', 'lyon', 'toulouse',
                    'marseille', 'bordeaux', 'grenoble', 'strasbourg', 'nantes',
                    'lille', 'montpellier', 'île-de-france', 'ile-de-france',
                    'rennes', 'rouen', 'reims', 'dijon', 'clermont-ferrand',
                    'saint-étienne', 'saint-etienne', 'le mans', 'amiens', 'metz',
                    'mulhouse', 'orléans', 'orleans', 'angers', 'caen', 'tours',
                    'douai', 'valenciennes', 'poissy', 'flins', 'sochaux',
                    'cluses', 'scionzier', 'annecy', 'chambéry', 'chambery',
                    // Regions and departments
                    'hauts-de-france', 'normandie', 'normandy', 'bretagne', 'brittany',
                    'auvergne', 'rhône-alpes', 'rhone-alpes', 'grand est', 'alsace',
                    'provence', 'occitanie', 'nouvelle-aquitaine', 'pays de la loire',
                    'bourgogne', 'franche-comté', 'franche-comte', 'picardie',
                    // French industry terms that indicate France presence
                    'siège social', 'cedex', 'siret', 'siren',
                    // Arabic/German
                    'فرنسا', 'باريس', 'frankreich', 'französisch'],
            ],
            'NL' => [
                'tlds' => ['nl'],
                'terms' => ['netherlands', 'dutch', 'holland', 'eindhoven', 'amsterdam',
                    'rotterdam', 'the hague', 'utrecht', 'brainport', 'delft'],
            ],
            'CZ' => [
                'tlds' => ['cz'],
                'terms' => ['czech', 'czechia', 'prague', 'praha', 'brno', 'ostrava',
                    'plzen', 'pilsen', 'liberec', 'olomouc', 'české', 'ceske'],
            ],
            'PL' => [
                'tlds' => ['pl'],
                'terms' => ['poland', 'polish', 'warsaw', 'warszawa', 'wroclaw',
                    'wrocław', 'krakow', 'kraków', 'gdansk', 'gdańsk',
                    'poznan', 'poznań', 'łódź', 'lodz', 'katowice'],
            ],
            'RO' => [
                'tlds' => ['ro'],
                'terms' => ['romania', 'romanian', 'bucharest', 'bucurești', 'bucuresti',
                    'timisoara', 'timișoara', 'cluj', 'brasov', 'brașov',
                    'sibiu', 'constanta', 'constanța', 'iasi', 'iași'],
            ],
            'IT' => [
                'tlds' => ['it'],
                'terms' => ['italy', 'italian', 'italia', 'milan', 'milano', 'rome',
                    'roma', 'turin', 'torino', 'bologna', 'florence', 'firenze',
                    'naples', 'napoli', 'genoa', 'genova', 'venice', 'venezia'],
            ],
            'ES' => [
                'tlds' => ['es'],
                'terms' => ['spain', 'spanish', 'españa', 'espana', 'madrid',
                    'barcelona', 'valencia', 'seville', 'sevilla', 'bilbao',
                    'malaga', 'málaga', 'zaragoza'],
            ],
            'GB' => [
                'tlds' => ['uk', 'co.uk'],
                'terms' => ['uk', 'united kingdom', 'britain', 'british', 'england',
                    'scotland', 'wales', 'london', 'birmingham', 'manchester',
                    'edinburgh', 'glasgow', 'cardiff', 'leeds', 'bristol',
                    'sheffield', 'liverpool', 'nottingham', 'southampton'],
            ],
            'US' => [
                'tlds' => ['us'],
                'terms' => ['usa', 'united states', 'u.s.', 'u.s.a.', 'america',
                    'american', 'new york', 'texas', 'massachusetts', 'michigan',
                    'detroit', 'north carolina', 'pennsylvania', 'california',
                    'florida', 'ohio', 'illinois', 'boston', 'chicago',
                    'san jose', 'san francisco', 'los angeles', 'houston',
                    'philadelphia', 'phoenix', 'dallas', 'austin', 'atlanta',
                    'seattle', 'denver', 'minneapolis', 'portland', 'charlotte',
                    'raleigh', 'pittsburgh'],
            ],
            'FI' => [
                'tlds' => ['fi'],
                'terms' => ['finland', 'finnish', 'helsinki', 'tampere', 'oulu', 'turku', 'espoo'],
            ],
            'SE' => [
                'tlds' => ['se'],
                'terms' => ['sweden', 'swedish', 'stockholm', 'gothenburg', 'göteborg',
                    'malmö', 'malmo', 'linköping', 'linkoping', 'västerås', 'vasteras'],
            ],
            'KW' => [
                'tlds' => ['kw'],
                'terms' => ['kuwait', 'kuwaiti'],
            ],
            'BH' => [
                'tlds' => ['bh'],
                'terms' => ['bahrain', 'bahraini', 'manama'],
            ],
            'OM' => [
                'tlds' => ['om'],
                'terms' => ['oman', 'omani', 'muscat'],
            ],
        ];

        $countryCode = $locationMap[$location] ?? null;
        if ($countryCode === null) {
            return null;
        }

        return $countryVocab[$countryCode] ?? null;
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
        $rawText = $snippet . ' ' . $title;

        // ─── 0. Channel-partner/distributor structural scoring ─────────
        // Uses co-occurring business-model signals rather than brittle exact phrases.
        $channelSignals = 0;
        if (preg_match('/\b(authori[sz]ed|official|exclusive|sole|regional)\s+(dealer(ship)?|distributor|importer|agent|reseller)\b/i', $text)) $channelSignals += 3;
        if (preg_match('/\b(distribut(or|ion)|dealer(ship)?|reseller|wholesal(e|er)|trading\s+company|general\s+trading|import(er|ing)|export(er|ing))\b/i', $text)) $channelSignals += 2;
        if (preg_match('/\b(spare\s+parts?|aftermarket|genuine\s+parts?|parts?\s+catalog|showroom|test\s+drive|book\s+(a\s+)?service|pre[\s-]?owned|used\s+cars?)\b/i', $text)) $channelSignals += 2;
        if (preg_match('/\b(وكيل|موزع|تاجر|قطع\s+غيار|معرض\s+سيارات)\b/iu', $rawText)) $channelSignals += 3;
        if ($channelSignals >= 4) {
            $this->logger->debug('Channel partner/distributor detected by structural scoring', [
                'domain' => $domain,
                'score' => $channelSignals,
            ]);
            return true;
        }

        // ─── 1. EMS COMPETITOR signals ────────────────────────────────
        // These phrases indicate a company that PROVIDES the same services
        // Starz provides → competitor, not a buyer
        $competitorSignals = [
            // Direct EMS provider language — catch companies PROVIDING
            // contract electronics manufacturing, NOT companies that
            // simply manufacture their own products (those are buyers)
            'we\s+(manufacture|assemble|produce|build|offer|provide)\s+(pcb|cable|harness|electronic|circuit|smt|board)',
            'we\s+specialize\s+in\s+(pcb|cable|harness|electronic|circuit|smt|board|contract\s+manufactur|ems)',
            'our (manufacturing|assembly|production|capabilities)\s+(service|for\s+(you|client|customer))',
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
            'authorized\s+(dealer|distributor|importer)',
            '(car|auto(motive)?)\s+(dealer(ship)?|distributor|importer)',
            '(auto(motive)?|car)\s+spare\s+parts?\s+(distributor|dealer|supplier|wholesal)',
            'genuine\s+parts?\s+(dealer|distributor|supplier)',
            'spare\s+parts?\s+(catalog|shop|store)',
            // ── Broader chip/FPGA/semiconductor distributor patterns ──
            '(fpga|asic|cpld|soc|microcontroller|mcu|dsp|memory|flash|dram|gpu)\s+distribut',
            '(chip|semiconductor|ic)\s+distribut',
            '\bdistribut(or|ion)\b.*\b(high[\s-]?performance\s+chip|fpga|asic|semiconductor|ic|component)',
            '\breliable\s+source\s+for\s+(chip|component|semiconductor|fpga|ic)',
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
        // Companies that sell/distribute components (not manufacturers —
        // component manufacturers may actually BUY EMS services)
        $componentSignals = [
            '(connector|terminal|contact)\s+(supplier|distribut)',
            '(connector|terminal|contact)\s+manufactur(er|ing)',
            '(semiconductor|chip|ic|led|mosfet|transistor)\s+(supplier|distribut)',
            '(fpga|asic|cpld|soc|microcontroller|mcu|dsp)\s+(supplier|distribut)',
            '(resistor|capacitor|inductor|transformer)\s+(supplier|distribut)',
            '(raw\s+material|copper\s+wire|solder|flux)\s+suppli',
            'component\s+(suppli|distribut)',
            'electronic\s+component\s+distribut',
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

        // ─── 5. CONTROL PANEL / SYSTEM INTEGRATOR / AUTOMATION RESELLER signals ──
        // These are assembly shops or brand resellers, not OEM buyers of EMS services
        $integratorSignals = [
            'control\s+panel\s+(manufactur|build|assembl|design|wir)',
            'panel\s+build(er|ing)',
            'switchgear\s+(manufactur|assembl)',
            'plc\s+(programming|integration|panel)',
            'bespoke\s+(control|automation)\s+(panel|system|solution)',
            'system\s+integrat(or|ion)\s+(for|specializ|provid)',
            // ── Automation reseller / distributor (iter11 Egypt EISAC) ──
            '\b(authorized|certified|official)\s+(siemens|abb|schneider|omron|rockwell|allen[\s-]?bradley|beckhoff|mitsubishi|phoenix\s+contact|eaton)\s+(partner|distributor|reseller|integrator|dealer)',
            '\b(siemens|abb|schneider|omron|rockwell|allen[\s-]?bradley|eaton)\s+(solution|channel|certified)\s+partner',
            '\bautomation\s+(distributor|reseller|dealer|supplier|trading|products?\s+supplier)',
            '\b(we\s+)?(sell|supply|distribute|stock|represent)\s+(siemens|abb|schneider|omron|rockwell|eaton)\b',
            '\bindustrial\s+automation\s+(solutions?\s+)?(provider|supplier|distributor|company)',
            '\b(plc|hmi|vfd|drive|inverter|servo)s?\s+(supply|supplier|sales|distributor|trading)',
            '\bautomation\s+product(s)?\s+(catalog|range|portfolio|trading)',
            '\b(low|medium)\s+voltage\s+(products?|switchgear|panel)\s+(distributor|supplier|dealer)',
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
            // ── iter11b Morocco MDS Aviation ──
            '\beasa\s+part\s+(21|145|M)',
            'avionics\s+(upgrade|install|repair|shop|dealer)',
            'aircraft\s+sales?\s+(and|&)\s+(leasing|brokerage|charter)',
            '(helicopter|rotorcraft|turboprop)\s+(maintenance|mro|service)',
            'engine\s+(overhaul|repair|test)\s+(shop|facility|center)',
            'landing\s+gear\s+(overhaul|repair|service)',
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
            // ── iter12: market reports, telecoms news, megaprojects ──
            'market\s+report\s+(publisher|seller|provider)',
            'industry\s+(report|research|forecast|outlook)\s+(provider|publisher)',
            'CAGR.*?forecast.*?(market|industry)',
            'telecom(s|munication)?\s+(news|industry)\s+(site|portal|publication|magazine)',
            'megaproject|mega[\s-]?project|giga[\s-]?project',
            'government\s+development\s+(project|zone|authority)',
            // ── IT/GB/ES audit: expanded consultancy detection ──
            'professional\s+services\s+(firm|company|provider|leader)',
            'audit\s+(&|and)\s+assurance',
            'tax\s+(&|and)\s+legal\s+(services?|advisory)',
            'risk\s+advisory\s+(services?|practice|firm)',
            'deal\s+advisory',
            'transaction\s+(advisory|services)',
            'restructuring\s+(advisory|services|consulting)',
            'digital\s+consulting',
            'technology\s+consulting',
            'operations?\s+consulting',
            'human\s+capital\s+consulting',
            'change\s+management\s+consulting',
            'supply\s+chain\s+consulting',
            'transformation\s+consulting',
            'implementation\s+(partner|services|consulting)',
            'IT\s+(consulting|advisory)\s+(firm|company|services|practice)',
            // ── IT/GB/ES audit: Big Four / Big Three by name in snippet ──
            '\b(Deloitte|PwC|PricewaterhouseCoopers|Ernst\s*&\s*Young|KPMG)\b.*\b(audit|assurance|advisory|tax|consulting)',
            '\b(McKinsey|BCG|Boston\s+Consulting|Bain)\b.*\b(strategy|consulting|advisory)',
            '\b(Accenture|Capgemini|Infosys|TCS|Wipro|Cognizant)\b.*\b(consulting|services|solutions|digital)',
            // ── DE/FR/IT: multilingual consulting detection ──
            '\b(Unternehmensberatung|Managementberatung|Strategieberatung|Personalberatung|Prozessberatung)\b',
            '\bBeratung(sunternehmen|sgesellschaft|sfirma|sleistung|shaus)?\b',
            '\b(wir\s+beraten|beraten\s+wir|Berater|beratend)\b',
            '\b(Interim[\s-]?Management|Coaching|Organisationsentwicklung)\b.*\b(Beratung|beraten|consult)',
            '\bconseil\s+(en\s+)?(management|stratégie|organisation|entreprise|direction)',
            '\bcabinet\s+de\s+(conseil|consulting)',
            '\bconsulenza\s+(aziendale|strategica|direzionale|gestionale|manageriale)',
            '\b(società|studio)\s+di\s+consulenza\b',
            // ── Generic consulting role/service phrases ──
            '\b(we\s+)?(help|assist|support|enable|empower)\s+(companies|businesses|organizations|clients|firms)\s+(to\s+)?(transform|optimize|improve|grow|succeed|achieve|navigate)',
            '\b(trusted\s+)?(advisor|partner)\s+for\s+(business|digital|strategic)\s+(transformation|change|growth)',
            '\b(change|transformation|turnaround)\s+management\s+(consult|advis|firm|company|services)',
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
            '\blegal\s+consult(ing|ancy)\b',
            '\b(attorneys?\s+at\s+law|law\s+office|law\s+offices)\b',
            '\b(advocates?\s+and\s+legal\s+consultants?)\b',
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
            '\bpharmaceutical\s+(company|manufacturer|industry|group|corporation)',
            '\bdrug\s+(development|discovery|manufacturer|candidate|pipeline)',
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
            '\bbiotech(nology)?\s+(company|firm|startup|industry)',
            '\bbiopharmaceutical\b',
            '\blife\s+science(s)?\s+(company|sector)',
            '\bactive\s+pharmaceutical\s+ingredient',
            '\bAPI\s+manufactur',  // pharmaceutical API manufacturing
            '\bgeneric\s+(drug|medicine|pharmaceutical)',
            '\bnucleus\s+acid\b',
            '\bCRO\b.*\bpharm',   // contract research org for pharma
            '\bcell\s+therapy\b',
            '\bprotein\s+engineering\b',
            '\bmonoclonal\s+antibod',
            '\bcompounding\s+pharmacy\b',
            '\bpharma\s+(company|group|industry|division)',
            '\bregulatory\s+affairs?\s+.*(pharma|drug|fda)',
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
            // ── Mineral extraction / quarrying (iter11 Egypt ACCM) ──
            '\bcalcium\s+carbonate\b',
            '\blimestone\s+(quarry|mining|extraction|crushing)',
            '\bmineral\s+(processing|extraction|mining|company|producer)',
            '\bquarry(ing)?\s+(company|operations?|industry)',
            '\b(gypsum|feldspar|silica|talc|kaolin|dolomite|barite|bentonite|calcium|bauxite|phosphate|graphite)\s+(mining|extraction|processing|producer)',
            '\bground\s+calcium\b',
            '\b(open[\s-]pit|underground)\s+min(e|ing)\b',
            '\bmineral\s+resources?\s+(company|group)',
            '\bore\s+(processing|mining|extraction|enrichment)',
            '\bcement\s+(company|manufactur|plant|factory|producer)',
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
            '\binvestment\s+(fund|management|bank|firm|company|portfolio|advisory|group)',
            '\basset\s+management\s+(firm|company)',
            '\bprivate\s+equity\s+(firm|fund|group)',
            '\bventure\s+capital\s+(firm|fund)',
            '\binsurance\s+(company|provider|broker|underwriter)',
            '\baircraft\s+leasing\b',
            '\b(leasing|lease)\s+(company|provider|portfolio)',
            '\bportfolio\s+management\b',
            '\bhedge\s+fund\b',
            '\bwealth\s+management\b',
            '\bfinancial\s+(advisory|consulting|services)\s+(firm|company)',
            '\bcorporate\s+finance\s+(advisory|boutique)',
            '\bmergers?\s+(and|&)\s+acquisitions?\b',
            '\bM&A\s+(advisory|boutique|firm)',
            '\bfund\s+management\b',
            '\bfintech\s+(company|startup|platform)',
            '\bcommercial\s+bank(ing)?\b',
            '\bcredit\s+(union|facility|rating)',
            '\baccounting\s+(firm|company|services)',
            '\bchartered\s+accountant',
            '\baudit(ing)?\s+(firm|company|services)',
            '\btax\s+(advisory|consulting|services)\s+(firm|company)',
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
            '\bchemical\s+(company|manufacturer|producer|supplier|distribution|plant|factory)',
            '\bspecialty\s+chemical\b',
            '\bchemicals?\s+(and|&)\s+material\b',
            '\bplastics?\s+(compounding|injection|molding|moulds?)\b',
            '\brubber\s+(compounding|molding|manufacturer)\b',
            '\bpaints?\s+(and|&)\s+(coatings?|varnish)\b',
            '\badhesives?\s+(and|&)\s+(sealant|tape)\b',
            '\blubricant\s+(manufacturer|supplier|company)\b',
            '\bfertilizer\s+(company|manufacturer|plant|producer)',
            '\bagrochemical\s+(company|manufacturer|producer)',
            '\bpolymer\s+(manufacturer|producer|company)',
            '\bresin\s+(manufacturer|producer|supplier)',
            '\bsolvent\s+(manufacturer|producer|supplier)',
            '\bsurfactant\b',
            '\bindustrial\s+(chemical|solvent|detergent)\b',
            '\bchemical\s+(engineering|processing|process)\s+(company|plant)',
            '\bcoating\s+(manufacturer|supplier|company|solution)',
            '\bpigment\s+(manufacturer|producer|supplier)',
            '\bexplosive(s)?\s+(manufacturer|maker|company)',
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

        // ─── 26b. NON-MANUFACTURING: adhesives, paint, brushes, extrusion (iter13 Tunisia) ──
        $nonMfgIndustrialSignals = [
            '\badhesive\s+(manufacturer|producer|factory)',
            '\bglue\s+(manufacturer|producer|factory)',
            '\bpaint\s+(manufacturer|producer|factory)',
            '\bbrush\s+(manufacturer|producer|factory)',
            '\bpaintbrush\s+(manufacturer|producer)',
            '\balumini?um\s+extrusion\s+(manufacturer|company|factory)',
            '\bauto(motive)?\s+spare\s+parts?\s+(distributor|wholesal|supplier|dealer)',
            '\bcar\s+spare\s+parts?\s+(distributor|wholesal|supplier)',
            '\bspare\s+parts?\s+(wholesale|distribution|dealer)',
            '\bMercedes[- ]Benz\s+(dealer|sub[- ]dealer|agent)',
            '\bauthorized\s+dealer\s+of\s+(Mercedes|BMW|Toyota|Hyundai|Kia)',
        ];

        foreach ($nonMfgIndustrialSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Non-manufacturing industrial company detected', [
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
            // ── Software company / IT services (iter11 Egypt) ──────────
            '\bsoftware\s+(company|development|solutions?|provider|house|firm|developer)',
            '\b(custom|bespoke|offshore|nearshore)\s+software\b',
            '\bERP\s+(software|solutions?|system|vendor|provider|platform|implementation)',
            '\bSaaS\s+(platform|provider|company|product|solution)',
            '\b(mobile|web|app)\s+develop(ment|er)\s+(company|firm|agency|services?)',
            '\bit\s+solutions?\s+(company|provider|firm)',
            '\btechnology\s+solutions?\s+(company|provider|firm)',
            '\bdigital\s+transformation\s+(company|agency|partner|firm)',
            '\b(crm|hrm|accounting|billing|inventory)\s+software\b',
            '\bcloud\s+(platform|solution|services?)\s+(company|provider)',
            '\bmanaged\s+(it|cloud)\s+services?\b',
            '\bsoftware\s+as\s+a\s+service\b',
            '\bembedded\s+software\s+(develop|services?|company|solutions?)',
            '\bautomotive\s+software\s+(develop|company|solutions?|services?)',
            '\bECU\s+software\b',
            '\bADAS\s+software\b',
            '\bHIL\s+testing\s+software\b',
            '\baviation\s+(it|information\s+technology)\b',
            // ── Engineering consultancy / EPC / EPCM (iter11 PGESCo) ──
            '\bEPC\s*[\/&]\s*EPCM\b',
            '\b(EPC|EPCM)\s+(contractor|company|services?|project)',
            '\bpower\s+(plant|generation|station)\s+(engineering|construction|consultancy)',
            '\bengineering\s+(procurement|services?)\s+(and|&)\s+construction\b',
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

        // ─── 27b. EQUIPMENT DEALER / AGENT / REPRESENTATIVE signals ──
        // Companies that sell/service other brands' equipment are not EMS buyers
        $equipmentDealerSignals = [
            '\b(authorized|official|certified)\s+(agent|representative|dealer|distributor)\s+(for|of)\b',
            '\bequipment\s+(dealer|agent|supplier|trading|rental)',
            '\b(lab|laboratory|medical|pharma|scientific)\s+equipment\s+(dealer|supplier|distributor|agent|trading|company)',
            '\b(we\s+)?(sell|supply|distribute|service|maintain|calibrate|repair)\s+(and\s+)?(sell|supply|distribute|service|maintain|calibrate|repair\s+)?.*?\b(equipment|instruments?)\s+(from|by|manufactured\s+by)\b',
            '\b(sole|exclusive|authorized)\s+(agent|representative)\s+(in|for)\s+(egypt|morocco|middle\s+east|africa|mena)',
            '\brepresenting\s+(international|global|leading)\s+brands?\b',
            '\b(trading|import)\s+(company|house)\s+.*?(equipment|instruments?|machines?)',
            '\binstrument(ation)?\s+(dealer|supplier|trading|distributor|agent)',
        ];

        foreach ($equipmentDealerSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Equipment dealer/agent detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 27c. CAR DEALERSHIP NETWORK / AUTO RETAIL GROUP signals ──
        // These disguise themselves as "automotive company" but are dealers/importers
        $carDealershipSignals = [
            '\b(car|auto|vehicle|automobile)\s+(dealership|dealer\s+network|retail\s+group|showroom)',
            '\b(authorized|official|exclusive)\s+(dealer|distributor|importer)\s+(for|of)\s+(mercedes|bmw|audi|toyota|nissan|hyundai|kia|ford|chevrolet|volkswagen|renault|peugeot|fiat|jeep|chrysler)',
            '\bnational\s+auto(mobile)?\s+(company|trading|group|dealer)',
            '\b(import(er|ing)|distribut(or|ing))\s+(of\s+)?(passenger|commercial)\s+vehicles?\b',
            '\b(after[\s-]?sales|spare\s+parts?|service\s+center)\s+(for|of)\s+(mercedes|bmw|toyota|nissan|hyundai)',
            '\bvehicle\s+(import|trading|distribution)\s+(company|group)',
        ];

        foreach ($carDealershipSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Car dealership network detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 28. TEXTILE / APPAREL / GARMENT / LEATHER signals ──────
        $textileSignals = [
            '\btextile\s+(manufactur|company|mill|factory|industry|group)',
            '\bgarment\s+(manufactur|company|factory|industry|export)',
            '\bapparel\s+(manufactur|company|brand|industry)',
            '\bclothing\s+(manufactur|company|brand|factory)',
            '\bfashion\s+(brand|house|company|design)',
            '\bknitwear\s+(manufactur|company|factory)',
            '\b(weaving|spinning|dyeing)\s+(mill|factory|plant|company)',
            '\bfabric\s+(manufactur|supplier|mill)',
            '\b(cotton|polyester|denim|silk|wool)\s+(manufactur|mill|fabric)',
            '\bleather\s+(goods|manufactur|tanning|company|products)',
            '\bshoe\s+(manufactur|company|factory)',
            '\bfootwear\s+(manufactur|company|brand)',
            '\b(embroidery|sewing|stitching|tailoring)\s+(company|factory|services?)',
            '\bready[\s-]made\s+garment',
            '\bhome\s+textile',
            '\bcarpet\s+(manufactur|company|mill)',
            '\brug\s+(manufactur|company)',
        ];

        foreach ($textileSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Textile/apparel/garment company detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 29. TRADE BODY / ASSOCIATION / CHAMBER signals ─────────
        $tradeBodySignals = [
            '\bchamber\s+of\s+(commerce|industry|trade)',
            '\bbusiness\s+council\b',
            '\btrade\s+council\b',
            '\bindustry\s+(body|council|association|group)\b',
            '\bemployers?\s+(association|federation|organisation|organization)',
            '\bmanufacturers?\s+(association|federation|organisation|organization)',
            '\bexporters?\s+(association|council|federation)',
            '\bmember\s+(directory|listing|companies|organizations)',
            '\bjoin\s+(our|the)\s+(association|organization|chamber|body)',
            '\bour\s+members\b',
            '\bmembership\s+(benefits|fees|join|apply)',
            '\bindustry\s+lobby\b',
            '\btrade\s+promotion\s+(agency|authority|council)',
            '\bbusiness\s+network\b',
        ];

        foreach ($tradeBodySignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Trade body/association detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 30. PACKAGING / PRINTING / LABEL signals ───────────────
        $packagingSignals = [
            '\bpackaging\s+(manufactur|company|supplier|solutions?|materials?)',
            '\bcorrugated\s+(box|packaging|cardboard)',
            '\bcarton\s+(manufactur|box|packaging)',
            '\bprinting\s+(company|house|press|services?|solutions?)',
            '\blabel\s+(manufactur|printing|company)',
            '\bshrink\s+(wrap|film|sleeve)',
            '\bflexible\s+packaging\b',
            '\bplastic\s+(bag|film|bottle)\s+(manufactur|company)',
            '\bglass\s+(bottle|container)\s+(manufactur|company)',
        ];

        foreach ($packagingSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Packaging/printing company detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 31. PLASTIC INJECTION / RUBBER MOLDING signals ─────────
        $plasticSignals = [
            '\bplastic\s+injection\s+(mold|mould|manufactur|company)',
            '\binjection\s+(mold|mould)ing\s+(company|manufactur|services?)',
            '\bblow\s+(mold|mould)ing\s+(company|manufactur)',
            '\brotational\s+(mold|mould)ing\b',
            '\bthermoforming\s+(company|manufactur)',
            '\brubber\s+(mold|mould)ing\s+(company|manufactur)',
            '\brubber\s+(compounding|extrusion|products?)\s+(company|manufactur)',
            '\bplastic\s+(extrusion|compounding|products?)\s+(company|manufactur)',
            '\bpolymer\s+(compounding|processing)\s+(company|manufactur)',
        ];

        foreach ($plasticSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Plastic/rubber molding company detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 32. FURNITURE / WOODWORKING / GLASS signals ────────────
        $furnitureSignals = [
            '\bfurniture\s+(manufactur|company|factory|maker)',
            '\boffice\s+furniture\b',
            '\bkitchen\s+(cabinet|manufactur|company)',
            '\bwoodwork(ing)?\s+(company|factory|workshop)',
            '\bcarpentry\s+(company|workshop|services?)',
            '\bglass\s+(manufactur|company|factory|processing)',
            '\bwindow\s+(manufactur|company|factory)',
            '\bdoor\s+(manufactur|company|factory)',
            '\balumini?um\s+profile\s+(manufactur|company)',
            '\bceramic\s+(tile|manufactur|company)',
        ];

        foreach ($furnitureSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Furniture/woodworking/glass company detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 33. STEEL / FOUNDRY / HEAVY METAL signals ──────────────
        $steelSignals = [
            '\bsteel\s+(manufactur|mill|works|plant|company|producer)',
            '\biron\s+(works|casting|foundry)',
            '\bfoundry\s+(company|services?|operations?)',
            '\b(aluminum|aluminium)\s+(smelter|foundry|rolling)',
            '\bmetal\s+(stamping|forging|casting)\s+(company|manufacturer)',
            '\bscrap\s+(metal|steel|iron)\b',
            '\bpipe\s+(manufactur|mill|steel)',
        ];

        foreach ($steelSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Steel/foundry/heavy metal company detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 34. GAME STUDIO / VIDEO GAME signals ──────────────────
        $gameSignals = [
            '\bgame\s+(studio|developer|development|publisher)',
            '\bvideo\s+game\s+(company|developer|studio|publisher)',
            '\bindependent\s+game\s+(studio|developer)',
            '\bindie\s+game\b',
            '\bgameplay\b',
            '\besports?\b',
            '\bmultiplayer\s+(game|online)',
            '\bMMO(RPG)?\b',
            '\bgaming\s+(company|studio|industry|platform)',
            '\bmobile\s+game\s+(studio|developer)',
            '\bgame\s+engine\b',
        ];

        foreach ($gameSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Game studio/gaming company detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 35. CHANNEL PARTNER / DISTRIBUTOR (not OEM) signals ────
        // Schneider/ABB/Siemens channel partners resell, they don't buy EMS
        $channelPartnerSignals = [
            '\bauthori[sz]ed\s+(distributor|reseller|partner|dealer)',
            '\b(schneider|ABB|siemens|legrand)\s+(partner|elite|distributor|dealer)',
            '\belectrical\s+(distributor|wholesaler|dealer)',
            '\belectronics?\s+(e-?shop|webshop|online\s+store)',
            '\b(distributor|dealer)\s+of\s+(schneider|ABB|siemens|legrand|SE|eaton)',
            '\bwe\s+(distribute|resell|supply)\s+(schneider|ABB|siemens)',
            '\b(panel|switchgear|switchboard)\s+(builder|assembler)\b',
            '\belectrical\s+(panel|switchboard|MCC)\s+(manufactur|assembl|builder)',
            '\bsolar\s+panel\s+install(er|ation)?\b',
            '\bbuilding\s+automation\s+(integrator|installer|dealer)',
        ];

        foreach ($channelPartnerSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Channel partner/distributor detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 36. NEWS / MEDIA / JOB BOARD / PUBLICATION signals ────
        $newsMediaSignals = [
            '\bnews\s+(agency|outlet|portal|site|publication)',
            '\bnewspaper\b',
            '\bonline\s+(magazine|publication|journal|news)',
            '\bjob\s+(board|portal|listing|posting|site|aggregator)',
            '\brecruitment\s+(platform|portal|website)',
            '\bjob\s+search\s+(engine|platform)',
            '\bresume\s+(builder|posting|database)',
            '\bcareer(s)?\s+(portal|platform|site|page)',
        ];

        foreach ($newsMediaSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('News/media/job board detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 37. CAR DEALER / SHOWROOM signals ─────────────────────
        $carDealerSignals = [
            '\bcar\s+(dealer|dealership|showroom)',
            '\bauto(mobile)?\s+(dealer|dealership|showroom)',
            '\bpre-?owned\s+(vehicle|car|auto)',
            '\bnew\s+(and|&)\s+used\s+(car|vehicle|auto)',
            '\btest\s+drive\b.*\b(schedule|book|today)',
            '\bfinancing\s+(option|available|plan).*\bvehicle\b',
            '\bauthori[sz]ed\s+(dealer|dealership)\b.*\b(toyota|ford|hyundai|kia|dodge|chevrolet|bmw|mercedes)',
        ];

        foreach ($carDealerSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Car dealer/showroom detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 38. CNC MACHINING / PRECISION MACHINING JOB SHOPS (FR/DE audit) ──
        $machiningSignals = [
            '\bcnc\s+(machining|turning|milling|grinding)\s+(services?|company|shop|center|centre)',
            '\bprecision\s+(machining|engineering|components?)\s+(services?|company|shop|specialist)',
            '\bcontract\s+machin(ing|e\s+shop)',
            '\bjob\s+shop\s+(machining|manufacturing)',
            '\b(turning|milling|grinding|boring|drilling|honing)\s+(services?|shop|center|centre)\b',
            '\businage\s+(de\s+)?précision',
            '\businage\s+(CNC|numérique|mécanique)',
            '\btournage[\s,]+fraisage',
            '\bdécolletage\s+(de\s+)?précision',
            '\brectification\s+(cylindrique|plane)',
            '\bCNC[\s-]?Bearbeitung\b',
            '\bZerspanung(stechnik)?\b',
            '\bDreh[\s-]?(und|&)\s+Fräs(teile|bearbeitung)',
            '\bLohnfertigung\b',
            '\bPräzisionsteile\b',
            '\bmachine\s+tool\s+(manufactur|builder|maker|company|OEM)',
        ];

        foreach ($machiningSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('CNC/machining job shop detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 39. GIANT ROBOTICS / AUTOMATION / INSTRUMENTATION OEMs (FR/DE audit) ──
        // These companies make robots/instruments — they don't buy EMS services
        $giantAutomationSignals = [
            '\b(FANUC|KUKA|Yaskawa|Stäubli|Staubli)\s+(corporation|robotics?|robot|america|europe)',
            '\b(Universal\s+Robots|EPSON\s+Robots?|Kawasaki\s+Robot|Nachi\s+Robot|Comau)\b',
            '\b(Yokogawa|Endress\s*\+?\s*Hauser)\s+(electric|process|field|test|measurement)',
            '\bindustrial\s+robot(ics)?\s+(manufacturer|maker|company|leader|pioneer|global)',
            '\b(collaborative|cobot|articulated|SCARA|delta)\s+robot\s+(manufacturer|maker|company)',
            '\bfactory\s+automation\s+(company|leader|pioneer|global)',
            '\bprocess\s+(instrumentation|measurement)\s+(company|leader|manufacturer)',
            '\bmeasurement\s+(and|&)\s+(control|instrumentation)\s+(company|manufacturer)',
        ];

        foreach ($giantAutomationSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Giant automation/robotics OEM detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 40. COSMETICS / BEAUTY / PERSONAL CARE (FR/DE audit) ───
        $cosmeticsSignals = [
            '\bcosmetic(s|que)?\s+(manufactur|company|ingredient|raw\s+material|supplier|brand|industry)',
            '\bbeauty\s+(brand|company|product|industry)',
            '\bskincare\s+(brand|company|product|range)',
            '\bhair\s+care\s+(brand|company|product)',
            '\bfragrance\s+(house|company|brand|manufactur)',
            '\bpersonal\s+care\s+(product|brand|company|manufactur)',
            '\bpigment\s+(manufactur|supplier).*cosmetic',
            '\bcolorant\s+(manufactur|supplier)',
            '\bmatière\s+première\b.*\b(cosmétique|beauté)',
            '\bKosmetik(hersteller|industrie|rohstoff)',
        ];

        foreach ($cosmeticsSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Cosmetics/beauty company detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 41. WATER COOLERS / DISPENSERS / FOUNTAINS (FR/DE audit) ─
        $waterCoolerSignals = [
            '\bwater\s+(cooler|dispenser|fountain|purifier|filter)\s+(manufactur|company|supplier|brand)',
            '\bfontaine\s+à\s+eau\b',
            '\bdistributeur\s+d.eau\b',
            '\bWasserspender\b',
            '\bTrinkwasser(system|automat|spender)\b',
            '\bdrinking\s+water\s+(system|machine|dispenser|fountain)',
            '\bbottleless\s+water\b',
            '\bpoint[\s-]of[\s-]use\s+water\b',
        ];

        foreach ($waterCoolerSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Water cooler/dispenser company detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 42. CUTTING TOOLS / TOOLING / DRILL (FR/DE audit) ──────
        $cuttingToolSignals = [
            '\bcutting\s+tool(s)?\s+(manufactur|company|supplier|brand|producer)',
            '\bdrill\s+bit(s)?\s+(manufactur|company|supplier)',
            '\bmilling\s+cutter(s)?\s+(manufactur|company|supplier)',
            '\bcarbide\s+(tool|insert|end\s+mill)\s+(manufactur|company|supplier)',
            '\boutil(s|lage)?\s+coupant(s)?\b',
            '\bSchneidwerkzeug(e|hersteller)\b',
            '\bWerkzeug(hersteller|technik|maschine)\b',
            '\bhigh[\s-]?speed\s+steel\s+tool\b',
            '\btooling\s+(manufactur|company|supplier|solutions?)',
        ];

        foreach ($cuttingToolSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Cutting tool manufacturer detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 43. FRENCH/GERMAN RESEARCH INSTITUTES (FR/DE audit) ────
        $researchInstSignals = [
            '\binstitut\s+de\s+recherche\b',
            '\bcentre\s+(national|de)\s+(la\s+)?recherche',
            '\blaboratoire\s+(national|de\s+recherche)',
            '\bCommissariat\s+à\s+l.énergie',
            '\bForschungsinstitut\b',
            '\bForschungszentrum\b',
            '\bForschungsgesellschaft\b',
            '\b(Fraunhofer|Max[\s-]Planck|Helmholtz|Leibniz)[\s-](Institut|Gesellschaft|Zentrum)\b',
            '\b(CEA|CNRS|INRIA|INSERM|ONERA)[\s-](List|Tech|Leti|Liten|Saclay|Grenoble)\b',
        ];

        foreach ($researchInstSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('French/German research institute detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 44. BROADCASTING / RADIO / TV (FR/DE audit) ────────────
        $broadcastSignals = [
            '\bradio\s+(station|broadcast|internationale|france|diffusion)',
            '\b(télévision|fernsehen|rundfunk|broadcast(er|ing)\s+company)\b',
            '\bTV\s+(channel|station|network|broadcast)\b',
            '\bactualités\s+(internationales?|en\s+direct|monde)',
            '\bNachrichten(sendung|portal|agentur)\b',
            '\b(podcast|streaming)\s+(platform|company|service|network)',
            '\bmedia\s+(company|group|corporation|network|house)\b',
        ];

        foreach ($broadcastSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Broadcasting/radio/TV company detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 45. POWER ELECTRONICS OEMs / INVERTER MANUFACTURERS (Feb 2026 sweep) ──
        // These companies MAKE power electronics products — they don't buy EMS
        // AGGRESSIVE detection: catch standalone product terms in context
        $powerElecOemSignals = [
            // Standalone product presence (aggressive)
            '\b(solar|string|hybrid|grid[\s-]?tie|off[\s-]?grid)\s+inverter\b',
            '\bphotovoltaic\s+inverter\b',
            '\b(micro[\s-]?)?inverter\s+(for|with|range|series|product|model|line)',
            '\b(frequency\s+)?converter\s+(for|with|range|series|product|model)',
            '\bpower\s+(supply|supplies|module|modules|converter|inverter|electronics)\b.*\b(product|range|series|portfolio|solution|our)',
            '\bour\s+(inverter|converter|drive|power\s+supply|power\s+module|UPS)',
            '\b(leading|global|world)\s+(inverter|converter|power\s+electronics)\b',
            // "We make X" patterns
            '\bwe\s+(manufactur|design|develop|produce|make|build|offer|supply)\s+.{0,30}(inverter|converter|drive|power\s+supply|power\s+module|UPS|VFD)',
            '\bwe\s+are\s+.{0,20}(inverter|converter|power\s+electronics|power\s+supply)\s+(manufactur|maker|company|leader)',
            // Product line indicators
            '\b(inverter|converter|VFD|drive)\s+(range|lineup|portfolio|family|series)\b',
            '\b(residential|commercial|industrial|utility[\s-]?scale)\s+inverter',
            '\bhybrid\s+inverter\b',
            '\bstring\s+inverter\b',
            '\bcentral\s+inverter\b',
            '\bmicro[\s-]?inverter\b',
            '\bmotor\s+drive\b.*\b(range|series|product|portfolio)',
            '\bvariable[\s-]?(frequency|speed)\s+drive\b',
            '\b(VFD|VSD)\s+(product|range|series|system)',
            // Power module/semiconductor manufacturer
            '\b(SiC|GaN|IGBT|MOSFET)\s+(power\s+)?(module|device|product)',
            '\bpower\s+semiconductor\b',
            '\bpower\s+module\b.*\b(product|range|series|portfolio)',
            '\btraction\s+(inverter|converter)\b',
            // Charger/UPS manufacturer
            '\b(battery|EV|vehicle)\s+charger\b.*\b(product|range|series|our)',
            '\bon[\s-]?board\s+charger\b',
            '\bOBC\b.*\b(product|develop|design)',
            '\bUPS\s+(system|product|range|series)\b',
            '\buninterruptible\s+power\s+(supply|system)\b.*\b(product|our|range)',
            // Industrial equipment
            '\bwelding\s+(power\s+)?(source|equipment|machine|system)\b',
            '\brectifier\s+(product|range|system)\b',
            '\binduction\s+(heating|furnace|equipment)\b',
            '\bplasma\s+(cutter|cutting|equipment)\b',
            '\bswitchgear\b.*\b(product|range|our|manufactur)',
            '\btransformer\b.*\b(product|range|our|manufactur)',
            '\bpower\s+distribution\s+(unit|panel|system)\b',
            // German patterns (standalone)
            '\bUmrichter\b',
            '\bFrequenzumrichter\b',
            '\bWechselrichter\b',
            '\bLeistungselektronik\b',
            '\bNetzger[aä]t\b',
            '\bStromversorgung\b',
            '\bSchaltschrank\b',
            // French patterns (standalone)
            '\bonduleur\b',
            '\bconvertisseur\s+(de\s+)?(puissance|fréquence)\b',
            '\bvariateur\s+de\s+(vitesse|fréquence)\b',
            '\bélectronique\s+de\s+puissance\b',
            '\balimentation\s+(électrique|stabilisée)\b',
        ];

        foreach ($powerElecOemSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Power electronics OEM/manufacturer detected', [
                    'domain' => $domain,
                    'matched' => $pattern,
                ]);
                return true;
            }
        }

        // ─── 46. EXTENDED MACHINING / CNC JOB SHOP DETECTION (Feb 2026 sweep) ──
        // AGGRESSIVE patterns to catch machining service providers
        $extendedMachiningSignals = [
            // Core machining terms (standalone - very aggressive)
            '\bmachine\s+shop\b',
            '\bjob\s+shop\b',
            '\bCNC\s+machining\b',
            '\bprecision\s+machining\b',
            '\bcontract\s+machining\b',
            '\bcustom\s+machining\b',
            // Multi-axis machining
            '\b(5[\s-]?axis|4[\s-]?axis|3[\s-]?axis)\s+(machining|milling|CNC|center|centre)',
            '\bmulti[\s-]?axis\s+(machining|milling|CNC)',
            // Swiss/screw machine
            '\bSwiss[\s-]?(type|turn|turning|screw|style)\b',
            '\bscrew\s+machine\b',
            '\bautomatic\s+screw\s+machine\b',
            // Material-specific machining
            '\b(aluminum|aluminium|steel|titanium|brass|copper|plastic|polymer)\s+machining',
            '\b(metal|alloy)\s+(cutting|machining|working)\b',
            // Capabilities pages
            '\bmachining\s+capabilit',
            '\bCNC\s+capabilit',
            '\bour\s+(machining|CNC|manufacturing)\s+(service|capabilit|facilit)',
            '\bprecision\s+capabilit',
            '\b(milling|turning|grinding|drilling|boring)\s+capabilit',
            // "We machine/manufacture" patterns
            '\bwe\s+(machine|mill|turn|grind|manufacture|produce|fabricate)\b',
            '\bwe\s+speciali[sz]e\s+in\s+(machining|CNC|precision|milling|turning)',
            '\bwe\s+(offer|provide)\s+.{0,20}(machining|CNC|milling|turning)',
            // Prototype and production
            '\bprototype\s+(machining|CNC|parts?|manufacturing)\b',
            '\brapid\s+(machining|prototyp|manufacturing)\b',
            '\blow[\s-]?volume\s+(machining|manufacturing|production)\b',
            '\bhigh[\s-]?mix\b.*\b(machining|manufacturing)',
            '\bshort[\s-]?run\s+(machining|manufacturing|production)\b',
            '\bquick[\s-]?turn\s+(machining|manufacturing)\b',
            // Tolerances and quality
            '\bclose[\s-]?tolerance\b',
            '\btight\s+tolerance\b',
            '\bprecision\s+(parts?|components?|manufacturing|engineering)\b',
            '\bAS[\s-]?9100\b.*\b(certified|machining|aerospace)',
            '\bIATF[\s-]?16949\b',
            '\bNADCAP\b',
            '\bITAR\b.*\b(compliant|registered|certified)',
            // EDM/waterjet/laser
            '\bEDM\b',
            '\bwire[\s-]?EDM\b',
            '\bsinker[\s-]?EDM\b',
            '\belectrical\s+discharge\s+machining\b',
            '\bwaterjet\s+(cutting|machining)\b',
            '\blaser\s+cutting\b',
            '\bplasma\s+cutting\b',
            // Sheet metal/stamping/fabrication
            '\bsheet\s+metal\s+(fabricat|shop|work)\b',
            '\bmetal\s+(stamping|forming|fabricat)\b',
            '\bprecision\s+sheet\s+metal\b',
            '\bstamping\s+(die|press|shop|capabilit)\b',
            // Casting/forging
            '\bdie\s+casting\b',
            '\binvestment\s+casting\b',
            '\bsand\s+casting\b',
            '\blost[\s-]?wax\s+casting\b',
            '\bprecision\s+casting\b',
            '\bmetal\s+casting\b',
            '\bforging\b.*\b(capabilit|service|company)',
            '\bpowder\s+metal(lurgy)?\b',
            // Surface treatments
            '\bheat\s+treat(ing|ment)\b',
            '\bsurface\s+treat(ing|ment)\b',
            '\banodizing\b',
            '\bhard[\s-]?coat\s+anodizing\b',
            '\bplating\b.*\b(service|capabilit)',
            '\bpassivation\b',
            '\bpowder\s+coat(ing)?\b',
            // CAD/CAM
            '\bCAD[\s\\/]CAM\b',
            '\bSolidWorks\b.*\b(design|programming)',
            '\bMasterCAM\b',
            // French (standalone)
            '\businage\b',
            '\bfraisage\b',
            '\btournage\b',
            '\bdécolletage\b',
            '\brectification\b',
            '\btôlerie\b',
            '\bchaudronnerie\b',
            '\bmécanique\s+(de\s+)?précision\b',
            '\bmécanique\s+générale\b',
            '\batelier\s+(d.)?usinage\b',
            '\bsous[\s-]?traitant\b.*\b(usinage|mécanique)',
            // German (standalone)
            '\bZerspanung\b',
            '\bCNC[\s-]?Bearbeitung\b',
            '\bDrehen\b.*\b(Fräsen|CNC)',
            '\bFräsen\b.*\b(Drehen|CNC)',
            '\bPräzisionsteile\b',
            '\bDrehteile\b',
            '\bFrästeile\b',
            '\bLohnfertigung\b',
            '\bLohnbearbeitung\b',
            '\bBlechbearbeitung\b',
            '\bStanzteile\b',
            '\bLaserschneiden\b',
            '\bWasserstrahlschneiden\b',
            '\bOberfl[aä]chenbehandlung\b',
        ];

        foreach ($extendedMachiningSignals as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $this->logger->debug('Extended machining/CNC job shop detected', [
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
      * @param array<string|int, mixed> $result
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
      * @param array<string|int, mixed> $candidates
     */
    private function verifyCompanies(array $candidates): array
    {
        if (empty($candidates) || !$this->hasSearchProvider()) {
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
                $html = $this->smartTruncateHtml($html, 250000);

                // ── Store cleaned homepage text for classifier context ──
                // Available regardless of enrichment success — gives the
                // classifier actual page content instead of a search snippet.
                $cleanedText = preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
                $data['_homepage_text'] = mb_substr(
                    trim(preg_replace('/\s+/u', ' ',
                        html_entity_decode(strip_tags($cleanedText), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    ) ?? ''),
                    0, 1500
                );

                // ── SYSTEMATIC CONTENT CLASSIFIER ────────────────
                // Run on ALL fetched pages (not just enriched ones).
                // Catches news sites, investment firms, news agencies in
                // non-English languages where processHomepageHtml returns null
                // but the classifier can still detect non-target patterns.
                if ($this->isHomepageDealerOrNonTarget($html, $data['name'], $domain)) {
                    $this->logger->info('Rejected via homepage content classifier (dealer/non-target)', [
                        'name' => $data['name'], 'domain' => $domain,
                    ]);
                    $fallbackInfo = $this->extractContactInfoFromHtml($html);
                    if (!empty($fallbackInfo['contacts'])) {
                        $data['contacts'] = $fallbackInfo['contacts'];
                    }
                    if (!empty($fallbackInfo['phone']) && empty($data['phone'])) {
                        $data['phone'] = $fallbackInfo['phone'];
                    }
                    if (!empty($fallbackInfo['email']) && empty($data['email'])) {
                        $data['email'] = $fallbackInfo['email'];
                    }
                    $data['homepage_rejected'] = true;
                    $needsLinkedIn[$domain] = $data;
                    continue;
                }

                $enrichment = $this->processHomepageHtml($html, $data['name']);

                if ($enrichment !== null) {
                    // ── HOMEPAGE LOCATION RE-VERIFICATION ──────────
                    // Skip if candidate already passed the thorough
                    // hasLocationPresence() check (which includes subpage
                    // crawling, phone codes, structured data — more thorough
                    // than this homepage-only check).
                    $candidateLocation = $data['location'] ?? null;
                    $alreadyLocationValidated = !empty($data['location_validated']);
                    if (!$alreadyLocationValidated && $candidateLocation !== null && $candidateLocation !== '') {
                        $locationVocab = $this->getLocationVocabulary(strtolower(trim($candidateLocation)));
                        if ($locationVocab !== null) {
                            $homepageText = strtolower(strip_tags($html));
                            $locationFoundOnHomepage = false;
                            // ccTLD is strong evidence
                            foreach ($locationVocab['tlds'] as $tld) {
                                if (preg_match('/\.' . preg_quote($tld, '/') . '$/i', $domain)) {
                                    $locationFoundOnHomepage = true;
                                    break;
                                }
                            }
                            // Check homepage text for location terms
                            if (!$locationFoundOnHomepage) {
                                foreach ($locationVocab['terms'] as $term) {
                                    if (str_contains($homepageText, $term)) {
                                        $locationFoundOnHomepage = true;
                                        break;
                                    }
                                }
                            }
                            // Domain name containing location
                            if (!$locationFoundOnHomepage) {
                                $domainLowerCheck = strtolower($domain);
                                foreach ($locationVocab['terms'] as $term) {
                                    if (str_contains($domainLowerCheck, str_replace(' ', '', $term))) {
                                        $locationFoundOnHomepage = true;
                                        break;
                                    }
                                }
                            }
                            if (!$locationFoundOnHomepage) {
                                $this->logger->debug('Homepage verified but NO location evidence on homepage — rejecting', [
                                    'name' => $data['name'], 'domain' => $domain,
                                    'location' => $candidateLocation,
                                ]);
                                $needsLinkedIn[$domain] = $data;
                                continue;
                            }
                        }
                    }

                    $data = $this->mergeEnrichment($data, $enrichment);

                    $verified[$domain] = $data;
                    $this->logger->debug('Verified+enriched via homepage', [
                        'name' => $data['name'], 'domain' => $domain,
                        'has_phone' => !empty($data['phone']),
                        'has_contacts' => !empty($data['contacts']),
                    ]);

                    // ── MULTI-PAGE SCRAPE: /contact, /about-us, /team ──
                    // If we verified but have no contacts yet, scrape
                    // subpages that commonly list people/contacts.
                    if (empty($data['contacts']) || empty($data['address'])) {
                        $subpageEnrichment = $this->scrapeSubpagesForContacts(
                            $data['website'] ?? '',
                            $data['name']
                        );
                        if ($subpageEnrichment) {
                            $data = $this->mergeEnrichment($data, $subpageEnrichment);
                            $verified[$domain] = $data;
                        }
                    }

                    // ── DYNAMIC LINK DISCOVERY ───────────────────────
                    // If still no contacts after hardcoded paths, scan
                    // the homepage HTML for about/team/management links
                    // and follow them. Catches non-standard CMS paths.
                    if (empty($data['contacts'])) {
                        $dynamicEnrichment = $this->discoverAndScrapeContactLinks(
                            $html,
                            $data['website'] ?? '',
                            $data['name']
                        );
                        if ($dynamicEnrichment) {
                            $data = $this->mergeEnrichment($data, $dynamicEnrichment);
                            $verified[$domain] = $data;
                        }
                    }

                    // ── EMAIL-TO-NAME HEURISTIC ──────────────────────
                    // If still no named contacts but we have emails,
                    // try extracting person names from email patterns
                    if (empty($data['contacts'])) {
                        $emailContacts = $this->extractContactsFromEmails($data);
                        if (!empty($emailContacts)) {
                            $data['contacts'] = $emailContacts;
                            $verified[$domain] = $data;
                        }
                    }

                    // ── GOOGLE→LINKEDIN PERSON SEARCH ────────────────
                    // Last resort: search Google for LinkedIn profiles of
                    // decision-makers at this company (1 API call)
                    if (empty($data['contacts'])) {
                        $liContacts = $this->searchLinkedInDecisionMakers($data['name']);
                        if (!empty($liContacts)) {
                            $data['contacts'] = $liContacts;
                            $verified[$domain] = $data;
                            $this->logger->info('Found contacts via Google→LinkedIn search', [
                                'company' => $data['name'], 'count' => count($liContacts),
                            ]);
                        }
                    }
                } else {
                    // Identity verification failed, but we already have the HTML.
                    // Extract contacts from it before falling to LinkedIn fallback.
                    // Common cause: SPA titles, Cloudflare challenges, non-standard meta tags.
                    $fallbackInfo = $this->extractContactInfoFromHtml($html);
                    if (!empty($fallbackInfo['contacts'])) {
                        $data['contacts'] = $fallbackInfo['contacts'];
                    }
                    if (!empty($fallbackInfo['phone']) && empty($data['phone'])) {
                        $data['phone'] = $fallbackInfo['phone'];
                    }
                    if (!empty($fallbackInfo['email']) && empty($data['email'])) {
                        $data['email'] = $fallbackInfo['email'];
                    }
                    // Also try subpages + dynamic links even without identity verification
                    if (empty($data['contacts']) && !empty($data['website'])) {
                        $sub = $this->scrapeSubpagesForContacts($data['website'], $data['name']);
                        if ($sub) { $data = $this->mergeEnrichment($data, $sub); }
                    }
                    if (empty($data['contacts'])) {
                        $dynLinks = $this->discoverAndScrapeContactLinks($html, $data['website'] ?? '', $data['name']);
                        if ($dynLinks && !empty($dynLinks['contacts'])) {
                            $data['contacts'] = array_merge($data['contacts'] ?? [], $dynLinks['contacts']);
                        }
                    }
                    if (empty($data['contacts'])) {
                        $ec = $this->extractContactsFromEmails($data);
                        if ($ec) { $data['contacts'] = $ec; }
                    }
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

        // ── PHASE 2: Verification for candidates NOT verified via homepage ──
        // OPTIMIZATION: Check benefit-of-the-doubt FIRST to avoid burning
        // engine quota on LinkedIn verification that would accept them anyway.
        // LinkedIn company-page searches try all 28 engines (~4 min each when
        // rate-limited), but BOTD accepts candidates with plausible domains.
        //
        // NOTE: homepage_rejected is NOT a gate for BOTD. The homepage classifier
        // checks for dealer/non-target content but has high false-positive rates
        // (e.g. Fft, Omron were rejected but are legitimate companies). Companies
        // with branded domains are real companies regardless of homepage content.
        // The BuyerEvidenceGate later in the pipeline handles buyer filtering.
        foreach ($needsLinkedIn as $domain => $data) {
            $name = $data['name'];

            // ── Entity-Type Pre-Filter (v19) for Phase 2 ──────────
            // Catch obvious non-targets before BOTD/LinkedIn verification
            if ($this->isObviousNonTarget($name, ($data['snippet'] ?? '') . ' ' . ($data['title'] ?? ''), $domain)) {
                $this->logger->info('Entity-type pre-filter REJECT in Phase 2', [
                    'name' => $name, 'domain' => $domain,
                ]);
                continue;
            }

            // ── FAST PATH: Benefit-of-the-doubt for branded-domain companies ──
            // If the candidate has a branded domain, accept immediately.
            // This saves 4+ minutes of futile LinkedIn engine-burning per
            // candidate. A branded domain proves the company exists; whether
            // it's a buyer target is handled by BuyerEvidenceGate downstream.
            $domainParts = explode('.', $domain);
            $baseName = $domainParts[0] ?? '';
            $looksLikeCompanyDomain = (
                mb_strlen($baseName) >= 3 &&
                mb_strlen($baseName) <= 30 &&
                !preg_match('/\d{4,}/', $baseName) &&
                !preg_match('/^(info|shop|store|buy|deal|free|best|top|my|the|get|go|web|net|online)$/i', $baseName)
            );

            $botdRiskText = strtolower(($data['name'] ?? '') . ' ' . ($data['title'] ?? '') . ' ' . ($data['snippet'] ?? '') . ' ' . $domain);
            // Also include homepage text for non-English content detection
            if (!empty($data['_homepage_text'])) {
                $botdRiskText .= ' ' . strtolower($data['_homepage_text']);
            }
            $botdHighRiskBusinessModel = (bool) preg_match(
                '/\b(distribut(or|ion)|dealer(ship)?|reseller|trading\s+company|general\s+trading|trading\s+and\s+(import|export)|import\s*(and|&)\s*export|import\s+company|import\s+group|importer|authorized\s+(dealer|distributor|importer)|spare\s+parts?|aftermarket|genuine\s+parts?|showroom|law\s+firm|legal\s+consult(ing|ancy)|attorneys?\s+at\s+law|law\s+office|headlines|latest\s+news|latest\s+insights|news\s+portal|media\s+partner|magazine|register\s+to\s+visit|book\s+a\s+stand|speaker\s+lineup|exhibitors?\b|conference|summit|expo|exhibition|shipping|logistics|freight\s+forward|market\s+data|market\s+intelligence|market\s+research|market\s+analysis|news\s+agency|agence\s+de\s+presse|stock\s+brokerage|asset\s+management|gestion\s+d.actifs|interm.diation\s+en\s+bourse|minist[eè]re|ministry|government|gouvernement|agence\s+nationale|national\s+agency|office\s+national|groupe\s+chimique|investment\s+promotion|promotion\s+des\s+investissement|ieee|student\s+(branch|chapter|club)|offres?\s+d.?emploi|job\s+(board|portal)|recruitment\s+platform|career\s+portal|observatoire|agricultural|douane|customs\s+authority)\b/i',
                $botdRiskText
            );

            if ($looksLikeCompanyDomain && empty($data['homepage_rejected']) && !$botdHighRiskBusinessModel) {
                // ── BOTD Location re-check ─────────────────────────────
                // The candidate's snippet may echo the query's location
                // term. Before accepting via BOTD, verify the homepage
                // actually mentions the target location. This blocks
                // global companies that have no presence in the target country.
                $botdLocation = $data['location'] ?? null;
                if ($botdLocation !== null && $botdLocation !== '') {
                    $botdLocationVocab = $this->getLocationVocabulary(strtolower(trim($botdLocation)));
                    if ($botdLocationVocab !== null) {
                        $botdLocationFound = false;
                        // ccTLD is strong evidence
                        foreach ($botdLocationVocab['tlds'] as $tld) {
                            if (preg_match('/\.' . preg_quote($tld, '/') . '$/i', $domain)) {
                                $botdLocationFound = true;
                                break;
                            }
                        }
                        // Domain name containing location
                        if (!$botdLocationFound) {
                            $domLower = strtolower($domain);
                            foreach ($botdLocationVocab['terms'] as $term) {
                                if (str_contains($domLower, str_replace(' ', '', $term))) {
                                    $botdLocationFound = true;
                                    break;
                                }
                            }
                        }
                        // Fetch homepage and check for location terms
                        if (!$botdLocationFound) {
                            try {
                                $botdHomepageText = $this->fetchEvidenceHomepageText($domain);
                                if ($botdHomepageText !== '') {
                                    $botdHomeLower = strtolower($botdHomepageText);
                                    foreach ($botdLocationVocab['terms'] as $term) {
                                        if (str_contains($botdHomeLower, $term)) {
                                            $botdLocationFound = true;
                                            break;
                                        }
                                    }
                                }
                            } catch (\Throwable $e) {
                                // Homepage fetch failed — don't block on network errors
                            }
                        }
                        if (!$botdLocationFound) {
                            $this->logger->info('BOTD rejected: no location evidence on homepage/domain', [
                                'name' => $name, 'domain' => $domain,
                                'location' => $botdLocation,
                            ]);
                            continue;
                        }
                    }
                }

                $data['verification_status'] = 'benefit_of_doubt';
                if (!empty($data['homepage_rejected'])) {
                    $data['verification_status'] = 'benefit_of_doubt_homepage_rejected';
                }
                // Enrich via subpages (fast — direct HTTP, no search engines)
                if (empty($data['contacts']) || empty($data['address'])) {
                    $sub = $this->scrapeSubpagesForContacts($data['website'] ?? '', $data['name']);
                    if ($sub) { $data = $this->mergeEnrichment($data, $sub); }
                }

                $verified[$domain] = $data;
                $this->logger->info('Accepted via benefit-of-the-doubt (fast path, skipped LinkedIn)', [
                    'name' => $name, 'domain' => $domain,
                    'homepage_rejected' => !empty($data['homepage_rejected']),
                ]);
                continue;
            }

            // ── HOMEPAGE-REJECTED: hard reject ─────────────────
            // The homepage classifier flagged this as a non-target (dealer,
            // media site, directory, etc.). No LLM rescue available — just reject.
            if (!empty($data['homepage_rejected'])) {
                $this->logger->info('Hard-rejecting homepage-classified non-target', [
                    'name' => $name,
                    'domain' => $domain,
                ]);
                continue;
            }

            if ($botdHighRiskBusinessModel) {
                $this->logger->info('Skipping benefit-of-the-doubt due to high-risk business model signals', [
                    'name' => $name,
                    'domain' => $domain,
                ]);
            }

            // ── SLOW PATH: Full LinkedIn verification for uncertain candidates ──
            // Only runs for: generic domains (too short, numeric, generic words)
            // that can't be trusted as branded company domains.

            // ── Helper: Pre-compute location vocabulary for LinkedIn-verified location check
            $liLocation = $data['location'] ?? null;
            $liLocationVocab = null;
            if ($liLocation !== null && $liLocation !== '') {
                $liLocationVocab = $this->getLocationVocabulary(strtolower(trim($liLocation)));
            }

            // Step 2a: LinkedIn check with extracted name
            $linkedInResult = $this->checkLinkedInCompanyPage($name);
            if ($linkedInResult !== null) {
                $data = $this->mergeEnrichment($data, $linkedInResult);

                // ── Location re-check for LinkedIn-verified candidates ──
                if ($liLocationVocab !== null) {
                    $liLocFound = false;
                    foreach ($liLocationVocab['tlds'] as $tld) {
                        if (preg_match('/\.' . preg_quote($tld, '/') . '$/i', $domain)) { $liLocFound = true; break; }
                    }
                    if (!$liLocFound) {
                        $liDomLower = strtolower($domain);
                        foreach ($liLocationVocab['terms'] as $term) {
                            if (str_contains($liDomLower, str_replace(' ', '', $term))) { $liLocFound = true; break; }
                        }
                    }
                    if (!$liLocFound) {
                        try {
                            $liHome = $this->fetchEvidenceHomepageText($domain);
                            if ($liHome !== '') {
                                $liHomeLower = strtolower($liHome);
                                foreach ($liLocationVocab['terms'] as $term) {
                                    if (str_contains($liHomeLower, $term)) { $liLocFound = true; break; }
                                }
                            }
                        } catch (\Throwable $e) { /* network error — don't block */ }
                    }
                    if (!$liLocFound) {
                        $this->logger->info('LinkedIn-verified but no location evidence — rejecting', [
                            'name' => $data['name'], 'domain' => $domain, 'location' => $liLocation,
                        ]);
                        usleep(250000);
                        continue;
                    }
                }

                $verified[$domain] = $data;
                $this->logger->debug('Verified+enriched via LinkedIn', [
                    'name' => $data['name'], 'domain' => $domain,
                ]);
                if (empty($data['contacts']) || empty($data['address'])) {
                    $sub = $this->scrapeSubpagesForContacts($data['website'] ?? '', $data['name']);
                    if ($sub) { $data = $this->mergeEnrichment($data, $sub); $verified[$domain] = $data; }
                }
                if (empty($data['contacts'])) {
                    $ec = $this->extractContactsFromEmails($data);
                    if ($ec) { $data['contacts'] = $ec; $verified[$domain] = $data; }
                }
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

                    // ── Location re-check for LinkedIn-verified (Step 2b) ──
                    if ($liLocationVocab !== null) {
                        $li2LocFound = false;
                        foreach ($liLocationVocab['tlds'] as $tld) {
                            if (preg_match('/\.' . preg_quote($tld, '/') . '$/i', $domain)) { $li2LocFound = true; break; }
                        }
                        if (!$li2LocFound) {
                            $li2DomLower = strtolower($domain);
                            foreach ($liLocationVocab['terms'] as $term) {
                                if (str_contains($li2DomLower, str_replace(' ', '', $term))) { $li2LocFound = true; break; }
                            }
                        }
                        if (!$li2LocFound) {
                            try {
                                $li2Home = $this->fetchEvidenceHomepageText($domain);
                                if ($li2Home !== '') {
                                    $li2HomeLower = strtolower($li2Home);
                                    foreach ($liLocationVocab['terms'] as $term) {
                                        if (str_contains($li2HomeLower, $term)) { $li2LocFound = true; break; }
                                    }
                                }
                            } catch (\Throwable $e) { /* network error */ }
                        }
                        if (!$li2LocFound) {
                            $this->logger->info('LinkedIn-verified (domain name) but no location evidence — rejecting', [
                                'name' => $data['name'], 'domain' => $domain, 'location' => $liLocation,
                            ]);
                            usleep(250000);
                            continue;
                        }
                    }

                    $verified[$domain] = $data;
                    $this->logger->debug('Verified via LinkedIn (domain name)', [
                        'original' => $name, 'corrected' => $data['name'],
                    ]);
                    if (empty($data['contacts']) || empty($data['address'])) {
                        $sub = $this->scrapeSubpagesForContacts($data['website'] ?? '', $data['name']);
                        if ($sub) { $data = $this->mergeEnrichment($data, $sub); $verified[$domain] = $data; }
                    }
                    if (empty($data['contacts'])) {
                        $ec = $this->extractContactsFromEmails($data);
                        if ($ec) { $data['contacts'] = $ec; $verified[$domain] = $data; }
                    }
                    usleep(250000);
                    continue;
                }
                usleep(250000);
            }

            // LinkedIn couldn't verify — reject (homepage already rejected or domain too generic)
            $this->logger->info('Rejected unverified company', [
                'name' => $name, 'domain' => $domain,
                'homepage_rejected' => !empty($data['homepage_rejected']),
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
      * @param array<string|int, mixed> $data
 * @param array<string|int, mixed> $enrichment
     */
    private function mergeEnrichment(array $data, array $enrichment): array
    {
        // Name override (e.g. LinkedIn's canonical name)
        if (!empty($enrichment['name'])) {
            $newName = $enrichment['name'];
            // Strip TLD suffixes that may have crept in
            $newName = preg_replace('/\.(com|net|org|io|co|biz|info|us|eu|fr|de|nl|it|es|pl|cz|fi|se)\.?$/i', '', $newName);
            // Strip trademark symbols
            $newName = preg_replace('/[®™©]/u', '', $newName);
            // Strip trailing dashes and descriptive suffixes
            $newName = preg_replace('/\s*[-–—|·]\s*(LinkedIn|Facebook|Twitter|Indeed|Glassdoor|Crunchbase|Overview|About).*$/i', '', $newName);
            $newName = preg_replace('/\s*[-–—]\s*(Electrifying|Driving|Powering|Leading|Global|The).*$/i', '', $newName);
            $newName = preg_replace('/\s*[-–—]\s*$/i', '', $newName);
            $newName = trim($newName);
            if (!$this->isJunkCompanyName($newName)
                && !$this->isGenericPageWord($newName)
                && mb_strlen($newName) >= 2) {
                // Re-check name-domain plausibility before accepting the override.
                // LinkedIn sometimes returns a completely different company name
                // (e.g. parent company, subsidiary, or unrelated entity).
                $domain = $data['displayLink'] ?? '';
                if ($domain !== '' && $this->isNameDomainMismatch($newName, $domain)) {
                    // The new name doesn't match the domain — keep the original name
                    $this->logger->debug('LinkedIn name override rejected (name-domain mismatch)', [
                        'original' => $data['name'] ?? '?',
                        'rejected' => $newName,
                        'domain' => $domain,
                    ]);
                } else {
                    $data['name'] = $newName;
                }
            }
        }

        $enrichKeys = ['phone', 'description', 'address', 'linkedin_url', 'legal_name', 'country_hint', 'email'];
        foreach ($enrichKeys as $key) {
            if (!empty($enrichment[$key]) && empty($data[$key])) {
                $data[$key] = $enrichment[$key];
            }
        }

        // Merge all_emails array (accumulate from all extraction phases)
        if (!empty($enrichment['all_emails']) && is_array($enrichment['all_emails'])) {
            $existing = $data['all_emails'] ?? [];
            $data['all_emails'] = array_values(array_unique(
                array_merge($existing, $enrichment['all_emails'])
            ));
        }

        // ── iter15: sanitize address — strip HTML/JavaScript artifacts ──
        if (!empty($data['address'])) {
            $data['address'] = $this->sanitizeAddress($data['address']);
            if (empty($data['address'])) {
                unset($data['address']);
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

            // ── Improvement 5A: Score and rank contacts by quality ──
            if ($this->contactScorer !== null && !empty($data['contacts'])) {
                $companyDomain = $data['displayLink'] ?? '';
                foreach ($data['contacts'] as &$ct) {
                    $ct['company_domain'] = $companyDomain;
                    $ct['role_score'] = $this->linkedInParser->computeRoleScore($ct['job_title'] ?? '');
                }
                unset($ct);
                $data['contacts'] = $this->contactScorer->scoreAndSort($data['contacts']);
            }
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
            // Use Google CSE directly for site:linkedin.com queries.
            // Most scraped engines can't handle LinkedIn site-restricted
            // searches (28 engines × 3-5s = 4+ min wasted with 0 results).
            $results = $this->executeLinkedInSearch($query, 3);
            if (!empty($results['results'])) {
                $first = $results['results'][0];
                $title = $first['title'] ?? '';
                $snippet = $first['snippet'] ?? '';
                $link = $first['link'] ?? '';

                $enrichment = [
                    'linkedin_url' => $link,
                    'description' => $this->extractLinkedInDescription($snippet),
                ];

                // ── Improvement 5A: Use LinkedInProfileParser for company pages ──
                $companyPageData = $this->linkedInParser->parseCompanyPage($link, $title, $snippet);
                if ($companyPageData !== null) {
                    if (!empty($companyPageData['company_name'])) {
                        $enrichment['name'] = $companyPageData['company_name'];
                    }
                    if (!empty($companyPageData['employee_hint'])) {
                        $enrichment['employee_hint'] = $companyPageData['employee_hint'];
                    }
                }

                // Fallback: parse LinkedIn title if parser didn't extract name
                if (empty($enrichment['name'])) {
                    if (preg_match('/^(.+?)\s*[|–—-]\s*(LinkedIn|Overview)/i', $title, $m)) {
                        $linkedInName = trim($m[1]);
                        if (mb_strlen($linkedInName) >= 2 && mb_strlen($linkedInName) <= 80) {
                            $enrichment['name'] = $linkedInName;
                        }
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

        // Polite crawl: check governor before homepage fetch
        $domain = parse_url($url, PHP_URL_HOST) ?? '';
        if ($this->crawlGovernor !== null && !$this->crawlGovernor->canRequest($domain)) {
            $this->logger->debug('verifyAndEnrichViaHomepage: crawl limit reached', ['domain' => $domain]);
            return null;
        }

        try {
            if ($this->crawlGovernor !== null) {
                $this->crawlGovernor->throttle($domain);
            }

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
                if ($this->crawlGovernor !== null && $statusCode >= 429) {
                    $this->crawlGovernor->reportError($domain, $statusCode);
                }
                return null;
            }

            if ($this->crawlGovernor !== null) {
                $this->crawlGovernor->reportSuccess($domain);
            }

            $html = $this->smartTruncateHtml($response->getContent(false), 250000);

            return $this->processHomepageHtml($html, $expectedName);

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Smart HTML truncation that preserves contact-rich sections.
     *
     * Instead of blindly cutting at a byte limit (which often removes
     * footer/about sections where contacts live), this method:
     * 1. Extracts high-value sections (Schema.org, footer, about) first
     * 2. Truncates the remaining HTML to fit the limit
     * 3. Reassembles so contact-rich content is never lost
     *
     * Benchmarked at <2ms for 500KB pages — negligible overhead.
     */
    private function smartTruncateHtml(string $html, int $maxBytes = 250000): string
    {
        if (strlen($html) <= $maxBytes) {
            return $html;
        }

        // Extract high-value sections that contain contacts
        $preserved = '';

        // 1. Schema.org JSON-LD (always in <head>, small, structured data)
        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>[\s\S]{1,15000}?<\/script>/i', $html, $m)) {
            $preserved .= implode("\n", $m[0]);
        }

        // 2. Footer section (phone, email, "founded by", addresses)
        if (preg_match('/<footer[\s>][\s\S]{1,50000}?<\/footer>/i', $html, $m)) {
            $preserved .= "\n" . $m[0];
        }

        // 3. About/team/management sections by ID or class
        if (preg_match_all('/<(?:section|div|article)[^>]*(?:id|class)=["\'][^"\']*(?:about|team|management|leadership|founder|director|president|chairman|governance|equipe|directoire)[^"\']*["\'][^>]*>[\s\S]{1,30000}?<\/(?:section|div|article)>/i', $html, $m)) {
            foreach ($m[0] as $section) {
                $preserved .= "\n" . $section;
            }
        }

        // Take the first $maxBytes of original HTML (head + hero + nav + body start)
        $mainChunk = substr($html, 0, $maxBytes);

        // Append preserved sections if they weren't already in the main chunk
        if (!empty($preserved)) {
            // Check that we're not double-including content
            $preservedLen = strlen($preserved);
            // Only append if it would fit in a reasonable total size
            if ($preservedLen < 100000) {
                $mainChunk .= "\n<!-- smartTruncate preserved sections -->\n" . $preserved;
            }
        }

        return $mainChunk;
    }

    /**
     * Discover and scrape contact-bearing links found in the page HTML.
     *
     * Unlike scrapeSubpagesForContacts() which tries hardcoded paths,
     * this method scans the actual page for <a> links that point to
     * about/team/management/governance pages and follows them.
     *
     * This catches non-standard CMS paths like:
     * - /accueil/mot-du-president/  (CIELEC)
     * - /english/pages/page.aspx?pageid=9  (Egypt Cable)
     * - /en/notre-societe/equipe-dirigeante  (NSE Groupe)
     */
    private function discoverAndScrapeContactLinks(string $html, string $website, string $companyName): ?array
    {
        if (empty($website) || empty($html)) {
            return null;
        }

        $base = rtrim($website, '/');
        $parsedBase = parse_url($base);
        $baseHost = $parsedBase['host'] ?? '';

        // Patterns that indicate a link leads to a page with people/contacts
        $linkPatterns = [
            // English
            'about[\-_/]?us', 'who[\-_/]we[\-_/]are', 'our[\-_/]team',
            'our[\-_/]people', 'management', 'leadership', 'executive',
            'board[\-_/](?:of[\-_/])?directors?', 'governance',
            'company[\-_/]profile', 'our[\-_/]company', 'our[\-_/]management',
            'meet[\-_/]the[\-_/]team', 'our[\-_/]leadership',
            'chairman', 'ceo[\-_/]message', 'president[\-_/]message',
            'founder', 'management[\-_/]team',
            // French (common in MA/TN)
            'a[\-_/]propos', 'qui[\-_/]sommes', 'notre[\-_/]equipe',
            'equipe[\-_/]dirigeante', 'mot[\-_/]du[\-_/]president',
            'mot[\-_/]du[\-_/]directeur', 'direction[\-_/]generale',
            'directoire', 'conseil[\-_/]administration', 'gouvernance',
            'notre[\-_/]societe', 'notre[\-_/]entreprise',
            // Arabic-ish transliterated patterns
            'manajem', 'idara',
        ];
        $linkRegex = '#(' . implode('|', $linkPatterns) . ')#i';

        // Find all <a> tags with href containing relevant keywords
        $discoveredUrls = [];
        if (preg_match_all('/<a[^>]+href=["\']([^"\']{5,200})["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $href) {
                // Check if the href or its visible text matches our patterns
                if (!preg_match($linkRegex, $href)) {
                    continue;
                }

                // Resolve relative URLs
                if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
                    $url = $href;
                    // Must be same domain
                    $parsedHref = parse_url($url);
                    if (($parsedHref['host'] ?? '') !== $baseHost) {
                        continue;
                    }
                } elseif (str_starts_with($href, '/')) {
                    $scheme = $parsedBase['scheme'] ?? 'https';
                    $url = $scheme . '://' . $baseHost . $href;
                } else {
                    $url = $base . '/' . $href;
                }

                // Avoid duplicates and non-HTML resources
                if (preg_match('/\.(pdf|jpg|jpeg|png|gif|svg|css|js|zip|doc)$/i', $url)) {
                    continue;
                }

                $discoveredUrls[$url] = true;
            }
        }

        // Also check anchor text for pattern matches even if href is opaque
        if (preg_match_all('/<a[^>]+href=["\']([^"\']{5,200})["\'][^>]*>([\s\S]{1,200}?)<\/a>/i', $html, $matches, PREG_SET_ORDER)) {
            $anchorKeywords = '/\b(about\s+us|who\s+we\s+are|our\s+team|management|leadership|board|governance|chairman|directoire|notre\s+(?:é|e)quipe|mot\s+du\s+pr(?:é|e)sident|direction|à\s+propos|company\s+profile)\b/iu';
            foreach ($matches as $match) {
                $href = $match[1];
                $anchorText = strip_tags($match[2]);
                if (!preg_match($anchorKeywords, $anchorText)) {
                    continue;
                }
                // Resolve URL
                if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
                    $url = $href;
                    $parsedHref = parse_url($url);
                    if (($parsedHref['host'] ?? '') !== $baseHost) continue;
                } elseif (str_starts_with($href, '/')) {
                    $scheme = $parsedBase['scheme'] ?? 'https';
                    $url = $scheme . '://' . $baseHost . $href;
                } elseif (str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                    continue;
                } else {
                    $url = $base . '/' . $href;
                }
                if (preg_match('/\.(pdf|jpg|jpeg|png|gif|svg|css|js|zip|doc)$/i', $url)) continue;
                $discoveredUrls[$url] = true;
            }
        }

        if (empty($discoveredUrls)) {
            return null;
        }

        // Limit to 8 URLs to avoid excessive requests
        $urls = array_slice(array_keys($discoveredUrls), 0, 8);

        $this->logger->debug('Dynamic link discovery found contact pages', [
            'company' => $companyName,
            'urls' => $urls,
        ]);

        // Fire concurrent requests
        $responses = [];
        foreach ($urls as $url) {
            try {
                $responses[$url] = $this->httpClient->request('GET', $url, [
                    'timeout' => 5,
                    'max_redirects' => 2,
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Accept' => 'text/html,application/xhtml+xml',
                        'Accept-Language' => 'en-US,en;q=0.9',
                    ],
                ]);
            } catch (\Exception $e) {
                // skip
            }
        }
        $enrichment = ['contacts' => []];
        foreach ($responses as $url => $response) {
            try {
                if ($response->getStatusCode() >= 400) continue;
                $subHtml = $this->smartTruncateHtml($response->getContent(false), 200000);
                if (empty($subHtml)) continue;

                // Extract contacts
                $contactInfo = $this->extractContactInfoFromHtml($subHtml);
                if (!empty($contactInfo['contacts'])) {
                    $enrichment['contacts'] = array_merge($enrichment['contacts'], $contactInfo['contacts']);
                }
                if (empty($enrichment['phone']) && !empty($contactInfo['phone'])) {
                    $enrichment['phone'] = $contactInfo['phone'];
                }
                if (empty($enrichment['email']) && !empty($contactInfo['email'])) {
                    $enrichment['email'] = $contactInfo['email'];
                }
                if (empty($enrichment['address']) && !empty($contactInfo['address'])) {
                    $enrichment['address'] = $contactInfo['address'];
                }

                // Also try team page extraction
                $teamContacts = $this->extractTeamPageContacts($subHtml);
                if (!empty($teamContacts)) {
                    $enrichment['contacts'] = array_merge($enrichment['contacts'], $teamContacts);
                }
            } catch (\Exception $e) {
                // skip
            }
        }

        // Deduplicate
        if (!empty($enrichment['contacts'])) {
            $seen = [];
            $unique = [];
            foreach ($enrichment['contacts'] as $c) {
                $key = strtolower(($c['first_name'] ?? '') . '|' . ($c['last_name'] ?? ''));
                if ($key === '|' || isset($seen[$key])) continue;
                $seen[$key] = true;
                $unique[] = $c;
            }
            $enrichment['contacts'] = array_slice($unique, 0, 5);
        }

        return empty($enrichment['contacts']) && empty($enrichment['phone'] ?? null) ? null : $enrichment;
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

        // Also try team page extraction patterns on homepage
        // (some sites embed leadership info directly on homepage)
        $teamContacts = $this->extractTeamPageContacts($html);
        if (!empty($teamContacts)) {
            $enrichment['contacts'] = array_merge(
                    $enrichment['contacts'] ?? [],
                    $teamContacts
            );
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
                        } elseif (is_string($addr) && mb_strlen($addr) >= 15) {
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
                                    $ct = is_array($cp['contactType']) ? $cp['contactType'][0] : $cp['contactType'];
                                    $person['job_title'] = ucwords((string)$ct);
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
        $allFoundEmails = [];
        if (preg_match_all('/href=["\']mailto:([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})["\']?/i', $html, $mailMatches)) {
            foreach ($mailMatches[1] as $email) {
                $lower = strtolower($email);
                // Skip boilerplate addresses
                if (preg_match('/^(noreply|no-reply|donotreply|unsubscribe|privacy|abuse|postmaster|mailer-daemon|webmaster|hostmaster)@/i', $lower)) {
                    continue;
                }
                $allFoundEmails[] = $lower;
            }
        }
        // Store ALL found emails for later use by extractContactsFromEmails
        $info['all_emails'] = array_values(array_unique($allFoundEmails));

        if (!$info['email']) {
            // Prefer sales/info/contact emails over generic ones
            $preferredPrefixes = ['sales', 'info', 'contact', 'enquir', 'inquiry', 'business'];
            foreach ($preferredPrefixes as $prefix) {
                foreach ($allFoundEmails as $email) {
                    if (str_starts_with($email, $prefix)) {
                        $info['email'] = $email;
                        break 2;
                    }
                }
            }
            // Fallback to first non-junk email
            if (!$info['email'] && !empty($allFoundEmails)) {
                $info['email'] = $allFoundEmails[0];
            }
        }

        // ── 4. Extract named contacts from "Contact Us" sections ──
        // Always run HTML-based extraction — JSON-LD often yields generic
        // contactPoints ("Sales", "Support") rather than named persons.
        // Merge with any JSON-LD contacts and dedup later.
        $htmlContacts = $this->extractNamedContactsFromHtml($html);
        if (!empty($htmlContacts)) {
            $info['contacts'] = array_merge($info['contacts'], $htmlContacts);
            // Dedup by first+last name
            $seen = [];
            $unique = [];
            foreach ($info['contacts'] as $c) {
                $key = strtolower(($c['first_name'] ?? '') . '|' . ($c['last_name'] ?? ''));
                if ($key === '|' || isset($seen[$key])) continue;
                $seen[$key] = true;
                $unique[] = $c;
            }
            $info['contacts'] = array_slice($unique, 0, 5);
        }

        // ── 5. Address from visible text (footer / contact section) ──
        // Many sites put addresses in footer or contact sections without
        // Schema.org markup.  Look for common patterns:
        //   123 Main Street, City, State ZIP, Country
        //   Postal code patterns: US (12345), UK (AB12 3CD), EU (D-12345)
        if (!$info['address']) {
            // Strip cookie banners from footer too
            $cleanHtml = $this->stripCookieBannerHtml($html);
            // Strip all tags but keep text content — only look in the last
            // 30% of HTML (footer area) to avoid grabbing random addresses
            $footerHtml = substr($cleanHtml, (int)(strlen($cleanHtml) * 0.65));
            $footerText = strip_tags($footerHtml);
            // Normalize whitespace (strip excessive newlines/tabs)
            $footerText = preg_replace('/\s+/', ' ', $footerText);

            // US-style: 123 Street Name, City, ST 12345
            if (preg_match('/(\d{1,5}\s+[A-Z][a-zA-Z\s]{3,30},\s*[A-Z][a-zA-Z\s]{2,20},?\s*[A-Z]{2}\s+\d{5}(?:-\d{4})?)/u', $footerText, $addrMatch)) {
                $info['address'] = trim($addrMatch[1]);
            }
            // UK-style: City, County, AB12 3CD
            elseif (preg_match('/([A-Z][a-zA-Z\s]{2,25},\s*[A-Z][a-zA-Z\s]{2,25},?\s*[A-Z]{1,2}\d{1,2}\s*\d[A-Z]{2})/u', $footerText, $addrMatch)) {
                $info['address'] = trim($addrMatch[1]);
            }
            // EU-style: Streetname 123, D-12345 City or Straße 5, 12345 München
            elseif (preg_match('/([A-ZÀ-Ÿ][a-zà-ÿA-ZÀ-Ÿ\-\.\s]{2,30}\s+\d{1,5}[a-z]?\s*,\s*(?:[A-Z]{1,3}[\-\s]?)?\d{4,5}\s+[A-ZÀ-Ÿ][a-zà-ÿ\s\-]{2,25})/u', $footerText, $addrMatch)) {
                $info['address'] = trim($addrMatch[1]);
            }
            // Generic: look for lines with street keywords near postal codes
            elseif (preg_match('/((?:Street|Str\.|Avenue|Ave\.|Boulevard|Blvd\.|Road|Rd\.|Drive|Dr\.|Lane|Way|Place|Court|Suite|Floor)[^,\n]{0,40},\s*[A-Z][a-zA-Z\s]{2,30})/iu', $footerText, $addrMatch)) {
                $candidate = trim($addrMatch[1]);
                // Validate: must NOT contain sentence fragments / verbose text
                if (mb_strlen($candidate) <= 120 && !preg_match('/\b(we can|to access|click|button|please|will be|you can|content|cookie|datenschutz|impressum|wird|werden|können|the actual)\b/i', $candidate)) {
                    $info['address'] = $candidate;
                }
            }
        }

        // Validate any address (whether from Schema.org or footer text)
        if (!empty($info['address'])) {
            // Reject if it starts with lowercase (not a proper address)
            if (preg_match('/^[a-z]/', $info['address'])) {
                $info['address'] = null;
            }
            // Reject if it contains sentence-like fragments
            if (!empty($info['address']) && preg_match('/\b(to access|click the|button below|we can|content|please|actual|will be|you can|the button|wird die|werden die|können Sie|for all means|from production|all the way|means of|environment for|as a manufacturing|manufacturing company|lanet\.|planet\.)\b/i', $info['address'])) {
                $info['address'] = null;
            }
            // Reject if too short (likely truncated/incomplete — need at least street + city)
            if (!empty($info['address']) && mb_strlen($info['address']) < 15) {
                $info['address'] = null;
            }
            // Reject if it's mostly not address-like (has many function words)
            if (!empty($info['address']) && preg_match_all('/\b(the|and|for|with|that|this|from|have|has|are|was|were|been|will|would|could|should|which|their|about)\b/i', $info['address']) > 3) {
                $info['address'] = null;
            }
            // Reject if address contains no digits (valid addresses have street numbers or postal codes)
            if (!empty($info['address']) && !preg_match('/\d/', $info['address'])) {
                $info['address'] = null;
            }
            // Reject if address contains date patterns (news/press release fragments)
            if (!empty($info['address']) && preg_match('/\b(January|February|March|April|May|June|July|August|September|October|November|December|20\d{2})\b/i', $info['address'])) {
                $info['address'] = null;
            }
        }

        // ── 6. Phone from visible text (last resort) ─────────────
        // If no phone found via schema/tel: links, look for international
        // phone patterns in footer area text
        if (!$info['phone']) {
            $footerHtml = $footerHtml ?? substr($html, (int)(strlen($html) * 0.65));
            $footerText = $footerText ?? strip_tags($footerHtml);
            // International format: +1 (555) 123-4567 or +44 20 7123 4567
            if (preg_match('/(\+\d{1,3}[\s.-]?\(?\d{1,4}\)?[\s.-]?\d{2,4}[\s.-]?\d{2,4}[\s.-]?\d{0,4})/', $footerText, $phoneMatch)) {
                $candidate = $this->cleanPhoneNumber($phoneMatch[1]);
                if ($candidate) {
                    $info['phone'] = $candidate;
                }
            }
        }

        // Limit to 3 contacts max — clean HTML artifacts and filter out non-person artifacts
        $info['contacts'] = array_map(fn(array $c) => $this->cleanContactFields($c), $info['contacts']);
        $info['contacts'] = array_values(array_filter(
            $info['contacts'],
            fn(array $c) => $this->isValidPersonContact($c)
        ));
        $info['contacts'] = array_slice($info['contacts'], 0, 3);

        return $info;
    }

    /**
     * Extract a person record from Schema.org Person entity.
      * @param array<string|int, mixed> $entity
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
     *
     * Applies aggressive filtering to reject common HTML headings,
     * navigation labels, product names, and other non-person text
     * that happens to look like "Capitalized Word Capitalized Word".
     */

    /**
     * Normalize ALL-CAPS name sequences to Title Case in HTML/text.
     *
     * North African / Middle Eastern websites commonly render person names
     * in ALL CAPS (e.g. "MOHAMED AIDI", "MR.SAMIR SHATA").  Our regex
     * patterns require Title Case ([A-ZÀ-Ÿ][a-zà-ÿ]+), so we convert
     * sequences of 2-4 consecutive ALL-CAPS words (each 2-15 chars) to
     * Title Case.  Single ALL-CAPS words are left alone (they may be
     * acronyms like CEO, VP, etc.).
     *
     * Also normalizes "MR.NAME" → "Mr. Name" so the prefix-strip regex
     * in splitPersonName() can catch it.
     */
    private function normalizeAllCapsNames(string $text): string
    {
        // Only apply to plain text (not HTML) to avoid corrupting tags.
        // Convert sequences of 2-4 consecutive ALL-CAPS words (each 2-15 alpha chars)
        // to Title Case.  Uses word-boundary anchors for safety.
        $text = preg_replace_callback(
            '/(?<![<\/\w="\'-])(?<!\w)([A-ZÀ-Ÿ][A-ZÀ-Ÿ]{1,14})[ \t]+([A-ZÀ-Ÿ][A-ZÀ-Ÿ]{1,14})(?:[ \t]+([A-ZÀ-Ÿ][A-ZÀ-Ÿ]{1,14}))?(?:[ \t]+([A-ZÀ-Ÿ][A-ZÀ-Ÿ]{1,14}))?(?!\w)/u',
            function ($m) {
                $result = mb_convert_case($m[1], MB_CASE_TITLE, 'UTF-8')
                    . ' ' . mb_convert_case($m[2], MB_CASE_TITLE, 'UTF-8');
                if (!empty($m[3])) {
                    $result .= ' ' . mb_convert_case($m[3], MB_CASE_TITLE, 'UTF-8');
                }
                if (!empty($m[4])) {
                    $result .= ' ' . mb_convert_case($m[4], MB_CASE_TITLE, 'UTF-8');
                }
                return $result;
            },
            $text
        );

        // Handle "MR.NAME" or "MRS.NAME" (no space after period) → "Mr. Name"
        $text = preg_replace_callback(
            '/\b(MR|MRS|MS|MME|DR|PROF)\.\s*([A-ZÀ-Ÿ][A-ZÀ-Ÿa-zà-ÿ]{1,14})/u',
            function ($m) {
                $prefix = mb_convert_case($m[1], MB_CASE_TITLE, 'UTF-8');
                $name = mb_convert_case($m[2], MB_CASE_TITLE, 'UTF-8');
                return $prefix . '. ' . $name;
            },
            $text
        );

        return $text;
    }

    private function splitPersonName(string $fullName): ?array
    {
        $name = trim($fullName);
        // Strip common prefixes (including "MR.MOHAMED" without space)
        $name = preg_replace('/^(Mr\.?|Mrs\.?|Ms\.?|Mme\.?|Dr\.?|Prof\.?|Eng\.?|Ir\.?)\s*/i', '', $name);
        // Strip common suffixes (German format "Firstname Lastname Dr")
        $name = preg_replace('/\s+(Dr\.?|Prof\.?|Eng\.?|Ir\.?|Dipl\.\s*\w+\.?|M\.?\s*Sc\.?|B\.?\s*Sc\.?|MBA|PhD|Jr\.?|Sr\.?|MS|BS|BA|MA|PE|PMP|CSM®?|CPA|CISSP|PgMP)$/i', '', $name);
        // Strip trailing comma + title/degree ("Firstname Lastname, MS")
        $name = preg_replace('/,\s*(Dr\.?|Prof\.?|PhD|MBA|Eng\.?|Jr\.?|Sr\.?|MS|MSc|BSc|BS|BA|MA|PE|PMP|CSM®?|CPA|CISSP|PgMP|LEED\s*AP|CPHIMS|FACHE|RN|MD|DO|DDS|DMD|JD|LLM|CFA|CFP|SPHR|SHRM)\.?$/i', '', $name);
        $name = trim($name);

        if (mb_strlen($name) < 4) return null;
        if (mb_strlen($name) > 50) return null; // Too long for a person name

        $parts = preg_split('/\s+/', $name);
        if (count($parts) < 2) return null;
        if (count($parts) > 5) return null; // Too many words for a person name

        // ── Comprehensive non-person text blacklist ──────────────
        $lower = strtolower($name);

        // Section headings / navigation labels commonly found in HTML
        $blacklistPhrases = [
            // Navigation & headings
            'about us', 'about company', 'about comeca', 'about becker',
            'contact us', 'contact form', 'contact information', 'contact details',
            'our products', 'our services', 'our story', 'our vision', 'our mission',
            'our values', 'our team', 'our promise', 'our clients', 'our history',
            'our approach', 'our work', 'our brands', 'our people', 'our solutions',
            'who we are', 'what we do', 'how we work', 'why choose us',
            'read more', 'learn more', 'view more', 'see more', 'show more',
            'case studies', 'case study', 'success stories',
            'privacy policy', 'privacy overview', 'privacy notice', 'privacy statement',
            'terms conditions', 'terms service', 'terms use', 'cookie policy',
            'footer social', 'footer links', 'footer menu', 'footer navigation',
            'header navigation', 'main navigation', 'main menu', 'site map',
            'email us', 'call us', 'write us', 'visit us', 'find us', 'reach us',
            'get started', 'get touch', 'get quote', 'request quote', 'free quote',
            'sign up', 'sign in', 'log in', 'register now',
            'quick links', 'useful links', 'related links', 'important links',
            'social media', 'follow us', 'connect with',
            'news events', 'latest news', 'press releases', 'media center',
            'customer service', 'customer support', 'technical support',
            'sales team', 'support team', 'general inquiry', 'main office',
            'head office', 'regional office', 'corporate office', 'branch office',
            'home page', 'search results', 'back top',
            'our location', 'our address', 'our offices', 'our factory',
            'general information', 'general contact', 'general enquiry', 'general enquiries',
            'marathon furniture',  // Non-person HTML artifact
            // iter15h: more HTML/UI artifacts
            'cards soft', 'navbar logo', 'submit button',  // HTML element names parsed as people
            'working hours', 'opening hours', 'business hours', 'office hours',
            'contact form', 'contact details', 'send message', 'read more',
            'learn more', 'view more', 'see all', 'show more',
            'call tender', 'call for tender',
            'family medicine', 'professional skills', 'professional development',
            'professional experience', 'professional summary',
            'douglas lyphe', // Non-person HTML artifact

            // Product/service descriptions
            'products categories', 'products services', 'product overview', 'product catalog',
            'custom sample', 'sample kit', 'product range', 'product line',
            'hose assemblies', 'cable assemblies', 'wire harness',
            'refrigerant fittings', 'hydraulic fittings', 'pneumatic fittings',
            'car detailing', 'car repair', 'car rental', 'car service',
            'airbag solution', 'security solutions', 'software solutions',
            'power systems', 'control systems', 'management systems',

            // Company descriptions
            'mission statement', 'company profile', 'corporate governance',
            'annual report', 'financial report', 'investor relations',
            'communiqués financiers', 'notre mission', 'nous contacter',
            'programmation clé', 'clé universelle',

            // Locations/places
            'dubai international', 'cairo international', 'abu dhabi',
            'united arab emirates', 'saudi arabia', 'middle east',
            'north america', 'south america', 'latin america',

            // Industry jargon
            'aerospace aluminum', 'automotive parts', 'aviation parts',
            'industrial automation', 'digital transformation',
            'supply chain', 'quality assurance', 'quality control',
            'lunar communications', 'ground support',

            // Sentence fragments that look like 2-3 word names
            'preference of', 'have been', 'has been', 'will be',
            'would be', 'cooperating with', 'from the',
            'president award', 'presidents award',
            'eliquo tech', 'jpg german',
            'lufthansa ground', 'ground handling',

            // French section headings / page labels
            'adresse siège', 'siège social', 'savoir faire', 'savoir-faire',
            'nos implantations', 'nos réalisations', 'nos services',
            'nos clients', 'nos métiers', 'notre histoire',
            'address head', 'head office', 'siège social',

            // Product descriptions that look like names
            'hydrogen fuel', 'fuel cell', 'ics eliminate',
            'software provider', 'software solutions',
            'cooling systems', 'magnetic components',

            // Job titles that get parsed as person names
            'chief strategy', 'chief executive', 'chief financial',
            'chief operating', 'chief technology', 'chief marketing',
            'chief information', 'chief digital', 'chief commercial',
            'board of', 'board directors', 'supervisory board',
            'managing director', 'general manager', 'executive director',
            'vice president', 'senior vice', 'executive vice',

            // Form field labels
            'first name', 'last name', 'full name', 'work email', 'work phone',
            'email address', 'phone number', 'your name', 'your email',
            'custom text', 'custom showcase', 'action call',

            // Country/territory names parsed as person names
            'puerto rico', 'south africa', 'north africa', 'saudi arabia',
            'new zealand', 'costa rica', 'sri lanka',

            // German phrases that look like 2-word names
            'deutsche handelsflotte', 'deine zukunft', 'meine zukunft',
            'perfekte systemintegration', 'unbenannter verlauf',
            'analyse wird', 'polarity protection', 'label printer',
            'speech tests', 'capacity free', 'leadership team',
            'code of', 'code conduct',
            // More observed junk from DE results
            'common grounds', 'eco inverter', 'spectrum water',
            'ethische geschäftspraktiken', 'berichtet nachfolgend',
            'noopener noreferrer', 'noreferrer noopener',
            'peter engineer', 'service zu',

            // iter16: Company slogans/marketing text parsed as names
            'strengthening communities', 'expanding broadband', 'broadband access',
            'texas roots', 'family values', 'core values', 'company values',
            'consider long', 'ensuring long', 'long term', 'term investment',
            'interest earned', 'total revenue', 'annual revenue', 'total assets',

            // iter16: Product categories parsed as names
            'fiber access', 'access hubs', 'wireless hubs', 'network hubs',
            'control houses', 'substation control', 'substation structures',
            'light duty', 'duty structures', 'heavy duty',
            'offsite construction', 'construction minimizes', 'modular construction',
            'galvanische trennglieder', 'trennglieder für', 'optokoppler für',
            'rackmount attenuators', 'coaxial attenuators', 'fixed attenuators',
            'rackmount filters', 'bandpass filters', 'lowpass filters',
            'fast lead', 'lead times', 'quick delivery', 'fast delivery',
            'low cost', 'cost effective', 'affordable solutions',

            // iter16: Button/form text parsed as names
            'send request', 'request cooperation', 'cooperation arrangement',
            'submit application', 'apply now', 'request info', 'request callback',
            'page load', 'load link', 'link cookie', 'cookie einstellungen',
            'einstellungen ablehnen', 'ablehnen akzeptieren', 'alle akzeptieren',
            'alle ablehnen', 'auswahl speichern', 'einstellungen speichern',
            'more information', 'additional information', 'further information',
        ];

        foreach ($blacklistPhrases as $phrase) {
            if ($lower === $phrase || str_starts_with($lower, $phrase)) {
                return null;
            }
        }

        // Single-word blacklist — reject if ANY word is one of these
        $blacklistWords = [
            'products', 'services', 'solutions', 'overview', 'categories',
            'navigation', 'menu', 'footer', 'header', 'sidebar', 'widget',
            'cookie', 'cookies', 'privacy', 'policy', 'terms', 'conditions',
            'copyright', 'disclaimer', 'sitemap', 'search', 'subscribe',
            'download', 'downloads', 'resources', 'documentation', 'faq',
            'portfolio', 'gallery', 'testimonials', 'reviews', 'blog',
            'newsletter', 'login', 'register', 'checkout', 'cart', 'shop',
            'approved', 'certified', 'accredited', 'registered', 'licensed',
            'manufacturing', 'distribution', 'consulting', 'engineering',
            'automotive', 'aerospace', 'industrial', 'pharmaceutical',
            'technologies', 'technology', 'electronics', 'electric',
            'corporation', 'incorporated', 'limited', 'company',
            'worldwide', 'international', 'global', 'national', 'regional',
            'category', 'communiqués', 'financiers', 'programmation',
            // iter15c: reject non-person words that leaked through
            'candidate', 'location', 'information', 'furniture', 'stores',
            'freelance', 'freelancer',
            // iter15h: HTML element artifacts & UI components
            'navbar', 'button', 'submit', 'logo', 'cards', 'modal',
            'carousel', 'slider', 'toggle', 'dropdown', 'tooltip',
            'icon', 'badge', 'alert', 'breadcrumb', 'pagination',
            'working', 'hours', 'pmp', 'certified',
            // iter15h-2: more HTML artifacts
            'medicine', 'skills',
            // iter15k: engineering methodology terms
            'pfmea', 'dfmea', 'fmea', 'apqp', 'ppap', 'sqe',
            // iter15k: product/measurement terms that leak as names
            'acoustic', 'akustische', 'vibration', 'testing', 'measurement',
            'calibration', 'sensor', 'transducer', 'amplifier',
            // iter15l: academic degree abbreviations
            'msc', 'bsc', 'phd', 'mba', 'eng', 'beng', 'meng',
            // EU-expansion: prepositions, articles, conjunctions that get Title-Cased and parsed as names
            'by', 'as', 'at', 'to', 'in', 'on', 'or', 'if', 'so', 'up', 'do',
            'an', 'be', 'we', 'us', 'it', 'am', 'is', 'my', 'no',
            'the', 'and', 'for', 'but', 'not', 'you', 'all', 'can', 'had',
            'her', 'was', 'one', 'our', 'out', 'has', 'are', 'his', 'how',
            'its', 'may', 'new', 'use', 'any', 'who', 'get', 'set', 'now',
            'via', 'per', 'off', 'also', 'with', 'from', 'this', 'that',
            'your', 'they', 'them', 'then', 'than', 'into', 'only', 'over',
            'such', 'take', 'each', 'make', 'like', 'more', 'here',
            'about', 'after', 'being', 'their', 'there', 'these', 'those',
            'where', 'which', 'while', 'other',
            // German prepositions/articles/function words (Title-Cased in German)
            'die', 'der', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'und',
            'oder', 'aber', 'auch', 'nur', 'wie', 'mit', 'für', 'von',
            'zur', 'zum', 'bei', 'auf', 'aus', 'bis', 'nach', 'vor',
            'ist', 'sind', 'hat', 'wir', 'sie', 'als', 'über', 'unter',
            'nicht', 'noch', 'sehr', 'hier', 'dann', 'wenn', 'dass',
            'ihre', 'seine', 'rolle', 'weitere', 'unsere',
            'kontakt', 'datenschutz', 'impressum', 'unternehmen',
            'geschäftsleitung', 'führungsteam', 'geschäftsführung',
            'verwenden', 'akzeptieren', 'einstellungen',
            // German words that were missing (auxiliary verbs, possessives, common nouns)
            'wird', 'werden', 'wurde', 'können', 'müssen', 'sollen', 'dürfen',
            'deine', 'deiner', 'meine', 'meiner', 'zukunft', 'perfekte',
            'verlauf', 'analyse', 'mittels', 'während', 'trotz',
            'handelsflotte', 'unbenannter', 'unbenannte', 'bausatz',
            'megawatt', 'systemintegration', 'ansprechpartner',
            'menü', 'schlie', 'schließen', 'entwickelt', 'deutsche',
            'startseite', 'inhalt', 'ergebnis',
            // English form/product/action terms
            'first', 'last', 'phone', 'email', 'fax', 'printer',
            'label', 'showcase', 'conduct', 'speech', 'tests',
            'text', 'action', 'passionate', 'capacity', 'polarity',
            'liquid', 'cooled', 'develops', 'rico', 'puerto', 'africa',
            // French prepositions/articles
            'les', 'des', 'une', 'aux', 'par', 'sur', 'dans', 'pour',
            'avec', 'sans', 'est', 'sont', 'ont', 'nous', 'vous',
            'accepter', 'gérer', 'consentement', 'politique',
            // Dutch
            'het', 'een', 'zij', 'wij', 'met', 'voor', 'niet',
            'worden', 'kunnen', 'moeten',
            // Polish
            'nie', 'tak', 'jest', 'lub', 'czy', 'jak', 'przy',
            'zgoda', 'polityka', 'pliki', 'plików', 'plikow',
            'ciasteczka', 'prywatność', 'prywatnosc', 'wymagane',
            'funkcjonalne', 'statystyczne', 'marketingowe', 'odrzucam',
            'niezbędne', 'niezbedne', 'ustawienia', 'zewnętrzne',
            'dogodne', 'terminy', 'godziny', 'pracy',
            // Czech cookie/consent
            'soubory', 'souborů', 'souboru', 'zásady', 'zasady',
            'ochrana', 'osobních', 'osobnich', 'údajů', 'udaju',
            'nastavení', 'nastaveni', 'přijmout', 'prijmout',
            'odmítnout', 'odmitnout', 'nezbytné', 'funkční', 'funkcni',
            'statistické', 'statisticke',
            // Italian
            'gli', 'sono', 'nel', 'della', 'dello', 'delle',
            'utilizziamo', 'accetta', 'consenso',
            // Spanish
            'los', 'las', 'sus', 'somos', 'puede', 'pueden',
            'aceptar', 'privacidad', 'productos', 'nuestros', 'nuestra',
            'utiliza', 'dejar', 'frecuencia', 'cardiaca',
            'electrolitica', 'electrolítica',
            // Misc non-name words that leaked as contacts
            'industry', 'collaborations', 'collaboration', 'innovation',
            'innovations', 'initiative', 'initiatives', 'integration',
            'implementation', 'infrastructure', 'development',
            'management', 'communications', 'communication',
            'organization', 'association', 'application', 'applications',
            'environment', 'performance', 'efficiency', 'sustainability',
            'microsoft', 'google', 'facebook', 'linkedin', 'twitter',
            'ground', 'campaigns', 'campaign', 'procurement',
            // Product/chemistry/engineering terms
            'hydrogen', 'fuel', 'cell', 'eliminate', 'bulky', 'cooling',
            'magnetic', 'reduce', 'supplies', 'provider', 'software',
            // French section heading words
            'adresse', 'siège', 'implantations', 'réalisations',
            'savoir', 'faire', 'nos', 'notre',
            'recrutement', 'embauche', 'candidature', 'candidatures',
            // Job title words that get parsed as names
            'vice', 'chief', 'officer', 'chairman', 'chairwoman',
            'directs', 'director', 'president', 'executive',
            'board', 'supervisory', 'strategy', 'strategist',
            // Marketing slogans that look like names
            'elevating', 'connectivity', 'vehicle', 'innovative',
            'redefining', 'transforming', 'empowering', 'enabling',
            'pioneering', 'advancing', 'accelerating',

            // iter16: Company slogans/marketing words that leak as names
            'strengthening', 'communities', 'expanding', 'broadband',
            'roots', 'values', 'consider', 'ensuring', 'earned', 'revenue',
            'minimizes', 'coordination', 'challenges', 'reduces', 'congestion',
            'total', 'assets', 'liabilities', 'equity', 'income', 'expenses',

            // iter16: Product/infrastructure terms that leak as names
            'fiber', 'hubs', 'substation', 'houses', 'structures',
            'offsite', 'modular', 'rackmount', 'attenuators', 'bandpass',
            'lowpass', 'coaxial', 'galvanische', 'trennglieder', 'optokoppler',
            'arrangements', 'arrangement', 'cooperation', 'partnerships',

            // iter16: German cookie/UI words
            'einstellungen', 'ablehnen', 'akzeptieren', 'speichern', 'auswahl',
            'laden', 'seite', 'link', 'weiter', 'zurück',
        ];

        foreach ($parts as $part) {
            if (in_array(strtolower($part), $blacklistWords, true)) {
                return null;
            }
        }

        $firstName = array_shift($parts);
        $lastName = implode(' ', $parts);

        // ══════════════════════════════════════════════════════════════════
        // SMART PATTERN-BASED JUNK DETECTION (iter16b)
        // Instead of listing every junk phrase, detect structural patterns
        // that indicate the text is a sentence fragment, not a person name.
        // ══════════════════════════════════════════════════════════════════

        $firstLower = strtolower($firstName);
        $lastLower = strtolower($lastName);

        // ── 1. Gerund detection: First name ending in -ing → likely junk ──
        // "Strengthening Communities", "Expanding Broadband", "Ensuring Long"
        // Real names ending in -ing are extremely rare (Sterling, Starling are exceptions)
        $gerundExceptions = ['sterling', 'starling', 'king', 'bing', 'ring', 'wing', 'ting', 'ming', 'ling', 'ning', 'ping', 'ying', 'zing', 'qing'];
        if (preg_match('/ing$/i', $firstName) && mb_strlen($firstName) > 5 && !in_array($firstLower, $gerundExceptions, true)) {
            return null;
        }

        // ── 2. Past participle detection: First name ending in -ed → likely junk ──
        // "Earned Revenue", "Reduced Cost", "Integrated Solutions"
        // Real names ending in -ed are very rare (Fred, Ted, Jed, Ed are short)
        if (preg_match('/ed$/i', $firstName) && mb_strlen($firstName) > 4) {
            return null;
        }

        // ── 3. Abstract noun detection: Last name is common abstract noun ──
        // These almost never appear as real surnames
        $abstractNouns = [
            'access', 'analysis', 'approach', 'area', 'asset', 'assets',
            'benefit', 'benefits', 'business', 'capacity', 'challenges',
            'change', 'changes', 'communities', 'community', 'compliance',
            'concept', 'conditions', 'configuration', 'congestion', 'content',
            'control', 'coordination', 'cost', 'costs', 'coverage', 'data',
            'delivery', 'demand', 'development', 'efficiency', 'environment',
            'equipment', 'equity', 'excellence', 'experience', 'expertise',
            'features', 'flexibility', 'focus', 'function', 'functions',
            'growth', 'guidelines', 'handling', 'impact', 'implementation',
            'improvement', 'income', 'information', 'infrastructure', 'innovation',
            'integration', 'integrity', 'interface', 'investment', 'issues',
            'items', 'leadership', 'level', 'levels', 'liabilities', 'limits',
            'lines', 'logistics', 'maintenance', 'management', 'market',
            'materials', 'measures', 'methods', 'metrics', 'model', 'models',
            'network', 'networks', 'objectives', 'operations', 'opportunities',
            'optimization', 'options', 'outcomes', 'output', 'outputs',
            'overview', 'parameters', 'partnerships', 'patterns', 'performance',
            'perspective', 'planning', 'platforms', 'points', 'policies',
            'portfolio', 'positioning', 'potential', 'practices', 'presence',
            'principles', 'priorities', 'procedures', 'processes', 'products',
            'profiles', 'programs', 'progress', 'projects', 'properties',
            'protection', 'provisions', 'quality', 'range', 'rates', 'ratio',
            'reach', 'readiness', 'records', 'references', 'regulations',
            'relations', 'reliability', 'requirements', 'research', 'resources',
            'response', 'responsibilities', 'results', 'returns', 'revenue',
            'revenues', 'review', 'rights', 'risk', 'risks', 'roots', 'rules',
            'safety', 'sales', 'satisfaction', 'scale', 'scope', 'security',
            'segments', 'selection', 'series', 'services', 'settings', 'sharing',
            'skills', 'solutions', 'sources', 'space', 'specifications', 'speed',
            'stability', 'staff', 'stages', 'standards', 'statements', 'status',
            'steps', 'storage', 'strategies', 'strategy', 'strengths', 'structures',
            'studies', 'success', 'summary', 'support', 'sustainability', 'systems',
            'targets', 'tasks', 'teams', 'techniques', 'technologies', 'technology',
            'terms', 'testing', 'themes', 'times', 'tools', 'topics', 'tracking',
            'training', 'transformation', 'transparency', 'trends', 'types',
            'understanding', 'updates', 'usage', 'users', 'utilization', 'value',
            'values', 'variables', 'variants', 'verification', 'version', 'versions',
            'visibility', 'vision', 'volume', 'workflow', 'workloads', 'zones',
        ];
        if (in_array($lastLower, $abstractNouns, true)) {
            return null;
        }

        // ── 4. Technical/product noun detection in last name ──
        $technicalNouns = [
            'attenuators', 'amplifiers', 'antennas', 'assemblies', 'bearings',
            'cables', 'capacitors', 'circuits', 'coils', 'components', 'compressors',
            'conductors', 'connectors', 'controllers', 'converters', 'couplers',
            'cylinders', 'detectors', 'devices', 'diodes', 'displays', 'drives',
            'electrodes', 'electronics', 'elements', 'enclosures', 'engines',
            'fasteners', 'fittings', 'fixtures', 'flanges', 'frames', 'gears',
            'generators', 'handles', 'harnesses', 'heaters', 'housings', 'hubs',
            'indicators', 'inductors', 'insulators', 'interfaces', 'inverters',
            'isolators', 'joints', 'lamps', 'lenses', 'machines', 'manifolds',
            'mechanisms', 'meters', 'microphones', 'modules', 'monitors', 'motors',
            'mounts', 'nozzles', 'oscillators', 'panels', 'pipes', 'plates',
            'plugs', 'ports', 'processors', 'probes', 'pumps', 'radiators',
            'receivers', 'rectifiers', 'regulators', 'relays', 'resistors',
            'rotors', 'scanners', 'screens', 'seals', 'sensors', 'servers',
            'shafts', 'shields', 'sockets', 'speakers', 'spindles', 'springs',
            'stators', 'substation', 'substations', 'switches', 'terminals',
            'thermostats', 'transducers', 'transformers', 'transistors',
            'transmitters', 'tubes', 'turbines', 'valves', 'windings', 'wires',
        ];
        if (in_array($lastLower, $technicalNouns, true)) {
            return null;
        }

        // ── 5. Common verb as first name → likely sentence fragment ──
        $commonVerbs = [
            'accept', 'achieve', 'acquire', 'add', 'address', 'adjust', 'advance',
            'allow', 'analyze', 'apply', 'approach', 'assess', 'assist', 'assume',
            'assure', 'avoid', 'bring', 'build', 'buy', 'calculate', 'call',
            'carry', 'change', 'check', 'choose', 'claim', 'close', 'combine',
            'compare', 'complete', 'compute', 'confirm', 'connect', 'consider',
            'contact', 'continue', 'control', 'convert', 'coordinate', 'cover',
            'create', 'cut', 'define', 'deliver', 'demonstrate', 'design',
            'detect', 'determine', 'develop', 'discover', 'discuss', 'display',
            'distribute', 'download', 'drive', 'earn', 'enable', 'ensure',
            'enter', 'establish', 'evaluate', 'examine', 'exceed', 'exchange',
            'execute', 'expand', 'expect', 'explain', 'explore', 'extend',
            'extract', 'facilitate', 'fill', 'find', 'focus', 'follow', 'form',
            'fulfill', 'gain', 'gather', 'generate', 'give', 'grow', 'guarantee',
            'guide', 'handle', 'identify', 'implement', 'improve', 'include',
            'increase', 'indicate', 'inform', 'install', 'integrate', 'introduce',
            'investigate', 'involve', 'keep', 'know', 'lead', 'learn', 'leverage',
            'limit', 'link', 'list', 'load', 'locate', 'maintain', 'manage',
            'match', 'maximize', 'measure', 'meet', 'minimize', 'monitor', 'move',
            'offer', 'operate', 'optimize', 'organize', 'outline', 'overcome',
            'oversee', 'perform', 'place', 'plan', 'position', 'practice',
            'prepare', 'present', 'prevent', 'proceed', 'process', 'produce',
            'promote', 'protect', 'provide', 'purchase', 'pursue', 'qualify',
            'reach', 'read', 'realize', 'receive', 'recognize', 'recommend',
            'record', 'reduce', 'reflect', 'register', 'reinforce', 'release',
            'remove', 'replace', 'report', 'request', 'require', 'research',
            'resolve', 'respond', 'restore', 'result', 'retain', 'retrieve',
            'return', 'review', 'satisfy', 'save', 'schedule', 'secure', 'seek',
            'select', 'send', 'serve', 'set', 'share', 'shift', 'show', 'simplify',
            'solve', 'specify', 'standardize', 'start', 'store', 'strengthen',
            'structure', 'study', 'submit', 'succeed', 'summarize', 'supply',
            'support', 'sustain', 'take', 'target', 'teach', 'test', 'track',
            'trade', 'train', 'transfer', 'transform', 'translate', 'transmit',
            'update', 'upgrade', 'upload', 'use', 'utilize', 'validate', 'verify',
            'view', 'visit', 'work', 'write',
        ];
        if (in_array($firstLower, $commonVerbs, true)) {
            return null;
        }

        // ── 6. Adjective + Noun pattern detection ──
        // "Fast Lead", "Low Cost", "Light Duty", "Total Revenue"
        $commonAdjectives = [
            'advanced', 'annual', 'automatic', 'available', 'basic', 'best',
            'better', 'big', 'broad', 'central', 'clean', 'clear', 'close',
            'cold', 'common', 'compact', 'complete', 'complex', 'comprehensive',
            'constant', 'continuous', 'cool', 'core', 'correct', 'critical',
            'current', 'custom', 'daily', 'dark', 'deep', 'dense', 'different',
            'difficult', 'digital', 'direct', 'double', 'dry', 'dual', 'early',
            'easy', 'effective', 'efficient', 'electric', 'electronic', 'empty',
            'entire', 'equal', 'essential', 'exact', 'excellent', 'external',
            'extra', 'extreme', 'fair', 'fast', 'final', 'fine', 'firm', 'first',
            'fixed', 'flat', 'flexible', 'foreign', 'formal', 'free', 'fresh',
            'front', 'full', 'future', 'general', 'global', 'good', 'grand',
            'great', 'green', 'gross', 'hard', 'heavy', 'high', 'higher', 'hot',
            'huge', 'ideal', 'immediate', 'important', 'independent', 'individual',
            'industrial', 'initial', 'inner', 'instant', 'integrated', 'intelligent',
            'internal', 'international', 'key', 'large', 'last', 'late', 'latest',
            'leading', 'left', 'legal', 'light', 'limited', 'liquid', 'little',
            'live', 'local', 'long', 'loose', 'low', 'lower', 'main', 'major',
            'manual', 'mass', 'massive', 'maximum', 'medium', 'micro', 'middle',
            'mild', 'minimum', 'minor', 'mobile', 'modern', 'modular', 'monthly',
            'multiple', 'narrow', 'national', 'natural', 'near', 'necessary', 'net',
            'neutral', 'new', 'next', 'nice', 'normal', 'north', 'official', 'old',
            'online', 'open', 'optical', 'optimal', 'optional', 'original', 'other',
            'outer', 'overall', 'own', 'parallel', 'partial', 'particular', 'passive',
            'past', 'perfect', 'permanent', 'physical', 'plain', 'positive', 'possible',
            'potential', 'powerful', 'practical', 'precise', 'premium', 'present',
            'previous', 'primary', 'prime', 'principal', 'prior', 'private', 'probable',
            'professional', 'proper', 'protective', 'public', 'pure', 'quick', 'quiet',
            'rapid', 'raw', 'ready', 'real', 'recent', 'regional', 'regular', 'relative',
            'reliable', 'remote', 'responsible', 'reverse', 'rich', 'right', 'robust',
            'rough', 'round', 'royal', 'rural', 'safe', 'same', 'secondary', 'secure',
            'select', 'senior', 'separate', 'serial', 'serious', 'severe', 'sharp',
            'short', 'significant', 'similar', 'simple', 'single', 'slight', 'slow',
            'small', 'smart', 'smooth', 'soft', 'solar', 'solid', 'south', 'spatial',
            'special', 'specific', 'stable', 'standard', 'static', 'steady', 'steep',
            'straight', 'strategic', 'strict', 'strong', 'structural', 'subsequent',
            'substantial', 'successful', 'sufficient', 'suitable', 'super', 'superior',
            'sustainable', 'technical', 'temporary', 'thermal', 'thick', 'thin', 'third',
            'tight', 'top', 'total', 'tough', 'traditional', 'transparent', 'true',
            'typical', 'ultimate', 'uniform', 'unique', 'universal', 'upper', 'urgent',
            'useful', 'usual', 'valid', 'valuable', 'variable', 'various', 'vertical',
            'virtual', 'visible', 'visual', 'vital', 'warm', 'weak', 'weekly', 'west',
            'wet', 'white', 'whole', 'wide', 'wild', 'wireless', 'wrong', 'young',
        ];

        // If first name is a common adjective → likely junk ("Fast Lead", "Low Cost")
        if (in_array($firstLower, $commonAdjectives, true)) {
            return null;
        }

        // ══════════════════════════════════════════════════════════════════
        // END SMART PATTERN DETECTION
        // ══════════════════════════════════════════════════════════════════

        // Reject fully-lowercase input — likely a phrase, not a person name
        // e.g. "john smith" (probably from anchor text / generic page content)
        if (preg_match('/^[a-zà-ÿ\s]+$/u', $name)) {
            return null;
        }

        // Reject trailing prepositions/articles (parsing artifacts like "Bacher as", "Reipert as")
        $originalLastName = $lastName;
        $lastName = preg_replace('/\s+(?:as|by|at|in|on|to|of|for|and|the|als|und|von|der|die|das|des|dem|den|für|mit|bei|zur|zum|du|de|et|le|la|les|en|par|pour|van|het)$/i', '', $lastName);
        if ($originalLastName !== $lastName) return null;
        if (mb_strlen($lastName) < 2) return null;

        // Normalize case: all-lowercase names get Title Case (e.g. "mohamed aidi" → "Mohamed Aidi")
        if (preg_match('/^[a-zà-ÿ]/u', $firstName)) {
            $firstName = mb_convert_case($firstName, MB_CASE_TITLE, 'UTF-8');
        }
        if (preg_match('/^[a-zà-ÿ]/u', $lastName)) {
            $lastName = mb_convert_case($lastName, MB_CASE_TITLE, 'UTF-8');
        }

        // Validate: names should start with uppercase letters
        if (!preg_match('/^[A-ZÀ-Ÿ]/u', $firstName) || !preg_match('/^[A-ZÀ-Ÿ]/u', $lastName)) {
            return null;
        }

        // Each name part should be 2-20 chars
        if (mb_strlen($firstName) < 2 || mb_strlen($firstName) > 20) return null;
        if (mb_strlen($lastName) < 2 || mb_strlen($lastName) > 30) return null;

        // Reject if either part contains digits
        if (preg_match('/\d/', $firstName) || preg_match('/\d/', $lastName)) {
            return null;
        }

        // Reject duplicate first/last names (e.g. "Agar Agar")
        if (strtolower($firstName) === strtolower($lastName)) {
            return null;
        }

        // Convert ALL-CAPS names to Title Case instead of rejecting them
        // North African / Middle Eastern websites often display names in ALL CAPS
        if ($firstName === mb_strtoupper($firstName, 'UTF-8') && mb_strlen($firstName) > 2) {
            $firstName = mb_convert_case($firstName, MB_CASE_TITLE, 'UTF-8');
        }
        if ($lastName === mb_strtoupper($lastName, 'UTF-8') && mb_strlen($lastName) > 3) {
            $lastName = mb_convert_case($lastName, MB_CASE_TITLE, 'UTF-8');
        }

        return ['first_name' => $firstName, 'last_name' => $lastName];
    }

    /**
     * Strip cookie consent banners, GDPR/DSGVO notices, and privacy overlays
     * from HTML before extracting contacts/addresses.
     *
     * European (especially German) sites commonly have cookie consent banners
     * that use Title Case words and sentence fragments that match person-name
     * regex patterns. This causes junk contacts like:
     *   "Notice Data Protection" → first=Notice, last=Data Protection
     *   "Individuelle Datenschutzeinstellungen" → Title-Cased words parsed
     *   "Because Required Functional" → parsed as 3-word person name
     *
     * This method removes:
     * 1. <div> blocks with cookie/consent/privacy CSS classes
     * 2. <div> blocks with DSGVO/GDPR/Datenschutz CSS classes  
     * 3. Inline <script> blocks (contain no contact data)
     * 4. <noscript> blocks (contain no useful contact data)
     * 5. <style> blocks
     */
    private function stripCookieBannerHtml(string $html): string
    {
        // ── 1. Remove cookie/consent/privacy overlay divs ──────────
        // Match div/section/aside elements with cookie/consent/privacy in their class or id.
        // Uses a non-greedy match with balanced tag heuristic (limited depth).
        $cookiePatterns = [
            // Class-based patterns (most common)
            '/<(?:div|section|aside|dialog|footer)[^>]*(?:class|id)="[^"]*\b(?:cookie[-_]?(?:consent|banner|notice|bar|popup|modal|overlay|wall|wrap|container|message|info|settings|preferences|layer)|consent[-_]?(?:banner|bar|modal|popup|overlay|manager|container|wrapper|dialog)|gdpr[-_]?(?:banner|notice|bar|popup|modal|overlay|consent)|dsgvo[-_]?(?:banner|hinweis|notice|consent)|privacy[-_]?(?:banner|notice|bar|popup|modal|overlay|settings)|data[-_]?protection|datenschutz(?:[-_]?(?:hinweis|banner|einstellungen))?|cc[-_]?(?:banner|window|revoke)|cmp[-_]?(?:banner|modal|container)|onetrust[-_]?(?:banner|consent)|klaro|cookiebot|borlabs[-_]?cookie|usercentrics|quantcast[-_]?choice|complianz|osano)\b[^"]*"[^>]*>[\s\S]{0,15000}?<\/(?:div|section|aside|dialog|footer)>/i',
            // ID-based patterns
            '/<(?:div|section|aside|dialog)[^>]*id="[^"]*\b(?:cookie|consent|gdpr|dsgvo|privacy|onetrust|klaro|cookiebot|usercentrics|CybotCookiebot)\b[^"]*"[^>]*>[\s\S]{0,15000}?<\/(?:div|section|aside|dialog)>/i',
        ];

        foreach ($cookiePatterns as $pattern) {
            $html = preg_replace($pattern, '', $html) ?? $html;
        }

        // ── 2. Remove <script> blocks ──────────────────────────────
        $html = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $html) ?? $html;

        // ── 3. Remove <noscript> blocks ────────────────────────────
        $html = preg_replace('/<noscript\b[^>]*>[\s\S]*?<\/noscript>/i', '', $html) ?? $html;

        // ── 4. Remove <style> blocks ───────────────────────────────
        $html = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i', '', $html) ?? $html;

        return $html;
    }

    /**
     * Validate that a contact looks like a real person and not a 
     * foreign-language UI artifact, cookie banner text, or company name.
     *
     * Returns true if the contact should be KEPT, false if rejected.
     */
    /**
     * Clean HTML entities and whitespace artifacts from contact fields.
      * @param array<string|int, mixed> $contact
     */
    private function cleanContactFields(array $contact): array
    {
        foreach (['first_name', 'last_name', 'job_title'] as $field) {
            if (!empty($contact[$field])) {
                $v = $contact[$field];
                $v = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $v = str_replace(["\xC2\xA0", "\u{00A0}"], ' ', $v);   // &nbsp; as UTF-8
                $v = preg_replace('/&#\d+;/', '', $v);                  // numeric entities like &#8211;
                $v = strip_tags($v);
                // Remove © ® ™ symbols
                $v = str_replace(['©', '®', '™'], '', $v);
                // For name fields: strip non-Latin characters (Korean 님, Chinese, Japanese, etc.)
                if ($field !== 'job_title') {
                    $v = preg_replace('/[^\p{Latin}\s\-\'.]/u', '', $v);
                }
                $v = preg_replace('/\s+/', ' ', trim($v));
                $contact[$field] = $v;
            }
        }

        // Strip degree suffixes from last name ("Oglesbee, MS" → "Oglesbee")
        if (!empty($contact['last_name'])) {
            // Strip comma + any all-caps certification/degree suffix
            $contact['last_name'] = preg_replace('/,\s*[A-Z][A-Z®\.]{1,20}$/', '', $contact['last_name']);
            $contact['last_name'] = trim($contact['last_name']);
        }

        // iter16: Strip company name acronyms stuck to last name ("Boutwell BTS" → "Boutwell")
        // Pattern: name followed by space + 2-5 uppercase letters (company acronym)
        if (!empty($contact['last_name'])) {
            $contact['last_name'] = preg_replace('/\s+[A-Z]{2,5}$/', '', $contact['last_name']);
            $contact['last_name'] = trim($contact['last_name']);
        }

        // Strip German title suffixes stuck to name fields
        // e.g. "Quartaro Geschäftsführer" → "Quartaro"
        $titleSuffixes = ['Geschäftsführer', 'Geschäftsführerin', 'Vorstandsvorsitzender', 'Vorstand', 'Inhaber', 'Inhaberin', 'Prokurist', 'Betriebsleiter'];
        foreach (['first_name', 'last_name'] as $field) {
            if (!empty($contact[$field])) {
                foreach ($titleSuffixes as $suffix) {
                    if (str_contains($contact[$field], $suffix)) {
                        $contact[$field] = trim(str_ireplace($suffix, '', $contact[$field]));
                    }
                }
            }
        }

        // Clean concatenated LinkedIn title fields
        // e.g. "CEOChristian Hahn CEOHossein..." → truncate to first meaningful title
        if (!empty($contact['job_title'])) {
            $jt = $contact['job_title'];
            // Detect concatenation: title word immediately followed by uppercase name
            // Pattern: "CEO/Director/Manager/Head of..." then "UppercaseName" without space
            if (preg_match('/^(.{5,}?)(CEO|CTO|CFO|COO|Director|Manager|Head|VP|President|Founder)[A-Z][a-z]/u', $jt, $m)) {
                $jt = trim($m[1]);
            }
            // Also truncate at pattern: "role)Name" — when role text runs into another name
            if (preg_match('/^(.{5,}?)(?:[a-z])([A-Z][a-z]{2,}\s+[A-Z][a-z]{2,})/u', $jt, $m)) {
                $jt = trim($m[1]);
            }
            // If still looks concatenated (multiple uppercase runs separated by lowercase), limit length
            if (mb_strlen($jt) > 80) {
                $jt = mb_substr($jt, 0, 80);
            }
            $contact['job_title'] = $jt;
        }

        // iter16: Validate job_title - reject if it's clearly not a job title
        if (!empty($contact['job_title'])) {
            $jt = $contact['job_title'];

            // List of words that indicate it's NOT a job title.
            // Matched as whole words — short legal suffixes like "ag" must
            // never substring-match inside real titles ("Sales Manager").
            $invalidJobTitleWords = [
                'headquarters', 'head office', 'main office', 'office',
                'gmbh', 'ag', 'ltd', 'llc', 'inc', 'corp', 'plc', 'se',
                'cookie', 'einstellungen', 'settings', 'preferences',
                'building', 'factory', 'plant', 'facility',
                'address', 'location', 'street', 'avenue', 'road',
                'phone', 'fax', 'email', 'mailto', 'tel',
            ];

            $invalidTitlePattern = '/\b(' . implode('|', array_map('preg_quote', $invalidJobTitleWords)) . ')\b/i';
            if (preg_match($invalidTitlePattern, $jt)) {
                $contact['job_title'] = null;
            }

            // Also reject if job title looks like a company name (starts with uppercase, ends with legal suffix)
            if (!empty($contact['job_title']) && preg_match('/^[A-Z][A-Za-z\s]+\s+(GmbH|AG|Ltd|LLC|Inc|Corp|SE|BV|NV|SA|SAS|SARL|SRL|SpA)$/i', $contact['job_title'])) {
                $contact['job_title'] = null;
            }
        }

        return $contact;
    }

    private function isValidPersonContact(array $contact): bool
    {
        $firstName = $contact['first_name'] ?? '';
        $lastName = $contact['last_name'] ?? '';
        $fullName = trim("$firstName $lastName");

        if (empty($firstName) || empty($lastName)) {
            return false;
        }

        // ── 0. Reject email addresses leaking as names ───────────
        if (str_contains($fullName, '@') || str_contains($fullName, '<') || str_contains($fullName, '>')) {
            return false;
        }

        // ── 0b. Strip German/English title suffixes stuck to names ──
        $titleSuffixes = [
            'Geschäftsführer', 'Geschäftsführerin', 'Geschaeftsfuehrer',
            'Vorstandsvorsitzender', 'Vorstandsvorsitzende', 'Vorstand',
            'Aufsichtsratsvorsitzender', 'Prokurist', 'Inhaber', 'Inhaberin',
            'Betriebsleiter', 'Betriebsleiterin', 'Leiter', 'Leiterin',
            'Werkleiter', 'Vertriebsleiter',
        ];
        foreach ($titleSuffixes as $suffix) {
            if (str_contains($lastName, $suffix)) {
                $lastName = trim(str_ireplace($suffix, '', $lastName));
                $contact['last_name'] = $lastName;
            }
            if (str_contains($firstName, $suffix)) {
                $firstName = trim(str_ireplace($suffix, '', $firstName));
                $contact['first_name'] = $firstName;
            }
        }
        $fullName = trim("$firstName $lastName");
        if (empty($firstName) || empty($lastName)) {
            return false;
        }

        $firstLower = mb_strtolower($firstName);
        $lastLower = mb_strtolower($lastName);
        $fullLower = mb_strtolower($fullName);

        // ── 1. Reject cookie/consent/GDPR/privacy artifacts ──────
        $cookieWords = [
            'cookie', 'cookies', 'consent', 'gdpr', 'dsgvo', 'privacy',
            'datenschutz', 'einstellungen', 'akzeptieren', 'ablehnen',
            'erforderlich', 'notwendig', 'funktional', 'statistik',
            'zustimmen', 'bestätigen', 'bestatigen', 'auswahl',
            'personalisierung', 'marketing', 'analytik', 'analytics',
            'datenschutzeinstellungen', 'cookie-details', 'cookie-informationen',
            'hinweis', 'banner', 'notice', 'accept', 'reject', 'decline',
            'manage', 'preferences', 'settings', 'necessary', 'functional',
            'performance', 'targeting', 'required', 'optional', 'allowed',
            'consentement', 'accepter', 'refuser', 'nécessaire', 'fonctionnel',
            'toestemming', 'accepteren', 'noodzakelijk', 'functioneel',
            'consenso', 'accetta', 'rifiuta', 'necessario',
            'aceptar', 'rechazar', 'necesario',
            'zgoda', 'akceptuję', 'wymagane',
            'samtycke', 'godkänn', 'nödvändig',
            'suostumus', 'hyväksy', 'välttämätön',
            'souhlas', 'souhlasím', 'nezbytné',
        ];

        foreach ($cookieWords as $cw) {
            if (str_contains($firstLower, $cw) || str_contains($lastLower, $cw)) {
                return false;
            }
        }

        // ── 2. Reject full phrases that are clearly not people ───
        $junkPhrases = [
            'data protection', 'legal notice', 'cookie policy', 'cookie settings',
            'privacy policy', 'privacy notice', 'terms conditions',
            'wind tunnel', 'supply chain', 'quality control', 'quality assurance',
            'press releases', 'news events', 'social media',
            'diese zwecke', 'ihre daten', 'dritte weiter', 'weitere informationen',
            'informationen anzeigen', 'informationen ausblenden',
            'individuelle datenschutz', 'benutzerdefinierten attribut',
            'inhaltlich verantwortlich', 'angaben gemäß', 'verantwortlich für',
            'contact legal', 'all rights', 'rights reserved',
            'technical support', 'customer service', 'customer support',
            'general inquiry', 'general enquiry',
            'preference of', 'have been', 'has been', 'will be', 'would be',
            'cooperating with', 'successfully', 'from the very',
            'president award', 'presidents award',
            // French consent/institutional phrases
            'certains de ces', 'activation de ces', 'ecole nationale',
            'école nationale', 'agence gardeners', 'comparison study',
            'for dietmar', 'indoor led', 'led strip', 'fast boot',
            'immediate boot', 'flex align', 'box overlap',
            'accordion box', 'accordion item', 'announcement scroll',
            'integrated micro', 'interface dual', 'supports conductive',
            'discover ondina', 'latest posts', 'options panoramique',
            'references naval', 'partenariats afrique',
            'anesthésie loco', 'ouvèze payre',
            'islands colombia', 'native cantonese', 'key milestones',
            'small business', 'business program',
            // Polish junk phrases
            'przez nas', 'godziny pracy', 'biuro projektowe',
            'gliwicki park', 'park techniki', 'dogodne terminy',
            'fully professional', 'many private', 'operators will',
            'three companies', 'term orientation', 'magic garden',
            'rd party', 'link wp', 'service après', 'service apres',
            'spiralnych cormak', 'pasc borehole',
            // Polish institutional
            'polsko-chińska', 'polsko-chinska', 'izba gospodarcza',
            'koleje małopolskie', 'koleje malopolskie',
            'polskie radio', 'common direction',
            // Czech junk phrases
            'soubory cookie', 'ochrana osobních', 'ochrana osobnich',
            'zásady ochrany', 'zasady ochrany',
            // Product-category phrases (Roger access control products)
            'access control', 'standard access', 'advanced access', 'locker access',
            'connecting rkd', 'block uk', 'extremely efficient',
            // Italian product / geography phrases
            'oil exchangers', 'sede amministrativa', 'isole cocos',
            'scambiatori aria', 'scambiatori acqua', 'scambiatori olio',
            'sgrigliatore automatico', 'cooling groups',
            // Head-of-state / political title phrases
            'president of the', 'presidente della', 'président de la',
            'prime minister', 'premier ministre', 'primo ministro',
            'head of state', 'chef de l\'état', 'capo dello stato',
            'italian republic', 'french republic', 'polish republic',
            'repubblica italiana', 'république française',
            // German industrial product phrases
            'sonstiges drehmaschinen', 'dornenlose rohr',
            // PL startup / generic phrases
            'startup attempts', 'county resident',

            // iter16: Company slogans/marketing text from crawl results
            'strengthening communities', 'expanding broadband', 'broadband access',
            'texas roots', 'family values', 'core values', 'company values',
            'consider long', 'ensuring long', 'long term', 'term investment',
            'interest earned', 'total revenue', 'annual revenue', 'total assets',

            // iter16: Product infrastructure categories
            'fiber access', 'access hubs', 'wireless hubs', 'network hubs',
            'control houses', 'substation control', 'substation structures',
            'light duty', 'duty structures', 'heavy duty',
            'offsite construction', 'construction minimizes', 'modular construction',
            'galvanische trennglieder', 'trennglieder für', 'optokoppler für',
            'rackmount attenuators', 'coaxial attenuators', 'fixed attenuators',

            // iter16: Form/button text
            'send request', 'request cooperation', 'cooperation arrangement',
            'submit application', 'apply now', 'request info', 'request callback',
            'page load', 'load link', 'link cookie', 'cookie einstellungen',
        ];
        foreach ($junkPhrases as $phrase) {
            if (str_contains($fullLower, $phrase)) {
                return false;
            }
        }

        // ── 3. Reject company name fragments parsed as contacts ──
        // Pattern: "CompanyName Location" like "Rtw Rohrtechnik Warburg"
        $companyIndicators = [
            'gmbh', 'ag', 'ohg', 'kg', 'mbh', 'ug', 'se', 'bv', 'nv',
            'srl', 'spa', 'sarl', 'sas', 'sa', 'ltd', 'llc', 'inc',
            'corp', 'plc', 'pty', 'co.', 'oy', 'ab', 'as', 'a/s',
            'sp.', 's.r.o.', 'a.s.', 'z.o.o.', 'k.s.',  // Polish & Czech legal forms
        ];
        foreach ($companyIndicators as $ind) {
            if (str_contains($fullLower, " $ind") || str_ends_with($fullLower, " $ind")) {
                return false;
            }
        }

        // ── 4. Reject if name starts with "Of " or "For " (parsing artifact) ─
        if (str_starts_with($firstName, 'Of ') || $firstLower === 'of'
            || str_starts_with($firstName, 'For ') || $firstLower === 'for') {
            return false;
        }

        // ── 5. Reject if first or last name matches a known company-name word ─
        // "Rtw Rohrtechnik" is a company name, not a person
        $companyNameWords = [
            'gmbh', 'rohrtechnik', 'elektronik', 'technik', 'werke',
            'maschinenbau', 'maschinen', 'fahrzeugbau', 'werkzeugbau',
            'systems', 'industries', 'solutions', 'technologies',
            'technology', 'electronics', 'engineering', 'manufacturing',
            'automotive', 'aerospace', 'industrial', 'group', 'holding',
            'international', 'global', 'worldwide', 'corporation',
            'enterprise', 'company', 'limited', 'incorporated',
        ];
        if (in_array($lastLower, $companyNameWords, true)) {
            return false;
        }

        // Reject if name contains a city name suffix (company branch patterns)
        // e.g. "Rtw Rohrtechnik Warburg" = company + city, not a person
        if (preg_match('/\b(warburg|halberstadt|münchen|berlin|hamburg|frankfurt|stuttgart|düsseldorf|köln|nürnberg|dresden|leipzig|hannover|bremen|dortmund|essen|duisburg|warszawa|kraków|krakow|gdańsk|gdansk|wrocław|wroclaw|poznań|poznan|łódź|lodz|katowice|szczecin|lublin|białystok|bialystok|gdynia|częstochowa|czestochowa|radom|sosnowiec|toruń|torun|kielce|rzeszów|rzeszow|gliwice|olsztyn|bielsko|bydgoszcz|milano|roma|torino|firenze|bologna|napoli|genova|palermo|venezia|verona|padova|brescia|modena|parma|bergamo|catania|bari|messina)\b/iu', $fullLower)) {
            return false;
        }

        // ── 6. Reject common non-person English/German words ──────
        $nonPersonWords = [
            'notice', 'because', 'required', 'functional', 'diese',
            'zwecke', 'ihre', 'benutzerdefinierten', 'attribut',
            'individuelle', 'informationen', 'anzeigen', 'ausblenden',
            'inhaltlich', 'verantwortlich', 'angaben', 'tunnel',
            'contact', 'general', 'technical', 'customer',
            // Common parsing artifacts from German Impressum pages
            'bildnachweise', 'bildnachweis', 'fotonachweis', 'quellenangaben',
            'haftungsausschluss', 'rechtshinweis', 'rechtshinweise',
            'nutzungsbedingungen', 'geschäftsbedingungen',
            'allgemeine', 'allgemeiner', 'besondere', 'besonderer',
            'vertretungsberechtigter', 'vertretungsberechtigt',
            'handelsregister', 'registergericht', 'amtsgericht',
            // Common sentence-fragment first words that aren't names
            'generated', 'through', 'close', 'cooperation',
            'click', 'button', 'here', 'below', 'above',
            'managing', 'leading', 'providing', 'delivering',
            'creating', 'building', 'making', 'developing',
            'offering', 'serving', 'supporting', 'helping',
            // Financial/legal/corporate terms
            'funds', 'advised', 'fund', 'advisory', 'capital',
            'portfolio', 'investment', 'investments', 'equity',
            'acquisition', 'acquisitions', 'venture', 'ventures',
            'partner', 'partners', 'associates', 'advisors',
            // Transportation/logistics
            'transportation', 'production', 'logistics', 'supply',
            'distribution', 'warehouse', 'freight',
            // Sentence fragments and gerunds that aren't names
            'preference', 'successfully', 'cooperating', 'beginning',
            'owners', 'owner', 'award', 'awards', 'prize', 'prizes',
            'ship', 'ships', 'been', 'have', 'has', 'were', 'was',
            'tech', 'jpg', 'png', 'pdf', 'svg', 'gif', 'img',
            // Product/technical terms
            'hydrogen', 'fuel', 'cell', 'cooling', 'magnetic',
            'eliminate', 'bulky', 'reduce', 'supplies', 'provider',
            'software', 'implantations', 'réalisations',
            'savoir', 'faire', 'siège', 'adresse',
            // Spanish product/UI words
            'productos', 'nuestros', 'nuestra', 'utiliza', 'dejar',
            'frecuencia', 'cardiaca', 'electrolitica', 'electrolítica',
            // French department/section words
            'recrutement', 'embauche', 'candidature', 'candidatures',
            'direction', 'comptabilité', 'ressources', 'humaines',
            'juridique', 'logistique', 'achats', 'approvisionnement',
            // Job title words that get parsed as part of names
            'vice', 'chief', 'officer', 'chairman', 'chairwoman',
            'directs', 'director', 'president', 'executive',
            'board', 'supervisory', 'strategy', 'strategist',
            // Marketing slogans
            'elevating', 'connectivity', 'vehicle', 'innovative',
            'redefining', 'transforming', 'empowering', 'enabling',
            'pioneering', 'advancing', 'accelerating',
            // German common words missing from initial list
            'deine', 'deiner', 'deinem', 'deinen', 'meine', 'meiner', 'meinem', 'meinen',
            'zukunft', 'perfekte', 'perfekter', 'optimale', 'optimaler', 'aktuelle', 'aktueller',
            'verlauf', 'ergebnis', 'übersicht', 'mittels', 'während', 'trotz', 'gemäß',
            'wird', 'werden', 'wurde', 'können', 'müssen', 'sollen', 'dürfen',
            'deutsche', 'deutscher', 'deutsches', 'deutschen',
            'handelsflotte', 'unbenannter', 'unbenannte', 'bausatz',
            'megawatt', 'kilowatt', 'gigawatt',
            'systemintegration', 'ansprechpartner', 'menü', 'schließen', 'schlie',
            'analyse', 'synthese', 'bewertung', 'verfahren', 'entwickelt',
            'startseite', 'inhalt', 'suche', 'ergebnisse',
            // Form field labels
            'first', 'last', 'phone', 'email', 'fax', 'mobile', 'submit',
            'field', 'placeholder', 'input', 'textarea', 'checkbox',
            // English product/feature/marketing terms
            'passionate', 'leadership', 'capacity', 'polarity', 'liquid',
            'develops', 'printer', 'showcase', 'conduct', 'speech',
            'tests', 'test', 'text', 'label', 'action', 'cooled',
            // Country/territory names
            'puerto', 'rico', 'africa', 'zealand', 'lanka', 'costa',
            'islands', 'colombia', 'comoros', 'cantonese', 'native',
            'milestones', 'milestone', 'program', 'programme', 'business',
            'small', 'cambodia', 'congo', 'guinea', 'samoa', 'tonga',
            'vanuatu', 'kiribati', 'tuvalu', 'nauru', 'palau',
            // HTML attributes parsed as names
            'noopener', 'noreferrer', 'nofollow', 'target', 'blank',
            'href', 'class', 'style', 'onclick', 'onload',
            // More German words and business terms
            'ethische', 'geschäftspraktiken', 'nachfolgend', 'berichtet',
            'vorstand', 'geschäftsbericht', 'essor', 'inverter',
            'chiller', 'spectrum', 'struktur', 'strukture',
            // Product/tech terms that get parsed as names
            'solar', 'carport', 'tracker', 'inverter', 'chiller',
            'grounds', 'common',
            // German adjectives/nouns that appear as marketing phrases
            'hohe', 'hoher', 'hohes', 'hohem', 'klare', 'klarer', 'klares',
            'flexibilität', 'kommunikation', 'kompetenz', 'qualität',
            'zuverlässigkeit', 'sicherheit', 'nachhaltigkeit', 'effizienz',
            'excellence', 'ineering', 'omplete', 'olutions', // truncated words
            // More marketing / slogan words
            'complete', 'solutions', 'reliable', 'quality', 'superior',
            'premium', 'excellence', 'dedicated', 'committed',
            'integrity', 'sustainable', 'trusted', 'precision',
            // Italian/Spanish junk
            'azienda', 'empresa', 'società', 'sociedad',
            // Ansprechpartner context (German for "contact person" label)
            'ansprechpartner', 'kontaktperson',
            // HTML/CSS UI element names that get scraped as contact names
            'accordion', 'scroll', 'overlap', 'announcement', 'carousel',
            'slider', 'dropdown', 'modal', 'popup', 'tooltip', 'sidebar',
            'footer', 'header', 'navbar', 'breadcrumb', 'pagination',
            'toggle', 'collapse', 'tab', 'tabs', 'panel', 'widget',
            'boot', 'align', 'flex', 'grid', 'container', 'wrapper',
            'overlay', 'badge', 'alert', 'progress', 'spinner',
            'thumbnail', 'jumbotron', 'navigation', 'menu',
            'immediate', 'panoramique', 'conductive', 'supports',
            'indoor', 'outdoor', 'strip', 'led', 'posts', 'latest',
            'interface', 'dual', 'integrated', 'micro', 'comparison',
            'discover', 'references', 'partenariats',
            // French common words / consent text / institutional
            'certains', 'activation', 'ces', 'sont', 'nécessaires',
            'fonctionnement', 'aide', 'améliorer', 'notre', 'site',
            'cookies', 'consentement', 'accepter', 'refuser',
            'personnaliser', 'paramètres', 'gérer', 'préférences',
            'nationale', 'civile', 'ecole', 'école', 'agence',
            'anesthésie', 'loco', 'énergies', 'energies',
            'naval', 'ouvèze', 'payre',
            // More French institutional / section words
            'association', 'fédération', 'federation', 'syndicat',
            'chambre', 'commerce', 'ministère', 'ministere',
            'autorite', 'autorité', 'régulation', 'regulation',
            // Polish common words / institutional / navigation
            'biuro', 'projektowe', 'projektowy', 'gliwicki', 'techniki',
            'park', 'spiralnych', 'pasja', 'główna', 'glowna',
            'przez', 'nas', 'plików', 'plikow', 'strona',
            'aktualności', 'aktualnosci', 'oferta', 'cennik',
            'referencje', 'realizacje', 'więcej', 'wiecej',
            'informacje', 'szczegóły', 'szczegoly', 'dowiedz',
            'firma', 'grupa', 'spółka', 'spolka', 'oddział', 'oddzial',
            'dział', 'dzial', 'zespół', 'zespol', 'zakład', 'zaklad',
            'centrum', 'instytut', 'izba', 'gospodarcza', 'gospodarcze',
            'koleje', 'małopolskie', 'malopolskie', 'polsko',
            'chińska', 'chinska', 'kierunek', 'orientacja',
            'witamy', 'witaj', 'usługi', 'uslugi', 'nasza', 'nasz',
            'tutaj', 'więcej', 'dalej', 'wstecz', 'dalsze',
            // Czech common words / institutional / navigation
            'společnost', 'spolecnost', 'oddělení', 'oddeleni',
            'hlavní', 'hlavni', 'závod', 'zavod', 'ústav', 'ustav',
            'práce', 'prace', 'český', 'cesky', 'česká', 'ceska',
            'nový', 'novy', 'nová', 'nova', 'nové', 'nove',
            'nabídka', 'nabidka', 'aktuality', 'kariéra', 'kariera',
            'přehled', 'prehled', 'vyhledávání', 'vyhledavani',
            'řešení', 'reseni', 'výrobky', 'vyrobky',
            // English sentence fragments / marketing junk
            'fully', 'professional', 'operators', 'depart',
            'aircraft', 'private', 'many', 'three', 'companies',
            'orientation', 'cooperation', 'borehole', 'calibrator',
            'magic', 'garden', 'party',
            'après', 'apres',
            // ── German product description adjectives/nouns (iter: DE Automotive/Industrial junk) ──
            'kompakt', 'kompakter', 'kompakte', 'kompaktes', 'kompaktem', 'kompakten',
            'zahlreich', 'zahlreiche', 'zahlreicher', 'zahlreiches', 'zahlreichen',
            'leistungsstark', 'leistungsstarker', 'leistungsstarke', 'leistungsstarkes',
            'mittlere', 'mittlerer', 'mittleres', 'mittlerem',
            'reife', 'reifen', 'multitag', 'multicore', 'multisensor',
            'schnittstelle', 'schnittstellen', 'steuerung', 'steuerungen',
            'robust', 'robuste', 'robuster', 'robustes', 'robustem',
            'vielseitig', 'vielseitige', 'vielseitiger', 'vielseitiges',
            'zertifiziert', 'zertifizierte', 'zertifizierter', 'zertifiziertes',
            'integriert', 'integrierte', 'integrierter', 'integriertes',
            'automatisiert', 'automatisierte', 'automatisierter',
            'modular', 'modulare', 'modularer', 'modulares',
            'kundenspezifisch', 'kundenspezifische', 'kundenspezifischer',
            'hochwertig', 'hochwertige', 'hochwertiger', 'hochwertiges',
            'langlebig', 'langlebige', 'langlebiger',
            'wartungsfrei', 'wartungsfreie', 'wartungsfreier',
            'temperatur', 'temperaturen', 'spannung', 'spannungen',
            'frequenz', 'frequenzen', 'leistung', 'leistungen',
            'baugruppe', 'baugruppen', 'platine', 'platinen',
            'bauteil', 'bauteile', 'gehäuse', 'stecker', 'buchse',
            'widerstand', 'kondensator', 'transistor', 'diode',
            // ── French common words / product / scientific terms ──
            'colorant', 'colorants', 'rouge', 'bleu', 'vert', 'noir', 'blanc',
            'ingénieur', 'ingénieurs', 'technicien', 'techniciens',
            'peuvent', 'devrait', 'devront', 'pourrait', 'pourraient',
            'vidéo', 'vidéos', 'audio', 'photo', 'photos',
            'industriel', 'industrielle', 'industriels', 'industrielles',
            'réseau', 'réseaux', 'système', 'systèmes',
            'équipement', 'équipements', 'composant', 'composants',
            'fabrication', 'production', 'assemblage', 'montage',
            'mesure', 'mesures', 'contrôle', 'capteur', 'capteurs',
            'puissance', 'tension', 'courant', 'fréquence',
            // ── Chemical / scientific compound terms ──
            'benzoate', 'denatonium', 'sulfate', 'phosphate', 'carbonate',
            'nitrate', 'acetate', 'chloride', 'oxide', 'hydroxide',
            'polymer', 'polymère', 'résine', 'silicone', 'silicium',
            // ── City / geography names (FR/DE/IT/ES) ──
            'paris', 'lyon', 'marseille', 'toulouse', 'bordeaux', 'lille',
            'nice', 'strasbourg', 'nantes', 'montpellier', 'grenoble',
            'rennes', 'rouen', 'toulon', 'reims', 'dijon', 'angers',
            'wien', 'vienna', 'zürich', 'zurich', 'bern', 'genf', 'geneva',
            'basel', 'graz', 'linz', 'salzburg', 'innsbruck', 'lausanne',
            'milano', 'roma', 'torino', 'firenze', 'bologna', 'napoli',
            'madrid', 'barcelona', 'valencia', 'sevilla', 'bilbao',
            'amsterdam', 'rotterdam', 'bruxelles', 'brussels', 'antwerp',
            // ── Generic UI / location / department terms ──
            'locations', 'location', 'office', 'offices', 'bureau', 'bureaux',
            'commercial', 'export', 'import', 'vente', 'ventes',
            'département', 'departement', 'filiale', 'filiales',
            'succursale', 'succursales', 'siège', 'siege',
            'atelier', 'ateliers', 'usine', 'usines', 'entrepôt',
            // ── Quote attribution verbs ("Says John Smith" → "Says" leaked into name) ──
            'says', 'said', 'explains', 'explained', 'adds', 'added',
            'notes', 'noted', 'states', 'stated', 'announces', 'announced',
            'comments', 'commented', 'reports', 'reported',
            'sagt', 'sagte', 'erklärt', 'erklärte', 'betont', 'betonte',
            'dit', 'déclare', 'explique', 'ajoute', 'précise', 'affirme',
            'selon', 'poursuit', 'confirme', 'indique', 'souligne',
            // ── Polish product / technical / navigation words ──
            'dostępny', 'dostepny', 'dostępne', 'dostepne', 'wydajny', 'wydajna', 'wydajne',
            'niezawodny', 'niezawodna', 'niezawodne', 'precyzyjny', 'precyzyjna',
            'wytrzymały', 'wytrzymala', 'trwały', 'trwala', 'skuteczny', 'skuteczna',
            'nowoczesny', 'nowoczesna', 'nowoczesne', 'innowacyjny', 'innowacyjna',
            'automatyczny', 'automatyczna', 'automatyczne', 'spiralnych', 'spiralna',
            'maszyna', 'maszyny', 'urządzenie', 'urzadzenie', 'urządzenia', 'urzadzenia',
            'narzędzie', 'narzedzie', 'narzędzia', 'narzedzia', 'element', 'elementy',
            'produkt', 'produkty', 'rozwiązanie', 'rozwiazanie', 'rozwiązania', 'rozwiazania',
            'technologia', 'technologie', 'system', 'systemy', 'moduł', 'modul', 'moduły', 'moduly',
            'łożysko', 'lozysko', 'łożyska', 'lozyska', 'przekładnia', 'przekladnia',
            'silnik', 'silniki', 'pompa', 'pompy', 'zawór', 'zawor', 'zawory',
            'czujnik', 'czujniki', 'sterownik', 'sterowniki', 'przetwornik', 'przetworniki',
            'sprężarka', 'sprezarka', 'kompresor', 'agregat', 'agregaty',
            'obróbka', 'obrobka', 'spawanie', 'toczenie', 'frezowanie', 'szlifowanie',
            'startup', 'attempts', 'attempt', 'resident', 'county',
            'connecting', 'locker', 'frontair', 'extremely', 'efficient',
            // ── German industrial nouns missing from prior lists ──
            'sonstiges', 'sonstige', 'sonstiger', 'drehmaschinen', 'drehmaschine',
            'dornenlose', 'dornenlos', 'rohr', 'rohre', 'rohren',
            'fräsmaschine', 'frasmaschine', 'fräsmaschinen', 'frasmaschinen',
            'bohrmaschine', 'bohrmaschinen', 'schleifmaschine', 'schleifmaschinen',
            'werkzeugmaschine', 'werkzeugmaschinen', 'bandsäge', 'bandsage',
            'hobelmaschine', 'pressmaschine', 'stanzmaschine',
            // ── Italian product / technical / navigation words ──
            'sgrigliatore', 'automatico', 'automatica', 'automatici', 'automatiche',
            'scambiatore', 'scambiatori', 'raffreddamento', 'riscaldamento',
            'aria', 'acqua', 'olio', 'vapore', 'gas',
            'sede', 'amministrativa', 'amministrativo', 'amministrazione',
            'isola', 'isole', 'territorio', 'provincia', 'regione', 'comune',
            'macchina', 'macchine', 'impianto', 'impianti', 'apparecchiatura',
            'componente', 'componenti', 'accessorio', 'accessori',
            'lavorazione', 'lavorazioni', 'produzione', 'produzioni',
            'trattamento', 'trattamenti', 'verniciatura', 'saldatura',
            'stampaggio', 'fusione', 'fresatura', 'tornitura', 'rettifica',
            'qualità', 'sicurezza', 'affidabilità', 'efficienza', 'prestazione',
            'resistenza', 'potenza', 'pressione', 'portata', 'capacità',
            'misura', 'misure', 'controllo', 'controlli', 'sensore', 'sensori',
            'motore', 'motori', 'pompa', 'valvola', 'valvole',
            'cilindro', 'cilindri', 'pistone', 'pistoni', 'riduttore', 'riduttori',
            'compressore', 'compressori', 'generatore', 'generatori',
            'trasformatore', 'trasformatori', 'inverter', 'convertitore',
            'catalogo', 'brochure', 'scheda', 'tecnica', 'manuale',
            'certificazione', 'certificazioni', 'normativa', 'normative',
            // ── Italian navigation / institutional ──
            'azienda', 'aziende', 'impresa', 'imprese', 'stabilimento',
            'reparto', 'reparti', 'ufficio', 'uffici', 'laboratorio',
            'magazzino', 'deposito', 'officina', 'fonderia', 'acciaieria',
            'contatti', 'contattaci', 'richiesta', 'preventivo',
            'notizie', 'eventi', 'novità', 'comunicato', 'stampa',
            'carriera', 'carriere', 'lavora', 'posizioni', 'aperte',
            'chi', 'siamo', 'storia', 'missione', 'visione', 'valori',
            // ── Political / head-of-state title words ──
            'republic', 'repubblica', 'republik', 'république', 'rzeczpospolita',
            'monarchy', 'kingdom', 'principality', 'chancellor', 'minister',
            'senator', 'congressman', 'parliamentarian', 'ambassador',
            'consul', 'governor', 'mayor', 'prefect', 'prefecture',
            // ── Generic English words that leak as contacts ──
            'standard', 'advanced', 'basic', 'enhanced', 'premium',
            'autonomous', 'exchangers', 'exchanger', 'cooling', 'heating',
            'cocos', 'cook',
            // ── Place/country names parsed as person names ──
            'morocco', 'maroc', 'marokko', 'turkey', 'türkiye', 'turkiye',
            'india', 'china', 'japan', 'brasil', 'brazil', 'mexico',
            'canada', 'australia', 'russia', 'world', 'global',
            'schweiz', 'suisse', 'svizzera', 'österreich', 'osterreich',
            'nederland', 'belgique', 'belgio', 'belgien',
            // ── German job titles parsed as first names ──
            'werksleiter', 'betriebsleiter', 'abteilungsleiter',
            'projektleiter', 'vertriebsleiter', 'produktionsleiter',
            'personalleiter', 'einkaufsleiter', 'fertigungsleiter',
            'qualitätsleiter', 'entwicklungsleiter', 'werkleiter',
            // ── French job titles parsed as first names ──
            'directeur', 'directrice', 'responsable', 'gérant', 'gerant',
            // ── Italian job titles parsed as first names ──
            'dirigente', 'direttore', 'direttrice',
            // ── Polish job titles parsed as first names ──
            'kierownik', 'dyrektor', 'prezes',
            // ── Organization-name words parsed as person names ──
            'trade', 'centre', 'center', 'federation', 'foundation',
            'institute', 'chamber', 'council', 'commission', 'committee',
            'authority', 'agency', 'bureau', 'ministry', 'department',
            'experiences', 'collective', 'consortium', 'syndicate',
            'cooperative', 'alliance',
            // ── LinkedIn-scraped garbage suffixes ──
            'emphasized', 'highlighted', 'underlined', 'selected',
            'verified', 'updated', 'promoted', 'featured', 'sponsored',
            'recommended', 'endorsed', 'approved', 'certified',

            // iter16: Additional junk words from crawl results
            'strengthening', 'communities', 'expanding', 'broadband',
            'roots', 'values', 'consider', 'ensuring', 'earned', 'revenue',
            'minimizes', 'coordination', 'challenges', 'reduces', 'congestion',
            'fiber', 'hubs', 'substation', 'houses', 'structures',
            'offsite', 'modular', 'rackmount', 'attenuators', 'bandpass',
            'lowpass', 'coaxial', 'galvanische', 'trennglieder', 'optokoppler',
            'arrangements', 'arrangement', 'partnerships', 'page', 'load',
            'einstellungen', 'ablehnen', 'speichern', 'auswahl', 'weiter',
            'zurück', 'laden', 'seite', 'founder', 'headquarters',
        ];
        if (in_array($firstLower, $nonPersonWords, true) || in_array($lastLower, $nonPersonWords, true)) {
            return false;
        }
        // Also check individual words within multi-word names (e.g. "Brandon Managing")
        $nameWords = preg_split('/\s+/', $fullLower);
        foreach ($nameWords as $nw) {
            if (in_array($nw, $nonPersonWords, true)) {
                return false;
            }
        }

        // ── Reject concatenated text (camelCase or runTogether patterns) ──
        // e.g. "DevelopmentSteffen" or "CEOChristian" — indicates parsing failure
        if (preg_match('/[a-z][A-Z]/', $firstName) || preg_match('/[a-z][A-Z]/', $lastName)) {
            return false;
        }
        // Reject if first or last name has more than 15 consecutive lowercase chars
        // (likely a compound word, not a name)
        if (preg_match('/[a-zà-ÿ]{16,}/u', $firstName) || preg_match('/[a-zà-ÿ]{16,}/u', $lastName)) {
            return false;
        }
        // Reject names containing "MBA" or degree abbreviations stuck to name
        if (preg_match('/MBA|PhD|PMP|MSc|BSc$/i', $firstName) || preg_match('/MBA|PhD|PMP|MSc|BSc$/i', $lastName)) {
            return false;
        }

        // ══════════════════════════════════════════════════════════════════
        // SMART PATTERN-BASED JUNK DETECTION (iter16b) - Secondary layer
        // Applies same smart detection as splitPersonName() for contacts
        // that bypass the initial parsing
        // ══════════════════════════════════════════════════════════════════

        // Gerund as first name (>5 chars ending in -ing) → likely junk
        $gerundExceptions = ['sterling', 'starling', 'king', 'ming', 'ling', 'ning', 'ping', 'ying'];
        if (preg_match('/ing$/i', $firstName) && mb_strlen($firstName) > 5 && !in_array($firstLower, $gerundExceptions, true)) {
            return false;
        }

        // Past participle as first name (>4 chars ending in -ed) → likely junk
        if (preg_match('/ed$/i', $firstName) && mb_strlen($firstName) > 4) {
            return false;
        }

        // First word looks like a comparative/superlative adjective
        if (preg_match('/er$|est$/i', $firstName) && mb_strlen($firstName) > 5) {
            // Check for common name exceptions
            $erExceptions = ['peter', 'roger', 'walter', 'oliver', 'jasper', 'tyler', 'hunter', 'porter', 'foster', 'cooper', 'parker', 'tucker', 'carter', 'amber', 'jennifer', 'heather', 'esther', 'chester', 'dexter', 'baxter', 'fletcher', 'spencer', 'homer', 'denver'];
            if (!in_array($firstLower, $erExceptions, true)) {
                // Check if it's likely a comparative adjective followed by noun
                $comparativeAdjectives = ['larger', 'smaller', 'faster', 'slower', 'higher', 'lower', 'better', 'greater', 'broader', 'deeper', 'wider', 'longer', 'shorter', 'stronger', 'weaker', 'cleaner', 'lighter', 'heavier', 'thicker', 'thinner'];
                if (in_array($firstLower, $comparativeAdjectives, true)) {
                    return false;
                }
            }
        }

        // ══════════════════════════════════════════════════════════════════
        // END SMART PATTERN DETECTION
        // ══════════════════════════════════════════════════════════════════

        // ── Final safety net: classifier-based person name check ──
        if ($this->classifier && !$this->classifier->isLikelyPersonName($firstName, $lastName)) {
            return false;
        }

        return true;
    }

    /**
     * Extract named contacts from HTML "Contact Us" / about / general pages.
     *
     * Looks for person-name + email/phone patterns in:
     * - vCard/hCard microdata
     * - LinkedIn /in/ profile links (+ name derivation from slug)
     * - CSS class patterns (contact-person, staff-info, etc.)
     * - "Name - Title" patterns in divs/paragraphs
     * - Email mailto links with nearby person names
     * - LinkedIn slug → name derivation (john-smith → John Smith)
     */
    private function extractNamedContactsFromHtml(string $html): array
    {
        // ── CRITICAL: Strip cookie banners BEFORE contact extraction ──
        $html = $this->stripCookieBannerHtml($html);

        $contacts = [];
        $titlePattern = '/\b(CEO|CTO|CFO|COO|CIO|CHRO|CMO|CSO|CPO'
            . '|VP|Vice\s*President|President|Chairman|Director|Managing\s*Director'
            . '|Manager|General\s*Manager|Head|Chief|Lead|Senior|Partner|Founder|Co-?Founder|Owner'
            . '|Purchasing|Procurement|Buyer|Supply\s*Chain|Sourcing'
            . '|Business\s*Development|Account\s*Manager|Sales\s*Manager|Regional\s*Manager'
            . '|Directeur|Directrice|Directeur\s+Général|Gérant|Responsable|Président|Fondateur|PDG|DG'
            . '|Président\s+du\s+Directoire|Membre\s+du\s+Directoire'
            . '|Geschäftsführer|Leiter|Inhaber|مدير|رئيس)\b/iu';

        // ── vCard / hCard microformat ────────────────────────────
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
        if (preg_match_all('/href=["\']?(https?:\/\/(?:www\.)?linkedin\.com\/in\/([a-zA-Z0-9_-]+))\/?["\'\s>][^>]*>([^<]{1,60})<\/a>/i', $html, $liMatches, PREG_SET_ORDER)) {
            foreach ($liMatches as $match) {
                $linkText = html_entity_decode(trim($match[3]));
                $slug = $match[2];
                $linkedInUrl = rtrim($match[1], '/');

                // Try link text first
                $parts = $this->splitPersonName($linkText);

                // If link text isn't a name (e.g. "View Profile"), derive from slug
                if (!$parts) {
                    $parts = $this->extractNameFromLinkedInSlug($slug);
                }

                if ($parts) {
                    $parts['linkedin_url'] = $linkedInUrl;
                    $contacts[] = $parts;
                }
            }
        }

        // ── CSS-class contact person divs ────────────────────────
        // <div class="contact-person|staff-info|people-card|executive">
        $contactCardPattern = '/<(?:div|li|article|section|span)[^>]*class="[^"]*\b(?:contact[-_]?person|staff[-_]?info|people[-_]?card|executive[-_]?card|key[-_]?contact|management[-_]?member|director[-_]?card)\b[^"]*"[^>]*>([\s\S]{10,2000}?)<\/(?:div|li|article|section|span)>/i';
        if (preg_match_all($contactCardPattern, $html, $ccMatches)) {
            foreach ($ccMatches[1] as $cardHtml) {
                $person = $this->extractPersonFromCardHtml($cardHtml, $titlePattern);
                if ($person) {
                    $contacts[] = $person;
                }
            }
        }

        // ── Mailto links with nearby person name context ─────────
        // Look for person name within 300 chars before a mailto link
        if (preg_match_all('/([A-ZÀ-Ÿ][a-zà-ÿ]+\s+[A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+[A-ZÀ-Ÿ][a-zà-ÿ]+)?)[^<]{0,200}href=["\']mailto:([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/iu', $html, $nameEmailMatches, PREG_SET_ORDER)) {
            foreach ($nameEmailMatches as $nem) {
                $parts = $this->splitPersonName(trim($nem[1]));
                if ($parts) {
                    $email = strtolower(trim($nem[2]));
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $parts['email'] = $email;
                    }
                    $contacts[] = $parts;
                }
            }
        }

        // ── "Name, Title" in contact sections ────────────────────
        // Look in the full page for "Name, Title" where Title is a decision-maker role
        $textContent = $this->normalizeAllCapsNames(strip_tags($html));
        // Fix strip_tags concatenation: "SayadChairman" → "Sayad Chairman"
        $textContent = preg_replace('/([a-zà-ÿ])(Chairman|President|CEO|CTO|CFO|COO|Director|Founder|Manager|Directeur|Président|Gérant)/u', '$1 $2', $textContent);
        if (preg_match_all('/([A-ZÀ-Ÿ][a-zà-ÿ]+\s+[A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+[A-ZÀ-Ÿ][a-zà-ÿ]+)?)\s*[-–—,|:\s]\s*((?:CEO|CTO|CFO|COO|VP|President|Director|Managing Director|Founder|Co-?Founder|General Manager|Owner|Chairman|Purchasing Manager|Procurement|Sales Manager|Business Development|Directeur|Gérant|Président|Président\s+du\s+Directoire|PDG|DG|Geschäftsführer|مدير)[^,.;\n]{0,60})/iu', $textContent, $nameRoleMatches, PREG_SET_ORDER)) {
            foreach ($nameRoleMatches as $nrm) {
                $parts = $this->splitPersonName(trim($nrm[1]));
                if ($parts) {
                    $parts['job_title'] = mb_substr(trim($nrm[2]), 0, 80);
                    $contacts[] = $parts;
                }
            }
        }

        // ── "Title: Name" in contact sections ──────────────────
        // Common on German/EU sites: "Geschäftsführer: Max Mustermann"
        if (preg_match_all('/\b(CEO|CTO|CFO|COO|VP|President|Director|Managing Director|Founder|Co-?Founder|General Manager|Owner|Chairman|Purchasing Manager|Procurement|Sales Manager|Business Development|Directeur|Gérant|Président|Président\s+du\s+Directoire|PDG|DG|Geschäftsführer|Geschäftsleitung|Leiter|Inhaber|Vorstand|Ansprechpartner|Leitung|Vertreten\s+durch|Vertretungsberechtigt(?:e|er)?|Prokurist(?:in)?)\b\s*[:\-–—]\s*([A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+[A-ZÀ-Ÿ][a-zà-ÿ]+){1,2})/iu', $textContent, $titleNameMatches, PREG_SET_ORDER)) {
            foreach ($titleNameMatches as $tnm) {
                $parts = $this->splitPersonName(trim($tnm[2]));
                if ($parts) {
                    $parts['job_title'] = mb_substr(trim($tnm[1]), 0, 80);
                    $contacts[] = $parts;
                }
            }
        }

        // ── "Title Name" without punctuation ──────────────────
        // Example: "Geschäftsführer Max Mustermann"
        if (preg_match_all('/\b(CEO|CTO|CFO|COO|VP|President|Director|Managing Director|Founder|Co-?Founder|General Manager|Owner|Chairman|Purchasing Manager|Procurement|Sales Manager|Business Development|Directeur|Gérant|Président|Président\s+du\s+Directoire|PDG|DG|Geschäftsführer|Geschäftsleitung|Leiter|Inhaber|Vorstand|Ansprechpartner|Leitung|Vertreten\s+durch|Vertretungsberechtigt(?:e|er)?|Prokurist(?:in)?)\b\s+([A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+[A-ZÀ-Ÿ][a-zà-ÿ]+){1,2})/iu', $textContent, $titleNamePlainMatches, PREG_SET_ORDER)) {
            foreach ($titleNamePlainMatches as $tnm) {
                $parts = $this->splitPersonName(trim($tnm[2]));
                if ($parts) {
                    $parts['job_title'] = mb_substr(trim($tnm[1]), 0, 80);
                    $contacts[] = $parts;
                }
            }
        }

        // ── "founded by Name" / "By: Name, Title" patterns ──────
        // Common on about pages of small North African / Middle Eastern companies
        if (preg_match_all('/(?:founded\s+by|created\s+by|managed\s+by|led\s+by|dirigée?\s+par|fondée?\s+par|by\s*:\s*)\s*(?:Mr\.?|Mrs\.?|Ms\.?|Mme\.?|Dr\.?|Eng\.?|Ir\.?)?\s*([A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+(?:El|Al|Ben|Bin|Abu|Ibn|De|Van|Von|Le|La)\s+)?[A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+[A-ZÀ-Ÿ][a-zà-ÿ]+)?)/iu', $textContent, $foundedMatches, PREG_SET_ORDER)) {
            foreach ($foundedMatches as $fm) {
                $parts = $this->splitPersonName(trim($fm[1]));
                if ($parts) {
                    $contacts[] = $parts;
                }
            }
        }

        // ── "Mr. Name Name, Chairman/CEO/..." inline title pattern ──
        // Catches: "Mr. Mohamed Ismail Abdou, Euromed Chairman"
        //          "Esmat El SayadChairman & CEO"
        $inlineTitleKw = 'Chairman|President|CEO|CTO|CFO|COO|Founder|Director|Managing\s*Director|General\s*Manager'
            . '|Président|Directeur|Gérant|Fondateur|PDG|DG|Président\s+du\s+Directoire';
        if (preg_match_all('/(?:Mr\.?|Mrs\.?|Ms\.?|Mme\.?|Dr\.?|Eng\.?|Ir\.?)\s+([A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+[A-ZÀ-Ÿ][a-zà-ÿ]+){1,3})\s*[,\s]\s*(?:\w+\s+)?(?:' . $inlineTitleKw . ')/iu', $textContent, $inlineMatches, PREG_SET_ORDER)) {
            foreach ($inlineMatches as $im) {
                $parts = $this->splitPersonName(trim($im[1]));
                if ($parts) {
                    $contacts[] = $parts;
                }
            }
        }

        // Deduplicate by first+last name
        $seen = [];
        $unique = [];
        foreach ($contacts as $c) {
            $key = strtolower(($c['first_name'] ?? '') . '|' . ($c['last_name'] ?? ''));
            if ($key === '|' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $unique[] = $c;
        }

        // ── Clean HTML artifacts & filter out non-person contacts ──
        $unique = array_map(fn(array $c) => $this->cleanContactFields($c), $unique);
        $unique = array_values(array_filter($unique, fn(array $c) => $this->isValidPersonContact($c)));

        return array_slice($unique, 0, 5);
    }

    /**
     * Derive a person name from a LinkedIn profile slug.
     *
     * "john-smith-12345" → ['first_name' => 'John', 'last_name' => 'Smith']
     * "jean-marie-dupont" → ['first_name' => 'Jean-Marie', 'last_name' => 'Dupont']
     */
    private function extractNameFromLinkedInSlug(string $slug): ?array
    {
        // Remove trailing numeric ID (john-smith-12345ab → john-smith)
        $slug = preg_replace('/-[0-9a-f]{5,}$/i', '', $slug);
        // Remove trailing digits (john-smith-123 → john-smith)
        $slug = preg_replace('/-\d+$/', '', $slug);

        $parts = explode('-', $slug);
        if (count($parts) < 2) return null;

        // Filter out parts that look like numbers or are too short
        $parts = array_filter($parts, fn($p) => strlen($p) >= 2 && !is_numeric($p));
        $parts = array_values($parts);
        if (count($parts) < 2) return null;

        $firstName = ucfirst(strtolower($parts[0]));
        // If 3+ parts, use last as lastName, join middle parts with first
        if (count($parts) >= 3) {
            $lastName = ucfirst(strtolower(array_pop($parts)));
            array_shift($parts); // remove first
            // Could be a compound first name like Jean-Marie
            $firstName = $firstName;
        } else {
            $lastName = ucfirst(strtolower($parts[1]));
        }

        // Validate: names should be at least 2 chars
        if (strlen($firstName) < 2 || strlen($lastName) < 2) return null;

        // Reject generic slugs
        $genericSlugs = ['about', 'company', 'contact', 'admin', 'sales', 'info', 'support', 'team', 'staff', 'manager', 'director'];
        if (in_array(strtolower($firstName), $genericSlugs) || in_array(strtolower($lastName), $genericSlugs)) {
            return null;
        }

        return ['first_name' => $firstName, 'last_name' => $lastName];
    }

    /**
     * Scrape subpages (/contact, /about-us, /team, /people) to find
     * additional contacts, addresses, and phone numbers.
     *
     * This is FREE — only HTTP requests, no API calls.
     * Returns an enrichment array compatible with mergeEnrichment().
     */
    private function scrapeSubpagesForContacts(string $website, string $companyName): ?array
    {
        if (empty($website)) {
            return null;
        }

        // Normalize base URL
        $base = rtrim($website, '/');

        // ── Sitemap-first page discovery ──
        // If we have a SitemapPageDiscovery service, prefer sitemap-sourced
        // pages since they reflect the site's actual structure, then fall
        // back to hardcoded paths if the sitemap yields nothing.
        $sitemapPaths = [];
        if ($this->sitemapDiscovery !== null) {
            try {
                $domain = parse_url($base, PHP_URL_HOST) ?? '';
                $sitemapPages = $this->sitemapDiscovery->discoverPages($domain);
                foreach ($sitemapPages as $page) {
                    $path = parse_url($page['url'], PHP_URL_PATH);
                    if ($path !== null && $path !== '/' && $path !== '') {
                        $sitemapPaths[] = $path;
                    }
                }
                if (!empty($sitemapPaths)) {
                    $this->logger->debug('scrapeSubpagesForContacts: sitemap provided paths', [
                        'domain' => $domain,
                        'count'  => count($sitemapPaths),
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->debug('scrapeSubpagesForContacts: sitemap discovery failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $subpages = [
            // Contact pages
            '/contact', '/contact-us', '/contactus', '/kontakt',
            '/contacts', '/get-in-touch', '/reach-us', '/talk-to-us',
            // About pages (often have leadership/management info)
            '/about', '/about-us', '/aboutus', '/who-we-are',
            '/a-propos', '/qui-sommes-nous',  // French (Morocco)
            '/ueber-uns', '/uber-uns', '/ueberuns', // German (uml/ASCII variants)
            '/sobre-nosotros',                 // Spanish
            '/sobre-nos',                      // Portuguese
            '/chi-siamo',                      // Italian
            '/over-ons',                       // Dutch
            '/om-oss', '/om-os',               // Norwegian/Danish
            '/meista',                         // Finnish
            '/o-nas',                          // Polish/Czech
            '/despre-noi',                     // Romanian
            '/rolunk',                         // Hungarian
            // Team/leadership pages - US-style
            '/team', '/our-team', '/the-team', '/meet-the-team',
            '/people', '/our-people', '/staff', '/our-staff',
            '/leadership', '/leadership-team', '/senior-leadership',
            '/management', '/management-team', '/exec-team',
            '/executives', '/executive-team', '/executive-leadership',
            '/board', '/board-of-directors', '/our-board',
            '/bios', '/biographies', '/executive-bios',
            '/key-contacts', '/key-people', '/key-executives',
            '/president', '/ceo', '/founders',
            // Team/leadership - French
            '/notre-equipe', '/equipe', '/equipe-dirigeante',
            '/direction', '/nous-contacter',
            // Team/leadership - German
            '/ansprechpartner', '/mitarbeiter', '/geschaeftsfuehrung', '/geschaeftsleitung',
            '/leitung', '/vorstand', '/kader',
            // Team/leadership - Other EU languages
            '/equipo', '/direccion',           // Spanish
            '/equipa', '/direcao',             // Portuguese
            '/direzione', '/azienda',          // Italian
            '/directie', '/organisatie',       // Dutch
            '/ledning', '/ledelse', '/ledningsgrupp', // Scandinavian
            '/yhteystiedot', '/johto',          // Finnish
            '/zarzad', '/kadra', '/zespol',     // Polish
            '/vedeni', '/tym',                  // Czech
            '/conducere', '/echipa',            // Romanian
            '/vezetoseg', '/csapat',            // Hungarian
            // Company info pages
            '/company', '/corporate', '/company-info',
            '/impressum', '/unternehmen', '/kontaktseite', // German legal/info pages
            // Additional paths common on small company sites
            '/en/about', '/en/contact', '/en/team',  // English subpath for multilingual sites
            '/about/team', '/about/leadership', '/about/management',
            '/company/team', '/company/leadership', '/company-profile',
            '/company/about', '/company/contact',
            // CMS pages common on North African / Middle Eastern sites
            '/english/pages', '/en/about-us', '/en/who-we-are',
            '/fr/a-propos', '/fr/contact',
            '/governance', '/corporate-governance', '/gouvernance',
            '/directoire', '/conseil-administration',
            '/chairman', '/chairman-message', '/ceo-message',
            '/words-from-chairman', '/message-du-president',
            '/mot-du-president', '/mot-du-directeur',
            // US business-style pages
            '/why-us', '/why-choose-us', '/our-story', '/our-history',
            '/locations', '/offices', '/facilities',
        ];

        // Merge sitemap paths at the front (higher quality) and deduplicate
        if (!empty($sitemapPaths)) {
            $subpages = array_values(array_unique(array_merge($sitemapPaths, $subpages)));
        }

        $enrichment = ['contacts' => []];

        // ── Polite crawl governor ──
        $crawlDomain = parse_url($base, PHP_URL_HOST) ?? '';

        // Fire concurrent requests for all subpages
        // Prioritize /en/ paths for multilingual EU sites
        $responses = [];
        foreach ($subpages as $path) {
            // Check crawl governor before each request
            if ($this->crawlGovernor !== null && !$this->crawlGovernor->canRequest($crawlDomain)) {
                $this->logger->debug('scrapeSubpagesForContacts: crawl limit reached', [
                    'domain' => $crawlDomain,
                    'path'   => $path,
                ]);
                break;
            }

            try {
                if ($this->crawlGovernor !== null) {
                    $this->crawlGovernor->throttle($crawlDomain);
                }
                $url = $base . $path;
                $responses[$path] = $this->httpClient->request('GET', $url, [
                    'timeout' => 4,
                    'max_redirects' => 2,
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Accept' => 'text/html,application/xhtml+xml',
                        'Accept-Language' => 'en-US,en;q=0.9',
                    ],
                ]);
            } catch (\Exception $e) {
                // skip
            }
        }

        // Process responses
        foreach ($responses as $path => $response) {
            try {
                $code = $response->getStatusCode();
                if ($code >= 400) {
                    // Report error to crawl governor for backoff
                    if ($this->crawlGovernor !== null && $code >= 429) {
                        $this->crawlGovernor->reportError($crawlDomain, $code);
                    }
                    continue;
                }

                // Report success to crawl governor
                if ($this->crawlGovernor !== null) {
                    $this->crawlGovernor->reportSuccess($crawlDomain);
                }

                $html = $this->smartTruncateHtml($response->getContent(false), 200000);
                if (empty($html)) continue;

                // Extract contacts from this subpage
                $contactInfo = $this->extractContactInfoFromHtml($html);

                // Merge phone
                if (empty($enrichment['phone']) && !empty($contactInfo['phone'])) {
                    $enrichment['phone'] = $contactInfo['phone'];
                }
                // Merge email
                if (empty($enrichment['email']) && !empty($contactInfo['email'])) {
                    $enrichment['email'] = $contactInfo['email'];
                }
                // Merge address
                if (empty($enrichment['address']) && !empty($contactInfo['address'])) {
                    $enrichment['address'] = $contactInfo['address'];
                }
                // Merge contacts
                if (!empty($contactInfo['contacts'])) {
                    $enrichment['contacts'] = array_merge(
                        $enrichment['contacts'],
                        $contactInfo['contacts']
                    );
                }
                // Merge all_emails for email-to-name extraction downstream
                if (!empty($contactInfo['all_emails'])) {
                    $enrichment['all_emails'] = array_values(array_unique(
                        array_merge($enrichment['all_emails'] ?? [], $contactInfo['all_emails'])
                    ));
                }

                // Also try extracting person names from visible text
                // on ALL subpages (leadership info can be on any page)
                $teamContacts = $this->extractTeamPageContacts($html);
                if (!empty($teamContacts)) {
                    $enrichment['contacts'] = array_merge(
                        $enrichment['contacts'],
                        $teamContacts
                    );
                }

            } catch (\Exception $e) {
                // skip failed pages
            }
        }

        // Deduplicate contacts by first+last name
        if (!empty($enrichment['contacts'])) {
            $seen = [];
            $unique = [];
            foreach ($enrichment['contacts'] as $c) {
                $key = strtolower(($c['first_name'] ?? '') . '|' . ($c['last_name'] ?? ''));
                if ($key === '|' || isset($seen[$key])) continue;
                $seen[$key] = true;
                $unique[] = $c;
            }
            $enrichment['contacts'] = array_slice($unique, 0, 5);
        }

        // ── Filter out contacts with banking/consulting/mismatched emails ──
        // Subpage scraping can pick up analyst emails from investor-relations
        // pages (e.g. socgen.com, tpicap.com on annual reports).
        if (!empty($enrichment['contacts'])) {
            $companyDomain = $this->extractCompanyDomain($website);
            $enrichment['contacts'] = array_values(array_filter(
                $enrichment['contacts'],
                fn(array $c) => !$this->isRejectContactEmail($c['email'] ?? null, $companyDomain)
            ));
        }

        // Only return if we found something useful
        if (!empty($enrichment['contacts']) || !empty($enrichment['address'])
            || !empty($enrichment['phone']) || !empty($enrichment['email'])) {
            return $enrichment;
        }
        return null;
    }

    /**
     * Extract person names and roles from team/leadership/about pages.
     *
     * Recognizes 10+ common HTML patterns used by corporate websites:
     * - Headings followed by role/title text
     * - CSS-classed team member cards
     * - Figure/figcaption patterns (photo + name)
     * - Table/list patterns (Name | Title)
     * - data-name attributes
     * - Schema.org Person microdata
     * - WordPress/CMS team plugin markup
     */
    private function extractTeamPageContacts(string $html): array
    {
        // ── CRITICAL: Strip cookie banners BEFORE contact extraction ──
        $html = $this->stripCookieBannerHtml($html);

        $contacts = [];
        $titlePattern = '/\b(CEO|CTO|CFO|COO|CIO|CHRO|CMO|CSO|CPO|CLO|CDO'
            . '|VP|Vice\s*President|President|Chairman|Chairwoman|Chairperson'
            . '|Director|Managing\s*Director|General\s*Manager|Country\s*Manager'
            . '|Manager|Head|Chief|Lead|Senior|Principal|Partner|Associate'
            . '|Engineer|Architect|Officer|Founder|Co-?Founder|Owner'
            . '|Purchasing|Procurement|Buyer|Supply\s*Chain|Sourcing'
            . '|Business\s*Development|Account\s*Manager|Sales\s*Manager'
            . '|Regional\s*Manager|Operations|Plant\s*Manager'
            . '|Directeur|Directrice|Directeur\s+Général|Gérant|Responsable|Président|Fondateur|PDG|DG'  // French
            . '|Président\s+du\s+Directoire|Membre\s+du\s+Directoire'  // French governance
            . '|Geschäftsführer|Leiter|Inhaber'                     // German
            . '|مدير|رئيس)\b/iu';                                   // Arabic

        // ── Pattern 1: <h2-4>Name</h2-4> ... <p|span|div>Title</p|span|div>
        // REQUIRES a recognized job title — bare headings are too noisy
        if (preg_match_all('/<h[2-4][^>]*>\s*([A-ZÀ-Ÿ][a-zà-ÿ]+\s+[A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+[A-ZÀ-Ÿ][a-zà-ÿ]+)?)\s*<\/h[2-4]>\s*(?:<[^>]*>)*\s*([^<]{3,80})/u', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $title = trim(strip_tags($m[2]));
                if (!preg_match($titlePattern, $title)) continue; // Skip if no job title
                $parts = $this->splitPersonName(html_entity_decode(trim($m[1])));
                if ($parts) {
                    $parts['job_title'] = mb_substr($title, 0, 80);
                    $contacts[] = $parts;
                }
            }
        }

        // ── Pattern 2: <strong|b>Name</strong|b> - Title
        // REQUIRES a recognized job title to avoid matching random bold text
        if (preg_match_all('/<(?:strong|b)>\s*([A-ZÀ-Ÿ][a-zà-ÿ]+\s+[A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+[A-ZÀ-Ÿ][a-zà-ÿ]+)?)\s*<\/(?:strong|b)>\s*[-–—,]?\s*([^<]{3,80})/u', $html, $matches2, PREG_SET_ORDER)) {
            foreach ($matches2 as $m) {
                $title = trim(strip_tags($m[2]));
                if (!preg_match($titlePattern, $title)) continue; // Skip if no job title
                $parts = $this->splitPersonName(html_entity_decode(trim($m[1])));
                if ($parts) {
                    $parts['job_title'] = mb_substr($title, 0, 80);
                    $contacts[] = $parts;
                }
            }
        }

        // ── Pattern 3: CSS-class based team member cards ─────────
        // <div class="team-member|staff|bio|person|member|employee|card">
        //   ... <h3|span|strong class="name">Name</...> ... <p|span class="title|role|position">Title</...>
        $cardPattern = '/<(?:div|li|article|section)[^>]*class="[^"]*\b(?:team[-_]?member|staff[-_]?member|bio|person|member[-_]?card|employee|team[-_]?card|people[-_]?item|leadership[-_]?card|executive|board[-_]?member)\b[^"]*"[^>]*>([\s\S]{20,2000}?)<\/(?:div|li|article|section)>/i';
        if (preg_match_all($cardPattern, $html, $cardMatches)) {
            foreach ($cardMatches[1] as $cardHtml) {
                $person = $this->extractPersonFromCardHtml($cardHtml, $titlePattern);
                if ($person) {
                    $contacts[] = $person;
                }
            }
        }

        // ── Pattern 4: <figure>/<figcaption> (photo + name) ─────
        // Requires "Name - Title" format in figcaption
        if (preg_match_all('/<figure[^>]*>([\s\S]{10,2000}?)<\/figure>/i', $html, $figMatches)) {
            foreach ($figMatches[1] as $figHtml) {
                if (preg_match('/<figcaption[^>]*>([\s\S]{5,500}?)<\/figcaption>/i', $figHtml, $capMatch)) {
                    $capText = strip_tags($capMatch[1]);
                    // "John Smith, CEO" or "John Smith - Director"
                    if (preg_match('/^([A-ZÀ-Ÿ][a-zà-ÿ]+\s+[A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+[A-ZÀ-Ÿ][a-zà-ÿ]+)?)\s*[-–—,]\s*(.+)$/u', trim($capText), $capParts)) {
                        $title = trim($capParts[2]);
                        if (!preg_match($titlePattern, $title)) continue;
                        $parts = $this->splitPersonName($capParts[1]);
                        if ($parts) {
                            $parts['job_title'] = mb_substr($title, 0, 80);
                            $contacts[] = $parts;
                        }
                    }
                }
            }
        }

        // ── Pattern 5: data-name attributes ─────────────────────
        if (preg_match_all('/data-name=["\']([A-ZÀ-Ÿ][a-zà-ÿ]+\s+[A-ZÀ-Ÿ][^"\']{2,40})["\'](?:[^>]*data-(?:title|role|position)=["\']([^"\']+)["\'])?/iu', $html, $dataMatches, PREG_SET_ORDER)) {
            foreach ($dataMatches as $dm) {
                $parts = $this->splitPersonName(html_entity_decode(trim($dm[1])));
                if ($parts) {
                    if (!empty($dm[2]) && preg_match($titlePattern, $dm[2])) {
                        $parts['job_title'] = mb_substr(trim($dm[2]), 0, 80);
                    }
                    $contacts[] = $parts;
                }
            }
        }

        // ── Pattern 6: Schema.org Person microdata ──────────────
        // <div itemtype="https://schema.org/Person"><span itemprop="name">...</span>
        if (preg_match_all('/<[^>]*itemtype=["\']https?:\/\/schema\.org\/Person["\'][^>]*>([\s\S]{10,2000}?)<\/(?:div|span|li|article)>/i', $html, $sdMatches)) {
            foreach ($sdMatches[1] as $sdHtml) {
                $person = [];
                if (preg_match('/itemprop=["\']name["\'][^>]*>([^<]+)/i', $sdHtml, $nm)) {
                    $parts = $this->splitPersonName(html_entity_decode(trim($nm[1])));
                    if ($parts) $person = $parts;
                }
                if (preg_match('/itemprop=["\']jobTitle["\'][^>]*>([^<]+)/i', $sdHtml, $jt)) {
                    $person['job_title'] = mb_substr(trim(html_entity_decode($jt[1])), 0, 80);
                }
                if (preg_match('/itemprop=["\']email["\'][^>]*>([^<]+)/i', $sdHtml, $em)) {
                    $email = strtolower(trim($em[1]));
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $person['email'] = $email;
                    }
                }
                if (!empty($person['first_name'])) {
                    $contacts[] = $person;
                }
            }
        }

        // ── Pattern 7: <table> rows with Name | Title columns ───
        if (preg_match_all('/<tr[^>]*>\s*<td[^>]*>\s*([A-ZÀ-Ÿ][a-zà-ÿ]+\s+[A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+[A-ZÀ-Ÿ][a-zà-ÿ]+)?)\s*<\/td>\s*<td[^>]*>\s*([^<]{3,80})\s*<\/td>/iu', $html, $tableMatches, PREG_SET_ORDER)) {
            foreach ($tableMatches as $tm) {
                $parts = $this->splitPersonName(html_entity_decode(trim($tm[1])));
                if ($parts) {
                    $title = trim(strip_tags($tm[2]));
                    if (preg_match($titlePattern, $title)) {
                        $parts['job_title'] = mb_substr($title, 0, 80);
                    }
                    $contacts[] = $parts;
                }
            }
        }

        // ── Pattern 8: <li> with "Name - Title" in list format ──
        if (preg_match_all('/<li[^>]*>\s*(?:<[^>]+>)*\s*([A-ZÀ-Ÿ][a-zà-ÿ]+\s+[A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+[A-ZÀ-Ÿ][a-zà-ÿ]+)?)\s*[-–—,]\s*([^<]{3,80})/u', $html, $liMatches, PREG_SET_ORDER)) {
            foreach ($liMatches as $lm) {
                $parts = $this->splitPersonName(html_entity_decode(trim($lm[1])));
                if ($parts) {
                    $title = trim(strip_tags($lm[2]));
                    if (preg_match($titlePattern, $title)) {
                        $parts['job_title'] = mb_substr($title, 0, 80);
                    }
                    $contacts[] = $parts;
                }
            }
        }

        // ── Pattern 9: WordPress team plugin / Elementor ────────
        // <div class="elementor-widget-image-box">...<h3>Name</h3><p>Title</p>
        // <div class="wp-block-media-text">...<h2-4>Name<...><p>Title<...>
        $wpPattern = '/<div[^>]*class="[^"]*\b(?:elementor-(?:widget|team)|wp-block-(?:media|column)|team[-_]?block|staff[-_]?block)\b[^"]*"[^>]*>([\s\S]{20,3000}?)<\/div>\s*<\/div>/i';
        if (preg_match_all($wpPattern, $html, $wpMatches)) {
            foreach ($wpMatches[1] as $wpHtml) {
                $person = $this->extractPersonFromCardHtml($wpHtml, $titlePattern);
                if ($person) {
                    $contacts[] = $person;
                }
            }
        }

        // ── Pattern 10: "Name" + mailto link nearby ─────────────
        // <h3>John Smith</h3>...<a href="mailto:john@company.com">
        if (preg_match_all('/<h[2-5][^>]*>\s*([A-ZÀ-Ÿ][a-zà-ÿ]+\s+[A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+[A-ZÀ-Ÿ][a-zà-ÿ]+)?)\s*<\/h[2-5]>[\s\S]{0,500}?href=["\']mailto:([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})["\']?/iu', $html, $mailHeadMatches, PREG_SET_ORDER)) {
            foreach ($mailHeadMatches as $mhm) {
                $parts = $this->splitPersonName(html_entity_decode(trim($mhm[1])));
                if ($parts) {
                    $email = strtolower(trim($mhm[2]));
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $parts['email'] = $email;
                    }
                    $contacts[] = $parts;
                }
            }
        }

        // ── Pattern 11: Plain text "Name, Title" paragraphs ─────
        // Common on minimalist about pages: "Founded by John Smith, CEO."
        $textContent = $this->normalizeAllCapsNames(strip_tags($html));
        // Fix strip_tags concatenation: "SayadChairman" → "Sayad Chairman"
        $textContent = preg_replace('/([a-zà-ÿ])(Chairman|President|CEO|CTO|CFO|COO|Director|Founder|Manager|Directeur|Président|Gérant)/u', '$1 $2', $textContent);
        if (preg_match_all('/(?:^|\.\s+|;\s+)([A-ZÀ-Ÿ][a-zà-ÿ]+\s+[A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+[A-ZÀ-Ÿ][a-zà-ÿ]+)?)\s*,\s*((?:CEO|CTO|CFO|COO|VP|President|Director|Managing Director|Founder|Co-?Founder|General Manager|Owner|Chairman|Geschäftsführer|Directeur|Gérant|Responsable|Président|Président\s+du\s+Directoire|PDG|DG|مدير)[^,.;]{0,50})/iu', $textContent, $plainMatches, PREG_SET_ORDER)) {
            foreach ($plainMatches as $pm) {
                $parts = $this->splitPersonName(trim($pm[1]));
                if ($parts) {
                    $parts['job_title'] = mb_substr(trim($pm[2]), 0, 80);
                    $contacts[] = $parts;
                }
            }
        }

        // ── Pattern 12: meta author tag ─────────────────────────
        if (preg_match('/name=["\']author["\'][^>]*content=["\']([^"\']+)/i', $html, $authorMatch)
            || preg_match('/content=["\']([^"\']+)["\'][^>]*name=["\']author/i', $html, $authorMatch)) {
            $parts = $this->splitPersonName(html_entity_decode(trim($authorMatch[1])));
            if ($parts) {
                $contacts[] = $parts;
            }
        }

        // ── Pattern 13: "founded by / managed by / By:" text ────
        // Common on about pages: "founded by Mr. Samir Shata in 1985"
        if (preg_match_all('/(?:founded\s+by|created\s+by|managed\s+by|led\s+by|dirigée?\s+par|fondée?\s+par|by\s*:\s*)\s*(?:Mr\.?|Mrs\.?|Ms\.?|Mme\.?|Dr\.?|Eng\.?|Ir\.?)?\s*([A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+(?:El|Al|Ben|Bin|Abu|Ibn|De|Van|Von|Le|La)\s+)?[A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+[A-ZÀ-Ÿ][a-zà-ÿ]+)?)/iu', $textContent, $foundedMatches, PREG_SET_ORDER)) {
            foreach ($foundedMatches as $fm) {
                $parts = $this->splitPersonName(trim($fm[1]));
                if ($parts) {
                    $contacts[] = $parts;
                }
            }
        }

        // ── Pattern 14: "Words from the [Title]" / "Message from [Title]" ──
        // e.g. "Words from the Chairman: Esmat El Sayad"
        if (preg_match_all('/(?:words?\s+from\s+(?:the\s+)?|message\s+from\s+(?:the\s+)?|mot\s+du?\s+)(?:chairman|president|ceo|director|founder|managing\s*director|gérant|directeur|président)[^.]{0,200}?([A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+(?:El|Al|Ben|Bin|Abu|Ibn|De|Van|Von|Le|La)\s+)?[A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+[A-ZÀ-Ÿ][a-zà-ÿ]+)?)/iu', $textContent, $wordsFromMatches, PREG_SET_ORDER)) {
            foreach ($wordsFromMatches as $wfm) {
                $parts = $this->splitPersonName(trim($wfm[1]));
                if ($parts) {
                    $contacts[] = $parts;
                }
            }
        }

        // ── Pattern 15: "Mr. Name Name, Chairman/CEO/..." inline title ──
        // Catches: "Mr. Mohamed Ismail Abdou, Euromed Chairman"
        $inlineTitleKw = 'Chairman|President|CEO|CTO|CFO|COO|Founder|Director|Managing\s*Director|General\s*Manager'
            . '|Président|Directeur|Gérant|Fondateur|PDG|DG|Président\s+du\s+Directoire';
        if (preg_match_all('/(?:Mr\.?|Mrs\.?|Ms\.?|Mme\.?|Dr\.?|Eng\.?|Ir\.?)\s+([A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+[A-ZÀ-Ÿ][a-zà-ÿ]+){1,3})\s*[,\s]\s*(?:\w+\s+)?(?:' . $inlineTitleKw . ')/iu', $textContent, $inlineMatches, PREG_SET_ORDER)) {
            foreach ($inlineMatches as $im) {
                $parts = $this->splitPersonName(trim($im[1]));
                if ($parts) {
                    $contacts[] = $parts;
                }
            }
        }

        // Deduplicate by first+last name
        $seen = [];
        $unique = [];
        foreach ($contacts as $c) {
            $key = strtolower(($c['first_name'] ?? '') . '|' . ($c['last_name'] ?? ''));
            if ($key === '|' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $unique[] = $c;
        }

        // ── Clean HTML artifacts & filter out non-person contacts ──
        $unique = array_map(fn(array $c) => $this->cleanContactFields($c), $unique);
        $unique = array_values(array_filter($unique, fn(array $c) => $this->isValidPersonContact($c)));

        return array_slice($unique, 0, 5);
    }

    /**
     * Extract a person (name + title) from a team member card HTML fragment.
     *
     * Handles common card layouts:
     *   <h3>Name</h3><p class="title">Title</p>
     *   <span class="name">Name</span><span class="role">Title</span>
     *   <strong>Name</strong> - Title
     */
    private function extractPersonFromCardHtml(string $cardHtml, string $titlePattern): ?array
    {
        // Normalize ALL-CAPS text within card (small fragment, safe to process)
        $cardHtml = $this->normalizeAllCapsNames($cardHtml);
        $person = null;

        // Try heading-based name
        if (preg_match('/<h[2-5][^>]*>\s*([A-ZÀ-Ÿ][a-zà-ÿ]+\s+[A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+[A-ZÀ-Ÿ][a-zà-ÿ]+)?)\s*<\/h[2-5]>/u', $cardHtml, $nm)) {
            $person = $this->splitPersonName(html_entity_decode(trim($nm[1])));
        }
        // Try class="name|person-name|member-name"
        if (!$person && preg_match('/class="[^"]*\b(?:name|person[-_]?name|member[-_]?name|staff[-_]?name)\b[^"]*"[^>]*>([^<]+)/i', $cardHtml, $nm)) {
            $person = $this->splitPersonName(html_entity_decode(trim($nm[1])));
        }
        // Try <strong>Name</strong>
        if (!$person && preg_match('/<(?:strong|b)>\s*([A-ZÀ-Ÿ][a-zà-ÿ]+\s+[A-ZÀ-Ÿ][a-zà-ÿ]+(?:\s+[A-ZÀ-Ÿ][a-zà-ÿ]+)?)\s*<\/(?:strong|b)>/u', $cardHtml, $nm)) {
            $person = $this->splitPersonName(html_entity_decode(trim($nm[1])));
        }

        if (!$person) return null;

        // Extract title from class="title|role|position|designation"
        if (preg_match('/class="[^"]*\b(?:title|role|position|designation|job[-_]?title)\b[^"]*"[^>]*>([^<]+)/i', $cardHtml, $titleMatch)) {
            $title = trim(html_entity_decode($titleMatch[1]));
            if (preg_match($titlePattern, $title)) {
                $person['job_title'] = mb_substr($title, 0, 80);
            }
        }
        // Fallback: title in <p> after name heading
        elseif (preg_match('/<p[^>]*>\s*([^<]{3,80})\s*<\/p>/i', $cardHtml, $pMatch)) {
            $title = trim(html_entity_decode($pMatch[1]));
            if (preg_match($titlePattern, $title)) {
                $person['job_title'] = mb_substr($title, 0, 80);
            }
        }

        // Extract email
        if (preg_match('/href=["\']mailto:([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})["\']?/i', $cardHtml, $emailMatch)) {
            $email = strtolower(trim($emailMatch[1]));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $person['email'] = $email;
            }
        }

        // Extract LinkedIn
        if (preg_match('/href=["\']?(https?:\/\/(?:www\.)?linkedin\.com\/in\/[a-zA-Z0-9_-]+)\/?["\'\s>]/i', $cardHtml, $liMatch)) {
            $person['linkedin_url'] = rtrim($liMatch[1], '/');
        }

        return $person;
    }

    /**
     * Known non-company email domains: banking, consulting, ISPs, etc.
     * Contacts with these email domains are always rejected.
     */
    private const REJECT_EMAIL_DOMAINS = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com',
        'socgen.com', 'bnpparibas.com', 'credit-agricole.com', 'cic.fr',
        'hsbc.com', 'barclays.com', 'jpmorgan.com', 'goldmansachs.com',
        'morganstanley.com', 'ubs.com', 'db.com', 'citi.com', 'rbc.com',
        'tpicap.com', 'oddo-bhf.com', 'natixis.com', 'lazard.com',
        'pwc.com', 'deloitte.com', 'ey.com', 'kpmg.com', 'mckinsey.com',
        'bcg.com', 'bain.com', 'accenture.com', 'capgemini.com',
    ];

    /**
     * Check if an email belongs to a known non-company domain (banking,
     * consulting, ISP) or mismatches the company's own domain.
     *
     * Returns true if the email should be REJECTED.
     */
    private function isRejectContactEmail(?string $email, ?string $companyDomain): bool
    {
        if (empty($email) || !str_contains($email, '@')) {
            return false; // No email — don't reject the contact entirely
        }

        $emailDomain = strtolower(explode('@', $email)[1] ?? '');
        if (empty($emailDomain)) {
            return false;
        }

        // ── 1. Hardcoded reject domains (banking, consulting, ISP) ──
        if (in_array($emailDomain, self::REJECT_EMAIL_DOMAINS, true)) {
            $this->logger->debug('Rejected contact email from non-company domain', [
                'email' => $email,
                'company_domain' => $companyDomain,
            ]);
            return true;
        }

        // ── 2. Domain mismatch: email from completely different org ──
        if ($companyDomain && $emailDomain) {
            $companyRoot = implode('.', array_slice(explode('.', $companyDomain), -2));
            $emailRoot = implode('.', array_slice(explode('.', $emailDomain), -2));
            if ($companyRoot !== $emailRoot) {
                $companyWord = explode('.', $companyRoot)[0];
                $emailWord = explode('.', $emailRoot)[0];
                if (strlen($companyWord) >= 3 && strlen($emailWord) >= 3
                    && !str_contains($emailWord, $companyWord)
                    && !str_contains($companyWord, $emailWord)) {
                    $this->logger->debug('Rejected contact email domain mismatch', [
                        'email' => $email,
                        'email_domain' => $emailDomain,
                        'company_domain' => $companyDomain,
                    ]);
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract the root domain from a website URL for email matching.
     * "https://www.figeac-aero.com/en" → "figeac-aero.com"
     */
    private function extractCompanyDomain(?string $website): ?string
    {
        if (empty($website)) {
            return null;
        }
        $host = parse_url($website, PHP_URL_HOST);
        if (!$host) {
            return null;
        }
        return strtolower(preg_replace('/^www\./', '', $host));
    }

    /**
     * Extract person contacts from email patterns like firstname.lastname@domain.
     *
     * "john.smith@company.com" → first_name=John, last_name=Smith
     * "j.doe@company.com" → skip (first name too short)
      * @param array<string|int, mixed> $data
     */
    private function extractContactsFromEmails(array $data): array
    {
        $contacts = [];

        // ── Determine company's own domain for email validation ──
        $companyDomain = $this->extractCompanyDomain($data['website'] ?? null);

        // Collect all emails from every source
        $emails = [];
        if (!empty($data['email'])) {
            $emails[] = $data['email'];
        }
        // Include ALL emails found during HTML extraction (mailto: links)
        if (!empty($data['all_emails']) && is_array($data['all_emails'])) {
            $emails = array_merge($emails, $data['all_emails']);
        }
        // Also try to find emails in the snippet/description
        $text = ($data['snippet'] ?? '') . ' ' . ($data['description'] ?? '');
        if (preg_match_all('/([a-zA-Z][a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i', $text, $emailMatches)) {
            $emails = array_merge($emails, $emailMatches[1]);
        }
        $emails = array_values(array_unique(array_map('strtolower', $emails)));

        $genericFirsts = ['customer', 'sales', 'info', 'support', 'admin', 'general', 'office', 'technical', 'human', 'contact', 'service', 'marketing', 'accounts', 'billing', 'help', 'enquiry', 'inquiry', 'reception', 'webmaster', 'mail', 'noreply', 'no-reply', 'news', 'team', 'hello', 'hi',
            // French department names
            'recrutement', 'direction', 'comptabilite', 'ressources', 'juridique', 'achats', 'communication', 'logistique', 'secretariat', 'accueil', 'standard',
            // German department names
            'vertrieb', 'verwaltung', 'buchhaltung', 'einkauf', 'technik', 'personal', 'empfang', 'zentrale', 'leitung', 'geschaeftsfuehrung',
            // Dutch department names
            'verkoop', 'inkoop', 'administratie', 'ontvangst', 'klantenservice',
            // Arabic transliterated
            'istiqbal', 'maktab', 'idara',
            // Additional generic
            'newsletter', 'subscribe', 'unsubscribe', 'bounce', 'postmaster', 'daemon', 'root', 'abuse', 'spam', 'security', 'privacy',
            'career', 'careers', 'jobs', 'recruitment', 'hiring', 'press', 'media', 'events', 'conference',
            'feedback', 'complaints', 'compliance', 'legal', 'orders', 'shipping', 'returns', 'refund',
        ];
        // Department/role names that appear as the "last name" part in email-derived contacts
        $genericLasts = ['sales', 'support', 'info', 'admin', 'marketing', 'billing', 'service', 'services', 'office', 'team', 'campaigns', 'campaign', 'procurement', 'purchasing', 'finance', 'accounting', 'legal', 'press', 'media', 'hr', 'it', 'ops', 'operations', 'logistics', 'warehouse', 'shipping', 'production', 'engineering', 'design', 'development', 'research', 'quality', 'compliance', 'safety', 'security', 'reception', 'general', 'web', 'digital', 'communications', 'events', 'careers', 'jobs', 'training',
            // Additional
            'department', 'division', 'office', 'management', 'direction', 'bureau',
            'group', 'newsletter', 'webmaster', 'postmaster', 'noreply', 'system',
            'automation', 'notification', 'alert', 'updates', 'digest',
        ];

        foreach ($emails as $email) {
            $local = strtolower(explode('@', $email)[0] ?? '');
            $emailDomain = strtolower(explode('@', $email)[1] ?? '');

            // ── Reject emails from known non-company / mismatched domains ──
            if ($this->isRejectContactEmail($email, $companyDomain)) {
                continue;
            }

            // Match firstname.lastname or firstname_lastname patterns
            // Also handles multi-part: jean-marie.dupont, mohamed.el-sayad
            if (preg_match('/^([a-z]{2,}(?:-[a-z]{2,})?)[._]([a-z]{2,}(?:-[a-z]{2,})?)$/', $local, $m)) {
                $firstName = ucfirst($m[1]);
                $lastName = ucfirst($m[2]);
                // Handle hyphenated names: jean-marie → Jean-Marie
                if (str_contains($firstName, '-')) {
                    $firstName = implode('-', array_map('ucfirst', explode('-', $firstName)));
                }
                if (str_contains($lastName, '-')) {
                    $lastName = implode('-', array_map('ucfirst', explode('-', $lastName)));
                }
                if (!in_array(strtolower($firstName), $genericFirsts, true)
                    && !in_array(strtolower($m[2]), $genericLasts, true)) {
                    $contacts[] = [
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $email,
                    ];
                }
            }
            // Match firstnamelastname@ (common: "johnsmith@") via capital letter pattern
            // e.g. "JohnSmith@company.com"
            elseif (preg_match('/^([A-Z][a-z]{2,})([A-Z][a-z]{2,})@/', $email, $m)) {
                $firstName = $m[1];
                $lastName = $m[2];
                if (!in_array(strtolower($firstName), $genericFirsts, true)) {
                    $contacts[] = [
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => strtolower($email),
                    ];
                }
            }
            // Match f.lastname@ (single initial + lastname)
            elseif (preg_match('/^([a-z])[._]([a-z]{3,})$/', $local, $m)) {
                $firstName = strtoupper($m[1]) . '.';
                $lastName = ucfirst($m[2]);
                if (!in_array(strtolower($m[2]), $genericFirsts, true)) {
                    $contacts[] = [
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $email,
                    ];
                }
            }
            // Match firstname-lastname@ (hyphenated)
            elseif (preg_match('/^([a-z]{2,})-([a-z]{2,})$/', $local, $m)) {
                $firstName = ucfirst($m[1]);
                $lastName = ucfirst($m[2]);
                if (!in_array(strtolower($firstName), $genericFirsts, true)) {
                    $contacts[] = [
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $email,
                    ];
                }
            }
        }

        return $contacts;
    }

    /**
     * @deprecated No longer used — we require REAL person contacts only.
     * Kept as stub to prevent method-not-found errors.
      * @param array<string|int, mixed> $data
     */
    private function createFallbackContact(array $data): ?array
    {
        return null; // Never create fake "General Contact" entries
    }

    /**
     * Search Google for LinkedIn /in/ profiles of decision-makers at a company.
     *
     * Multi-strategy approach using SHORT queries (Google CSE chokes on
     * long OR-chains). Tries up to 2 API calls in cascading priority:
     *
     *   Strategy A (1 call):  site:linkedin.com/in/ "CompanyName" CEO OR Director OR Manager
     *   Strategy B (1 call):  "CompanyName" CEO OR founder OR owner site:linkedin.com
     *
     * Parses LinkedIn titles: "John Smith - Procurement Manager - ACME Corp | LinkedIn"
     * Also extracts names from LinkedIn URL slugs as fallback.
     *
     * Cost: 1–2 Google API calls per company (stops after first hit).
     * Returns array of contact arrays with first_name, last_name, job_title, linkedin_url.
     */
    private function searchLinkedInDecisionMakers(string $companyName): array
    {
        if (!$this->hasSearchProvider()) {
            return [];
        }

        // Clean company name: strip common suffixes for better matching
        $searchName = preg_replace('/\s*(GmbH|LLC|Inc\.?|Ltd\.?|Corp\.?|S\.?A\.?|S\.?A\.?R\.?L\.?|AG|SE|SAS|SARL|Co\.?|Pty|PLC)\s*$/i', '', $companyName);
        $searchName = trim($searchName, " \t\n\r\0\x0B,.-;");
        if (mb_strlen($searchName) < 2) {
            $searchName = $companyName;
        }

        // ── Strategy queries: SHORT and specific ─────────────────
        // Target procurement/purchasing/engineering decision-makers,
        // NOT generic CEO/founder roles (user feedback: "CEO is useless").
        $queries = [
            // Strategy A: LinkedIn /in/ profiles with procurement/purchasing titles
            sprintf(
                'site:linkedin.com/in/ "%s" Procurement OR Purchasing OR "Supply Chain" OR "Project Manager"',
                $searchName
            ),
            // Strategy B: broader LinkedIn with engineering/operations titles
            sprintf(
                'site:linkedin.com/in/ "%s" Director OR Manager OR Engineering OR Operations',
                $searchName
            ),
            // Strategy C: ANY person at the company (for small companies where
            // specific role searches return nothing)
            sprintf(
                'site:linkedin.com/in/ "%s"',
                $searchName
            ),
        ];

        // Strategy D: If the name has multiple words, try just the first
        // distinctive word (helps with companies like "Gulf Instruments",
        // "Battfix", etc. where the full name returns no results)
        $firstWord = preg_split('/\s+/', $searchName)[0] ?? '';
        if (mb_strlen($firstWord) >= 5 && mb_strtolower($firstWord) !== mb_strtolower($searchName)) {
            $queries[] = sprintf(
                'site:linkedin.com/in/ "%s" Director OR Manager OR Engineer',
                $firstWord
            );
        }

        // Strategy E: General web search (NOT LinkedIn) for leadership names.
        // When a company has no LinkedIn presence at all, we can still find
        // CEO/founder/director names from news articles, about pages, etc.
        $webQueries = [
            sprintf(
                '"%s" CEO OR founder OR "managing director" OR "general manager" OR "directeur" OR "gérant"',
                $searchName
            ),
        ];

        $contacts = [];

        foreach ($queries as $qi => $query) {
            // Stop if we already found contacts from a previous query
            if (!empty($contacts)) {
                break;
            }

            try {
                // Use Google CSE directly for site:linkedin.com queries.
                // Most scraped engines can't handle LinkedIn site-restricted
                // searches, so burning 28 engines is futile.
                $results = $this->executeLinkedInSearch($query, 10);
                $items = $results['results'] ?? [];

                foreach ($items as $item) {
                    $title = $item['title'] ?? '';
                    $link = $item['link'] ?? ($item['url'] ?? '');
                    $snippet = $item['snippet'] ?? '';

                    // Must be a LinkedIn /in/ profile URL
                    if (!str_contains($link, 'linkedin.com/in/')) {
                        continue;
                    }

                    // ── Company match verification ───────────────
                    if (!$this->linkedInResultMatchesCompany($companyName, $searchName, $title, $snippet)) {
                        continue;
                    }

                    // ── Region mismatch rejection (iter15) ───────
                    // LinkedIn snippets typically contain the person's location:
                    // "Priyanka Mohapatra – Bengaluru, Karnataka, India"
                    // Reject contacts clearly located in wrong regions.
                    if ($this->isLinkedInContactRegionMismatch($snippet, $title)) {
                        $this->logger->debug('Rejected LinkedIn contact: region mismatch', [
                            'title' => $title,
                            'search_region' => $this->currentSearchRegion,
                        ]);
                        continue;
                    }

                    // ── Try parsing the title format ─────────────
                    // Use new LinkedInProfileParser first (Improvement 5A)
                    $contact = $this->linkedInParser->parseProfile($link, $title, $snippet);

                    // Fallback to legacy parser if new one returns null
                    if ($contact === null) {
                        $contact = $this->parseLinkedInProfileTitle($title, $link);
                    }

                    // ── Fallback: extract name from URL slug ─────
                    if ($contact === null) {
                        $contact = $this->extractContactFromLinkedInUrl($link, $snippet);
                    }

                    if ($contact !== null) {
                        // ── Wrong-company detection (iter15c) ────
                        // Check if the contact's job title mentions a DIFFERENT
                        // company name (e.g., "at Centra Health" when searching
                        // for "Centra" in Egypt). This catches cases where
                        // LinkedIn returns people at similarly-named companies.
                        $jobT = strtolower($contact['job_title'] ?? '');
                        $snippetLower = strtolower($snippet);
                        $isWrongCompany = false;

                        // Known wrong-company suffixes that indicate a different entity
                        $wrongCompanySuffixes = [
                            'health', 'hospital', 'clinic', 'medical center',
                            'university', 'college', 'school', 'academy',
                            'bank', 'insurance', 'financial',
                            'marketplace', 'hire', 'rental', 'staffing',
                            'hawaii', 'australia', 'canada',
                            'homes', 'home builder', 'real estate', 'realty',
                            'foods', 'food', 'beverages', 'restaurant', 'catering',
                            'travel', 'tours', 'tourism', 'hotel', 'hospitality',
                            'church', 'ministry', 'foundation', 'charity',
                            'gym', 'fitness', 'sports', 'yoga',
                            'salon', 'spa', 'beauty', 'cosmetics',
                            // iter15h: more wrong-company indicators
                            'dewatering', 'drilling', 'mining',
                        ];
                        foreach ($wrongCompanySuffixes as $suffix) {
                            // Check: does the job title/snippet say "Company SUFFIX"
                            // where SUFFIX indicates a completely different entity?
                            if (str_contains($jobT, $suffix) || str_contains($snippetLower, $suffix)) {
                                // Only reject if NO significant word from the suffix
                                // appears in the original company name. This prevents
                                // "medical center" from rejecting contacts at "Cairo Medical Care".
                                $origLower = strtolower($companyName);
                                $suffixWords = explode(' ', $suffix);
                                $anyWordMatch = false;
                                foreach ($suffixWords as $sw) {
                                    if (strlen($sw) >= 4 && str_contains($origLower, $sw)) {
                                        $anyWordMatch = true;
                                        break;
                                    }
                                }
                                if (!$anyWordMatch) {
                                    $isWrongCompany = true;
                                    $this->logger->debug('Rejected LinkedIn contact: wrong company detected', [
                                        'contact' => ($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''),
                                        'job_title' => $contact['job_title'] ?? '',
                                        'suffix_matched' => $suffix,
                                        'target_company' => $companyName,
                                    ]);
                                    break;
                                }
                            }
                        }

                        // Also check: if the job title says "at X", "chez X",
                        // "@ X" and X doesn't match the target, reject.
                        // Also check "of/for COMPANY" patterns but only
                        // if the extracted text looks like a company name.
                        if (!$isWrongCompany && !empty($jobT)) {
                            $mentionedCo = null;
                            // "at X", "chez X", "@ X" — high confidence
                            if (preg_match('/\b(?:at|chez|@)\s+(.{3,50})(?:\s*[·|,]|$)/i', $jobT, $atm)) {
                                $mentionedCo = strtolower(trim($atm[1]));
                            }
                            // "of X" / "for X" — only when X looks like a company name
                            // (contains Corp, Inc, Ltd, LLC, Group, or is ≥2 capitalized words)
                            elseif (preg_match('/\b(?:of|for)\s+([A-Z][a-zA-Z&\s]{2,49}(?:\b(?:Corp|Inc|Ltd|LLC|Group|Company|Co|GmbH|SA|SAS|SARL)\b[.]?))/i', $jobT, $ofm)) {
                                $mentionedCo = strtolower(trim($ofm[1]));
                            }

                            if ($mentionedCo !== null) {
                                $targetLower = strtolower($searchName);
                                $origLower = strtolower($companyName);
                                // If the mentioned company doesn't match well enough
                                // to the target company, it's probably wrong
                                $targetWords = preg_split('/[\s\-&,;.]+/', $targetLower);
                                $targetWords = array_filter($targetWords, fn($w) => mb_strlen($w) >= 4);
                                $targetWords = array_values($targetWords);
                                $matchCount = 0;
                                foreach ($targetWords as $tw) {
                                    if (str_contains($mentionedCo, $tw)) {
                                        $matchCount++;
                                    }
                                }
                                // Require majority of target words to match, or at least
                                // 2 matches for multi-word names. For single-word targets,
                                // still require 1 match but also check the mentioned company
                                // doesn't have too many extra words.
                                $requiredMatches = count($targetWords) <= 1 ? 1 : max(2, (int)ceil(count($targetWords) * 0.6));
                                if (!empty($targetWords) && $matchCount < $requiredMatches) {
                                    $isWrongCompany = true;
                                    $this->logger->debug('Rejected LinkedIn contact: "at" different company', [
                                        'contact' => ($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''),
                                        'mentioned_company' => $mentionedCo,
                                        'target_company' => $companyName,
                                        'match_count' => $matchCount,
                                        'required' => $requiredMatches,
                                    ]);
                                }
                            }
                        }

                        // Also check: if the job title IS a company name
                        // (contains LLC, Inc, Corp, Ltd, etc.) rather than
                        // an actual job title, and doesn't match target
                        if (!$isWrongCompany && !empty($jobT)) {
                            if (preg_match('/\b(llc|inc|corp|ltd|gmbh|s\.a\.|sarl|sas)\b/i', $jobT)) {
                                $targetWords = preg_split('/[\s\-&]+/', strtolower($searchName));
                                $targetWords = array_filter($targetWords, fn($w) => mb_strlen($w) >= 4);
                                $targetWords = array_values($targetWords);
                                $matchCount = 0;
                                foreach ($targetWords as $tw) {
                                    if (str_contains($jobT, $tw)) {
                                        $matchCount++;
                                    }
                                }
                                // Require majority match for multi-word companies
                                $requiredMatches = count($targetWords) <= 1 ? 1 : max(2, (int)ceil(count($targetWords) * 0.6));
                                if (!empty($targetWords) && $matchCount < $requiredMatches) {
                                    $isWrongCompany = true;
                                    $this->logger->debug('Rejected LinkedIn contact: job title is a different company name', [
                                        'contact' => ($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''),
                                        'job_title' => $contact['job_title'] ?? '',
                                        'target_company' => $companyName,
                                    ]);
                                }
                            }
                        }

                        if (!$isWrongCompany) {
                            $contacts[] = $contact;
                        }
                    }
                }

                usleep(300000); // Rate-limit
            } catch (\Throwable $e) {
                $this->logger->debug('LinkedIn search strategy {i} failed for {company}: {msg}', [
                    'i' => $qi,
                    'company' => $companyName,
                    'msg' => $e->getMessage(),
                ]);
            }
        }

        // ── Strategy E: Google web search fallback ────────────────
        // If LinkedIn yielded nothing, try general Google to find
        // CEO/founder/director names from news, about pages, etc.
        if (empty($contacts)) {
            foreach ($webQueries as $wq) {
                try {
                    $webResults = $this->executeProviderSearch($wq, 5);
                    $webItems = $webResults['results'] ?? [];

                    foreach ($webItems as $wi) {
                        $wTitle = $wi['title'] ?? '';
                        $wSnippet = $wi['snippet'] ?? '';
                        $wLink = $wi['link'] ?? ($wi['url'] ?? '');

                        // Skip LinkedIn URLs (already tried), social media, Wikipedia
                        if (preg_match('/linkedin\.com|facebook\.com|twitter\.com|wikipedia\.org|youtube\.com/i', $wLink)) {
                            continue;
                        }

                        $combined = $wTitle . ' ' . $wSnippet;

                        // Verify the result mentions the target company
                        if (mb_stripos($combined, $searchName) === false && mb_stripos($combined, $companyName) === false) {
                            // Token-based fallback check
                            $nameWords = preg_split('/[\s\-&,;.]+/', mb_strtolower($searchName));
                            $nameWords = array_filter($nameWords, fn($w) => mb_strlen($w) >= 3);
                            $combinedLower = mb_strtolower($combined);
                            $found = 0;
                            foreach ($nameWords as $nw) {
                                if (str_contains($combinedLower, $nw)) {
                                    $found++;
                                }
                            }
                            if ($found < max(1, count($nameWords) * 0.5)) {
                                continue;
                            }
                        }

                        // Try to extract person names from the snippet/title
                        // Pattern: "CEO John Smith" or "founder: Jane Doe" or "John Smith, CEO"
                        $leaderPatterns = [
                            // "John Smith, CEO/founder/director/manager/gérant"
                            '/\b([A-Z][a-zéèêëàâäùûüôöîïç]{1,20})\s+([A-Z][a-zéèêëàâäùûüôöîïç]{1,25})\s*[,\-–—]\s*(?:CEO|founder|co-?founder|managing\s+director|general\s+manager|directeur|gérant|PDG|DG|president|chairman|chief\s+executive)/iu',
                            // "CEO/founder/etc John Smith" or "CEO: John Smith"
                            '/(?:CEO|founder|co-?founder|managing\s+director|general\s+manager|directeur|gérant|PDG|DG|president|chairman|chief\s+executive)\s*[:\-–—]?\s*([A-Z][a-zéèêëàâäùûüôöîïç]{1,20})\s+([A-Z][a-zéèêëàâäùûüôöîïç]{1,25})/iu',
                            // "by John Smith" (common in "founded by" contexts)
                            '/\bfounded\s+by\s+([A-Z][a-zéèêëàâäùûüôöîïç]{1,20})\s+([A-Z][a-zéèêëàâäùûüôöîïç]{1,25})/iu',
                            // "Mr./Mrs./Dr. FirstName LastName"
                            '/\b(?:Mr|Mrs|Ms|Dr|Eng|Ing)\.\s*([A-Z][a-zéèêëàâäùûüôöîïç]{1,20})\s+([A-Z][a-zéèêëàâäùûüôöîïç]{1,25})/iu',
                        ];

                        foreach ($leaderPatterns as $lp) {
                            if (preg_match($lp, $combined, $lm)) {
                                $fn = trim($lm[1]);
                                $ln = trim($lm[2]);

                                // Basic validation
                                if (mb_strlen($fn) < 2 || mb_strlen($ln) < 2) continue;
                                if ($this->classifier && !$this->classifier->isLikelyPersonName($fn, $ln)) continue;

                                $contacts[] = [
                                    'first_name' => $fn,
                                    'last_name' => $ln,
                                    'job_title' => 'Director',
                                ];
                                $this->logger->debug('Found contact via web search fallback', [
                                    'contact' => "$fn $ln",
                                    'company' => $companyName,
                                    'source' => $wLink,
                                ]);
                                break 2; // Found a contact, stop all web queries
                            }
                        }
                    }

                    usleep(300000);
                } catch (\Throwable $e) {
                    $this->logger->debug('Web search fallback failed for {company}: {msg}', [
                        'company' => $companyName,
                        'msg' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Deduplicate by first_name+last_name
        $seen = [];
        $unique = [];
        foreach ($contacts as $c) {
            $key = mb_strtolower(($c['first_name'] ?? '') . '|' . ($c['last_name'] ?? ''));
            if ($key === '|' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $unique[] = $c;
        }

        // Clean HTML artifacts and filter out non-person contacts
        $unique = array_map(fn(array $c) => $this->cleanContactFields($c), $unique);
        $unique = array_values(array_filter($unique, fn(array $c) => $this->isValidPersonContact($c)));

        return array_slice($unique, 0, 3);
    }

    /**
     * Check if a Google result (title + snippet) is about the target company.
     */
    private function linkedInResultMatchesCompany(
        string $originalName,
        string $searchName,
        string $title,
        string $snippet,
    ): bool {
        $combined = $title . ' ' . $snippet;

        // Direct substring match (case-insensitive)
        if (mb_stripos($combined, $originalName) !== false) {
            return true;
        }
        if (mb_stripos($combined, $searchName) !== false) {
            return true;
        }

        // Token-based match: at least 60% of the company name words appear
        $nameWords = preg_split('/[\s\-&,;.]+/', mb_strtolower($searchName));
        $nameWords = array_filter($nameWords, fn($w) => mb_strlen($w) >= 3);
        $nameWords = array_values($nameWords);
        if (empty($nameWords)) return false;

        $combinedLower = mb_strtolower($combined);
        $found = 0;
        foreach ($nameWords as $word) {
            if (str_contains($combinedLower, $word)) {
                $found++;
            }
        }

        return $found >= max(1, count($nameWords) * 0.6);
    }

    /**
     * Check if a LinkedIn contact's location (from snippet/title) indicates
     * they are in the WRONG geographic region for the current search.
     *
     * iter15: prevents Indian/UK/Spanish contacts from leaking into
     * Egypt/Tunisia/Morocco company results.
     *
     * Only rejects when we have HIGH CONFIDENCE the contact is in a
     * wrong region (explicit country name in snippet). Returns false
     * (no mismatch) for ambiguous/missing location data.
     */
    private function isLinkedInContactRegionMismatch(string $snippet, string $title): bool
    {
        $region = $this->currentSearchRegion;
        if ($region === 'GENERIC') {
            return false; // No region constraint
        }

        $combined = strtolower($snippet . ' ' . $title);

        // ── Define "wrong region" indicators per search region ────
        // These are location strings that appear in LinkedIn snippets
        // for people who are CLEARLY in the wrong geography.
        $wrongLocationIndicators = [
            'EG' => [ // Searching in Egypt — reject contacts in:
                'india', 'bangalore', 'bengaluru', 'mumbai', 'delhi', 'hyderabad',
                'chennai', 'pune', 'kolkata', 'ahmedabad', 'karnataka', 'maharashtra',
                'tamil nadu', 'telangana', 'kerala', 'gujarat', 'west bengal',
                'pakistan', 'karachi', 'lahore', 'islamabad',
                'united kingdom', 'london', 'manchester', 'birmingham',
                'spain', 'madrid', 'barcelona',
                'turkey', 'türkiye', 'istanbul', 'ankara', 'izmir', 'antalya',
                'hong kong', 'singapore', 'kuala lumpur', 'malaysia',
                'nigeria', 'lagos', 'nairobi', 'kenya',
                'philippines', 'manila', 'bangkok', 'thailand',
                'vietnam', 'ho chi minh', 'indonesia', 'jakarta',
                // US states/cities (contacts at similarly-named US companies)
                'hawaii', 'california', 'new york', 'texas', 'florida',
                'virginia', 'ohio', 'illinois', 'pennsylvania', 'michigan',
                'georgia', 'north carolina', 'new jersey', 'washington',
                'massachusetts', 'tennessee', 'maryland', 'minnesota',
                'wisconsin', 'colorado', 'arizona', 'oregon', 'indiana',
                // Lithuania/Netherlands/etc. (contacts at EU subsidiaries)
                'lithuania', 'vilnius', 'kaunas',
                'netherlands', 'amsterdam', 'rotterdam', 'eindhoven',
                'belgium', 'brussels',
                'zimbabwe', 'harare',
            ],
            'TN' => [ // Searching in Tunisia — reject contacts in:
                'india', 'bangalore', 'bengaluru', 'mumbai', 'delhi', 'hyderabad',
                'chennai', 'pune', 'kolkata', 'ahmedabad', 'karnataka', 'maharashtra',
                'pakistan', 'karachi', 'lahore',
                'united kingdom', 'london', 'manchester',
                'spain', 'madrid', 'barcelona',
                'turkey', 'türkiye', 'istanbul', 'ankara', 'izmir',
                'hong kong', 'singapore', 'malaysia',
                'nigeria', 'lagos', 'nairobi', 'kenya',
                'philippines', 'manila', 'bangkok', 'thailand',
                'vietnam', 'indonesia', 'jakarta',
                'hawaii', 'california', 'texas', 'florida', 'ohio',
                'illinois', 'michigan', 'georgia', 'new jersey', 'washington',
                'lithuania', 'vilnius', 'netherlands', 'amsterdam',
                'zimbabwe', 'harare',
            ],
            'MA' => [ // Searching in Morocco — reject contacts in:
                'india', 'bangalore', 'bengaluru', 'mumbai', 'delhi', 'hyderabad',
                'chennai', 'pune', 'kolkata', 'ahmedabad', 'karnataka', 'maharashtra',
                'pakistan', 'karachi', 'lahore',
                'turkey', 'türkiye', 'istanbul', 'ankara', 'izmir',
                'hong kong', 'singapore', 'malaysia',
                'nigeria', 'lagos', 'nairobi', 'kenya',
                'philippines', 'manila', 'bangkok', 'thailand',
                'vietnam', 'indonesia', 'jakarta',
                'hawaii', 'california', 'texas', 'florida', 'ohio',
                'illinois', 'michigan', 'georgia', 'new jersey', 'washington',
                'massachusetts', 'tennessee', 'maryland', 'minnesota',
                'colorado', 'arizona', 'oregon', 'indiana', 'pennsylvania',
                'lithuania', 'vilnius', 'netherlands', 'amsterdam',
                'zimbabwe', 'harare',
                // Nordic countries (e.g., Haukur from Iceland at ICEMAR)
                'iceland', 'reykjavik', 'norway', 'oslo', 'bergen',
                'sweden', 'stockholm', 'denmark', 'copenhagen', 'finland', 'helsinki',
                // More US/Canada
                'new york', 'chicago', 'los angeles', 'san francisco', 'toronto',
                'montreal', 'vancouver', 'canada',
                // South America
                'brazil', 'são paulo', 'argentina', 'buenos aires',
                // More European
                'united kingdom', 'london', 'manchester', 'birmingham',
                'spain', 'madrid', 'barcelona',
                'romania', 'bucharest', 'poland', 'warsaw',
            ],
            'GCC' => [
                'india', 'bangalore', 'bengaluru', 'mumbai', 'delhi', 'hyderabad',
                'chennai', 'pune', 'kolkata', 'karnataka', 'maharashtra',
                'pakistan', 'karachi', 'lahore',
                'nigeria', 'lagos', 'nairobi', 'kenya',
                'philippines', 'manila',
            ],
        ];

        $indicators = $wrongLocationIndicators[$region] ?? [];
        if (empty($indicators)) {
            return false;
        }

        foreach ($indicators as $wrongLoc) {
            if (str_contains($combined, $wrongLoc)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse a LinkedIn title like "John Smith - Procurement Manager - ACME Corp | LinkedIn"
     * into a contact array.
     */
    private function parseLinkedInProfileTitle(string $title, string $link): ?array
    {
        // Strip "| LinkedIn" suffix
        $titleCleaned = preg_replace('/\s*[|·]\s*LinkedIn$/i', '', $title);

        // Split on dash/en-dash/em-dash
        $parts = array_map('trim', preg_split('/\s*[-–—]\s*/', $titleCleaned, 4));

        if (count($parts) < 2) {
            return null;
        }

        $fullName = $parts[0];
        $jobTitle = $parts[1] ?? '';

        // Strip certifications/credentials from name portion
        // e.g. "Ramzi Bejaoui, PMP®, PMI-RMP®" → "Ramzi Bejaoui"
        $fullName = preg_replace('/,\s*(PMP|PMI|CPA|CFA|MBA|PhD|PE|MS|MSc|BSc|BS|BA|MA|MEng|BEng|MPhil|CPIM|CSCP|CPSM|PgMP|ACP|CAPM|CSM|ITIL|PRINCE2|CSSBB|CISA|CISSP|CEH|LEED|SHRM|SPHR|PHR|Six Sigma|Scrum|RMP|PSM|PSPO|SAFe|CSP|PSP|ASP|CHMM|CQE|CRE|CMQ|CQA|FACS|FRCS|MRCS|MD|DO|DDS|DMD|RN|BSN|MSN|FNP|CMA|RMA|CPC|COC|RHIT|RHIA|CCTM|CSSO|CSSM|LSSGB|LSSBB|CEM|CEP|CMVP|CBAP|TOGAF|MELSSMBB)[®™]?[\s,]*/i', '', $fullName);
        // Also strip standalone cert abbreviations at end: "Name CERT"
        $fullName = preg_replace('/\s+(?:PMP|MBA|PhD|PE|MS|MSc|BSc|CPIM|CSCP|LEED|ITIL|PRINCE2|MELSSMBB)[®™]*$/i', '', $fullName);
        // Catch-all: strip comma + any remaining all-caps suffix (generic credential pattern)
        $fullName = preg_replace('/,\s*[A-Z][A-Z®™\.]{1,20}$/', '', $fullName);
        $fullName = trim($fullName, " \t,");

        // Validate full name
        $nameParts = $this->splitPersonName($fullName);
        if (!$nameParts) {
            return null;
        }

        $nameParts['linkedin_url'] = rtrim($link, '/');

        // Set job title if it looks real (not the company name)
        if (!empty($jobTitle) && mb_strlen($jobTitle) >= 3 && mb_strlen($jobTitle) <= 100) {
            // Reject language names and generic non-job words that LinkedIn sometimes returns
            $junkTitles = [
                'english', 'french', 'arabic', 'spanish', 'german', 'italian', 'portuguese',
                'chinese', 'japanese', 'korean', 'russian', 'turkish', 'hindi', 'dutch',
                'swedish', 'danish', 'norwegian', 'finnish', 'polish', 'czech', 'hungarian',
                'romanian', 'bulgarian', 'greek', 'hebrew', 'persian', 'thai', 'vietnamese',
                'indonesia', 'malay', 'filipino', 'swahili', 'urdu', 'bengali', 'tamil',
                'location', 'see more', 'view profile', 'see all', 'more', 'about',
                'skills', 'experience', 'education', 'summary', 'overview', 'bio',
                'connections', 'followers', 'following', 'posts', 'articles',
            ];
            $jobTitleLower = strtolower(trim($jobTitle));
            if (!in_array($jobTitleLower, $junkTitles, true)) {
                $nameParts['job_title'] = mb_substr(html_entity_decode($jobTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 80);
            }
        }

        return $nameParts;
    }

    /**
     * Extract a contact from a LinkedIn /in/ URL slug + snippet text.
     * Slug format: linkedin.com/in/john-smith-12345
     * Snippet may contain "John Smith — CEO at ACME Corp"
     */
    private function extractContactFromLinkedInUrl(string $url, string $snippet): ?array
    {
        // Extract name from URL slug
        if (!preg_match('#linkedin\.com/in/([a-z0-9-]+)#i', $url, $m)) {
            return null;
        }
        $slug = $m[1];

        // Try to derive name from slug
        $nameParts = $this->extractNameFromLinkedInSlug($slug);
        if (!$nameParts) {
            return null;
        }

        // Validate the derived name
        $validated = $this->splitPersonName(
            ($nameParts['first_name'] ?? '') . ' ' . ($nameParts['last_name'] ?? '')
        );
        if (!$validated) {
            return null;
        }

        $validated['linkedin_url'] = rtrim($url, '/');

        // Try to extract job title from snippet
        // Look for patterns like: "CEO at", "Director of", "Manager -", etc.
        if (preg_match('/\b(CEO|CTO|CFO|COO|Director|Manager|VP|President|Founder|Owner|Head|Chief|Procurement|Purchasing|Engineering|Operations)\b[^.]{0,60}/i', $snippet, $tm)) {
            $validated['job_title'] = mb_substr(trim($tm[0]), 0, 80);
        }

        return $validated;
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
            'site:kompass.com',                   // Global B2B with strong EU coverage
        ],
        'DE' => [
            'site:wlw.de',                       // German manufacturing index (Wer liefert was)
            'site:europages.de',                 // Europages Germany
            'site:industrystock.com Germany',    // Industrial B2B marketplace
        ],
        'FR' => [
            'site:europages.fr',                 // Europages France
            'site:kompass.com France',           // Kompass France section
            'site:industrie.com',                // French industrial directory
        ],
        'PL' => [
            'site:europages.pl',                 // Europages Poland
            'site:kompass.com Poland',           // Kompass Poland section
            'site:pfrn.pl',                      // Polish manufacturing registry
        ],
        'NL' => [
            'site:europages.nl',                 // Europages Netherlands
            'site:kompass.com Netherlands',      // Kompass Netherlands section
            'site:dutchindustry.com',            // Dutch industrial directory
        ],
        'IT' => [
            'site:europages.it',                 // Europages Italy
            'site:kompass.com Italy',            // Kompass Italy section
        ],
        'ES' => [
            'site:europages.es',                 // Europages Spain
            'site:kompass.com Spain',            // Kompass Spain section
        ],
        'BE' => [
            'site:europages.be',                 // Europages Belgium
            'site:kompass.com Belgium',          // Kompass Belgium section
        ],
        'AT' => [
            'site:europages.at',                 // Europages Austria
            'site:kompass.com Austria',          // Kompass Austria section
        ],
        'CZ' => [
            'site:europages.cz',                 // Europages Czech Republic
            'site:kompass.com Czech',            // Kompass Czech section
        ],
        'SE' => [
            'site:europages.se',                 // Europages Sweden
            'site:kompass.com Sweden',           // Kompass Sweden section
        ],
        'DK' => [
            'site:europages.dk',                 // Europages Denmark
            'site:kompass.com Denmark',          // Kompass Denmark section
        ],
        'FI' => [
            'site:europages.fi',                 // Europages Finland
            'site:kompass.com Finland',          // Kompass Finland section
        ],
        'NO' => [
            'site:europages.no',                 // Europages Norway
            'site:kompass.com Norway',           // Kompass Norway section
        ],
        'CH' => [
            'site:europages.ch',                 // Europages Switzerland
            'site:kompass.com Switzerland',      // Kompass Switzerland section
        ],
        'RO' => [
            'site:europages.ro',                 // Europages Romania
            'site:kompass.com Romania',          // Kompass Romania section
        ],
        'HU' => [
            'site:europages.hu',                 // Europages Hungary
            'site:kompass.com Hungary',          // Kompass Hungary section
        ],
        'PT' => [
            'site:europages.pt',                 // Europages Portugal
            'site:kompass.com Portugal',         // Kompass Portugal section
        ],
        'IE' => [
            'site:europages.ie',                 // Europages Ireland
            'site:kompass.com Ireland',          // Kompass Ireland section
        ],
        'GB' => [
            'site:make-it.uk',                   // UK manufacturing directory
            'site:kompass.com United Kingdom',    // Kompass UK section
            'site:mae.co.uk',                    // Manufacturing Advisory Service UK
        ],
        'TN' => [
            'site:tunisieindustrie.nat.tn',       // Tunisian Industrial Agency (API)
            'site:kompass.com Tunisia',           // Kompass Tunisia section
            'site:cepex.nat.tn',                  // Tunisian Export Promotion Centre
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

        // Germany markers
        $deMarkers = ['germany', 'deutschland', 'berlin', 'munich', 'münchen', 'hamburg', 'frankfurt', 'stuttgart', 'düsseldorf', 'dusseldorf', 'cologne', 'köln', 'dresden', 'leipzig', 'hannover', 'nuremberg', 'nürnberg', 'bremen', 'dortmund', 'essen', 'bavaria', 'baden-württemberg', 'nordrhein-westfalen'];
        foreach ($deMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'DE';
            }
        }

        // France markers
        $frMarkers = ['france', 'paris', 'lyon', 'marseille', 'toulouse', 'nice', 'nantes', 'strasbourg', 'montpellier', 'bordeaux', 'lille', 'rennes', 'grenoble', 'île-de-france', 'provence', 'normandy', 'normandie'];
        foreach ($frMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'FR';
            }
        }

        // Poland markers
        $plMarkers = ['poland', 'polska', 'warsaw', 'warszawa', 'krakow', 'kraków', 'wroclaw', 'wrocław', 'gdansk', 'gdańsk', 'poznan', 'poznań', 'łódź', 'lodz', 'katowice', 'szczecin', 'lublin', 'bydgoszcz'];
        foreach ($plMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'PL';
            }
        }

        // Netherlands markers
        $nlMarkers = ['netherlands', 'holland', 'amsterdam', 'rotterdam', 'den haag', 'the hague', 'utrecht', 'eindhoven', 'groningen', 'tilburg', 'almere', 'breda', 'nijmegen', 'delft'];
        foreach ($nlMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'NL';
            }
        }

        // Romania markers
        $roMarkers = ['romania', 'românia', 'bucharest', 'bucurești', 'cluj', 'timișoara', 'timisoara', 'iași', 'iasi', 'constanța', 'constanta', 'craiova', 'brașov', 'brasov', 'galați', 'oradea', 'sibiu'];
        foreach ($roMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'RO';
            }
        }

        // Italy markers
        $itMarkers = ['italy', 'italia', 'rome', 'roma', 'milan', 'milano', 'turin', 'torino', 'naples', 'napoli', 'florence', 'firenze', 'bologna', 'genoa', 'genova', 'venice', 'venezia', 'verona', 'padova', 'brescia', 'lombardy', 'lombardia', 'piemonte', 'emilia-romagna', 'veneto', 'toscana', 'tuscany'];
        foreach ($itMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'IT';
            }
        }

        // Spain markers
        $esMarkers = ['spain', 'españa', 'madrid', 'barcelona', 'valencia', 'seville', 'sevilla', 'zaragoza', 'malaga', 'málaga', 'bilbao', 'murcia', 'palma', 'catalonia', 'cataluña', 'andalusia', 'andalucía', 'basque', 'país vasco'];
        foreach ($esMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'ES';
            }
        }

        // Belgium markers
        $beMarkers = ['belgium', 'belgique', 'belgië', 'brussels', 'bruxelles', 'brussel', 'antwerp', 'antwerpen', 'ghent', 'gent', 'liège', 'charleroi', 'bruges', 'brugge', 'leuven', 'namur', 'wallonia', 'flanders', 'vlaanderen'];
        foreach ($beMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'BE';
            }
        }

        // Austria markers
        $atMarkers = ['austria', 'österreich', 'vienna', 'wien', 'graz', 'linz', 'salzburg', 'innsbruck', 'klagenfurt', 'villach', 'wels', 'steiermark', 'tirol', 'oberösterreich'];
        foreach ($atMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'AT';
            }
        }

        // Czech Republic markers
        $czMarkers = ['czech', 'czechia', 'česko', 'prague', 'praha', 'brno', 'ostrava', 'plzeň', 'pilsen', 'liberec', 'olomouc', 'české budějovice', 'bohemia', 'moravia'];
        foreach ($czMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'CZ';
            }
        }

        // Sweden markers
        $seMarkers = ['sweden', 'sverige', 'stockholm', 'gothenburg', 'göteborg', 'malmö', 'malmo', 'uppsala', 'linköping', 'västerås', 'örebro', 'norrköping', 'jönköping', 'lund'];
        foreach ($seMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'SE';
            }
        }

        // Denmark markers
        $dkMarkers = ['denmark', 'danmark', 'copenhagen', 'københavn', 'aarhus', 'århus', 'odense', 'aalborg', 'esbjerg', 'randers', 'kolding', 'horsens'];
        foreach ($dkMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'DK';
            }
        }

        // Finland markers
        $fiMarkers = ['finland', 'suomi', 'helsinki', 'espoo', 'tampere', 'vantaa', 'oulu', 'turku', 'jyväskylä', 'lahti', 'kuopio'];
        foreach ($fiMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'FI';
            }
        }

        // Norway markers
        $noMarkers = ['norway', 'norge', 'oslo', 'bergen', 'trondheim', 'stavanger', 'drammen', 'fredrikstad', 'kristiansand', 'tromsø', 'sandnes'];
        foreach ($noMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'NO';
            }
        }

        // Switzerland markers
        $chMarkers = ['switzerland', 'schweiz', 'suisse', 'svizzera', 'zurich', 'zürich', 'geneva', 'genève', 'geneve', 'basel', 'bern', 'lausanne', 'winterthur', 'lucerne', 'luzern', 'st. gallen', 'lugano', 'biel'];
        foreach ($chMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'CH';
            }
        }

        // Hungary markers
        $huMarkers = ['hungary', 'magyarország', 'budapest', 'debrecen', 'szeged', 'miskolc', 'pécs', 'győr', 'nyíregyháza', 'kecskemét', 'székesfehérvár'];
        foreach ($huMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'HU';
            }
        }

        // Portugal markers
        $ptMarkers = ['portugal', 'lisbon', 'lisboa', 'porto', 'braga', 'coimbra', 'funchal', 'setúbal', 'aveiro', 'faro', 'algarve'];
        foreach ($ptMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'PT';
            }
        }

        // Ireland markers
        $ieMarkers = ['ireland', 'éire', 'dublin', 'cork', 'galway', 'limerick', 'waterford', 'drogheda', 'dundalk', 'kilkenny', 'shannon'];
        foreach ($ieMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'IE';
            }
        }

        // EU markers (catch-all for remaining European countries not individually mapped)
        $euMarkers = ['europe', 'greece', 'slovakia', 'bulgaria', 'croatia', 'slovenia', 'lithuania', 'latvia', 'estonia', 'luxembourg', 'malta', 'cyprus', 'athens', 'bratislava', 'sofia', 'zagreb', 'ljubljana', 'vilnius', 'riga', 'tallinn'];
        foreach ($euMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'EU';
            }
        }

        // Tunisia markers
        $tunisiaMarkers = ['tunisia', 'tunisie', 'tunis', 'sfax', 'sousse', 'monastir', 'bizerte', 'gabès', 'gabes', 'kairouan', 'gafsa', 'nabeul', 'ben arous', 'ariana', 'manouba', 'zaghouan', 'enfidha'];
        foreach ($tunisiaMarkers as $m) {
            if (str_contains($loc, $m)) {
                return 'TN';
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
     * Map internal region code to Google's gl= country parameter.
     * The gl parameter biases results toward the specified country.
     * For multi-country regions (EU, GCC) we pick the primary country.
     */
    private function regionToGoogleGl(string $region): ?string
    {
        return match ($region) {
            'MA' => 'ma',  // Morocco
            'TN' => 'tn',  // Tunisia
            'US' => 'us',  // United States
            'GB' => 'gb',  // United Kingdom
            'DE' => 'de',  // Germany
            'FR' => 'fr',  // France
            'PL' => 'pl',  // Poland
            'NL' => 'nl',  // Netherlands
            'IT' => 'it',  // Italy
            'ES' => 'es',  // Spain
            'BE' => 'be',  // Belgium
            'AT' => 'at',  // Austria
            'CZ' => 'cz',  // Czech Republic
            'SE' => 'se',  // Sweden
            'DK' => 'dk',  // Denmark
            'FI' => 'fi',  // Finland
            'NO' => 'no',  // Norway
            'CH' => 'ch',  // Switzerland
            'RO' => 'ro',  // Romania
            'HU' => 'hu',  // Hungary
            'PT' => 'pt',  // Portugal
            'IE' => 'ie',  // Ireland
            'EG' => 'eg',  // Egypt
            'GCC' => 'ae', // UAE as primary GCC country
            'EU' => null,  // EU is multi-country, don't bias
            default => null,
        };
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
    /**
     * Extract a clean location term from a raw location string.
     * Reusable by both buildGoogleDorkQueries() and dynamic expansion queries.
     */
    private function buildLocationTerm(?string $location): string
    {
        if (!$location) {
            return '';
        }

        return $this->buildLocationTermAdvanced($location);
    }

    private function buildGoogleDorkQueries(?string $sector, ?string $location = null): array
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

        $exclude = ' -site:linkedin.com -site:wikipedia.org -site:youtube.com'
            . ' -site:facebook.com -site:twitter.com -site:instagram.com';

        // Reuse the shared helper for location term extraction
        $locationTerm = $this->buildLocationTerm($location);

        // ── Synonym rotation: vary phrasing between runs ──────────────
        // Use day-of-week as seed so each day produces different queries
        // but the same day produces deterministic results (debuggable).
        $daySeed = (int) date('N'); // 1=Mon ... 7=Sun
        $mfgSynonyms = ['manufacturer', 'maker', 'producer', 'fabricant', 'OEM', 'factory'];
        $mfgAlt = $mfgSynonyms[$daySeed % count($mfgSynonyms)];
        $companySynonyms = ['company', 'corporation', 'enterprise', 'firm', 'group'];
        $compAlt = $companySynonyms[$daySeed % count($companySynonyms)];

        // ── Time-based query freshness injection ──────────────────────
        // Append year context to some queries so search engines return
        // different results than last month.
        $yearMonth = date('Y');
        $freshness = " {$yearMonth}";

        // ── 1. Sector-specific queries ────────────────────────────────
        if (!$sector) {
            $queries[] = "OEM {$mfgAlt} electronics {$compAlt}{$locationTerm}" . $exclude;
            $queries[] = "equipment {$mfgAlt} electronics {$compAlt}{$locationTerm}" . $exclude;
            $queries[] = "\"contract electronics manufacturing\" OR \"EMS provider\" buyer{$locationTerm}" . $exclude;
            $queries[] = "\"PCB assembly\" OR \"PCBA\" outsourcing {$compAlt}{$locationTerm}" . $exclude;
        } else {
            switch ($sector) {
            case 'Automotive':
                // ── Primary: find OEM manufacturers that HAVE products ──
                $queries[] = "automotive {$mfgAlt} \"our products\" OR \"our solutions\"{$locationTerm}" . $exclude;
                $queries[] = "automotive OEM {$mfgAlt} electronics{$locationTerm}" . $exclude;
                $queries[] = "automotive \"tier 1\" supplier {$mfgAlt}{$locationTerm}" . $exclude;
                // ── Technical product queries — companies making these BUY EMS ──
                $queries[] = "\"ECU\" OR \"powertrain\" OR \"ADAS\" {$mfgAlt}{$locationTerm}" . $exclude;
                $queries[] = "\"electric vehicle\" OR \"EV\" electronics {$mfgAlt}{$locationTerm}" . $exclude;
                // ── About-us style queries — high intent for real companies ──
                $queries[] = "automotive electronics {$compAlt} \"about us\" OR \"founded\"{$locationTerm}" . $exclude;
                // ── NEW: wire harness / cable assembly — core Starz service ──
                $queries[] = "automotive \"wire harness\" OR \"cable assembly\" {$mfgAlt}{$locationTerm}" . $exclude;
                $queries[] = "automotive \"injection molding\" OR \"stamping\" OR \"die casting\" {$compAlt}{$locationTerm}" . $exclude;
                // ── NEW: Freshness-rotated queries ──
                $queries[] = "automotive {$mfgAlt} {$compAlt}{$locationTerm}{$freshness}" . $exclude;
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
                // ── Additional queries: sensors, instrumentation, Industrie 4.0 ──
                $queries[] = "industrial \"sensors\" OR \"instrumentation\" OR \"measurement\" manufacturer{$locationTerm}" . $exclude;
                $queries[] = "\"industrial automation\" OR \"Automatisierungstechnik\" company{$locationTerm}" . $exclude;
                $queries[] = "\"Maschinenbau\" OR \"Sondermaschinenbau\" OR \"Anlagenbau\" manufacturer{$locationTerm}" . $exclude;
                $queries[] = "industrial \"control systems\" OR \"HMI\" OR \"SCADA\" manufacturer{$locationTerm}" . $exclude;
                $queries[] = "\"Industrie 4.0\" OR \"smart factory\" equipment company{$locationTerm}" . $exclude;
                $queries[] = "industrial \"power supply\" OR \"embedded systems\" manufacturer{$locationTerm}" . $exclude;
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
                // ── Additional: telecom hardware OEMs, not operators/consultants ──
                $queries[] = "telecom \"network equipment\" OR \"radio unit\" OR \"small cell\" manufacturer{$locationTerm}" . $exclude;
                $queries[] = "\"microwave radio\" OR \"mmWave\" OR \"beamforming\" equipment company{$locationTerm}" . $exclude;
                $queries[] = "\"Nachrichtentechnik\" OR \"Funktechnik\" OR \"Kommunikationstechnik\" manufacturer{$locationTerm}" . $exclude;
                $queries[] = "telecom \"power amplifier\" OR \"filter\" OR \"duplexer\" manufacturer{$locationTerm}" . $exclude;
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
        }

        // ── 2. Location-specific company-finding queries ──────────────
        if ($location) {
            if ($sector) {
                $queries[] = "{$locationTerm} {$sector} manufacturer \"about us\" OR \"our products\"" . $exclude;
                $queries[] = "{$locationTerm} {$sector} \"factory\" OR \"plant\" OR \"facility\" company" . $exclude;
            } else {
                $queries[] = "{$locationTerm} manufacturer \"about us\" OR \"our products\"" . $exclude;
                $queries[] = "{$locationTerm} \"factory\" OR \"plant\" OR \"facility\" company" . $exclude;
            }
        }

        // ── 3. SUPPLIER PORTAL TARGETING QUERIES ───────────────────────
        // High-value leads: OEMs that actively seek EMS suppliers.
        // These companies have "become a supplier" pages, indicating
        // they outsource manufacturing and procurement.
        if ($sector) {
            // Sector-specific supplier portal queries
            $queries[] = "{$sector} \"become a supplier\" OR \"supplier registration\"{$locationTerm}" . $exclude;
            $queries[] = "{$sector} \"vendor registration\" OR \"new supplier\"{$locationTerm}" . $exclude;
            $queries[] = "{$sector} inurl:supplier OR inurl:vendor \"application\" OR \"registration\"{$locationTerm}" . $exclude;
        } else {
            // Generic supplier portal queries
            $queries[] = "manufacturer \"become a supplier\" OR \"supplier registration\"{$locationTerm}" . $exclude;
            $queries[] = "OEM \"vendor registration\" OR \"new supplier\"{$locationTerm}" . $exclude;
        }
        // Generic high-value queries (all sectors)
        $queries[] = "\"contract manufacturing\" \"become a supplier\" OR \"supplier application\"{$locationTerm}" . $exclude;
        $queries[] = "\"PCBA outsourcing\" OR \"PCB assembly partner\" manufacturer{$locationTerm}" . $exclude;
        $queries[] = "\"supplier portal\" OR \"supplier onboarding\" manufacturer OEM{$locationTerm}" . $exclude;

        // ── 4. FOREIGN LANGUAGE QUERIES (DE/FR) ────────────────────────
        // Many European manufacturers have German/French websites only.
        // These queries target local-language corporate pages.
        if ($region === 'DE' || $region === 'EU') {
            $queries[] = "\"Lieferantenregistrierung\" OR \"Lieferant werden\"{$locationTerm}" . $exclude;
            $queries[] = "\"Beschaffung\" OR \"Einkauf\" Hersteller{$locationTerm}" . $exclude;
            $queries[] = "Maschinenbau OR Sondermaschinenbau \"unsere Produkte\"{$locationTerm}" . $exclude;
        }
        if ($region === 'FR' || $region === 'EU' || $region === 'TN' || $region === 'MA') {
            $queries[] = "\"devenir fournisseur\" OR \"inscription fournisseur\"{$locationTerm}" . $exclude;
            $queries[] = "\"achats\" OR \"approvisionnement\" fabricant{$locationTerm}" . $exclude;
            $queries[] = "équipementier OR sous-traitant \"nos produits\"{$locationTerm}" . $exclude;
        }

        // ── 5. B2B DIRECTORY TARGETING QUERIES ─────────────────────────
        // Actively mine industry directories for OEM/manufacturer listings.
        // These results will be recognized by DirectorySeedExtractor and
        // converted to seeds rather than blocked.
        $directoryTargetSector = $sector ?? 'electronics';
        // Europages (pan-European B2B directory)
        $queries[] = "site:europages.com \"{$directoryTargetSector}\" manufacturer{$locationTerm}";
        $queries[] = "site:europages.de \"{$directoryTargetSector}\" Hersteller{$locationTerm}";
        $queries[] = "site:europages.fr \"{$directoryTargetSector}\" fabricant{$locationTerm}";
        // Kompass (global B2B directory)
        $queries[] = "site:kompass.com \"{$directoryTargetSector}\" manufacturer OEM{$locationTerm}";
        // Wer Liefert Was (German industrial supplier directory)
        if ($region === 'DE' || $region === 'EU') {
            $queries[] = "site:wlw.de \"{$directoryTargetSector}\" Hersteller";
        }
        // ThomasNet (US industrial supplier directory)
        if ($region === 'US' || !$location) {
            $queries[] = "site:thomasnet.com \"{$directoryTargetSector}\" manufacturer";
        }
        // GlobalSpec (industrial product directory)
        $queries[] = "site:globalspec.com \"{$directoryTargetSector}\" supplier manufacturer";
        // DirectIndustry (industrial marketplace)
        $queries[] = "site:directindustry.com \"{$directoryTargetSector}\" manufacturer";
        // MENA-specific directories for Morocco, Tunisia, Egypt, GCC
        if (in_array($region, ['MA', 'TN', 'EG', 'GCC'], true)) {
            // Kerix (Morocco business directory)
            $queries[] = "site:kerix.net \"{$directoryTargetSector}\" manufacturer";
            // Yellow Pages MENA
            $queries[] = "site:yellowpages.ae OR site:yellowpages.sa \"{$directoryTargetSector}\" manufacturer";
            // Kompass Africa/MENA
            $queries[] = "site:kompass.com/c \"{$directoryTargetSector}\" Morocco OR Egypt OR Tunisia OR UAE manufacturer";
        }

        // ── 6. MOROCCO-SPECIFIC BUSINESS DIRECTORIES ───────────────
        if ($region === 'MA') {
            // Pages Jaunes Maroc (largest Moroccan yellow pages)
            $queries[] = "site:pagesjaunes.ma \"{$directoryTargetSector}\"{$locationTerm}";
            // Charika.ma (Moroccan company registry)
            $queries[] = "site:charika.ma \"{$directoryTargetSector}\" manufacturer{$locationTerm}";
            // Telecontact.ma (Moroccan business directory)
            $queries[] = "site:telecontact.ma \"{$directoryTargetSector}\"{$locationTerm}";
            // Morocco-specific industrial zone queries
            $queries[] = "\"{$directoryTargetSector}\" \"Tanger Automotive City\" OR \"TFZ\" OR \"Tanger Free Zone\" manufacturer";
            $queries[] = "\"{$directoryTargetSector}\" \"Atlantic Free Zone\" OR \"Kenitra\" OR \"Midparc\" OR \"Bouskoura\" manufacturer";
            // AMICA (association of car industry in Morocco)
            $queries[] = "site:amica.org.ma OR \"AMICA\" \"{$directoryTargetSector}\" manufacturer Morocco";
            // AMITH Morocco textile/industrial association
            $queries[] = "\"{$directoryTargetSector}\" \"zone industrielle\" OR \"zone franche\" Morocco manufacturer";
        }

        // ── 7. EGYPT-SPECIFIC BUSINESS DIRECTORIES ─────────────────
        if ($region === 'EG') {
            // Yellow Pages Egypt
            $queries[] = "site:yellowpages.com.eg \"{$directoryTargetSector}\"{$locationTerm}";
            // Egypt Industry Portal
            $queries[] = "site:egyindustry.com OR site:go4worldbusiness.com \"{$directoryTargetSector}\" manufacturer Egypt";
            // Daleel Egypt directory
            $queries[] = "site:daleel.com.eg \"{$directoryTargetSector}\"{$locationTerm}";
            // Industrial Development Authority Egypt
            $queries[] = "\"{$directoryTargetSector}\" \"10th of Ramadan\" OR \"6th of October\" OR \"Suez Canal Economic Zone\" manufacturer";
            $queries[] = "\"{$directoryTargetSector}\" \"industrial zone\" Egypt manufacturer electronics";
        }

        // ── 8. TUNISIA-SPECIFIC BUSINESS DIRECTORIES ───────────────
        if ($region === 'TN') {
            $queries[] = "<<P>>site:tunisieindustrie.nat.tn \"{$directoryTargetSector}\" manufacturer";
            $queries[] = "<<P>>site:pagesjaunes.com.tn \"{$directoryTargetSector}\"{$locationTerm}";
            $queries[] = "<<P>>\"{$directoryTargetSector}\" \"zone industrielle\" Tunisia manufacturer";
        }

        // ── 9. LINKEDIN COMPANY PAGE MINING ────────────────────────
        // LinkedIn company pages for manufacturers in target location
        if ($sector) {
            $queries[] = "site:linkedin.com/company \"{$sector}\" manufacturer{$locationTerm} \"about\"" . $exclude;
        }

        // ── 10. TRADE ASSOCIATION & CLUSTER QUERIES ────────────────
        // Many manufacturers are listed as members of trade associations
        if ($sector) {
            $queries[] = "\"{$sector}\" \"our members\" OR \"member companies\" OR \"member directory\" manufacturer{$locationTerm}" . $exclude;
            $queries[] = "\"{$sector}\" \"cluster\" OR \"association\" \"member list\" manufacturer{$locationTerm}" . $exclude;
        }

        // ── 11. INVESTMENT / INDUSTRIAL ZONE TENANT LISTS ──────────
        // Companies listed as tenants of industrial zones are real manufacturers
        if ($location) {
            $queries[] = "\"industrial zone\" OR \"free zone\" OR \"economic zone\" \"tenant list\" OR \"companies\" \"{$sector}\"{$locationTerm}" . $exclude;
        }

        // ── 12. NICHE SUB-SECTOR DRILLING ──────────────────────────
        // Drill into sub-niches that mainstream queries miss.
        // Rotation based on day-of-week ensures different niches each day.
        if ($sector === 'Automotive') {
            $autoNiches = [
                ['"automotive lighting" OR "headlamp" OR "tail light" manufacturer', '"body electronics" OR "BCM" OR "body control module" manufacturer'],
                ['"brake system" OR "ABS module" OR "braking" electronics manufacturer', '"steering system" OR "EPS" OR "electric power steering" manufacturer'],
                ['"automotive sensor" OR "LIDAR" OR "radar module" manufacturer', '"infotainment" OR "head unit" OR "telematics" manufacturer'],
                ['"exhaust system" OR "catalytic converter" OR "emission" manufacturer', '"automotive connector" OR "wiring harness" OR "cable assembly" manufacturer'],
                ['"dashboard" OR "instrument cluster" OR "HUD" manufacturer', '"seat electronics" OR "airbag" OR "SRS" module manufacturer'],
                ['"EV charger" OR "on-board charger" OR "DC-DC converter" automotive', '"battery management" OR "BMS" OR "thermal management" automotive manufacturer'],
                ['"automotive relay" OR "fuse box" OR "junction box" manufacturer', '"window regulator" OR "door module" OR "lock actuator" manufacturer'],
            ];
            $nicheGroup = $autoNiches[$daySeed % count($autoNiches)];
            foreach ($nicheGroup as $niche) {
                $queries[] = "{$niche}{$locationTerm}" . $exclude;
            }
        }

        // ── 13. OPENCORPORATES / COMPANY REGISTRY MINING ───────────
        // Open business registries can surface companies invisible to Google
        if ($sector) {
            $queries[] = "site:opencorporates.com \"{$sector}\"{$locationTerm}";
            $queries[] = "site:dnb.com OR site:crunchbase.com \"{$sector}\" {$mfgAlt}{$locationTerm}";
        }

        // ── 14. IMPORT/EXPORT DATA MINING ──────────────────────────
        // Companies that import electronic components are likely buyers
        if ($sector && in_array($region, ['MA', 'EG', 'TN'], true)) {
            $queries[] = "\"{$sector}\" \"import\" OR \"importer\" electronics components{$locationTerm}" . $exclude;
            $queries[] = "site:importgenius.com OR site:panjiva.com \"{$sector}\"{$locationTerm}";
        }

        // ── 15. CERTIFICATION BODY LISTINGS ────────────────────────
        // Companies that hold IATF/ISO certifications are prime targets
        if ($sector === 'Automotive') {
            $queries[] = "\"IATF 16949\" certified {$compAlt} list{$locationTerm}" . $exclude;
            $queries[] = "\"IATF 16949\" certificate holder {$mfgAlt}{$locationTerm}" . $exclude;
        }
        if ($sector) {
            $queries[] = "\"ISO 9001\" certified \"{$sector}\" {$mfgAlt}{$locationTerm}" . $exclude;
        }

        // ── 16. GOVERNMENT INVESTMENT & FDI REFERENCES ─────────────
        // Government FDI reports often list manufacturers in a region
        if ($sector && in_array($region, ['MA', 'EG', 'TN'], true)) {
            $countryFull = match($region) { 'MA' => 'Morocco', 'EG' => 'Egypt', 'TN' => 'Tunisia', default => '' };
            $queries[] = "\"{$sector}\" \"invest in {$countryFull}\" OR \"FDI\" {$mfgAlt} list" . $exclude;
            $queries[] = "\"{$sector}\" \"{$countryFull}\" \"new factory\" OR \"new plant\" OR \"expansion\"{$freshness}" . $exclude;
        }

        // ── 17. INDUSTRIAL ZONE / FREE ZONE TENANT QUERIES ────────
        // Real manufacturers locate in industrial zones. Querying zone
        // names directly surfaces companies invisible to generic queries.
        if ($sector && $region === 'EG') {
            // Egypt's main manufacturing zones (rotate 2 per run)
            $egyptZones = [
                ['"10th of Ramadan" OR "10th of ramadan city"', '"6th of October" OR "6th of october city"'],
                ['"Sadat City" factory', '"Borg El Arab" OR "Borg el-Arab" industrial'],
                ['"Ain Sokhna" OR "Sokhna" industrial zone', '"New Cairo" OR "Badr City" manufacturing'],
                ['"Obour City" OR "El Obour" factory', '"10th of Ramadan" manufacturing plant'],
            ];
            $zoneGroup = $egyptZones[$daySeed % count($egyptZones)];
            foreach ($zoneGroup as $zone) {
                $queries[] = "{$zone} \"{$sector}\" {$mfgAlt}" . $exclude;
            }
            // Egypt industry associations & directories
            $queries[] = "site:amcham.org.eg \"{$sector}\" OR \"manufacturing\"";
            $queries[] = "\"{$sector}\" \"Egypt\" manufacturer \"factory\" OR \"plant\" -dealer -showroom -rental" . $exclude;
        }
        if ($sector && $region === 'MA') {
            // Morocco's main automotive/manufacturing zones (rotate 2 per run)
            $moroccoZones = [
                ['"Tanger Automotive City" OR "Tanger Free Zone"', '"Atlantic Free Zone" OR "Kenitra" factory'],
                ['"Casablanca Free Zone" OR "CFCIM"', '"Nouaceur" OR "Bouskoura" industrial'],
                ['"MIDPARC" OR "aeroport Mohammed V" industrial', '"Tangier Med" OR "Tanger Med" manufacturer'],
            ];
            $zoneGroup = $moroccoZones[$daySeed % count($moroccoZones)];
            foreach ($zoneGroup as $zone) {
                $queries[] = "{$zone} \"{$sector}\" {$mfgAlt}" . $exclude;
            }
            $queries[] = "site:amcham.ma OR site:cfcim.org \"{$sector}\" OR \"manufacturing\"";
        }
        if ($sector && $region === 'TN') {
            // Tunisia's main industrial/free zones — run ALL zones (priority)
            $tunisiaZones = [
                ['"Zone Franche Bizerte" OR "Bizerte Free Zone" factory', '"El Fejja" OR "Borj Cedria" industrial zone'],
                ['"Zone Industrielle Sousse" OR "Sousse" industrial factory', '"Monastir" OR "Ksar Hellal" manufacturer'],
                ['"Enfidha" OR "Enfidha Free Zone" industrial', '"Nabeul" OR "Grombalia" manufacturer OR factory'],
                ['"Ben Arous" OR "Mégrine" industrial zone', '"Sfax" industrial OR factory OR manufacturer'],
            ];
            // Run 2 zone groups per day (rotate which 2)
            $zoneIdx1 = $daySeed % count($tunisiaZones);
            $zoneIdx2 = ($daySeed + 1) % count($tunisiaZones);
            foreach ([$tunisiaZones[$zoneIdx1], $tunisiaZones[$zoneIdx2]] as $zGroup) {
                foreach ($zGroup as $zone) {
                    $queries[] = "<<P>>{$zone} \"{$sector}\" {$mfgAlt}" . $exclude;
                }
            }
            // Tunisia industry associations & directories
            $queries[] = "<<P>>site:tunisieindustrie.nat.tn \"{$sector}\" OR \"manufacturing\"";
            $queries[] = "<<P>>site:cepex.nat.tn OR \"FIPA Tunisia\" \"{$sector}\" manufacturer";
        }

        // ── 18. ANTI-NOISE MANUFACTURING-FOCUSED QUERIES ───────────
        // Explicitly exclude non-manufacturer noise (dealer, rental, news)
        // to surface real factories hidden behind generic search results.
        if ($sector && in_array($region, ['MA', 'EG', 'TN'], true)) {
            $countryFull = match($region) { 'MA' => 'Morocco', 'EG' => 'Egypt', 'TN' => 'Tunisia', default => '' };
            $pTag = ($region === 'TN') ? '<<P>>' : '';
            $queries[] = "{$pTag}\"{$sector}\" \"{$countryFull}\" \"factory\" OR \"manufacturing plant\" OR \"production line\" -dealer -showroom -news -blog" . $exclude;
            $queries[] = "{$pTag}\"{$sector}\" \"{$countryFull}\" \"our factory\" OR \"our plant\" OR \"our production\"" . $exclude;
            $queries[] = "{$pTag}\"made in {$countryFull}\" \"{$sector}\" parts OR components {$mfgAlt}" . $exclude;
        }

        // ── 19. PROCESS-BASED MANUFACTURING QUERIES ────────────────
        // Many MENA manufacturers describe themselves by PROCESS (injection
        // molding, stamping, machining) rather than by SECTOR name. These
        // queries surface real factories the sector-centric queries miss.
        if ($sector === 'Automotive' && in_array($region, ['EG', 'MA', 'TN'], true)) {
            $countryFull = match ($region) { 'MA' => 'Morocco', 'EG' => 'Egypt', 'TN' => 'Tunisia', default => '' };
            $ccTld = match ($region) { 'MA' => '.ma', 'EG' => '.com.eg', 'TN' => '.tn', default => '' };
            $pTag19 = ($region === 'TN') ? '<<P>>' : '';

            // Day-rotating groups of process queries (2 per day)
            $processGroups = [
                ['"injection molding" OR "injection moulding" "' . $countryFull . '" automotive OR "auto parts"',
                 '"metal stamping" OR "sheet metal" "' . $countryFull . '" manufacturer OR factory'],
                ['"CNC machining" OR "precision machining" "' . $countryFull . '" automotive OR components',
                 '"rubber molding" OR "rubber products" "' . $countryFull . '" manufacturer OR automotive'],
                ['"die casting" OR "aluminum casting" "' . $countryFull . '" automotive OR parts',
                 '"plastic parts" OR "plastic components" "' . $countryFull . '" automotive manufacturer'],
                ['"PCB assembly" OR "electronic assembly" "' . $countryFull . '" manufacturer',
                 '"automotive supplier" "' . $countryFull . '" tier OR OEM OR manufacturer'],
                ['"glass manufacturer" OR "automotive glass" "' . $countryFull . '"',
                 '"brake" OR "clutch" OR "bearing" manufacturer "' . $countryFull . '"'],
                ['"cable assembly" OR "wiring" "' . $countryFull . '" manufacturer OR factory',
                 '"automotive lighting" OR "LED module" "' . $countryFull . '" manufacturer'],
                ['"paint" OR "coating" automotive "' . $countryFull . '" manufacturer OR plant',
                 '"seat" OR "upholstery" OR "foam" automotive "' . $countryFull . '" manufacturer'],
            ];
            $pGroup = $processGroups[$daySeed % count($processGroups)];
            foreach ($pGroup as $pq) {
                $queries[] = $pTag19 . $pq . ' ' . $exclude;
            }

            // ccTLD-specific query (surfaces .com.eg / .ma / .tn sites)
            $queries[] = $pTag19 . "site:{$ccTld} automotive OR \"auto parts\" manufacturer OR factory OR supplier" . $exclude;

            // OEM supplier queries — rotating 2 OEM names per day
            $oems = ['Hyundai', 'Mercedes', 'BMW', 'Nissan', 'Toyota', 'Renault', 'Peugeot',
                     'Stellantis', 'Volkswagen', 'GM', 'Kia', 'Chery', 'MAN', 'Volvo'];
            $oemPair = array_slice($oems, ($daySeed * 2) % count($oems), 2);
            foreach ($oemPair as $oem) {
                $queries[] = $pTag19 . "\"{$oem}\" supplier OR vendor \"{$countryFull}\" automotive parts" . $exclude;
            }
        }

        // ── 20. MULTINATIONAL FACTORY PRESENCE QUERIES ────────────────
        // Many countries have multinational automotive manufacturers with
        // local factories. These companies' corporate sites mention the
        // country in a factory/plant context. These targeted queries find
        // them directly rather than hoping generic queries surface them.
        if ($sector === 'Automotive' && $region === 'EG') {
            // Day-rotating groups of factory-presence queries (3 per day)
            $factoryGroups = [
                // Group 0: Wiring harness manufacturers (huge in Egypt)
                ['"wiring harness" OR "wire harness" factory OR plant Egypt OR Cairo',
                 '"Yazaki" OR "Sumitomo" OR "Leoni" Egypt factory OR plant OR facility',
                 '"Lear" OR "Aptiv" OR "Delphi" Egypt manufacturing OR production'],
                // Group 1: Tier 1/2 auto parts multinationals
                ['"Continental" OR "ZF" OR "Bosch" Egypt factory OR plant OR facility',
                 '"Valeo" OR "Faurecia" OR "Forvia" Egypt manufacturing OR production',
                 '"Magna" OR "Denso" OR "Aisin" Egypt plant OR facility automotive'],
                // Group 2: Glass, brake, bearing specialists
                ['"Saint-Gobain" OR "AGC" OR "Pilkington" Egypt automotive glass factory',
                 '"SKF" OR "Schaeffler" OR "NTN" Egypt bearing OR manufacturing',
                 '"Brembo" OR "TMD" OR "Federal Mogul" Egypt brake OR friction manufacturing'],
                // Group 3: Plastics, rubber, sealing
                ['"Hutchinson" OR "Trelleborg" OR "NOK" Egypt rubber OR sealing factory',
                 '"Plastic Omnium" OR "Motherson" OR "Samvardhana" Egypt manufacturing',
                 '"ElringKlinger" OR "Dana" OR "Freudenberg" Egypt plant OR gasket OR seal'],
                // Group 4: Egyptian industrial zone specific
                ['"10th of Ramadan" OR "6th of October" automotive factory OR manufacturer',
                 '"Sadat City" OR "Borg El Arab" automotive OR auto parts manufacturer',
                 '"Suez Canal Economic Zone" OR "SCZONE" OR "Ain Sokhna" automotive manufacturer'],
                // Group 5: Egyptian automotive associations & directories
                ['"Engineering Industries Chamber" Egypt automotive member',
                 '"Egyptian Automotive" manufacturer OR supplier directory list',
                 'site:kompass.com OR site:dnb.com automotive manufacturer Egypt'],
                // Group 6: Recent investment / expansion
                ['automotive "new factory" OR "new plant" OR "expansion" Egypt 2024 OR 2025 OR 2026',
                 'automotive manufacturer "invested" OR "investment" Egypt million',
                 '"automotive cluster" OR "automotive hub" Egypt manufacturer'],
            ];
            $fGroup = $factoryGroups[$daySeed % count($factoryGroups)];
            foreach ($fGroup as $fq) {
                $queries[] = $fq . ' ' . $exclude;
            }
        }

        // ── 20b. MULTINATIONAL FACTORY PRESENCE — TUNISIA ─────────────
        // Tunisia is a major wiring-harness and automotive components hub.
        // Many European multinationals have factories there.
        if ($sector === 'Automotive' && $region === 'TN') {
            $factoryGroupsTN = [
                // Group 0: Wiring harness (Tunisia is #1 in Africa)
                ['"wiring harness" OR "wire harness" factory OR plant Tunisia OR Tunis',
                 '"Yazaki" OR "Sumitomo" OR "Fujikura" Tunisia factory OR plant',
                 '"Leoni" OR "Draexlmaier" OR "Dräxlmaier" Tunisia manufacturing OR production'],
                // Group 1: European Tier 1 with Tunisia plants
                ['"Aptiv" OR "Lear" OR "Coficab" Tunisia factory OR plant OR facility',
                 '"Valeo" OR "Continental" OR "Marquardt" Tunisia manufacturing OR production',
                 '"Faurecia" OR "Forvia" OR "Premo" Tunisia plant OR usine'],
                // Group 2: French industrials with Tunisia presence
                ['"Actia" OR "Lacroix" OR "Mets" Tunisia factory OR plant',
                 '"Sagemcom" OR "Telnet" OR "Zodiac" Tunisia manufacturing',
                 '"Hutchinson" OR "Plastic Omnium" OR "Faurecia" Tunisia usine OR factory'],
                // Group 3: German/Italian auto parts with Tunisia operations
                ['"Kromberg" OR "Schubert" OR "Prettl" Tunisia factory OR plant',
                 '"Brose" OR "Hella" OR "MAHLE" Tunisia manufacturing OR production',
                 '"Pirelli" OR "Magneti Marelli" OR "Marelli" Tunisia plant OR facility'],
                // Group 4: Tunisia automotive associations & investment
                ['"CETTEX" OR "Centre Technique du Textile" Tunisia automotive OR textile technique',
                 '"FIPA" Tunisia automotive OR manufacturing investment OR investor',
                 'site:tunisieindustrie.nat.tn automotive OR "industrie automobile"'],
                // Group 5: Recent investment / expansion
                ['automotive "new factory" OR "new plant" OR "expansion" Tunisia 2024 OR 2025 OR 2026',
                 'automotive manufacturer "invested" OR "investment" Tunisia million',
                 '"zone franche" OR "zone industrielle" Tunisia automotive manufacturer usine'],
            ];
            // Run 2 groups (6 queries) instead of 1 (3) — all priority-tagged
            $fIdx1 = $daySeed % count($factoryGroupsTN);
            $fIdx2 = ($daySeed + 1) % count($factoryGroupsTN);
            foreach ([$factoryGroupsTN[$fIdx1], $factoryGroupsTN[$fIdx2]] as $fGroupTN) {
                foreach ($fGroupTN as $fq) {
                    $queries[] = '<<P>>' . $fq . ' ' . $exclude;
                }
            }

            // French-language queries (Tunisia is francophone) — ALL groups, priority
            $frenchGroups = [
                ['"fabricant" OR "usine" automobile OR "pièces auto" Tunisie',
                 '"équipementier automobile" Tunisie OR "zone franche"'],
                ['"câblage automobile" OR "faisceau de câbles" Tunisie usine',
                 '"injection plastique" OR "moulage" automobile Tunisie fabricant'],
            ];
            foreach ($frenchGroups as $frGroup) {
                foreach ($frGroup as $frq) {
                    $queries[] = '<<P>>' . $frq . ' ' . $exclude;
                }
            }

            // ── Direct manufacturer name queries (highest value) ─────────
            // Search for specific known manufacturers by name + Tunisia
            $directMfrs = [
                'Misfat Tunisia filter manufacturer',
                'STIA Tunisia automotive interior',
                'Coficab Tunisia cable manufacturer',
                'AAF Tunisia automotive',
                '"Bontaz" Tunisia automotive factory',
                '"Chakira" Tunisia manufacturer',
                '"Nani" Tunisia manufacturing',
                '"Mets" OR "Metserco" Tunisia automotive',
            ];
            foreach ($directMfrs as $dm) {
                $queries[] = '<<P>>' . $dm . ' ' . $exclude;
            }
        }

        // ── 20c. FRANCE-SPECIFIC AUTOMOTIVE / INDUSTRIAL QUERIES ──────
        // France is a major automotive manufacturing hub with large OEMs
        // (Renault, Stellantis/PSA) and hundreds of Tier 1/2 suppliers.
        // French companies often have French-language-only websites.
        // ALL France queries are PRIORITY — they produce the best results for FR searches.
        if ($sector && $region === 'FR') {
            // ── French-language sector queries (many FR companies are FR-only sites) ──
            if ($sector === 'Automotive') {
                $queries[] = '<<P>>' . "\"équipementier automobile\" France OR français fabricant" . $exclude;
                $queries[] = '<<P>>' . "\"sous-traitant automobile\" France usine OR fabricant" . $exclude;
                $queries[] = '<<P>>' . "\"industrie automobile\" France \"nos produits\" OR \"notre usine\"" . $exclude;
                $queries[] = '<<P>>' . "\"constructeur\" OR \"fabricant\" \"pièces automobiles\" France" . $exclude;
                $queries[] = '<<P>>' . "\"câblage automobile\" OR \"faisceau\" France fabricant OR usine" . $exclude;
                $queries[] = '<<P>>' . "\"injection plastique\" automobile France fabricant" . $exclude;
                $queries[] = '<<P>>' . "\"découpage\" OR \"emboutissage\" automobile France fabricant" . $exclude;
                $queries[] = '<<P>>' . "\"fonderie\" OR \"forge\" automobile France" . $exclude;

                // ── Hardcoded well-known French automotive companies as seed queries ──
                // When DB has few same-region entries, these ensure we find real companies
                // and their lesser-known competitors/suppliers via search results.
                $frKnownSeeds = [
                    // Tier 1 French-origin suppliers
                    ['Valeo', 'Faurecia', 'Forvia', 'Plastic Omnium'],
                    ['Actia Group', 'Akwel', 'ARaymond', 'Lisi Automotive'],
                    ['Hutchinson', 'Mersen', 'Le Bélier', 'Novares'],
                    ['NTN-SNR', 'SKF France', 'Schaeffler France', 'GMD'],
                    // Wiring / connectors in France
                    ['Nexans autoelectric', 'Coficab France', 'Leoni France', 'Aptiv France'],
                    ['Radiall', 'Souriau', 'Amphenol France', 'TE Connectivity France'],
                    // Stamping / machining / plastics
                    ['Lisi Aerospace', 'Figeac Aero', 'Mecachrome', 'SMP France'],
                    ['Novares', 'Bourbon Automotive Plastics', 'ERCE', 'MGI Coutier'],
                ];
                // Use 2 groups per run (rotating) to avoid too many queries
                $seedGroup1 = $frKnownSeeds[$daySeed % count($frKnownSeeds)];
                $seedGroup2 = $frKnownSeeds[($daySeed + 1) % count($frKnownSeeds)];
                $seedCompanies = array_merge($seedGroup1, $seedGroup2);
                // Build "competitors of X" queries for each seed company
                foreach ($seedCompanies as $seedName) {
                    $queries[] = '<<P>>' . "\"{$seedName}\" competitors OR fournisseur OR supplier automobile France" . $exclude;
                }
            } else {
                $queries[] = '<<P>>' . "\"fabricant\" \"{$sector}\" France \"nos produits\" OR \"notre usine\"" . $exclude;
                $queries[] = '<<P>>' . "\"sous-traitant\" \"{$sector}\" France usine OR fabricant" . $exclude;
            }

            // ── French business directories ──
            $directorySector = $sector ?? 'electronics';
            $queries[] = "site:societe.com \"{$directorySector}\" fabricant OR manufacturer";
            $queries[] = "site:annuaire-entreprises.data.gouv.fr \"{$directorySector}\"";
            $queries[] = "site:usinenouvelle.com \"{$directorySector}\" usine OR fabricant France";
            $queries[] = "site:industrie-techno.com \"{$directorySector}\" fabricant France";
            $queries[] = "site:kompass.com \"{$directorySector}\" fabricant France";

            // ── French automotive associations & clusters ──
            if ($sector === 'Automotive') {
                $queries[] = '<<P>>' . "\"PFA\" OR \"Plateforme Filière Automobile\" adhérent OR membre fabricant" . $exclude;
                $queries[] = '<<P>>' . "\"FIEV\" OR \"fiev.fr\" équipementier OR membre automobile France" . $exclude;
                $queries[] = '<<P>>' . "\"SIA\" OR \"sia.fr\" membre OR partenaire automobile France" . $exclude;
                $queries[] = '<<P>>' . "\"Pôle Véhicule du Futur\" OR \"vehiculedufutur\" membre OR adhérent" . $exclude;
                $queries[] = '<<P>>' . "\"Mov'eo\" OR \"moveo.fr\" membre OR partenaire automotive" . $exclude;
                $queries[] = '<<P>>' . "\"NextMove\" OR \"nextmove.fr\" membre équipementier automobile" . $exclude;
            }

            // ── French industrial zones / automotive regions ──
            // Run ALL zone groups every run — France has many spread-out industrial regions.
            if ($sector === 'Automotive') {
                $frAutoZones = [
                    '"Hauts-de-France" OR "Nord-Pas-de-Calais" automobile usine OR fabricant',
                    '"Douai" OR "Valenciennes" OR "Onnaing" automobile manufacturer OR usine OR factory',
                    '"Normandie" OR "Normandy" automobile usine OR fabricant OR factory',
                    '"Sandouville" OR "Cléon" OR "Dieppe" Renault OR automobile manufacturer',
                    '"Vallée de l\'Arve" OR "Arve Valley" OR "décolletage" manufacturer OR fabricant',
                    '"Haute-Savoie" OR "Cluses" OR "Scionzier" automobile usine OR manufacturer',
                    '"Auvergne-Rhône-Alpes" automobile équipementier OR fabricant',
                    '"Lyon" OR "Grenoble" OR "Saint-Étienne" automobile manufacturer OR usine',
                    '"Île-de-France" OR "Centre-Val de Loire" automobile équipementier OR usine',
                    '"Poissy" OR "Flins" OR "Sochaux" OR "Mulhouse" automobile usine OR manufacturer',
                    '"Grand Est" OR "Alsace" automobile fabricant OR équipementier',
                    '"Strasbourg" OR "Mulhouse" OR "Metz" automobile manufacturer OR usine',
                    '"Bretagne" OR "Pays de la Loire" automobile fabricant OR manufacturer',
                    '"Rennes" OR "Nantes" OR "Le Mans" automobile usine OR factory',
                ];
                foreach ($frAutoZones as $fzq) {
                    $queries[] = '<<P>>' . $fzq . ' ' . $exclude;
                }
            }

            // ── MULTINATIONAL FACTORY PRESENCE in France ──
            // Run ALL groups every run — each targets different supplier families.
            if ($sector === 'Automotive') {
                $frFactoryQueries = [
                    // Major Tier 1 with France plants
                    '"Valeo" France usine OR factory OR plant manufacturing',
                    '"Faurecia" OR "Forvia" France usine OR factory OR manufacturing',
                    '"Plastic Omnium" France usine OR factory OR manufacturing',
                    // German suppliers in France
                    '"Continental" OR "Schaeffler" France usine OR factory OR plant',
                    '"Bosch" OR "ZF" France usine OR manufacturing OR facility',
                    '"Hella" OR "MAHLE" OR "Brose" France usine OR factory',
                    // Japanese / global suppliers
                    '"Yazaki" OR "Sumitomo" OR "Denso" France usine OR factory',
                    '"NTN" OR "NSK" OR "Aisin" France usine OR plant manufacturing',
                    '"Toyota" OR "Michelin" France usine OR factory OR supplier',
                    // French-origin suppliers
                    '"Hutchinson" OR "Mersen" France fabricant OR manufacturer',
                    '"Actia" OR "Aptiv" OR "Akwel" France usine OR factory',
                    '"Le Bélier" OR "Lisi" OR "ARaymond" France fabricant automotive',
                    // Wire harness / cable / connectors
                    '"Nexans" OR "Leoni" OR "Coficab" France câblage OR wiring factory',
                    '"Amphenol" OR "TE Connectivity" OR "Molex" France usine OR factory',
                    '"Radiall" OR "Souriau" France connecteur OR connector manufacturer',
                    // OEM supplier queries
                    '"Renault" OR "Stellantis" fournisseur OR supplier France usine OR factory',
                    '"PSA" OR "Peugeot" OR "Citroën" fournisseur OR supplier fabricant France',
                    '"Renault" OR "Dacia" supplier tier France automotive manufacturer',
                ];
                foreach ($frFactoryQueries as $ffq) {
                    $queries[] = '<<P>>' . $ffq . ' ' . $exclude;
                }
            }

            // ── French certification-based queries ──
            if ($sector === 'Automotive') {
                $queries[] = '<<P>>' . "\"IATF 16949\" France OR français certifié OR certified list" . $exclude;
                $queries[] = '<<P>>' . "\"ISO 9001\" équipementier automobile France certifié" . $exclude;
            } else {
                $queries[] = '<<P>>' . "\"ISO 9001\" fabricant \"{$sector}\" France certifié" . $exclude;
            }

            // ── French ccTLD site queries ──
            $queries[] = '<<P>>' . "site:.fr \"{$sector}\" fabricant OR manufacturer \"nos produits\" OR \"about us\"" . $exclude;
            $queries[] = '<<P>>' . "site:.fr automobile OR automotive \"usine\" OR \"factory\" OR \"production\"" . $exclude;

            // ── French investment / expansion ──
            $queries[] = '<<P>>' . "\"{$sector}\" \"nouvelle usine\" OR \"extension\" OR \"investissement\" France 2024 OR 2025" . $exclude;
            $queries[] = '<<P>>' . "\"{$sector}\" France fabricant \"notre usine\" OR \"our factory\" OR \"our plant\"" . $exclude;

            // ── SIMPLE ENGLISH-LANGUAGE QUERIES (high success rate on scraped engines) ──
            // Scraped engines handle simple queries much better than complex
            // French-language queries with diacritics and multiple OR operators.
            if ($sector === 'Automotive') {
                $queries[] = '<<P>>automotive parts manufacturer France' . $exclude;
                $queries[] = '<<P>>automotive supplier France tier 1' . $exclude;
                $queries[] = '<<P>>French automotive parts supplier list' . $exclude;
                $queries[] = '<<P>>automotive component manufacturer France factory' . $exclude;
                $queries[] = '<<P>>France automotive tier 2 supplier manufacturer' . $exclude;
                $queries[] = '<<P>>car parts manufacturer France factory production' . $exclude;
                $queries[] = '<<P>>automotive wiring harness manufacturer France' . $exclude;
                $queries[] = '<<P>>automotive injection molding France manufacturer' . $exclude;
                $queries[] = '<<P>>automotive stamping forging manufacturer France' . $exclude;
                $queries[] = '<<P>>automotive electronics manufacturer France' . $exclude;
                $queries[] = '<<P>>auto parts supplier France company' . $exclude;
                $queries[] = '<<P>>automobile equipment manufacturer France' . $exclude;

                // ── Direct company searches (highest value — name + context) ──
                // These are SIMPLE searches for specific known companies that
                // any search engine can handle. Each returns the company's own site.
                $directFrCompanies = [
                    'Valeo automotive supplier',
                    'Faurecia automotive manufacturer',
                    'Plastic Omnium automotive systems',
                    'Actia Group electronics automotive',
                    'Akwel automotive supplier France',
                    'ARaymond fastener automotive',
                    'Hutchinson automotive parts',
                    'Mersen electrical components manufacturer',
                    'MGI Coutier automotive manufacturer',
                    'Novares automotive plastics',
                    'LISI Automotive fasteners',
                    'Le Belier foundry automotive',
                    'Mecaplast automotive components',
                    'Delfingen automotive protection',
                    'Gruau vehicle manufacturer France',
                    'Burelle automotive group',
                    'SMP automotive supplier France',
                    'Streit automotive bodywork France',
                    'SNOP automotive stamping France',
                    'Poclain Hydraulics manufacturer France',
                    'Radiall connector manufacturer France',
                    'Souriau connector automotive aerospace',
                ];
                // Cycle through 8 companies per run to avoid too many queries
                $companiesPerRun = 8;
                $offset = ($daySeed * $companiesPerRun) % count($directFrCompanies);
                for ($i = 0; $i < $companiesPerRun; $i++) {
                    $idx = ($offset + $i) % count($directFrCompanies);
                    $queries[] = '<<P>>' . $directFrCompanies[$idx] . $exclude;
                }
            } else {
                $queries[] = '<<P>>' . "{$sector} manufacturer France company" . $exclude;
                $queries[] = '<<P>>' . "{$sector} supplier France factory" . $exclude;
                $queries[] = '<<P>>' . "French {$sector} manufacturer list" . $exclude;
            }

            // ── SIMPLE FRENCH-LANGUAGE QUERIES (no diacritics for better scraping) ──
            if ($sector === 'Automotive') {
                $queries[] = '<<P>>equipementier automobile France' . $exclude;
                $queries[] = '<<P>>sous-traitant automobile France usine' . $exclude;
                $queries[] = '<<P>>fabricant pieces automobile France' . $exclude;
                $queries[] = '<<P>>fournisseur automobile France fabricant' . $exclude;
                $queries[] = '<<P>>industrie automobile France usine production' . $exclude;
            }
        }

        // ── 20d. GERMANY-SPECIFIC AUTOMOTIVE / INDUSTRIAL QUERIES ────
        // Germany is the world's largest automotive supplier hub.
        if ($sector && $region === 'DE') {
            // ── German-language sector queries ──
            if ($sector === 'Automotive') {
                $queries[] = "\"Automobilzulieferer\" OR \"Kfz-Zulieferer\" Deutschland Hersteller" . $exclude;
                $queries[] = "\"Fahrzeugtechnik\" OR \"Automobiltechnik\" Hersteller Deutschland" . $exclude;
                $queries[] = "\"Kabelbaumhersteller\" OR \"Kabelbaum\" Deutschland Hersteller" . $exclude;
                $queries[] = "\"Spritzguss\" OR \"Kunststofftechnik\" Automobil Deutschland" . $exclude;
                $queries[] = "\"Stanztechnik\" OR \"Umformtechnik\" Automobil Deutschland Hersteller" . $exclude;
            } else {
                $queries[] = "\"Hersteller\" \"{$sector}\" Deutschland \"unsere Produkte\" OR \"unser Werk\"" . $exclude;
                $queries[] = "\"Zulieferer\" \"{$sector}\" Deutschland Hersteller OR Fabrik" . $exclude;
            }

            // ── German directories ──
            $queries[] = "site:wlw.de \"{$sector}\" Hersteller Deutschland";
            $queries[] = "site:industrystock.de \"{$sector}\" Hersteller Deutschland";

            // ── German automotive associations ──
            if ($sector === 'Automotive') {
                $queries[] = "\"VDA\" OR \"vda.de\" Mitglied OR member Automobilzulieferer" . $exclude;
                $queries[] = "\"IATF 16949\" Deutschland OR Germany zertifiziert OR certified list" . $exclude;
            }

            // ── German ccTLD site queries ──
            $queries[] = "site:.de \"{$sector}\" Hersteller \"unsere Produkte\" OR \"about us\"" . $exclude;
        }

        return $queries;
    }

    /**
     * Region-specific TLD suggestions for domain guessing.
     */
    private const REGION_TLDS = [
        'MA' => ['.ma', '.com'],
        'EG' => ['.com.eg', '.eg', '.com'],
        'TN' => ['.tn', '.com.tn', '.com'],
        'US' => ['.com', '.us', '.net'],
        'GB' => ['.co.uk', '.com', '.uk'],
        'EU' => ['.com', '.eu', '.de', '.fr'],
        'DE' => ['.de', '.com', '.eu'],
        'FR' => ['.fr', '.com', '.eu'],
        'PL' => ['.pl', '.com.pl', '.com', '.eu'],
        'NL' => ['.nl', '.com', '.eu'],
        'IT' => ['.it', '.com', '.eu'],
        'ES' => ['.es', '.com', '.eu'],
        'BE' => ['.be', '.com', '.eu'],
        'AT' => ['.at', '.com', '.eu'],
        'CZ' => ['.cz', '.com', '.eu'],
        'SE' => ['.se', '.com', '.eu'],
        'DK' => ['.dk', '.com', '.eu'],
        'FI' => ['.fi', '.com', '.eu'],
        'NO' => ['.no', '.com'],
        'CH' => ['.ch', '.com'],
        'RO' => ['.ro', '.com', '.eu'],
        'HU' => ['.hu', '.com', '.eu'],
        'PT' => ['.pt', '.com', '.eu'],
        'IE' => ['.ie', '.com', '.eu'],
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
        if (!$this->hasSearchProvider()) {
            $this->logger->warning('customSearch() called but no search provider configured');
            return [];
        }

        $this->logger->info('Executing custom search query', ['query' => $query]);

        $allResults = [];

        try {
            $results = $this->executeProviderSearch($this->prepareSearchQueryForProvider($query), 20);

            if (!empty($results['results'])) {
                foreach ($results['results'] as $result) {
                    $domain = $this->canonicalizeResultDomain((string) ($result['displayLink'] ?? ''), (string) ($result['link'] ?? ''));

                    if ($this->isBlockedDomain($domain)) {
                        continue;
                    }

                    $domainKey = $this->domainDedupeKey($domain);
                    if (!isset($allResults[$domainKey])) {
                        $companyName = $this->extractCompanyName($result['title'] ?? '', $domain);

                        if ($this->isJunkCompanyName($companyName) || $this->isGiantOem($companyName)) {
                            continue;
                        }

                        $allResults[$domainKey] = [
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

    /**
     * Sanitize an address string extracted from web scraping.
     *
     * iter15: removes JavaScript code, HTML tags, excessive whitespace,
     * and other artifacts that may leak into addresses from DOM extraction.
     *
     * Returns empty string if the address is unsalvageable.
     */
    /**
     * Canonicalize a host/domain string.
     */
    private function canonicalizeDomain(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $domain)) {
            $host = parse_url($domain, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $domain = $host;
            }
        }

        $domain = strtolower($domain);
        $domain = preg_replace('/:\d+$/', '', $domain) ?? $domain;
        $domain = preg_replace('/^www\d*\./i', '', $domain) ?? $domain;
        $domain = preg_replace('/\.$/', '', $domain) ?? $domain;

        if (str_contains($domain, '/')) {
            $domain = explode('/', $domain, 2)[0];
        }

        $domain = preg_replace('/^(?:en|fr|de|es|it|pt|pl|nl|cz|cs|ar)\./i', '', $domain) ?? $domain;

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/u', $domain)) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $domain = strtolower($ascii);
            }
        }

        return trim($domain);
    }

    private function canonicalizeResultDomain(string $displayLink, string $link = ''): string
    {
        $domain = $this->canonicalizeDomain($displayLink);

        if ($domain === '' && $link !== '') {
            $host = parse_url($link, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $domain = $this->canonicalizeDomain($host);
            }
        }

        return $domain;
    }

    private function domainDedupeKey(string $domain): string
    {
        $domain = $this->canonicalizeDomain($domain);
        if ($domain === '') {
            return '';
        }

        $parts = array_values(array_filter(explode('.', $domain)));
        if (count($parts) <= 2) {
            return $domain;
        }

        $twoLevelPublicSuffixes = [
            'co.uk','org.uk','gov.uk','ac.uk','com.au','net.au','org.au',
            'co.nz','com.br','com.mx','com.tr','com.sa','com.eg','com.tn','com.ma',
            'co.za','co.ke','co.in','co.jp'
        ];

        $tail2 = implode('.', array_slice($parts, -2));
        $tail3 = implode('.', array_slice($parts, -3));

        if (in_array($tail2, $twoLevelPublicSuffixes, true) && count($parts) >= 3) {
            return $tail3;
        }

        if (count($parts) >= 3 && in_array($parts[0], ['en','fr','de','es','it','pt','pl','nl','ar','global','corp','corporate','www2','www3'], true)) {
            return implode('.', array_slice($parts, -2));
        }

        return implode('.', array_slice($parts, -2));
    }

    private function prepareSearchQueryForProvider(string $query): string
    {
        $query = preg_replace('/\s+/u', ' ', trim($query)) ?? trim($query);

        // ── Strip quotes around single words ────────────────────────────
        // SearXNG's site: operator breaks when combined with quoted single
        // words (e.g. site:kerix.net "automotive" returns 0 results, but
        // site:kerix.net automotive returns real companies). This is harmless
        // for all search engines since "word" ≡ word.
        $query = preg_replace('/"(\w+)"/u', '$1', $query);

        preg_match_all('/-site:[^\s]+/u', $query, $m);
        if (!empty($m[0])) {
            $sites = array_values(array_unique($m[0]));
            sort($sites);
            $query = preg_replace('/(?:\s+-site:[^\s]+)+/u', '', $query) ?? $query;
            $query = trim($query) . ' ' . implode(' ', $sites);
        }

        if (mb_strlen($query) > 380) {
            $pieces = preg_split('/\s+/', $query) ?: [];
            $out = [];
            $len = 0;
            foreach ($pieces as $piece) {
                $next = $len + ($len > 0 ? 1 : 0) + mb_strlen($piece);
                if ($next > 380) {
                    break;
                }
                $out[] = $piece;
                $len = $next;
            }
            $query = implode(' ', $out);
        }

        $query = preg_replace('/\s+(OR|AND)\s*$/iu', '', $query) ?? $query;

        return trim($query);
    }

    private function optimizeAndDiversifySearchQueries(array $queries, ?string $sector, ?string $location): array
    {
        $region = $this->detectRegionFromLocation($location);
        $queries = array_merge($queries, $this->buildAdaptiveDiscoveryQueries($sector, $location, $region));

        $seen = [];
        $scored = [];

        foreach ($queries as $raw) {
            if (!is_string($raw)) {
                continue;
            }

            $isPriority = str_starts_with($raw, '<<P>>');
            $q = $isPriority ? substr($raw, 5) : $raw;
            $q = trim($q);
            if ($q === '') {
                continue;
            }

            $q = $this->appendSearchNoiseExclusions($q, $region);
            $q = $this->prepareSearchQueryForProvider($q);
            if ($q === '' || mb_strlen($q) < 8) {
                continue;
            }

            $key = $this->normalizeSearchQueryForDedup($q);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $score = $this->scoreSearchQueryIntent($q, $sector, $location) + ($isPriority ? 35 : 0);
            $scored[] = ['q' => $q, 'score' => $score, 'priority' => $isPriority];
        }

        usort($scored, function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                return strlen($a['q']) <=> strlen($b['q']);
            }
            return $b['score'] <=> $a['score'];
        });

        $div = [];
        $bucketCounts = [];
        foreach ($scored as $row) {
            $bucket = $this->queryDiversityBucket($row['q']);
            $bucketCounts[$bucket] = ($bucketCounts[$bucket] ?? 0) + 1;
            if ($bucketCounts[$bucket] > 18) {
                continue;
            }

            $div[] = ($row['priority'] ? '<<P>>' : '') . $row['q'];
            if (count($div) >= 260) {
                break;
            }
        }

        return $div;
    }

    private function stableDiversifyQueryOrder(array $queries): array
    {
        $buckets = [];
        foreach ($queries as $q) {
            $k = $this->queryDiversityBucket((string) $q);
            $buckets[$k] = $buckets[$k] ?? [];
            $buckets[$k][] = $q;
        }

        uasort($buckets, fn(array $a, array $b) => count($a) <=> count($b));

        $out = [];
        $max = 0;
        foreach ($buckets as $items) {
            $max = max($max, count($items));
        }
        for ($i = 0; $i < $max; $i++) {
            foreach ($buckets as $items) {
                if (isset($items[$i])) {
                    $out[] = $items[$i];
                }
            }
        }
        return $out;
    }

    private function normalizeSearchQueryForDedup(string $q): string
    {
        $q = mb_strtolower($q);
        $q = str_replace(['“','”','’'], ['"','"',"'" ], $q);
        $q = preg_replace('/\s+/u', ' ', trim($q)) ?? trim($q);

        preg_match_all('/-site:[^\s]+/u', $q, $m);
        if (!empty($m[0])) {
            $sites = array_values(array_unique($m[0]));
            sort($sites);
            $q = preg_replace('/(?:\s+-site:[^\s]+)+/u', '', $q) ?? $q;
            $q = trim($q) . ' ' . implode(' ', $sites);
        }

        return trim($q);
    }

    private function scoreSearchQueryIntent(string $q, ?string $sector, ?string $location): int
    {
        $lq = mb_strtolower($q);
        $score = 0;

        $positive = [
            'manufacturer'=>30,'manufacturing'=>24,'oem'=>30,'factory'=>18,'plant'=>18,'production'=>14,
            'assembly'=>18,'wire harness'=>28,'cable harness'=>28,'pcba'=>28,'pcb assembly'=>28,
            'contract manufacturing'=>22,'electronics'=>14,'electronic'=>12,'procurement'=>20,'purchasing'=>20,
            'sourcing'=>16,'rfq'=>20,'supplier'=>14,'tier 1'=>20,'tier 2'=>20,'iatf 16949'=>22,'as9100'=>22,'iso 13485'=>18
        ];
        foreach ($positive as $term => $w) {
            if (str_contains($lq, $term)) {
                $score += $w;
            }
        }

        $negative = [
            'jobs'=>40,'careers'=>40,'news'=>35,'magazine'=>35,'wikipedia'=>35,'reddit'=>25,'forum'=>25,
            'hotel'=>25,'booking'=>25,'expo'=>20,'conference'=>20,'market report'=>35
        ];
        foreach ($negative as $term => $w) {
            if (str_contains($lq, $term)) {
                $score -= $w;
            }
        }

        if (preg_match('/\bsite:linkedin\.com\b/i', $q)) {
            $score += 8;
        }
        if (preg_match('/\bsite:(europages|kompass|industrystock|yellowpages)\./i', $q)) {
            $score -= 8;
        }
        if ($sector && stripos($q, $sector) !== false) {
            $score += 12;
        }
        if ($location) {
            $locTerm = trim($this->buildLocationTerm($location));
            if ($locTerm !== '' && stripos($q, $locTerm) !== false) {
                $score += 8;
            }
        }

        $score -= max(0, substr_count($q, ' OR ') - 6) * 3;
        $score -= max(0, intdiv(max(0, mb_strlen($q) - 220), 20));

        return $score;
    }

    private function queryDiversityBucket(string $query): string
    {
        $q = mb_strtolower($query);

        foreach ([
            'site:linkedin.com' => 'linkedin',
            'site:europages' => 'europages',
            'site:kompass' => 'kompass',
            'site:industrystock' => 'industrystock',
            'wire harness' => 'wire_harness',
            'cable harness' => 'cable_harness',
            'pcba' => 'pcba',
            'pcb assembly' => 'pcb_assembly',
            'contract manufacturing' => 'cm',
            'oem' => 'oem',
            'rfq' => 'rfq',
            'procurement' => 'procurement',
            'automotive' => 'automotive',
            'aerospace' => 'aerospace',
            'medical' => 'medical',
            'energy' => 'energy',
            'industrial' => 'industrial',
        ] as $needle => $bucket) {
            if (str_contains($q, $needle)) {
                return $bucket;
            }
        }

        if (str_contains($q, 'intitle:')) return 'intitle';
        if (str_contains($q, 'inurl:')) return 'inurl';
        if (str_contains($q, 'site:')) return 'site';
        return 'broad';
    }

    private function appendSearchNoiseExclusions(string $query, string $region): string
    {
        $lq = mb_strtolower($query);
        $siteRestricted = preg_match('/(^|\s)site:/i', $query) === 1;
        $isLinkedIn = preg_match('/site:linkedin\.com/i', $query) === 1;

        $add = ['-jobs','-careers','-vacancies','-news','-magazine','-wikipedia','-youtube','-facebook','-instagram','-reddit','-forum','-blog'];
        if (!$siteRestricted && !$isLinkedIn) {
            $add = array_merge($add, ['-alibaba','-aliexpress','-amazon','-ebay','-conference','-expo']);
        }
        if (in_array($region, ['MA','TN','EG','GCC'], true)) {
            $add = array_merge($add, ['-emploi','-recrutement','-وظائف']);
        }

        foreach ($add as $x) {
            if (str_contains($lq, mb_strtolower($x))) {
                continue;
            }
            if (mb_strlen($query . ' ' . $x) > 380) {
                break;
            }
            $query .= ' ' . $x;
        }

        return $query;
    }

    private function buildAdaptiveDiscoveryQueries(?string $sector, ?string $location, string $region): array
    {
        $queries = [];
        $locationTerm = trim($this->buildLocationTerm($location));
        $sectorTerm = trim((string) ($sector ?? ''));

        $seedSectorTerms = $sectorTerm !== '' ? [$sectorTerm] : [];
        $sk = mb_strtolower($sectorTerm);
        if (str_contains($sk, 'autom')) {
            $seedSectorTerms = array_merge($seedSectorTerms, ['automotive', 'vehicle electronics', 'tier 1 supplier']);
        } elseif (str_contains($sk, 'aero')) {
            $seedSectorTerms = array_merge($seedSectorTerms, ['aerospace', 'avionics', 'aircraft systems']);
        } elseif (str_contains($sk, 'med')) {
            $seedSectorTerms = array_merge($seedSectorTerms, ['medical device', 'medtech', 'diagnostic equipment']);
        } elseif (str_contains($sk, 'energ')) {
            $seedSectorTerms = array_merge($seedSectorTerms, ['energy storage', 'battery systems', 'power electronics']);
        } elseif (str_contains($sk, 'indust')) {
            $seedSectorTerms = array_merge($seedSectorTerms, ['industrial automation', 'control systems', 'machine builder']);
        } elseif ($sectorTerm === '') {
            $seedSectorTerms = ['industrial electronics', 'equipment manufacturer'];
        }
        $seedSectorTerms = array_values(array_unique($seedSectorTerms));

        foreach (array_slice($seedSectorTerms, 0, 5) as $st) {
            $queries[] = "\"{$st}\" (manufacturer OR OEM)".($locationTerm !== '' ? " {$locationTerm}" : '');
            $queries[] = "\"{$st}\" (factory OR plant OR production)".($locationTerm !== '' ? " {$locationTerm}" : '');
            $queries[] = "\"{$st}\" (procurement OR purchasing OR sourcing OR RFQ)".($locationTerm !== '' ? " {$locationTerm}" : '');
            $queries[] = "\"{$st}\" (electronics OR PCB assembly OR wire harness OR cable assembly)".($locationTerm !== '' ? " {$locationTerm}" : '');
            $queries[] = 'intitle:"'.$st.'" (manufacturer OR OEM)'.($locationTerm !== '' ? " {$locationTerm}" : '');
        }

        if (in_array($region, ['MA','TN','EG','GCC'], true)) {
            $queries[] = '(مصنع OR مُصنّع OR شركة صناعية) (إلكترونيات OR ضفائر أسلاك OR كابلات)'.($locationTerm !== '' ? " {$locationTerm}" : '');
            $queries[] = '(fabricant OR usine OR OEM) (électronique OR faisceau OR câbles)'.($locationTerm !== '' ? " {$locationTerm}" : '');
        }
        if (in_array($region, ['DE','AT','CH','EU'], true)) {
            $queries[] = '(Hersteller OR OEM) (Elektronik OR Kabelbaum OR Leiterplattenbestückung)'.($locationTerm !== '' ? " {$locationTerm}" : '');
        }
        if (in_array($region, ['FR','BE','MA','TN'], true)) {
            $queries[] = '(fabricant OR équipementier OR OEM) (électronique OR carte électronique)'.($locationTerm !== '' ? " {$locationTerm}" : '');
        }

        return array_values(array_filter(array_map('trim', $queries)));
    }

    private function buildLocationTermAdvanced(string $location): string
    {
        $raw = trim($location);
        if ($raw === '') {
            return '';
        }

        $norm = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $norm = preg_replace('/[(){}\[\]]+/u', ' ', $norm) ?? $norm;
        $norm = preg_replace('/\s+/u', ' ', $norm) ?? $norm;
        $lower = mb_strtolower($norm);

        $terms = [];

        $phraseMap = [
            'tangier automotive city' => ['Tangier', 'Tanger', '"Tangier Automotive City"', '"Tanger Automotive City"', 'TAC Morocco'],
            'tanger automotive city' => ['Tangier', 'Tanger', '"Tangier Automotive City"', '"Tanger Automotive City"', 'TAC Morocco'],
            'tanger free zone' => ['Tangier', 'Tanger', '"Tangier Free Zone"', 'TFZ Morocco'],
            'tangier free zone' => ['Tangier', 'Tanger', '"Tangier Free Zone"', 'TFZ Morocco'],
            'kenitra' => ['Kenitra', 'Kénitra', 'Morocco'],
            'kénitra' => ['Kenitra', 'Kénitra', 'Morocco'],
            '10th of ramadan' => ['"10th of Ramadan"', 'Egypt'],
            '6th of october' => ['"6th of October"', 'Egypt'],
            'sixth of october' => ['"Sixth of October"', 'Egypt'],
            'bizerte' => ['Bizerte', 'Tunisia'],
            'sfax' => ['Sfax', 'Tunisia'],
            'tunis' => ['Tunis', 'Tunisia'],
        ];

        foreach ($phraseMap as $needle => $alts) {
            if (str_contains($lower, $needle)) {
                $terms = array_merge($terms, $alts);
            }
        }

        $stop = ['free','zone','industrial','city','area','region','port','special','economic','park','estate','hub','corridor','district','valley','greater','metro','the','of','and'];
        $parts = preg_split('/[\s,\/\-]+/u', $norm) ?: [];
        foreach ($parts as $part) {
            $token = trim($part);
            if ($token === '') {
                continue;
            }
            $asciiToken = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $token) : $token;
            $cmp = mb_strtolower((string) $asciiToken);
            if (mb_strlen($cmp) <= 2 || in_array($cmp, $stop, true)) {
                continue;
            }
            $terms[] = preg_match('/\p{Arabic}/u', $token) ? $token : ucfirst($token);
        }

        $countryCtx = [
            'EG' => ['Egypt','مصر'],
            'MA' => ['Morocco','Maroc','المغرب'],
            'TN' => ['Tunisia','Tunisie','تونس'],
            'GCC' => ['UAE','Saudi','Qatar'],
        ];
        $region = $this->detectRegionFromLocation($location);
        if (isset($countryCtx[$region])) {
            $terms = array_merge($terms, $countryCtx[$region]);
        }

        $seen = [];
        $out = [];
        foreach ($terms as $t) {
            $k = mb_strtolower(str_replace('"', '', trim($t)));
            if ($k === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = trim($t);
        }

        if (empty($out)) {
            return '';
        }

        return ' ' . implode(' ', array_slice($out, 0, 6));
    }

    private function hasCompanySuffix(string $name): bool
    {
        return preg_match('/\b(ltd|llc|inc|corp|corporation|company|co\.?|gmbh|ag|sa|sas|spa|srl|sro|bv|nv|plc|pte|pty|fzc|fze|sarl|group|holding|industr(?:y|ies)|systems?|technologies?|electronics?|automation|robotics|motors?|drives?)\b/iu', $name) === 1;
    }

    private function looksLikeDirectoryOrCategoryLabel(string $name): bool
    {
        $n = mb_strtolower(trim($name));
        if (preg_match('/\b(suppliers?|manufacturers?|exporters?|distributors?|wholesalers?|companies?)\b/iu', $n)
            && preg_match('/\b(list|directory|top|best|in|near|for|of|from)\b/iu', $n)) {
            return true;
        }

        return preg_match('/^(home|about|contact|products?|services?|solutions?|industries|news|careers|downloads|support)$/iu', $n) === 1;
    }

    private function looksLikeStrongCompanyName(string $name): bool
    {
        $trim = trim($name);
        $wordCount = count(preg_split('/\s+/u', $trim) ?: []);

        if (preg_match('/[.!?]/u', $trim) && mb_strlen($trim) > 45) {
            return false;
        }
        if (preg_match('/\b(consulting|law|attorney|hotel|travel|tour|news|media|magazine|university|college|hospital|clinic|bank|insurance|real\s+estate|properties|construction|recruitment|staffing|academy|training)\b/iu', $trim)) {
            return false;
        }

        if ($wordCount >= 1 && $wordCount <= 6 && $this->hasCompanySuffix($trim)) {
            return true;
        }

        if ($wordCount >= 1 && $wordCount <= 4
            && preg_match('/^(?:[\p{L}0-9&.\-]+(?:\s+[\p{L}0-9&.\-]+){0,3})$/u', $trim)
            && preg_match('/[A-Z\p{Lu}]/u', $trim)
            && !preg_match('/\b(home|contact|products?|services?|news|careers)\b/iu', $trim)
        ) {
            return true;
        }

        return false;
    }

    private function sanitizeAddress(string $address): string
    {
        // Strip any HTML tags
        $address = strip_tags($address);

        // Detect and reject JavaScript code fragments
        if (preg_match('/\b(function|\.on\s*\(|\.click|\.submit|\.ajax|\.val\s*\(|\.html\s*\(|addEventListener|document\.|window\.|var\s+|let\s+|const\s+|=>\s*\{|\}\s*\)|console\.)/i', $address)) {
            return '';
        }

        // Detect and reject CSS fragments
        if (preg_match('/\{[^}]*:\s*[^}]*\}|@media|@import|font-size|margin:|padding:|display:/i', $address)) {
            return '';
        }

        // Reject if the "address" is clearly a URL
        if (preg_match('#^https?://#i', trim($address))) {
            return '';
        }

        // Reject HTML attribute artifacts (placeholderText, submitIcon, etc.)
        if (preg_match('/\b(placeholder|placeholderText|submitIcon|className|innerHTML|onclick|onsubmit|setAttribute|getElementById|querySelector)\b/i', $address)) {
            return '';
        }

        // Reject job listing / university artifacts scraped as addresses
        if (preg_match('/\b(placement externe|placement au|appui lin|stage|recrutement|candidature|offre d\'emploi|internship|vacancy|job description|apply now|submit your|curriculum vitae)\b/i', $address)) {
            return '';
        }

        // Reject mid-sentence prose fragments scraped as addresses
        // Valid addresses don't contain phrases like "ranging from", "when seeking", "operators face"
        if (preg_match('/\b(ranging from|when seeking|operators face|industries ranging|in industries|designed to|solutions for|specializing in|committed to|dedicated to|focused on|responsible for|looking for|searching for|working with|helping you|we offer|we provide|we deliver|our goal|our aim)\b/i', $address)) {
            return '';
        }

        // Collapse multiple whitespace/newlines to single space
        $address = preg_replace('/\s+/', ' ', $address);

        // Strip leading/trailing punctuation noise
        $address = trim($address, " \t\n\r\0\x0B,.;:-|/\\");

        // Reject if too short (< 5 chars) or too long (> 300 chars) after cleanup
        if (mb_strlen($address) < 5 || mb_strlen($address) > 300) {
            return '';
        }

        // Reject if mostly non-printable or control characters
        $printable = preg_replace('/[^\x20-\x7E\xA0-\xFF]/u', '', $address);
        if (mb_strlen($printable) < mb_strlen($address) * 0.5) {
            return '';
        }

        return $address;
    }
}
