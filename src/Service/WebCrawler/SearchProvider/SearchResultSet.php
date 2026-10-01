<?php

namespace App\Service\WebCrawler\SearchProvider;

/**
 * Value object representing a set of search results from a provider.
 *
 * Implements \Countable and \IteratorAggregate for convenient usage.
 */
final class SearchResultSet implements \Countable, \IteratorAggregate
{
    /**
     * @param SearchResult[] $results
     */
    public function __construct(
        private readonly array  $results,
        private readonly int    $totalResults,
        private readonly float  $searchTimeSeconds,
        private readonly string $providerName,
        private readonly string $query = '',
        private readonly array  $metadata = [],
    ) {
    }

    /**
     * @return SearchResult[]
     */
    public function getResults(): array
    {
        return $this->results;
    }

    public function getTotalResults(): int
    {
        return $this->totalResults;
    }

    public function getSearchTimeSeconds(): float
    {
        return $this->searchTimeSeconds;
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function count(): int
    {
        return count($this->results);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->results);
    }

    public function isEmpty(): bool
    {
        return empty($this->results);
    }

    /**
     * Filter results by a callback.
     *
     * @param callable(SearchResult): bool $callback
     */
    public function filter(callable $callback): self
    {
        return new self(
            array_values(array_filter($this->results, $callback)),
            $this->totalResults,
            $this->searchTimeSeconds,
            $this->providerName,
            $this->query,
            $this->metadata,
        );
    }

    /**
     * Map results to a new SearchResultSet.
     *
     * @param callable(SearchResult): SearchResult $callback
     */
    public function map(callable $callback): self
    {
        return new self(
            array_map($callback, $this->results),
            $this->totalResults,
            $this->searchTimeSeconds,
            $this->providerName,
            $this->query,
            $this->metadata,
        );
    }

    /**
     * Convert to the legacy array format used throughout GoogleDorkService.
     *
     * @return array{results: array, totalResults: int, searchTime: float}
     */
    public function toLegacyArray(): array
    {
        return [
            'results'      => array_map(fn(SearchResult $r) => $r->toLegacyArray(), $this->results),
            'totalResults' => $this->totalResults,
            'searchTime'   => $this->searchTimeSeconds,
        ];
    }

    /**
     * Create a SearchResultSet from the legacy array format.
     */
    public static function fromLegacyArray(array $data, string $providerName, string $query = ''): self
    {
        $results = [];
        foreach (($data['results'] ?? []) as $item) {
            $results[] = new SearchResult(
                url: $item['link'] ?? '',
                title: $item['title'] ?? '',
                snippet: $item['snippet'] ?? '',
                displayLink: $item['displayLink'] ?? '',
                formattedUrl: $item['formattedUrl'] ?? null,
                metadata: array_diff_key($item, array_flip(['link', 'title', 'snippet', 'displayLink', 'formattedUrl'])),
            );
        }

        return new self(
            results: $results,
            totalResults: (int) ($data['totalResults'] ?? 0),
            searchTimeSeconds: (float) ($data['searchTime'] ?? 0.0),
            providerName: $providerName,
            query: $query,
        );
    }
}
