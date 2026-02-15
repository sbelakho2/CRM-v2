<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use App\Service\WebCrawler\SearchProvider\HeaderRandomizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Ask.com search scraper.
 *
 * Ask.com (formerly Ask Jeeves) — still functioning with Bing-powered results.
 * Different domain = different rate limit bucket.
 */
final class AskScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://www.ask.com/web';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly HeaderRandomizer $headerRandomizer,
    ) {}

    public function getEngineName(): string
    {
        return 'ask';
    }

    public function scrapeResults(string $query, ?string $region = null, int $maxResults = 10): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL, [
            'query' => [
                'q' => $query,
                'qsrc' => '0',
                'o' => '0',
            ],
            'headers' => $this->headerRandomizer->getRandomHeaders($region, self::BASE_URL),
            'timeout' => 15,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException("Ask.com returned HTTP {$statusCode}");
        }

        $html = $response->getContent();
        $crawler = new Crawler($html);
        $results = [];

        $crawler->filter('.PartialSearchResults-item, .result, .algo, .search-result')->each(function (Crawler $node) use (&$results, $maxResults) {
            if (count($results) >= $maxResults) return;

            $titleNode = $node->filter('.PartialSearchResults-item-title a, .result-title a, h3 a, a[href*="://"]');
            $snippetNode = $node->filter('.PartialSearchResults-item-abstract, .result-abstract, p');

            $link = $titleNode->count() ? $titleNode->first()->attr('href') : null;
            if (!$link) return;

            if (!str_starts_with($link, 'http') || str_contains($link, 'ask.com')) return;

            $results[] = [
                'title' => $titleNode->count() ? trim($titleNode->first()->text()) : '',
                'link' => $link,
                'snippet' => $snippetNode->count() ? trim($snippetNode->first()->text()) : '',
                'displayLink' => parse_url($link, PHP_URL_HOST) ?: '',
            ];
        });

        if (empty($results)) {
            throw new \RuntimeException('Ask.com returned no parseable results');
        }

        return $results;
    }
}
