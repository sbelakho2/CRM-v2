<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\Contact;
use App\Service\GoogleSearchService;
use App\Service\DeepScrapingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Multi-source contact enrichment service.
 *
 * Strategy:
 *   1. Google Search API → find LinkedIn profiles for decision-maker roles
 *   2. Website scraping  → structured contact extraction (JSON-LD, team pages, vCards)
 *   3. Google Search API → find @domain email patterns
 *   4. Persist valid, deduplicated Contact entities
 */
class ContactEnrichmentService
{
    /**
     * Target procurement / engineering / executive roles ordered by priority.
     */
    private const TARGET_ROLES = [
        'Procurement Manager',
        'Purchasing Manager',
        'Procurement Director',
        'VP Procurement',
        'VP Supply Chain',
        'Chief Procurement Officer',
        'Commodity Manager',
        'Buyer',
        'Senior Buyer',
        'Strategic Sourcing Manager',
        'Supply Chain Manager',
        'Supply Chain Director',
        'Supplier Quality Engineer',
        'Supplier Quality Manager',
        'Category Manager',
        'Vendor Manager',
        'Materials Manager',
        'Engineering Manager',
        'Engineering Director',
        'VP Engineering',
        'CTO',
        'Chief Technology Officer',
        'Director of Engineering',
        'R&D Manager',
        'Design Engineer',
        'Project Manager',
        'Program Manager',
        'Operations Manager',
        'Operations Director',
        'VP Operations',
        'COO',
        'Chief Operating Officer',
        'Plant Manager',
        'General Manager',
        'Managing Director',
        'CEO',
        'Chief Executive Officer',
        'President',
        'CFO',
        'Chief Financial Officer',
        'VP Sales',
        'Sales Director',
    ];

    /**
     * Grouped role searches to limit API calls.
     */
    private const ROLE_SEARCH_GROUPS = [
        'procurement' => ['Procurement', 'Purchasing', 'Buyer', 'Sourcing', 'Commodity Manager'],
        'engineering'  => ['Engineering', 'CTO', 'R&D', 'Design Engineer', 'Technical Director'],
        'executive'    => ['CEO', 'COO', 'President', 'Managing Director', 'General Manager', 'VP Operations'],
        'supply_chain' => ['Supply Chain', 'Materials Manager', 'Supplier Quality', 'Vendor Manager'],
    ];

    public function __construct(
        private GoogleSearchService     $googleSearchService,
        private DeepScrapingService     $deepScrapingService,
        private EntityManagerInterface  $entityManager,
        private LoggerInterface         $logger,
    ) {}

    // ──────────────────────────────────────────────────────────────────
    //  Public entry point
    // ──────────────────────────────────────────────────────────────────

    /**
     * Enrich contacts for a company using every available source.
     *
     * @return array{created: int, updated: int, skipped: int, contacts: Contact[], sources: string[]}
     */
    public function enrichCompanyContacts(Company $company, int $maxContacts = 10): array
    {
        $companyName = $company->getName();
        $domain      = $this->extractDomain($company->getWebsite());
        $sourcesUsed = [];
        $candidates  = []; // array of structured contact arrays

        $this->logger->info('[ContactEnrichment] Starting enrichment for {company}', [
            'company' => $companyName,
            'domain'  => $domain,
        ]);

        // ── Source 1: Google → LinkedIn profiles for each role group ──
        try {
            $linkedin = $this->searchLinkedInProfiles($companyName, $domain);
            if (!empty($linkedin)) {
                $candidates  = array_merge($candidates, $linkedin);
                $sourcesUsed[] = 'google_linkedin';
                $this->logger->info('[ContactEnrichment] LinkedIn search found {n} candidates', ['n' => count($linkedin)]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[ContactEnrichment] LinkedIn search failed: {msg}', ['msg' => $e->getMessage()]);
        }

        // ── Source 2: Scrape company website ──
        if ($domain) {
            try {
                $scraped = $this->scrapeWebsiteContacts($company);
                if (!empty($scraped)) {
                    $candidates  = array_merge($candidates, $scraped);
                    $sourcesUsed[] = 'website_scrape';
                    $this->logger->info('[ContactEnrichment] Website scrape found {n} candidates', ['n' => count($scraped)]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[ContactEnrichment] Website scrape failed: {msg}', ['msg' => $e->getMessage()]);
            }
        }

        // ── Source 3: Google → "@domain" email pattern search ──
        if ($domain) {
            try {
                $emailHits = $this->searchGoogleForEmails($companyName, $domain);
                if (!empty($emailHits)) {
                    $candidates  = array_merge($candidates, $emailHits);
                    $sourcesUsed[] = 'google_email';
                    $this->logger->info('[ContactEnrichment] Email search found {n} candidates', ['n' => count($emailHits)]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[ContactEnrichment] Email search failed: {msg}', ['msg' => $e->getMessage()]);
            }
        }

        // ── Persist ──
        $result = $this->persistValidContacts($candidates, $company, $domain, $maxContacts);
        $result['sources'] = $sourcesUsed;

        $this->logger->info('[ContactEnrichment] Completed for {company}: created={c}, updated={u}, skipped={s}', [
            'company' => $companyName,
            'c'       => $result['created'],
            'u'       => $result['updated'],
            's'       => $result['skipped'],
        ]);

        return $result;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Source 1 — Google → LinkedIn
    // ──────────────────────────────────────────────────────────────────

    private function searchLinkedInProfiles(string $companyName, ?string $domain): array
    {
        $candidates = [];

        foreach (self::ROLE_SEARCH_GROUPS as $groupKey => $roleKeywords) {
            $roleString = implode(' OR ', array_map(fn($r) => '"' . $r . '"', $roleKeywords));
            $query = sprintf('site:linkedin.com/in/ "%s" (%s)', $companyName, $roleString);

            try {
                $searchResult = $this->googleSearchService->searchCompanies($query, 10);
                $items = $searchResult['results'] ?? [];

                foreach ($items as $item) {
                    $parsed = $this->parseLinkedInSearchResult($item, $companyName);
                    if ($parsed) {
                        $parsed['_source'] = 'linkedin_' . $groupKey;
                        $candidates[] = $parsed;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->debug('[ContactEnrichment] LinkedIn group "{group}" search error: {msg}', [
                    'group' => $groupKey,
                    'msg'   => $e->getMessage(),
                ]);
            }

            // Small pause between API calls to avoid 429
            usleep(300_000);
        }

        // Also search for team/about pages on the company domain
        if ($domain) {
            try {
                $teamQuery = sprintf('site:%s ("our team" OR "leadership" OR "management team" OR "about us")', $domain);
                $teamResult = $this->googleSearchService->searchCompanies($teamQuery, 5);
                $teamPages = count($teamResult['results'] ?? []);
                if ($teamPages > 0) {
                    $this->logger->info('[ContactEnrichment] Found {n} team/leadership pages on {domain}', [
                        'n'      => $teamPages,
                        'domain' => $domain,
                    ]);
                }
            } catch (\Throwable $e) {
                // Non-critical
            }
        }

        return $candidates;
    }

    /**
     * Parse a Google search result that points to a LinkedIn profile.
     *
     * Typical title format: "John Smith - Procurement Manager - ACME Corp | LinkedIn"
     * Snippet may contain additional role/company info.
     */
    private /**
 * @param array<string|int, mixed> $item
 */
function parseLinkedInSearchResult(array $item, string $companyName): ?array
    {
        $title   = $item['title'] ?? '';
        $link    = $item['link'] ?? ($item['url'] ?? '');
        $snippet = $item['snippet'] ?? '';

        // Must be a LinkedIn profile URL
        if (!str_contains($link, 'linkedin.com/in/')) {
            return null;
        }

        // Parse "FirstName LastName - Title - Company | LinkedIn"
        $titleCleaned = preg_replace('/\s*\|\s*LinkedIn$/i', '', $title);
        $parts = array_map('trim', explode(' - ', $titleCleaned));

        if (count($parts) < 2) {
            // Try alternative format: "FirstName LastName – Title at Company"
            $parts = array_map('trim', preg_split('/\s*[–—]\s*/', $titleCleaned));
        }

        $fullName = $parts[0] ?? '';
        $jobTitle = $parts[1] ?? '';
        $listedCompany = $parts[2] ?? '';

        // Remove "| LinkedIn" remnants from company
        $listedCompany = preg_replace('/\s*\|.*$/', '', $listedCompany);

        // Validate: person must work at the target company (fuzzy match)
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

        // Split full name
        $nameParts = $this->splitName($fullName);
        if (!$nameParts) {
            return null;
        }

        return [
            'first_name'   => $nameParts['first'],
            'last_name'    => $nameParts['last'],
            'job_title'    => $jobTitle ?: null,
            'linkedin_url' => $link,
            'email'        => null,
            'phone'        => null,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Source 2 — Website scraping
    // ──────────────────────────────────────────────────────────────────

    private function scrapeWebsiteContacts(Company $company): array
    {
        $url = $company->getWebsite();
        if (!$url) {
            return [];
        }

        if (!str_starts_with($url, 'http')) {
            $url = 'https://' . $url;
        }

        $scrapeResult = $this->deepScrapingService->scrapeWebsite($url, 5, false);
        $structured   = $scrapeResult['structured_contacts'] ?? [];

        // Normalise keys coming from DeepScrapingService
        return array_map(function (array $c) {
            return [
                'first_name'   => $c['first_name'] ?? null,
                'last_name'    => $c['last_name'] ?? null,
                'job_title'    => $c['job_title'] ?? null,
                'email'        => $c['email'] ?? null,
                'phone'        => $c['phone'] ?? null,
                'linkedin_url' => $c['linkedin_url'] ?? null,
                '_source'      => 'website',
            ];
        }, $structured);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Source 3 — Google → email patterns
    // ──────────────────────────────────────────────────────────────────

    private function searchGoogleForEmails(string $companyName, string $domain): array
    {
        $candidates = [];

        // Search for "@domain.com" emails
        $query = sprintf('"%s" "@%s"', $companyName, $domain);

        try {
            $searchResult = $this->googleSearchService->searchCompanies($query, 10);
            $items = $searchResult['results'] ?? [];

            foreach ($items as $item) {
                $text = ($item['title'] ?? '') . ' ' . ($item['snippet'] ?? '');
                $emails = $this->extractEmailsFromText($text, $domain);

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
            $this->logger->debug('[ContactEnrichment] Email search error: {msg}', ['msg' => $e->getMessage()]);
        }

        return $candidates;
    }

    /**
     * Extract emails from text that match the given domain.
     */
    private function extractEmailsFromText(string $text, string $domain): array
    {
        $pattern = '/[a-zA-Z0-9._%+\-]+@' . preg_quote($domain, '/') . '/i';
        preg_match_all($pattern, $text, $matches);

        $emails = array_unique($matches[0] ?? []);

        // Filter out generic addresses
        $generic = ['info', 'sales', 'contact', 'support', 'admin', 'hr', 'marketing',
                     'webmaster', 'noreply', 'no-reply', 'office', 'careers', 'jobs',
                     'press', 'media', 'general', 'enquiries', 'hello', 'service'];

        return array_values(array_filter($emails, function (string $email) use ($generic) {
            $local = strtolower(explode('@', $email)[0]);
            return !in_array($local, $generic, true)
                && strlen($local) > 2
                && preg_match('/[a-z]/', $local);
        }));
    }

    /**
     * Attempt to derive first/last name from an email local part.
     * Handles: john.smith, jsmith, john_smith, john-smith, smithj
     */
    private function deriveNameFromEmail(string $email): ?array
    {
        $local = strtolower(explode('@', $email)[0]);

        // firstname.lastname or firstname_lastname or firstname-lastname
        if (preg_match('/^([a-z]{2,})[\._\-]([a-z]{2,})$/', $local, $m)) {
            return [
                'first' => ucfirst($m[1]),
                'last'  => ucfirst($m[2]),
            ];
        }

        // lastname.firstinitial or firstinitial.lastname
        if (preg_match('/^([a-z])[\._\-]([a-z]{2,})$/', $local, $m)) {
            return [
                'first' => strtoupper($m[1]) . '.',
                'last'  => ucfirst($m[2]),
            ];
        }
        if (preg_match('/^([a-z]{2,})[\._\-]([a-z])$/', $local, $m)) {
            return [
                'first' => strtoupper($m[2]) . '.',
                'last'  => ucfirst($m[1]),
            ];
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────────
    //  Persistence
    // ──────────────────────────────────────────────────────────────────

    /**
     * Deduplicate, score, and persist contacts.
     *
     * @return array{created: int, updated: int, skipped: int, contacts: Contact[]}
     */
    private /**
 * @param array<string|int, mixed> $candidates
 */
function persistValidContacts(array $candidates, Company $company, ?string $domain, int $maxContacts): array
    {
        // Step 1: deduplicate candidates (merge multi-source data for the same person)
        $merged = $this->deduplicateCandidates($candidates);

        // Step 2: score & sort
        $scored = [];
        foreach ($merged as $c) {
            $score = $this->scoreContact($c, $domain);
            $c['_score'] = $score;
            $scored[] = $c;
        }
        usort($scored, fn($a, $b) => ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0));

        // Step 3: take top N
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
            // Quality gate
            if (($c['_score'] ?? 0) < 25) {
                $skipped++;
                continue;
            }

            // Must have at least a name
            if (empty($c['first_name']) && empty($c['last_name'])) {
                $skipped++;
                continue;
            }

            // Must have at least one reachable identifier (email, phone, or LinkedIn)
            if (empty($c['email']) && empty($c['phone']) && empty($c['linkedin_url'])) {
                $skipped++;
                continue;
            }

            // Email domain must match company if email is present. Compare the
            // registrable (root) domain on both sides so subdomains of the
            // company's own domain (e.g. engineering.example.com) are accepted.
            if (!empty($c['email']) && $domain) {
                $emailDomain = strtolower(explode('@', $c['email'])[1] ?? '');
                if ($emailDomain && !$this->domainsMatch($emailDomain, $domain)) {
                    $skipped++;
                    continue;
                }
            }

            $nameKey  = mb_strtolower(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')));
            $emailKey = mb_strtolower($c['email'] ?? '');

            $existing = $existingMap[$nameKey] ?? ($emailKey ? ($existingMap[$emailKey] ?? null) : null);

            if ($existing) {
                // Update existing contact with any new data
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
                if ($wasUpdated) {
                    $updated++;
                    $contacts[] = $existing;
                }
            } else {
                // Create new Contact entity
                $isDecisionMaker = $this->deepScrapingService->isDecisionMaker($c);

                $contact = new Contact();
                $contact->setCompany($company);
                $contact->setFirstName($c['first_name'] ?? '');
                $contact->setLastName($c['last_name'] ?? '');
                $contact->setJobTitle($c['job_title'] ?? null);
                $contact->setEmail($c['email'] ?? null);
                $contact->setPhone($c['phone'] ?? null);
                $contact->setLinkedInUrl($c['linkedin_url'] ?? null);
                $contact->setSource('WebCrawler Enrichment');
                $contact->setPrimaryContact($isDecisionMaker);
                $contact->setNotes(sprintf(
                    'Auto-enriched | Score: %d | Sources: %s%s',
                    $c['_score'] ?? 0,
                    $c['_source'] ?? 'unknown',
                    $isDecisionMaker ? ' | ★ Decision Maker' : ''
                ));

                $this->entityManager->persist($contact);
                $created++;
                $contacts[] = $contact;

                // Add to dedup map so we don't create duplicates within this batch
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
    //  Deduplication
    // ──────────────────────────────────────────────────────────────────

    /**
     * Merge candidate records that refer to the same person.
     * Uses name + email as dedup keys.
     */
    private /**
 * @param array<string|int, mixed> $candidates
 */
function deduplicateCandidates(array $candidates): array
    {
        $buckets = []; // key → merged record

        foreach ($candidates as $c) {
            $nameKey  = mb_strtolower(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')));
            $emailKey = mb_strtolower($c['email'] ?? '');

            // Find existing bucket by name or email
            $bucketKey = null;
            if ($nameKey && isset($buckets[$nameKey])) {
                $bucketKey = $nameKey;
            } elseif ($emailKey && isset($buckets['email:' . $emailKey])) {
                $bucketKey = 'email:' . $emailKey;
            }

            if ($bucketKey !== null) {
                // Merge: fill in missing fields
                $existing = &$buckets[$bucketKey];
                foreach (['email', 'phone', 'job_title', 'linkedin_url'] as $field) {
                    if (empty($existing[$field]) && !empty($c[$field])) {
                        $existing[$field] = $c[$field];
                    }
                }
                // Combine source tags
                $existing['_source'] = ($existing['_source'] ?? '') . '+' . ($c['_source'] ?? '');
                unset($existing);
            } else {
                // New bucket
                $key = $nameKey ?: ('email:' . $emailKey);
                if ($key) {
                    $buckets[$key] = $c;
                    // Also index by email for cross-source merging
                    if ($emailKey) {
                        $buckets['email:' . $emailKey] = &$buckets[$key];
                    }
                }
            }
        }

        // Remove email-keyed aliases (they're references to name-keyed entries)
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

    // ──────────────────────────────────────────────────────────────────
    //  Scoring
    // ──────────────────────────────────────────────────────────────────

    /**
     * Score a candidate contact 0–100.
     */
    private /**
 * @param array<string|int, mixed> $contact
 */
function scoreContact(array $contact, ?string $companyDomain = null): int
    {
        return $this->deepScrapingService->scoreContactQuality($contact, $companyDomain);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────────

    private function extractDomain(?string $url): ?string
    {
        if (!$url) {
            return null;
        }
        if (!str_starts_with($url, 'http')) {
            $url = 'https://' . $url;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return null;
        }
        // Strip www.
        return preg_replace('/^www\./i', '', $host);
    }

    /**
     * Compare two domains at the registrable-root level so that subdomains of
     * the company's own domain are accepted (e.g. engineering.example.com
     * matches example.com), while unrelated domains are rejected.
     */
    private function domainsMatch(string $emailDomain, string $companyDomain): bool
    {
        $emailDomain = strtolower(trim($emailDomain, " \t\n\r\0\x0B."));
        $companyDomain = strtolower(trim($companyDomain, " \t\n\r\0\x0B."));

        if ($emailDomain === '' || $companyDomain === '') {
            return false;
        }

        // Exact match, or a subdomain of the company domain.
        if ($emailDomain === $companyDomain || str_ends_with($emailDomain, '.' . $companyDomain)) {
            return true;
        }

        // Shared registrable root: compare the last two labels of each side.
        return $this->registrableRoot($emailDomain) === $this->registrableRoot($companyDomain)
            && $this->registrableRoot($emailDomain) !== null;
    }

    /**
     * Extract a simple registrable root: the last two labels of a domain,
     * which covers common patterns (co.uk, com.au, etc. get the final three
     * labels when the last label is a well-known second-level suffix).
     */
    private function registrableRoot(string $domain): ?string
    {
        $parts = explode('.', $domain);
        $parts = array_values(array_filter($parts, fn(string $p): bool => $p !== ''));

        if (count($parts) < 2) {
            return null;
        }

        $last = end($parts);
        $secondLast = $parts[count($parts) - 2];

        // ccTLD-with-2nd-level patterns: co.uk, com.au, co.jp, org.uk, ...
        if (strlen($last) === 2 && in_array($secondLast, ['co', 'com', 'org', 'net', 'gov', 'ac', 'edu'], true)) {
            return implode('.', array_slice($parts, -3));
        }

        return implode('.', array_slice($parts, -2));
    }

    /**
     * Fuzzy company name match — checks if company name words appear in text.
     */
    private function fuzzyCompanyMatch(string $companyLower, string $textLower): bool
    {
        if (empty($companyLower) || empty($textLower)) {
            return false;
        }

        // Exact substring
        if (str_contains($textLower, $companyLower)) {
            return true;
        }

        // Check if main words of company name appear (skip short words)
        $words = preg_split('/[\s\-_]+/', $companyLower);
        $significantWords = array_filter($words, fn($w) => strlen($w) > 2
            && !in_array($w, ['inc', 'ltd', 'llc', 'corp', 'co', 'the', 'gmbh', 'ag', 'sa', 'plc', 'group', 'international'], true));

        if (empty($significantWords)) {
            return false;
        }

        $matchCount = 0;
        foreach ($significantWords as $word) {
            if (str_contains($textLower, $word)) {
                $matchCount++;
            }
        }

        // Require at least 60% of significant words to match
        return $matchCount >= max(1, ceil(count($significantWords) * 0.6));
    }

    /**
     * Split "John Smith" or "John Michael Smith" into first/last.
     */
    private function splitName(string $fullName): ?array
    {
        $name = trim($fullName);
        if (empty($name)) {
            return null;
        }

        // Strip everything after em-dash/en-dash/double-hyphen (title or company suffix)
        // e.g. "Tobias Harms – ES-Tec GmbH" → "Tobias Harms"
        $name = preg_replace('/\s*[–—]\s*.+$/', '', $name);

        // Strip " - CompanyName" or " - Title" suffix (but not hyphenated names)
        // Only if what comes after " - " looks like a company/title (3+ chars)
        $name = preg_replace('/\s+\-\s+\S{3,}.*$/', '', $name);

        // Remove common suffixes
        $name = preg_replace('/\s*,?\s*(Jr\.?|Sr\.?|III|II|IV|PhD|MD|MBA|CPA|PE|PMP)$/i', '', $name);

        $parts = preg_split('/\s+/', $name);
        if (count($parts) < 2) {
            return null; // Need at least first + last
        }

        // Validate it looks like a real name (not a company or generic term)
        foreach ($parts as $part) {
            if (strlen($part) < 1) {
                return null;
            }
        }

        // Common non-name words
        $badWords = ['linkedin', 'profile', 'page', 'company', 'view', 'see', 'more', 'all', 'results'];
        foreach ($parts as $part) {
            if (in_array(strtolower($part), $badWords, true)) {
                return null;
            }
        }

        return [
            'first' => $parts[0],
            'last'  => implode(' ', array_slice($parts, 1)),
        ];
    }
}
