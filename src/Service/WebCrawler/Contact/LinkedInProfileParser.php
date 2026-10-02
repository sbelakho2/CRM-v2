<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Contact;

/**
 * Dedicated LinkedIn profile parser that extracts maximum structured data
 * from LinkedIn URLs, page titles, and Google snippets.
 *
 * Handles:
 *  - Profile titles ("John Smith - Procurement Manager at ACME | LinkedIn")
 *  - URL slugs (linkedin.com/in/john-smith-12345)
 *  - Google snippet text ("John Smith — Senior Buyer at ACME Corp · Berlin")
 *  - Company page URLs (linkedin.com/company/acme-corp)
 *  - Credential stripping (PMP, MBA, PhD, etc.)
 *  - Multi-language title detection (French, German, etc.)
 *  - Region extraction from snippet locations
 */
final class LinkedInProfileParser
{
    // ──────────────────────────────────────────────────
    // Constants
    // ──────────────────────────────────────────────────

    /**
     * Credential/certification suffixes that should be stripped from names.
     */
    private const CREDENTIAL_PATTERNS = [
        // Strip everything after first comma if it's all credential abbreviations
        // e.g. "Ramzi Bejaoui, PMP®, PMI-RMP®" → "Ramzi Bejaoui"
        '/,\s*(?:[A-Z][A-Z®™.\-]{0,15}[\s,]*)+$/u',

        // Standalone credential at end (no comma): "Name PMP" or "Name Dipl.-Ing"
        '/\s+(?:PMP|MBA|PhD|PE|MS|MSc|BSc|CPIM|CSCP|LEED|ITIL|PRINCE2|'
        . 'Dipl\.\-?Ing|Ing\.?|Engr\.?|Dott\.?|Dr\.?)[®™]*$/iu',
    ];

    /**
     * Junk "job titles" that LinkedIn sometimes returns instead of real titles.
     */
    private const JUNK_TITLES = [
        'english', 'french', 'arabic', 'spanish', 'german', 'italian', 'portuguese',
        'chinese', 'japanese', 'korean', 'russian', 'turkish', 'hindi', 'dutch',
        'swedish', 'danish', 'norwegian', 'finnish', 'polish', 'czech', 'hungarian',
        'romanian', 'bulgarian', 'greek', 'hebrew', 'persian', 'thai', 'vietnamese',
        'indonesia', 'malay', 'filipino', 'swahili', 'urdu', 'bengali', 'tamil',
        'location', 'see more', 'view profile', 'see all', 'more', 'about',
        'skills', 'experience', 'education', 'summary', 'overview', 'bio',
        'connections', 'followers', 'following', 'posts', 'articles',
        'linkedin', 'linkedin member', 'linkedin user',
    ];

    /**
     * Multi-language role keywords that help identify real job titles.
     */
    private const ROLE_KEYWORDS = [
        // English
        'procurement', 'purchasing', 'buyer', 'supply chain', 'sourcing',
        'operations', 'production', 'manufacturing', 'quality', 'engineering',
        'director', 'manager', 'head', 'chief', 'vice president', 'vp',
        'officer', 'president', 'founder', 'owner', 'partner', 'lead',
        'coordinator', 'supervisor', 'specialist', 'analyst', 'consultant',
        'ceo', 'cto', 'cfo', 'coo', 'cpo', 'cso', 'cio', 'cmo',
        // French
        'directeur', 'responsable', 'chef', 'gérant', 'achats', 'acheteur',
        'approvisionnement', 'direction', 'pdg', 'dg',
        // German
        'leiter', 'einkauf', 'einkäufer', 'geschäftsführer', 'vorstand',
        'abteilungsleiter', 'bereichsleiter', 'prokurist',
        // Italian
        'direttore', 'responsabile', 'acquisti', 'compratore',
        'amministratore', 'delegato',
        // Spanish
        'director', 'gerente', 'jefe', 'compras', 'adquisiciones',
    ];

    /**
     * Priority scoring for role relevance (EMS buyer targeting).
     */
    private const ROLE_PRIORITY = [
        // High priority: procurement decision-makers
        'procurement' => 100, 'purchasing' => 100, 'buyer' => 95, 'acheteur' => 100,
        'einkauf' => 100, 'einkäufer' => 100, 'achats' => 100, 'acquisti' => 100,
        'compras' => 100, 'sourcing' => 90, 'supply chain' => 85,
        'approvisionnement' => 90,

        // Medium-high: engineering/production leadership
        'engineering' => 70, 'production' => 70, 'manufacturing' => 70,
        'operations' => 65, 'quality' => 60, 'r&d' => 60, 'design' => 55,

        // Medium: general leadership
        'director' => 50, 'manager' => 45, 'head' => 50, 'lead' => 40,
        'vp' => 55, 'vice president' => 55, 'chief' => 60,
        'directeur' => 50, 'responsable' => 45, 'leiter' => 50,
        'direttore' => 50, 'gerente' => 45,

        // Lower: C-suite (usually not the direct buyer)
        'ceo' => 35, 'cto' => 40, 'cfo' => 30, 'coo' => 40,
        'founder' => 30, 'owner' => 30, 'president' => 35,
        'geschäftsführer' => 35, 'pdg' => 35, 'gérant' => 30,
        'amministratore' => 35,
    ];

    // ──────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────

    /**
     * Parse a LinkedIn profile from a Google search result.
     *
     * @param string $url    LinkedIn profile URL (must contain /in/)
     * @param string $title  Google result title
     * @param string $snippet Google result snippet
     *
     * @return array<string, mixed>|null Parsed contact or null if unparseable.
     *   Keys: first_name, last_name, job_title, linkedin_url, company_mentioned,
     *         location, role_score, source_method
     */
    public function parseProfile(string $url, string $title, string $snippet): ?array
    {
        if (!str_contains($url, 'linkedin.com/in/')) {
            return null;
        }

        // Strategy 1: Parse the page title (most reliable)
        $result = $this->parseTitle($title);

        // Strategy 2: Fall back to URL slug
        if ($result === null) {
            $result = $this->parseSlug($url);
        }

        if ($result === null) {
            return null;
        }

        // Enrich from snippet
        $result = $this->enrichFromSnippet($result, $snippet);

        // Add LinkedIn URL
        $result['linkedin_url'] = $this->normalizeLinkedInUrl($url);
        $result['source_method'] = isset($result['source_method']) ? $result['source_method'] : 'title_parse';

        // Compute role relevance score
        $jobTitle = $result['job_title'] ?? '';
        $result['role_score'] = $this->computeRoleScore(is_string($jobTitle) ? $jobTitle : '');

        return $result;
    }

    /**
     * Parse a LinkedIn company page URL and snippet.
     *
     * @return array<string, mixed>|null Keys: company_name, linkedin_company_url, description, employee_hint
     */
    public function parseCompanyPage(string $url, string $title, string $snippet): ?array
    {
        if (!str_contains($url, 'linkedin.com/company/')) {
            return null;
        }

        $companyName = null;

        // Extract from title: "ACME Corp | LinkedIn" or "ACME Corp - LinkedIn" or "ACME Corp: Overview | LinkedIn"
        $cleaned = preg_replace('/\s*[|·:\-–—]\s*(Overview|LinkedIn|About).*$/i', '', $title) ?? $title;
        $cleaned = trim($cleaned);

        if (mb_strlen($cleaned) >= 2 && mb_strlen($cleaned) <= 120) {
            $companyName = $cleaned;
        }

        // Fallback: extract from URL slug
        if ($companyName === null) {
            if (preg_match('#linkedin\.com/company/([a-z0-9-]+)#i', $url, $m)) {
                $companyName = str_replace('-', ' ', $m[1]);
                $companyName = mb_convert_case($companyName, MB_CASE_TITLE, 'UTF-8');
            }
        }

        if ($companyName === null) {
            return null;
        }

        // Employee hint from snippet ("1,001-5,000 employees", "10K+ employees")
        $employeeHint = null;
        if (preg_match('/(\d[\d,KkMm+\-–\s]*)\s*employees/i', $snippet, $em)) {
            $employeeHint = trim($em[1]);
        }

        return [
            'company_name'         => $companyName,
            'linkedin_company_url' => $this->normalizeLinkedInUrl($url),
            'description'          => $this->cleanSnippet($snippet),
            'employee_hint'        => $employeeHint,
        ];
    }

    /**
     * Extract the best job title from a LinkedIn snippet.
     *
     * Snippets typically look like:
     *   "Senior Buyer at ACME Corp · Berlin, Germany · 500+ connections"
     *   "View John Smith's profile ... Procurement Manager. ACME Corporation."
     */
    public function extractTitleFromSnippet(string $snippet): ?string
    {
        $text = html_entity_decode($snippet, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Pattern 1: "Title at Company"
        if (preg_match('/^([A-Z][^·|]+?)\s+(?:at|chez|bei|presso|en)\s+/iu', $text, $m)) {
            $candidate = trim($m[1]);
            if ($this->looksLikeJobTitle($candidate)) {
                return mb_substr($candidate, 0, 100);
            }
        }

        // Pattern 2: after "profile" marker: "... profile ... Title. Company."
        if (preg_match('/profile[^.]*\.\s*([A-Z][^.]{3,60})\./iu', $text, $m)) {
            $candidate = trim($m[1]);
            if ($this->looksLikeJobTitle($candidate)) {
                return mb_substr($candidate, 0, 100);
            }
        }

        // Pattern 3: Known role keywords anywhere
        foreach (self::ROLE_KEYWORDS as $kw) {
            if (preg_match('/\b(' . preg_quote($kw, '/') . '[^·|.]{0,50})/iu', $text, $m)) {
                $candidate = trim($m[1]);
                if (mb_strlen($candidate) >= 3 && mb_strlen($candidate) <= 100) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Extract location from a LinkedIn snippet.
     *
     * Snippets: "John Smith · Berlin, Germany · 500+ connections"
     */
    public function extractLocationFromSnippet(string $snippet): ?string
    {
        // Pattern: "City, Country" or "City, Region, Country" between · separators
        if (preg_match('/·\s*([A-Z][a-zéèêëàâäùûü]+(?:[\s-][A-Z][a-zéèêëàâäùûü]+)*,\s*[A-Za-zéèêëàâäùûü\s]+)\s*·/u', $snippet, $m)) {
            return trim($m[1]);
        }

        // Pattern: "City, Country" at end
        if (preg_match('/·\s*([A-Z][a-zéèêëàâäùûü]+(?:[\s-][A-Z][a-zéèêëàâäùûü]+)*,\s*[A-Za-zéèêëàâäùûü\s]+)$/u', $snippet, $m)) {
            return trim($m[1]);
        }

        // Pattern: standalone "Location · "City, Country" not between ·
        if (preg_match('/[–—-]\s*([A-Z][a-zéèêëàâäùûü]+,\s*[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\s*[–—·|-]/u', $snippet, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Compute a role relevance score (0–100) for EMS buyer targeting.
     */
    public function computeRoleScore(string $jobTitle): int
    {
        if (empty($jobTitle)) {
            return 0;
        }

        $lower = mb_strtolower($jobTitle);
        $bestScore = 0;

        foreach (self::ROLE_PRIORITY as $keyword => $score) {
            if (str_contains($lower, $keyword)) {
                $bestScore = max($bestScore, $score);
            }
        }

        return $bestScore;
    }

    /**
     * Score and rank a list of contacts by role relevance.
     *
     * @param list<array<string|int, mixed>> $contacts
     * @return list<array<string|int, mixed>> Sorted by role_score descending
     */
    public function rankContacts(array $contacts): array
    {
        foreach ($contacts as &$c) {
            if (!isset($c['role_score'])) {
                $jobTitle = $c['job_title'] ?? '';
                $c['role_score'] = $this->computeRoleScore(is_string($jobTitle) ? $jobTitle : '');
            }
        }
        unset($c);

        usort($contacts, fn(array $a, array $b) => ($b['role_score'] ?? 0) <=> ($a['role_score'] ?? 0));

        return $contacts;
    }

    // ──────────────────────────────────────────────────
    // Internal: Title parsing
    // ──────────────────────────────────────────────────

    /**
     * Parse the Google title for a LinkedIn profile page.
     *
     * Formats:
     *   "John Smith - Procurement Manager at ACME | LinkedIn"
     *   "John Smith – Directeur Achats – ACME Corp | LinkedIn"
     *   "Dr. John Smith, MBA - CEO - Company Name | LinkedIn"
     */
    /**
     * @return array<string, mixed>|null
     */
    private function parseTitle(string $title): ?array
    {
        // Strip "| LinkedIn" or "· LinkedIn" suffix
        $cleaned = preg_replace('/\s*[|·]\s*LinkedIn$/i', '', $title) ?? $title;
        $cleaned = trim($cleaned);

        if (mb_strlen($cleaned) < 3) {
            return null;
        }

        // Split on dash/en-dash/em-dash (require spaces around delimiter
        // to avoid splitting compound terms like PMI-RMP)
        $split = preg_split('/\s+[-–—]+\s+/u', $cleaned, 4);
        $parts = $split === false ? [] : array_map('trim', $split);

        if (count($parts) < 1 || mb_strlen($parts[0]) < 2) {
            return null;
        }

        // Part 0 = Full name (possibly with credentials)
        $fullName = $this->stripCredentials($parts[0]);
        $nameParts = $this->splitName($fullName);

        if ($nameParts === null) {
            return null;
        }

        $result = $nameParts;

        // Part 1 = Usually job title (sometimes company)
        if (isset($parts[1]) && mb_strlen($parts[1]) >= 2) {
            $candidate = html_entity_decode($parts[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Handle "Title at Company" within a single part
            if (preg_match('/^(.+?)\s+(?:at|chez|bei|presso|en)\s+(.+)$/iu', $candidate, $atm)) {
                $result['job_title'] = mb_substr(trim($atm[1]), 0, 100);
                $result['company_mentioned'] = trim($atm[2]);
            } elseif ($this->looksLikeJobTitle($candidate)) {
                $result['job_title'] = mb_substr($candidate, 0, 100);
            } else {
                // Might be company name
                $result['company_mentioned'] = $candidate;
            }
        }

        // Part 2 = Company name or additional title context
        if (isset($parts[2]) && mb_strlen($parts[2]) >= 2) {
            $part2 = html_entity_decode($parts[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (!isset($result['company_mentioned'])) {
                $result['company_mentioned'] = $part2;
            } elseif (!isset($result['job_title']) && $this->looksLikeJobTitle($part2)) {
                $result['job_title'] = mb_substr($part2, 0, 100);
            }
        }

        $result['source_method'] = 'title_parse';

        return $result;
    }

    // ──────────────────────────────────────────────────
    // Internal: Slug parsing
    // ──────────────────────────────────────────────────

    /**
     * Parse a LinkedIn URL slug to extract first/last name.
     *
     * Slugs: "john-smith-12345" or "john-smith-a1b2c3"
     * Or: "jeanpierre-dupont-ab12cd34"
     */
    /**
     * @return array<string, mixed>|null
     */
    private function parseSlug(string $url): ?array
    {
        if (!preg_match('#linkedin\.com/in/([a-z0-9-]+)#i', $url, $m)) {
            return null;
        }

        $slug = strtolower($m[1]);

        // Strip trailing hash codes (hex chars, digits)
        $slug = preg_replace('/-[a-f0-9]{6,}$/', '', $slug) ?? $slug;
        $slug = preg_replace('/-\d{3,}$/', '', $slug) ?? $slug;

        $parts = explode('-', $slug);
        $parts = array_filter($parts, fn(string $p) => mb_strlen($p) >= 2 && !is_numeric($p));
        $parts = array_values($parts);

        if (count($parts) < 2) {
            return null;
        }

        $firstName = mb_convert_case($parts[0], MB_CASE_TITLE, 'UTF-8');
        $lastName = mb_convert_case($parts[count($parts) - 1], MB_CASE_TITLE, 'UTF-8');

        // Basic validation
        if (!preg_match('/^[A-ZÀ-ÖØ-Ý][a-zà-öø-ÿ]{1,30}$/u', $firstName)) {
            return null;
        }
        if (!preg_match('/^[A-ZÀ-ÖØ-Ý][a-zà-öø-ÿ]{1,30}$/u', $lastName)) {
            return null;
        }

        return [
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'source_method' => 'slug_parse',
        ];
    }

    // ──────────────────────────────────────────────────
    // Internal: Snippet enrichment
    // ──────────────────────────────────────────────────

    /**
     * Enrich a parsed contact with additional data from the Google snippet.
     * @param array<string, mixed> $contact
     * @return array<string, mixed>
     */
    private function enrichFromSnippet(array $contact, string $snippet): array
    {
        if (empty($snippet)) {
            return $contact;
        }

        // Extract job title if missing
        if (empty($contact['job_title'])) {
            $title = $this->extractTitleFromSnippet($snippet);
            if ($title !== null) {
                $contact['job_title'] = $title;
            }
        }

        // Extract location
        if (empty($contact['location'])) {
            $location = $this->extractLocationFromSnippet($snippet);
            if ($location !== null) {
                $contact['location'] = $location;
            }
        }

        // Extract company mention if missing
        if (empty($contact['company_mentioned'])) {
            // "at Company" or "chez Company"
            if (preg_match('/\b(?:at|chez|bei|presso|en)\s+([A-Z][A-Za-z0-9&\s.-]{2,50}?)(?:\s*[·|,.]|$)/u', $snippet, $cm)) {
                $contact['company_mentioned'] = trim($cm[1]);
            }
        }

        // Extract connection count as a relevance signal
        if (preg_match('/(\d+\+?)\s*connections/i', $snippet, $connM)) {
            $contact['connections'] = $connM[1];
        }

        return $contact;
    }

    // ──────────────────────────────────────────────────
    // Internal: Name utilities
    // ──────────────────────────────────────────────────

    /**
     * Strip credential suffixes from a name string.
     */
    private function stripCredentials(string $name): string
    {
        foreach (self::CREDENTIAL_PATTERNS as $pattern) {
            $name = preg_replace($pattern, '', $name) ?? $name;
        }
        return trim($name, " \t,.");
    }

    /**
     * Split a full name into first_name and last_name.
     * Returns null if the result doesn't look like a person name.
     */
    /**
     * @return array{first_name: string, last_name: string}|null
     */
    private function splitName(string $fullName): ?array
    {
        $fullName = trim($fullName);

        // Strip honorifics
        $fullName = preg_replace('/^(?:Mr\.?|Mrs\.?|Ms\.?|Dr\.?|Prof\.?|Eng\.?|Ing\.?|Dott\.?|Dott\.ssa)\s+/iu', '', $fullName) ?? $fullName;

        $split = preg_split('/\s+/', $fullName);
        $words = $split === false ? [] : array_filter($split, fn(string $w) => mb_strlen($w) >= 1);
        $words = array_values($words);

        if (count($words) < 2) {
            return null;
        }

        $firstName = $words[0];
        $lastName = implode(' ', array_slice($words, 1));

        // Reject if doesn't look like a name (must start with letter)
        if (!preg_match('/^[A-ZÀ-ÖØ-Ý]/u', $firstName)) {
            return null;
        }
        if (!preg_match('/^[A-ZÀ-ÖØ-Ý]/u', $lastName)) {
            return null;
        }

        // Reject obvious non-names
        $lower = mb_strtolower($firstName . ' ' . $lastName);
        $junkNames = [
            'linkedin member', 'linkedin user', 'anonymous user', 'test user',
            'page not found', 'access denied', 'sign in', 'log in',
        ];
        foreach ($junkNames as $junk) {
            if (str_contains($lower, $junk)) {
                return null;
            }
        }

        return [
            'first_name' => $firstName,
            'last_name'  => $lastName,
        ];
    }

    // ──────────────────────────────────────────────────
    // Internal: Helpers
    // ──────────────────────────────────────────────────

    /**
     * Does a string look like a job title rather than a company name?
     */
    private function looksLikeJobTitle(string $candidate): bool
    {
        $lower = mb_strtolower($candidate);

        // Reject junk
        if (in_array($lower, self::JUNK_TITLES, true)) {
            return false;
        }

        // Reject obvious company suffixes
        if (preg_match('/\b(gmbh|ltd|inc|corp|s\.a\.|sarl|sas|llc|plc|ag|se|co\.?\s*kg)\b/i', $candidate)) {
            return false;
        }

        // Check for role keywords
        foreach (self::ROLE_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }

        // Check common title patterns
        if (preg_match('/\b(senior|junior|assistant|associate|intern|trainee|executive)\b/i', $candidate)) {
            return true;
        }

        // Check multi-language purchasing/procurement keywords not in ROLE_KEYWORDS
        if (preg_match('/\b(achats|einkauf|beschaffung|acquisti|compras|inkoop|zakupy|approvisionnement)\b/iu', $candidate)) {
            return true;
        }

        // If it's short and starts with a capital, it might be a title
        if (mb_strlen($candidate) <= 40 && preg_match('/^[A-Z]/', $candidate)) {
            return true;
        }

        return false;
    }

    /**
     * Clean and normalize a LinkedIn URL.
     */
    private function normalizeLinkedInUrl(string $url): string
    {
        // Strip query params and trailing slashes
        $url = preg_replace('/[?#].*$/', '', $url) ?? $url;
        $url = rtrim($url, '/');

        // Ensure https
        if (str_starts_with($url, 'http://')) {
            $url = 'https://' . substr($url, 7);
        }

        return $url;
    }

    /**
     * Clean snippet text: strip HTML, normalize whitespace.
     */
    private function cleanSnippet(string $snippet): string
    {
        $text = html_entity_decode($snippet, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }
}
