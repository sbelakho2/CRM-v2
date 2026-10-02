<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

use App\Service\CountryService;
use App\Service\WebCrawler\SearchProvider\SearchProviderInterface;
use App\Service\WebCrawler\Seed\DirectorySeedExtractor;
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
    /** Maximum extra candidates to add from directory seed extraction. */
    private const MAX_DIRECTORY_SEED_CANDIDATES = 30;

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
        private readonly ?DirectorySeedExtractor $directorySeedExtractor = null,
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

        // Log query stats
        $queryStats = $candidates->getQueryStats();
        if (!empty($queryStats)) {
            $this->logger->info('[Pipeline] Query stats: {stats}', [
                'stats' => json_encode($queryStats),
            ]);
        }

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
        /** @var list<\App\Service\WebCrawler\Seed\DirectorySeed> $directorySeeds */
        $directorySeeds = [];

        foreach ($crawledDomains as $domain => $crawledDomain) {
            $candidate = $candidates->get($domain);

            // Force "directory" classification for known directory domains BEFORE the classifier runs.
            // The PageClassifier's content-based scoring often misclassifies directory deep pages
            // (e.g., kerix.net company profiles) as "manufacturer" because the listing text
            // contains manufacturing keywords that outscore the directory indicators.
            // By checking DirectorySeedExtractor's known domain fragments first, we ensure
            // real directory sites like kerix.net and charika.ma are always classified as
            // "directory", allowing company name extraction from their snippets/pages.
            if ($this->directorySeedExtractor !== null
                && $this->directorySeedExtractor->isDirectoryDomain($domain)
            ) {
                $classification = new PageClassification('directory', 1.0, [
                    'manufacturer' => 0, 'oem_tier' => 0, 'distributor' => 0,
                    'directory' => 999, 'media' => 0, 'association' => 0,
                    'government' => 0, 'recruiter' => 0, 'consultant' => 0,
                    'competitor_ems' => 0,
                ]);
            } else {
                $classification = $this->pageClassifier->classify($crawledDomain);
            }

            $evidenceScore = $this->evidenceScorer->score($crawledDomain, $classification);
            $locationVerdict = $this->locationVerifier->verify($crawledDomain, $verificationLocation);
            $contacts = $this->contactExtractor->extract($crawledDomain);

            // If classified as directory and we have a seed extractor, try to extract companies
            // from ALL collected results (not just the first one via $candidate).
            // For directory domains, CandidateCollector keeps multiple entries keyed by URL,
            // so we iterate over all of them to extract every company name.
            if ($classification->getCategory() === 'directory'
                && $this->directorySeedExtractor !== null
            ) {
                $dirCandidates = $candidates->getByDomain($domain);
                foreach ($dirCandidates as $dirCandidate) {
                    $seeds = $this->directorySeedExtractor->extractSeedsFromSnippet(
                        $dirCandidate['snippet'],
                        $dirCandidate['title'],
                        $dirCandidate['url'],
                        $domain,
                        $searchRegion,
                        $sector,
                    );
                    if (!empty($seeds)) {
                        $directorySeeds = array_merge($directorySeeds, array_values($seeds));
                        $this->logger->info('[Pipeline] Extracted {n} seeds from directory {d}', [
                            'n' => \count($seeds),
                            'd' => $domain,
                        ]);
                    }
                }
            }

            // Use the best available candidate info for the result entry.
            // For directory domains, $candidate may be null (keyed by URL), so use getByDomain().
            $resultCandidate = $candidate ?? ($candidates->getByDomain($domain)[0] ?? null);

            $result = new DiscoveryResult(
                $domain,
                $resultCandidate['name'] ?? $domain,
                $resultCandidate['url'] ?? 'https://' . $domain,
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

        // 8. Handle directory seeds: search for each extracted company and crawl found websites
        if (!empty($directorySeeds) && \count($directorySeeds) <= self::MAX_DIRECTORY_SEED_CANDIDATES) {
            $this->logger->info('[Pipeline] Searching for {n} directory-seed companies', [
                'n' => \count($directorySeeds),
            ]);

            $seedCandidates = $this->collectSeedCandidates($directorySeeds, $searchRegion, $location);
            if (!$seedCandidates->isEmpty()) {
                $this->logger->info('[Pipeline] Found {n} seed-based candidates to crawl', [
                    'n' => $seedCandidates->count(),
                ]);

                $seedCrawledDomains = $this->domainCrawler->crawl($seedCandidates);

                foreach ($seedCrawledDomains as $domain => $crawledDomain) {
                    $classification = $this->pageClassifier->classify($crawledDomain);
                    $evidenceScore = $this->evidenceScorer->score($crawledDomain, $classification);
                    $locationVerdict = $this->locationVerifier->verify($crawledDomain, $verificationLocation);
                    $contacts = $this->contactExtractor->extract($crawledDomain);

                    $seedCandidate = $seedCandidates->get($domain);

                    $result = new DiscoveryResult(
                        $domain,
                        $seedCandidate['name'] ?? $domain,
                        $seedCandidate['url'] ?? 'https://' . $domain,
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

    /**
     * Search for directory seed companies to find their actual websites.
     *
     * @param array<int, \App\Service\WebCrawler\Seed\DirectorySeed> $seeds
     * @return CandidateSet
     */
    private function collectSeedCandidates(
        array $seeds,
        ?string $searchRegion,
        ?string $location,
    ): CandidateSet {
        $seedQueries = [];
        $added = [];

        foreach ($seeds as $seed) {
            $companyName = $seed->companyName;
            if ($companyName === '' || \strlen($companyName) < 3) {
                continue;
            }

            // Normalize to avoid duplicates
            $normalized = mb_strtolower(trim($companyName));
            if (isset($added[$normalized])) {
                continue;
            }
            $added[$normalized] = true;

            // Create queries to find this company's website
            $locationClause = $location !== null ? ' ' . $location : '';
            $seedQueries[] = [
                'query' => "\"{$companyName}\" official website{$locationClause} -site:linkedin.com -site:wikipedia.org -site:facebook.com",
                'type'  => 'seed_discovery',
            ];

            // Limit to reasonable number
            if (\count($seedQueries) >= self::MAX_DIRECTORY_SEED_CANDIDATES) {
                break;
            }
        }

        if (empty($seedQueries)) {
            return new CandidateSet([], []);
        }

        return $this->candidateCollector->collect($seedQueries, $this->searchProvider, $searchRegion);
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
