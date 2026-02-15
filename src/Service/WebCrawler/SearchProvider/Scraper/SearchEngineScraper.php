<?php

namespace App\Service\WebCrawler\SearchProvider\Scraper;

/**
 * Contract for a single search engine scraper.
 *
 * Each implementation is responsible for:
 *   1. Building the HTTP request to the search engine
 *   2. Parsing the HTML response
 *   3. Handling engine-specific rate limiting and anti-bot measures
 *   4. Returning normalized result arrays
 *
 * Result array format (must match pipeline field requirements):
 *   [
 *     'link'        => 'https://example.com/page',   // REQUIRED — full URL
 *     'title'       => 'Page Title - Example',       // REQUIRED — page title
 *     'snippet'     => 'Text excerpt from page...',  // REQUIRED — ~160 chars
 *     'displayLink' => 'example.com',                // REQUIRED — root domain
 *   ]
 */
interface SearchEngineScraper
{
    /**
     * Scrape search results from this engine.
     *
     * @param string      $query      Search query (supports site:, -site:, OR, "", etc.)
     * @param string|null $region     ISO country code for geo-bias (e.g. 'DE', 'IT')
     * @param int         $maxResults Maximum results to return (best-effort)
     *
     * @return array[] Array of result arrays, each with: link, title, snippet, displayLink
     *
     * @throws \RuntimeException On CAPTCHA, HTTP error, or parse failure
     */
    public function scrapeResults(string $query, ?string $region = null, int $maxResults = 10): array;

    /**
     * Return the engine name for logging/metrics.
     */
    public function getEngineName(): string;
}
