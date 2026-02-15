<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use App\Service\WebCrawler\SearchProvider\HeaderRandomizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Dogpile meta-search engine scraper.
 *
 * Aggregates results from Google, Yahoo, Bing, and Yandex.
 * Established in 1996 — one of the original meta-search engines.
 */
final class DogpileScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://www.dogpile.com/serp';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly HeaderRandomizer $headerRandomizer,
    ) {}

    public function getEngineName(): string
    {
        return 'dogpile';
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
            throw new \RuntimeException("Dogpile returned HTTP {$statusCode}");
        }

        $html = $response->getContent();
        $crawler = new Crawler($html);
        $results = [];

        $crawler->filter('.web-bing__result, .web-result, .result')->each(function (Crawler $node) use (&$results, $maxResults) {
            if (count($results) >= $maxResults) return;

            $titleNode = $node->filter('.web-bing__title a, .result__title a, a.result-title, h2 a');
            $snippetNode = $node->filter('.web-bing__description, .result__description, .result-snippet, p');

            $link = $titleNode->count() ? $titleNode->first()->attr('href') : null;
            if (!$link) return;

            // Clean tracking URLs
            if (str_contains($link, '/redirect?') || str_contains($link, 'ru=')) {
                parse_str(parse_url($link, PHP_URL_QUERY) ?? '', $params);
                $link = $params['ru'] ?? $params['url'] ?? $params['u'] ?? $link;
                $link = urldecode($link);
            }

            if (!str_starts_with($link, 'http')) return;

            $results[] = [
                'title' => $titleNode->count() ? trim($titleNode->first()->text()) : '',
                'link' => $link,
                'snippet' => $snippetNode->count() ? trim($snippetNode->first()->text()) : '',
                'displayLink' => parse_url($link, PHP_URL_HOST) ?: '',
            ];
        });

        if (empty($results)) {
            throw new \RuntimeException('Dogpile returned no parseable results');
        }

        return $results;
    }
}
