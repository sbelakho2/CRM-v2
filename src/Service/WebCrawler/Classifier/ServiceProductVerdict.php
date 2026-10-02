<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Classifier;

/**
 * Verdict from the Service-vs-Product classifier.
 *
 * Three possible classifications:
 *  - PRODUCT_COMPANY: Designs / manufactures / sells tangible products → potential EMS buyer
 *  - SERVICE_PROVIDER: Sells services (consulting, staffing, IT, EMS competitor…) → reject
 *  - INDETERMINATE:   Not enough signal either way → allow (let other gates decide)
 */
final class ServiceProductVerdict
{
    public const TYPE_PRODUCT     = 'PRODUCT_COMPANY';
    public const TYPE_SERVICE     = 'SERVICE_PROVIDER';
    public const TYPE_INDETERMINATE = 'INDETERMINATE';

    /** @param array<string, float> $signalBreakdown */
    public function __construct(
        public readonly string $type,
        public readonly float  $productScore,
        public readonly float  $serviceScore,
        public readonly string $reason,
        public readonly array  $signalBreakdown = [],
    ) {}

    /**
     * Should this candidate be REJECTED?
     *
     * Only service providers with a clear service signal get rejected.
     * INDETERMINATE passes through (other gates decide).
     */
    public function isRejected(): bool
    {
        return $this->type === self::TYPE_SERVICE;
    }

    public function isProduct(): bool
    {
        return $this->type === self::TYPE_PRODUCT;
    }

    /**
     * @return array{type: string, product_score: float, service_score: float, reason: string, signal_breakdown: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'type'            => $this->type,
            'product_score'   => $this->productScore,
            'service_score'   => $this->serviceScore,
            'reason'          => $this->reason,
            'signal_breakdown' => $this->signalBreakdown,
        ];
    }
}
