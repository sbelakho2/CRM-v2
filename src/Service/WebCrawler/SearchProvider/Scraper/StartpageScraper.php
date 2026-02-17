<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Startpage search scraper.
 *
 * TERTIARY fallback engine — used when Brave and DDG are in cooldown.
 *
 * Advantages:
 *   - Uses Google's index (high quality)
 *   - Privacy-focused (less aggressive bot detection for simple queries)
 *   - Direct URLs (no redirect wrapper)
 *
 * Limitations:
 *   - Aggressive CAPTCHA on LinkedIn site: queries
 *   - 10 results per page
 *   - More sensitive to rapid queries
 *
 * CSS selectors (verified Feb 2026):
 *   - Result link:    a.result-title.result-link[data-testid="gl-title-link"]
 *   - Result snippet: div.result-description (or p.description)
 */
final class StartpageScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://www.startpage.com/sp/search';

    /** Minimum seconds between requests */
    private const MIN_DELAY_SECONDS = 4.0;

    /** Maximum additional random jitter (seconds) */
    private const MAX_JITTER_SECONDS = 4.0;

    private float $lastRequestTime = 0.0;

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:122.0) Gecko/20100101 Firefox/122.0',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function scrapeResults(string $query, ?string $region = null, int $maxResults = 10): array
    {
        $this->respectRateLimit();

        $url = $this->buildUrl($query);
        $userAgent = self::USER_AGENTS[array_rand(self::USER_AGENTS)];

        $this->logger->debug('StartpageScraper: fetching', [
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

            // Check if redirected to CAPTCHA
            $finalUrl = $response->getInfo('url') ?? '';
            if (str_contains($finalUrl, 'captcha')) {
                throw new \RuntimeException('Startpage CAPTCHA redirect detected');
            }

            if ($statusCode === 429) {
                throw new \RuntimeException('Startpage rate limit (HTTP 429)');
            }

            if ($statusCode >= 400) {
                throw new \RuntimeException("Startpage returned HTTP {$statusCode}");
            }

            $html = $response->getContent();

            // Check for CAPTCHA
            if (str_contains($html, 'captcha') || $this->isCaptchaPage($html)) {
                throw new \RuntimeException('Startpage CAPTCHA detected');
            }

            return $this->parseResults($html, $maxResults);

        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Startpage search failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getEngineName(): string
    {
        return 'startpage';
    }

    // ──────────────────────────────────────────────────────────────────────
    // HTML Parsing
    // ──────────────────────────────────────────────────────────────────────

    private function parseResults(string $html, int $maxResults): array
    {
        $crawler = new Crawler($html);
        $results = [];

        // Try primary selector
        $resultLinks = $crawler->filter('a.result-title.result-link[data-testid="gl-title-link"]');

        if ($resultLinks->count() === 0) {
            // Fallback: try broader selector
            $resultLinks = $crawler->filter('a.result-link[href]');
        }

        $resultLinks->each(function (Crawler $node) use (&$results, $maxResults) {
            if (count($results) >= $maxResults) {
                return;
            }

            try {
                $url = $node->attr('href') ?? '';
                $title = trim($node->text(''));

                if (empty($url) || empty($title)) {
                    return;
                }

                // Skip internal URLs
                if (str_starts_with($url, '/') || str_contains($url, 'startpage.com')) {
                    return;
                }

                // Get snippet from sibling elements
                $snippet = $this->extractSnippet($node);
                $displayLink = parse_url($url, PHP_URL_HOST) ?? '';

                if (empty($displayLink)) {
                    return;
                }

                $results[] = [
                    'link' => $url,
                    'title' => $title,
                    'snippet' => $snippet,
                    'displayLink' => $displayLink,
                    'formattedUrl' => $url,
                ];
            } catch (\Throwable) {
                // Skip malformed results
            }
        });

        $this->logger->debug('StartpageScraper: parsed', [
            'count' => count($results),
        ]);

        return $results;
    }

    private function extractSnippet(Crawler $linkNode): string
    {
        try {
            // Navigate up to find the result container
            $parent = $linkNode->closest('.w-gl__result');
            if ($parent->count() > 0) {
                $description = $parent->filter('p.w-gl__description');
                if ($description->count() > 0) {
                    return trim($description->text(''));
                }
            }

            // Try another common pattern
            $parent = $linkNode->closest('.result');
            if ($parent->count() > 0) {
                $description = $parent->filter('.result-description, p.description');
                if ($description->count() > 0) {
                    return trim($description->text(''));
                }
            }
        } catch (\Throwable) {
            // Snippet extraction failed
        }

        return '';
    }

    private function isCaptchaPage(string $html): bool
    {
        $markers = [
            'please complete the security check',
            'verify you are human',
            'robot',
            'challenge-form',
        ];

        $htmlLower = strtolower($html);
        foreach ($markers as $marker) {
            if (str_contains($htmlLower, $marker)) {
                return true;
            }
        }

        // If page is very small, likely CAPTCHA
        return strlen($html) < 2000;
    }

    // ──────────────────────────────────────────────────────────────────────
    // URL Building and Rate Limiting
    // ──────────────────────────────────────────────────────────────────────

    private function buildUrl(string $query): string
    {
        return self::BASE_URL . '?' . http_build_query(['q' => $query]);
    }

    private function respectRateLimit(): void
    {
        if ($this->lastRequestTime > 0) {
            $elapsed = microtime(true) - $this->lastRequestTime;
            $delay = self::MIN_DELAY_SECONDS + (mt_rand(0, (int)(self::MAX_JITTER_SECONDS * 1000)) / 1000);

            if ($elapsed < $delay) {
                $sleepUs = (int)(($delay - $elapsed) * 1_000_000);
                usleep($sleepUs);
            }
        }
    }
}
