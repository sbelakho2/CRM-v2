<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\Contact;
use App\Service\WebCrawler\CompanyClassifierService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Aggressive Contact Discovery Service
 * 
 * A 5-phase pipeline designed to find REAL, confirmed key contacts
 * while minimizing Google API costs.
 * 
 * Pipeline phases (ordered by cost):
 *   Phase 1 — FREE: Fast concurrent curl_multi scraping via FastWebScraperService
 *   Phase 2 — FREE: Gemini Flash AI extraction from scraped HTML (free tier / near-free)
 *   Phase 3 — FREE: Gemini enrichment of candidates missing titles
 *   Phase 4 — PAID: Google Search → LinkedIn profile discovery (targeted, 1-2 queries only)
 *   Phase 5 — PAID: Google Search → @domain email discovery (1 query per company)
 * 
 * Performance: 100 companies batch-scraped in ~30-60 seconds (no Playwright/browser overhead)
 */
class AggressiveContactDiscoveryService
{
    /**
     * Grouped role searches for Google LinkedIn queries (targeted, only 2 groups to save API calls)
     */
    private const ROLE_SEARCH_GROUPS = [
        'procurement_ops' => ['Procurement', 'Purchasing', 'Buyer', 'Supply Chain', 'Sourcing', 'Operations', 'Materials'],
        'leadership'      => ['CEO', 'President', 'Managing Director', 'CTO', 'VP', 'Director', 'General Manager', 'COO'],
    ];

    /**
     * Generic email prefixes to filter out (not real person contacts)
     */
    private const GENERIC_EMAIL_PREFIXES = [
        'info', 'sales', 'contact', 'support', 'admin', 'hr', 'marketing',
        'webmaster', 'noreply', 'no-reply', 'office', 'careers', 'jobs',
        'press', 'media', 'general', 'enquiries', 'hello', 'service',
        'help', 'billing', 'accounts', 'orders', 'team', 'news',
        'feedback', 'privacy', 'legal', 'compliance', 'reception',
    ];

    public function __construct(
        private DeepScrapingService          $deepScrapingService,
        private FastWebScraperService        $fastScraper,
        private GeminiContactExtractorService $geminiExtractor,
        private GoogleSearchService          $googleSearchService,
        private CompanyClassifierService     $classifierService,
        private EntityManagerInterface       $entityManager,
        private LoggerInterface              $logger,
    ) {}

    // ──────────────────────────────────────────────────────────────────
    //  Batch fast scraping — pure PHP curl_multi, no browser needed
    // ──────────────────────────────────────────────────────────────────

    /**
     * Pre-scrape ALL company websites using concurrent curl_multi.
     * Returns a map of website URL → structured scrape data.
     * Performance: ~30-60 seconds for 100 sites (vs 30+ min with Playwright).
     *
     * @param Company[] $companies
     * @return array<string, array> Map of website URL → scrape result
     */
    public function batchScrapeAll(array $companies): array
    {
        $urls = [];
        foreach ($companies as $company) {
            $website = $company->getWebsite();
            if ($website) {
                if (!str_starts_with($website, 'http')) {
                    $website = 'https://' . $website;
                }
                $urls[$company->getWebsite()] = $website;
            }
        }

        if (empty($urls)) {
            return [];
        }

        $rawResults = $this->fastScraper->batchScrape(array_values($urls));

        // Map back to original website keys
        $mapped = [];
        foreach ($urls as $originalKey => $normalizedUrl) {
            if (isset($rawResults[$normalizedUrl])) {
                $mapped[$originalKey] = $rawResults[$normalizedUrl];
                $mapped[$normalizedUrl] = $rawResults[$normalizedUrl]; // also index by normalized URL
            }
        }

        return $mapped;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Public entry point
    // ──────────────────────────────────────────────────────────────────

    /**
     * Discover contacts for a company using all available sources.
     * 
     * @param Company $company       The company to find contacts for
     * @param int     $maxContacts   Maximum contacts to persist per company
     * @param bool    $skipGoogle    If true, skip paid Google API calls (phases 4-5)
     * @param array|null $preScrapedData Pre-scraped data from batchScrapeAll()
     * 
     * @return array{created: int, updated: int, skipped: int, contacts: Contact[], sources: string[], phase_stats: array}
     */
    public function discoverContacts(
        Company $company,
        int $maxContacts = 15,
        bool $skipGoogle = false,
        ?array $preScrapedData = null,
    ): array {
        // Ensure company is managed by the EntityManager (may be detached after clear())
        if (!$this->entityManager->contains($company)) {
            $company = $this->entityManager->find(Company::class, $company->getId());
            if (!$company) {
                return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'contacts' => [], 'sources' => [], 'phase_stats' => []];
            }
        }

        $companyName = $company->getName();
        $website     = $company->getWebsite();
        $domain      = $this->extractDomain($website);
        $candidates  = [];
        $sourcesUsed = [];
        $phaseStats  = [];

        $this->logger->info('[AggressiveDiscovery] ══════ Starting for {company} ══════', [
            'company' => $companyName,
            'domain'  => $domain,
            'website' => $website,
        ]);

        // ── Phase 1: Fast web scraping (FREE) ──
        // Use pre-scraped data from batchScrapeAll() if available,
        // otherwise scrape this single company inline via FastWebScraperService
        $phase1Count = 0;
        $scrapeData = null;
        if ($website) {
            try {
                if ($preScrapedData !== null) {
                    $scrapeData = $preScrapedData;
                } else {
                    $normalizedUrl = $website;
                    if (!str_starts_with($normalizedUrl, 'http')) {
                        $normalizedUrl = 'https://' . $normalizedUrl;
                    }
                    $singleResult = $this->fastScraper->batchScrape([$normalizedUrl]);
                    $scrapeData = $singleResult[$normalizedUrl] ?? null;
                }

                if ($scrapeData && !empty($scrapeData['pages'])) {
                    $scraped = $this->processScrapedData($scrapeData, $companyName, $domain);
                    if (!empty($scraped)) {
                        $candidates = array_merge($candidates, $scraped);
                        $sourcesUsed[] = 'fast_scrape';
                        $phase1Count = count($scraped);
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[AggressiveDiscovery] Phase 1 failed: {msg}', ['msg' => $e->getMessage()]);
            }
        }
        $phaseStats['phase1_fast_scrape'] = $phase1Count;
        $this->logger->info('[AggressiveDiscovery] Phase 1 (fast scrape): {n} candidates', ['n' => $phase1Count]);

        // ── Phase 2: Gemini AI extraction from scraped HTML (FREE) ──
        // Extract contacts from team HTML sections found in Phase 1 using Gemini AI
        $phase2Count = 0;
        if ($scrapeData && $this->geminiExtractor->isConfigured()) {
            try {
                $geminiResults = $this->extractViaGemini($scrapeData, $companyName, $domain);
                if (!empty($geminiResults)) {
                    $candidates = array_merge($candidates, $geminiResults);
                    $sourcesUsed[] = 'gemini_html';
                    $phase2Count = count($geminiResults);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[AggressiveDiscovery] Phase 2 Gemini failed: {msg}', ['msg' => $e->getMessage()]);
            }
        }
        $phaseStats['phase2_gemini_extract'] = $phase2Count;
        $this->logger->info('[AggressiveDiscovery] Phase 2 (Gemini extraction): {n} candidates', ['n' => $phase2Count]);

        // ── Phase 3: Gemini enrichment of existing candidates (FREE) ──
        $phase3Count = 0;
        if (!empty($candidates) && $this->geminiExtractor->isConfigured()) {
            try {
                $beforeCount = count($candidates);
                $candidates = $this->geminiExtractor->enrichCandidates($candidates, $companyName);
                $enrichedCount = 0;
                foreach ($candidates as $c) {
                    if (str_contains($c['_source'] ?? '', 'gemini')) {
                        $enrichedCount++;
                    }
                }
                $phase3Count = $enrichedCount;
                if ($enrichedCount > 0) {
                    $sourcesUsed[] = 'gemini_enrich';
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[AggressiveDiscovery] Phase 3 enrichment failed: {msg}', ['msg' => $e->getMessage()]);
            }
        }
        $phaseStats['phase3_gemini_enrich'] = $phase3Count;

        // ── Phase 4: Google → LinkedIn (PAID, targeted) ──
        // Only if we have < 3 contacts from scraping, to save API costs
        $phase4Count = 0;
        if (!$skipGoogle && count($this->getUniqueByName($candidates)) < 3) {
            try {
                $linkedinResults = $this->phase4GoogleLinkedIn($companyName, $domain);
                if (!empty($linkedinResults)) {
                    $candidates = array_merge($candidates, $linkedinResults);
                    $sourcesUsed[] = 'google_linkedin';
                    $phase4Count = count($linkedinResults);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[AggressiveDiscovery] Phase 4 failed: {msg}', ['msg' => $e->getMessage()]);
            }
        }
        $phaseStats['phase4_google_linkedin'] = $phase4Count;
        if ($phase4Count > 0) {
            $this->logger->info('[AggressiveDiscovery] Phase 4 (Google LinkedIn): {n} candidates', ['n' => $phase4Count]);
        }

        // ── Phase 5: Google → email discovery (PAID, 1 query) ──
        $phase5Count = 0;
        if (!$skipGoogle && $domain) {
            try {
                $emailResults = $this->phase5GoogleEmails($companyName, $domain);
                if (!empty($emailResults)) {
                    $candidates = array_merge($candidates, $emailResults);
                    $sourcesUsed[] = 'google_email';
                    $phase5Count = count($emailResults);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[AggressiveDiscovery] Phase 5 failed: {msg}', ['msg' => $e->getMessage()]);
            }
        }
        $phaseStats['phase5_google_email'] = $phase5Count;

        // ── Validate all candidates ──
        $candidates = $this->validateCandidates($candidates, $companyName);
        $phaseStats['post_validation'] = count($candidates);

        // ── Persist ──
        $result = $this->persistValidContacts($candidates, $company, $domain, $maxContacts);
        $result['sources'] = array_unique($sourcesUsed);
        $result['phase_stats'] = $phaseStats;

        $this->logger->info('[AggressiveDiscovery] ══════ Completed for {company}: created={c}, updated={u}, skipped={s} ══════', [
            'company' => $companyName,
            'c' => $result['created'],
            'u' => $result['updated'],
            's' => $result['skipped'],
        ]);

        return $result;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Phase 1 — Process scraped data into contact candidates
    // ──────────────────────────────────────────────────────────────────

    /**
     * Convert FastWebScraperService output into contact candidate arrays.
     * Extracts: DOM contact cards, LinkedIn URLs, mailto emails.
     */
    private function processScrapedData(array $data, string $companyName, ?string $domain): array
    {
        $candidates = [];
        $pages = $data['pages'] ?? [];

        foreach ($pages as $page) {
            // DOM-extracted contacts
            foreach ($page['contacts'] ?? [] as $c) {
                if (!empty($c['first_name']) && !empty($c['last_name'])) {
                    $c['_source'] = 'fast_scrape_dom';
                    $candidates[] = $c;
                }
            }

            // LinkedIn URLs with names
            foreach ($page['linkedinUrls'] ?? [] as $li) {
                if (!empty($li['text']) && str_contains($li['url'] ?? '', 'linkedin.com/in/')) {
                    $nameParts = $this->splitName($li['text']);
                    if ($nameParts) {
                        $candidates[] = [
                            'first_name'   => $nameParts['first'],
                            'last_name'    => $nameParts['last'],
                            'job_title'    => null,
                            'email'        => null,
                            'phone'        => null,
                            'linkedin_url' => $li['url'],
                            '_source'      => 'fast_scrape_linkedin',
                        ];
                    }
                }
            }

            // Emails found on page — try to derive name from email
            foreach ($page['emails'] ?? [] as $email) {
                if ($domain && str_ends_with(strtolower($email), '@' . strtolower($domain))) {
                    $nameParts = $this->deriveNameFromEmail($email);
                    if ($nameParts) {
                        $candidates[] = [
                            'first_name'   => $nameParts['first_name'],
                            'last_name'    => $nameParts['last_name'],
                            'job_title'    => null,
                            'email'        => $email,
                            'phone'        => null,
                            'linkedin_url' => null,
                            '_source'      => 'fast_scrape_email',
                        ];
                    }
                }
            }
        }

        return $candidates;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Phase 2 — Gemini AI extraction from scraped HTML
    // ──────────────────────────────────────────────────────────────────

    /**
     * Feed team HTML sections and JSON-LD data to Gemini for AI extraction.
     */
    private function extractViaGemini(array $data, string $companyName, ?string $domain): array
    {
        $candidates = [];
        $pages = $data['pages'] ?? [];

        foreach ($pages as $page) {
            // Team HTML → Gemini
            $teamHtml = $page['teamHtml'] ?? '';
            if (strlen($teamHtml) > 100) {
                try {
                    $geminiContacts = $this->geminiExtractor->extractContactsFromHtml(
                        $teamHtml,
                        $companyName,
                        $domain
                    );
                    foreach ($geminiContacts as $gc) {
                        $gc['_source'] = 'gemini_html';
                        $candidates[] = $gc;
                    }
                } catch (\Throwable $e) {
                    $this->logger->debug('[AggressiveDiscovery] Gemini HTML extraction failed: {msg}', ['msg' => $e->getMessage()]);
                }
            }

            // JSON-LD → Gemini
            $jsonLd = $page['jsonLd'] ?? [];
            if (!empty($jsonLd)) {
                $jsonLdText = json_encode($jsonLd, JSON_UNESCAPED_UNICODE);
                if (strlen($jsonLdText) > 50) {
                    try {
                        $geminiContacts = $this->geminiExtractor->extractContactsFromText(
                            $jsonLdText,
                            $companyName,
                            $domain
                        );
                        foreach ($geminiContacts as $gc) {
                            $gc['_source'] = 'gemini_jsonld';
                            $candidates[] = $gc;
                        }
                    } catch (\Throwable $e) {
                        // Non-critical
                    }
                }
            }
        }

        return $candidates;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Phase 4 — Google → LinkedIn (PAID, targeted)
    // ──────────────────────────────────────────────────────────────────

    private function phase4GoogleLinkedIn(string $companyName, ?string $domain): array
    {
        $candidates = [];

        // Only 2 role groups instead of 4 (saves 50% API calls vs old approach)
        foreach (self::ROLE_SEARCH_GROUPS as $groupKey => $roleKeywords) {
            $roleString = implode(' OR ', array_map(fn($r) => '"' . $r . '"', $roleKeywords));
            $query = sprintf('site:linkedin.com/in/ "%s" (%s)', $companyName, $roleString);

            try {
                $searchResult = $this->googleSearchService->searchCompanies($query, 10);
                $items = $searchResult['results'] ?? [];

                foreach ($items as $item) {
                    $parsed = $this->parseLinkedInResult($item, $companyName);
                    if ($parsed) {
                        $parsed['_source'] = 'linkedin_' . $groupKey;
                        $candidates[] = $parsed;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->debug('[AggressiveDiscovery] LinkedIn search for "{group}" failed: {msg}', [
                    'group' => $groupKey,
                    'msg' => $e->getMessage(),
                ]);
            }

            usleep(100_000); // 100ms rate limiting (paid API has high quota)
        }

        return $candidates;
    }

    /**
     * Parse a Google result pointing to a LinkedIn profile.
     * Format: "John Smith - Procurement Manager - ACME Corp | LinkedIn"
     */
    private function parseLinkedInResult(array $item, string $companyName): ?array
    {
        $title   = $item['title'] ?? '';
        $link    = $item['link'] ?? ($item['url'] ?? '');
        $snippet = $item['snippet'] ?? '';

        if (!str_contains($link, 'linkedin.com/in/')) {
            return null;
        }

        // Parse title
        $titleCleaned = preg_replace('/\s*\|\s*LinkedIn$/i', '', $title);
        $parts = array_map('trim', explode(' - ', $titleCleaned));
        if (count($parts) < 2) {
            $parts = array_map('trim', preg_split('/\s*[–—]\s*/', $titleCleaned));
        }

        $fullName = $parts[0] ?? '';
        $jobTitle = $parts[1] ?? '';
        $listedCompany = $parts[2] ?? '';
        $listedCompany = preg_replace('/\s*\|.*$/', '', $listedCompany);

        // Verify company association
        $companyLower = mb_strtolower($companyName);
        $matchesCompany = false;
        foreach ([$listedCompany, $snippet, $title] as $haystack) {
            if ($this->fuzzyCompanyMatch($companyLower, mb_strtolower($haystack))) {
                $matchesCompany = true;
                break;
            }
        }

        if (!$matchesCompany) {
            return null;
        }

        $nameParts = $this->splitName($fullName);
        if (!$nameParts) {
            return null;
        }

        return [
            'first_name'   => $nameParts['first'],
            'last_name'    => $nameParts['last'],
            'job_title'    => $jobTitle ? $this->cleanLinkedInTitle($jobTitle, $companyName) : null,
            'linkedin_url' => $link,
            'email'        => null,
            'phone'        => null,
        ];
    }

    /**
     * Clean LinkedIn-style job titles:
     * "Supply Chain Manager at ABB" → "Supply Chain Manager"
     * "VP Procurement, ACME Corp" → "VP Procurement"
     */
    private function cleanLinkedInTitle(string $title, string $companyName): ?string
    {
        // Remove "at CompanyName" suffix
        $title = preg_replace('/\s+at\s+.{3,}$/i', '', $title);
        // Remove "@ CompanyName" suffix
        $title = preg_replace('/\s*@\s+.{3,}$/i', '', $title);
        // Remove ", CompanyName" if it matches company
        if (!empty($companyName)) {
            $title = preg_replace('/,\s*' . preg_quote($companyName, '/') . '.*$/i', '', $title);
        }
        // Remove "| LinkedIn" remnants
        $title = preg_replace('/\s*\|.*$/i', '', $title);
        // Remove trailing "at ..."
        $title = preg_replace('/\s+at\s*\.\.\.\s*$/i', '', $title);
        // Remove trailing "..." (LinkedIn truncation)
        $title = preg_replace('/\s*\.{3,}\s*$/', '', $title);
        
        $title = trim($title);
        
        // Reject titles that are actually personal taglines/bios (too long, contain first-person language)
        if (strlen($title) > 80) {
            return null;
        }
        if (preg_match('/\b(I |I\'m |my |love |strive|passionate|helping|dedicated|driven)\b/i', $title)) {
            return null;
        }
        // Reject if it's just a credential abbreviation (PE, CPA, etc.) with no actual title
        if (preg_match('/^[A-Z]{1,4}$/', $title)) {
            return null;
        }
        
        return $title ?: null;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Phase 5 — Google → email discovery (PAID, 1 query)
    // ──────────────────────────────────────────────────────────────────

    private function phase5GoogleEmails(string $companyName, string $domain): array
    {
        $candidates = [];

        $query = sprintf('"%s" "@%s"', $companyName, $domain);

        try {
            $searchResult = $this->googleSearchService->searchCompanies($query, 10);
            $items = $searchResult['results'] ?? [];

            foreach ($items as $item) {
                $text = ($item['title'] ?? '') . ' ' . ($item['snippet'] ?? '');
                $emails = $this->extractPersonEmails($text, $domain);

                foreach ($emails as $email) {
                    $name = $this->deriveNameFromEmail($email);
                    if ($name) {
                        $candidates[] = [
                            'first_name'   => $name['first'],
                            'last_name'    => $name['last'],
                            'email'        => $email,
                            'job_title'    => null,
                            'phone'        => null,
                            'linkedin_url' => null,
                            '_source'      => 'google_email',
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[AggressiveDiscovery] Email search failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return $candidates;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Validation
    // ──────────────────────────────────────────────────────────────────

    /**
     * Validate all candidates through quality gates.
     * Removes garbage names, deduplicates, and scores.
     */
    private function validateCandidates(array $candidates, string $companyName = ''): array
    {
        $validated = [];
        $companyLower = mb_strtolower($companyName);
        // Build short company keywords for matching (e.g. "340B Health" → ["340b","health"])
        $companyWords = array_filter(preg_split('/[\s,.\-&]+/', $companyLower), fn($w) => strlen($w) >= 3);

        foreach ($candidates as $c) {
            $firstName = trim($c['first_name'] ?? '');
            $lastName  = trim($c['last_name'] ?? '');

            // ── Strip honorifics from first name ──
            // Handle "Dr. Ahmed" → "Ahmed", "Capt.Sumit" → "Sumit", and "Dr." alone → move last_name part
            if (preg_match('/^(Dr|Prof|Eng|Engr|Capt|Col|Lt|Cmdr|Maj|Gen|Sgt|Mr|Mrs|Ms|Miss|Sir|Dame|Br|Fr|Rev|Hj|Ir)\.?\s*(.+)$/i', $firstName, $hm) && strlen($hm[2]) >= 2) {
                $firstName = $hm[2];
            } elseif (preg_match('/^(Dr|Prof|Eng|Engr|Capt|Capt\.|Col|Lt|Cmdr|Maj|Gen|Sgt|Mr|Mrs|Ms|Miss|Sir|Dame|Br|Fr|Rev|Hj|Ir)\.?$/i', $firstName)) {
                // Honorific IS the first name — split lastName into firstName + lastName
                $lastParts = preg_split('/\s+/', $lastName, 2);
                if (count($lastParts) >= 2) {
                    $firstName = $lastParts[0];
                    $lastName = $lastParts[1];
                } else {
                    continue; // Can't salvage this — skip
                }
            }
            $firstName = trim($firstName);

            // ── Strip credential suffixes from last name ──
            // "Mian, CSCP" → "Mian", "Girges, mba, dsc" → "Girges", "Henry, PHR, SHRM-CP" → "Henry"
            $lastName = preg_replace('/[,;]\s*[A-Za-z][A-Za-z.\-]{0,10}(\s*[,;]\s*[A-Za-z][A-Za-z.\-]{0,10})*\s*$/', '', $lastName);
            // Also strip space+ALL-CAPS suffix: "Khan MBA" → "Khan"
            $lastName = preg_replace('/\s+[A-Z]{2,6}[®™]?$/', '', $lastName);
            // Strip common credential patterns case-insensitively (with optional ® or ™)
            $lastName = preg_replace('/[,;]\s*(mba|phd|dsc|bsc|msc|cpa|cfa|pe|pmp|cscp|pgdm|shrm-cp|phr|rph|mha|cae|cmp|des|mni)[®™]?\b.*/i', '', $lastName);
            // Strip trailing credential with ® symbol: "Svanidze PMP®" → "Svanidze"
            $lastName = preg_replace('/\s+\w{2,6}[®™]\s*$/', '', $lastName);
            $lastName = trim($lastName);

            $fullName  = trim("$firstName $lastName");

            // Must have both first and last name
            if (empty($firstName) || empty($lastName)) {
                continue;
            }

            // Reject common non-person word pairs that slip through the classifier
            $rejectPairs = ['data protection', 'customer service', 'human resources', 'public relations',
                'supply chain', 'project management', 'quality assurance', 'information technology',
                'business development', 'corporate communications', 'technical support', 'legal department',
                'general enquiries', 'media relations', 'investor relations', 'press office',
                'contact us', 'get touch', 'learn more', 'read more', 'find out', 'click here',
                'first middle', 'first last', 'your name', 'full name', 'john doe', 'jane doe',
                'test test', 'sample user', 'example name', 'no name',
                // Brand/product names that look like person names
                'land rover', 'rolls royce', 'aston martin', 'range rover', 'alfa romeo',
                'monte carlo', 'grand prix', 'formula one',
                // Multi-word non-person compound names
                'design support', 'design team', 'sales team', 'tech support', 'help desk',
                'front desk', 'main office', 'head office', 'call center', 'service center'];
            $fullNameLower = mb_strtolower($fullName);
            $isRejectPair = false;
            foreach ($rejectPairs as $rp) {
                if (str_contains($fullNameLower, $rp)) {
                    $isRejectPair = true;
                    break;
                }
            }
            if ($isRejectPair) {
                $this->logger->debug('[AggressiveDiscovery] Rejected non-person phrase: {name}', ['name' => $fullName]);
                continue;
            }

            // Reject if name is actually a company/garbage name
            if ($this->classifierService->isLikelyPersonName($firstName, $lastName)) {
                // isLikelyPersonName returns TRUE if it IS a person name → keep it
            } else {
                $this->logger->debug('[AggressiveDiscovery] Rejected non-person name: {name}', ['name' => $fullName]);
                continue;
            }

            // Reject names that are too short (single chars)
            if (strlen($firstName) < 2 || strlen($lastName) < 2) {
                // Exception: initials like "J. Smith"
                if (!preg_match('/^[A-Z]\.$/', $firstName)) {
                    continue;
                }
            }

            // Reject names containing conjunctions/prepositions that indicate compound references
            $nameWords = preg_split('/\s+/', $fullName);
            $conjunctions = ['and', 'or', 'the', 'for', 'with', 'from', 'this', 'that', 'our', 'their'];
            $hasConjunction = false;
            foreach ($nameWords as $w) {
                if (in_array(strtolower($w), $conjunctions, true)) {
                    $hasConjunction = true;
                    break;
                }
            }
            if ($hasConjunction) {
                $this->logger->debug('[AggressiveDiscovery] Rejected conjunction name: {name}', ['name' => $fullName]);
                continue;
            }

            // Auto-capitalize names if all lowercase: "victoria" → "Victoria"
            if ($firstName === mb_strtolower($firstName)) {
                $firstName = mb_convert_case($firstName, MB_CASE_TITLE);
            }
            if ($lastName === mb_strtolower($lastName)) {
                $lastName = mb_convert_case($lastName, MB_CASE_TITLE);
            }

            // Store cleaned names back
            $c['first_name'] = $firstName;
            $c['last_name'] = $lastName;

            // ══════ Comprehensive job title cleaning ══════
            if (!empty($c['job_title'])) {
                $title = trim($c['job_title']);

                // Strip bullet/special chars decorations: "Engineer • Node.js" → "Engineer"
                $title = preg_replace('/\s*[•·|]\s+.*$/', '', $title);
                // Strip "@CompanyName" or "@ CompanyName"
                $title = preg_replace('/\s*@\s*.{3,}$/i', '', $title);
                // Strip "at CompanyName" / "en CompanyName" / "w CompanyName" / "bei CompanyName" suffix
                $title = preg_replace('/\s+(?:at|en|bei|chez|w)\s+.{3,}$/i', '', $title);
                // Strip trailing "..." (LinkedIn truncation)
                $title = preg_replace('/\s*\.{3,}\s*$/', '', $title);
                // Strip trailing comma/dash fragments: "Director of Operations," → "Director of Operations"
                $title = rtrim($title, ' ,-;');
                $title = trim($title);

                $titleLower = mb_strtolower($title);
                $rejected = false;

                // 1. Too long → tagline/bio, not a title
                if (strlen($title) > 80) {
                    $rejected = true;
                }
                // 2. Contains first-person language or self-promotional keywords → tagline
                elseif (preg_match('/\b(I |I\'m |my |love to|strive|passionate|helping|dedicated|driven|believe|aspiring|available to|looking for|seeking|open to|enthusiast)\b/i', $title)) {
                    $rejected = true;
                }
                // 3. Exact literal "LinkedIn"
                elseif ($titleLower === 'linkedin') {
                    $rejected = true;
                }
                // 4. Inactive/retired/former
                elseif (preg_match('/^(Retired|Former|Ex-|Inactive|Student|Intern$)/i', $title)) {
                    $rejected = true;
                }
                // 5. Just a credential abbreviation (PE, CPA, MBA, PGDM, etc.)
                elseif (preg_match('/^[A-Z]{1,6}$/', $title) || preg_match('/^(PGDM|MBA|PhD|BSc|MSc|BBA|MCA)$/i', $title)) {
                    $rejected = true;
                }
                // 6. Title matches the company name (case-insensitive fuzzy)
                elseif ($companyLower && ($titleLower === $companyLower || $this->fuzzyCompanyMatch($companyLower, $titleLower))) {
                    $rejected = true;
                }
                // 7. Single-word non-role titles: "Programmatic", "Wordpress", etc.
                elseif (!str_contains($title, ' ') && !preg_match('/^(CEO|CTO|CFO|COO|CIO|CHRO|CSO|CMO|CPO|SVP|EVP|AVP|VP|Owner|Founder|Partner|Director|Manager|President|Chairman|Chairperson|Principal|Controller|Treasurer|Secretary|Buyer|Analyst|Engineer|Coordinator|Administrator|Supervisor|Specialist|Consultant|Researcher|Architect|Designer|Developer|Accountant|Auditor|Planner|Strategist|Recruiter|Advisor|Lead|Head|Procurement|Purchasing|Logistics|Operations|Sales|Marketing|Finance|Compliance|Legal)$/i', $title)) {
                    $rejected = true;
                }
                // 7b. Multi-word title with NO role keywords at all → likely a company name or random text
                elseif (str_word_count($title) <= 3 
                    && !preg_match('/\b(CEO|CTO|CFO|COO|CIO|CHRO|CSO|CMO|CPO|SVP|EVP|AVP|VP|Director|Manager|President|Chairman|Chief|Officer|Head|Lead|Founder|Owner|Partner|Buyer|Analyst|Engineer|Coordinator|Specialist|Consultant|Advisor|Supervisor|Controller|Secretary|Treasurer|Planner|Architect|Developer|Designer|Recruiter|Researcher|Auditor|Accountant|Administrator|Strategist|Procurement|Purchasing|Logistics|Operations|Sales|Marketing|Finance|Compliance|Legal|Senior|Junior|Executive|Associate|Assistant|Global|Regional|Principal)\b/i', $title)) {
                    $rejected = true;
                }
                // 8. University/institution/company-suffix without role keywords
                elseif (preg_match('/\b(university|college|school|institute|gmbh|ltd|inc|corp|spa|s\.a\.|b\.v\.|s\.r\.l)\b/i', $title)
                    && !preg_match('/\b(director|manager|president|officer|vp|head|lead|chief|founder|engineer|buyer|coordinator|specialist|analyst|professor|dean|provost)\b/i', $title)) {
                    $rejected = true;
                }
                // 9. Page navigation elements
                elseif (preg_match('/^(home|menu|navigation|footer|header|sidebar|click|read more|submit|search|contact us)/i', $title)) {
                    $rejected = true;
                }
                // 10. Title is entirely company keywords (e.g. "6Wresearch" for "6W Research")
                elseif (count($companyWords) > 0) {
                    $titleWordsLower = array_filter(preg_split('/[\s,.\-&]+/', $titleLower), fn($w) => strlen($w) >= 2);
                    $allMatchCompany = true;
                    foreach ($titleWordsLower as $tw) {
                        $matchesAny = false;
                        foreach ($companyWords as $cw) {
                            if (str_contains($tw, $cw) || str_contains($cw, $tw)) {
                                $matchesAny = true;
                                break;
                            }
                        }
                        if (!$matchesAny) {
                            $allMatchCompany = false;
                            break;
                        }
                    }
                    if ($allMatchCompany && count($titleWordsLower) > 0) {
                        $rejected = true;
                    }
                }

                $c['job_title'] = $rejected ? null : $title;
            }

            $validated[] = $c;
        }

        // Deduplicate
        return $this->deduplicateCandidates($validated);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Deduplication
    // ──────────────────────────────────────────────────────────────────

    private function deduplicateCandidates(array $candidates): array
    {
        $buckets = [];

        foreach ($candidates as $c) {
            $nameKey  = mb_strtolower(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')));
            $emailKey = mb_strtolower($c['email'] ?? '');

            $bucketKey = null;
            if ($nameKey && isset($buckets[$nameKey])) {
                $bucketKey = $nameKey;
            } elseif ($emailKey && isset($buckets['email:' . $emailKey])) {
                $bucketKey = 'email:' . $emailKey;
            }

            if ($bucketKey !== null) {
                $existing = &$buckets[$bucketKey];
                foreach (['email', 'phone', 'job_title', 'linkedin_url'] as $field) {
                    if (empty($existing[$field]) && !empty($c[$field])) {
                        $existing[$field] = $c[$field];
                    }
                }
                $existing['_source'] = ($existing['_source'] ?? '') . '+' . ($c['_source'] ?? '');
                unset($existing);
            } else {
                $key = $nameKey ?: ('email:' . $emailKey);
                if ($key) {
                    $buckets[$key] = $c;
                    if ($emailKey) {
                        $buckets['email:' . $emailKey] = &$buckets[$key];
                    }
                }
            }
        }

        $result = [];
        $seen = [];
        foreach ($buckets as $key => $entry) {
            if (str_starts_with($key, 'email:')) {
                continue;
            }
            $id = ($entry['first_name'] ?? '') . '|' . ($entry['last_name'] ?? '') . '|' . ($entry['email'] ?? '');
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * Get unique candidates by name (for counting distinct people)
     */
    private function getUniqueByName(array $candidates): array
    {
        $seen = [];
        $unique = [];
        foreach ($candidates as $c) {
            $key = mb_strtolower(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')));
            if ($key && !isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $c;
            }
        }
        return $unique;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Persistence
    // ──────────────────────────────────────────────────────────────────

    private function persistValidContacts(array $candidates, Company $company, ?string $domain, int $maxContacts): array
    {
        // Score & sort
        $scored = [];
        foreach ($candidates as $c) {
            $score = $this->deepScrapingService->scoreContactQuality($c, $domain);
            $c['_score'] = $score;
            $scored[] = $c;
        }
        usort($scored, fn($a, $b) => ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0));

        // Take top N
        $top = array_slice($scored, 0, $maxContacts);

        // Load existing contacts for dedup
        $existingContacts = $this->entityManager
            ->getRepository(Contact::class)
            ->findBy(['company' => $company]);

        $existingMap = [];
        foreach ($existingContacts as $ec) {
            $key = mb_strtolower(trim($ec->getFirstName() . ' ' . $ec->getLastName()));
            $existingMap[$key] = $ec;
            if ($ec->getEmail()) {
                $existingMap[mb_strtolower($ec->getEmail())] = $ec;
            }
        }

        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $contacts = [];

        foreach ($top as $c) {
            // Quality gate: minimum score
            if (($c['_score'] ?? 0) < 15) {
                $skipped++;
                continue;
            }

            // Must have a name
            if (empty($c['first_name']) && empty($c['last_name'])) {
                $skipped++;
                continue;
            }

            // Must have at least one reachable identifier
            // RELAXED: Accept contacts with just name + title (score 25) for companies where
            // scraping found team pages but no emails/LinkedIn
            $hasIdentifier = !empty($c['email']) || !empty($c['phone']) || !empty($c['linkedin_url']);
            $hasStrongTitle = !empty($c['job_title']) && $this->deepScrapingService->isDecisionMaker($c);
            
            if (!$hasIdentifier && !$hasStrongTitle) {
                $skipped++;
                continue;
            }

            // Email domain must match company if present
            if (!empty($c['email']) && $domain) {
                $emailDomain = strtolower(explode('@', $c['email'])[1] ?? '');
                if ($emailDomain && $emailDomain !== strtolower($domain)) {
                    $skipped++;
                    continue;
                }
            }

            $nameKey  = mb_strtolower(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')));
            $emailKey = mb_strtolower($c['email'] ?? '');

            $existing = $existingMap[$nameKey] ?? ($emailKey ? ($existingMap[$emailKey] ?? null) : null);

            if ($existing) {
                $wasUpdated = false;
                if (!$existing->getJobTitle() && !empty($c['job_title'])) {
                    $existing->setJobTitle($c['job_title']);
                    $wasUpdated = true;
                }
                if (!$existing->getEmail() && !empty($c['email'])) {
                    $existing->setEmail($c['email']);
                    $wasUpdated = true;
                }
                if (!$existing->getPhone() && !empty($c['phone'])) {
                    $existing->setPhone($c['phone']);
                    $wasUpdated = true;
                }
                if (!$existing->getLinkedinUrl() && !empty($c['linkedin_url'])) {
                    $existing->setLinkedInUrl($c['linkedin_url']);
                    $wasUpdated = true;
                }
                if (!$existing->getRole() && !empty($c['job_title'])) {
                    $existing->setRole($c['job_title']);
                    $wasUpdated = true;
                }
                if ($wasUpdated) {
                    $existing->setUpdatedAt(new \DateTime());
                    $updated++;
                    $contacts[] = $existing;
                }
            } else {
                $isDecisionMaker = $this->deepScrapingService->isDecisionMaker($c);

                $contact = new Contact();
                $contact->setCompany($company);
                $contact->setFirstName($c['first_name'] ?? '');
                $contact->setLastName($c['last_name'] ?? '');
                $contact->setJobTitle($c['job_title'] ?? null);
                $contact->setEmail($c['email'] ?? null);
                $contact->setPhone($c['phone'] ?? null);
                $contact->setLinkedInUrl($c['linkedin_url'] ?? null);
                $contact->setSource('AggressiveDiscovery');
                $contact->setPrimaryContact($isDecisionMaker);
                $contact->setRole($c['job_title'] ?? null);
                $contact->setNotes(sprintf(
                    'Auto-discovered | Score: %d | Sources: %s%s',
                    $c['_score'] ?? 0,
                    $c['_source'] ?? 'unknown',
                    $isDecisionMaker ? ' | ★ Decision Maker' : ''
                ));

                $this->entityManager->persist($contact);
                $created++;
                $contacts[] = $contact;

                $existingMap[$nameKey] = $contact;
                if ($emailKey) {
                    $existingMap[$emailKey] = $contact;
                }
            }
        }

        if ($created > 0 || $updated > 0) {
            $this->entityManager->flush();
        }

        return [
            'created'  => $created,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'contacts' => $contacts,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────────

    private function extractDomain(?string $url): ?string
    {
        if (!$url) return null;
        if (!str_starts_with($url, 'http')) $url = 'https://' . $url;
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return null;
        return preg_replace('/^www\./i', '', $host);
    }

    private function extractPersonEmails(string $text, string $domain): array
    {
        $pattern = '/[a-zA-Z0-9._%+\-]+@' . preg_quote($domain, '/') . '/i';
        preg_match_all($pattern, $text, $matches);

        $emails = array_unique($matches[0] ?? []);

        return array_values(array_filter($emails, function (string $email) {
            $local = strtolower(explode('@', $email)[0]);
            return !in_array($local, self::GENERIC_EMAIL_PREFIXES, true)
                && strlen($local) > 2
                && preg_match('/[a-z]/', $local);
        }));
    }

    private function deriveNameFromEmail(string $email): ?array
    {
        $local = strtolower(explode('@', $email)[0]);

        if (preg_match('/^([a-z]{2,})[\._\-]([a-z]{2,})$/', $local, $m)) {
            return ['first' => ucfirst($m[1]), 'last' => ucfirst($m[2])];
        }
        if (preg_match('/^([a-z])[\._\-]([a-z]{2,})$/', $local, $m)) {
            return ['first' => strtoupper($m[1]) . '.', 'last' => ucfirst($m[2])];
        }
        if (preg_match('/^([a-z]{2,})[\._\-]([a-z])$/', $local, $m)) {
            return ['first' => strtoupper($m[2]) . '.', 'last' => ucfirst($m[1])];
        }

        return null;
    }

    private function splitName(string $fullName): ?array
    {
        $name = trim($fullName);
        if (empty($name)) return null;

        $name = preg_replace('/\s*[–—]\s*.+$/', '', $name);
        $name = preg_replace('/\s+\-\s+\S{3,}.*$/', '', $name);
        $name = preg_replace('/\s*,?\s*(Jr\.?|Sr\.?|III|II|IV|PhD|MD|MBA|CPA|PE|PMP)$/i', '', $name);

        $parts = preg_split('/\s+/', $name);
        if (count($parts) < 2) return null;

        $badWords = ['linkedin', 'profile', 'page', 'company', 'view', 'see', 'more', 'all', 'results'];
        foreach ($parts as $part) {
            if (in_array(strtolower($part), $badWords, true)) return null;
        }

        return [
            'first' => $parts[0],
            'last'  => implode(' ', array_slice($parts, 1)),
        ];
    }

    private function fuzzyCompanyMatch(string $companyLower, string $textLower): bool
    {
        if (empty($companyLower) || empty($textLower)) return false;
        if (str_contains($textLower, $companyLower)) return true;

        $words = preg_split('/[\s\-_]+/', $companyLower);
        $significantWords = array_filter($words, fn($w) => strlen($w) > 2
            && !in_array($w, ['inc', 'ltd', 'llc', 'corp', 'co', 'the', 'gmbh', 'ag', 'sa', 'plc', 'group', 'international'], true));

        if (empty($significantWords)) return false;

        $matchCount = 0;
        foreach ($significantWords as $word) {
            if (str_contains($textLower, $word)) $matchCount++;
        }

        return $matchCount >= max(1, ceil(count($significantWords) * 0.6));
    }
}
