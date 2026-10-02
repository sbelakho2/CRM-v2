<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

/**
 * Immutable set of discovery candidates.
 *
 * Normal domains are keyed by root domain (one candidate per domain).
 * Known directory domains (kerix.net, charika.ma, etc.) may have MULTIPLE
 * entries keyed by URL, since each search result is a different company
 * profile page that needs separate seed extraction.
 */
/**
 * @implements \IteratorAggregate<string, array{domain: string, name: string, url: string, title: string, snippet: string, query_type: string}>
 */
final class CandidateSet implements \Countable, \IteratorAggregate
{
    /**
     * @param array<string, array{domain: string, name: string, url: string, title: string, snippet: string, query_type: string}> $candidates
     *   Keyed by root domain for normal domains, or URL for directory domains.
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
     * Get a single candidate by key (domain for normal domains, URL for directory domains).
     *
     * @return array{domain: string, name: string, url: string, title: string, snippet: string, query_type: string}|null
     */
    public function get(string $key): ?array
    {
        return $this->candidates[$key] ?? null;
    }

    /**
     * Get ALL candidates for a given domain.
     *
     * For normal domains, this returns at most one candidate.
     * For directory domains (kerix.net, charika.ma, etc.), this returns
     * ALL collected results so the DirectorySeedExtractor can extract
     * company names from every search result's title/snippet.
     *
     * @return array<int, array{domain: string, name: string, url: string, title: string, snippet: string, query_type: string}>
     */
    public function getByDomain(string $domain): array
    {
        $results = [];

        // Direct match (normal domain, keyed by domain itself)
        if (isset($this->candidates[$domain])) {
            $results[] = $this->candidates[$domain];
            return $results;
        }

        // Prefix match (directory domain, keyed by URL containing the domain)
        $domainPrefix = $domain . '/';
        foreach ($this->candidates as $key => $candidate) {
            if ($candidate['domain'] === $domain) {
                $results[] = $candidate;
            }
        }

        return $results;
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

    /**
     * @return \ArrayIterator<string, array{domain: string, name: string, url: string, title: string, snippet: string, query_type: string}>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->candidates);
    }
}
