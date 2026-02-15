<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use App\Service\WebCrawler\SearchProvider\HeaderRandomizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Right Dao search scraper.
 *
 * Independent search engine with its own crawler and index.
 * Minimal anti-bot protection, returns clean HTML results.
 */
final class RightDaoScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://rightdao.com/search';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly HeaderRandomizer $headerRandomizer,
    ) {}

    public function getEngineName(): string
    {
        return 'rightdao';
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
            throw new \RuntimeException("RightDao returned HTTP {$statusCode}");
        }

        $html = $response->getContent();
        $crawler = new Crawler($html);
        $results = [];

        $crawler->filter('.result, .search-result, article, li')->each(function (Crawler $node) use (&$results, $maxResults) {
            if (count($results) >= $maxResults) return;

            $titleNode = $node->filter('a[href*="://"]');
            $snippetNode = $node->filter('p, .snippet, .description, span');

            $link = $titleNode->count() ? $titleNode->first()->attr('href') : null;
            if (!$link || !str_starts_with($link, 'http') || str_contains($link, 'rightdao.com')) return;

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
            throw new \RuntimeException('RightDao returned no parseable results');
        }

        return $results;
    }
}
