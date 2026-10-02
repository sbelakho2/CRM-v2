<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Lead;
use App\Entity\CompetitorDetection;
use App\Entity\LearnedCompetitor;
use App\Repository\CompetitorDetectionRepository;
use App\Repository\LeadRepository;
use App\Repository\LearnedCompetitorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Competitor Detection Service (Sniper)
 * 
 * Detects OEMs using competitor contract manufacturers for "Sniper" displacement.
 * When a prospect works with a regional competitor, they're high-value targets
 * as they already outsource electronics/harness manufacturing.
 * 
 * STARZ SERVICES (American-Tunisian, 25+ years):
 * - PCB Assembly (fine-pitch, BGA, multi-layer)
 * - Cable & Wire Harness Assembly
 * - Overmolding & Plastic Injection  
 * - Copper Windings
 * - System Integration & Turnkey Projects
 * - Mechanical Services (CNC)
 * - Design & Prototyping, R&D
 * 
 * INDUSTRIES: Automotive, Aerospace, Industrial
 * LOCATIONS: Tunisia (HQ), Morocco (Tangier Free Zone)
 * 
 * IDEAL CUSTOMERS (NOT competitors!): 
 * Tier 1 auto suppliers - Aptiv, Yazaki, Leoni, Valeo, Lear, Sumitomo
 * 
 * ACTUAL COMPETITORS by tier:
 * - Tier 1: North Africa contract mfrs +45 boost
 *   Telnet, All Circuits, Actia, Coficab, Kromberg, Draexlmaier, SEWS-Cabind
 * - Tier 2: Eastern European EMS +30 boost  
 *   Fideltronik, Videoton, KATEK, Kitron, Scanfil, Enics, GPV, Zollner
 * - Tier 3: Global EMS (rarely compete directly) +15 boost
 *   Flex, Jabil, Celestica, Sanmina, Plexus
 * 
 * @see Documentation/AUTONOMOUS_SALES_V2.md
 */
class CompetitorDetectionService
{
    // Tier 1 - Direct Competitors (North Africa - same services, same region)
    // EMS, Cable Harness, Overmolding providers in Morocco/Tunisia
    // Public: original curated seed lists, kept available for tooling/tests and
    // subclasses; the active pipeline reads CompetitorLearnerService instead.
    public const TIER_1_COMPETITORS = [
        'telnet-group.com' => 'Telnet',
        'all-circuits.com' => 'All Circuits',
        'actia.com' => 'Actia',
        'eolane.com' => 'Eolane',
        'premo-group.com' => 'Premo',
        'coficab.com' => 'Coficab',               // Major harness competitor
        'kromberg-schubert.com' => 'Kromberg & Schubert', // Harness
        'draexlmaier.com' => 'Draexlmaier',       // Harness/interiors
        'matis-aerospace.com' => 'Matis Aerospace',
        'sews-cabind.com' => 'SEWS-Cabind',       // Sumitomo JV harness
    ];

    // Tier 2 - Eastern European EMS (compete for same European OEM business)
    public const TIER_2_COMPETITORS = [
        'fideltronik.com' => 'Fideltronik',
        'videoton.hu' => 'Videoton',
        'katek.de' => 'KATEK',
        'kitron.com' => 'Kitron',
        'scanfil.com' => 'Scanfil',
        'note.eu' => 'NOTE',
        'enics.com' => 'Enics',
        'gpvintl.com' => 'GPV',
        'zollner.de' => 'Zollner',
        'cicor.com' => 'Cicor',
        'incap.com' => 'Incap',
    ];

    // Tier 3 - Global EMS (different scale, occasionally overlap)
    public const TIER_3_COMPETITORS = [
        'flex.com' => 'Flex',
        'jabil.com' => 'Jabil',
        'celestica.com' => 'Celestica',
        'sanmina.com' => 'Sanmina',
        'benchmark.com' => 'Benchmark',
        'plexus.com' => 'Plexus',
        'ttelectronics.com' => 'TT Electronics',
    ];

    // Score boosts per tier (higher = more valuable displacement opportunity)
    private const TIER_SCORE_BOOSTS = [
        1 => 45,  // Direct regional competitor = highest value target
        2 => 30,  // Eastern Europe = same cost tier, good opportunity
        3 => 15,  // Global players = different segment, less likely switch
    ];

    // Cache for dynamic competitors
    /** @var array<string, array{name: string|null, tier: int, source: string, aliases?: list<string>, confidence?: int}>|null */
    private ?array $dynamicCompetitorCache = null;
    private ?int $cacheTimestamp = null;
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompetitorDetectionRepository $detectionRepository,

        /**
         * Not read in this class yet; kept injected for subclasses and tests.
         */
        protected LeadRepository $leadRepository,
        private ?LearnedCompetitorRepository $learnedCompetitorRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Detect competitors from website content/analysis
     * Now uses both static and dynamically learned competitors
     *
     * @return list<array{domain: string, name: string, tier: int, source: string}>
     */
    public function detectCompetitorsFromContent(Lead $lead, string $content): array
    {
        $detectedCompetitors = [];
        $content = strtolower($content);
        
        // Get all competitors (static + dynamic)
        $allCompetitors = $this->getAllCompetitors();
        
        foreach ($allCompetitors as $domain => $info) {
            // Learned competitors may carry a null name; only the domain is matchable then.
            $competitorName = $info['name'] ?? '';
            if ($competitorName === '' && $domain === '') {
                continue;
            }
            // Check for domain mention or company name
            $domainPattern = preg_quote(strtolower($domain), '/');
            $namePattern = preg_quote(strtolower($competitorName), '/');
            
            // Also check aliases if available
            $patterns = [$domainPattern, $namePattern];
            if (isset($info['aliases'])) {
                foreach ($info['aliases'] as $alias) {
                    $patterns[] = preg_quote(strtolower($alias), '/');
                }
            }
            
            $pattern = '/(' . implode('|', $patterns) . ')/i';
            
            if (preg_match($pattern, $content)) {
                $tier = $info['tier'];
                
                $detectedCompetitors[] = [
                    'domain' => $domain,
                    'name' => $competitorName !== '' ? $competitorName : $domain,
                    'tier' => $tier,
                    'source' => $info['source'],
                ];
                
                // If this is a learned competitor, increment its detection count
                if ($info['source'] === 'dynamic' && $this->learnedCompetitorRepository) {
                    $learnedCompetitor = $this->learnedCompetitorRepository->findByDomain($domain);
                    if ($learnedCompetitor) {
                        $learnedCompetitor->incrementDetectionCount();
                        $this->entityManager->persist($learnedCompetitor);
                    }
                }
            }
        }
        
        // Save detections
        if (!empty($detectedCompetitors)) {
            $this->saveCompetitorDetections($lead, $detectedCompetitors);
            $this->entityManager->flush();
        }
        
        return $detectedCompetitors;
    }

    /**
     * Get all competitors (static seed list + dynamically learned)
     *
     * @return array<string, array{name: string|null, tier: int, source: string, aliases?: list<string>, confidence?: int}>
     */
    public function getAllCompetitors(): array
    {
        // Check cache
        if ($this->dynamicCompetitorCache !== null && 
            $this->cacheTimestamp !== null &&
            (time() - $this->cacheTimestamp) < self::CACHE_TTL) {
            return $this->dynamicCompetitorCache;
        }
        
        // Start with static competitors from CompetitorLearnerService
        $allCompetitors = [];
        $staticByTier = CompetitorLearnerService::getStaticCompetitorsByTier();
        foreach ($staticByTier as $tier => $competitors) {
            foreach ($competitors as $domain => $name) {
                $allCompetitors[$domain] = ['name' => $name, 'tier' => $tier, 'source' => 'static'];
            }
        }
        
        // Add dynamically learned competitors
        if ($this->learnedCompetitorRepository) {
            $learnedCompetitors = $this->learnedCompetitorRepository->findHighConfidence(50);
            
            foreach ($learnedCompetitors as $competitor) {
                $domain = $competitor->getDomain() ?? '';
                
                // Don't override static competitors
                if (!isset($allCompetitors[$domain])) {
                    $allCompetitors[$domain] = [
                        'name' => $competitor->getName(),
                        'tier' => $competitor->getTier(),
                        'source' => 'dynamic',
                        'aliases' => $competitor->getAliases(),
                        'confidence' => $competitor->getConfidenceScore(),
                    ];
                }
            }
        }
        
        // Update cache
        $this->dynamicCompetitorCache = $allCompetitors;
        $this->cacheTimestamp = time();
        
        return $allCompetitors;
    }

    /**
     * Clear competitor cache (call after learning new competitors)
     */
    public function clearCache(): void
    {
        $this->dynamicCompetitorCache = null;
        $this->cacheTimestamp = null;
    }

    /**
     * Get tier for a domain (checks both static and dynamic).
     * Protected: kept for subclass reuse; the current pipeline derives tiers
     * from getAllCompetitors() instead.
     */
    protected function getTierForDomain(string $domain): int
    {
        $staticByTier = CompetitorLearnerService::getStaticCompetitorsByTier();
        foreach ($staticByTier as $tier => $competitors) {
            if (isset($competitors[$domain])) {
                return $tier;
            }
        }
        
        // Check dynamic competitors
        if ($this->learnedCompetitorRepository) {
            $learned = $this->learnedCompetitorRepository->findByDomain($domain);
            if ($learned) {
                return $learned->getTier();
            }
        }
        
        return 3; // Default to tier 3
    }

    /**
     * Save competitor detections for a lead
     *
     * @param list<array{domain: string, name: string, tier: int, source?: string, detectedIn?: string, confidence?: int}> $competitors
     */
    public function saveCompetitorDetections(Lead $lead, array $competitors): void
    {
        foreach ($competitors as $competitor) {
            // Check if already exists
            $existing = $this->detectionRepository->findOneBy([
                'lead' => $lead,
                'competitorDomain' => $competitor['domain'],
            ]);
            
            if ($existing) {
                continue; // Already detected
            }
            
            $detection = new CompetitorDetection();
            $detection->setLead($lead);
            $detection->setCompetitorDomain($competitor['domain']);
            $detection->setCompetitorName($competitor['name']);
            $detection->setCompetitorTier($competitor['tier']);
            $detection->setDetectedIn($competitor['detectedIn'] ?? 'website_analysis');
            $detection->setDetectionConfidence($competitor['confidence'] ?? 100);
            
            $this->entityManager->persist($detection);
            
            $this->logger->info('Detected competitor for lead', [
                'leadId' => $lead->getId(),
                'competitor' => $competitor['name'],
                'tier' => $competitor['tier'],
            ]);
        }
        
        $this->entityManager->flush();
    }

    /**
     * Calculate total competitor score boost for a lead
     */
    public function getCompetitorScoreBoost(Lead $lead): int
    {
        $detections = $this->detectionRepository->findByLead($lead);
        
        if (empty($detections)) {
            return 0;
        }
        
        // Get the highest tier (lowest number = highest priority)
        $bestTier = min(array_map(fn($d) => $d->getCompetitorTier(), $detections));
        
        return self::TIER_SCORE_BOOSTS[$bestTier] ?? 0;
    }

    /**
     * Get competitor leads by tier (for Sniper targeting)
     *
     * @return array{leads: list<Lead>, byCompetitor: array<string, non-empty-list<Lead>>, competitorStats: list<array{name: mixed, domain: mixed, tier: mixed, count: int}>}
     */
    public function getCompetitorLeads(?int $tier = null, int $minScore = 0, int $limit = 100): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('d', 'l')
           ->from(CompetitorDetection::class, 'd')
           ->join('d.lead', 'l')
           ->where('l.leadScore >= :minScore')
           ->setParameter('minScore', $minScore)
           ->orderBy('l.leadScore', 'DESC')
           ->setMaxResults($limit);
        
        if ($tier !== null) {
            $qb->andWhere('d.competitorTier = :tier')
               ->setParameter('tier', $tier);
        }
        
        /** @var list<CompetitorDetection> $results */
        $results = $qb->getQuery()->getResult();
        
        // Group by competitor
        $grouped = [];
        $leads = [];
        foreach ($results as $detection) {
            $lead = $detection->getLead();
            if ($lead === null) {
                continue; // detection without a hydrated lead cannot be targeted
            }
            $leads[] = $lead;
            $competitor = $detection->getCompetitorName();
            $grouped[$competitor][] = $lead;
        }
        
        return [
            'leads' => $leads,
            'byCompetitor' => $grouped,
            'competitorStats' => $this->detectionRepository->getCompetitorStats(),
        ];
    }

    /**
     * Check if a lead has competitor detection
     */
    public function leadHasCompetitor(Lead $lead): bool
    {
        return $this->detectionRepository->leadHasCompetitor($lead);
    }

    /**
     * Get top competitor for a lead
     */
    public function getTopCompetitorForLead(Lead $lead): ?CompetitorDetection
    {
        return $this->detectionRepository->getTopCompetitorForLead($lead);
    }

    /**
     * Get competitor statistics
     *
     * @return list<array{name: mixed, domain: mixed, tier: mixed, count: int}>
     */
    public function getCompetitorStats(): array
    {
        return $this->detectionRepository->getCompetitorStats();
    }

    /**
     * Manually add a competitor detection
     */
    public function addManualDetection(
        Lead $lead,
        string $competitorDomain,
        string $competitorName,
        int $tier = 3
    ): CompetitorDetection {
        $detection = new CompetitorDetection();
        $detection->setLead($lead);
        $detection->setCompetitorDomain(strtolower($competitorDomain));
        $detection->setCompetitorName($competitorName);
        $detection->setCompetitorTier($tier);
        $detection->setDetectedIn('manual');
        $detection->setDetectionConfidence(100);
        
        $this->entityManager->persist($detection);
        $this->entityManager->flush();
        
        $this->logger->info('Added manual competitor detection', [
            'leadId' => $lead->getId(),
            'competitor' => $competitorName,
        ]);
        
        return $detection;
    }
}
