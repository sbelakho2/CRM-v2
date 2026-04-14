<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

/**
 * Immutable set of deduplicated discovery candidates, keyed by root domain.
 *
 * Each candidate carries: domain, name (from search title), snippet, source URL,
 * and the query type that found it.
 */
final class CandidateSet implements \Countable, \IteratorAggregate
{
    /**
     * @param array<string, array{domain: string, name: string, url: string, title: string, snippet: string, query_type: string}> $candidates
     *   Keyed by root domain.
     * @param array<string, int> $queryStats query string → result count
     */
    public function __construct(
        private readonly array $candidates,
        private readonly array $queryStats = [],
    ) {
    }

    /**
     * @return array<string, array{domain: string, name: string, url: string, title: string, snippet: string, query_type: string}>
     */
    public function getCandidates(): array
    {
        return $this->candidates;
    }

    /**
     * @return array<string, int>
     */
    public function getQueryStats(): array
    {
        return $this->queryStats;
    }

    /**
     * Get a single candidate by domain, or null.
     *
     * @return array{domain: string, name: string, url: string, title: string, snippet: string, query_type: string}|null
     */
    public function get(string $domain): ?array
    {
        return $this->candidates[$domain] ?? null;
    }

    /**
     * @return string[]
     */
    public function getDomains(): array
    {
        return array_keys($this->candidates);
    }

    public function count(): int
    {
        return count($this->candidates);
    }

    public function isEmpty(): bool
    {
        return empty($this->candidates);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->candidates);
    }
}
