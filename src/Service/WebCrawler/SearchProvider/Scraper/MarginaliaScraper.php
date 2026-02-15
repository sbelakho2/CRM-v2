<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use App\Service\WebCrawler\SearchProvider\HeaderRandomizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Marginalia Search scraper.
 *
 * Independent search engine with its own web index.
 * Focuses on non-commercial, text-heavy websites.
 * Very tolerant of automated queries.
 */
final class MarginaliaScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://search.marginalia.nu/search';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly HeaderRandomizer $headerRandomizer,
    ) {}

    public function getEngineName(): string
    {
        return 'marginalia';
    }

    public function scrapeResults(string $query, ?string $region = null, int $maxResults = 10): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL, [
            'query' => [
                'query' => $query,
                'profile' => 'corpo',
                'js' => 'default',
            ],
            'headers' => $this->headerRandomizer->getRandomHeaders($region, self::BASE_URL),
            'timeout' => 15,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException("Marginalia returned HTTP {$statusCode}");
        }

        $html = $response->getContent();
        $crawler = new Crawler($html);
        $results = [];

        // Marginalia uses card-based layout
        $crawler->filter('.search-result, section .card, .result')->each(function (Crawler $node) use (&$results, $maxResults) {
            if (count($results) >= $maxResults) return;

            $titleNode = $node->filter('h2 a, .title a, a[href]');
            $snippetNode = $node->filter('.description, .snippet, p, .result-body');

            $link = $titleNode->count() ? $titleNode->first()->attr('href') : null;
            if (!$link || !str_starts_with($link, 'http')) return;

            $results[] = [
                'title' => $titleNode->count() ? trim($titleNode->first()->text()) : '',
                'link' => $link,
                'snippet' => $snippetNode->count() ? trim($snippetNode->first()->text()) : '',
                'displayLink' => parse_url($link, PHP_URL_HOST) ?: '',
            ];
        });

        if (empty($results)) {
            throw new \RuntimeException('Marginalia returned no parseable results');
        }

        return $results;
    }
}
