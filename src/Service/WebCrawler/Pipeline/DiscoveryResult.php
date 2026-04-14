<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

/**
 * Complete discovery result for a single domain:
 * classification, evidence, location verdict, and contacts.
 */
final class DiscoveryResult
{
    /**
     * @param ExtractedContact[] $contacts
     */
    public function __construct(
        private readonly string $domain,
        private readonly string $companyName,
        private readonly string $websiteUrl,
        private readonly PageClassification $classification,
        private readonly EvidenceScore $evidenceScore,
        private readonly LocationVerdict $locationVerdict,
        private readonly array $contacts,
    ) {
    }

    public function getDomain(): string             { return $this->domain; }
    public function getCompanyName(): string         { return $this->companyName; }
    public function getWebsiteUrl(): string          { return $this->websiteUrl; }
    public function getClassification(): PageClassification { return $this->classification; }
    public function getEvidenceScore(): EvidenceScore { return $this->evidenceScore; }
    public function getLocationVerdict(): LocationVerdict { return $this->locationVerdict; }

    /** @return ExtractedContact[] */
    public function getContacts(): array { return $this->contacts; }

    /**
     * Composite pass: target type + evidence threshold + location confirmed.
     */
    public function isPassed(): bool
    {
        return $this->classification->isTargetType()
            && $this->evidenceScore->isPassed()
            && $this->locationVerdict->isConfirmed();
    }
}
