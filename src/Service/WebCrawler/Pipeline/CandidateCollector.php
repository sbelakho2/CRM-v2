<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

use App\Service\WebCrawler\SearchProvider\SearchProviderInterface;
use App\Service\WebCrawler\SearchProvider\SearchResult;

/**
 * Collects search results across all queries, deduplicates by root domain,
 * applies cheap prefilters (blocked TLDs, junk domains), and caps output.
 *
 * This replaces the candidate collection loop in GoogleDorkService::searchCompanies()
 * which mixed collection, LLM gating, and verification in a single 400-line loop.
 */
final class CandidateCollector
{
    /**
     * TLDs that are never real prospect companies.
     */
    private const BLOCKED_TLDS = [
        '.gov', '.edu', '.mil', '.int', '.museum',
    ];

    /**
     * TLD suffixes that indicate government or academic domains.
     * Checked as suffix of the full host.
     */
    private const BLOCKED_TLD_SUFFIXES = [
        '.gov.uk', '.gov.ma', '.gov.eg', '.gov.tn', '.gov.fr', '.gov.de', '.gov.it',
        '.gov.es', '.gov.tr', '.gov.pl', '.gov.ro', '.gov.cz', '.gov.hu', '.gov.nl',
        '.gov.be', '.gov.se', '.gov.au', '.gov.ca', '.gov.in', '.gov.br', '.gov.za',
        '.ac.uk', '.ac.at', '.ac.be', '.ac.il', '.ac.jp', '.ac.kr', '.ac.nz',
        '.edu.au', '.edu.eg', '.edu.ma', '.edu.tn', '.edu.tr',
    ];

    /**
     * Root domains that are never valid candidates — social media, news,
     * directories, marketplaces, job boards.
     */
    private const BLOCKED_DOMAINS = [
        // Social media
        'linkedin.com', 'facebook.com', 'twitter.com', 'x.com', 'instagram.com',
        'youtube.com', 'tiktok.com', 'pinterest.com', 'reddit.com',
        'threads.net', 'snapchat.com', 'whatsapp.com', 'telegram.org',
        // News / media
        'bloomberg.com', 'reuters.com', 'bbc.com', 'bbc.co.uk', 'cnn.com',
        'theguardian.com', 'nytimes.com', 'ft.com', 'wsj.com', 'forbes.com',
        'businessinsider.com', 'techcrunch.com', 'wired.com', 'zdnet.com',
        'cnbc.com', 'marketwatch.com', 'economist.com', 'newsweek.com',
        'usatoday.com', 'huffpost.com', 'buzzfeed.com', 'vox.com',
        // Job boards
        'glassdoor.com', 'indeed.com', 'monster.com', 'stepstone.de',
        'jobrapido.com', 'seek.com.au', 'bayt.com',
        'dice.com', 'careerbuilder.com', 'totaljobs.com', 'reed.co.uk',
        // Marketplaces / directories (blocked as crawl targets, not as seed sources)
        'amazon.com', 'alibaba.com', 'aliexpress.com', 'ebay.com',
        'thomasnet.com', 'europages.com', 'kompass.com', 'dnb.com',
        'crunchbase.com', 'zoominfo.com', 'owler.com',
        'mouser.com', 'digikey.com', 'farnell.com', 'rs-online.com',
        'newark.com', 'arrow.com', 'element14.com',
        // Reference / encyclopedia
        'wikipedia.org', 'wikimedia.org', 'wikidata.org',
        'britannica.com', 'investopedia.com',
        // File sharing / code
        'github.com', 'gitlab.com', 'bitbucket.org', 'stackoverflow.com',
        'medium.com', 'blogspot.com', 'wordpress.com', 'tumblr.com',
        'wixsite.com', 'squarespace.com', 'weebly.com',
        // Search engines / portals
        'google.com', 'bing.com', 'yahoo.com', 'duckduckgo.com',
        'baidu.com', 'yandex.com', 'ask.com', 'aol.com',
        // Social / review / rating platforms
        'yelp.com', 'trustpilot.com', 'g2.com', 'capterra.com',
        'glassdoor.co.in', 'sitejabber.com',
        // Patent / IP databases
        'patents.google.com', 'patentscope.wipo.int', 'uspto.gov',
        'espacenet.com', 'freepatentsonline.com',
        // Academic / research
        'researchgate.net', 'academia.edu', 'scholar.google.com',
    ];

    /**
     * Known directory domains that should bypass domain deduplication.
     * These domains host company listings (kerix.net, charika.ma, etc.)
     * where each search result is a DIFFERENT company profile page.
     * We need ALL results, not just the first one, so the DirectorySeedExtractor
     * can extract every company name from their respective titles/snippets.
     */
    private const SEED_DIRECTORY_DOMAINS = [
        'kerix.net', 'charika.ma', 'pagesjaunes.ma', 'telecontact.ma',
        'amica.org.ma', 'yellowpages.com.eg', 'daleel.com.eg',
        'pagesjaunes.com.tn', 'tunisieindustrie.nat.tn',
    ];

    public function __construct(
        private readonly int $maxCandidates = 200,
    ) {
    }

    /**
     * Collect and deduplicate candidates from search results.
     *
     * @param array<int, array{query: string, type: string}> $queries
     */
    public function collect(
        array $queries,
        SearchProviderInterface $provider,
        ?string $region = null,
        ?string $language = null,
    ): CandidateSet
    {
        if (empty($queries)) {
            return new CandidateSet([], []);
        }

        /** @var array<string, array{domain: string, name: string, url: string, title: string, snippet: string, query_type: string}> */
        $candidates = [];
        $queryStats = [];

        foreach ($queries as $queryDef) {
            $queryString = $queryDef['query'];
            $queryType = $queryDef['type'];

            $resultSet = $provider->search($queryString, $region, $language);
            $queryStats[$queryString] = count($resultSet);

            foreach ($resultSet->getResults() as $result) {
                if (count($candidates) >= $this->maxCandidates) {
                    break 2;
                }

                $domain = $result->getRootDomain();
                if ($domain === '') {
                    continue;
                }

                // Determine dedup key:
                // - For known directory domains (kerix.net, charika.ma, etc.), use the FULL URL
                //   so each different company profile page is collected as a separate seed source.
                // - For normal domains, use the root domain to avoid duplicates.
                $dedupKey = $this->isSeedDirectoryDomain($domain)
                    ? $result->getUrl()
                    : $domain;

                // Skip if already collected (by dedup key)
                if (isset($candidates[$dedupKey])) {
                    continue;
                }

                // Apply prefilters
                if ($this->isBlockedTld($domain) || $this->isBlockedDomain($domain)) {
                    continue;
                }

                // Derive company name from search result
                $companyName = $this->deriveCompanyName($result);

                // Filter junk company names early — before wasting crawl resources
                if ($this->isJunkCompanyName($companyName, $result->getSnippet(), $domain)) {
                    continue;
                }

                $candidates[$dedupKey] = [
                    'domain'     => $domain,
                    'name'       => $companyName,
                    'url'        => $result->getUrl(),
                    'title'      => $result->getTitle(),
                    'snippet'    => $result->getSnippet(),
                    'query_type' => $queryType,
                ];
            }
        }

        return new CandidateSet($candidates, $queryStats);
    }

    /**
     * Check if the domain has a blocked TLD (.gov, .edu, .mil, .int, .museum).
     */
    private function isBlockedTld(string $domain): bool
    {
        // Check suffix-based blocks first (more specific: .gov.uk, .ac.uk, etc.)
        foreach (self::BLOCKED_TLD_SUFFIXES as $suffix) {
            if (str_ends_with($domain, $suffix)) {
                return true;
            }
        }

        // Extract TLD from domain (everything after the last dot, or last two parts for compound TLDs)
        $parts = explode('.', $domain);
        $tld = '.' . end($parts);

        foreach (self::BLOCKED_TLDS as $blockedTld) {
            if ($tld === $blockedTld) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the domain is in the blocked domains list.
     */
    private function isBlockedDomain(string $domain): bool
    {
        // Exact match
        if (in_array($domain, self::BLOCKED_DOMAINS, true)) {
            return true;
        }

        // Subdomain match (e.g., news.google.com → google.com is blocked)
        foreach (self::BLOCKED_DOMAINS as $blocked) {
            if (str_ends_with($domain, '.' . $blocked)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the domain is a known seed directory domain.
     * These are directory sites (kerix.net, charika.ma, etc.) where each
     * search result is a DIFFERENT company profile page. We keep all results
     * as separate candidates so the DirectorySeedExtractor can extract every
     * company name from their individual titles/snippets.
     */
    private function isSeedDirectoryDomain(string $domain): bool
    {
        foreach (self::SEED_DIRECTORY_DOMAINS as $seedDomain) {
            if (str_contains($domain, $seedDomain)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Derive a company name from the search result title.
     * Strips common suffixes like " - Wikipedia", " | LinkedIn", " - Home".
     */
    private function deriveCompanyName(SearchResult $result): string
    {
        $title = $result->getTitle();

        // Remove common trailing patterns
        $title = preg_replace(
            '/\s*[\|–—-]\s*(Home|Homepage|Welcome|Official|Website|LinkedIn|Facebook|Twitter|Wikipedia|Glassdoor|Crunchbase|About|Contact|Products|Solutions|Overview).*$/i',
            '',
            $title,
        ) ?? $title;

        // Remove trademark symbols
        $title = preg_replace('/[®™©]/u', '', $title) ?? $title;

        $title = trim($title);

        // If the cleaned title is too short, use the display link as fallback
        if (mb_strlen($title) < 2) {
            $title = ucfirst(explode('.', $result->getDisplayLink())[0]);
        }

        return $title;
    }

    /**
     * Filter out junk/non-company names early to avoid wasting crawl resources.
     * Based on patterns from CompanyDiscoveryService junk name detection.
     */
    private function isJunkCompanyName(string $name, string $snippet, string $domain): bool
    {
        $name = trim($name);
        if ($name === '') {
            return true;
        }

        // Too short to be a real company name
        if (mb_strlen($name) < 3) {
            return true;
        }

        // Too long — likely a title/description, not a company name
        if (mb_strlen($name) > 100) {
            return true;
        }

        // Error/page-not-found indicators
        $errorPatterns = [
            'page not found', '404 not found', 'access denied', 'forbidden',
            'just a moment', 'please wait', 'verify you are human',
            'captcha', 'attention required', 'sorry', 'error',
            'maintenance', 'under construction', 'coming soon',
            'this site can\'t be reached', 'server not found',
            'connection timed out', 'ssl error', 'privacy error',
        ];
        $nameLower = mb_strtolower($name);
        foreach ($errorPatterns as $pattern) {
            if (str_contains($nameLower, $pattern)) {
                return true;
            }
        }

        // Category/directory label patterns — not real company names
        $nonCompanyPatterns = [
            '/^[a-z]+\s+companies\s+in\s+/i',
            '/^top\s+\d+\s+/i',
            '/\blist\s+of\b/i',
            '/\bdirectory\b/i',
            '/^\d+\s+best\b/i',
            '/\bsupplier\s+directory\b/i',
            '/\bmanufacturing\s+companies\b/i',
            '/^manufacturers?\s+in\b/i',
            '/^suppliers?\s+in\b/i',
            '/^factories\s+in\b/i',
        ];
        foreach ($nonCompanyPatterns as $pattern) {
            if (preg_match($pattern, $nameLower) === 1) {
                return true;
            }
        }

        // Domain-only or URL-like names (no real company identifier)
        if (preg_match('/^https?:\/\//i', $name) || preg_match('/^www\./i', $name)) {
            return true;
        }

        // All-caps single word — likely a category, not a company
        if (mb_strlen($name) < 10 && preg_match('/^[A-Z\s]+$/', $name) && !preg_match('/\s/', $name)) {
            // Check snippet and domain for company indicators
            $combined = mb_strtolower($snippet . ' ' . $domain);
            if (!preg_match('/\b(manufacturer|company|corp|inc|ltd|gmbh|sarl|sas|spa)\b/i', $combined)) {
                return true;
            }
        }

        // Excessive special characters — likely junk
        $specialCharCount = preg_match_all('/[^a-zA-Z0-9\s\-.,&()\/]/', $name);
        if ($specialCharCount > 5) {
            return true;
        }

        // Generic navigation/page title patterns
        $genericTitles = [
            'home', 'homepage', 'index', 'default', 'main',
            'products', 'services', 'solutions', 'about us',
            'contact us', 'careers', 'news', 'blog',
            'industries', 'capabilities', 'quality',
            'sign in', 'login', 'register', 'subscribe',
        ];
        if (in_array(mb_strtolower($name), $genericTitles, true)) {
            return true;
        }

        return false;
    }
}
