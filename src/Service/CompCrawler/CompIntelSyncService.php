<?php

namespace App\Service\CompCrawler;

use App\Entity\Competitor;
use App\Entity\CompetitorBlockIntel;
use App\Repository\CompetitorBlockIntelRepository;
use App\Repository\CompetitorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * CompIntelSyncService — Push verified competitor intelligence to LeadCrawler.
 *
 * When CompCrawler verifies a domain as a real competitor, it creates a
 * CompetitorBlockIntel record. This is then consumed by LeadCrawler to:
 *   1. Block competitor domains from lead pipeline
 *   2. Add competitor phrases to negative filters
 *   3. Provide embedding examples for the classifier
 */
class CompIntelSyncService
{
    public function __construct(
        private readonly CompetitorRepository $competitorRepo,
        private readonly CompetitorBlockIntelRepository $blockIntelRepo,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Generate block intel for all verified competitors and queue for sync.
     *
     * @return array{created: int, updated: int, total_domains: int}
     */
    public function generateBlockIntel(): array
    {
        $competitors = $this->competitorRepo->findActive();
        $created = 0;
        $updated = 0;
        $totalDomains = 0;

        foreach ($competitors as $competitor) {
            if ($competitor->getStatus() === Competitor::STATUS_REJECTED) continue;
            if ($competitor->getStatus() === Competitor::STATUS_CANDIDATE) continue;

            $result = $this->syncSingleCompetitor($competitor);
            if ($result === 'created') $created++;
            if ($result === 'updated') $updated++;
            $totalDomains++;
        }

        $this->em->flush();

        $this->logger->info('CompIntelSync: Generated block intel', [
            'created' => $created,
            'updated' => $updated,
            'total' => $totalDomains,
        ]);

        return ['created' => $created, 'updated' => $updated, 'total_domains' => $totalDomains];
    }

    /**
     * Sync a single competitor's intel to the block list.
     */
    private function syncSingleCompetitor(Competitor $competitor): string
    {
        // Check for existing intel record
        $existing = $this->blockIntelRepo->findOneBy(['competitor' => $competitor]);

        $blockedDomains = $this->buildBlockedDomains($competitor);
        $competitorPhrases = $this->buildCompetitorPhrases($competitor);
        $embeddingExamples = $this->buildEmbeddingExamples($competitor);

        if ($existing) {
            // Update if changed
            $changed = false;

            if ($existing->getBlockedDomainsAdd() !== $blockedDomains) {
                $existing->setBlockedDomainsAdd($blockedDomains);
                $changed = true;
            }
            if ($existing->getCompetitorPhrasesAdd() !== $competitorPhrases) {
                $existing->setCompetitorPhrasesAdd($competitorPhrases);
                $changed = true;
            }
            if ($existing->getEmbeddingExamplesAdd() !== $embeddingExamples) {
                $existing->setEmbeddingExamplesAdd($embeddingExamples);
                $changed = true;
            }

            if ($changed) {
                $existing->setSyncedToLeadcrawler(false);
                return 'updated';
            }

            return 'unchanged';
        }

        // Create new
        $intel = new CompetitorBlockIntel();
        $intel->setCompetitor($competitor);
        $intel->setBlockedDomainsAdd($blockedDomains);
        $intel->setCompetitorPhrasesAdd($competitorPhrases);
        $intel->setEmbeddingExamplesAdd($embeddingExamples);
        $intel->setSyncedToLeadcrawler(false);

        $this->em->persist($intel);
        return 'created';
    }

    /**
     * Build the list of domains to block.
     */
    private function buildBlockedDomains(Competitor $competitor): array
    {
        $domains = [$competitor->getCanonicalDomain()];

        foreach ($competitor->getAltDomains() as $alt) {
            $domains[] = $alt;
        }

        // Add www variants
        $withWww = [];
        foreach ($domains as $d) {
            if (!str_starts_with($d, 'www.')) {
                $withWww[] = 'www.' . $d;
            }
        }

        return array_unique(array_merge($domains, $withWww));
    }

    /**
     * Build competitor name phrases for negative filtering.
     */
    private function buildCompetitorPhrases(Competitor $competitor): array
    {
        $phrases = [];

        // Company name and variations
        $name = $competitor->getName();
        $phrases[] = $name;

        // Remove common suffixes for base name
        $baseName = preg_replace(
            '/\s+(inc|ltd|gmbh|ag|sa|sas|sarl|llc|corp|plc|bv|nv|srl|spa)\b\.?$/i',
            '',
            $name
        );
        if ($baseName !== $name) {
            $phrases[] = $baseName;
        }

        // Domain as phrase (without TLD)
        $domain = $competitor->getCanonicalDomain();
        $domainBase = explode('.', $domain)[0];
        if (strlen($domainBase) > 3 && strtolower($domainBase) !== strtolower($baseName)) {
            $phrases[] = $domainBase;
        }

        return array_unique(array_filter($phrases));
    }

    /**
     * Build embedding examples for the LeadCrawler classifier.
     * These are short descriptions that exemplify "this is a competitor, not a lead."
     */
    private function buildEmbeddingExamples(Competitor $competitor): array
    {
        $examples = [];

        $name = $competitor->getName();
        $types = $competitor->getCompetitorTypes();
        $typeLabel = !empty($types) ? implode('/', $types) : 'manufacturer';

        $examples[] = "{$name} is a {$typeLabel} competitor, not a potential customer.";

        // Add capability-based examples
        $caps = $competitor->getCapabilities();
        if (!empty($caps)) {
            $capList = implode(', ', array_slice($caps, 0, 3));
            $examples[] = "{$name} offers {$capList} — they are a competitor in this space.";
        }

        // Industries
        $industries = $competitor->getIndustries();
        if (!empty($industries)) {
            $indList = implode(', ', array_slice($industries, 0, 3));
            $examples[] = "{$name} serves {$indList} industries as a competitor manufacturer.";
        }

        return $examples;
    }

    /**
     * Get all unsynced intel records (for LeadCrawler to consume).
     *
     * @return CompetitorBlockIntel[]
     */
    public function getUnsyncedIntel(): array
    {
        return $this->blockIntelRepo->findUnsynced();
    }

    /**
     * Mark intel records as synced.
     *
     * @param CompetitorBlockIntel[] $records
     */
    public function markSynced(array $records): void
    {
        foreach ($records as $record) {
            $record->setSyncedToLeadcrawler(true);
        }
        $this->em->flush();
    }

    /**
     * Export all block intel as a flat structure for LeadCrawler configuration.
     *
     * @return array{blocked_domains: string[], competitor_phrases: string[], embedding_examples: string[]}
     */
    public function exportFlatBlockList(): array
    {
        $allDomains = [];
        $allPhrases = [];
        $allExamples = [];

        $records = $this->blockIntelRepo->findAll();
        foreach ($records as $record) {
            $allDomains = array_merge($allDomains, $record->getBlockedDomainsAdd());
            $allPhrases = array_merge($allPhrases, $record->getCompetitorPhrasesAdd());
            $allExamples = array_merge($allExamples, $record->getEmbeddingExamplesAdd());
        }

        return [
            'blocked_domains' => array_unique($allDomains),
            'competitor_phrases' => array_unique($allPhrases),
            'embedding_examples' => array_unique($allExamples),
        ];
    }
}
