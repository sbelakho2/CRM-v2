<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bing search scraper.
 *
 * Microsoft Bing — second largest search engine globally.
 *   - Massive index with excellent coverage
 *   - Returns 10 results per page
 *   - Server-side rendered HTML
 *   - Good European B2B coverage
 *   - Powers Yahoo, DuckDuckGo, Ecosia backends
 *
 * CSS selectors (verified Feb 2026):
 *   - Result container: li.b_algo
 *   - Title link:       h2 a
 *   - Snippet:          p, .b_caption p
 *   - URL:              cite
 */
final class BingScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://www.bing.com/search';

    private const MIN_DELAY_SECONDS = 2.5;
    private const MAX_JITTER_SECONDS = 2.5;

    private float $lastRequestTime = 0.0;

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36 Edg/121.0.0.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    ];

    /** Map region codes to Bing market codes */
    private const REGION_MAP = [
        'DE' => 'de-DE',
        'FR' => 'fr-FR',
        'IT' => 'it-IT',
        'ES' => 'es-ES',
        'GB' => 'en-GB',
        'UK' => 'en-GB',
        'NL' => 'nl-NL',
        'PL' => 'pl-PL',
        'AT' => 'de-AT',
        'CH' => 'de-CH',
        'BE' => 'fr-BE',
        'SE' => 'sv-SE',
        'NO' => 'nb-NO',
        'DK' => 'da-DK',
        'FI' => 'fi-FI',
        'PT' => 'pt-PT',
        'CZ' => 'cs-CZ',
        'US' => 'en-US',
        'RU' => 'ru-RU',
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

        $this->logger->debug('BingScraper: fetching', ['region' => $region]);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => $userAgent,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9,de;q=0.8',
                    'DNT' => '1',
                    'Referer' => 'https://www.bing.com/',
                ],
                'timeout' => 15,
                'max_redirects' => 3,
            ]);

            $statusCode = $response->getStatusCode();
            $this->lastRequestTime = microtime(true);

            if ($statusCode === 403 || $statusCode === 429) {
                throw new \RuntimeException("Bing returned {$statusCode} — rate limited");
            }

            if ($statusCode >= 400) {
                throw new \RuntimeException("Bing returned HTTP {$statusCode}");
            }

            $html = $response->getContent();

            if (str_contains($html, 'captcha') || str_contains($html, 'unusual traffic')) {
                throw new \RuntimeException('Bing CAPTCHA detected');
            }

            return $this->parseResults($html, $maxResults);

        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Bing search failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getEngineName(): string
    {
        return 'bing';
    }

    private function parseResults(string $html, int $maxResults): array
    {
        $crawler = new Crawler($html);
        $results = [];

        // Bing organic results are in li.b_algo
        $resultItems = $crawler->filter('li.b_algo');

        $resultItems->each(function (Crawler $node) use (&$results, $maxResults) {
            if (count($results) >= $maxResults) {
                return;
            }

            try {
                $linkNode = $node->filter('h2 a')->first();
                if ($linkNode->count() === 0) {
                    return;
                }

                $url = $linkNode->attr('href');
                if (!$url || !str_starts_with($url, 'http')) {
                    return;
                }

                if (str_contains($url, 'bing.com') || str_contains($url, 'microsoft.com/bing')) {
                    return;
                }

                $title = trim($linkNode->text());
                if (empty($title)) {
                    return;
                }

                $snippetNode = $node->filter('.b_caption p, p')->first();
                $snippet = $snippetNode->count() > 0 ? trim($snippetNode->text()) : '';

                $citeNode = $node->filter('cite')->first();
                $displayLink = $citeNode->count() > 0 ? trim($citeNode->text()) : $this->extractRootDomain($url);
                $displayLink = preg_replace('/^https?:\/\//', '', $displayLink);
                $displayLink = preg_replace('/\/.*$/', '', $displayLink);

                $results[] = [
                    'link' => $url,
                    'title' => $title,
                    'snippet' => $snippet,
                    'displayLink' => $displayLink,
                ];
            } catch (\Throwable) {
            }
        });

        $this->logger->debug('BingScraper: parsed results', ['count' => count($results)]);
        return $results;
    }

    private function buildUrl(string $query, ?string $region): string
    {
        $params = ['q' => $query];

        if ($region && isset(self::REGION_MAP[strtoupper($region)])) {
            $params['setmkt'] = self::REGION_MAP[strtoupper($region)];
            $params['setlang'] = substr(self::REGION_MAP[strtoupper($region)], 0, 2);
        }

        return self::BASE_URL . '?' . http_build_query($params);
    }

    private function respectRateLimit(): void
    {
        if ($this->lastRequestTime > 0) {
            $elapsed = microtime(true) - $this->lastRequestTime;
            $required = self::MIN_DELAY_SECONDS + (mt_rand() / mt_getrandmax()) * self::MAX_JITTER_SECONDS;
            if ($elapsed < $required) {
                usleep((int)(($required - $elapsed) * 1_000_000));
            }
        }
    }

    private function extractRootDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return $host ? preg_replace('/^www\./', '', strtolower($host)) : '';
    }
}
