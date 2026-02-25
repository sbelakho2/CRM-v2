<?php

namespace App\Service\WebCrawler\SearchProvider;

/**
 * Search Provider Abstraction
 *
 * Decouples the discovery pipeline from any specific search engine.
 *
 * Implementation: LocalSearxngProvider (self-hosted SearXNG, aggregates 9 engines)
 */
interface SearchProviderInterface
{
    /**
     * Execute a search query and return structured results.
     *
     * @param string      $query     The search query string
     * @param string|null $region    ISO 3166-1 alpha-2 country code for geo-bias (e.g. 'DE', 'IT')
     * @param string|null $language  Language restriction (e.g. 'lang_en')
     * @param int         $maxResults Maximum number of results to return (1-10)
     * @param int         $startIndex Starting index for pagination
     *
     * @return SearchResultSet
     */
    public function search(
        string $query,
        ?string $region = null,
        ?string $language = null,
        int $maxResults = 10,
        int $startIndex = 1,
    ): SearchResultSet;

    /**
     * Return the provider name for logging/observability.
     */
    public function getProviderName(): string;

    /**
     * Check if the provider is currently available / configured.
     */
    public function isAvailable(): bool;
}
