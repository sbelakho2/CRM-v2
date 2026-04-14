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
        // News / media
        'bloomberg.com', 'reuters.com', 'bbc.com', 'bbc.co.uk', 'cnn.com',
        'theguardian.com', 'nytimes.com', 'ft.com', 'wsj.com', 'forbes.com',
        'businessinsider.com', 'techcrunch.com', 'wired.com', 'zdnet.com',
        // Job boards
        'glassdoor.com', 'indeed.com', 'monster.com', 'stepstone.de',
        'jobrapido.com', 'seek.com.au', 'bayt.com',
        // Marketplaces / directories
        'amazon.com', 'alibaba.com', 'aliexpress.com', 'ebay.com',
        'thomasnet.com', 'europages.com', 'kompass.com', 'dnb.com',
        'crunchbase.com', 'zoominfo.com', 'owler.com',
        // Reference / encyclopedia
        'wikipedia.org', 'wikimedia.org', 'wikidata.org',
        // File sharing / code
        'github.com', 'gitlab.com', 'bitbucket.org', 'stackoverflow.com',
        // Search engines
        'google.com', 'bing.com', 'yahoo.com', 'duckduckgo.com',
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

                // Skip if already collected
                if (isset($candidates[$domain])) {
                    continue;
                }

                // Apply prefilters
                if ($this->isBlockedTld($domain) || $this->isBlockedDomain($domain)) {
                    continue;
                }

                $candidates[$domain] = [
                    'domain'     => $domain,
                    'name'       => $this->deriveCompanyName($result),
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
        );

        // Remove trademark symbols
        $title = preg_replace('/[®™©]/u', '', $title);

        $title = trim($title);

        // If the cleaned title is too short, use the display link as fallback
        if (mb_strlen($title) < 2) {
            $title = ucfirst(explode('.', $result->getDisplayLink())[0] ?? '');
        }

        return $title;
    }
}
