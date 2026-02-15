<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use App\Service\WebCrawler\SearchProvider\HeaderRandomizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Info.com meta-search scraper.
 *
 * Info.com is a meta-search engine that aggregates results from
 * multiple search providers. Different rate limit bucket.
 */
final class InfoScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://www.info.com/serp';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly HeaderRandomizer $headerRandomizer,
    ) {}

    public function getEngineName(): string
    {
        return 'info';
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
            throw new \RuntimeException("Info.com returned HTTP {$statusCode}");
        }

        $html = $response->getContent();
        $crawler = new Crawler($html);
        $results = [];

        $crawler->filter('.web-bing__result, .algo-sr, .result, .web-result')->each(function (Crawler $node) use (&$results, $maxResults) {
            if (count($results) >= $maxResults) return;

            $titleNode = $node->filter('h3 a, .title a, a.algo, a[href*="://"]');
            $snippetNode = $node->filter('.web-bing__description, .compText, p');

            $link = $titleNode->count() ? $titleNode->first()->attr('href') : null;
            if (!$link) return;

            // Unwrap redirect URLs
            if (str_contains($link, '/redirect?') || str_contains($link, 'ru=')) {
                parse_str(parse_url($link, PHP_URL_QUERY) ?? '', $params);
                $link = urldecode($params['ru'] ?? $params['url'] ?? $params['u'] ?? $link);
            }

            if (!str_starts_with($link, 'http') || str_contains($link, 'info.com')) return;

            $results[] = [
                'title' => $titleNode->count() ? trim($titleNode->first()->text()) : '',
                'link' => $link,
                'snippet' => $snippetNode->count() ? trim($snippetNode->first()->text()) : '',
                'displayLink' => parse_url($link, PHP_URL_HOST) ?: '',
            ];
        });

        if (empty($results)) {
            throw new \RuntimeException('Info.com returned no parseable results');
        }

        return $results;
    }
}
