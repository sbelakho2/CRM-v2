<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

/**
 * A single extracted contact with quality score.
 */
final class ExtractedContact
{
    public function __construct(
        private readonly ?string $firstName,
        private readonly ?string $lastName,
        private readonly ?string $jobTitle,
        private readonly ?string $email,
        private readonly ?string $phone,
        private readonly ?string $linkedinUrl,
        private readonly int $qualityScore,
    ) {
    }

    public function getFirstName(): ?string  { return $this->firstName; }
    public function getLastName(): ?string   { return $this->lastName; }
    public function getJobTitle(): ?string   { return $this->jobTitle; }
    public function getEmail(): ?string      { return $this->email; }
    public function getPhone(): ?string      { return $this->phone; }
    public function getLinkedinUrl(): ?string { return $this->linkedinUrl; }
    public function getQualityScore(): int   { return $this->qualityScore; }

    public function getFullName(): string
    {
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
    }
}
