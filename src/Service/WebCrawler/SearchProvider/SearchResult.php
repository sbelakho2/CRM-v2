<?php

namespace App\Service\WebCrawler\SearchProvider;

/**
 * Value object representing a single search result.
 *
 * Immutable. Created by SearchProviderInterface implementations.
 */
final class SearchResult
{
    public function __construct(
        private readonly string $url,
        private readonly string $title,
        private readonly string $snippet,
        private readonly string $displayLink,
        private readonly ?string $formattedUrl = null,
        private readonly array  $metadata = [],
    ) {
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSnippet(): string
    {
        return $this->snippet;
    }

    public function getDisplayLink(): string
    {
        return $this->displayLink;
    }

    public function getFormattedUrl(): ?string
    {
        return $this->formattedUrl;
    }

    /**
     * Provider-specific metadata (e.g. Google CSE 'pagemap', ranking position).
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Extract the root domain from the URL.
     */
    public function getRootDomain(): string
    {
        $host = parse_url($this->url, PHP_URL_HOST);
        if (!$host) {
            return '';
        }

        // Strip www.
        $host = preg_replace('/^www\./', '', $host);

        return strtolower($host);
    }

    /**
     * Convert to the legacy array format used by GoogleDorkService.
     */
    public function toLegacyArray(): array
    {
        return [
            'link'         => $this->url,
            'title'        => $this->title,
            'snippet'      => $this->snippet,
            'displayLink'  => $this->displayLink,
            'formattedUrl' => $this->formattedUrl ?? $this->url,
        ] + $this->metadata;
    }
}
