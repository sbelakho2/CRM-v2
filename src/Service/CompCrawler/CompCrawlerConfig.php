<?php

namespace App\Service\CompCrawler;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads and provides access to compcrawler_config.yaml settings.
 */
class CompCrawlerConfig
{
    private array $config;

    public function __construct(private readonly string $projectDir)
    {
        $path = $this->projectDir . '/config/compcrawler_config.yaml';
        $raw = Yaml::parseFile($path);
        $this->config = $raw['compcrawler'] ?? [];
    }

    public function get(string $dotPath, mixed $default = null): mixed
    {
        $keys = explode('.', $dotPath);
        $value = $this->config;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }
        return $value;
    }

    public function getAll(): array
    {
        return $this->config;
    }

    // ── Convenience accessors ──

    public function getLanguages(): array
    {
        return $this->get('languages', ['en']);
    }

    public function getMaxPagesShallow(): int
    {
        return (int) $this->get('crawl.max_pages_shallow', 25);
    }

    public function getMaxPagesDeep(): int
    {
        return (int) $this->get('crawl.max_pages_deep', 200);
    }

    public function getMinEvidenceFamilies(): int
    {
        return (int) $this->get('competitor_evidence_gate.min_families', 2);
    }

    public function getRequestDelayMs(): int
    {
        return (int) $this->get('crawl.request_delay_ms', 1500);
    }

    public function getQueryFamilies(): array
    {
        return $this->get('query_families', []);
    }

    public function getRegionQueries(): array
    {
        return $this->get('region_queries', []);
    }

    public function getVerificationConfig(string $type): array
    {
        return $this->get("verification.{$type}", []);
    }

    public function getPriorityPaths(string $type = 'common'): array
    {
        return $this->get("priority_paths.{$type}", []);
    }

    public function getStarzReference(): array
    {
        return $this->get('starz_reference', []);
    }

    public function getScoringWeights(string $category): array
    {
        return $this->get("scoring.{$category}", []);
    }

    public function getHighThreatThreshold(): int
    {
        return (int) $this->get('alerts.high_threat_threshold', 70);
    }

    public function isLinkedInCrawlAllowed(): bool
    {
        return false; // Always URL-only for LinkedIn
    }

    public function isTosEnforcementStrict(): bool
    {
        return (bool) $this->get('tos_enforcement.strict', true);
    }
}
