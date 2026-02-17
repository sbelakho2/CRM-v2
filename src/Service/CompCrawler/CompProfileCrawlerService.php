<?php

namespace App\Service\CompCrawler;

use App\Entity\Competitor;
use App\Entity\CompetitorPageFingerprint;
use App\Repository\CompetitorPageFingerprintRepository;
use App\Service\FastWebScraperService;
use App\Service\WebCrawler\SearchProvider\HeaderRandomizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * CompProfileCrawlerService — Sitemap-first, bounded-depth profiling crawl.
 *
 * Crawl modes:
 *  - Shallow: homepage + 10–25 high-signal URLs
 *  - Deep: up to 100–200 pages + PDFs (bounded)
 *
 * Features:
 *  - curl_multi concurrent fetching for speed
 *  - HeaderRandomizer for anti-detection (reused from LeadBot)
 *  - Retry with exponential backoff on transient errors
 *  - Sitemap gzip support + sitemap index recursion
 *  - Proper SSL verification (with selective fallback)
 *  - Content caching between pipeline phases via filesystem
 */
class CompProfileCrawlerService
{
    /** Maximum concurrent connections for curl_multi */
    private const MAX_CONCURRENT = 10;

    /** Maximum retry attempts for transient failures */
    private const MAX_RETRIES = 3;

    /** Cache directory for content between pipeline phases */
    private const CACHE_DIR = 'var/comp_crawl_cache';

    public function __construct(
        private readonly CompCrawlerConfig $config,
        private readonly FastWebScraperService $scraper,
        private readonly HeaderRandomizer $headerRandomizer,
        private readonly CompetitorPageFingerprintRepository $fingerprintRepo,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {}

    /**
     * Perform a shallow crawl — homepage + high-signal URLs.
     *
     * @return array{pages_crawled: int, pages_changed: int, urls: string[], content: array<string,string>}
     */
    public function shallowCrawl(Competitor $competitor): array
    {
        $maxPages = $this->config->getMaxPagesShallow();
        return $this->crawl($competitor, $maxPages, 'shallow');
    }

    /**
     * Perform a deep crawl — sitemap + expanded URL set + PDFs.
     *
     * @return array{pages_crawled: int, pages_changed: int, urls: string[], content: array<string,string>}
     */
    public function deepCrawl(Competitor $competitor): array
    {
        $maxPages = $this->config->getMaxPagesDeep();
        return $this->crawl($competitor, $maxPages, 'deep');
    }

    /**
     * Retrieve cached crawl content for a competitor (used by extract phase).
     * Returns empty array if no cache exists.
     */
    public function getCachedContent(Competitor $competitor): array
    {
        $cacheFile = $this->getCacheFilePath($competitor);
        if (!file_exists($cacheFile)) {
            return [];
        }

        $data = @json_decode(file_get_contents($cacheFile), true);
        if (!is_array($data)) {
            return [];
        }

        // Handle new envelope format with _meta
        if (isset($data['_meta']) && isset($data['content'])) {
            return $data['content'];
        }

        return $data;
    }

    /**
     * Retrieve metadata from the cached crawl (e.g. pages_changed count).
     */
    public function getCachedMeta(Competitor $competitor): array
    {
        $cacheFile = $this->getCacheFilePath($competitor);
        if (!file_exists($cacheFile)) {
            return ['pages_changed' => 0, 'cached_at' => 0];
        }

        $data = @json_decode(file_get_contents($cacheFile), true);
        if (is_array($data) && isset($data['_meta'])) {
            return $data['_meta'];
        }

        return ['pages_changed' => 0, 'cached_at' => 0];
    }

    /**
     * Clear cached crawl content for a competitor.
     */
    public function clearCache(Competitor $competitor): void
    {
        $cacheFile = $this->getCacheFilePath($competitor);
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }
    }

    /**
     * Core crawl method.
     */
    private function crawl(Competitor $competitor, int $maxPages, string $mode): array
    {
        $domain = $competitor->getCanonicalDomain();
        $baseUrl = "https://{$domain}";

        if ($competitor->isTosBlocksCrawl() || $competitor->isSeedOnly()) {
            $this->logger->info("CompCrawler: Skipping {$domain} (TOS block or seed-only)");
            return ['pages_crawled' => 0, 'pages_changed' => 0, 'urls' => [], 'content' => []];
        }

        $this->logger->info("CompCrawler: Starting {$mode} crawl of {$domain}", ['maxPages' => $maxPages]);

        // 1. Build URL priority list
        $urls = $this->buildUrlList($competitor, $baseUrl, $maxPages);

        // Filter out LinkedIn URLs
        $urls = array_values(array_filter($urls, fn(string $u) => !str_contains($u, 'linkedin.com')));

        // Limit to maxPages
        $urls = array_slice($urls, 0, $maxPages);

        // 2. Fetch pages using curl_multi for concurrency
        $content = [];
        $pagesCrawled = 0;
        $pagesChanged = 0;
        $crawledUrls = [];
        $delayMs = $this->config->getRequestDelayMs();

        // Process URLs in batches via curl_multi
        $batches = array_chunk($urls, self::MAX_CONCURRENT);

        foreach ($batches as $batch) {
            $batchResults = $this->fetchBatch($batch, $domain);

            foreach ($batchResults as $url => $html) {
                if ($html === null) continue;

                $pagesCrawled++;
                $crawledUrls[] = $url;

                // Fingerprint check
                $contentHash = hash('sha256', $html);
                $urlHash = hash('sha256', $url);
                $fingerprint = $this->fingerprintRepo->findByCompetitorAndUrl($competitor->getId(), $urlHash);

                if ($fingerprint) {
                    if ($fingerprint->hasChanged($contentHash)) {
                        $pagesChanged++;
                        $fingerprint->setContentHash($contentHash);
                        $fingerprint->setLastCheckedAt(new \DateTime());
                    } else {
                        $fingerprint->setLastCheckedAt(new \DateTime());
                    }
                } else {
                    $fingerprint = new CompetitorPageFingerprint();
                    $fingerprint->setCompetitor($competitor);
                    $fingerprint->setUrl($url);
                    $fingerprint->setContentHash($contentHash);
                    $fingerprint->setPageType($this->classifyPageType($url));
                    $this->em->persist($fingerprint);
                    $pagesChanged++;
                }

                $content[$url] = $html;
            }

            // Politeness delay between batches (per-domain courtesy)
            if (!empty($batches) && $batch !== end($batches)) {
                usleep($delayMs * 1000);
            }
        }

        // Update competitor crawl metadata
        $now = new \DateTime();
        $competitor->setLastCrawledAt($now);
        $competitor->setPagesCrawled($competitor->getPagesCrawled() + $pagesCrawled);

        if ($mode === 'shallow') {
            $competitor->setLastShallowCrawlAt($now);
        } else {
            $competitor->setLastDeepCrawlAt($now);
        }

        $this->em->flush();

        // Cache content to filesystem for extract phase (avoids double-crawl)
        if (!empty($content)) {
            $this->cacheContent($competitor, $content, $pagesChanged);
        }

        $this->logger->info("CompCrawler: {$mode} crawl of {$domain} complete", [
            'pages_crawled' => $pagesCrawled,
            'pages_changed' => $pagesChanged,
        ]);

        return [
            'pages_crawled' => $pagesCrawled,
            'pages_changed' => $pagesChanged,
            'urls' => $crawledUrls,
            'content' => $content,
        ];
    }

    /**
     * Fetch a batch of URLs concurrently using curl_multi.
     *
     * @param string[] $urls URLs to fetch
     * @param string $domain Domain for header context
     * @return array<string, string|null> URL → HTML content (null on failure)
     */
    private function fetchBatch(array $urls, string $domain): array
    {
        $results = [];
        $handles = [];
        $mh = curl_multi_init();

        // Determine region hint from domain TLD for Accept-Language
        $parts = explode('.', $domain);
        $tld = end($parts);
        $regionMap = ['de' => 'de', 'fr' => 'fr', 'it' => 'it', 'es' => 'es', 'nl' => 'nl', 'ma' => 'ar', 'ae' => 'ar'];
        $regionHint = $regionMap[$tld] ?? null;

        foreach ($urls as $url) {
            $ch = curl_init();

            // Get randomized headers from HeaderRandomizer
            $headers = $this->headerRandomizer->getRandomHeaders($regionHint, $url);

            $httpHeaders = [];
            foreach ($headers as $key => $value) {
                if ($key === 'User-Agent') continue; // Set via CURLOPT_USERAGENT
                $httpHeaders[] = "{$key}: {$value}";
            }

            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => (int) $this->config->get('crawl.timeout_seconds', 30),
                CURLOPT_USERAGENT => $headers['User-Agent'] ?? $this->headerRandomizer->getRandomUserAgent(),
                CURLOPT_SSL_VERIFYPEER => true,  // Secure by default
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING => '',  // Accept gzip/deflate/br
                CURLOPT_HTTPHEADER => $httpHeaders,
            ]);

            curl_multi_add_handle($mh, $ch);
            $handles[(int) $ch] = ['ch' => $ch, 'url' => $url];
        }

        // Execute all handles concurrently
        $active = null;
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 1);
            }
        } while ($active && $status === CURLM_OK);

        // Collect results
        $failedUrls = [];
        foreach ($handles as $info) {
            $ch = $info['ch'];
            $url = $info['url'];

            $response = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 400 && $response) {
                $results[$url] = $response;
            } else {
                // Track for retry on transient errors
                if ($httpCode >= 500 || $httpCode === 0 || $httpCode === 429) {
                    $failedUrls[] = $url;
                    $this->logger->debug("CompCrawler: Transient failure for {$url} (HTTP {$httpCode})", [
                        'error' => $curlError,
                    ]);
                } else {
                    $this->logger->debug("CompCrawler: Permanent failure for {$url} (HTTP {$httpCode})");
                    $results[$url] = null;
                }
            }
        }

        curl_multi_close($mh);

        // Retry transient failures with exponential backoff (sequential, SSL fallback)
        foreach ($failedUrls as $url) {
            $html = $this->fetchWithRetry($url, $regionHint);
            $results[$url] = $html;
        }

        return $results;
    }

    /**
     * Fetch a single URL with retry and exponential backoff.
     * Falls back to SSL-verify-off on certificate errors.
     */
    private function fetchWithRetry(string $url, ?string $regionHint = null): ?string
    {
        $delays = [1000, 3000, 9000]; // ms between retries

        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                usleep($delays[$attempt - 1] * 1000);
            }

            $headers = $this->headerRandomizer->getRandomHeaders($regionHint, $url);
            $httpHeaders = [];
            foreach ($headers as $key => $value) {
                if ($key === 'User-Agent') continue;
                $httpHeaders[] = "{$key}: {$value}";
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => (int) $this->config->get('crawl.timeout_seconds', 30),
                CURLOPT_USERAGENT => $headers['User-Agent'] ?? $this->headerRandomizer->getRandomUserAgent(),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => $httpHeaders,
            ]);

            // On last attempt, relax SSL for sites with bad certs
            if ($attempt === self::MAX_RETRIES - 1) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 400 && $response) {
                return $response;
            }

            // Rate limited — wait longer
            if ($httpCode === 429) {
                $this->logger->info("CompCrawler: Rate limited on {$url}, backing off");
                usleep(($delays[$attempt] ?? 9000) * 2 * 1000);
            }

            $this->logger->debug("CompCrawler: Retry {$attempt} failed for {$url} (HTTP {$httpCode}): {$curlError}");
        }

        return null;
    }

    /**
     * Fetch a single page (used for sitemaps, verification, and single-page needs).
     * Uses HeaderRandomizer and SSL verification with fallback.
     */
    public function fetchPage(string $url): ?string
    {
        return $this->fetchWithRetry($url);
    }

    /**
     * Build prioritized URL list for a competitor.
     */
    private function buildUrlList(Competitor $competitor, string $baseUrl, int $maxPages): array
    {
        $urls = [];

        // 1. Homepage always first
        $urls[] = $baseUrl;
        $urls[] = $baseUrl . '/';

        // 2. Try sitemap (with gzip support)
        $sitemapUrls = $this->parseSitemap($baseUrl . '/sitemap.xml');
        if (empty($sitemapUrls)) {
            $sitemapUrls = $this->parseSitemap($baseUrl . '/sitemap_index.xml');
        }
        // Try gzipped sitemap
        if (empty($sitemapUrls)) {
            $sitemapUrls = $this->parseSitemap($baseUrl . '/sitemap.xml.gz');
        }

        // 3. Priority paths based on competitor type
        $types = $competitor->getCompetitorTypes();
        $priorityPaths = $this->config->getPriorityPaths('common');

        // Add type-specific paths
        $first = $types[0] ?? '';
        if (in_array($first, Competitor::TYPE_GROUP_CORE_EMS, true)) {
            $priorityPaths = array_merge($priorityPaths, $this->config->getPriorityPaths('ems'));
        } elseif (in_array($first, Competitor::TYPE_GROUP_MACHINING, true)) {
            $priorityPaths = array_merge($priorityPaths, $this->config->getPriorityPaths('machining'));
        } elseif (in_array($first, Competitor::TYPE_GROUP_INTERCONNECT, true)) {
            $priorityPaths = array_merge($priorityPaths, $this->config->getPriorityPaths('harness'));
        } elseif (in_array($first, Competitor::TYPE_GROUP_ENERGY, true)) {
            $priorityPaths = array_merge($priorityPaths, $this->config->getPriorityPaths('supercapacitor'));
        }

        // Add multilingual paths
        foreach ($this->config->get('priority_paths.multilingual', []) as $lang => $langPaths) {
            $priorityPaths = array_merge($priorityPaths, $langPaths);
        }

        foreach ($priorityPaths as $path) {
            $urls[] = $baseUrl . $path;
        }

        // 4. Merge sitemap URLs (prefer high-signal pages)
        foreach ($sitemapUrls as $sitemapUrl) {
            $urls[] = $sitemapUrl;
        }

        // Deduplicate and limit
        $urls = array_unique($urls);
        return array_slice($urls, 0, $maxPages * 2); // Extra buffer for 404s
    }

    /**
     * Parse sitemap.xml for URLs (supports gzip and sitemap indexes).
     */
    private function parseSitemap(string $sitemapUrl): array
    {
        $urls = [];
        try {
            $raw = $this->fetchPage($sitemapUrl);
            if (!$raw) return [];

            // Handle gzip-compressed sitemaps
            $xml = $raw;
            if (str_ends_with($sitemapUrl, '.gz') || substr($raw, 0, 2) === "\x1f\x8b") {
                $decompressed = @gzdecode($raw);
                if ($decompressed !== false) {
                    $xml = $decompressed;
                }
            }

            // Suppress XML errors
            libxml_use_internal_errors(true);
            $doc = simplexml_load_string($xml);
            if ($doc === false) return [];

            // Standard sitemap
            foreach ($doc->url ?? [] as $entry) {
                $urls[] = (string) $entry->loc;
            }

            // Sitemap index — recursively parse child sitemaps
            foreach ($doc->sitemap ?? [] as $entry) {
                $childUrl = (string) $entry->loc;
                $subUrls = $this->parseSitemap($childUrl);
                $urls = array_merge($urls, $subUrls);
                if (count($urls) > 500) break;
            }

            libxml_clear_errors();
        } catch (\Throwable $e) {
            // Sitemap not available, that's fine
        }

        return array_slice($urls, 0, 500);
    }

    /**
     * Classify page type from URL path.
     */
    private function classifyPageType(string $url): string
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '/');

        $typeMap = [
            'capabilities' => ['capabilities', 'services', 'manufacturing', 'machining', 'assembly'],
            'certifications' => ['certif', 'quality', 'compliance', 'iso', 'standards'],
            'facilities' => ['facilities', 'locations', 'plants', 'factory'],
            'equipment' => ['equipment', 'technology', 'machines'],
            'industries' => ['industries', 'sectors', 'markets', 'automotive', 'aerospace', 'medical'],
            'customers' => ['customers', 'clients', 'case-stud', 'testimonial', 'partners'],
            'careers' => ['careers', 'jobs', 'hiring', 'employment'],
            'news' => ['news', 'press', 'blog', 'media'],
            'contact' => ['contact', 'about'],
            'products' => ['products', 'datasheets', 'catalog', 'supercapacitor', 'edlc'],
        ];

        foreach ($typeMap as $type => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($path, $pattern)) {
                    return $type;
                }
            }
        }

        return 'general';
    }

    // ─── Content Caching ───────────────────────────────────────────────────

    /**
     * Cache crawl content to filesystem for later use by extract phase.
     */
    private function cacheContent(Competitor $competitor, array $content, int $pagesChanged = 0): void
    {
        $cacheDir = $this->projectDir . '/' . self::CACHE_DIR;
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        $cacheFile = $this->getCacheFilePath($competitor);
        $payload = [
            '_meta' => [
                'pages_changed' => $pagesChanged,
                'cached_at' => time(),
            ],
            'content' => $content,
        ];
        file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Get filesystem cache path for a competitor's crawl content.
     */
    private function getCacheFilePath(Competitor $competitor): string
    {
        return $this->projectDir . '/' . self::CACHE_DIR . '/' . md5($competitor->getCanonicalDomain()) . '.json';
    }
}
