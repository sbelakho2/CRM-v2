<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

/**
 * Represents a single crawled page with its extracted structured data.
 */
final class CrawledPage
{
    public function __construct(
        private readonly string $url,
        private readonly string $html,
        private readonly int $httpStatus,
        private readonly string $pageType,
        private readonly array $structuredData = [],
        private readonly array $metaTags = [],
    ) {
    }

    public function getUrl(): string { return $this->url; }
    public function getHtml(): string { return $this->html; }
    public function getHttpStatus(): int { return $this->httpStatus; }

    /**
     * Page type: homepage, about, contact, team, careers, supplier, other.
     */
    public function getPageType(): string { return $this->pageType; }

    /**
     * JSON-LD, microdata, etc. parsed from the page.
     * @return array<int, array<string, mixed>>
     */
    public function getStructuredData(): array { return $this->structuredData; }

    /**
     * Meta tags: og:title, og:description, description, keywords, etc.
     * @return array<string, string>
     */
    public function getMetaTags(): array { return $this->metaTags; }

    public function isSuccess(): bool
    {
        return $this->httpStatus >= 200 && $this->httpStatus < 400;
    }

    /**
     * Get the text content stripped of HTML tags, collapsed whitespace.
     */
    public function getTextContent(): string
    {
        $text = strip_tags($this->html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}
