<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Classifier;

use App\Service\WebCrawler\Text\TextNormalizer;

/**
 * Competitor Proximity Veto (Improvement 2C)
 *
 * Detects when a Google result mentions a known EMS/contract-manufacturing
 * competitor name in conjunction with service-offering language, strongly
 * suggesting the candidate is itself an EMS provider (or a list/comparison
 * of EMS providers).
 *
 * Two modes:
 *  1. HARD VETO — the candidate domain matches a known competitor.
 *  2. PROXIMITY — the snippet/title mentions a competitor name near
 *     service-language, suggesting the candidate is in the same space.
 *     (e.g. "Jabil, Flex, Foxconn and other EMS providers in the region")
 *
 * False-positive mitigation: if the snippet also has strong buyer language
 * ("our products", "we design", "R&D"), the veto is suppressed — the
 * candidate might just be naming its supply chain.
 */
final class CompetitorProximityVeto
{
    /**
     * Known EMS / contract-manufacturing competitor brand names.
     * Lowercased, unique. Short names (≤3 chars) require word-boundary matching.
     */
    private const COMPETITOR_BRANDS = [
        // Tier-1 global EMS
        'foxconn', 'hon hai', 'pegatron', 'wistron', 'quanta',
        'compal', 'inventec', 'flex', 'flextronics', 'jabil',
        'celestica', 'sanmina', 'benchmark electronics', 'plexus',
        'venture corporation', 'ttm technologies',

        // Europe-focused EMS
        'lacroix electronics', 'lacroix', 'zollner', 'zollner elektronik',
        'ems-chemie', 'note ems', 'gpv group', 'gpv', 'neways',
        'cicor', 'katek', 'hanza', 'scanfil', 'incap', 'enics',
        'kitron', 'ihlemann', 'fideltronik', 'videoton',
        'asteelflash', 'eolane', 'tronico', 'all circuits',
        'selha group', 'cofidur', 'lacme',

        // MENA / Morocco-relevant
        'stmicroelectronics ems', 'prettl', 'sumitomo electric',
        'yazaki', 'aptiv', 'delphi', 'lear corporation',
        'leoni', 'dräxlmaier', 'draexlmaier',

        // Cable / wire harness competitors
        'te connectivity', 'molex', 'amphenol', 'hirose',
        'bizlink', 'nai group',

        // PCB / PCBA specialists
        'kimball electronics', 'usi', 'fabrinet', 'neotech',
        'smtc', 'creation technologies', 'firstronic',
        'colonial electronics', 'epec', 'epectec',
        'hunter technology', 'saline lectronics',

        // India / Asia ODM
        'vvdn', 'embitel', 'dixon technologies', 'amber enterprises',
    ];

    /**
     * Service-offering language that, combined with competitor mention,
     * strongly indicates the result is about EMS/services, not a buyer.
     */
    private const SERVICE_PROXIMITY_PATTERNS = [
        'ems\s+(provider|partner|competitor|company|industr|sector|market)',
        'contract\s+(manufactur|assembl|electronic)',
        'manufacturing\s+services?',
        'pcb\s+assembl',
        'electronics?\s+manufactur',
        'wire\s+harness\s+manufactur',
        'cable\s+(harness|assembl)\s+manufactur',
        'outsourc(e|ed|ing)\s+(manufactur|assembl|production)',
        'ems\s+(revenue|market|growth|ranking)',
        'top\s+\d+\s+ems',
        'leading\s+ems',
        'ems\s+companies',
        'compare\s+ems',
    ];

    /**
     * Buyer language that overrides the proximity signal —
     * indicates the candidate is merely naming its suppliers/partners.
     */
    private const BUYER_OVERRIDE_PATTERNS = [
        'we\s+design',
        'we\s+develop',
        'our\s+products?',
        'product\s+(line|range|portfolio)',
        'r&d\s+(center|lab|team|department)',
        'research\s+and\s+development',
        'we\s+outsource\s+to',
        'our\s+(ems|manufacturing)\s+partner',
        'looking\s+for\s+(an?\s+)?ems',
    ];

    private TextNormalizer $normalizer;

    public function __construct(?TextNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new TextNormalizer();
    }

    /**
     * @return array{vetoed: bool, reason: string, matchedCompetitor: ?string, matchedService: ?string, overridden: bool}
     */
    public function evaluate(
        string $companyName,
        string $snippet,
        string $title,
        string $domain,
    ): array {
        $text = strtolower($snippet . ' ' . $title . ' ' . $companyName);
        $domainLower = strtolower($domain);
        $nameLower = strtolower(trim($companyName));

        // ── Mode 1: Hard match — candidate name IS a competitor ──
        foreach (self::COMPETITOR_BRANDS as $brand) {
            // For short brands, require more context
            if (strlen($brand) <= 4) {
                if ($nameLower === $brand) {
                    return [
                        'vetoed'            => true,
                        'reason'            => "Company name matches known competitor: {$brand}",
                        'matchedCompetitor' => $brand,
                        'matchedService'    => null,
                        'overridden'        => false,
                    ];
                }
                continue;
            }
            // Check if the company name strongly overlaps with a competitor brand
            if (str_contains($nameLower, $brand) || str_contains($brand, $nameLower)) {
                return [
                    'vetoed'            => true,
                    'reason'            => "Company name matches known competitor: {$brand}",
                    'matchedCompetitor' => $brand,
                    'matchedService'    => null,
                    'overridden'        => false,
                ];
            }
        }

        // ── Mode 2: Proximity — snippet mentions competitor + service language ──
        $mentionedCompetitor = null;
        foreach (self::COMPETITOR_BRANDS as $brand) {
            // For very short names, use word boundary
            if (strlen($brand) <= 4) {
                if (preg_match('/\b' . preg_quote($brand, '/') . '\b/i', $text)) {
                    $mentionedCompetitor = $brand;
                    break;
                }
            } elseif (str_contains($text, $brand)) {
                $mentionedCompetitor = $brand;
                break;
            }
        }

        if ($mentionedCompetitor === null) {
            return [
                'vetoed'            => false,
                'reason'            => 'No competitor mentioned',
                'matchedCompetitor' => null,
                'matchedService'    => null,
                'overridden'        => false,
            ];
        }

        // Competitor mentioned — check for service-language proximity
        $matchedService = null;
        foreach (self::SERVICE_PROXIMITY_PATTERNS as $pattern) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $matchedService = $pattern;
                break;
            }
        }

        if ($matchedService === null) {
            // Competitor mentioned but no service language — not a veto.
            // The candidate might be an OEM customer mentioning their partner.
            return [
                'vetoed'            => false,
                'reason'            => "Competitor '{$mentionedCompetitor}' mentioned but no service language detected",
                'matchedCompetitor' => $mentionedCompetitor,
                'matchedService'    => null,
                'overridden'        => false,
            ];
        }

        // Both competitor + service language found — check for buyer override
        foreach (self::BUYER_OVERRIDE_PATTERNS as $buyerPattern) {
            if (preg_match('/' . $buyerPattern . '/i', $text)) {
                return [
                    'vetoed'            => false,
                    'reason'            => "Competitor '{$mentionedCompetitor}' + service language '{$matchedService}' found, but buyer override active",
                    'matchedCompetitor' => $mentionedCompetitor,
                    'matchedService'    => $matchedService,
                    'overridden'        => true,
                ];
            }
        }

        // VETO: competitor + service language without buyer override
        return [
            'vetoed'            => true,
            'reason'            => "Competitor '{$mentionedCompetitor}' mentioned alongside service language '{$matchedService}'",
            'matchedCompetitor' => $mentionedCompetitor,
            'matchedService'    => $matchedService,
            'overridden'        => false,
        ];
    }
}
