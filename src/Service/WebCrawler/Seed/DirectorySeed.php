<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Seed;

/**
 * A seed extracted from a B2B directory listing.
 *
 * This is NOT a final lead — it's a hint that needs to be verified
 * through the normal search → classify → evidence pipeline.
 */
final class DirectorySeed
{
    public function __construct(
        public readonly string  $companyName,
        public readonly ?string $domain,
        public readonly ?string $country,
        public readonly string  $sourceDirectory,
        public readonly string  $sourceUrl,
        public readonly ?string $sector = null,
        public readonly ?string $snippet = null,
    ) {}

    public function toArray(): array
    {
        return [
            'company_name'     => $this->companyName,
            'domain'           => $this->domain,
            'country'          => $this->country,
            'source_directory' => $this->sourceDirectory,
            'source_url'       => $this->sourceUrl,
            'sector'           => $this->sector,
            'snippet'          => $this->snippet,
        ];
    }
}
