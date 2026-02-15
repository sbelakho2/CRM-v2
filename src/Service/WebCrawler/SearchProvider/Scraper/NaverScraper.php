<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use App\Service\WebCrawler\SearchProvider\HeaderRandomizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Naver search scraper.
 *
 * Naver is South Korea's dominant search engine with global web search.
 * Has its own massive index and is completely independent of Google/Bing.
 *
 * Advantages:
 *   - Completely independent index and infrastructure
 *   - Different IP/rate-limit domain than all Western engines
 *   - Good for finding international B2B companies
 *   - Less likely to block European/US IPs
 */
final class NaverScraper implements SearchEngineScraper
{
    // Naver's global search (English-supported)
    private const BASE_URL = 'https://search.naver.com/search.naver';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly HeaderRandomizer $headerRandomizer,
    ) {}

    public function getEngineName(): string
    {
        return 'naver';
    }

    public function scrapeResults(string $query, ?string $region = null, int $maxResults = 10): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL, [
            'query' => [
                'query' => $query,
                'where' => 'web',
            ],
            'headers' => $this->headerRandomizer->getRandomHeaders($region, self::BASE_URL),
            'timeout' => 15,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException("Naver returned HTTP {$statusCode}");
        }

        $html = $response->getContent();
        $crawler = new Crawler($html);
        $results = [];

        $crawler->filter('.total_wrap, .sp_web, .web_item, .total_tit')->each(function (Crawler $node) use (&$results, $maxResults) {
            if (count($results) >= $maxResults) return;

            $titleNode = $node->filter('a.link_tit, a.total_tit, a[href*="://"]');
            $snippetNode = $node->filter('.total_dsc, .dsc_txt, .api_txt_lines');

            $link = $titleNode->count() ? $titleNode->first()->attr('href') : null;
            if (!$link || !str_starts_with($link, 'http') || str_contains($link, 'naver.com')) return;

            $results[] = [
                'title' => $titleNode->count() ? trim($titleNode->first()->text()) : '',
                'link' => $link,
                'snippet' => $snippetNode->count() ? trim($snippetNode->first()->text()) : '',
                'displayLink' => parse_url($link, PHP_URL_HOST) ?: '',
            ];
        });

        if (empty($results)) {
            throw new \RuntimeException('Naver returned no parseable results');
        }

        return $results;
    }
}
