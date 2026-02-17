<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * DuckDuckGo HTML-only endpoint scraper.
 *
 * FALLBACK search engine — used when Brave Search is in cooldown.
 *
 * Uses DDG's dedicated HTML endpoint (https://html.duckduckgo.com/html/)
 * which is designed for non-JS clients and returns server-rendered results.
 *
 * Advantages:
 *   - Dedicated HTML endpoint (no JS required)
 *   - Supports all required operators: site:, -site:, OR, "", -keyword, inurl:
 *   - Region support via &kl= parameter (e.g., kl=de-de)
 *
 * Limitations:
 *   - 10 results per page (vs Brave's 20)
 *   - URLs wrapped in DDG redirect (//duckduckgo.com/l/?uddg=...) — needs unwrapping
 *   - More aggressive bot detection — triggers CAPTCHA after ~5-8 rapid queries
 *   - HTTP 202 response with "anomaly" page = bot detection triggered
 *
 * Rate limiting strategy:
 *   - 3-6 second delay between requests (DDG is more sensitive than Brave)
 *   - Immediate abort on HTTP 202 (bot detection)
 *   - User-agent rotation per request
 *
 * CSS selectors (verified Feb 2026):
 *   - Result link:    a.result__a[href]         → redirect-wrapped URL
 *   - Result URL:     a.result__url             → display URL text
 *   - Result snippet: a.result__snippet         → snippet text
 *   - Result title:   text content of a.result__a
 *
 * Region parameter format: &kl={country}-{lang} (e.g., kl=de-de, kl=fr-fr)
 */
final class DuckDuckGoScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://html.duckduckgo.com/html/';

    /** Minimum seconds between requests (DDG is sensitive) */
    private const MIN_DELAY_SECONDS = 3.0;

    /** Maximum additional random jitter (seconds) */
    private const MAX_JITTER_SECONDS = 3.0;

    private float $lastRequestTime = 0.0;

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:122.0) Gecko/20100101 Firefox/122.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:122.0) Gecko/20100101 Firefox/122.0',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ];

    /**
     * Map ISO country codes to DDG region parameter values.
     * Format: {country_lowercase}-{language_lowercase}
     */
    private const REGION_MAP = [
        'DE' => 'de-de', 'FR' => 'fr-fr', 'IT' => 'it-it', 'ES' => 'es-es',
        'GB' => 'uk-en', 'US' => 'us-en', 'NL' => 'nl-nl', 'BE' => 'be-fr',
        'AT' => 'at-de', 'CH' => 'ch-de', 'SE' => 'se-sv', 'NO' => 'no-no',
        'DK' => 'dk-da', 'FI' => 'fi-fi', 'PL' => 'pl-pl', 'CZ' => 'cz-cs',
        'PT' => 'pt-pt', 'IE' => 'ie-en', 'RO' => 'ro-ro', 'HU' => 'hu-hu',
        'BG' => 'bg-bg', 'HR' => 'hr-hr', 'SK' => 'sk-sk', 'SI' => 'sl-sl',
        'LT' => 'lt-lt', 'LV' => 'lv-lv', 'EE' => 'ee-et', 'GR' => 'gr-el',
        'TR' => 'tr-tr', 'IN' => 'in-en', 'JP' => 'jp-jp', 'KR' => 'kr-kr',
        'AU' => 'au-en', 'NZ' => 'nz-en', 'CA' => 'ca-en', 'MX' => 'mx-es',
        'BR' => 'br-pt', 'AR' => 'ar-es', 'ZA' => 'za-en', 'EG' => 'eg-ar',
        'MA' => 'xa-ar', 'TN' => 'xa-ar', 'IL' => 'il-he', 'AE' => 'ae-ar',
        'SA' => 'xa-ar', 'TH' => 'th-th', 'VN' => 'vn-vi', 'MY' => 'my-en',
        'SG' => 'sg-en', 'PH' => 'ph-en', 'ID' => 'id-id', 'TW' => 'tw-tzh',
        'CN' => 'cn-zh',
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

        $this->logger->debug('DuckDuckGoScraper: fetching', [
            'region' => $region,
        ]);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'DNT' => '1',
                    'Connection' => 'keep-alive',
                    'Upgrade-Insecure-Requests' => '1',
                ],
                'timeout' => 15,
                'max_redirects' => 3,
            ]);

            $statusCode = $response->getStatusCode();
            $this->lastRequestTime = microtime(true);

            // DDG returns 202 when it detects a bot — this is the anomaly page
            if ($statusCode === 202) {
                throw new \RuntimeException('DuckDuckGo bot detection triggered (HTTP 202 anomaly page)');
            }

            if ($statusCode === 403) {
                throw new \RuntimeException('DuckDuckGo returned 403 Forbidden');
            }

            if ($statusCode >= 400) {
                throw new \RuntimeException("DuckDuckGo returned HTTP {$statusCode}");
            }

            $html = $response->getContent();

            // Secondary bot detection check: look for anomaly markers in content
            if (str_contains($html, 'anomaly') && str_contains($html, 'botnet')) {
                throw new \RuntimeException('DuckDuckGo bot detection triggered (anomaly content in 200 response)');
            }

            return $this->parseResults($html, $maxResults);

        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException("DuckDuckGo search failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getEngineName(): string
    {
        return 'duckduckgo_html';
    }

    // ──────────────────────────────────────────────────────────────────────
    // HTML Parsing
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Parse DuckDuckGo HTML results.
     *
     * DDG HTML structure (verified Feb 2026):
     *   <div class="result results_links results_links_deep web-result">
     *     <div class="links_main links_deep result__body">
     *       <h2 class="result__title">
     *         <a class="result__a" href="//duckduckgo.com/l/?uddg=ENCODED_URL&...">Title</a>
     *       </h2>
     *       <div class="result__extras__url">
     *         <a class="result__url" href="//duckduckgo.com/l/?uddg=...">
     *           <span class="result__icon">...</span>
     *           domain.com/path
     *         </a>
     *       </div>
     *       <a class="result__snippet" href="//duckduckgo.com/l/?uddg=...">
     *         Snippet text here...
     *       </a>
     *     </div>
     *   </div>
     *
     * @return array[]
     */
    private function parseResults(string $html, int $maxResults): array
    {
        $crawler = new Crawler($html);
        $results = [];

        // Primary extraction using DomCrawler
        $resultNodes = $crawler->filter('a.result__a');

        $resultNodes->each(function (Crawler $linkNode, int $index) use (&$results, $maxResults, $crawler) {
            if (count($results) >= $maxResults) {
                return;
            }

            try {
                // Title is the text content of the link
                $title = $linkNode->text('');

                // URL needs unwrapping from DDG redirect
                $rawHref = $linkNode->attr('href') ?? '';
                $url = $this->unwrapDdgRedirect($rawHref);

                if (empty($url) || empty($title)) {
                    return;
                }

                // Get display URL from result__url elements
                $displayLink = '';
                $urlNodes = $crawler->filter('a.result__url');
                if ($urlNodes->count() > $index) {
                    $urlText = $urlNodes->eq($index)->text('');
                    $displayLink = trim(preg_replace('/\s+/', '', $urlText));
                }

                // Derive proper displayLink (root domain)
                if (empty($displayLink)) {
                    $displayLink = $this->extractDisplayLink($url);
                } else {
                    // DDG shows "domain.com/path" — extract just the domain
                    $displayLink = $this->extractDisplayLink('https://' . ltrim($displayLink, '/ '));
                }

                // Get snippet
                $snippet = '';
                $snippetNodes = $crawler->filter('a.result__snippet');
                if ($snippetNodes->count() > $index) {
                    $snippet = $snippetNodes->eq($index)->text('');
                }

                $results[] = [
                    'link' => $url,
                    'title' => $this->cleanText($title),
                    'snippet' => $this->cleanText($snippet),
                    'displayLink' => $displayLink,
                ];

            } catch (\Throwable $e) {
                $this->logger->debug('DuckDuckGoScraper: failed to parse result', [
                    'error' => $e->getMessage(),
                    'index' => $index,
                ]);
            }
        });

        // Regex fallback if DomCrawler failed
        if (empty($results)) {
            $results = $this->parseResultsRegex($html, $maxResults);
        }

        $this->logger->debug('DuckDuckGoScraper: parsed results', ['count' => count($results)]);

        return $results;
    }

    /**
     * Unwrap DuckDuckGo redirect URL to get the actual target URL.
     *
     * DDG wraps all result URLs in a redirect:
     *   //duckduckgo.com/l/?uddg=https%3A%2F%2Fwww.example.com%2Fpage&rut=...
     *
     * The real URL is URL-encoded in the `uddg` parameter.
     */
    private function unwrapDdgRedirect(string $redirectUrl): string
    {
        // Handle protocol-relative URLs
        if (str_starts_with($redirectUrl, '//')) {
            $redirectUrl = 'https:' . $redirectUrl;
        }

        // Parse the redirect URL
        $parsed = parse_url($redirectUrl);
        if (!$parsed || !isset($parsed['query'])) {
            // Not a redirect — might be a direct URL
            return $redirectUrl;
        }

        parse_str($parsed['query'], $params);

        if (isset($params['uddg'])) {
            return urldecode($params['uddg']);
        }

        // Fallback: return as-is
        return $redirectUrl;
    }

    /**
     * Regex-based fallback parser.
     *
     * @return array[]
     */
    private function parseResultsRegex(string $html, int $maxResults): array
    {
        $results = [];

        // Extract links: <a class="result__a" href="...">TITLE</a>
        if (!preg_match_all(
            '/<a[^>]*class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/s',
            $html,
            $linkMatches,
            PREG_SET_ORDER,
        )) {
            return [];
        }

        // Extract snippets
        preg_match_all(
            '/<a[^>]*class="result__snippet"[^>]*>(.*?)<\/a>/s',
            $html,
            $snippetMatches,
            PREG_SET_ORDER,
        );

        // Extract display URLs
        preg_match_all(
            '/<a[^>]*class="result__url"[^>]*>(.*?)<\/a>/s',
            $html,
            $urlMatches,
            PREG_SET_ORDER,
        );

        foreach ($linkMatches as $i => $match) {
            if (count($results) >= $maxResults) {
                break;
            }

            $rawHref = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $url = $this->unwrapDdgRedirect($rawHref);
            $title = strip_tags($match[2]);

            $snippet = '';
            if (isset($snippetMatches[$i])) {
                $snippet = strip_tags($snippetMatches[$i][1]);
            }

            $displayLink = '';
            if (isset($urlMatches[$i])) {
                $displayLink = trim(strip_tags($urlMatches[$i][1]));
            }

            if (empty($displayLink)) {
                $displayLink = $this->extractDisplayLink($url);
            } else {
                $displayLink = $this->extractDisplayLink('https://' . ltrim($displayLink, '/ '));
            }

            if (!empty($url) && !empty($title)) {
                $results[] = [
                    'link' => $url,
                    'title' => $this->cleanText($title),
                    'snippet' => $this->cleanText($snippet),
                    'displayLink' => $displayLink,
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
                $params['kl'] = self::REGION_MAP[$regionCode];
            }
        }

        return self::BASE_URL . '?' . http_build_query($params);
    }

    private function respectRateLimit(): void
    {
        if ($this->lastRequestTime > 0) {
            $elapsed = microtime(true) - $this->lastRequestTime;
            $minDelay = self::MIN_DELAY_SECONDS + (mt_rand(0, (int)(self::MAX_JITTER_SECONDS * 1000)) / 1000);

            if ($elapsed < $minDelay) {
                $sleepUs = (int)(($minDelay - $elapsed) * 1_000_000);
                $this->logger->debug("DuckDuckGoScraper: sleeping {$sleepUs}µs for rate limit");
                usleep($sleepUs);
            }
        }
    }

    private function extractDisplayLink(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return '';
        }
        return preg_replace('/^www\./', '', strtolower($host));
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}
