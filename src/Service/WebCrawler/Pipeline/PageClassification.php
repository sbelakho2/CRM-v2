<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

/**
 * Result of classifying a domain based on all its crawled page content.
 */
final class PageClassification
{
    /**
     * @param array<string, int> $scores
     */
    public function __construct(
        private readonly string $category,
        private readonly float $confidence,
        private readonly array $scores,
    ) {
    }

    /**
     * The winning category: manufacturer, oem_tier, distributor, directory,
     * media, association, government, recruiter, consultant, competitor_ems, unknown.
     */
    public function getCategory(): string
    {
        return $this->category;
    }

    /**
     * Confidence in the classification (0.0–1.0).
     * Higher values mean the top category clearly dominates.
     */
    public function getConfidence(): float
    {
        return $this->confidence;
    }

    /**
     * Raw scores per category for debugging/audit.
     * @return array<string, int>
     */
    public function getScores(): array
    {
        return $this->scores;
    }

    public function isManufacturer(): bool
    {
        return $this->category === 'manufacturer';
    }

    public function isOemTier(): bool
    {
        return $this->category === 'oem_tier';
    }

    public function isDistributor(): bool
    {
        return $this->category === 'distributor';
    }

    public function isTargetType(): bool
    {
        return \in_array($this->category, ['manufacturer', 'oem_tier'], true);
    }
}
