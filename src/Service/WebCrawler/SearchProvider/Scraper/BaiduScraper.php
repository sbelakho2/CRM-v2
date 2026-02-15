<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use App\Service\WebCrawler\SearchProvider\HeaderRandomizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Baidu search scraper.
 *
 * Baidu is China's dominant search engine with a massive independent index.
 * Has good coverage of global B2B/industrial companies.
 *
 * Advantages:
 *   - Completely independent infrastructure from Western engines
 *   - Massive index covering international business
 *   - Different IP/rate-limit domain
 *   - Good for manufacturing/industrial queries
 */
final class BaiduScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://www.baidu.com/s';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly HeaderRandomizer $headerRandomizer,
    ) {}

    public function getEngineName(): string
    {
        return 'baidu';
    }

    public function scrapeResults(string $query, ?string $region = null, int $maxResults = 10): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL, [
            'query' => [
                'wd' => $query,
                'rn' => $maxResults,
                'ie' => 'utf-8',
            ],
            'headers' => $this->headerRandomizer->getRandomHeaders($region, self::BASE_URL),
            'timeout' => 15,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException("Baidu returned HTTP {$statusCode}");
        }

        $html = $response->getContent();
        $crawler = new Crawler($html);
        $results = [];

        $crawler->filter('.result, .c-container, div[id^="content_left"] div.result')->each(function (Crawler $node) use (&$results, $maxResults) {
            if (count($results) >= $maxResults) return;

            $titleNode = $node->filter('h3 a, .t a');
            $snippetNode = $node->filter('.c-abstract, .c-content, .c-span-last');
            $urlNode = $node->filter('.c-showurl, cite, span.g');

            $link = $titleNode->count() ? $titleNode->first()->attr('href') : null;
            if (!$link) return;

            // Baidu uses redirect URLs — we use the display URL when available
            $displayUrl = $urlNode->count() ? trim($urlNode->first()->text()) : '';
            if ($displayUrl && !str_starts_with($displayUrl, 'http')) {
                $displayUrl = 'http://' . $displayUrl;
            }

            $finalLink = $displayUrl ?: $link;
            if (!str_starts_with($finalLink, 'http') || str_contains($finalLink, 'baidu.com')) return;

            $results[] = [
                'title' => $titleNode->count() ? trim($titleNode->first()->text()) : '',
                'link' => $finalLink,
                'snippet' => $snippetNode->count() ? trim($snippetNode->first()->text()) : '',
                'displayLink' => parse_url($finalLink, PHP_URL_HOST) ?: '',
            ];
        });

        if (empty($results)) {
            throw new \RuntimeException('Baidu returned no parseable results');
        }

        return $results;
    }
}
