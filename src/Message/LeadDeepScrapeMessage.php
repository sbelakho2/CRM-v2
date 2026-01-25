<?php

namespace App\Message;

/**
 * Message to trigger deep scraping of a lead's website
 * 
 * This message is dispatched when a lead is imported and queued
 * for background enrichment via website scraping.
 */
class LeadDeepScrapeMessage
{
    public function __construct(
        private int $leadId,
        private string $websiteUrl,
        private array $options = []
    ) {}

    public function getLeadId(): int
    {
        return $this->leadId;
    }

    public function getWebsiteUrl(): string
    {
        return $this->websiteUrl;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
    
    /**
     * Check if LLM enrichment should be attempted
     */
    public function shouldUseLlm(): bool
    {
        return $this->options['use_llm'] ?? false;
    }
    
    /**
     * Get maximum pages to crawl
     */
    public function getMaxPages(): int
    {
        return $this->options['max_pages'] ?? 5;
    }
}
