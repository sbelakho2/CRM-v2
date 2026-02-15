<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use App\Service\WebCrawler\SearchProvider\HeaderRandomizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lycos search scraper.
 *
 * Lycos is one of the original search engines (est. 1994).
 * Still operational with Bing-powered results.
 */
final class LycosScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://search.lycos.com/web/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly HeaderRandomizer $headerRandomizer,
    ) {}

    public function getEngineName(): string
    {
        return 'lycos';
    }

    public function scrapeResults(string $query, ?string $region = null, int $maxResults = 10): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL, [
            'query' => [
                'q' => $query,
                'keession' => 'searchbox',
            ],
            'headers' => $this->headerRandomizer->getRandomHeaders($region, self::BASE_URL),
            'timeout' => 15,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException("Lycos returned HTTP {$statusCode}");
        }

        $html = $response->getContent();
        $crawler = new Crawler($html);
        $results = [];

        $crawler->filter('.result-item, .results-item, .result, li.search-result')->each(function (Crawler $node) use (&$results, $maxResults) {
            if (count($results) >= $maxResults) return;

            $titleNode = $node->filter('h3 a, .result-title a, a[href*="://"]');
            $snippetNode = $node->filter('.result-description, .result-text, p');
            $urlNode = $node->filter('.result-url, cite, span.url');

            $link = $titleNode->count() ? $titleNode->first()->attr('href') : null;
            if (!$link) return;

            // Lycos sometimes wraps URLs in redirects
            if (str_contains($link, 'lycos.com') && str_contains($link, 'as=')) {
                parse_str(parse_url($link, PHP_URL_QUERY) ?? '', $params);
                $link = $params['as'] ?? $link;
            }

            if (!str_starts_with($link, 'http') || str_contains($link, 'lycos.com')) return;

            $results[] = [
                'title' => $titleNode->count() ? trim($titleNode->first()->text()) : '',
                'link' => $link,
                'snippet' => $snippetNode->count() ? trim($snippetNode->first()->text()) : '',
                'displayLink' => parse_url($link, PHP_URL_HOST) ?: '',
            ];
        });

        if (empty($results)) {
            throw new \RuntimeException('Lycos returned no parseable results');
        }

        return $results;
    }
}
