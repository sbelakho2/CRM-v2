<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use App\Service\WebCrawler\SearchProvider\HeaderRandomizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Alexandria.org search scraper.
 *
 * Independent search engine focused on non-commercial websites.
 * Part of the Common Crawl ecosystem — uses its own index.
 *
 * Advantages:
 *   - Truly independent index
 *   - No anti-bot measures
 *   - Finds unique websites not in mainstream indexes
 *   - Good for finding company homepages directly
 */
final class AlexandriaScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://alexandria.org/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly HeaderRandomizer $headerRandomizer,
    ) {}

    public function getEngineName(): string
    {
        return 'alexandria';
    }

    public function scrapeResults(string $query, ?string $region = null, int $maxResults = 10): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL, [
            'query' => ['q' => $query],
            'headers' => $this->headerRandomizer->getRandomHeaders($region, self::BASE_URL),
            'timeout' => 15,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException("Alexandria returned HTTP {$statusCode}");
        }

        $html = $response->getContent();
        $crawler = new Crawler($html);
        $results = [];

        $crawler->filter('.search-result, .result, article, li.result')->each(function (Crawler $node) use (&$results, $maxResults) {
            if (count($results) >= $maxResults) return;

            $titleNode = $node->filter('a[href*="://"]');
            $snippetNode = $node->filter('p, .snippet, .description');

            $link = $titleNode->count() ? $titleNode->first()->attr('href') : null;
            if (!$link || !str_starts_with($link, 'http') || str_contains($link, 'alexandria.org')) return;

            $results[] = [
                'title' => $titleNode->count() ? trim($titleNode->first()->text()) : '',
                'link' => $link,
                'snippet' => $snippetNode->count() ? trim($snippetNode->first()->text()) : '',
                'displayLink' => parse_url($link, PHP_URL_HOST) ?: '',
            ];
        });

        if (empty($results)) {
            throw new \RuntimeException('Alexandria returned no parseable results');
        }

        return $results;
    }
}
