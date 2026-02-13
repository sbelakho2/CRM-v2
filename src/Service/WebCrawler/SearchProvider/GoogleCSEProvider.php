<?php

namespace App\Service\WebCrawler\SearchProvider;

use App\Service\GoogleSearchService;
use Psr\Log\LoggerInterface;

/**
 * Google CSE implementation of SearchProviderInterface.
 *
 * Wraps the existing GoogleSearchService, preserving all retry logic and
 * rate-limiting awareness while exposing the clean SearchProviderInterface.
 *
 * This is the default (and currently only) search provider.
 * To swap to a different engine, create a new implementation and re-bind
 * the interface in config/services.yaml.
 */
final class GoogleCSEProvider implements SearchProviderInterface
{
    public function __construct(
        private readonly GoogleSearchService $googleSearchService,
        private readonly LoggerInterface $logger,
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
                'query'   => $query,
                'error'   => $e->getMessage(),
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
        // GoogleSearchService already validates API key + engine ID in constructor
        return true;
    }
}
