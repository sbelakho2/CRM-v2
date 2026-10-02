<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Seed;

use App\Service\WebCrawler\Text\TextNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Security\SafeOutboundUrlGuard;

/**
 * Directory Seed Extractor (Improvement 2D)
 *
 * Treats directory websites (Kompass, Europages, ThomasNet, etc.) as
 * SEED SOURCES rather than lead candidates. Instead of blocking directory
 * domains and losing the data, this service:
 *
 *  1. Crawls Google search results from directory sites (using site: dorks)
 *  2. Extracts company names and domains from the directory page HTML
 *  3. Returns DirectorySeed objects for downstream verification
 *
 * These seeds are fed back into the search/classify pipeline —
 * they're hypotheses, not final leads.
 *
 * Known directory domains that are automatically recognized:
 */
final class DirectorySeedExtractor
{
    /**
     * Directory domains → parser profiles.
     * Each profile defines CSS selectors or regex patterns for extracting
     * company names and websites from that directory's HTML.
     */
    private const DIRECTORY_PROFILES = [
        'kompass.com'        => ['name' => 'Kompass',     'nameSelector' => '.product-company-name, h1.company-name, .company-title, .companyName a', 'linkSelector' => 'a[href*="company"]'],
        'europages.com'      => ['name' => 'Europages',   'nameSelector' => '.company-name, .company-title, h2.company-name, .companyName', 'linkSelector' => 'a.company-link, a[href*="company"]'],
        'europages.de'       => ['name' => 'Europages DE', 'nameSelector' => '.company-name, .company-title', 'linkSelector' => 'a.company-link'],
        'europages.fr'       => ['name' => 'Europages FR', 'nameSelector' => '.company-name, .company-title', 'linkSelector' => 'a.company-link'],
        'europages.it'       => ['name' => 'Europages IT', 'nameSelector' => '.company-name, .company-title', 'linkSelector' => 'a.company-link'],
        'europages.es'       => ['name' => 'Europages ES', 'nameSelector' => '.company-name, .company-title', 'linkSelector' => 'a.company-link'],
        'wlw.de'             => ['name' => 'Wer Liefert Was', 'nameSelector' => '.company-name, h2.supplier-name', 'linkSelector' => 'a[href*="supplier"]'],
        'thomasnet.com'      => ['name' => 'ThomasNet',   'nameSelector' => '.company-name, .supplier-name, h2 a', 'linkSelector' => 'a.supplier-link, a[href*="profile"]'],
        'globalspec.com'     => ['name' => 'GlobalSpec',   'nameSelector' => '.supplier-name, h2', 'linkSelector' => 'a[href*="supplier"]'],
        'industrystock.com'  => ['name' => 'IndustryStock','nameSelector' => '.company-name, h2', 'linkSelector' => 'a[href*="company"]'],
        'kerix.net'          => ['name' => 'Kerix',        'nameSelector' => '.company-name, h3, h2', 'linkSelector' => 'a[href*="fiche"]'],
        'directindustry.com' => ['name' => 'DirectIndustry','nameSelector'=> '.product-company, .company-name', 'linkSelector' => 'a[href*="company"]'],
        'make-it.uk'         => ['name' => 'Make It UK',   'nameSelector' => '.member-name, h2', 'linkSelector' => 'a[href*="member"]'],
        // African / MENA directories
        'pagesjaunes.ma'     => ['name' => 'Pages Jaunes Maroc', 'nameSelector' => '.company-name, .companyName, h2.name, .result-title, .bloc-info h2', 'linkSelector' => 'a[href*="entreprise"], a[href*="company"]'],
        'charika.ma'         => ['name' => 'Charika',      'nameSelector' => '.company-name, h1.company, h2, .raison-sociale', 'linkSelector' => 'a[href*="societe"], a[href*="company"]'],
        'telecontact.ma'     => ['name' => 'Telecontact',  'nameSelector' => '.company-name, .nom-entreprise, h2', 'linkSelector' => 'a[href*="entreprise"]'],
        'yellowpages.com.eg' => ['name' => 'Yellow Pages Egypt', 'nameSelector' => '.company-name, .companyName, h2', 'linkSelector' => 'a[href*="company"]'],
        'daleel.com.eg'      => ['name' => 'Daleel Egypt', 'nameSelector' => '.company-name, h2, .business-name', 'linkSelector' => 'a[href*="company"], a[href*="business"]'],
        'pagesjaunes.com.tn' => ['name' => 'Pages Jaunes Tunisie', 'nameSelector' => '.company-name, h2, .result-name', 'linkSelector' => 'a[href*="entreprise"]'],
        'tunisieindustrie.nat.tn' => ['name' => 'Tunisie Industrie', 'nameSelector' => '.company-name, h2, td.company', 'linkSelector' => 'a[href*="entreprise"], a[href*="company"]'],
    ];

    /**
     * Known directory domain fragments — if any of these appear in a result
     * domain, the result is a directory page and should be seed-only.
     */
    private const DIRECTORY_DOMAIN_FRAGMENTS = [
        'kompass.com', 'europages.', 'thomasnet.com', 'globalspec.com',
        'industrystock.com', 'wlw.de', 'kerix.net', 'directindustry.com',
        'make-it.uk', 'indiamart.com', 'made-in-china.com', 'alibaba.com',
        'industrynet.com', 'tradewheel.com', 'tradeindia.com',
        'yellowpages.', 'dnb.com', 'hoovers.com', 'manta.com',
        'f6s.com', 'crunchbase.com', 'zoominfo.com',
        // African / MENA directories
        'pagesjaunes.ma', 'charika.ma', 'telecontact.ma',
        'pagesjaunes.com.tn', 'tunisieindustrie.nat.tn',
        'yellowpages.com.eg', 'egyindustry.com', 'daleel.com.eg',
        'go4worldbusiness.com', 'amica.org.ma',
        // Additional global directories
        'dnb.com', 'opencorporates.com', 'importgenius.com',
        'panjiva.com', 'companiesmarketcap.com',
    ];

    private TextNormalizer $normalizer;
    private LoggerInterface $logger;

    private readonly HttpClientInterface $httpClient;
    private readonly SafeOutboundUrlGuard $urlGuard;

    public function __construct(
        HttpClientInterface $httpClient,
        ?TextNormalizer $normalizer = null,
        ?LoggerInterface $logger = null,
        ?SafeOutboundUrlGuard $urlGuard = null,
    ) {
        // SSRF defense: every outbound directory fetch is IP-validated on
        // the request and each redirect hop.
        // Mock clients power hermetic unit tests with unresolvable fixture
        // domains; SSRF enforcement is only meaningful for real transport.
        if (!$httpClient instanceof \Symfony\Component\HttpClient\MockHttpClient) {
            $this->httpClient = new \Symfony\Component\HttpClient\NoPrivateNetworkHttpClient($httpClient);
        } else {
            $this->httpClient = $httpClient;
        }
        $this->urlGuard = $urlGuard ?? new SafeOutboundUrlGuard();
        $this->normalizer = $normalizer ?? new TextNormalizer();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Is this domain a known B2B directory?
     */
    public function isDirectoryDomain(string $domain): bool
    {
        $domainLower = strtolower($domain);
        foreach (self::DIRECTORY_DOMAIN_FRAGMENTS as $fragment) {
            if (str_contains($domainLower, $fragment)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the directory name for a given domain, or null.
     */
    public function getDirectoryName(string $domain): ?string
    {
        $domainLower = strtolower($domain);
        foreach (self::DIRECTORY_PROFILES as $profileDomain => $profile) {
            if (str_contains($domainLower, $profileDomain)) {
                return $profile['name'];
            }
        }
        // Generic fallback
        if ($this->isDirectoryDomain($domain)) {
            return 'Unknown Directory';
        }
        return null;
    }

    /**
     * Extract company seeds from a directory page's Google search result.
     *
     * Uses the Google snippet + title to extract what we can without
     * crawling the actual page (fast path). For richer data, use
     * extractSeedsFromPage() which crawls the HTML.
     *
     * @return DirectorySeed[]
     */
    public function extractSeedsFromSnippet(
        string $snippet,
        string $title,
        string $url,
        string $domain,
        ?string $country = null,
        ?string $sector = null,
    ): array {
        $seeds = [];
        $directoryName = $this->getDirectoryName($domain) ?? 'Unknown';

        // Many directory snippets contain company names separated by "...", ","
        // e.g. "Siemens AG, Bosch GmbH, Continental AG - Kompass directory"
        // Try to extract clean names from the snippet.

        // Pattern 1: "Company Name - City, Country | Directory"
        if (preg_match_all('/([A-Z][A-Za-z&\s\.\-]{2,40})\s*[\-–|]\s*[A-Z][a-z]+/u', $title, $matches)) {
            foreach ($matches[1] as $name) {
                $cleaned = $this->normalizer->normalizeCompanyName($name);
                if (strlen($cleaned) >= 3 && !$this->isGenericWord($cleaned)) {
                    $seeds[] = new DirectorySeed(
                        companyName: $cleaned,
                        domain: null,
                        country: $country,
                        sourceDirectory: $directoryName,
                        sourceUrl: $url,
                        sector: $sector,
                        snippet: $snippet,
                    );
                }
            }
        }

        // Pattern 2: Extract the main subject from a title like
        // "Company Name | Europages" or "Company Name - Kompass"
        if (empty($seeds)) {
            $titleClean = preg_replace('/\s*[\|–\-]\s*(europages|kompass|thomasnet|globalspec|wlw|kerix|directindustry|industrystock).*$/i', '', $title);
            $titleClean = trim($titleClean ?? $title);
            if (strlen($titleClean) >= 3 && strlen($titleClean) <= 80) {
                $cleaned = $this->normalizer->normalizeCompanyName($titleClean);
                if (!$this->isGenericWord($cleaned)) {
                    $seeds[] = new DirectorySeed(
                        companyName: $cleaned,
                        domain: null,
                        country: $country,
                        sourceDirectory: $directoryName,
                        sourceUrl: $url,
                        sector: $sector,
                        snippet: $snippet,
                    );
                }
            }
        }

        return $seeds;
    }

    /**
     * Extract company seeds by actually crawling a directory page.
     *
     * This is the rich extraction path — fetches the HTML and parses
     * company listings using CSS selectors specific to each directory.
     *
     * @return DirectorySeed[]
     */
    public function extractSeedsFromPage(
        string $url,
        string $domain,
        ?string $country = null,
        ?string $sector = null,
    ): array {
        $directoryName = $this->getDirectoryName($domain);
        if ($directoryName === null) {
            return [];
        }

        try {
            $this->urlGuard->assertAllowed($url);
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 10,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; StarzCRM/1.0)',
                    'Accept' => 'text/html',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logger->debug('Directory page returned non-200', [
                    'url' => $url, 'status' => $response->getStatusCode(),
                ]);
                return [];
            }

            $html = $response->getContent();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch directory page', [
                'url' => $url, 'error' => $e->getMessage(),
            ]);
            return [];
        }

        return $this->parseSeedsFromHtml($html, $url, $directoryName, $country, $sector);
    }

    /**
     * Parse company seeds from directory HTML.
     *
     * @return DirectorySeed[]
     */
    private function parseSeedsFromHtml(
        string $html,
        string $url,
        string $directoryName,
        ?string $country,
        ?string $sector,
    ): array {
        $seeds = [];

        // Use DomCrawler for structured extraction
        try {
            $crawler = new \Symfony\Component\DomCrawler\Crawler($html);
        } catch (\Throwable $e) {
            $this->logger->debug('Failed to parse directory HTML', [
                'url' => $url, 'error' => $e->getMessage(),
            ]);
            return [];
        }

        // Try to find company names using generic patterns
        // Most directories use structured data or common class names
        $nameSelectors = [
            '.company-name', '.companyName', '.supplier-name',
            '.company-title', '.member-name', '.result-title',
            'h2.company', 'h3.company', '.product-company-name',
            '[itemtype*="Organization"] [itemprop="name"]',
        ];

        foreach ($nameSelectors as $selector) {
            try {
                $nodes = $crawler->filter($selector);
                if ($nodes->count() > 0) {
                    $nodes->each(function ($node) use (&$seeds, $url, $directoryName, $country, $sector) {
                        $name = trim($node->text());
                        $name = $this->normalizer->normalizeCompanyName($name);
                        if (strlen($name) >= 3 && !$this->isGenericWord($name)) {
                            // Try to find an associated website link
                            $domain = null;
                            try {
                                $parent = $node->closest('.company-card, .result-item, .supplier-card, article, .company-block, li');
                                if ($parent !== null) {
                                    $links = $parent->filter('a[href*="http"]');
                                    if ($links->count() > 0) {
                                        $href = $links->first()->attr('href');
                                        $host = is_string($href) && $href !== '' ? parse_url($href, PHP_URL_HOST) : false;
                                        if (is_string($host) && $host !== '' && !$this->isDirectoryDomain($host)) {
                                            $domain = $host;
                                        }
                                    }
                                }
                            } catch (\Throwable) {
                                // Ignore DOM traversal errors
                            }

                            $seeds[] = new DirectorySeed(
                                companyName: $name,
                                domain: $domain,
                                country: $country,
                                sourceDirectory: $directoryName,
                                sourceUrl: $url,
                                sector: $sector,
                            );
                        }
                    });
                    break; // Found results with this selector
                }
            } catch (\Throwable) {
                continue;
            }
        }

        // Fallback: extract from structured data (JSON-LD)
        if (empty($seeds)) {
            try {
                $scripts = $crawler->filter('script[type="application/ld+json"]');
                $scripts->each(function ($node) use (&$seeds, $url, $directoryName, $country, $sector) {
                    try {
                        $data = json_decode($node->text(), true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($data)
                            && ($data['@type'] ?? null) === 'Organization'
                            && isset($data['name'])
                            && is_string($data['name'])
                        ) {
                            $name = $this->normalizer->normalizeCompanyName($data['name']);
                            $domain = isset($data['url']) && is_string($data['url'])
                                ? parse_url($data['url'], PHP_URL_HOST)
                                : null;
                            $domain = is_string($domain) ? $domain : null;
                            if (strlen($name) >= 3) {
                                $seeds[] = new DirectorySeed(
                                    companyName: $name,
                                    domain: $domain,
                                    country: $country,
                                    sourceDirectory: $directoryName,
                                    sourceUrl: $url,
                                    sector: $sector,
                                );
                            }
                        }
                    } catch (\Throwable) {
                        // Invalid JSON-LD
                    }
                });
            } catch (\Throwable) {
                // No JSON-LD
            }
        }

        $this->logger->debug('Extracted seeds from directory page', [
            'url' => $url, 'directory' => $directoryName, 'count' => count($seeds),
        ]);

        return $seeds;
    }

    /**
     * Check if a string is just a generic word, not a company name.
     */
    private function isGenericWord(string $text): bool
    {
        $lower = strtolower(trim($text));
        $generics = [
            'companies', 'suppliers', 'manufacturers', 'products',
            'services', 'solutions', 'industries', 'search',
            'directory', 'results', 'page', 'home', 'about',
            'contact', 'login', 'register', 'sign up',
        ];
        return in_array($lower, $generics, true) || strlen($lower) < 3;
    }
}
