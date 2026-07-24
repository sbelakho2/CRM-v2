<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Service to search for companies using Google Custom Search API
 * 
 * Features:
 * - Automatic retry with exponential backoff for transient failures
 * - Rate limiting awareness
 * - Comprehensive error handling
 */
class GoogleSearchService
{
    private const SEARCH_URL = 'https://www.googleapis.com/customsearch/v1';
    private const MAX_RETRIES = 3;
    private const INITIAL_RETRY_DELAY_MS = 500;
    private const MAX_RETRY_DELAY_MS = 5000;
    
    // HTTP status codes that are retryable
    private const RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];
    
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $apiKey,
        private string $searchEngineId
    ) {}

    /**
     * Search for companies based on criteria with retry logic
     * 
     * @param string $query Search query (e.g., "aerospace manufacturing morocco")
     * @param int $resultsPerPage Number of results (max 10 per request)
     * @param int $startIndex Starting index for pagination
     * @return array Search results with title, link, snippet
     */
    public function searchCompanies(string $query, int $resultsPerPage = 10, int $startIndex = 1, ?string $gl = null): array
    {
        $lastException = null;
        $attempt = 0;
        
        while ($attempt < self::MAX_RETRIES) {
            try {
                return $this->executeSearch($query, $resultsPerPage, $startIndex, $gl);
            } catch (\Exception $e) {
                $lastException = $e;
                $attempt++;
                
                // Check if error is retryable
                if (!$this->isRetryableError($e)) {
                    $this->logger->error('Google Search API error (non-retryable)', [
                        'message' => $e->getMessage(),
                        'query' => $query,
                        'attempt' => $attempt,
                    ]);
                    throw new \RuntimeException('Failed to search Google: ' . $e->getMessage(), 0, $e);
                }
                
                // Calculate retry delay with exponential backoff
                $delayMs = min(
                    self::INITIAL_RETRY_DELAY_MS * pow(2, $attempt - 1),
                    self::MAX_RETRY_DELAY_MS
                );
                
                $this->logger->warning('Google Search API error, retrying...', [
                    'message' => $e->getMessage(),
                    'query' => $query,
                    'attempt' => $attempt,
                    'max_attempts' => self::MAX_RETRIES,
                    'retry_delay_ms' => $delayMs,
                ]);
                
                // Wait before retry
                usleep($delayMs * 1000);
            }
        }
        
        // All retries exhausted
        $this->logger->error('Google Search API error (all retries exhausted)', [
            'message' => $lastException?->getMessage(),
            'query' => $query,
            'attempts' => $attempt,
        ]);
        
        throw new \RuntimeException(
            'Failed to search Google after ' . self::MAX_RETRIES . ' attempts: ' . 
            ($lastException?->getMessage() ?? 'Unknown error'),
            0,
            $lastException
        );
    }
    
    /**
     * Execute the actual search request
     */
    private function executeSearch(string $query, int $resultsPerPage, int $startIndex, ?string $gl = null): array
    {
        $queryParams = [
            'key' => $this->apiKey,
            'cx' => $this->searchEngineId,
            'q' => $query,
            'num' => min($resultsPerPage, 10), // Max 10 per request
            'start' => $startIndex,
            // Force English-language results to avoid foreign-language page
            // titles being misinterpreted as company names (e.g. "Startseite",
            // "Accueil", "Strona główna")
            'lr' => 'lang_en',  // Restrict to English-language pages
            'hl' => 'en',       // Interface language = English
        ];
        
        // Add geo-location bias if specified (ISO 3166-1 alpha-2 country code)
        if ($gl) {
            $queryParams['gl'] = $gl;
        }
        
        $response = $this->httpClient->request('GET', self::SEARCH_URL, [
            'query' => $queryParams,
        ]);
        
        $statusCode = $response->getStatusCode();
        
        // Check for rate limiting or server errors that should trigger retry
        if (in_array($statusCode, self::RETRYABLE_STATUS_CODES)) {
            throw new \RuntimeException(
                sprintf('HTTP %d: Retryable error', $statusCode)
            );
        }

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
    }
    
    /**
     * Determine if an error is retryable
     */
    private function isRetryableError(\Exception $e): bool
    {
        // Transport exceptions (network issues) are retryable
        if ($e instanceof TransportExceptionInterface) {
            return true;
        }
        
        // Check for retryable HTTP status codes in message
        $message = $e->getMessage();
        foreach (self::RETRYABLE_STATUS_CODES as $code) {
            if (str_contains($message, "HTTP $code") || str_contains($message, "Retryable error")) {
                return true;
            }
        }
        
        // Check for common transient error patterns
        $retryablePatterns = [
            'timeout',
            'timed out',
            'connection reset',
            'temporarily unavailable',
            'service unavailable',
            'rate limit',
            'quota exceeded',
        ];
        
        $messageLower = strtolower($message);
        foreach ($retryablePatterns as $pattern) {
            if (str_contains($messageLower, $pattern)) {
                return true;
            }
        }
        
        return false;
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
     *
     * @param string $location Target location/region (e.g. 'Germany', 'Texas'). Empty = generic.
     */
    public function searchBySector(string $sector, string $location = '', int $limit = 10): array
    {
        $locationPart = $location && $location !== 'all' ? " {$location}" : '';
        $query = sprintf(
            '%s manufacturing suppliers%s',
            $sector,
            $locationPart
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
    public function searchAerospaceCompanies(string $region = '', int $limit = 10): array
    {
        $regionPart = $region ? " {$region}" : '';
        $queries = [
            "aerospace manufacturers{$regionPart}",
            "aviation parts suppliers{$regionPart}",
            "aircraft components{$regionPart}",
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
     * Note: Google Custom Search has a limit of 2000 queries/day (paid tier)
     */
    public function estimateQuota(int $searchCount, int $resultsPerSearch = 10): array
    {
        $totalQueries = ceil($searchCount * ($resultsPerSearch / 10));
        $dailyQuota = 2000;
        $costPerQuery = 0.005; // $5 per 1000 queries

        $freeQuota = max(0, $dailyQuota - $totalQueries);

        return [
            'total_queries' => $totalQueries,
            'daily_quota' => $dailyQuota,
            'free_quota' => $freeQuota,
            'billable_queries' => $totalQueries,
            'estimated_cost' => $totalQueries * $costPerQuery,
            'currency' => 'USD',
        ];
    }
}
