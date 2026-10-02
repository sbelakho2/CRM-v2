<?php

namespace App\Service\WebCrawler\Evidence;

/**
 * Single piece of buyer evidence (positive or anti).
 */
final class EvidenceItem
{
    public function __construct(
        public readonly string $family,
        public readonly string $signal,
        public readonly int    $weight,
        public readonly string $source,
    ) {
    }

    /** @return array{family: string, signal: string, weight: int, source: string} */
    public function toArray(): array
    {
        return [
            'family' => $this->family,
            'signal' => $this->signal,
            'weight' => $this->weight,
            'source' => $this->source,
        ];
    }
}
