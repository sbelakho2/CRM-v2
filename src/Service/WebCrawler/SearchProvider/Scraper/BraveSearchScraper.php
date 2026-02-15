<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Brave Search HTML scraper.
 *
 * PRIMARY search engine for the scraping provider.
 *
 * Advantages over alternatives:
 *   - 20 results per page (vs 10 for DDG)
 *   - Direct URLs (no redirect unwrapping needed)
 *   - Full server-side rendering (no JS required)
 *   - Supports all required operators: site:, -site:, OR, "", -keyword, inurl:
 *   - Region support via &country= parameter
 *   - Tolerant rate limiting (~2s between requests is safe)
 *
 * Anti-detection measures:
 *   - User-agent rotation (8 realistic Chrome/Firefox/Edge agents)
 *   - Accept-Language headers matching query region
 *   - Random delay jitter (2-5s between requests)
 *   - Circuit-breaker on 429/403 responses
 *
 * Verified working: February 2026 live testing confirmed 200 OK with 20 results
 * per page, clean DOM structure, and direct URLs.
 *
 * CSS selectors (verified live):
 *   - Result title:  div.title.search-snippet-title[title]
 *   - Result URL:    parent <a href="..."> of the title div
 *   - Result snippet: div.generic-snippet > div.content
 *   - Display URL:   cite element within result header
 */
final class BraveSearchScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://search.brave.com/search';

    /** Minimum seconds between requests to Brave */
    private const MIN_DELAY_SECONDS = 2.0;

    /** Maximum additional random jitter (seconds) */
    private const MAX_JITTER_SECONDS = 3.0;

    /** Time of last request (float microtime) */
    private float $lastRequestTime = 0.0;

    /**
     * Realistic user agents — rotated per request.
     * Mix of Chrome, Firefox, and Edge on Windows/Mac to look natural.
     */
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:122.0) Gecko/20100101 Firefox/122.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:122.0) Gecko/20100101 Firefox/122.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
    ];

    /**
     * Map ISO country codes to Brave's country parameter values.
     */
    private const REGION_MAP = [
        'DE' => 'de', 'FR' => 'fr', 'IT' => 'it', 'ES' => 'es',
        'GB' => 'gb', 'US' => 'us', 'NL' => 'nl', 'BE' => 'be',
        'AT' => 'at', 'CH' => 'ch', 'SE' => 'se', 'NO' => 'no',
        'DK' => 'dk', 'FI' => 'fi', 'PL' => 'pl', 'CZ' => 'cz',
        'PT' => 'pt', 'IE' => 'ie', 'RO' => 'ro', 'HU' => 'hu',
        'BG' => 'bg', 'HR' => 'hr', 'SK' => 'sk', 'SI' => 'si',
        'LT' => 'lt', 'LV' => 'lv', 'EE' => 'ee', 'LU' => 'lu',
        'MT' => 'mt', 'CY' => 'cy', 'GR' => 'gr', 'TR' => 'tr',
        'IN' => 'in', 'JP' => 'jp', 'KR' => 'kr', 'CN' => 'cn',
        'AU' => 'au', 'NZ' => 'nz', 'CA' => 'ca', 'MX' => 'mx',
        'BR' => 'br', 'AR' => 'ar', 'ZA' => 'za', 'EG' => 'eg',
        'MA' => 'ma', 'TN' => 'tn', 'IL' => 'il', 'AE' => 'ae',
        'SA' => 'sa', 'TH' => 'th', 'VN' => 'vn', 'MY' => 'my',
        'SG' => 'sg', 'PH' => 'ph', 'ID' => 'id', 'TW' => 'tw',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function scrapeResults(string $query, ?string $region = null, int $maxResults = 10): array
    {
        $this->respectRateLimit();

        $url = $this->buildUrl($query, $region);
        $userAgent = self::USER_AGENTS[array_rand(self::USER_AGENTS)];
        $acceptLang = $this->getAcceptLanguage($region);

        $this->logger->debug('BraveSearchScraper: fetching', [
            'url' => $url,
            'region' => $region,
        ]);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => $acceptLang,
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'DNT' => '1',
                    'Connection' => 'keep-alive',
                    'Upgrade-Insecure-Requests' => '1',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'none',
                    'Sec-Fetch-User' => '?1',
                    'Cache-Control' => 'max-age=0',
                ],
                'timeout' => 15,
                'max_redirects' => 3,
            ]);

            $statusCode = $response->getStatusCode();
            $this->lastRequestTime = microtime(true);

            if ($statusCode === 429) {
                throw new \RuntimeException('Brave returned 429 Too Many Requests — rate limited');
            }

            if ($statusCode === 403) {
                throw new \RuntimeException('Brave returned 403 Forbidden — possibly blocked');
            }

            if ($statusCode >= 400) {
                throw new \RuntimeException("Brave returned HTTP {$statusCode}");
            }

            $html = $response->getContent();

            // Check for CAPTCHA / challenge page
            if (str_contains($html, 'captcha') || str_contains($html, 'challenge')) {
                if (str_contains($html, 'Please complete') || str_contains($html, 'verify you are human')) {
                    throw new \RuntimeException('Brave CAPTCHA challenge detected');
                }
            }

            return $this->parseResults($html, $maxResults);

        } catch (\RuntimeException $e) {
            throw $e; // Re-throw our own exceptions
        } catch (\Throwable $e) {
            throw new \RuntimeException("Brave search failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getEngineName(): string
    {
        return 'brave_search';
    }

    // ──────────────────────────────────────────────────────────────────────
    // HTML Parsing
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Parse Brave search results HTML using Symfony DomCrawler.
     *
     * Brave HTML structure (verified Feb 2026):
     *   <div class="snippet fdb" data-type="web">
     *     <div class="snippet-title">
     *       <a href="https://direct.url">
     *         <span class="netloc">...</span>
     *         <cite>display.url/path</cite>
     *         <div class="title search-snippet-title" title="Full Title">Full Title</div>
     *       </a>
     *     </div>
     *     <div class="generic-snippet">
     *       <div class="content">Snippet text here...</div>
     *     </div>
     *   </div>
     *
     * @return array[] Normalized result arrays
     */
    private function parseResults(string $html, int $maxResults): array
    {
        $crawler = new Crawler($html);
        $results = [];

        // Primary selector: title divs with the search-snippet-title class
        // These are within <a> tags that contain the URL
        $titleNodes = $crawler->filter('div.title.search-snippet-title');

        $titleNodes->each(function (Crawler $titleNode) use (&$results, $maxResults) {
            if (count($results) >= $maxResults) {
                return;
            }

            try {
                $title = $titleNode->attr('title') ?? $titleNode->text('');

                // Navigate up to the parent <a> tag to get the URL
                $parentLink = $titleNode->closest('a');
                if (!$parentLink || $parentLink->count() === 0) {
                    return;
                }
                $url = $parentLink->attr('href') ?? '';

                if (empty($url) || empty($title)) {
                    return;
                }

                // Skip non-HTTP links (ads, internal brave links)
                if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                    return;
                }

                // Get display URL from <cite> element
                $displayLink = '';
                try {
                    $citeNode = $parentLink->filter('cite');
                    if ($citeNode->count() > 0) {
                        $displayLink = trim($citeNode->text(''));
                    }
                } catch (\Throwable) {
                    // cite not found — derive from URL
                }

                // Build displayLink (root domain) from URL if cite didn't provide it
                if (empty($displayLink)) {
                    $displayLink = $this->extractDisplayLink($url);
                } else {
                    // Clean cite text — may contain path info, extract just domain
                    $displayLink = $this->extractDisplayLink('https://' . ltrim($displayLink, '/ '));
                }

                // Get snippet from nearby generic-snippet div
                $snippet = $this->findSnippetForResult($parentLink, $results);

                $results[] = [
                    'link' => $url,
                    'title' => $this->cleanText($title),
                    'snippet' => $this->cleanText($snippet),
                    'displayLink' => $displayLink,
                ];

            } catch (\Throwable $e) {
                // Skip individual result parse failures — don't break the batch
                $this->logger->debug('BraveSearchScraper: failed to parse result', [
                    'error' => $e->getMessage(),
                ]);
            }
        });

        // Fallback: if DomCrawler found nothing, try regex-based extraction
        if (empty($results)) {
            $results = $this->parseResultsRegex($html, $maxResults);
        }

        $this->logger->debug('BraveSearchScraper: parsed results', ['count' => count($results)]);

        return $results;
    }

    /**
     * Find the snippet text associated with a result.
     *
     * Brave places snippets in a sibling div.generic-snippet after the title link.
     */
    private function findSnippetForResult(Crawler $linkNode, array $existingResults): string
    {
        try {
            // Try finding generic-snippet as a sibling of the snippet-title container
            $parent = $linkNode->closest('.snippet-title');
            if ($parent && $parent->count() > 0) {
                $grandparent = $parent->closest('.snippet');
                if ($grandparent && $grandparent->count() > 0) {
                    $snippetDiv = $grandparent->filter('.generic-snippet .content, .generic-snippet');
                    if ($snippetDiv->count() > 0) {
                        return $snippetDiv->first()->text('');
                    }
                }
            }
        } catch (\Throwable) {
            // Fall through to empty snippet
        }

        return '';
    }

    /**
     * Regex-based fallback parser for when DomCrawler fails.
     *
     * This handles edge cases where Brave's HTML structure varies slightly
     * from the expected DOM (class name changes, additional wrapper divs, etc.).
     *
     * @return array[]
     */
    private function parseResultsRegex(string $html, int $maxResults): array
    {
        $results = [];

        // Pattern: <a href="URL"> ... <div class="title search-snippet-title" title="TITLE">
        if (preg_match_all(
            '/<a[^>]*href="(https?:\/\/[^"]+)"[^>]*>.*?class="title\s+search-snippet-title[^"]*"[^>]*title="([^"]+)"/s',
            $html,
            $matches,
            PREG_SET_ORDER,
        )) {
            // Also find snippets
            preg_match_all(
                '/class="snippet-description[^"]*"[^>]*>(.*?)<\/div>|class="generic-snippet[^"]*"[^>]*>.*?<div[^>]*class="content[^"]*"[^>]*>(.*?)<\/div>/s',
                $html,
                $snippetMatches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $i => $match) {
                if (count($results) >= $maxResults) {
                    break;
                }

                $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $title = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $snippet = '';

                if (isset($snippetMatches[$i])) {
                    $rawSnippet = $snippetMatches[$i][1] ?: ($snippetMatches[$i][2] ?? '');
                    $snippet = strip_tags($rawSnippet);
                }

                $results[] = [
                    'link' => $url,
                    'title' => $this->cleanText($title),
                    'snippet' => $this->cleanText($snippet),
                    'displayLink' => $this->extractDisplayLink($url),
                ];
            }
        }

        return $results;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function buildUrl(string $query, ?string $region): string
    {
        $params = ['q' => $query];

        if ($region) {
            $regionCode = strtoupper($region);
            if (isset(self::REGION_MAP[$regionCode])) {
                $params['country'] = self::REGION_MAP[$regionCode];
            }
        }

        return self::BASE_URL . '?' . http_build_query($params);
    }

    /**
     * Enforce minimum delay between requests.
     * Adds random jitter to look human.
     */
    private function respectRateLimit(): void
    {
        if ($this->lastRequestTime > 0) {
            $elapsed = microtime(true) - $this->lastRequestTime;
            $minDelay = self::MIN_DELAY_SECONDS + (mt_rand(0, (int)(self::MAX_JITTER_SECONDS * 1000)) / 1000);

            if ($elapsed < $minDelay) {
                $sleepUs = (int)(($minDelay - $elapsed) * 1_000_000);
                $this->logger->debug("BraveSearchScraper: sleeping {$sleepUs}µs for rate limit");
                usleep($sleepUs);
            }
        }
    }

    /**
     * Extract root domain from URL, stripping www. prefix.
     * This becomes `displayLink` — the pipeline's primary dedup key.
     */
    private function extractDisplayLink(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return '';
        }
        return preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * Get Accept-Language header appropriate for the region.
     */
    private function getAcceptLanguage(?string $region): string
    {
        $langMap = [
            'DE' => 'de-DE,de;q=0.9,en;q=0.8',
            'FR' => 'fr-FR,fr;q=0.9,en;q=0.8',
            'IT' => 'it-IT,it;q=0.9,en;q=0.8',
            'ES' => 'es-ES,es;q=0.9,en;q=0.8',
            'NL' => 'nl-NL,nl;q=0.9,en;q=0.8',
            'PT' => 'pt-PT,pt;q=0.9,en;q=0.8',
            'PL' => 'pl-PL,pl;q=0.9,en;q=0.8',
            'SE' => 'sv-SE,sv;q=0.9,en;q=0.8',
            'NO' => 'nb-NO,nb;q=0.9,en;q=0.8',
            'DK' => 'da-DK,da;q=0.9,en;q=0.8',
            'FI' => 'fi-FI,fi;q=0.9,en;q=0.8',
            'CZ' => 'cs-CZ,cs;q=0.9,en;q=0.8',
            'RO' => 'ro-RO,ro;q=0.9,en;q=0.8',
            'HU' => 'hu-HU,hu;q=0.9,en;q=0.8',
            'TR' => 'tr-TR,tr;q=0.9,en;q=0.8',
            'GR' => 'el-GR,el;q=0.9,en;q=0.8',
        ];

        $key = strtoupper($region ?? '');
        return $langMap[$key] ?? 'en-US,en;q=0.9';
    }

    /**
     * Clean extracted text: decode entities, normalize whitespace, trim.
     */
    private function cleanText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}
