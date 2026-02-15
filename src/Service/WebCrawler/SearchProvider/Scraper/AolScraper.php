<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use App\Service\WebCrawler\SearchProvider\HeaderRandomizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * AOL Search scraper.
 *
 * AOL Search uses the Bing index but with different URL/domain,
 * giving us another IP endpoint for the same high-quality results.
 * Different rate limit bucket than Bing.
 */
final class AolScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://search.aol.com/aol/search';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly HeaderRandomizer $headerRandomizer,
    ) {}

    public function getEngineName(): string
    {
        return 'aol';
    }

    public function scrapeResults(string $query, ?string $region = null, int $maxResults = 10): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL, [
            'query' => [
                'q' => $query,
                'v_t' => 'comsearch',
            ],
            'headers' => $this->headerRandomizer->getRandomHeaders($region, self::BASE_URL),
            'timeout' => 15,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException("AOL returned HTTP {$statusCode}");
        }

        $html = $response->getContent();
        $crawler = new Crawler($html);
        $results = [];

        $crawler->filter('.algo-sr, .dd.algo, .sr, .algo')->each(function (Crawler $node) use (&$results, $maxResults) {
            if (count($results) >= $maxResults) return;

            $titleNode = $node->filter('h3 a, .title a, a.ac-algo');
            $snippetNode = $node->filter('.compText, p, .fc-falcon');

            $link = $titleNode->count() ? $titleNode->first()->attr('href') : null;
            if (!$link) return;

            // AOL wraps URLs in a redirect — extract real URL
            if (str_contains($link, '/RU=')) {
                if (preg_match('/\/RU=([^\/]+)\//', $link, $matches)) {
                    $link = urldecode($matches[1]);
                }
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
            throw new \RuntimeException('AOL returned no parseable results');
        }

        return $results;
    }
}
