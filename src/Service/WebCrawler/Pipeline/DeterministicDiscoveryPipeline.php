<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

use App\Service\CountryService;
use App\Service\WebCrawler\SearchProvider\SearchProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the full deterministic discovery pipeline:
 *
 *   QueryTemplateBuilder → CandidateCollector → DomainCrawler →
 *   PageClassifier → ManufacturingEvidenceScorer →
 *   LocationProofVerifier → UnifiedContactExtractor
 *
 * Replaces GoogleDorkService::searchCompanies() + verifyCompanies()
 * with a clean, testable, LLM-free pipeline.
 */
final class DeterministicDiscoveryPipeline
{
    public function __construct(
        private readonly QueryTemplateBuilder $queryBuilder,
        private readonly CandidateCollector $candidateCollector,
        private readonly DomainCrawler $domainCrawler,
        private readonly PageClassifier $pageClassifier,
        private readonly ManufacturingEvidenceScorer $evidenceScorer,
        private readonly LocationProofVerifier $locationVerifier,
        private readonly UnifiedContactExtractor $contactExtractor,
        private readonly SearchProviderInterface $searchProvider,
        private readonly LoggerInterface $logger,
        private readonly ?CountryService $countryService = null,
    ) {
    }

    /**
     * Run the full discovery pipeline for a sector + location.
     *
     * @return DiscoveryResult[] All processed domains with verdicts (caller filters with isPassed())
     */
    public function discover(?string $sector, ?string $location): array
    {
        $startTime = microtime(true);
        $searchRegion = $this->resolveSearchRegion($location);
        $verificationLocation = $this->resolveVerificationLocation($location, $searchRegion);

        // 1. Generate search queries
        $queries = $this->queryBuilder->buildQueries($sector, $location);
        $this->logger->info('[Pipeline] Generated {n} queries for sector={s} location={l}', [
            'n' => \count($queries),
            's' => $sector ?? '(any)',
            'l' => $location ?? '(any)',
        ]);

        if (empty($queries)) {
            return [];
        }

        // 2. Search and collect candidates
        $candidates = $this->candidateCollector->collect($queries, $this->searchProvider, $searchRegion);
        $this->logger->info('[Pipeline] Collected {n} candidate domains', [
            'n' => $candidates->count(),
        ]);

        if ($candidates->isEmpty()) {
            return [];
        }

        // 3. Crawl all candidate domains
        $crawledDomains = $this->domainCrawler->crawl($candidates);
        $this->logger->info('[Pipeline] Crawled {n} domains', [
            'n' => \count($crawledDomains),
        ]);

        // 4–7. Classify, score, verify, extract for each domain
        $results = [];
        $passCount = 0;

        foreach ($crawledDomains as $domain => $crawledDomain) {
            $classification = $this->pageClassifier->classify($crawledDomain);
            $evidenceScore = $this->evidenceScorer->score($crawledDomain, $classification);
            $locationVerdict = $this->locationVerifier->verify($crawledDomain, $verificationLocation);
            $contacts = $this->contactExtractor->extract($crawledDomain);

            $candidate = $candidates->get($domain);

            $result = new DiscoveryResult(
                $domain,
                $candidate['name'] ?? $domain,
                $candidate['url'] ?? 'https://' . $domain,
                $classification,
                $evidenceScore,
                $locationVerdict,
                $contacts,
            );

            $results[] = $result;

            if ($result->isPassed()) {
                $passCount++;
            }
        }

        $elapsed = round(microtime(true) - $startTime, 2);
        $this->logger->info('[Pipeline] Complete: {total} domains → {pass} passed in {t}s', [
            'total' => \count($results),
            'pass'  => $passCount,
            't'     => $elapsed,
        ]);

        return $results;
    }

    private function resolveSearchRegion(?string $location): ?string
    {
        if ($location === null || trim($location) === '' || $this->countryService === null) {
            return null;
        }

        $regionCode = $this->countryService->normalizeRegionCode($location);
        if ($regionCode === null) {
            return null;
        }

        if (str_contains($regionCode, '-')) {
            return substr($regionCode, 0, 2);
        }

        return preg_match('/^[A-Z]{2}$/', $regionCode) === 1 ? $regionCode : null;
    }

    private function resolveVerificationLocation(?string $location, ?string $searchRegion): ?string
    {
        if ($location === null || trim($location) === '') {
            return $location;
        }

        if ($this->countryService === null || $searchRegion === null) {
            return $location;
        }

        return $this->countryService->getCountryName($searchRegion) ?? $location;
    }
}
