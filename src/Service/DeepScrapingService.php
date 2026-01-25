<?php

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
    
    // Phone patterns - various formats
    private const PHONE_PATTERNS = [
        '/\+?\d{1,4}[\s.-]?\(?\d{1,4}\)?[\s.-]?\d{1,4}[\s.-]?\d{1,9}/', // International
        '/\(\d{3}\)\s*\d{3}[-.\s]?\d{4}/', // (555) 555-5555
        '/\d{3}[-.\s]?\d{3}[-.\s]?\d{4}/', // 555-555-5555
        '/\+212[\s.-]?\d{3}[\s.-]?\d{3}[\s.-]?\d{3}/', // Morocco
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
        
        // Clean and deduplicate results
        $result['emails'] = $this->cleanEmails(array_unique($result['emails']));
        $result['phones'] = $this->cleanPhones(array_unique($result['phones']));
        $result['contact_names'] = array_slice(array_unique($result['contact_names']), 0, 10);
        $result['social_links'] = $this->deduplicateSocialLinks($result['social_links']);
        
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
     * Extract potential contact names from page content
     */
    private function extractContactNames(Crawler $crawler, array &$result): void
    {
        // Look for structured data (Schema.org Person)
        $crawler->filter('[itemtype*="schema.org/Person"]')->each(function (Crawler $node) use (&$result) {
            $name = $node->filter('[itemprop="name"]')->count() > 0 
                ? $node->filter('[itemprop="name"]')->text() 
                : null;
            if ($name) {
                $result['contact_names'][] = trim($name);
            }
        });
        
        // Look for common contact section patterns
        $contactSections = $crawler->filter('.contact, .team, .leadership, [class*="contact"], [class*="team"]');
        
        $contactSections->each(function (Crawler $section) use (&$result) {
            // Look for names near email/phone patterns
            $html = $section->html();
            
            // Pattern: Name followed by title or email
            if (preg_match_all('/([A-Z][a-z]+\s+[A-Z][a-z]+)(?:\s*[-–]\s*|\s*,\s*)([A-Z][a-z\s]+)?/u', $html, $matches)) {
                foreach ($matches[1] as $name) {
                    $name = strip_tags(trim($name));
                    if (strlen($name) > 3 && strlen($name) < 50) {
                        $result['contact_names'][] = $name;
                    }
                }
            }
        });
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
