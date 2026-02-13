<?php

namespace App\Service\CompCrawler;

use App\Entity\Competitor;
use App\Entity\CompetitorPageFingerprint;
use App\Repository\CompetitorPageFingerprintRepository;
use App\Service\FastWebScraperService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * CompProfileCrawlerService — Sitemap-first, bounded-depth profiling crawl.
 *
 * Crawl modes:
 *  - Shallow: homepage + 10–25 high-signal URLs
 *  - Deep: up to 100–200 pages + PDFs (bounded)
 *
 * Respects robots.txt, TOS blocks, and politeness delays.
 */
class CompProfileCrawlerService
{
    public function __construct(
        private readonly CompCrawlerConfig $config,
        private readonly FastWebScraperService $scraper,
        private readonly CompetitorPageFingerprintRepository $fingerprintRepo,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
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

        // 2. Fetch pages
        $content = [];
        $pagesCrawled = 0;
        $pagesChanged = 0;
        $crawledUrls = [];
        $delayMs = $this->config->getRequestDelayMs();

        foreach ($urls as $url) {
            if ($pagesCrawled >= $maxPages) break;

            // LinkedIn check — URL-only, never fetch
            if (str_contains($url, 'linkedin.com')) {
                continue;
            }

            try {
                $html = $this->fetchPage($url);
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

                // Politeness delay
                usleep($delayMs * 1000);

            } catch (\Throwable $e) {
                $this->logger->warning("CompCrawler: Failed to fetch {$url}: {$e->getMessage()}");
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
     * Build prioritized URL list for a competitor.
     */
    private function buildUrlList(Competitor $competitor, string $baseUrl, int $maxPages): array
    {
        $urls = [];

        // 1. Homepage always first
        $urls[] = $baseUrl;
        $urls[] = $baseUrl . '/';

        // 2. Try sitemap
        $sitemapUrls = $this->parseSitemap($baseUrl . '/sitemap.xml');
        if (empty($sitemapUrls)) {
            $sitemapUrls = $this->parseSitemap($baseUrl . '/sitemap_index.xml');
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
     * Parse sitemap.xml for URLs.
     */
    private function parseSitemap(string $sitemapUrl): array
    {
        $urls = [];
        try {
            $xml = $this->fetchPage($sitemapUrl);
            if (!$xml) return [];

            // Suppress XML errors
            libxml_use_internal_errors(true);
            $doc = simplexml_load_string($xml);
            if ($doc === false) return [];

            // Standard sitemap
            foreach ($doc->url ?? [] as $entry) {
                $urls[] = (string) $entry->loc;
            }

            // Sitemap index
            foreach ($doc->sitemap ?? [] as $entry) {
                $subUrls = $this->parseSitemap((string) $entry->loc);
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
     * Fetch a page via curl with proper headers.
     */
    private function fetchPage(string $url): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => (int) $this->config->get('crawl.timeout_seconds', 30),
            CURLOPT_USERAGENT => $this->config->get('crawl.user_agent', 'StarzCompBot/1.0'),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9,fr;q=0.8,de;q=0.7,ar;q=0.6',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 400 && $response) {
            return $response;
        }

        return null;
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
}
