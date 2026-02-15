<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use App\Service\WebCrawler\SearchProvider\HeaderRandomizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Yep.com search scraper.
 *
 * Yep is a search engine by Ahrefs (major SEO tool company).
 * Has its own independent web index built from its massive web crawler.
 *
 * Advantages:
 *   - Independent index (Ahrefs crawls billions of pages)
 *   - Good quality results for business queries
 *   - Relatively new — less aggressive anti-bot measures
 */
final class YepScraper implements SearchEngineScraper
{
    private const BASE_URL = 'https://yep.com/web';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly HeaderRandomizer $headerRandomizer,
    ) {}

    public function getEngineName(): string
    {
        return 'yep';
    }

    public function scrapeResults(string $query, ?string $region = null, int $maxResults = 10): array
    {
        $response = $this->httpClient->request('GET', self::BASE_URL, [
            'query' => [
                'q' => $query,
                'no_correct' => '1',
            ],
            'headers' => $this->headerRandomizer->getRandomHeaders($region, self::BASE_URL),
            'timeout' => 15,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException("Yep returned HTTP {$statusCode}");
        }

        $html = $response->getContent();
        $crawler = new Crawler($html);
        $results = [];

        // Yep uses React/SSR, try multiple selector patterns
        $selectors = [
            '[data-testid="web-result"]',
            '.result',
            'article',
            '.search-result',
            'div[class*="Result"]',
        ];

        foreach ($selectors as $selector) {
            $nodes = $crawler->filter($selector);
            if ($nodes->count() > 0) {
                $nodes->each(function (Crawler $node) use (&$results, $maxResults) {
                    if (count($results) >= $maxResults) return;

                    $titleNode = $node->filter('a[href*="://"]');
                    $snippetNode = $node->filter('p, span, .snippet, div[class*="snippet"], div[class*="description"]');

                    $link = $titleNode->count() ? $titleNode->first()->attr('href') : null;
                    if (!$link || !str_starts_with($link, 'http') || str_contains($link, 'yep.com')) return;

                    $title = $titleNode->count() ? trim($titleNode->first()->text()) : '';
                    if (empty($title)) return;

                    $results[] = [
                        'title' => $title,
                        'link' => $link,
                        'snippet' => $snippetNode->count() ? trim($snippetNode->first()->text()) : '',
                        'displayLink' => parse_url($link, PHP_URL_HOST) ?: '',
                    ];
                });

                if (!empty($results)) break;
            }
        }

        if (empty($results)) {
            throw new \RuntimeException('Yep returned no parseable results');
        }

        return $results;
    }
}
