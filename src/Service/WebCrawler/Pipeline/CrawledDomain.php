<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

/**
 * All crawled data for a single candidate domain: homepage + subpages.
 */
final class CrawledDomain
{
    /**
     * @param CrawledPage[] $pages
     */
    public function __construct(
        private readonly string $domain,
        private readonly array $pages,
        private readonly float $totalTimeSeconds,
    ) {
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    /** @return CrawledPage[] */
    public function getPages(): array
    {
        return $this->pages;
    }

    public function getTotalTime(): float
    {
        return $this->totalTimeSeconds;
    }

    public function getHomepage(): ?CrawledPage
    {
        foreach ($this->pages as $page) {
            if ($page->getPageType() === 'homepage') {
                return $page;
            }
        }
        return null;
    }

    /** @return CrawledPage[] */
    public function getPagesByType(string $type): array
    {
        return array_values(array_filter(
            $this->pages,
            fn(CrawledPage $page) => $page->getPageType() === $type,
        ));
    }

    /** @return CrawledPage[] */
    public function getSuccessfulPages(): array
    {
        return array_values(array_filter(
            $this->pages,
            fn(CrawledPage $page) => $page->isSuccess(),
        ));
    }

    /**
     * Concatenated text content from all successful pages.
     */
    public function getAllText(): string
    {
        $texts = [];
        foreach ($this->getSuccessfulPages() as $page) {
            $text = $page->getTextContent();
            if ($text !== '') {
                $texts[] = $text;
            }
        }
        return implode(' ', $texts);
    }

    /**
     * All JSON-LD / structured data entries from all successful pages.
     * @return array<int, array<string, mixed>>
     */
    public function getAllStructuredData(): array
    {
        $all = [];
        foreach ($this->getSuccessfulPages() as $page) {
            foreach ($page->getStructuredData() as $item) {
                $all[] = $item;
            }
        }
        return $all;
    }

    /**
     * Merged meta tags from all successful pages (first value per key wins).
     * @return array<string, string>
     */
    public function getAllMetaTags(): array
    {
        $all = [];
        foreach ($this->getSuccessfulPages() as $page) {
            foreach ($page->getMetaTags() as $key => $value) {
                if (!isset($all[$key])) {
                    $all[$key] = $value;
                }
            }
        }
        return $all;
    }
}
