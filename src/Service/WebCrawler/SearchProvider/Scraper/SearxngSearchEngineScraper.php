<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

use App\Service\WebCrawler\SearchProvider\HeaderRandomizer;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * SearXNG meta-search wrapper implementing SearchEngineScraper interface.
 *
 * SearXNG is a privacy-focused meta-search engine with 100+ public instances.
 * Each instance has its own IP address, making this the most powerful tool
 * for evading rate limits. This wrapper delegates to the SearxngScraper
 * service which manages instance rotation.
 *
 * Key advantage: This single scraper effectively provides access to 100+
 * unique IPs all capable of querying Google/Bing/DuckDuckGo.
 */
final class SearxngSearchEngineScraper implements SearchEngineScraper
{
    public function __construct(
        private readonly SearxngScraper $searxngScraper,
        private readonly LoggerInterface $logger,
    ) {}

    public function getEngineName(): string
    {
        return 'searxng';
    }

    public function scrapeResults(string $query, ?string $region = null, int $maxResults = 10): array
    {
        return $this->searxngScraper->search($query, $maxResults, $region);
    }
}
