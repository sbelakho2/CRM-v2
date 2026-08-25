<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\SearchProvider;

use App\Service\GoogleSearchService;
use Psr\Log\LoggerInterface;

/**
 * Google Custom Search Engine (CSE) implementation of SearchProviderInterface.
 *
 * Wraps the existing GoogleSearchService, preserving all retry logic and
 * rate-limiting awareness while exposing the clean SearchProviderInterface.
 *
 * This is the canonical interface implementation. LocalSearxngProvider
 * extends this class so the self-hosted SearXNG provider remains the active
 * engine while still satisfying the SearchProviderInterface contract.
 */
class GoogleCSEProvider implements SearchProviderInterface
{
    public function __construct(
        protected readonly GoogleSearchService $googleSearchService,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function search(
        string $query,
        ?string $region = null,
        ?string $language = null,
        int $maxResults = 10,
        int $startIndex = 1,
    ): SearchResultSet {
        $this->logger->debug('GoogleCSE search', [
            'query'   => $query,
            'region'  => $region,
            'lang'    => $language,
            'max'     => $maxResults,
            'start'   => $startIndex,
        ]);

        try {
            // Delegate to the underlying GoogleSearchService (preserves retry logic)
            $legacyResult = $this->googleSearchService->searchCompanies(
                $query,
                $maxResults,
                $startIndex,
                $region, // gl parameter for geo-bias
            );

            return SearchResultSet::fromLegacyArray($legacyResult, $this->getProviderName(), $query);
        } catch (\RuntimeException $e) {
            $this->logger->error('GoogleCSE search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            // Return empty set on failure — let the pipeline continue gracefully
            return new SearchResultSet(
                results: [],
                totalResults: 0,
                searchTimeSeconds: 0.0,
                providerName: $this->getProviderName(),
                query: $query,
                metadata: ['error' => $e->getMessage()],
            );
        }
    }

    public function getProviderName(): string
    {
        return 'google_cse';
    }

    public function isAvailable(): bool
    {
        // Verify that the underlying GoogleSearchService has valid API credentials
        try {
            $ref = new \ReflectionProperty($this->googleSearchService, 'apiKey');
            $key = $ref->getValue($this->googleSearchService);
            $ref2 = new \ReflectionProperty($this->googleSearchService, 'searchEngineId');
            $engineId = $ref2->getValue($this->googleSearchService);

            return !empty($key) && !empty($engineId)
                && $key !== 'your_google_api_key_here'
                && $engineId !== 'your_search_engine_id_here';
        } catch (\Throwable) {
            return false;
        }
    }
}
