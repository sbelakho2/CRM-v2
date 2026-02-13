<?php

namespace App\Service\CompCrawler;

use App\Entity\Competitor;
use App\Repository\CompetitorRepository;
use App\Service\WebCrawler\SearchProvider\SearchProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * CompDiscoveryService — Finds competitor candidate domains via search queries.
 *
 * Generates multilingual queries per competitor type and region,
 * deduplicates against existing competitors, and creates candidate entries.
 */
class CompDiscoveryService
{
    // Domains that are always seed-only (directories, news, associations)
    private const SEED_ONLY_DOMAIN_PATTERNS = [
        'linkedin.com',
        'facebook.com',
        'twitter.com',
        'youtube.com',
        'instagram.com',
        'tiktok.com',
        'threads.com',
        'wikipedia.org',
        'bloomberg.com',
        'reuters.com',
        'thomasnet.com',
        'globalspec.com',
        'europages.com',
        'kompass.com',
        'dnb.com',
        'made-in-china.com',
        'alibaba.com',
        'indiamart.com',
        'glassdoor.com',
        'indeed.com',
        'crunchbase.com',
    ];

    /**
     * Domains that should NEVER be persisted — these are noise, not potential competitors.
     * Marketplaces, social media, academic, government, news, ecommerce, etc.
     */
    private const JUNK_DOMAIN_PATTERNS = [
        // Social / UGC
        'reddit.com', 'tiktok.com', 'instagram.com', 'facebook.com', 'threads.com',
        'pinterest.com', 'tumblr.com', 'quora.com', 'medium.com',
        // Marketplaces / ecommerce
        'ebay.com', 'amazon.com', 'etsy.com', 'reverb.com', 'ubuy.com', 'ubuy.ma',
        'ubuy.com.om', 'ubuy.com.jo', 'aliexpress.com', 'walmart.com', 'shopify.com',
        // Document / media hosting
        'scribd.com', 'slideshare.net', 'yumpu.com', 'fliphtml5.com', 'issuu.com',
        'apps.apple.com', 'play.google.com',
        // Stock / image sites
        'stock.adobe.com', 'shutterstock.com', 'gettyimages.com', 'istockphoto.com',
        // Academic / research
        'pubmed.ncbi.nlm.nih.gov', 'hal.science', 'sciencedirect.com', 'researchgate.net',
        'scholar.google.com', 'pubs.acs.org', 'nature.com', 'springer.com',
        'liberty.edu', 'academia.edu', 'arxiv.org', 'ieee.org',
        'search.ebscohost.com', 'repository.unescap.org',
        // News / media / aggregators
        'bbc.com', 'cnn.com', 'theguardian.com', 'reuters.com', 'bloomberg.com',
        'africaintelligence.com', 'china-briefing.com', 'automotivelogistics.media',
        'cardealermagazine.co.uk', 'northafricapost.com', 'cosmeticsbusiness.com',
        // Government / military
        'af.mil', 'gov.uk', 'europa.eu', 'worldbank.org', 'documents.worldbank.org',
        // Job boards
        'bayt.com', 'people.bayt.com', 'trabajo.org', 'tanqeeb.com',
        'indeed.com', 'glassdoor.com', 'boards.briohr.com',
        // Generic directories / trade shows / conferences
        'pse-conferences.net', 'tornitura.show', 'worldexpoin.com',
        'hifishark.com', 'picclick.ca', 'picclick.com',
        // Non-manufacturing verticals
        'nespresso.com', 'cms.law', 'cms.int', 'iosea-turtles.cms.int',
        'sony.com', 'ti.com', 'hikvision.com', 'fanuc.eu', 'mazak.com',
        'down4soundshop.com', 'creative-cables.com', 'creative-cables.co.uk',
        // Cosmetics / beauty / health that share manufacturing keywords
        'silkoilofmorocco.com.au', 'dimebeautyco.com', '111skin.com',
        'macreneactives.com', 'sonna.com.au', 'wheelsanddollbaby.com',
        'lepaar.com', 'youstarcosmetics.com', 'shopgraftoncosmetics.com',
        // Misc noise
        'skill-lync.com', 'developmentaid.org', 'industriall-union.org',
        'iosrjournals.org', 'electronicsu.org', 'piggypats.com',
        'lospinoscp.com', 'przybelmie.pl', 'nagashima-kikaku.com',
        'eight-id.com', 'jlc3dp.com', 'j2inn.com',
        'trekmedics.org', 'horizoneducational.com',
    ];

    public function __construct(
        private readonly CompCrawlerConfig $config,
        private readonly SearchProviderInterface $searchProvider,
        private readonly CompetitorRepository $competitorRepo,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Run discovery for a specific competitor type and optional region.
     *
     * @return array{discovered: int, skipped: int, queries_run: int}
     */
    public function discover(string $competitorType = 'ems', ?string $region = null, int $maxQueries = 50): array
    {
        $stats = ['discovered' => 0, 'skipped' => 0, 'queries_run' => 0, 'junk_filtered' => 0];

        $queries = $this->buildQueries($competitorType, $region);
        $queries = array_slice($queries, 0, $maxQueries);

        // In-memory dedup: track domains seen in THIS run to prevent duplicates
        // within the same query batch (DB check misses unflushed entities)
        $seenDomains = [];

        foreach ($queries as $query) {
            $stats['queries_run']++;

            try {
                $results = $this->searchProvider->search(query: $query, maxResults: 10);

                foreach ($results->getResults() as $result) {
                    $domain = $this->extractDomain($result->getUrl());
                    if (!$domain) continue;

                    // In-memory dedup within this run
                    if (isset($seenDomains[$domain])) {
                        $stats['skipped']++;
                        continue;
                    }

                    // Skip junk domains (never persist)
                    if ($this->isJunkDomain($domain)) {
                        $stats['junk_filtered']++;
                        $seenDomains[$domain] = true;
                        continue;
                    }

                    // Skip if already known in DB
                    $existing = $this->competitorRepo->findByAnyDomain($domain);
                    if ($existing) {
                        $stats['skipped']++;
                        $seenDomains[$domain] = true;
                        continue;
                    }

                    $seenDomains[$domain] = true;

                    // Check if seed-only domain
                    $isSeedOnly = $this->isSeedOnlyDomain($domain);

                    // Create candidate
                    $competitor = new Competitor();
                    $competitor->setName($this->extractCompanyName($result->getTitle(), $domain));
                    $competitor->setCanonicalDomain($domain);
                    $competitor->setCompetitorTypes([$this->mapQueryTypeToCompetitorType($competitorType)]);
                    $competitor->setDiscoverySource(Competitor::SOURCE_SEARCH);
                    $competitor->setSeedOnly($isSeedOnly);
                    $competitor->setStatus($isSeedOnly ? Competitor::STATUS_SEED_ONLY : Competitor::STATUS_CANDIDATE);

                    if ($region) {
                        $competitor->setRegions([$region]);
                    }

                    $this->em->persist($competitor);
                    $stats['discovered']++;

                    $this->logger->info("CompDiscovery: Found candidate {$domain}", [
                        'type' => $competitorType,
                        'name' => $competitor->getName(),
                        'seedOnly' => $isSeedOnly,
                    ]);
                }

                $this->em->flush();

                // Politeness delay
                usleep($this->config->getRequestDelayMs() * 1000);

            } catch (\Throwable $e) {
                $this->logger->warning("CompDiscovery query failed: {$e->getMessage()}", [
                    'query' => $query,
                ]);
            }
        }

        return $stats;
    }

    /**
     * Add a manual seed competitor.
     */
    public function addSeed(string $name, string $domain, array $types, string $directness = 'direct', ?string $region = null): Competitor
    {
        $existing = $this->competitorRepo->findByAnyDomain($domain);
        if ($existing) {
            return $existing;
        }

        $competitor = new Competitor();
        $competitor->setName($name);
        $competitor->setCanonicalDomain($domain);
        $competitor->setCompetitorTypes($types);
        $competitor->setDirectness($directness);
        $competitor->setDiscoverySource(Competitor::SOURCE_SEED);
        $competitor->setStatus(Competitor::STATUS_CANDIDATE);

        if ($region) {
            $competitor->setRegions([$region]);
        }

        $this->em->persist($competitor);
        $this->em->flush();

        return $competitor;
    }

    /**
     * Import competitors from LeadCrawler rejected leads labeled as "competitor".
     */
    public function importFromLeadCrawlerRejects(array $domains): int
    {
        $imported = 0;
        foreach ($domains as $domain) {
            $domain = $this->extractDomain($domain) ?: $domain;
            $existing = $this->competitorRepo->findByAnyDomain($domain);
            if ($existing) continue;

            $competitor = new Competitor();
            $competitor->setName($domain);
            $competitor->setCanonicalDomain($domain);
            $competitor->setDiscoverySource(Competitor::SOURCE_LEADCRAWLER);
            $competitor->setStatus(Competitor::STATUS_CANDIDATE);

            $this->em->persist($competitor);
            $imported++;
        }
        $this->em->flush();
        return $imported;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Private helpers
    // ═══════════════════════════════════════════════════════════════════

    private function buildQueries(string $type, ?string $region): array
    {
        $families = $this->config->getQueryFamilies();
        $regionQueries = $this->config->getRegionQueries();
        $languages = $this->config->getLanguages();

        $queries = [];

        // Type-specific queries per language
        if (isset($families[$type])) {
            foreach ($families[$type] as $lang => $langQueries) {
                if (!in_array($lang, $languages, true)) continue;
                foreach ($langQueries as $q) {
                    if ($region) {
                        $regionLabel = $this->getRegionSearchLabel($region);
                        $queries[] = $q . ' ' . $regionLabel;
                    } else {
                        $queries[] = $q;
                    }
                }
            }
        }

        // Region-specific queries
        if ($region && isset($regionQueries[$region])) {
            foreach ($regionQueries[$region] as $q) {
                $queries[] = $q;
            }
        }

        return array_unique($queries);
    }

    private function getRegionSearchLabel(string $region): string
    {
        $map = [
            'morocco' => 'Morocco',
            'us_east' => 'USA East Coast',
            'us_west' => 'USA West Coast',
            'us_central' => 'USA Midwest',
            'us_south' => 'USA South',
            'eu_west' => 'France OR Belgium OR Netherlands',
            'eu_central' => 'Germany OR Austria OR Switzerland OR Poland',
            'eu_south' => 'Spain OR Italy OR Portugal',
            'eu_north' => 'Sweden OR Finland OR Denmark OR Norway',
            'uk' => 'United Kingdom',
            'middle_east' => 'UAE OR Saudi Arabia OR Qatar',
            'africa_north' => 'Tunisia OR Egypt OR Algeria',
            'eastern_europe' => 'Romania OR Czech Republic OR Hungary',
            'china' => 'China',
            'india' => 'India',
            'turkey' => 'Turkey',
            'mexico' => 'Mexico',
            'canada' => 'Canada',
        ];
        return $map[$region] ?? $region;
    }

    private function extractDomain(string $url): ?string
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? null;
        if (!$host) return null;

        // Remove www.
        $host = preg_replace('/^www\./', '', $host);
        return strtolower($host);
    }

    private function extractCompanyName(string $title, string $domain): string
    {
        // 1. Split title on common delimiters
        $parts = preg_split('/\s*[-|–—:]\s*/', $title, 3);
        $candidate = trim($parts[0] ?? '');

        // 2. Check if the title segment is actually a usable company name
        //    Reject if it looks like a page title / article headline / generic text
        $isJunkTitle = $this->isJunkTitle($candidate);

        if (!$isJunkTitle && mb_strlen($candidate) >= 3 && mb_strlen($candidate) <= 80) {
            // Looks like a real company name segment
            return mb_substr($candidate, 0, 255);
        }

        // 3. Try the second segment (e.g. "Products - Acme Corp" → "Acme Corp")
        $candidate2 = trim($parts[1] ?? '');
        if (!empty($candidate2) && !$this->isJunkTitle($candidate2)
            && mb_strlen($candidate2) >= 3 && mb_strlen($candidate2) <= 80) {
            return mb_substr($candidate2, 0, 255);
        }

        // 4. Derive from domain: strip TLD, capitalize
        return $this->nameFromDomain($domain);
    }

    /**
     * Detect if a title segment is a page title / headline rather than a company name.
     */
    private function isJunkTitle(string $text): bool
    {
        if (mb_strlen($text) < 3) return true;

        // Too long to be a company name — probably an article/page title
        if (mb_strlen($text) > 80) return true;

        // Starts with common page-title words
        $pagePatterns = [
            '/^(how to|what is|guide|about us|contact|home|products?|services?|news|blog|careers?)/i',
            '/^(certified|scan here|catalogue|catalog|download|buy|shop|order|get )/i',
            '/^(the |a |an )\w+ (of|for|in|at|to|with|from|by|on) /i',
            '/\b(Morocco|Maroc|jobs?|hiring|prix|review|tutorial|course|training)\b/i',
            '/^(POINT I\/O|PMP\d|Vol\. |Red\/Blue|Solar harvesting|Egyptian)/i',
        ];
        foreach ($pagePatterns as $pattern) {
            if (preg_match($pattern, $text)) return true;
        }

        // Looks like a generic noun phrase, not a brand (all lowercase words, no proper nouns)
        $words = explode(' ', $text);
        if (count($words) >= 4) {
            $lcCount = 0;
            foreach ($words as $w) {
                if ($w === mb_strtolower($w) && mb_strlen($w) > 2) $lcCount++;
            }
            // If most words are lowercase → probably a sentence, not a company name
            if ($lcCount >= count($words) * 0.7) return true;
        }

        // Overly generic single words
        $generics = [
            'about', 'about us', 'contact', 'contact us', 'home', 'homepage',
            'products', 'services', 'news', 'blog', 'careers', 'overview',
            'fine', 'add', 'popular', 'catalogue', 'catalog', 'overmolding',
            'kontakt', 'morocco', 'ma', 'user guide',
        ];
        if (in_array(mb_strtolower(trim($text)), $generics, true)) return true;

        return false;
    }

    /**
     * Derive a readable company name from a domain.
     * E.g. "leoni-morocco.com" → "Leoni Morocco"
     *       "variosystems.com" → "Variosystems"
     *       "gett-group.com"   → "Gett Group"
     */
    private function nameFromDomain(string $domain): string
    {
        // Strip common subdomains and TLDs
        $domain = preg_replace('/^(www|ma|fr|de|it|es|en|shop|store|blog)\./', '', $domain);
        $parts = explode('.', $domain);
        $base = $parts[0] ?? $domain;

        // Split on hyphens/underscores → Title Case
        $words = preg_split('/[-_]/', $base);
        $name = implode(' ', array_map('ucfirst', $words));

        return mb_substr($name, 0, 255);
    }

    /**
     * Check if a domain is junk and should never be persisted.
     */
    private function isJunkDomain(string $domain): bool
    {
        foreach (self::JUNK_DOMAIN_PATTERNS as $pattern) {
            if (str_contains($domain, $pattern)) {
                return true;
            }
        }

        // Also reject obvious subdomain noise (e.g. literature.rockwellautomation.com)
        $parts = explode('.', $domain);
        if (count($parts) > 2) {
            // Has a subdomain — check if the root is a known non-manufacturer
            $root = implode('.', array_slice($parts, -2));
            $nonManufacturerRoots = [
                'rockwellautomation.com', 'honeywell.com', 'siemens.com',
                'trumpf.com', 'apple.com', 'google.com', 'microsoft.com',
            ];
            if (in_array($root, $nonManufacturerRoots, true)) {
                return true;
            }
        }

        return false;
    }

    private function isSeedOnlyDomain(string $domain): bool
    {
        foreach (self::SEED_ONLY_DOMAIN_PATTERNS as $pattern) {
            if (str_contains($domain, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function mapQueryTypeToCompetitorType(string $queryType): string
    {
        return match ($queryType) {
            'ems' => Competitor::TYPE_EMS_CONTRACT_MANUFACTURER,
            'machining' => Competitor::TYPE_CNC_MACHINE_SHOP,
            'harness' => Competitor::TYPE_WIRE_HARNESS_ASSEMBLER,
            'supercapacitor' => Competitor::TYPE_SUPERCAP_CELL_MANUFACTURER,
            default => Competitor::TYPE_EMS_CONTRACT_MANUFACTURER,
        };
    }
}
