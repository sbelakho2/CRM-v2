<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Fast Web Scraper — pure PHP curl_multi replacement for Playwright.
 * 
 * Scrapes multiple websites concurrently using PHP's curl_multi_*
 * to extract team/leadership pages, contacts, LinkedIn URLs, and emails.
 * 
 * Performance: 20 concurrent connections = 100 sites in ~30-60 seconds
 * (vs Playwright's 5+ minutes per 15 sites = 30+ minutes for 100).
 */
class FastWebScraperService
{
    private const CONCURRENCY = 20; // simultaneous HTTP requests
    private const TIMEOUT = 10;     // seconds per request
    private const CONNECT_TIMEOUT = 5;
    
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /** Paths most likely to contain team/contact info */
    private const TEAM_PATHS = [
        '/about', '/about-us', '/about-us/', '/about/',
        '/team', '/our-team', '/team/', '/our-team/',
        '/leadership', '/leadership/', '/management', '/management/',
        '/contact', '/contact/', '/contact-us', '/contact-us/',
    ];

    /** CSS-class keywords that indicate team member cards */
    private const TEAM_CLASS_KEYWORDS = [
        'team-member', 'team-card', 'staff-member', 'member-card',
        'leadership', 'person-card', 'profile-card', 'executive',
        'bio-card', 'team_member', 'team_card', 'staff_card',
    ];

    /** Nav-link keywords to discover team pages */
    private const NAV_KEYWORDS = [
        'about', 'team', 'leadership', 'people', 'management',
        'who we are', 'our team', 'staff', 'executives', 'directors',
    ];

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * Scrape multiple company websites concurrently.
     * Returns map of website URL → structured scrape data (same format as old Playwright output).
     *
     * @param string[] $urls Company website URLs
     * @return array<string, array> URL → {baseUrl, pagesScraped, pages: [...]}
     */
    public function batchScrape(array $urls): array
    {
        $urls = array_values(array_unique($urls));
        if (empty($urls)) {
            return [];
        }

        $this->logger->info('[FastScraper] Starting batch scrape of {n} sites', ['n' => count($urls)]);
        $startTime = microtime(true);

        // Step 1: Fetch all homepages concurrently
        $homepageUrls = [];
        foreach ($urls as $url) {
            $normalized = $this->normalizeUrl($url);
            if ($normalized) {
                $homepageUrls[$url] = $normalized;
            }
        }

        $homepageResults = $this->multiGet(array_values($homepageUrls));

        // Step 2: Discover team page URLs from homepage HTML + standard paths
        $teamPageUrls = []; // flat list of all URLs to fetch
        $siteUrlMap = [];   // maps each team-page URL back to its parent site URL
        
        foreach ($urls as $siteUrl) {
            $normalized = $homepageUrls[$siteUrl] ?? null;
            if (!$normalized) continue;
            
            $homepageHtml = $homepageResults[$normalized] ?? '';
            $baseUrl = $this->getBaseUrl($normalized);
            if (!$baseUrl) continue;

            // Discover team page links from homepage navigation
            $discovered = $this->discoverTeamLinks($homepageHtml, $baseUrl);
            
            // Also add standard paths
            foreach (self::TEAM_PATHS as $path) {
                $discovered[] = rtrim($baseUrl, '/') . $path;
            }
            
            // Deduplicate and limit
            $discovered = array_unique(array_map(fn($u) => rtrim(strtolower($u), '/'), $discovered));
            $discovered = array_slice($discovered, 0, 6); // max 6 subpages per site
            
            foreach ($discovered as $pageUrl) {
                if (!isset($siteUrlMap[$pageUrl])) {
                    $teamPageUrls[] = $pageUrl;
                    $siteUrlMap[$pageUrl] = $siteUrl;
                }
            }
        }

        // Step 3: Fetch all team/about pages concurrently
        $teamPageResults = $this->multiGet($teamPageUrls);

        // Step 4: Process all results per-site
        $allResults = [];
        foreach ($urls as $siteUrl) {
            $normalized = $homepageUrls[$siteUrl] ?? null;
            $baseUrl = $normalized ? $this->getBaseUrl($normalized) : null;
            
            // Gather all HTML for this site
            $pages = [];
            
            // Include homepage
            if ($normalized && !empty($homepageResults[$normalized])) {
                $pageData = $this->extractPageData($homepageResults[$normalized], $normalized);
                if ($pageData) {
                    $pages[] = $pageData;
                }
            }
            
            // Include team pages
            foreach ($teamPageResults as $pageUrl => $html) {
                if (($siteUrlMap[$pageUrl] ?? null) !== $siteUrl) continue;
                if (empty($html)) continue;
                // Skip if same as homepage
                if ($normalized && strtolower(rtrim($pageUrl, '/')) === strtolower(rtrim($normalized, '/'))) continue;
                
                $pageData = $this->extractPageData($html, $pageUrl);
                if ($pageData) {
                    $pages[] = $pageData;
                }
            }

            $allResults[$siteUrl] = [
                'baseUrl' => $baseUrl ?? $siteUrl,
                'pagesScraped' => count($pages),
                'totalContacts' => array_sum(array_map(fn($p) => count($p['contacts']), $pages)),
                'pages' => $pages,
            ];
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $totalPages = array_sum(array_map(fn($r) => $r['pagesScraped'], $allResults));
        $this->logger->info('[FastScraper] Batch scrape completed in {t}s: {n} sites, {p} pages fetched', [
            'n' => count($urls),
            'p' => $totalPages,
            't' => $elapsed,
        ]);

        return $allResults;
    }

    /**
     * Execute multiple HTTP GET requests concurrently using curl_multi.
     *
     * @param string[] $urls
     * @return array<string, string> URL → response body (empty string on failure)
     */
    private function multiGet(array $urls): array
    {
        if (empty($urls)) {
            return [];
        }

        $results = [];
        $chunks = array_chunk($urls, self::CONCURRENCY);

        foreach ($chunks as $chunk) {
            $mh = curl_multi_init();
            $handles = [];

            foreach ($chunk as $url) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_TIMEOUT => self::TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                    CURLOPT_USERAGENT => self::USER_AGENT,
                    CURLOPT_HTTPHEADER => [
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language: en-US,en;q=0.9',
                        'Accept-Encoding: gzip, deflate',
                        'Connection: keep-alive',
                    ],
                    CURLOPT_ENCODING => '', // auto-decode gzip
                    // SSL verification is intentionally disabled for broad scraping
                    // compatibility. WARNING: this accepts self-signed/expired certs and
                    // disables MITM protection. Only scrape trusted or publicly-accessible
                    // sources. DO NOT use this pattern for authenticated API calls.
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$url] = $ch;
            }

            $cumulativeStart = microtime(true);

            // Execute all requests
            $running = null;
            do {
                if (microtime(true) - $cumulativeStart > self::TIMEOUT) {
                    break;
                }
                curl_multi_exec($mh, $running);
                curl_multi_select($mh, 0.5);
            } while ($running > 0);

            // Collect results
            foreach ($handles as $url => $ch) {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($httpCode >= 200 && $httpCode < 400) {
                    $body = curl_multi_getcontent($ch);
                    // Only keep HTML responses (not PDFs, images, etc.)
                    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '';
                    if (str_contains($contentType, 'html') || str_contains($contentType, 'text') || empty($contentType)) {
                        $results[$url] = $body ?: '';
                    } else {
                        $results[$url] = '';
                    }
                } else {
                    $results[$url] = '';
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }

            curl_multi_close($mh);
        }

        return $results;
    }

    /**
     * Extract structured data from an HTML page.
     * Mirrors what the Playwright script extracted from the DOM.
     */
    private function extractPageData(string $html, string $url): ?array
    {
        if (strlen($html) < 100) {
            return null;
        }

        $data = [
            'url' => $url,
            'title' => '',
            'contacts' => [],
            'teamHtml' => '',
            'linkedinUrls' => [],
            'emails' => [],
            'phones' => [],
            'hasTeamContent' => false,
            'jsonLd' => [],
        ];

        // Title
        if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $m)) {
            $data['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }

        // JSON-LD
        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $jm)) {
            foreach ($jm[1] as $jsonStr) {
                $parsed = json_decode(trim($jsonStr), true);
                if (is_array($parsed)) {
                    $data['jsonLd'][] = $parsed;
                }
            }
        }

        // LinkedIn URLs
        if (preg_match_all('/href=["\']([^"\']*linkedin\.com\/in\/[^"\']*)["\'][^>]*>([^<]*)/si', $html, $lm)) {
            foreach ($lm[1] as $i => $liUrl) {
                $text = trim(strip_tags($lm[2][$i]));
                $data['linkedinUrls'][] = [
                    'url' => html_entity_decode($liUrl, ENT_QUOTES, 'UTF-8'),
                    'text' => $text ?: null,
                ];
            }
        }

        // Emails (mailto: links)
        if (preg_match_all('/href=["\']mailto:([^"\'?]+)/i', $html, $em)) {
            foreach ($em[1] as $email) {
                $email = strtolower(trim($email));
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $data['emails'][] = $email;
                }
            }
        }
        // Also extract emails from visible text
        if (preg_match_all('/\b[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}\b/', strip_tags($html), $em2)) {
            foreach ($em2[0] as $email) {
                $email = strtolower(trim($email));
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $data['emails'][] = $email;
                }
            }
        }
        $data['emails'] = array_values(array_unique($data['emails']));

        // Phones (tel: links)
        if (preg_match_all('/href=["\']tel:([^"\']+)/i', $html, $pm)) {
            foreach ($pm[1] as $phone) {
                $data['phones'][] = trim($phone);
            }
        }

        // Team content detection & extraction
        $teamHtml = $this->extractTeamSections($html);
        if ($teamHtml) {
            $data['hasTeamContent'] = true;
            $data['teamHtml'] = mb_substr($teamHtml, 0, 50000); // cap at 50KB
        }

        // Extract contacts from team card patterns in HTML
        $data['contacts'] = $this->extractContactCards($html);

        // Only return page data if it has useful content
        if ($data['hasTeamContent'] || !empty($data['contacts']) || !empty($data['linkedinUrls']) || !empty($data['emails'])) {
            return $data;
        }

        return null;
    }

    /**
     * Extract team/leadership HTML sections from the page.
     */
    private function extractTeamSections(string $html): string
    {
        $sections = '';

        // Method 1: Find elements with team-related class names
        foreach (self::TEAM_CLASS_KEYWORDS as $keyword) {
            // Match divs/sections with the class keyword
            $pattern = '/<(?:div|section|article|ul)[^>]*class=["\'][^"\']*' . preg_quote($keyword, '/') . '[^"\']*["\'][^>]*>.*?<\/(?:div|section|article|ul)>/si';
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[0] as $match) {
                    if (strlen($match) > 50 && strlen($match) < 100000) {
                        $sections .= $match . "\n";
                    }
                }
            }
        }

        // Method 2: Look for content blocks with team keywords  
        if (empty($sections)) {
            // Find sections/divs containing team-related keywords
            $teamKeywordPattern = '/\b(our\s+team|leadership|management\s+team|executive\s+team|meet\s+the\s+team|board\s+of\s+directors|key\s+people|our\s+people|founders?|co-founders?)\b/i';
            
            if (preg_match($teamKeywordPattern, strip_tags($html))) {
                // Extract main content area
                if (preg_match('/<(?:main|article)[^>]*>(.*?)<\/(?:main|article)>/si', $html, $mainMatch)) {
                    if (preg_match($teamKeywordPattern, strip_tags($mainMatch[1]))) {
                        $sections = $mainMatch[1];
                    }
                }
                
                // Fallback: extract large content div
                if (empty($sections) && preg_match('/<div[^>]*(?:class|id)=["\'][^"\']*(?:content|main|page|wrapper)[^"\']*["\'][^>]*>(.*?)<\/div>\s*(?:<\/div>|<footer)/si', $html, $contentMatch)) {
                    if (strlen($contentMatch[1]) > 200 && preg_match($teamKeywordPattern, strip_tags($contentMatch[1]))) {
                        $sections = $contentMatch[1];
                    }
                }
            }
        }

        return $sections;
    }

    /**
     * Extract structured contact data from HTML team cards.
     * Mirrors the DOM-based card extraction from the Playwright script.
     */
    private function extractContactCards(string $html): array
    {
        $contacts = [];
        $seen = [];

        // Pattern: Find elements that look like team member cards
        // Look for blocks containing a heading (name) + paragraph (title)
        foreach (self::TEAM_CLASS_KEYWORDS as $keyword) {
            $pattern = '/<(?:div|li|article)[^>]*class=["\'][^"\']*' . preg_quote($keyword, '/') . '[^"\']*["\'][^>]*>(.*?)<\/(?:div|li|article)>/si';
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $cardHtml) {
                    $contact = $this->parseContactCard($cardHtml);
                    if ($contact) {
                        $key = strtolower($contact['first_name'] . ' ' . $contact['last_name']);
                        if (!isset($seen[$key])) {
                            $seen[$key] = true;
                            $contacts[] = $contact;
                        }
                    }
                }
            }
        }

        // Also look for structured name+title patterns outside cards
        // Pattern: <h3>Name</h3><p>Title</p> or <h3>Name</h3><span>Title</span>
        if (preg_match_all('/<h[2-5][^>]*>\s*([^<]{3,50})\s*<\/h[2-5]>\s*(?:<[^>]*>\s*)*<(?:p|span|div)[^>]*>\s*([^<]{3,100})\s*<\/(?:p|span|div)>/si', $html, $headingMatches, PREG_SET_ORDER)) {
            foreach ($headingMatches as $hm) {
                $name = html_entity_decode(trim(strip_tags($hm[1])), ENT_QUOTES, 'UTF-8');
                $title = html_entity_decode(trim(strip_tags($hm[2])), ENT_QUOTES, 'UTF-8');
                
                $nameParts = preg_split('/\s+/', $name);
                if (count($nameParts) >= 2 && count($nameParts) <= 5) {
                    $firstName = $nameParts[0];
                    $lastName = implode(' ', array_slice($nameParts, 1));
                    
                    // Validate it looks like a person name, not a section heading
                    if (strlen($firstName) >= 2 && strlen($lastName) >= 2 
                        && !preg_match('/\b(our|the|meet|about|team|company|contact|services|products|news|blog)\b/i', $name)) {
                        $key = strtolower($firstName . ' ' . $lastName);
                        if (!isset($seen[$key])) {
                            $seen[$key] = true;
                            $contacts[] = [
                                'first_name' => $firstName,
                                'last_name' => $lastName,
                                'job_title' => $this->looksLikeTitle($title) ? $title : null,
                                'email' => null,
                                'phone' => null,
                                'linkedin_url' => null,
                            ];
                        }
                    }
                }
            }
        }

        return $contacts;
    }

    /**
     * Parse a single contact card HTML fragment.
     */
    private function parseContactCard(string $cardHtml): ?array
    {
        // Find name: first heading or .name element
        $name = null;
        
        // Try headings first
        if (preg_match('/<h[2-6][^>]*>\s*([^<]{2,60})\s*<\/h[2-6]>/si', $cardHtml, $nm)) {
            $name = html_entity_decode(trim(strip_tags($nm[1])), ENT_QUOTES, 'UTF-8');
        }
        // Try class="name" or similar
        if (!$name && preg_match('/<[^>]*class=["\'][^"\']*name[^"\']*["\'][^>]*>([^<]{2,60})<\//si', $cardHtml, $nm)) {
            $name = html_entity_decode(trim(strip_tags($nm[1])), ENT_QUOTES, 'UTF-8');
        }
        // Try <strong>
        if (!$name && preg_match('/<strong[^>]*>\s*([^<]{2,60})\s*<\/strong>/si', $cardHtml, $nm)) {
            $name = html_entity_decode(trim(strip_tags($nm[1])), ENT_QUOTES, 'UTF-8');
        }

        if (!$name) return null;

        $nameParts = preg_split('/\s+/', $name);
        if (count($nameParts) < 2 || count($nameParts) > 5) return null;

        $contact = [
            'first_name' => $nameParts[0],
            'last_name' => implode(' ', array_slice($nameParts, 1)),
            'job_title' => null,
            'email' => null,
            'phone' => null,
            'linkedin_url' => null,
        ];

        // Find title/position
        if (preg_match('/<[^>]*class=["\'][^"\']*(?:title|position|role|job)[^"\']*["\'][^>]*>([^<]{2,100})<\//si', $cardHtml, $tm)) {
            $title = html_entity_decode(trim(strip_tags($tm[1])), ENT_QUOTES, 'UTF-8');
            if ($title !== $name && $this->looksLikeTitle($title)) {
                $contact['job_title'] = $title;
            }
        }
        // Fallback: first <p> after name
        if (!$contact['job_title'] && preg_match('/<p[^>]*>\s*([^<]{3,100})\s*<\/p>/si', $cardHtml, $pm)) {
            $title = html_entity_decode(trim(strip_tags($pm[1])), ENT_QUOTES, 'UTF-8');
            if ($title !== $name && strlen($title) < 80 && $this->looksLikeTitle($title)) {
                $contact['job_title'] = $title;
            }
        }

        // Email
        if (preg_match('/href=["\']mailto:([^"\'?]+)/i', $cardHtml, $em)) {
            $email = strtolower(trim($em[1]));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $contact['email'] = $email;
            }
        }

        // Phone
        if (preg_match('/href=["\']tel:([^"\']+)/i', $cardHtml, $pm)) {
            $contact['phone'] = trim($pm[1]);
        }

        // LinkedIn
        if (preg_match('/href=["\']([^"\']*linkedin\.com\/in\/[^"\']*)["\']/', $cardHtml, $lm)) {
            $contact['linkedin_url'] = html_entity_decode($lm[1], ENT_QUOTES, 'UTF-8');
        }

        return $contact;
    }

    /**
     * Check if a string looks like a job title vs random text.
     */
    private function looksLikeTitle(string $text): bool
    {
        $text = trim($text);
        if (strlen($text) < 2 || strlen($text) > 100) return false;
        
        // Reject if it looks like a sentence (too many words)
        if (str_word_count($text) > 10) return false;
        
        // Reject obvious non-titles
        if (preg_match('/\b(click|read more|learn more|view|submit|our|the team|company)\b/i', $text)) return false;
        
        // Accept if it contains role keywords
        if (preg_match('/\b(CEO|CTO|CFO|COO|CIO|VP|Director|Manager|President|Chairman|Chief|Officer|Head|Lead|Founder|Owner|Partner|Buyer|Analyst|Engineer|Coordinator|Specialist|Consultant|Advisor|Supervisor|Procurement|Purchasing|Operations|Senior|Junior|Executive|Associate|Assistant|Global|Regional|Principal|Controller|Secretary|Treasurer)\b/i', $text)) {
            return true;
        }

        // Accept short text that doesn't look like navigation
        if (str_word_count($text) <= 5 && !preg_match('/^(home|menu|contact|about|services|products|news|blog|faq)/i', $text)) {
            return true;
        }

        return false;
    }

    /**
     * Discover team-related links from homepage HTML.
     */
    private function discoverTeamLinks(string $html, string $baseUrl): array
    {
        $links = [];

        // Find navigation links matching team keywords
        // Look in <nav>, <header>, .menu, .nav areas
        $navPattern = '/<(?:nav|header)[^>]*>(.*?)<\/(?:nav|header)>/si';
        $navSections = [];
        if (preg_match_all($navPattern, $html, $navMatches)) {
            $navSections = $navMatches[1];
        }
        // Also look for divs with nav/menu class
        if (preg_match_all('/<div[^>]*class=["\'][^"\']*(?:nav|menu|navigation)[^"\']*["\'][^>]*>(.*?)<\/div>/si', $html, $navDivMatches)) {
            $navSections = array_merge($navSections, $navDivMatches[1]);
        }

        // If no nav found, use entire HTML
        if (empty($navSections)) {
            $navSections = [$html];
        }

        foreach ($navSections as $navHtml) {
            if (preg_match_all('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si', $navHtml, $linkMatches, PREG_SET_ORDER)) {
                foreach ($linkMatches as $lm) {
                    $href = $lm[1];
                    $text = strtolower(trim(strip_tags($lm[2])));
                    $hrefLower = strtolower($href);

                    foreach (self::NAV_KEYWORDS as $keyword) {
                        if (str_contains($text, $keyword) || str_contains($hrefLower, str_replace(' ', '-', $keyword))) {
                            $resolved = $this->resolveUrl($href, $baseUrl);
                            if ($resolved && $this->isSameOrigin($resolved, $baseUrl)) {
                                $links[] = $resolved;
                            }
                            break;
                        }
                    }
                }
            }
        }

        return $links;
    }

    // ──────────────────────────────────────────────────────────────────
    //  URL helpers
    // ──────────────────────────────────────────────────────────────────

    private function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if (empty($url)) return null;
        if (!str_starts_with($url, 'http')) {
            $url = 'https://' . $url;
        }
        // Validate
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        return $url;
    }

    private function getBaseUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) return null;
        $scheme = $parts['scheme'] ?? 'https';
        return $scheme . '://' . $parts['host'];
    }

    private function resolveUrl(string $href, string $baseUrl): ?string
    {
        $href = trim($href);
        if (empty($href) || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')) {
            return null;
        }
        if (str_starts_with($href, 'http')) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }
        if (str_starts_with($href, '/')) {
            return rtrim($baseUrl, '/') . $href;
        }
        return rtrim($baseUrl, '/') . '/' . $href;
    }

    private function isSameOrigin(string $url, string $baseUrl): bool
    {
        $urlHost = parse_url($url, PHP_URL_HOST);
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
        if (!$urlHost || !$baseHost) return false;
        // Strip www. for comparison
        $urlHost = preg_replace('/^www\./i', '', $urlHost);
        $baseHost = preg_replace('/^www\./i', '', $baseHost);
        return strtolower($urlHost) === strtolower($baseHost);
    }
}
