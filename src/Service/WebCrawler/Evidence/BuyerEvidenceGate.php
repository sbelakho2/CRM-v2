<?php

namespace App\Service\WebCrawler\Evidence;

use App\Service\WebCrawler\Text\TextNormalizer;

/**
 * Buyer Evidence Gate (Improvement 2A)
 *
 * Hard requirement: a candidate must accumulate evidence from at least 2 of 4
 * evidence "families" before it can be promoted to a Lead.
 *
 * Evidence Families:
 *   1. PRODUCT_PORTFOLIO — Company designs/sells its own products or systems
 *   2. MANUFACTURING_OEM — Owns factory/production lines, does own manufacturing
 *   3. BUYER_PROCUREMENT — Has procurement/purchasing/supply-chain language
 *   4. ORG_FOOTPRINT    — Credible corporate footprint (HQ, employees, certs, global presence)
 *
 * Each piece of evidence is stored as an EvidenceItem with family, signal, weight,
 * and source (snippet, title, homepage, LinkedIn). The gate's decision + full trace
 * is persisted as JSON on the Lead entity's qualityStack field.
 *
 * Anti-evidence (negative signals from the old isLikelyEMSBuyer) is also tracked
 * and counted. A candidate with ≥2 anti-evidence families is hard-rejected
 * regardless of positive evidence.
 */
class BuyerEvidenceGate
{
    // Minimum number of distinct positive families required to pass
    public const MIN_FAMILIES = 2;

    // Maximum number of distinct anti-evidence families before hard-reject
    public const MAX_ANTI_FAMILIES = 1;

    private TextNormalizer $normalizer;

    public function __construct(?TextNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new TextNormalizer();
    }

    /**
     * Evaluate all evidence for a candidate and produce a verdict.
     *
     * @param string $companyName
     * @param string $snippet    Google search snippet
     * @param string $title      Google search title
     * @param string $domain     Root domain
     * @param string $homepageText Homepage body text (if fetched)
     *
     * @return BuyerEvidenceResult
     */
    public function evaluate(
        string $companyName,
        string $snippet,
        string $title,
        string $domain,
        string $homepageText = '',
    ): BuyerEvidenceResult {
        $evidence = [];
        $antiEvidence = [];

        // Normalize all text through TextNormalizer (Improvement 3A)
        $text = $this->normalizer->normalize($snippet . ' ' . $title . ' ' . $companyName . ' ' . $homepageText);
        $normalizedDomain = $this->normalizer->normalizeDomain($domain);

        // ══════════════════════════════════════════════════════════════
        // 1. PRODUCT_PORTFOLIO — designs/sells products or systems
        // ══════════════════════════════════════════════════════════════
        $productSignals = [
            'we design'                     => 15,
            'we develop'                    => 15,
            'we engineer'                   => 15,
            'our products'                  => 12,
            'product line'                  => 10,
            'product range'                 => 10,
            'product portfolio'             => 12,
            'product family'                => 10,
            'product catalog'               => 10,
            'product catalogue'             => 10,
        ];
        $productPatterns = [
            '/\b(inverter|converter|controller|sensor|actuator|module|radar|lidar|avionics|telematics|infotainment|instrument\s+cluster|battery\s+management|bms|ecu|power\s+supply|ups|generator|switchgear|transformer|motor\s+drive|vfd|plc|hmi|scada)\b/i' => 15,
            '/\b(our\s+range\s+of|our\s+line\s+of|we\s+offer\s+a\s+range|our\s+solutions?\s+include|our\s+system)\b/i' => 10,
            '/\b(designed\s+and\s+(manufactured|produced)|engineered\s+for|proprietary\s+(technology|design|system))\b/i' => 15,
        ];

        foreach ($productSignals as $signal => $weight) {
            if (str_contains($text, $signal)) {
                $evidence[] = new EvidenceItem('PRODUCT_PORTFOLIO', $signal, $weight, 'text_match');
            }
        }
        foreach ($productPatterns as $pattern => $weight) {
            if (preg_match($pattern, $text, $m)) {
                $evidence[] = new EvidenceItem('PRODUCT_PORTFOLIO', $m[0], $weight, 'regex_match');
            }
        }

        // ══════════════════════════════════════════════════════════════
        // 2. MANUFACTURING_OEM — owns production/factory
        // ══════════════════════════════════════════════════════════════
        $mfgSignals = [
            'factory'                       => 10,
            'production line'               => 12,
            'production facility'           => 12,
            'manufacturing plant'           => 12,
            'assembly plant'                => 12,
            'production capacity'           => 10,
            'quality control'               => 8,
            'lean manufactur'               => 10,
            'six sigma'                     => 10,
            'warehouse'                     => 5,
            'r&d'                           => 10,
            'research and development'      => 10,
            'innovation center'             => 10,
            'engineering team'              => 8,
        ];
        $mfgPatterns = [
            '/\b(our\s+factory|our\s+plant|our\s+production|our\s+facility|in[\s-]house\s+manufactur)\b/i' => 15,
            '/\b(own\s+(factory|plant|production|facility|manufactur))\b/i' => 15,
            '/\b(iso\s+9001|iatf\s+16949|as9100|iso\s+13485|iso\s+14001|nadcap)\b/i' => 10,
        ];

        foreach ($mfgSignals as $signal => $weight) {
            if (str_contains($text, $signal)) {
                $evidence[] = new EvidenceItem('MANUFACTURING_OEM', $signal, $weight, 'text_match');
            }
        }
        foreach ($mfgPatterns as $pattern => $weight) {
            if (preg_match($pattern, $text, $m)) {
                $evidence[] = new EvidenceItem('MANUFACTURING_OEM', $m[0], $weight, 'regex_match');
            }
        }

        // ══════════════════════════════════════════════════════════════
        // 3. BUYER_PROCUREMENT — supply chain / procurement language
        // ══════════════════════════════════════════════════════════════
        $procureSignals = [
            'supply chain'                  => 10,
            'procurement'                   => 10,
            'outsourc'                      => 10,
            'vendor'                        => 5,
            'supplier to'                   => 10,
            'supply to'                     => 8,
            'deliver to'                    => 5,
            'oem partner'                   => 12,
        ];
        $procurePatterns = [
            '/\b(tier[\s-]?[12]\s+(supplier|partner)|original\s+equipment\s+manufactur)\b/i' => 15,
            '/\b(supply\s+chain\s+manage|global\s+sourcing|strategic\s+sourcing|purchasing\s+department)\b/i' => 10,
            '/\b(bom|bill\s+of\s+material|rfq|request\s+for\s+quot)\b/i' => 12,
        ];

        foreach ($procureSignals as $signal => $weight) {
            if (str_contains($text, $signal)) {
                $evidence[] = new EvidenceItem('BUYER_PROCUREMENT', $signal, $weight, 'text_match');
            }
        }
        foreach ($procurePatterns as $pattern => $weight) {
            if (preg_match($pattern, $text, $m)) {
                $evidence[] = new EvidenceItem('BUYER_PROCUREMENT', $m[0], $weight, 'regex_match');
            }
        }

        // ══════════════════════════════════════════════════════════════
        // 4. ORG_FOOTPRINT — credible corporate presence
        // ══════════════════════════════════════════════════════════════
        $footprintSignals = [
            'headquarters'                  => 8,
            'founded'                       => 8,
            'established'                   => 8,
            'employees'                     => 8,
            'revenue'                       => 5,
            'annual revenue'                => 8,
            'global presence'               => 8,
            'worldwide'                     => 5,
            'subsidiaries'                  => 8,
            'locations in'                  => 5,
        ];
        $footprintPatterns = [
            '/\bsince\s+\d{4}\b/i' => 8,
            '/\b\d{1,3}[,.]?\d{3}\+?\s+employees\b/i' => 10,
            '/\b(ltd|llc|inc|corp|gmbh|sa|sas|bv|nv|ag|plc|co|pty|srl|spa|fze|fzc|group|holding)\b/i' => 5,
            '/\b(systems|electronics|electric|power|energy|tech|technologies|automation|robotics|aerospace|defense|defence|marine|medical|instruments|motors|drives|controls|optics|photonics)\b/i' => 5,
            '/\b(ce\s+mark|rohs\s+complian|reach\s+complian|ul\s+listed|etl\s+listed|csa\s+approved|atex)\b/i' => 10,
        ];
        // Domain-based footprint
        if (preg_match('/\.(com|co|net)$/i', $normalizedDomain) && !preg_match('/\.(wordpress|blogspot|wix|squarespace)\.com$/i', $normalizedDomain)) {
            $evidence[] = new EvidenceItem('ORG_FOOTPRINT', 'commercial_tld', 5, 'domain');
        }
        if (preg_match('/\.(ae|eg|ma|de|fr|nl|cz|pl|ro|us|it|gb|es|uk|tn|sa|qa|kw|bh|om)$/i', $normalizedDomain)) {
            $evidence[] = new EvidenceItem('ORG_FOOTPRINT', 'target_region_tld', 5, 'domain');
        }

        foreach ($footprintSignals as $signal => $weight) {
            if (str_contains($text, $signal)) {
                $evidence[] = new EvidenceItem('ORG_FOOTPRINT', $signal, $weight, 'text_match');
            }
        }
        foreach ($footprintPatterns as $pattern => $weight) {
            if (preg_match($pattern, $text, $m)) {
                $evidence[] = new EvidenceItem('ORG_FOOTPRINT', $m[0], $weight, 'regex_match');
            }
        }

        // ══════════════════════════════════════════════════════════════
        // ANTI-EVIDENCE — strong negative signals
        // ══════════════════════════════════════════════════════════════
        $antiPatterns = [
            'GOVERNMENT'    => '/\b(government|authority|ministry|department\s+of|bureau\s+of|municipality)\b/i',
            'ACADEMIC'      => '/\b(university|universit[éèeäa]|college|school\s+of|institute\s+of|research\s+cent[er]|academic|professor|doctoral)\b/i',
            'MEDIA'         => '/\b(newspaper|news\s+agency|media\s+company|publishing|journalist|magazine|podcast|broadcast)\b/i',
            'FINANCIAL'     => '/\b(bank|banking|insurance|fintech|financial\s+services|credit\s+union|stock\s+exchange|brokerage)\b/i',
            'HEALTHCARE'    => '/\b(hospital|clinic|medical\s+center|patient\s+care|nursing|physician|pharmacy)\b/i',
            'CONSULTING'    => '/\b(consulting\s+firm|law\s+firm|legal\s+services|accounting\s+firm|audit\s+firm|management\s+consult|advisory\s+firm)\b/i',
            'EVENT'         => '/\b(trade\s+show|exhibition|expo|conference|summit|forum|congress|symposium|convention)\b/i',
            'NGO'           => '/\b(humanitarian|refugee|development\s+aid|ngo|non[\s-]?governmental|unicef)\b/i',
            'REAL_ESTATE'   => '/\b(real\s+estate|property\s+develop|construction\s+company|general\s+contractor|building\s+contractor)\b/i',
            'FOOD_AGRI'     => '/\b(food\s+(and|&)\s+beverage|bottling|brewery|dairy|bakery|agriculture|farming)\b/i',
            'SOFTWARE'      => '/\b(software\s+(company|development|solutions?|house|firm)|ERP\s+(software|solutions?|vendor)|SaaS\s+(platform|provider))\b/i',
            'MARKET_REPORT' => '/\b(market\s+(report|research|insight|intelligence|forecast)|industry\s+report|CAGR|sample\s+pdf|buy\s+(this\s+)?report)\b/i',
        ];
        $antiDomain = [
            'GOVERNMENT' => '/\.(gov|mil|edu)(\.[a-z]{2,3})?$/i',
            'ACADEMIC'   => '/\.ac\.(uk|za|nz|jp|kr)$/i',
        ];

        foreach ($antiPatterns as $family => $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $antiEvidence[] = new EvidenceItem($family, $m[0], -30, 'anti_pattern');
            }
        }
        foreach ($antiDomain as $family => $pattern) {
            if (preg_match($pattern, $normalizedDomain)) {
                $antiEvidence[] = new EvidenceItem($family, $normalizedDomain, -50, 'anti_domain');
            }
        }

        return new BuyerEvidenceResult($evidence, $antiEvidence, $companyName, $domain);
    }
}
