<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DomCrawler\Crawler;
use Psr\Log\LoggerInterface;

/**
 * Headless Browser Service
 * 
 * Provides JavaScript-rendered page scraping capabilities with intelligent fallback.
 * 
 * Strategy:
 * 1. Check fail-safe service before scraping
 * 2. Try fast static HTTP first (most sites don't need JS)
 * 3. Detect if page is JS-rendered (SPA detection)
 * 4. Fall back to headless browser (Chrome/Firefox) for JS content
 * 5. Implement smart caching to avoid repeated browser launches
 * 
 * Anti-Bot Evasion:
 * - Proxy rotation (when configured)
 * - Randomized user agents
 * - Human-like delays
 * - Viewport randomization
 * - Cookie acceptance simulation
 * - Circuit breaker pattern (fail-safe)
 */
class HeadlessBrowserService
{
    private const STATIC_TIMEOUT = 8;
    private const BROWSER_TIMEOUT = 30;
    
    // Markers indicating JS-rendered page (SPA frameworks)
    private const SPA_MARKERS = [
        'id="__next"',          // Next.js
        'id="root"',            // React
        'id="app"',             // Vue.js
        'ng-app',               // Angular
        'data-reactroot',       // React
        'window.__NUXT__',      // Nuxt.js
        '__INITIAL_STATE__',    // Various SSR frameworks
    ];
    
    // Common user agents for rotation
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
    ];
    
    // Common cookie consent button patterns
    private const COOKIE_ACCEPT_SELECTORS = [
        'button[id*="accept"]',
        'button[class*="accept"]',
        'button[data-testid*="accept"]',
        '[class*="cookie"] button[class*="accept"]',
        '[class*="consent"] button[class*="agree"]',
        '#cookie-accept',
        '.cookie-accept',
        '[aria-label*="Accept"]',
    ];

    private ?object $pantherClient = null;
    private bool $pantherAvailable;
    
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private ?ProxyRotationService $proxyRotation = null,
        private ?ScrapingFailSafeService $failSafe = null
    ) {
        // Check if Panther is available at runtime
        $this->pantherAvailable = class_exists('\Symfony\Component\Panther\Client');
    }

    /**
     * Fetch page content intelligently
     * 
     * @param string $url URL to fetch
     * @param bool $forceHeadless Force headless browser even if not needed
     * @return array{html: string, method: string, success: bool, error: ?string, proxy: ?string}
     */
    public function fetchPage(string $url, bool $forceHeadless = false): array
    {
        $domain = $this->extractDomain($url);
        
        // Check fail-safe circuit breaker
        if ($this->failSafe && !$this->failSafe->canScrape($domain)) {
            $this->logger->warning('Scraping blocked by fail-safe circuit breaker', [
                'url' => $url,
                'domain' => $domain,
            ]);
            return [
                'html' => '',
                'method' => 'blocked',
                'success' => false,
                'error' => 'Domain in cooldown due to consecutive failures',
                'proxy' => null,
            ];
        }
        
        // Get proxy if available
        $proxy = $this->proxyRotation?->getNextProxy();
        
        // Try static fetch first (unless forced to use headless)
        if (!$forceHeadless) {
            $staticResult = $this->fetchStatic($url, $proxy);
            
            if ($staticResult['success']) {
                // Check if page is likely JS-rendered
                if (!$this->isJavaScriptRendered($staticResult['html'])) {
                    $this->logger->debug('Page fetched via static HTTP', ['url' => $url]);
                    $this->reportSuccess($domain, $proxy);
                    return $staticResult;
                }
                
                $this->logger->info('Page appears to be JS-rendered, trying headless browser', [
                    'url' => $url
                ]);
            } else {
                // Report failure for static fetch
                $this->reportFailure($domain, $proxy, $staticResult['error'] ?? 'Unknown error', $url);
            }
        }
        
        // Try headless browser
        if ($this->pantherAvailable) {
            $result = $this->fetchWithPanther($url, $proxy);
            
            if ($result['success']) {
                $this->reportSuccess($domain, $proxy);
            } else {
                $this->reportFailure($domain, $proxy, $result['error'] ?? 'Panther error', $url);
            }
            
            return $result;
        }
        
        // Fallback: return static result with warning
        $this->logger->warning('Panther not available for JS rendering', ['url' => $url]);
        
        if (isset($staticResult)) {
            $staticResult['method'] = 'static_fallback';
            return $staticResult;
        }
        
        return [
            'html' => '',
            'method' => 'failed',
            'success' => false,
            'error' => 'Could not fetch page and Panther is not available',
            'proxy' => $proxy,
        ];
    }

    /**
     * Fetch page with static HTTP client
     */
    private function fetchStatic(string $url, ?string $proxy = null): array
    {
        try {
            $userAgent = self::USER_AGENTS[array_rand(self::USER_AGENTS)];
            
            $options = [
                'headers' => [
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Accept-Encoding' => 'gzip, deflate, br',
                    'DNT' => '1',
                    'Connection' => 'keep-alive',
                    'Upgrade-Insecure-Requests' => '1',
                ],
                'timeout' => self::STATIC_TIMEOUT,
                'max_redirects' => 5,
            ];
            
            // Add proxy if available
            if ($proxy) {
                $options['proxy'] = $proxy;
            }
            
            $response = $this->httpClient->request('GET', $url, $options);
            
            $statusCode = $response->getStatusCode();
            
            if ($statusCode >= 400) {
                return [
                    'html' => '',
                    'method' => 'static',
                    'success' => false,
                    'error' => "HTTP {$statusCode} response",
                    'status_code' => $statusCode,
                    'proxy' => $proxy,
                ];
            }
            
            return [
                'html' => $response->getContent(),
                'method' => 'static',
                'success' => true,
                'error' => null,
                'proxy' => $proxy,
            ];
            
        } catch (\Exception $e) {
            return [
                'html' => '',
                'method' => 'static',
                'success' => false,
                'error' => $e->getMessage(),
                'proxy' => $proxy,
            ];
        }
    }

    /**
     * Fetch page with Symfony Panther (headless Chrome)
     */
    private function fetchWithPanther(string $url, ?string $proxy = null): array
    {
        try {
            $client = $this->getPantherClient($proxy);
            
            // Random viewport size to appear more human-like
            $width = rand(1200, 1920);
            $height = rand(800, 1080);
            
            // Navigate to URL
            $crawler = $client->request('GET', $url);
            
            // Wait for page to load
            usleep(rand(1500000, 3000000)); // 1.5-3 seconds random delay
            
            // Try to accept cookies if consent dialog appears
            $this->acceptCookies($client);
            
            // Additional wait for dynamic content
            usleep(rand(500000, 1500000)); // 0.5-1.5 seconds
            
            // Get the rendered HTML
            $html = $client->getCrawler()->html();
            
            return [
                'html' => $html,
                'method' => 'panther',
                'success' => true,
                'error' => null,
                'proxy' => $proxy,
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Panther fetch failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'html' => '',
                'method' => 'panther',
                'success' => false,
                'error' => $e->getMessage(),
                'proxy' => $proxy,
            ];
        }
    }

    /**
     * Detect if page is JavaScript-rendered (SPA)
     */
    private function isJavaScriptRendered(string $html): bool
    {
        // Check for SPA framework markers
        foreach (self::SPA_MARKERS as $marker) {
            if (str_contains($html, $marker)) {
                return true;
            }
        }
        
        // Check for very minimal body content (likely JS-rendered)
        $crawler = new Crawler($html);
        try {
            $bodyText = $crawler->filter('body')->text();
            $bodyTextLength = strlen(trim($bodyText));
            
            // If body text is very short, it's likely waiting for JS
            if ($bodyTextLength < 200) {
                // But check if there are noscript tags with content
                $noscriptCount = $crawler->filter('noscript')->count();
                if ($noscriptCount > 0) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // DOM parsing issue, assume it's fine
        }
        
        return false;
    }

    /**
     * Try to accept cookie consent dialog
     */
    private function acceptCookies($client): void
    {
        try {
            foreach (self::COOKIE_ACCEPT_SELECTORS as $selector) {
                try {
                    $crawler = $client->getCrawler();
                    $button = $crawler->filter($selector);
                    
                    if ($button->count() > 0) {
                        $client->click($button->link());
                        usleep(500000); // Wait for dialog to close
                        $this->logger->debug('Accepted cookie consent', ['selector' => $selector]);
                        return;
                    }
                } catch (\Exception $e) {
                    // Selector not found or not clickable, try next
                }
            }
        } catch (\Exception $e) {
            // Cookie acceptance failed, continue anyway
        }
    }

    /**
     * Get or create Panther client (with optional proxy)
     */
    private function getPantherClient(?string $proxy = null): object
    {
        // Always create new client if proxy changes
        if ($this->pantherClient === null || $proxy !== null) {
            if (!$this->pantherAvailable) {
                throw new \RuntimeException('Symfony Panther is not installed');
            }
            
            // If we have an existing client and need to change proxy, quit it first
            if ($this->pantherClient !== null) {
                try {
                    $this->pantherClient->quit();
                } catch (\Exception $e) {
                    // Ignore
                }
                $this->pantherClient = null;
            }
            
            $chromeOptions = [
                '--headless',
                '--disable-gpu',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--disable-software-rasterizer',
                '--window-size=1920,1080',
                '--user-agent=' . self::USER_AGENTS[array_rand(self::USER_AGENTS)],
            ];
            
            // Add proxy if available
            if ($proxy) {
                $chromeOptions[] = '--proxy-server=' . $this->formatProxyForChrome($proxy);
            }
            
            // Create client with Chrome in headless mode
            $this->pantherClient = \Symfony\Component\Panther\Client::createChromeClient(
                null, // Use default chromedriver
                $chromeOptions,
                [
                    'connection_timeout_in_ms' => self::BROWSER_TIMEOUT * 1000,
                    'request_timeout_in_ms' => self::BROWSER_TIMEOUT * 1000,
                ]
            );
        }
        
        return $this->pantherClient;
    }
    
    /**
     * Format proxy URL for Chrome command line
     */
    private function formatProxyForChrome(string $proxy): string
    {
        // Chrome expects: scheme://host:port
        // Credentials need to be handled separately (via extension or environment)
        $parsed = parse_url($proxy);
        
        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? '';
        $port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 8080);
        
        return "{$scheme}://{$host}:{$port}";
    }
    
    /**
     * Extract domain from URL
     */
    private function extractDomain(string $url): string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? $url;
        
        // Remove www prefix
        return preg_replace('/^www\./', '', strtolower($host));
    }
    
    /**
     * Report success to fail-safe and proxy services
     */
    private function reportSuccess(string $domain, ?string $proxy): void
    {
        if ($this->failSafe) {
            $this->failSafe->recordSuccess($domain);
        }
        
        if ($this->proxyRotation && $proxy) {
            $this->proxyRotation->reportSuccess($proxy);
        }
    }
    
    /**
     * Report failure to fail-safe and proxy services
     */
    private function reportFailure(string $domain, ?string $proxy, string $error, string $url): void
    {
        // Extract HTTP status code from error if present
        $httpCode = 0;
        if (preg_match('/HTTP (\d+)/', $error, $matches)) {
            $httpCode = (int) $matches[1];
        }
        
        if ($this->failSafe) {
            $this->failSafe->recordFailure($domain, $httpCode, $error);
        }
        
        if ($this->proxyRotation && $proxy) {
            $this->proxyRotation->reportFailure($proxy, $error, $httpCode === 403);
        }
    }

    /**
     * Check if headless browser is available
     */
    public function isHeadlessAvailable(): bool
    {
        return $this->pantherAvailable;
    }
    
    /**
     * Get scraping statistics
     */
    public function getScrapingStats(): array
    {
        return [
            'headless_available' => $this->pantherAvailable,
            'proxy_enabled' => $this->proxyRotation?->isEnabled() ?? false,
            'proxy_stats' => $this->proxyRotation?->getStats() ?? null,
            'failsafe_domains' => $this->failSafe?->getAllDomainsStatus() ?? [],
            'failsafe_alerts' => $this->failSafe?->getAlerts() ?? [],
        ];
    }

    /**
     * Clean up browser resources
     */
    public function shutdown(): void
    {
        if ($this->pantherClient !== null) {
            try {
                $this->pantherClient->quit();
            } catch (\Exception $e) {
                // Ignore shutdown errors
            }
            $this->pantherClient = null;
        }
    }

    public function __destruct()
    {
        $this->shutdown();
    }
}
