<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DomCrawler\Crawler;
use Psr\Log\LoggerInterface;

/**
 * Deep Scraping Service - Enhanced with Headless Browser Support
 * 
 * Visits websites and extracts:
 * - Email addresses (mailto links, text patterns)
 * - Phone numbers
 * - Contact names
 * - Social media links
 * - Contact form detection
 * - About/Contact page content
 * 
 * This enriches leads with actual contact information beyond just a URL.
 * 
 * Enhancements:
 * - Automatic fallback to headless browser for JS-rendered sites
 * - Smart contact form detection
 * - Improved menu navigation for Contact Us pages
 * - Anti-bot evasion with randomized delays
 */
class DeepScrapingService
{
    // Email pattern - matches common email formats
    private const EMAIL_PATTERN = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
    
    // Phone patterns - various international formats
    private const PHONE_PATTERNS = [
        '/\+?\d{1,4}[\s.-]?\(?\d{1,4}\)?[\s.-]?\d{1,4}[\s.-]?\d{1,9}/', // International generic
        '/\(\d{3}\)\s*\d{3}[-.\s]?\d{4}/',                               // US: (555) 555-5555
        '/\d{3}[-.\s]?\d{3}[-.\s]?\d{4}/',                               // US: 555-555-5555
        '/\+1[\s.-]?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}/',              // US: +1 (555) 555-5555
        '/\+44[\s.-]?\d{4}[\s.-]?\d{6}/',                                // UK: +44 1234 567890
        '/\+44[\s.-]?\d{3}[\s.-]?\d{3}[\s.-]?\d{4}/',                   // UK: +44 20 7946 0958
        '/\+49[\s.-]?\d{3,4}[\s.-]?\d{6,8}/',                           // Germany: +49 30 1234567
        '/\+33[\s.-]?\d[\s.-]?\d{2}[\s.-]?\d{2}[\s.-]?\d{2}[\s.-]?\d{2}/', // France: +33 1 23 45 67 89
        '/\+31[\s.-]?\d{2}[\s.-]?\d{3}[\s.-]?\d{4}/',                   // Netherlands: +31 20 123 4567
        '/\+212[\s.-]?\d{3}[\s.-]?\d{3}[\s.-]?\d{3}/',                  // Morocco: +212 522 123 456
        '/\+20[\s.-]?\d{1,2}[\s.-]?\d{3,4}[\s.-]?\d{4}/',              // Egypt: +20 2 1234 5678
        '/\+971[\s.-]?\d{1,2}[\s.-]?\d{3}[\s.-]?\d{4}/',              // UAE: +971 4 123 4567
        '/\+966[\s.-]?\d{1,2}[\s.-]?\d{3}[\s.-]?\d{4}/',              // Saudi Arabia: +966 11 123 4567
        '/\+974[\s.-]?\d{4}[\s.-]?\d{4}/',                              // Qatar: +974 1234 5678
    ];
    
    // Pages likely to contain contact info (expanded with fuzzy matching support)
    private const CONTACT_PAGE_PATTERNS = [
        'contact', 'about', 'about-us', 'team', 'leadership',
        'kontakt', 'contact-us', 'contacto', 'equipe', 'contactez',
        'get-in-touch', 'reach-us', 'connect', 'support', 'help',
        'nous-contacter', 'impressum', 'kontakta'
    ];
    
    // Menu link text patterns that likely lead to contact pages
    private const CONTACT_LINK_TEXT_PATTERNS = [
        'contact', 'about', 'team', 'reach', 'touch', 'connect',
        'support', 'help', 'get in touch', 'reach us', 'talk to us',
        'schedule', 'demo', 'speak', 'call', 'email us'
    ];
    
    // Form detection patterns
    private const CONTACT_FORM_INDICATORS = [
        'form[action*="contact"]',
        'form[id*="contact"]',
        'form[class*="contact"]',
        'form[name*="contact"]',
        'form#contact-form',
        '.contact-form form',
        '#contact form',
        '[class*="hubspot"] form',
        '[data-form-type="contact"]',
        'form[action*="formsubmit"]',
        'form[action*="formspree"]',
        'form[action*="mailchimp"]',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private HeadlessBrowserService $headlessBrowser,
        private LoggerInterface $logger,
        private ?string $userAgent = null
    ) {
        $this->userAgent ??= 'Mozilla/5.0 (compatible; CRMBot/1.0; +https://example.com/bot)';
    }

    /**
     * Scrape a website for contact information
     * 
     * Enhanced with:
     * - Automatic headless browser fallback for JS-rendered sites
     * - Contact form detection
     * - Smart menu navigation
     * 
     * @param string $url The base URL to scrape
     * @param int $maxPages Maximum pages to crawl (default: 5)
     * @param bool $useHeadless Force headless browser
     * @return array{
     *   emails: string[],
     *   phones: string[],
     *   contact_names: string[],
     *   social_links: array,
     *   about_text: string|null,
     *   contact_form_url: string|null,
     *   has_contact_form: bool,
     *   pages_scraped: int,
     *   scraping_method: string,
     *   errors: string[]
     * }
     */
    public function scrapeWebsite(string $url, int $maxPages = 5, bool $useHeadless = false): array
    {
        $result = [
            'emails' => [],
            'phones' => [],
            'contact_names' => [],
            'structured_contacts' => [],
            'social_links' => [],
            'about_text' => null,
            'contact_form_url' => null,
            'has_contact_form' => false,
            'pages_scraped' => 0,
            'scraping_method' => 'static',
            'errors' => [],
        ];
        
        $baseUrl = $this->normalizeBaseUrl($url);
        if (!$baseUrl) {
            $result['errors'][] = 'Invalid URL: ' . $url;
            return $result;
        }
        
        $pagesToVisit = [$baseUrl];
        $visitedPages = [];
        
        // First, try to find contact and about pages using smart navigation
        $contactPages = $this->findContactPagesSmartly($baseUrl, $useHeadless);
        $pagesToVisit = array_merge($pagesToVisit, $contactPages);
        
        while (!empty($pagesToVisit) && count($visitedPages) < $maxPages) {
            $currentUrl = array_shift($pagesToVisit);
            
            // Skip if already visited
            $normalizedUrl = $this->normalizeUrl($currentUrl);
            if (in_array($normalizedUrl, $visitedPages)) {
                continue;
            }
            
            // Skip external links
            if (!$this->isSameDomain($currentUrl, $baseUrl)) {
                continue;
            }
            
            $visitedPages[] = $normalizedUrl;
            
            try {
                $pageData = $this->scrapePage($currentUrl, $useHeadless);
                
                // Track scraping method
                if ($pageData['scraping_method'] === 'panther') {
                    $result['scraping_method'] = 'panther';
                }
                
                // Merge results
                $result['emails'] = array_unique(array_merge($result['emails'], $pageData['emails']));
                $result['phones'] = array_unique(array_merge($result['phones'], $pageData['phones']));
                $result['contact_names'] = array_unique(array_merge($result['contact_names'], $pageData['contact_names']));
                $result['structured_contacts'] = array_merge($result['structured_contacts'], $pageData['structured_contacts'] ?? []);
                $result['social_links'] = array_merge($result['social_links'], $pageData['social_links']);
                
                // Track contact form detection
                if ($pageData['has_contact_form'] && !$result['has_contact_form']) {
                    $result['has_contact_form'] = true;
                    $result['contact_form_url'] = $currentUrl;
                }
                
                // Keep the longest about text
                if ($pageData['about_text'] && 
                    strlen($pageData['about_text']) > strlen($result['about_text'] ?? '')) {
                    $result['about_text'] = $pageData['about_text'];
                }
                
                $result['pages_scraped']++;
                
                // Rate limiting with random variation (more human-like)
                usleep(rand(400000, 800000)); // 400-800ms between requests
                
            } catch (\Exception $e) {
                $result['errors'][] = "Failed to scrape {$currentUrl}: " . $e->getMessage();
                $this->logger->warning('Page scrape failed', [
                    'url' => $currentUrl,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Check if all pages failed and log warning
        if ($result['pages_scraped'] === 0 && !empty($result['errors'])) {
            $this->logger->warning('All pages failed to scrape', [
                'url' => $url,
                'error_count' => count($result['errors']),
                'errors' => $result['errors'],
            ]);
        }

        // Clean and deduplicate results
        $result['emails'] = $this->cleanEmails(array_unique($result['emails']));
        $result['phones'] = $this->cleanPhones(array_unique($result['phones']));
        $result['contact_names'] = array_slice(array_unique($result['contact_names']), 0, 10);
        $result['social_links'] = $this->deduplicateSocialLinks($result['social_links']);

        // Deduplicate structured contacts by name and try to enrich with scraped emails
        $result['structured_contacts'] = $this->deduplicateAndEnrichContacts(
            $result['structured_contacts'],
            $result['emails'],
            $baseUrl
        );
        
        $this->logger->info('Website scrape completed', [
            'url' => $url,
            'pages_scraped' => $result['pages_scraped'],
            'emails_found' => count($result['emails']),
            'phones_found' => count($result['phones']),
            'has_contact_form' => $result['has_contact_form'],
            'scraping_method' => $result['scraping_method'],
        ]);
        
        return $result;
    }

    /**
     * Scrape a single page for contact information
     * 
     * @param string $url The URL to scrape
     * @param bool $useHeadless Force headless browser
     */
    private function scrapePage(string $url, bool $useHeadless = false): array
    {
        $result = [
            'emails' => [],
            'phones' => [],
            'contact_names' => [],
            'structured_contacts' => [],
            'social_links' => [],
            'about_text' => null,
            'has_contact_form' => false,
            'scraping_method' => 'static',
        ];
        
        // Use headless browser service for intelligent fetching
        $pageResult = $this->headlessBrowser->fetchPage($url, $useHeadless);
        
        if (!$pageResult['success']) {
            throw new \RuntimeException($pageResult['error'] ?? 'Failed to fetch page');
        }
        
        $content = $pageResult['html'];
        $result['scraping_method'] = $pageResult['method'];
        
        $crawler = new Crawler($content);
        
        // Extract emails from mailto links
        $crawler->filter('a[href^="mailto:"]')->each(function (Crawler $node) use (&$result) {
            $href = $node->attr('href');
            $email = str_replace('mailto:', '', $href);
            $email = explode('?', $email)[0]; // Remove query params
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result['emails'][] = strtolower($email);
            }
        });
        
        // Extract emails from text content
        $textContent = '';
        try {
            $textContent = $crawler->filter('body')->text();
        } catch (\Exception $e) {
            // No body element, use full content
            $textContent = strip_tags($content);
        }
        
        preg_match_all(self::EMAIL_PATTERN, $textContent, $emailMatches);
        foreach ($emailMatches[0] as $email) {
            // Filter out likely false positives
            if (!$this->isLikelyFalsePositiveEmail($email)) {
                $result['emails'][] = strtolower($email);
            }
        }
        
        // Extract phone numbers
        foreach (self::PHONE_PATTERNS as $pattern) {
            preg_match_all($pattern, $textContent, $phoneMatches);
            foreach ($phoneMatches[0] as $phone) {
                $cleaned = preg_replace('/[^0-9+]/', '', $phone);
                if (strlen($cleaned) >= 7 && strlen($cleaned) <= 15) {
                    $result['phones'][] = $phone;
                }
            }
        }
        
        // Extract tel: links
        $crawler->filter('a[href^="tel:"]')->each(function (Crawler $node) use (&$result) {
            $href = $node->attr('href');
            $phone = str_replace('tel:', '', $href);
            $result['phones'][] = $phone;
        });
        
        // Extract social media links
        $socialPatterns = [
            'linkedin' => '/linkedin\.com\/(?:company|in)\/([^\/\?"]+)/i',
            'twitter' => '/(?:twitter|x)\.com\/([^\/\?"]+)/i',
            'facebook' => '/facebook\.com\/([^\/\?"]+)/i',
        ];
        
        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$result, $socialPatterns) {
            $href = $node->attr('href') ?? '';
            foreach ($socialPatterns as $platform => $pattern) {
                if (preg_match($pattern, $href, $matches)) {
                    $result['social_links'][] = [
                        'platform' => $platform,
                        'url' => $href,
                        'handle' => $matches[1] ?? null,
                    ];
                }
            }
        });
        
        // Detect contact forms
        $result['has_contact_form'] = $this->detectContactForm($crawler);
        
        // Try to extract contact names (look for common patterns)
        $this->extractContactNames($crawler, $result);
        
        // Extract about/description text
        $result['about_text'] = $this->extractAboutText($crawler);
        
        return $result;
    }
    
    /**
     * Detect if page has a contact form
     */
    private function detectContactForm(Crawler $crawler): bool
    {
        // Check for explicit contact form patterns
        foreach (self::CONTACT_FORM_INDICATORS as $selector) {
            try {
                if ($crawler->filter($selector)->count() > 0) {
                    return true;
                }
            } catch (\Exception $e) {
                // Invalid selector, continue
            }
        }
        
        // Heuristic: Look for forms with email/message/name fields
        try {
            $forms = $crawler->filter('form');
            foreach ($forms as $form) {
                $formCrawler = new Crawler($form);
                
                // Count typical contact form fields
                $hasEmail = $formCrawler->filter('input[type="email"], input[name*="email"], input[id*="email"]')->count() > 0;
                $hasMessage = $formCrawler->filter('textarea, input[name*="message"], input[name*="comment"]')->count() > 0;
                $hasName = $formCrawler->filter('input[name*="name"], input[id*="name"]')->count() > 0;
                $hasSubject = $formCrawler->filter('input[name*="subject"], input[id*="subject"]')->count() > 0;
                
                // If form has email + (message OR (name AND subject)), it's likely a contact form
                if ($hasEmail && ($hasMessage || ($hasName && $hasSubject))) {
                    return true;
                }
                
                // Check form action/class for contact indicators
                $formHtml = $formCrawler->html();
                if (preg_match('/(contact|inquiry|enquiry|feedback|message)/i', $formHtml)) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // Form parsing failed, continue
        }
        
        return false;
    }
    
    /**
     * Smart contact page discovery using fuzzy matching on menu links
     */
    private function findContactPagesSmartly(string $baseUrl, bool $useHeadless = false): array
    {
        $pages = [];
        
        // First add standard contact page patterns
        foreach (self::CONTACT_PAGE_PATTERNS as $pattern) {
            $pages[] = rtrim($baseUrl, '/') . '/' . $pattern;
            $pages[] = rtrim($baseUrl, '/') . '/' . $pattern . '/';
        }
        
        // Try to get links from homepage using headless browser
        try {
            $pageResult = $this->headlessBrowser->fetchPage($baseUrl, $useHeadless);
            
            if (!$pageResult['success']) {
                return array_unique($pages);
            }
            
            $crawler = new Crawler($pageResult['html']);
            
            // Look for navigation/menu links
            $navSelectors = ['nav a', 'header a', '.menu a', '.nav a', '#menu a', '[role="navigation"] a', '.navbar a'];
            
            foreach ($navSelectors as $selector) {
                try {
                    $crawler->filter($selector)->each(function (Crawler $node) use (&$pages, $baseUrl) {
                        $href = $node->attr('href') ?? '';
                        $text = strtolower(trim($node->text()));
                        
                        // Check if link text matches contact patterns (fuzzy)
                        $isContactLink = false;
                        foreach (self::CONTACT_LINK_TEXT_PATTERNS as $pattern) {
                            if (str_contains($text, $pattern)) {
                                $isContactLink = true;
                                break;
                            }
                        }
                        
                        // Also check the URL itself
                        if (!$isContactLink) {
                            foreach (self::CONTACT_PAGE_PATTERNS as $pattern) {
                                if (str_contains(strtolower($href), $pattern)) {
                                    $isContactLink = true;
                                    break;
                                }
                            }
                        }
                        
                        if ($isContactLink && !empty($href)) {
                            // Resolve relative URLs
                            $fullUrl = $this->resolveUrl($href, $baseUrl);
                            if ($fullUrl) {
                                $pages[] = $fullUrl;
                            }
                        }
                    });
                } catch (\Exception $e) {
                    // Selector not found, continue
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->debug('Failed to get homepage for smart contact page discovery', [
                'url' => $baseUrl,
                'error' => $e->getMessage()
            ]);
        }
        
        return array_unique($pages);
    }
    
    /**
     * Resolve a relative URL against a base URL
     */
    private function resolveUrl(string $href, string $baseUrl): ?string
    {
        // Skip mailto, tel, javascript links
        if (preg_match('/^(mailto:|tel:|javascript:)/i', $href)) {
            return null;
        }
        
        // Skip anchors
        if (str_starts_with($href, '#')) {
            return null;
        }
        
        // Absolute URL
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }
        
        // Protocol-relative URL
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }
        
        // Absolute path
        if (str_starts_with($href, '/')) {
            return rtrim($baseUrl, '/') . $href;
        }
        
        // Relative path
        return rtrim($baseUrl, '/') . '/' . $href;
    }

    /**
     * Decision-maker job titles that indicate purchasing/procurement authority.
     * Used to filter contacts to the ones most relevant for B2B sales outreach.
     */
    private const DECISION_MAKER_TITLES = [
        // Procurement / Purchasing
        'procurement', 'purchasing', 'sourcing', 'supply chain', 'buyer',
        'commodity', 'vendor management', 'supplier', 'acquisition',
        // Engineering / Technical
        'engineering', 'technical', 'r&d', 'design', 'quality', 'manufacturing',
        'production', 'operations', 'plant manager', 'factory',
        // C-Suite / Executive
        'ceo', 'cto', 'coo', 'cfo', 'cpo', 'president', 'founder',
        'managing director', 'general manager', 'executive',
        'vice president', 'vp', 'svp', 'evp', 'director', 'head of',
        'chief', 'owner', 'partner', 'principal',
    ];

    /**
     * Extract structured contacts from page content.
     *
     * Produces an array of structured person records (not just flat names)
     * by correlating names with nearby emails, phones, titles and LinkedIn URLs.
     *
     * Each contact: ['first_name', 'last_name', 'email', 'phone', 'job_title', 'linkedin_url']
     */
    private function extractContactNames(Crawler $crawler, array &$result): void
    {
        if (!isset($result['structured_contacts'])) {
            $result['structured_contacts'] = [];
        }

        // ── 1) JSON-LD Schema.org (highest quality) ──
        $this->extractContactsFromJsonLd($crawler, $result);

        // ── 2) Schema.org Person microdata ──
        $this->extractContactsFromMicrodata($crawler, $result);

        // ── 3) Team / Leadership / About page sections ──
        $this->extractContactsFromTeamSections($crawler, $result);

        // ── 4) LinkedIn profile links ──
        $this->extractContactsFromLinkedInLinks($crawler, $result);

        // ── 5) vCard / hCard microformat ──
        $this->extractContactsFromVCards($crawler, $result);

        // Deduplicate by name
        $seen = [];
        $unique = [];
        foreach ($result['structured_contacts'] as $c) {
            $key = strtolower(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')));
            if ($key && !isset($seen[$key]) && strlen($key) > 2) {
                $seen[$key] = true;
                $unique[] = $c;
            }
        }
        $result['structured_contacts'] = $unique;

        // Also keep flat names for backward compatibility
        foreach ($unique as $c) {
            $fullName = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
            if ($fullName) {
                $result['contact_names'][] = $fullName;
            }
        }
    }

    /**
     * Extract contacts from JSON-LD structured data (Schema.org)
     */
    private function extractContactsFromJsonLd(Crawler $crawler, array &$result): void
    {
        try {
            $crawler->filter('script[type="application/ld+json"]')->each(function (Crawler $node) use (&$result) {
                $json = json_decode($node->text(), true);
                if (!is_array($json)) {
                    return;
                }

                // Handle @graph arrays
                $entities = [];
                if (isset($json['@graph']) && is_array($json['@graph'])) {
                    $entities = $json['@graph'];
                } else {
                    $entities = [$json];
                }

                foreach ($entities as $entity) {
                    $type = $entity['@type'] ?? '';

                    // Direct Person entities
                    if (in_array($type, ['Person', 'schema:Person'])) {
                        $contact = $this->parseSchemaOrgPerson($entity);
                        if ($contact) {
                            $result['structured_contacts'][] = $contact;
                        }
                    }

                    // Organization → employee / founder / member arrays
                    if (in_array($type, ['Organization', 'Corporation', 'LocalBusiness', 'schema:Organization'])) {
                        foreach (['employee', 'founder', 'member', 'employees', 'founders', 'members'] as $rel) {
                            if (!empty($entity[$rel])) {
                                $people = is_array($entity[$rel]) && isset($entity[$rel][0])
                                    ? $entity[$rel]
                                    : [$entity[$rel]];
                                foreach ($people as $person) {
                                    if (is_array($person)) {
                                        $contact = $this->parseSchemaOrgPerson($person);
                                        if ($contact) {
                                            $result['structured_contacts'][] = $contact;
                                        }
                                    }
                                }
                            }
                        }
                        // contactPoint
                        if (!empty($entity['contactPoint'])) {
                            $points = is_array($entity['contactPoint']) && isset($entity['contactPoint'][0])
                                ? $entity['contactPoint']
                                : [$entity['contactPoint']];
                            foreach ($points as $cp) {
                                if (!empty($cp['email'])) {
                                    // Store as generic email contact
                                    $result['emails'][] = strtolower($cp['email']);
                                }
                                if (!empty($cp['telephone'])) {
                                    $result['phones'][] = $cp['telephone'];
                                }
                            }
                        }
                    }
                }
            });
        } catch (\Exception $e) {
            // Malformed JSON-LD, continue
        }
    }

    /**
     * Parse a Schema.org Person entity into a structured contact
     */
    private function parseSchemaOrgPerson(array $entity): ?array
    {
        $name = $entity['name'] ?? null;
        $firstName = $entity['givenName'] ?? null;
        $lastName = $entity['familyName'] ?? null;

        if (!$firstName && !$lastName && $name) {
            [$firstName, $lastName] = $this->splitPersonName($name);
        }

        if (!$firstName) {
            return null;
        }

        $contact = [
            'first_name' => trim($firstName ?? ''),
            'last_name' => trim($lastName ?? ''),
            'email' => null,
            'phone' => null,
            'job_title' => $entity['jobTitle'] ?? null,
            'linkedin_url' => null,
        ];

        // Email
        if (!empty($entity['email'])) {
            $email = str_replace('mailto:', '', $entity['email']);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $contact['email'] = strtolower($email);
            }
        }

        // Phone
        if (!empty($entity['telephone'])) {
            $contact['phone'] = $entity['telephone'];
        }

        // LinkedIn from sameAs
        if (!empty($entity['sameAs'])) {
            $sameAs = is_array($entity['sameAs']) ? $entity['sameAs'] : [$entity['sameAs']];
            foreach ($sameAs as $url) {
                if (is_string($url) && str_contains($url, 'linkedin.com/in/')) {
                    $contact['linkedin_url'] = $url;
                    break;
                }
            }
        }
        if (!empty($entity['url']) && str_contains($entity['url'], 'linkedin.com/in/')) {
            $contact['linkedin_url'] = $entity['url'];
        }

        return $contact;
    }

    /**
     * Extract contacts from Schema.org Person microdata (itemprop)
     */
    private function extractContactsFromMicrodata(Crawler $crawler, array &$result): void
    {
        try {
            $crawler->filter('[itemtype*="schema.org/Person"]')->each(function (Crawler $node) use (&$result) {
                $name = $node->filter('[itemprop="name"]')->count() > 0
                    ? trim($node->filter('[itemprop="name"]')->text())
                    : null;
                $givenName = $node->filter('[itemprop="givenName"]')->count() > 0
                    ? trim($node->filter('[itemprop="givenName"]')->text())
                    : null;
                $familyName = $node->filter('[itemprop="familyName"]')->count() > 0
                    ? trim($node->filter('[itemprop="familyName"]')->text())
                    : null;
                $jobTitle = $node->filter('[itemprop="jobTitle"]')->count() > 0
                    ? trim($node->filter('[itemprop="jobTitle"]')->text())
                    : null;

                $firstName = $givenName;
                $lastName = $familyName;
                if (!$firstName && !$lastName && $name) {
                    [$firstName, $lastName] = $this->splitPersonName($name);
                }

                if (!$firstName) {
                    return;
                }

                $contact = [
                    'first_name' => $firstName,
                    'last_name' => $lastName ?? '',
                    'email' => null,
                    'phone' => null,
                    'job_title' => $jobTitle,
                    'linkedin_url' => null,
                ];

                // Email from itemprop or nearby mailto
                if ($node->filter('[itemprop="email"]')->count() > 0) {
                    $email = str_replace('mailto:', '', $node->filter('[itemprop="email"]')->attr('href') ?? $node->filter('[itemprop="email"]')->text());
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $contact['email'] = strtolower($email);
                    }
                } elseif ($node->filter('a[href^="mailto:"]')->count() > 0) {
                    $email = str_replace('mailto:', '', explode('?', $node->filter('a[href^="mailto:"]')->attr('href'))[0]);
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $contact['email'] = strtolower($email);
                    }
                }

                // Phone
                if ($node->filter('[itemprop="telephone"]')->count() > 0) {
                    $contact['phone'] = trim($node->filter('[itemprop="telephone"]')->text());
                } elseif ($node->filter('a[href^="tel:"]')->count() > 0) {
                    $contact['phone'] = str_replace('tel:', '', $node->filter('a[href^="tel:"]')->attr('href'));
                }

                // LinkedIn
                $node->filter('a[href*="linkedin.com/in/"]')->each(function (Crawler $link) use (&$contact) {
                    $contact['linkedin_url'] = $link->attr('href');
                });

                $result['structured_contacts'][] = $contact;
            });
        } catch (\Exception $e) {
            // Malformed microdata, continue
        }
    }

    /**
     * Extract contacts from team/leadership/about page sections.
     *
     * Looks for common HTML patterns like:
     *   <div class="team-member">
     *     <h3>John Smith</h3>
     *     <p class="title">VP of Procurement</p>
     *     <a href="mailto:john@company.com">...</a>
     *   </div>
     */
    private function extractContactsFromTeamSections(Crawler $crawler, array &$result): void
    {
        // Broad selectors for team/leadership/people sections
        $sectionSelectors = [
            '.team-member', '.team-card', '.member', '.staff-member',
            '.leadership-card', '.leader', '.person', '.people-card',
            '.bio', '.executive', '.management-team .card',
            '[class*="team-member"]', '[class*="team-card"]',
            '[class*="leadership"]', '[class*="executive"]',
            '[class*="staff"]', '[class*="people"]',
        ];

        $selector = implode(', ', $sectionSelectors);

        try {
            $crawler->filter($selector)->each(function (Crawler $card) use (&$result) {
                $this->extractContactFromCard($card, $result);
            });
        } catch (\Exception $e) {
            // Invalid selectors, continue
        }

        // Fallback: look in broader contact/team sections for "Name - Title" patterns
        try {
            $sections = $crawler->filter('.contact, .team, .leadership, .about, .management, [class*="contact"], [class*="team"], [class*="about"]');
            $sections->each(function (Crawler $section) use (&$result) {
                $html = $section->html();

                // Pattern: "FirstName LastName" followed by separator and title
                // e.g., "John Smith - VP of Procurement" or "John Smith, Director of Purchasing"
                $pattern = '/
                    (?:<(?:h[2-6]|strong|b|span|p)[^>]*>)\s*
                    ([A-Z][a-zà-ÿ]+(?:\s+[A-Z][a-zà-ÿ]+){1,3})\s*
                    (?:<\/(?:h[2-6]|strong|b|span|p)>)\s*
                    (?:<[^>]*>)*\s*
                    ([A-Z][A-Za-zà-ÿ\s,&\/\-]+?)
                    \s*(?:<|$)
                /ux';

                if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $name = strip_tags(trim($m[1]));
                        $title = strip_tags(trim($m[2]));

                        // Validate this looks like a real name + title
                        if (strlen($name) > 3 && strlen($name) < 60 && $this->looksLikeJobTitle($title)) {
                            [$first, $last] = $this->splitPersonName($name);
                            if ($first) {
                                $result['structured_contacts'][] = [
                                    'first_name' => $first,
                                    'last_name' => $last ?? '',
                                    'email' => null,
                                    'phone' => null,
                                    'job_title' => $title,
                                    'linkedin_url' => null,
                                ];
                            }
                        }
                    }
                }
            });
        } catch (\Exception $e) {
            // Section parsing failed
        }
    }

    /**
     * Extract a single contact from a card/bio HTML block
     */
    private function extractContactFromCard(Crawler $card, array &$result): void
    {
        // Name: usually in h2-h6, strong, or specific class
        $name = null;
        foreach (['h2', 'h3', 'h4', 'h5', '.name', '[class*="name"]', 'strong'] as $sel) {
            try {
                if ($card->filter($sel)->count() > 0) {
                    $candidate = trim($card->filter($sel)->first()->text());
                    if (strlen($candidate) > 2 && strlen($candidate) < 60 && $this->looksLikePersonName($candidate)) {
                        $name = $candidate;
                        break;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        if (!$name) {
            return;
        }

        [$firstName, $lastName] = $this->splitPersonName($name);
        if (!$firstName) {
            return;
        }

        $contact = [
            'first_name' => $firstName,
            'last_name' => $lastName ?? '',
            'email' => null,
            'phone' => null,
            'job_title' => null,
            'linkedin_url' => null,
        ];

        // Title: usually in p, span, or specific class
        foreach (['.title', '.position', '.role', '.job-title', '[class*="title"]', '[class*="position"]', '[class*="role"]', 'p', 'span.subtitle'] as $sel) {
            try {
                if ($card->filter($sel)->count() > 0) {
                    $candidate = trim($card->filter($sel)->first()->text());
                    if ($candidate !== $name && strlen($candidate) > 2 && strlen($candidate) < 100 && $this->looksLikeJobTitle($candidate)) {
                        $contact['job_title'] = $candidate;
                        break;
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Email
        try {
            if ($card->filter('a[href^="mailto:"]')->count() > 0) {
                $email = str_replace('mailto:', '', explode('?', $card->filter('a[href^="mailto:"]')->attr('href'))[0]);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $contact['email'] = strtolower($email);
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Structured contact extraction failed', ['exception' => $e]);
        }

        // Phone
        try {
            if ($card->filter('a[href^="tel:"]')->count() > 0) {
                $contact['phone'] = str_replace('tel:', '', $card->filter('a[href^="tel:"]')->attr('href'));
            }
        } catch (\Exception $e) {
            $this->logger->warning('Phone extraction failed', ['exception' => $e]);
        }

        // LinkedIn
        try {
            if ($card->filter('a[href*="linkedin.com/in/"]')->count() > 0) {
                $contact['linkedin_url'] = $card->filter('a[href*="linkedin.com/in/"]')->attr('href');
            }
        } catch (\Exception $e) {
            $this->logger->warning('LinkedIn extraction failed', ['exception' => $e]);
        }

        $result['structured_contacts'][] = $contact;
    }

    /**
     * Extract contacts from LinkedIn profile links found on the page
     */
    private function extractContactsFromLinkedInLinks(Crawler $crawler, array &$result): void
    {
        try {
            $crawler->filter('a[href*="linkedin.com/in/"]')->each(function (Crawler $link) use (&$result) {
                $href = $link->attr('href') ?? '';
                $text = trim($link->text());

                // Only if the link text looks like a person name
                if ($text && $this->looksLikePersonName($text)) {
                    [$first, $last] = $this->splitPersonName($text);
                    if ($first) {
                        $result['structured_contacts'][] = [
                            'first_name' => $first,
                            'last_name' => $last ?? '',
                            'email' => null,
                            'phone' => null,
                            'job_title' => null,
                            'linkedin_url' => $href,
                        ];
                    }
                }
            });
        } catch (\Exception $e) {
            $this->logger->warning('LinkedIn DOM extraction failed', ['exception' => $e]);
        }
    }

    /**
     * Extract contacts from vCard / hCard microformat
     */
    private function extractContactsFromVCards(Crawler $crawler, array &$result): void
    {
        try {
            $crawler->filter('.vcard, .h-card')->each(function (Crawler $card) use (&$result) {
                $name = null;
                foreach (['.fn', '.p-name', '[class*="fn"]'] as $sel) {
                    if ($card->filter($sel)->count() > 0) {
                        $name = trim($card->filter($sel)->first()->text());
                        break;
                    }
                }
                if (!$name || !$this->looksLikePersonName($name)) {
                    return;
                }

                [$first, $last] = $this->splitPersonName($name);
                if (!$first) {
                    return;
                }

                $contact = [
                    'first_name' => $first,
                    'last_name' => $last ?? '',
                    'email' => null,
                    'phone' => null,
                    'job_title' => null,
                    'linkedin_url' => null,
                ];

                // Title
                foreach (['.title', '.p-job-title', '.role'] as $sel) {
                    if ($card->filter($sel)->count() > 0) {
                        $contact['job_title'] = trim($card->filter($sel)->first()->text());
                        break;
                    }
                }
                // Email
                if ($card->filter('a[href^="mailto:"]')->count() > 0) {
                    $email = str_replace('mailto:', '', explode('?', $card->filter('a[href^="mailto:"]')->attr('href'))[0]);
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $contact['email'] = strtolower($email);
                    }
                }
                // Phone
                if ($card->filter('.tel, .p-tel, a[href^="tel:"]')->count() > 0) {
                    $node = $card->filter('.tel, .p-tel, a[href^="tel:"]')->first();
                    $contact['phone'] = $node->attr('href')
                        ? str_replace('tel:', '', $node->attr('href'))
                        : trim($node->text());
                }
                // LinkedIn
                if ($card->filter('a[href*="linkedin.com/in/"]')->count() > 0) {
                    $contact['linkedin_url'] = $card->filter('a[href*="linkedin.com/in/"]')->attr('href');
                }

                $result['structured_contacts'][] = $contact;
            });
        } catch (\Exception $e) {
            $this->logger->warning('vCard structured contact extraction failed', ['exception' => $e]);
        }
    }

    /**
     * Split "John Smith" into ['John', 'Smith']
     */
    private function splitPersonName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name));
        if (empty($parts)) { return [null, null]; }
        if (count($parts) < 2) {
            return [$parts[0] ?? null, null];
        }
        $first = array_shift($parts);
        $last = implode(' ', $parts);
        return [$first, $last];
    }

    /**
     * Check if a string looks like a real person name (not a company/page title)
     */
    private function looksLikePersonName(string $text): bool
    {
        $text = trim(strip_tags($text));

        // Must be 2-5 words, starting with uppercase
        $words = preg_split('/\s+/', $text);
        if (count($words) < 2 || count($words) > 5) {
            return false;
        }

        // Each word should start with a capital letter (or be a particle like "de", "von")
        $particles = ['de', 'del', 'der', 'di', 'du', 'el', 'la', 'le', 'van', 'von', 'al', 'bin', 'ben'];
        foreach ($words as $w) {
            if (!ctype_upper($w[0]) && !in_array(strtolower($w), $particles, true)) {
                return false;
            }
        }

        // Reject if it contains common non-name words
        $rejectWords = [
            'company', 'inc', 'llc', 'ltd', 'corp', 'group', 'solutions',
            'contact', 'about', 'team', 'home', 'page', 'our', 'the',
            'services', 'products', 'news', 'blog', 'careers', 'jobs',
            'privacy', 'terms', 'policy', 'learn', 'more', 'read',
        ];
        foreach ($words as $w) {
            if (in_array(strtolower($w), $rejectWords, true)) {
                return false;
            }
        }

        return strlen($text) >= 4 && strlen($text) <= 60;
    }

    /**
     * Check if text looks like a job title
     */
    private function looksLikeJobTitle(string $text): bool
    {
        $text = strtolower(trim(strip_tags($text)));
        if (strlen($text) < 3 || strlen($text) > 120) {
            return false;
        }

        $titleKeywords = [
            'director', 'manager', 'president', 'vp', 'vice president',
            'chief', 'officer', 'head', 'lead', 'senior', 'junior',
            'coordinator', 'specialist', 'analyst', 'engineer', 'supervisor',
            'executive', 'founder', 'owner', 'partner', 'principal',
            'procurement', 'purchasing', 'buyer', 'sourcing', 'supply chain',
            'ceo', 'cto', 'cfo', 'coo', 'cpo', 'cio',
        ];

        foreach ($titleKeywords as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a contact is a decision-maker (procurement, engineering, C-suite)
     */
    public function isDecisionMaker(array $contact): bool
    {
        $title = strtolower($contact['job_title'] ?? '');
        if (!$title) {
            return false; // No title = unknown, not automatically a decision maker
        }

        foreach (self::DECISION_MAKER_TITLES as $keyword) {
            if (str_contains($title, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Score a contact's quality (0-100) for B2B sales relevance.
     * Higher = more likely a real, reachable decision-maker.
     */
    public function scoreContactQuality(array $contact, ?string $companyDomain = null): int
    {
        $score = 0;

        // Has a real name (first + last)
        if (!empty($contact['first_name']) && !empty($contact['last_name'])) {
            $score += 15;
        }

        // Has email
        if (!empty($contact['email'])) {
            $score += 20;
            // Bonus: email domain matches company domain
            if ($companyDomain) {
                $emailDomain = explode('@', $contact['email'])[1] ?? '';
                $emailDomain = preg_replace('/^www\./', '', strtolower($emailDomain));
                $companyDomain = preg_replace('/^www\./', '', strtolower($companyDomain));
                if ($emailDomain === $companyDomain) {
                    $score += 15; // Email matches company = high confidence
                }
            }
        }

        // Has phone
        if (!empty($contact['phone'])) {
            $score += 10;
        }

        // Has LinkedIn
        if (!empty($contact['linkedin_url'])) {
            $score += 15;
        }

        // Has job title
        if (!empty($contact['job_title'])) {
            $score += 10;
            // Bonus: decision-maker title
            if ($this->isDecisionMaker($contact)) {
                $score += 15;
            }
        }

        return min(100, $score);
    }

    /**
     * Extract about/description text from page
     */
    private function extractAboutText(Crawler $crawler): ?string
    {
        // Try meta description first
        $metaDesc = $crawler->filter('meta[name="description"]');
        if ($metaDesc->count() > 0) {
            $description = $metaDesc->attr('content');
            if ($description && strlen($description) > 50) {
                return trim($description);
            }
        }
        
        // Try Open Graph description
        $ogDesc = $crawler->filter('meta[property="og:description"]');
        if ($ogDesc->count() > 0) {
            $description = $ogDesc->attr('content');
            if ($description && strlen($description) > 50) {
                return trim($description);
            }
        }
        
        // Try to find about section
        $aboutSection = $crawler->filter('.about, [class*="about"], #about');
        if ($aboutSection->count() > 0) {
            $text = $aboutSection->first()->text();
            if (strlen($text) > 100) {
                return substr(trim($text), 0, 500);
            }
        }
        
        return null;
    }

    /**
     * Normalize base URL
     */
    private function normalizeBaseUrl(string $url): ?string
    {
        $url = trim($url);
        
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'https://' . $url;
        }
        
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            return null;
        }
        
        return sprintf('%s://%s', $parsed['scheme'] ?? 'https', $parsed['host']);
    }

    /**
     * Normalize URL for comparison
     */
    private function normalizeUrl(string $url): string
    {
        $url = strtolower($url);
        $url = rtrim($url, '/');
        $url = preg_replace('/\?.*$/', '', $url); // Remove query string
        $url = preg_replace('/#.*$/', '', $url); // Remove fragment
        
        return $url;
    }

    /**
     * Check if URL is on same domain
     */
    private function isSameDomain(string $url, string $baseUrl): bool
    {
        $urlHost = parse_url($url, PHP_URL_HOST);
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
        
        if (!$urlHost || !$baseHost) {
            return false;
        }
        
        // Allow www. variations
        $urlHost = preg_replace('/^www\./', '', $urlHost);
        $baseHost = preg_replace('/^www\./', '', $baseHost);
        
        return $urlHost === $baseHost;
    }

    /**
     * Check if email is likely a false positive
     */
    private function isLikelyFalsePositiveEmail(string $email): bool
    {
        $falsePositivePatterns = [
            'example.com', 'test.com', 'domain.com', 'email.com',
            'yourdomain', 'yourcompany', 'sentry.io', 'webpack',
            'jquery', 'bootstrap', '.png', '.jpg', '.gif', '.svg'
        ];
        
        $email = strtolower($email);
        foreach ($falsePositivePatterns as $pattern) {
            if (str_contains($email, $pattern)) {
                return true;
            }
        }
        
        // Filter out very short or generic emails
        $localPart = explode('@', $email)[0];
        if (strlen($localPart) < 2) {
            return true;
        }
        
        return false;
    }

    /**
     * Clean and filter emails
     */
    private function cleanEmails(array $emails): array
    {
        $cleaned = [];
        $seen = [];
        
        foreach ($emails as $email) {
            $email = strtolower(trim($email));
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            
            if (in_array($email, $seen)) {
                continue;
            }
            
            if ($this->isLikelyFalsePositiveEmail($email)) {
                continue;
            }
            
            $cleaned[] = $email;
            $seen[] = $email;
        }
        
        // Sort by quality (prefer info@, contact@, sales@ etc.)
        usort($cleaned, function($a, $b) {
            $priorityPrefixes = ['info', 'contact', 'sales', 'procurement', 'purchasing', 'business'];
            $aPrefix = explode('@', $a)[0];
            $bPrefix = explode('@', $b)[0];
            
            $aPriority = 999;
            $bPriority = 999;
            
            foreach ($priorityPrefixes as $i => $prefix) {
                if (str_starts_with($aPrefix, $prefix)) $aPriority = min($aPriority, $i);
                if (str_starts_with($bPrefix, $prefix)) $bPriority = min($bPriority, $i);
            }
            
            return $aPriority <=> $bPriority;
        });
        
        return array_slice($cleaned, 0, 10); // Limit to 10 emails
    }

    /**
     * Clean and filter phones
     */
    private function cleanPhones(array $phones): array
    {
        $cleaned = [];
        $seen = [];
        
        foreach ($phones as $phone) {
            $normalized = preg_replace('/[^0-9+]/', '', $phone);
            
            if (strlen($normalized) < 7 || strlen($normalized) > 15) {
                continue;
            }
            
            if (in_array($normalized, $seen)) {
                continue;
            }
            
            $cleaned[] = $phone;
            $seen[] = $normalized;
        }
        
        return array_slice($cleaned, 0, 5); // Limit to 5 phones
    }

    /**
     * Deduplicate structured contacts and try to match them with scraped emails.
     *
     * If a contact has no email but we found emails like firstname.lastname@domain
     * or firstinitiallastname@domain, we match them up.
     */
    private function deduplicateAndEnrichContacts(array $contacts, array $emails, string $baseUrl): array
    {
        $domain = parse_url($baseUrl, PHP_URL_HOST);
        $domain = $domain ? preg_replace('/^www\./', '', strtolower($domain)) : null;

        // Deduplicate by name
        $seen = [];
        $unique = [];
        foreach ($contacts as $c) {
            $key = strtolower(trim(($c['first_name'] ?? '') . '|' . ($c['last_name'] ?? '')));
            if (!$key || $key === '|' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $c;
        }

        // Build a set of company-domain emails for matching
        $companyEmails = [];
        if ($domain) {
            foreach ($emails as $email) {
                $parts = explode('@', $email);
                if (count($parts) === 2) {
                    $emailDomain = preg_replace('/^www\./', '', strtolower($parts[1]));
                    if ($emailDomain === $domain) {
                        $companyEmails[strtolower($parts[0])] = $email;
                    }
                }
            }
        }

        // Try to match contacts without email to company-domain emails
        foreach ($unique as &$contact) {
            if (!empty($contact['email'])) {
                continue; // Already has email
            }

            $first = strtolower(trim($contact['first_name'] ?? ''));
            $last = strtolower(trim($contact['last_name'] ?? ''));

            if (!$first || !$last) {
                continue;
            }

            // Common email patterns: firstname.lastname, firstnamelastname, f.lastname, flastname
            $patterns = [
                $first . '.' . $last,
                $first . $last,
                $first[0] . '.' . $last,
                $first[0] . $last,
                $last . '.' . $first,
                $first . '_' . $last,
            ];

            foreach ($patterns as $p) {
                if (isset($companyEmails[$p])) {
                    $contact['email'] = $companyEmails[$p];
                    break;
                }
            }
        }
        unset($contact);

        // Score and sort by quality (decision-makers first)
        usort($unique, function ($a, $b) use ($domain) {
            return $this->scoreContactQuality($b, $domain) <=> $this->scoreContactQuality($a, $domain);
        });

        return array_slice($unique, 0, 15); // Cap at 15 contacts
    }

    /**
     * Deduplicate social links
     */
    private function deduplicateSocialLinks(array $links): array
    {
        $seen = [];
        $unique = [];
        
        foreach ($links as $link) {
            $key = $link['platform'] . ':' . ($link['handle'] ?? $link['url']);
            if (!isset($seen[$key])) {
                $unique[] = $link;
                $seen[$key] = true;
            }
        }
        
        return $unique;
    }
}
