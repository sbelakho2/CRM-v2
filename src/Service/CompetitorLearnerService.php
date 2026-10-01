<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LearnedCompetitor;
use App\Entity\Lead;
use App\Repository\LearnedCompetitorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Competitor Learner Service
 * 
 * Automatically discovers and learns about competitors from:
 * - Web scraping results (company pages, supplier lists)
 * - Press releases and news
 * - Customer reference lists
 * - Manual user submissions
 * 
 * The competitor list grows dynamically as more data is scraped,
 * with confidence scores increasing based on detection frequency.
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
class CompetitorLearnerService
{
    // Seed competitors (hardcoded baseline)
    // Starz competes with contract manufacturers in North Africa, MENA, and Eastern Europe
    // Services: PCBA, Cable/Wire Harness, Overmolding, Copper Windings, System Integration
    // Industries: Automotive, Aerospace, Industrial
    // NOTE: Tier 1 auto suppliers (Aptiv, Yazaki, Leoni, Valeo, Lear, Sumitomo) are CUSTOMERS!
    public const SEED_COMPETITORS = [
        // Tier 1 - Direct Competitors (North Africa - same services, same region)
        ['telnet-group.com', 'Telnet', 'Telnet Holding', 1, 'ems'],              // Tunisia - aerospace/auto EMS
        ['all-circuits.com', 'All Circuits', 'All Circuits Group', 1, 'ems'],    // Tunisia/Morocco - PCBA
        ['actia.com', 'Actia', 'Actia Group', 1, 'ems'],                         // Tunisia - automotive electronics
        ['eolane.com', 'Eolane', 'Eolane', 1, 'ems'],                            // Morocco - EMS
        ['premo-group.com', 'Premo', 'Premo Group', 1, 'ems'],                   // Morocco - magnetics/windings
        ['coficab.com', 'Coficab', 'Coficab Group', 1, 'cable_harness'],         // Tunisia - cable harness specialist
        ['kromberg-schubert.com', 'Kromberg', 'Kromberg & Schubert', 1, 'cable_harness'], // Morocco - harnesses
        ['dräxlmaier.com', 'Draexlmaier', 'Dräxlmaier Group', 1, 'cable_harness'], // Morocco - harness/interiors
        ['matis-aerospace.com', 'Matis', 'Matis Aerospace', 1, 'ems'],           // Morocco - aerospace EMS
        ['sews-cabind.com', 'SEWS-Cabind', 'SEWS-Cabind', 1, 'cable_harness'],   // Morocco - Sumitomo JV harness
        
        // Tier 2 - Eastern European Competitors (compete for EU OEM business)
        ['fideltronik.com', 'Fideltronik', 'Fideltronik', 2, 'ems'],             // Poland - EMS
        ['videoton.hu', 'Videoton', 'Videoton Holding', 2, 'ems'],               // Hungary - major E.Europe EMS
        ['katek.de', 'KATEK', 'KATEK SE', 2, 'ems'],                             // Germany/E.Europe - EMS
        ['kitron.com', 'Kitron', 'Kitron ASA', 2, 'ems'],                        // Norway/Lithuania - EMS
        ['scanfil.com', 'Scanfil', 'Scanfil EMS', 2, 'ems'],                     // Finland/Poland/Hungary - EMS
        ['note.eu', 'NOTE', 'NOTE AB', 2, 'ems'],                                // Sweden/Estonia - EMS
        ['enics.com', 'Enics', 'Enics AG', 2, 'ems'],                            // Switzerland/Slovakia - EMS
        ['gpvintl.com', 'GPV', 'GPV International', 2, 'ems'],                   // Denmark/Slovakia - EMS
        ['zollner.de', 'Zollner', 'Zollner Elektronik', 2, 'ems'],               // Germany/Romania - EMS
        ['cicor.com', 'Cicor', 'Cicor Group', 2, 'ems'],                         // Swiss - EMS/overmolding
        ['pke-group.com', 'PKE', 'PKE Electronics', 2, 'ems'],                   // Austria - EMS
        ['incap.com', 'Incap', 'Incap Corporation', 2, 'ems'],                   // Estonia/India - EMS
        
        // Tier 3 - Global EMS (different scale, occasionally overlap)
        ['flex.com', 'Flex', 'Flex Ltd', 3, 'ems'],
        ['jabil.com', 'Jabil', 'Jabil Inc', 3, 'ems'],
        ['celestica.com', 'Celestica', 'Celestica Inc', 3, 'ems'],
        ['sanmina.com', 'Sanmina', 'Sanmina Corporation', 3, 'ems'],
        ['plexus.com', 'Plexus', 'Plexus Corp', 3, 'ems'],
        ['ttelectronics.com', 'TT Electronics', 'TT Electronics plc', 3, 'ems'],
        ['benchmark.com', 'Benchmark', 'Benchmark Electronics', 3, 'ems'],
    ];

    // Industry/service keywords for classification
    // Matches Starz's full service offerings
    private const INDUSTRY_KEYWORDS = [
        'ems' => ['ems', 'electronics manufacturing', 'contract manufacturing', 'manufacturing services', 'odm', 'oem'],
        'pcba' => ['pcba', 'pcb assembly', 'printed circuit board', 'smt', 'surface mount', 'through-hole', 'bga', 'fine pitch'],
        'cable_harness' => ['cable assembly', 'wire harness', 'cable harness', 'wiring harness', 'wiring assembly', 'cable loom'],
        'overmolding' => ['overmolding', 'overmold', 'injection molding', 'plastic injection', 'encapsulation', 'potting'],
        'windings' => ['copper winding', 'coil winding', 'transformer', 'inductor', 'magnetics', 'choke'],
        'mechanical' => ['cnc machining', 'mechanical assembly', 'sheet metal', 'tooling', 'enclosure'],
        'system_integration' => ['system integration', 'box build', 'turnkey', 'full assembly', 'complete system'],
        'test_equipment' => ['test equipment', 'testing', 'ict', 'aoi', 'x-ray', 'functional test'],
    ];

    // Patterns that indicate a company is a competitor (contract manufacturer)
    private const COMPETITOR_INDICATORS = [
        // EMS/PCBA indicators
        'ems provider',
        'electronics manufacturing',
        'contract manufacturer',
        'pcb assembly',
        'pcba services',
        'smt services',
        'electronic assemblies',
        'box build',
        'turnkey manufacturing',
        'prototype to production',
        'low volume high mix',
        'high volume manufacturing',
        // Cable/harness indicators
        'cable assembly',
        'wire harness',
        'wiring harness',
        'cable harness manufacturer',
        'harness assembly',
        'cable solutions',
        // Overmolding indicators
        'overmolding services',
        'injection molding',
        'plastic injection',
        'overmolded cables',
        'encapsulation services',
        // System integration
        'system integrator',
        'turnkey solutions',
        'full system assembly',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LearnedCompetitorRepository $competitorRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Initialize seed competitors in database
     */
    public function seedCompetitors(): array
    {
        $seeded = [];
        
        foreach (self::SEED_COMPETITORS as [$domain, $name, $fullName, $tier, $industry]) {
            $existing = $this->competitorRepository->findByDomain($domain);
            
            if ($existing) {
                continue; // Already exists
            }
            
            $competitor = new LearnedCompetitor();
            $competitor->setDomain($domain);
            $competitor->setName($name);
            $competitor->setFullName($fullName);
            $competitor->setTier($tier);
            $competitor->setIndustry($industry);
            $competitor->setDiscoverySource(LearnedCompetitor::SOURCE_MANUAL);
            $competitor->setDiscoveryContext('Seeded from hardcoded competitor list');
            $competitor->setConfidenceScore(100); // Seed competitors are verified
            $competitor->setVerified(true);
            $competitor->setDetectionCount(10); // Give them some initial weight
            
            $this->entityManager->persist($competitor);
            $seeded[] = $competitor;
            
            $this->logger->debug('Seeded competitor', ['domain' => $domain, 'name' => $name]);
        }
        
        $this->entityManager->flush();
        
        $this->logger->info('Competitor seeding complete', ['count' => count($seeded)]);
        
        return $seeded;
    }

    /**
     * Learn competitors from scraped website content
     * 
     * @param string $content The scraped page content
     * @param string $sourceUrl The URL where content was scraped from
     * @param string $discoverySource The discovery source type
     * @return array Newly discovered or updated competitors
     */
    public function learnFromContent(
        string $content,
        string $sourceUrl,
        string $discoverySource = LearnedCompetitor::SOURCE_WEBSITE_SCRAPE
    ): array {
        $discovered = [];
        $contentLower = strtolower($content);
        
        // 1. Check against existing competitors (update detection count)
        $existingCompetitors = $this->competitorRepository->findAllActive();
        foreach ($existingCompetitors as $competitor) {
            if ($competitor->matchesContent($contentLower)) {
                $competitor->incrementDetectionCount();
                $this->entityManager->persist($competitor);
                $discovered[] = [
                    'competitor' => $competitor,
                    'action' => 'updated',
                ];
            }
        }
        
        // 2. Try to discover new competitors from content
        $newCompetitors = $this->extractPotentialCompetitors($content, $sourceUrl);
        
        foreach ($newCompetitors as $potential) {
            $existing = $this->competitorRepository->findByDomain($potential['domain']);
            
            if ($existing) {
                // Already tracked, just increment
                $existing->incrementDetectionCount();
                $existing->addAlias($potential['name']);
                $this->entityManager->persist($existing);
                continue;
            }
            
            // Create new learned competitor
            $competitor = new LearnedCompetitor();
            $competitor->setDomain($potential['domain']);
            $competitor->setName($potential['name']);
            $competitor->setFullName($potential['fullName'] ?? $potential['name']);
            $competitor->setTier(3); // Start at lowest tier
            $competitor->setIndustry($potential['industry'] ?? LearnedCompetitor::INDUSTRY_EMS);
            $competitor->setDiscoverySource($discoverySource);
            $competitor->setDiscoveryContext("Discovered from: $sourceUrl");
            $competitor->setConfidenceScore(30); // Low initial confidence
            
            if (!empty($potential['keywords'])) {
                $competitor->setKeywords($potential['keywords']);
            }
            
            $this->entityManager->persist($competitor);
            $discovered[] = [
                'competitor' => $competitor,
                'action' => 'created',
            ];
            
            $this->logger->info('Discovered new potential competitor', [
                'domain' => $potential['domain'],
                'name' => $potential['name'],
                'source' => $sourceUrl,
            ]);
        }
        
        $this->entityManager->flush();
        
        return $discovered;
    }

    /**
     * Extract potential competitors from content
     */
    private function extractPotentialCompetitors(string $content, string $sourceUrl): array
    {
        $potentials = [];
        $contentLower = strtolower($content);
        
        // Check if content contains competitor indicators
        $hasCompetitorIndicators = false;
        foreach (self::COMPETITOR_INDICATORS as $indicator) {
            if (strpos($contentLower, $indicator) !== false) {
                $hasCompetitorIndicators = true;
                break;
            }
        }
        
        if (!$hasCompetitorIndicators) {
            return []; // Content doesn't seem to be about EMS/PCBA
        }
        
        // Extract company domain patterns (e.g., "working with acme.com")
        // Look for patterns like "partnered with X", "supplier X", "manufactured by X"
        $patterns = [
            '/(?:partnered with|working with|manufactured by|supplied by|contract with|ems provider)\s+([a-z0-9][-a-z0-9]+(?:\.[a-z]{2,}))/i',
            '/([a-z0-9][-a-z0-9]+\.(?:com|de|eu|co\.uk|fr|nl|it|es|se|no|fi|at|ch))/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $domain) {
                    $domain = strtolower(trim($domain, '.'));
                    
                    // Filter out common non-competitor domains
                    if ($this->isIgnoredDomain($domain)) {
                        continue;
                    }
                    
                    // Extract company name from domain
                    $name = $this->domainToCompanyName($domain);
                    
                    // Determine industry from context
                    $industry = $this->detectIndustry($contentLower);
                    
                    $potentials[$domain] = [
                        'domain' => $domain,
                        'name' => $name,
                        'industry' => $industry,
                        'keywords' => $this->extractKeywordsNearMention($content, $domain),
                    ];
                }
            }
        }
        
        return array_values($potentials);
    }

    /**
     * Check if domain should be ignored (common sites, etc.)
     */
    private function isIgnoredDomain(string $domain): bool
    {
        $ignored = [
            'google.com', 'facebook.com', 'linkedin.com', 'twitter.com', 'youtube.com',
            'amazon.com', 'microsoft.com', 'apple.com', 'github.com', 'gmail.com',
            'wikipedia.org', 'w3.org', 'cloudflare.com', 'googleapis.com',
            'jquery.com', 'bootstrap.com', 'wordpress.com', 'wix.com',
        ];
        
        return in_array($domain, $ignored);
    }

    /**
     * Convert domain to likely company name
     */
    private function domainToCompanyName(string $domain): string
    {
        // Remove TLD
        $name = preg_replace('/\.[a-z]{2,}$/', '', $domain);
        
        // Convert hyphens to spaces
        $name = str_replace('-', ' ', $name);
        
        // Capitalize words
        $name = ucwords($name);
        
        return $name;
    }

    /**
     * Detect industry from content context
     */
    private function detectIndustry(string $content): string
    {
        $scores = [];
        
        foreach (self::INDUSTRY_KEYWORDS as $industry => $keywords) {
            $scores[$industry] = 0;
            foreach ($keywords as $keyword) {
                $scores[$industry] += $this->countKeywordOccurrences($content, $keyword);
            }
        }
        
        arsort($scores);
        $topIndustry = array_key_first($scores);
        
        return $scores[$topIndustry] > 0 ? $topIndustry : LearnedCompetitor::INDUSTRY_EMS;
    }

    /**
     * Count occurrences of a keyword in lowercased content.
     *
     * Short single-word keywords (ems, smt, pcb, ict, aoi, bga, odm, oem,
     * ...) are matched with word boundaries so that e.g. "ems" does not
     * match inside "systems" or "blossom". Multi-word phrases keep plain
     * substring counting.
     */
    private function countKeywordOccurrences(string $content, string $keyword): int
    {
        $content = strtolower($content);

        if (str_contains($keyword, ' ')) {
            return substr_count($content, $keyword);
        }

        $pattern = '/(?<![a-z0-9])' . preg_quote($keyword, '/') . '(?![a-z0-9])/';
        $count = preg_match_all($pattern, $content);

        return $count !== false ? $count : 0;
    }

    /**
     * Extract keywords near a domain mention
     */
    private function extractKeywordsNearMention(string $content, string $domain): array
    {
        $keywords = [];
        $pos = stripos($content, $domain);
        
        if ($pos === false) {
            return $keywords;
        }
        
        // Get 200 chars around the mention
        $start = max(0, $pos - 100);
        $context = substr($content, $start, 200);
        
        // Extract relevant keywords from context
        $allKeywords = [];
        foreach (self::INDUSTRY_KEYWORDS as $words) {
            $allKeywords = array_merge($allKeywords, $words);
        }
        
        foreach ($allKeywords as $keyword) {
            // Short keywords must match as whole words to avoid false
            // positives in surrounding text (e.g. 'ems' inside 'systems').
            if ($this->countKeywordOccurrences($context, $keyword) > 0) {
                $keywords[] = $keyword;
            }
        }
        
        return array_unique($keywords);
    }

    /**
     * Learn from Google Dork search results
      * @param array<string|int, mixed> $searchResults
     */
    public function learnFromGoogleResults(array $searchResults): array
    {
        $discovered = [];
        
        foreach ($searchResults as $result) {
            if (empty($result['snippet']) && empty($result['title'])) {
                continue;
            }
            
            $content = ($result['title'] ?? '') . ' ' . ($result['snippet'] ?? '');
            $sourceUrl = $result['link'] ?? 'google_search';
            
            $found = $this->learnFromContent(
                $content,
                $sourceUrl,
                LearnedCompetitor::SOURCE_GOOGLE_DORK
            );
            
            $discovered = array_merge($discovered, $found);
        }
        
        return $discovered;
    }

    /**
     * Get all learned competitors as a detection map
     * Returns format compatible with CompetitorDetectionService
     */
    public function getCompetitorDetectionMap(): array
    {
        $competitors = $this->competitorRepository->findHighConfidence(50);
        
        $map = [
            1 => [], // Tier 1
            2 => [], // Tier 2
            3 => [], // Tier 3
        ];
        
        foreach ($competitors as $competitor) {
            $tier = $competitor->getTier();
            $map[$tier][$competitor->getDomain()] = $competitor->getName();
        }
        
        return $map;
    }

    /**
     * Verify a competitor (admin action)
     */
    public function verifyCompetitor(int $competitorId, string $verifiedBy, ?int $newTier = null): ?LearnedCompetitor
    {
        $competitor = $this->competitorRepository->find($competitorId);
        
        if (!$competitor) {
            return null;
        }
        
        $competitor->setVerified(true);
        $competitor->setVerifiedBy($verifiedBy);
        
        if ($newTier !== null) {
            $competitor->setTier($newTier);
        }
        
        $this->entityManager->persist($competitor);
        $this->entityManager->flush();
        
        $this->logger->info('Competitor verified', [
            'id' => $competitorId,
            'domain' => $competitor->getDomain(),
            'verifiedBy' => $verifiedBy,
        ]);
        
        return $competitor;
    }

    /**
     * Merge duplicate competitors
     */
    public function mergeCompetitors(int $primaryId, int $duplicateId): ?LearnedCompetitor
    {
        $primary = $this->competitorRepository->find($primaryId);
        $duplicate = $this->competitorRepository->find($duplicateId);
        
        if (!$primary || !$duplicate) {
            return null;
        }
        
        // Merge aliases
        foreach ($duplicate->getAliases() as $alias) {
            $primary->addAlias($alias);
        }
        $primary->addAlias($duplicate->getDomain());
        $primary->addAlias($duplicate->getName());
        
        // Merge keywords
        foreach ($duplicate->getKeywords() as $keyword) {
            $primary->addKeyword($keyword);
        }
        
        // Combine detection counts
        $primary->setDetectionCount(
            $primary->getDetectionCount() + $duplicate->getDetectionCount()
        );
        
        // Deactivate duplicate
        $duplicate->setActive(false);
        
        $this->entityManager->persist($primary);
        $this->entityManager->persist($duplicate);
        $this->entityManager->flush();
        
        $this->logger->info('Competitors merged', [
            'primary' => $primary->getDomain(),
            'duplicate' => $duplicate->getDomain(),
        ]);
        
        return $primary;
    }

    /**
     * Get statistics about learned competitors
     */
    public function getStatistics(): array
    {
        return $this->competitorRepository->getStatistics();
    }

    /**
     * Get static seed competitor map for use by CompetitorDetectionService.
     *
     * @return array<int, array<string, string>> Tier => [domain => name]
     */
    public static function getStaticCompetitorsByTier(): array
    {
        $map = [1 => [], 2 => [], 3 => []];
        foreach (self::SEED_COMPETITORS as [$domain, $name, $fullName, $tier, $industry]) {
            $map[$tier][$domain] = $name;
        }
        return $map;
    }
}
