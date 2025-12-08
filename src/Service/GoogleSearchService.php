<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Service to search for companies using Google Custom Search API
 */
class GoogleSearchService
{
    private const SEARCH_URL = 'https://www.googleapis.com/customsearch/v1';
    
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $apiKey,
        private string $searchEngineId
    ) {}

    /**
     * Search for companies based on criteria
     * 
     * @param string $query Search query (e.g., "aerospace manufacturing morocco")
     * @param int $resultsPerPage Number of results (max 10 per request)
     * @param int $startIndex Starting index for pagination
     * @return array Search results with title, link, snippet
     */
    public function searchCompanies(string $query, int $resultsPerPage = 10, int $startIndex = 1): array
    {
        try {
            $response = $this->httpClient->request('GET', self::SEARCH_URL, [
                'query' => [
                    'key' => $this->apiKey,
                    'cx' => $this->searchEngineId,
                    'q' => $query,
                    'num' => min($resultsPerPage, 10), // Max 10 per request
                    'start' => $startIndex,
                ],
            ]);

            $data = $response->toArray();
            
            if (!isset($data['items'])) {
                $this->logger->warning('Google Search returned no results', ['query' => $query]);
                return [
                    'results' => [],
                    'totalResults' => 0,
                    'searchTime' => 0,
                ];
            }

            return [
                'results' => $this->parseResults($data['items']),
                'totalResults' => (int)($data['searchInformation']['totalResults'] ?? 0),
                'searchTime' => (float)($data['searchInformation']['searchTime'] ?? 0),
                'queries' => $data['queries'] ?? [],
            ];

        } catch (\Exception $e) {
            $this->logger->error('Google Search API error', [
                'message' => $e->getMessage(),
                'query' => $query,
            ]);
            
            throw new \RuntimeException('Failed to search Google: ' . $e->getMessage());
        }
    }

    /**
     * Parse search results into structured format
     */
    private function parseResults(array $items): array
    {
        $results = [];

        foreach ($items as $item) {
            $results[] = [
                'title' => $item['title'] ?? '',
                'link' => $item['link'] ?? '',
                'snippet' => $item['snippet'] ?? '',
                'displayLink' => $item['displayLink'] ?? '',
                'formattedUrl' => $item['formattedUrl'] ?? '',
                'htmlSnippet' => $item['htmlSnippet'] ?? '',
                'cacheId' => $item['cacheId'] ?? null,
                'pagemap' => $item['pagemap'] ?? [],
            ];
        }

        return $results;
    }

    /**
     * Search for companies in a specific sector
     */
    public function searchBySector(string $sector, string $location = 'Morocco', int $limit = 10): array
    {
        $query = sprintf(
            '%s manufacturing suppliers %s',
            $sector,
            $location
        );

        return $this->searchCompanies($query, $limit);
    }

    /**
     * Search for company information by name
     */
    public function searchCompanyInfo(string $companyName): array
    {
        $query = sprintf(
            '"%s" company contact information',
            $companyName
        );

        return $this->searchCompanies($query, 5);
    }

    /**
     * Search for aerospace companies
     */
    public function searchAerospaceCompanies(string $region = 'Morocco', int $limit = 10): array
    {
        $queries = [
            "aerospace manufacturers {$region}",
            "aviation parts suppliers {$region}",
            "aircraft components {$region}",
        ];

        $allResults = [];
        foreach ($queries as $query) {
            $results = $this->searchCompanies($query, $limit);
            $allResults = array_merge($allResults, $results['results']);
        }

        // Remove duplicates based on displayLink
        $unique = [];
        $seen = [];
        foreach ($allResults as $result) {
            $domain = $result['displayLink'];
            if (!isset($seen[$domain])) {
                $unique[] = $result;
                $seen[$domain] = true;
            }
        }

        return [
            'results' => array_slice($unique, 0, $limit),
            'totalResults' => count($unique),
        ];
    }

    /**
     * Extract company website from search result
     */
    public function extractWebsite(array $searchResult): ?string
    {
        if (!empty($searchResult['link'])) {
            $parsed = parse_url($searchResult['link']);
            return sprintf('%s://%s', $parsed['scheme'] ?? 'https', $parsed['host'] ?? '');
        }

        return null;
    }

    /**
     * Build advanced search query
     */
    public function buildAdvancedQuery(array $criteria): string
    {
        $parts = [];

        if (!empty($criteria['sector'])) {
            $parts[] = $criteria['sector'];
        }

        if (!empty($criteria['keywords'])) {
            foreach ($criteria['keywords'] as $keyword) {
                $parts[] = "\"{$keyword}\"";
            }
        }

        if (!empty($criteria['location'])) {
            $parts[] = $criteria['location'];
        }

        if (!empty($criteria['exclude'])) {
            foreach ($criteria['exclude'] as $exclude) {
                $parts[] = "-{$exclude}";
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Get quota usage estimation
     * Note: Google Custom Search has a limit of 100 queries/day for free tier
     */
    public function estimateQuota(int $searchCount, int $resultsPerSearch = 10): array
    {
        $totalQueries = ceil($searchCount * ($resultsPerSearch / 10));
        $freeQueries = 100;
        $costPerQuery = 0.005; // $5 per 1000 queries

        return [
            'total_queries' => $totalQueries,
            'free_quota' => min($totalQueries, $freeQueries),
            'billable_queries' => max(0, $totalQueries - $freeQueries),
            'estimated_cost' => max(0, $totalQueries - $freeQueries) * $costPerQuery,
        ];
    }
}
