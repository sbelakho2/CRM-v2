<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use App\Service\WebCrawler\SearchProvider\HeaderRandomizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Presearch scraper.
 *
 * Presearch is a decentralized search engine that sources results
 * from multiple providers. Has its own community-run nodes.
 *
 * Advantages:
 *   - Decentralized — no single point of failure
 *   - Aggregates from multiple sources
 *   - Moderate anti-bot measures
 */
final class PresearchScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://presearch.com/search';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly HeaderRandomizer $headerRandomizer,
    ) {}

    public function getEngineName(): string
    {
        return 'presearch';
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
            throw new \RuntimeException("Presearch returned HTTP {$statusCode}");
        }

        $html = $response->getContent();
        $crawler = new Crawler($html);
        $results = [];

        $crawler->filter('.result, .search-result, article, [data-result]')->each(function (Crawler $node) use (&$results, $maxResults) {
            if (count($results) >= $maxResults) return;

            $titleNode = $node->filter('a[href*="://"]');
            $snippetNode = $node->filter('p, .snippet, .description, .result-description');

            $link = $titleNode->count() ? $titleNode->first()->attr('href') : null;
            if (!$link || !str_starts_with($link, 'http') || str_contains($link, 'presearch.com')) return;

            $title = $titleNode->count() ? trim($titleNode->first()->text()) : '';
            if (empty($title)) return;

            $results[] = [
                'title' => $title,
                'link' => $link,
                'snippet' => $snippetNode->count() ? trim($snippetNode->first()->text()) : '',
                'displayLink' => parse_url($link, PHP_URL_HOST) ?: '',
            ];
        });

        if (empty($results)) {
            throw new \RuntimeException('Presearch returned no parseable results');
        }

        return $results;
    }
}
