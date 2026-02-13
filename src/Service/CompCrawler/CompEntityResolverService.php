<?php

namespace App\Service\CompCrawler;

use App\Entity\Competitor;
use App\Repository\CompetitorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * CompEntityResolverService — Dedupe, merge, and canonicalize competitor entities.
 *
 * Deduplication strategies:
 *  1. Domain match (canonical domain)
 *  2. Alt-domain match (JSON array search)
 *  3. Name fuzzy match (Levenshtein + normalized)
 *  4. Subsidiary rollup (parent-child linking)
 */
class CompEntityResolverService
{
    public function __construct(
        private readonly CompetitorRepository $competitorRepo,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Resolve a candidate domain: find existing or confirm new.
     *
     * @return array{action: string, competitor: Competitor|null, match_type: string|null}
     *         action = 'exists' | 'merge' | 'new'
     */
    public function resolve(string $domain, ?string $companyName = null): array
    {
        $canonical = $this->canonicalizeDomain($domain);

        // 1. Exact canonical domain match
        $existing = $this->competitorRepo->findByDomain($canonical);
        if ($existing) {
            return ['action' => 'exists', 'competitor' => $existing, 'match_type' => 'canonical_domain'];
        }

        // 2. Alt-domain match
        $altMatch = $this->competitorRepo->findByAnyDomain($canonical);
        if ($altMatch) {
            return ['action' => 'exists', 'competitor' => $altMatch, 'match_type' => 'alt_domain'];
        }

        // 3. Reverse: check if domain is an alt of existing
        $withWww = 'www.' . $canonical;
        $withoutWww = str_starts_with($canonical, 'www.') ? substr($canonical, 4) : null;

        foreach ([$withWww, $withoutWww] as $variant) {
            if ($variant) {
                $match = $this->competitorRepo->findByDomain($variant);
                if ($match) {
                    return ['action' => 'exists', 'competitor' => $match, 'match_type' => 'domain_variant'];
                }
                $match = $this->competitorRepo->findByAnyDomain($variant);
                if ($match) {
                    return ['action' => 'exists', 'competitor' => $match, 'match_type' => 'alt_domain_variant'];
                }
            }
        }

        // 4. Name-based fuzzy match (if company name provided)
        if ($companyName) {
            $nameMatch = $this->findByFuzzyName($companyName);
            if ($nameMatch) {
                // Add domain as alt-domain
                $altDomains = $nameMatch->getAltDomains();
                if (!in_array($canonical, $altDomains, true)) {
                    $altDomains[] = $canonical;
                    $nameMatch->setAltDomains($altDomains);
                    $this->em->flush();
                }
                $this->logger->info('CompResolver: Merged {domain} into {existing} via name match', [
                    'domain' => $canonical,
                    'existing' => $nameMatch->getCanonicalDomain(),
                ]);
                return ['action' => 'merge', 'competitor' => $nameMatch, 'match_type' => 'fuzzy_name'];
            }
        }

        return ['action' => 'new', 'competitor' => null, 'match_type' => null];
    }

    /**
     * Canonicalize a domain: strip www, lowercase, trim slashes.
     */
    public function canonicalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));

        // Remove protocol
        $domain = preg_replace('#^https?://#', '', $domain);
        // Remove trailing path
        $domain = explode('/', $domain)[0];
        // Remove port
        $domain = explode(':', $domain)[0];
        // Strip www.
        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        return $domain;
    }

    /**
     * Merge two competitor entities (secondary → primary).
     * Moves change events, fingerprints, and watchlist memberships.
     */
    public function merge(Competitor $primary, Competitor $secondary): void
    {
        $this->logger->info('CompResolver: Merging {sec} into {pri}', [
            'sec' => $secondary->getCanonicalDomain(),
            'pri' => $primary->getCanonicalDomain(),
        ]);

        // Merge alt-domains
        $altDomains = array_unique(array_merge(
            $primary->getAltDomains(),
            $secondary->getAltDomains(),
            [$secondary->getCanonicalDomain()]
        ));
        // Remove primary's canonical from alt list
        $altDomains = array_values(array_filter(
            $altDomains,
            fn(string $d) => $d !== $primary->getCanonicalDomain()
        ));
        $primary->setAltDomains($altDomains);

        // Merge certifications
        $primary->setCertifications(array_unique(array_merge(
            $primary->getCertifications(),
            $secondary->getCertifications()
        )));

        // Merge industries
        $primary->setIndustries(array_unique(array_merge(
            $primary->getIndustries(),
            $secondary->getIndustries()
        )));

        // Merge capabilities
        $primary->setCapabilities(array_unique(array_merge(
            $primary->getCapabilities(),
            $secondary->getCapabilities()
        )));

        // Merge types
        $primary->setCompetitorTypes(array_unique(array_merge(
            $primary->getCompetitorTypes(),
            $secondary->getCompetitorTypes()
        )));

        // Take higher scores
        if ($secondary->getThreatScore() > $primary->getThreatScore()) {
            $primary->setThreatScore($secondary->getThreatScore());
        }
        if ($secondary->getOverlapScore() > $primary->getOverlapScore()) {
            $primary->setOverlapScore($secondary->getOverlapScore());
        }

        // Move page fingerprints
        $this->em->createQuery(
            'UPDATE App\Entity\CompetitorPageFingerprint f SET f.competitor = :primary WHERE f.competitor = :secondary'
        )->execute(['primary' => $primary, 'secondary' => $secondary]);

        // Move change events
        $this->em->createQuery(
            'UPDATE App\Entity\CompetitorChangeEvent e SET e.competitor = :primary WHERE e.competitor = :secondary'
        )->execute(['primary' => $primary, 'secondary' => $secondary]);

        // Remove secondary
        $this->em->remove($secondary);
        $this->em->flush();

        $this->logger->info('CompResolver: Merge complete, secondary removed');
    }

    /**
     * Link subsidiary to parent competitor.
     */
    public function linkSubsidiary(Competitor $parent, Competitor $subsidiary): void
    {
        $subsidiary->setParentCompetitor($parent);
        $this->em->flush();

        $this->logger->info('CompResolver: Linked {sub} as subsidiary of {parent}', [
            'sub' => $subsidiary->getCanonicalDomain(),
            'parent' => $parent->getCanonicalDomain(),
        ]);
    }

    /**
     * Find existing competitor by fuzzy company name match.
     */
    private function findByFuzzyName(string $name): ?Competitor
    {
        $normalizedInput = $this->normalizeName($name);
        if (strlen($normalizedInput) < 3) return null;

        // Get all active competitors for comparison
        $candidates = $this->competitorRepo->findActive();

        $bestMatch = null;
        $bestScore = 0;

        foreach ($candidates as $candidate) {
            $normalizedCandidate = $this->normalizeName($candidate->getName());
            if (strlen($normalizedCandidate) < 3) continue;

            // Exact normalized match
            if ($normalizedInput === $normalizedCandidate) {
                return $candidate;
            }

            // Levenshtein similarity
            $maxLen = max(strlen($normalizedInput), strlen($normalizedCandidate));
            if ($maxLen === 0) continue;

            $distance = levenshtein($normalizedInput, $normalizedCandidate);
            $similarity = 1 - ($distance / $maxLen);

            // Also check contains
            $containsScore = 0;
            if (str_contains($normalizedCandidate, $normalizedInput) || str_contains($normalizedInput, $normalizedCandidate)) {
                $containsScore = 0.3;
            }

            $score = $similarity + $containsScore;

            if ($score > $bestScore && $score >= 0.85) {
                $bestScore = $score;
                $bestMatch = $candidate;
            }
        }

        return $bestMatch;
    }

    /**
     * Normalize company name for comparison.
     */
    private function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));

        // Remove common suffixes
        $suffixes = [
            'inc', 'incorporated', 'ltd', 'limited', 'llc', 'gmbh',
            'ag', 'sa', 'sas', 'sarl', 'co', 'corp', 'corporation',
            'bv', 'nv', 'plc', 'pvt', 'pty', 'srl', 'spa',
        ];

        $name = preg_replace('/[^a-z0-9\s]/', '', $name);
        $words = explode(' ', $name);
        $words = array_filter($words, fn(string $w) => !in_array($w, $suffixes, true));

        return trim(implode(' ', $words));
    }

    /**
     * Batch resolve: check a list of domains and return results.
     *
     * @param string[] $domains
     * @return array<string, array{action: string, competitor: Competitor|null, match_type: string|null}>
     */
    public function batchResolve(array $domains): array
    {
        $results = [];
        foreach ($domains as $domain) {
            $results[$domain] = $this->resolve($domain);
        }
        return $results;
    }
}
