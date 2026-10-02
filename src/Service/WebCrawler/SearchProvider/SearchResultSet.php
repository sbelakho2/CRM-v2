<?php

namespace App\Service\WebCrawler\SearchProvider;

/**
 * Value object representing a set of search results from a provider.
 *
 * Implements \Countable and \IteratorAggregate for convenient usage.
 *
 * @implements \IteratorAggregate<int, SearchResult>
 */
final class SearchResultSet implements \Countable, \IteratorAggregate
{
    /**
     * @param list<SearchResult> $results
     * @param array<string, mixed> $metadata
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
     * @return list<SearchResult>
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

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function count(): int
    {
        return count($this->results);
    }

    /**
     * @return \ArrayIterator<int, SearchResult>
     */
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
     * @return array{results: list<array<string, mixed>>, totalResults: int, searchTime: float}
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
     *
     * @param array<string|int, mixed> $data
     */
    public static function fromLegacyArray(array $data, string $providerName, string $query = ''): self
    {
        $results = [];
        $rawResults = $data['results'] ?? [];
        if (is_array($rawResults)) {
            foreach ($rawResults as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $link = $item['link'] ?? null;
                $title = $item['title'] ?? null;
                $snippet = $item['snippet'] ?? null;
                $displayLink = $item['displayLink'] ?? null;
                $formattedUrl = $item['formattedUrl'] ?? null;

                $metadata = [];
                foreach ($item as $metaKey => $metaValue) {
                    if (is_string($metaKey)
                        && !in_array($metaKey, ['link', 'title', 'snippet', 'displayLink', 'formattedUrl'], true)
                    ) {
                        $metadata[$metaKey] = $metaValue;
                    }
                }

                $results[] = new SearchResult(
                    url: is_scalar($link) ? (string) $link : '',
                    title: is_scalar($title) ? (string) $title : '',
                    snippet: is_scalar($snippet) ? (string) $snippet : '',
                    displayLink: is_scalar($displayLink) ? (string) $displayLink : '',
                    formattedUrl: is_scalar($formattedUrl) ? (string) $formattedUrl : null,
                    metadata: $metadata,
                );
            }
        }

        $rawTotal = $data['totalResults'] ?? 0;
        $rawSearchTime = $data['searchTime'] ?? 0.0;

        return new self(
            results: $results,
            totalResults: is_numeric($rawTotal) ? (int) $rawTotal : 0,
            searchTimeSeconds: is_numeric($rawSearchTime) ? (float) $rawSearchTime : 0.0,
            providerName: $providerName,
            query: $query,
        );
    }
}
