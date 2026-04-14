<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

/**
 * Result of verifying whether a domain has real presence in a target location.
 */
final class LocationVerdict
{
    /**
     * @param array<string, string|int> $signals signal type → detail or weight
     */
    public function __construct(
        private readonly bool $confirmed,
        private readonly float $confidence,
        private readonly array $signals,
    ) {
    }

    public function isConfirmed(): bool  { return $this->confirmed; }
    public function getConfidence(): float { return $this->confidence; }

    /** @return array<string, string|int> */
    public function getSignals(): array { return $this->signals; }
}
