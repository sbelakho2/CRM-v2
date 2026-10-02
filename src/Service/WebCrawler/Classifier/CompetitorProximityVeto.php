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

        // Europe-focused EMS — DACH
        'lacroix electronics', 'lacroix', 'zollner', 'zollner elektronik',
        'ems-chemie', 'note ems', 'gpv group', 'gpv', 'neways',
        'cicor', 'katek', 'hanza', 'scanfil', 'incap', 'enics',
        'kitron', 'ihlemann', 'fideltronik', 'videoton',
        'melecs', 'melecs ems', 'limtronik', 'tq group', 'tq-group',
        'cms electronics', 'heitec', 'produktiv',
        'ginzinger', 'sievert', 'bfz', 'bebro electronic',
        'turck duotec', 'heicks', 'kuttig', 'elringerwerk',

        // Europe — France / Benelux
        'asteelflash', 'eolane', 'tronico', 'all circuits',
        'selha group', 'cofidur', 'lacme',
        'éolane', 'presto engineering', 'canon bretagne',
        'scel', 'savoy international', 'actia',
        'thales ems', 'awl techniek', 'tbp electronics',
        'eurocircuits', 'niko electronics',

        // Europe — Nordics
        'aq group', 'note ab', 'orbit one', 'inission',
        'partnertech', 'interspiro', 'flex factory',

        // Europe — Poland / CEE
        'fideltronik', 'elhurt', 'printor', 'amtek',
        'awp group', 'poltronic', 'jabil wroclaw',
        'eltroplan', 'videoton ecs', 'elektrokem',

        // Europe — UK
        'jaltek', 'texcel', 'speedboard', 'pektron',
        'jaltek group', 'nemco', 'zot engineering',
        'rowse', 'simco electronics', 'trojan electronics',

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
        'eurocircuits', 'würth elektronik', 'wurth elektronik',
        'ilfa', 'multi-cb', 'leiterbahnen',

        // India / Asia ODM
        'vvdn', 'embitel', 'dixon technologies', 'amber enterprises',
        'kaynes', 'syrma sgs', 'centum electronics',
    ];

    /**
     * Service-offering language that, combined with competitor mention,
     * strongly indicates the result is about EMS/services, not a buyer.
     */
    private const SERVICE_PROXIMITY_PATTERNS = [
        // ── English ──
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

        // ── DE: Auftragsfertigung / EMS ──
        'EMS[\s-]?(Anbieter|Partner|Dienstleister|Unternehmen|Branche|Markt)',
        '(Auftrags|Lohn|Kontakt)fertigung',
        'Elektronik(fertigung|produktion|montage)',
        'Bestückung(s[\s-]?)?(Service|Dienstleist)',
        'Baugruppen(fertigung|montage)',
        'Kabelbaumfertigung',
        'SMD[\s-]?Bestückung',
        '(führend|größt)\w*\s+EMS',

        // ── FR: Sous-traitance électronique ──
        'sous[\s-]?trait(ant|ance)\s+(électronique|industriel)',
        'fabrication\s+électronique\s+(sous|pour)',
        'assemblage\s+électronique',
        'fabricant\s+(sous[\s-]?contrat|contractuel)',
        'câblage\s+(industriel|automobile)',
        '(principal|leader)\s+EMS',

        // ── IT: Terzista / produzione conto terzi ──
        'terzista\s+(elettronic|industriale)',
        '(produzione|assemblaggio)\s+conto\s+terzi',
        'servizi\s+di\s+(produzione|assemblaggio)',
        'produttore\s+per\s+conto\s+terzi',

        // ── ES: Fabricación por contrato ──
        'fabricaci[oó]n\s+(por\s+contrato|subcontrat)',
        'ensamblaje\s+(electrónico|por\s+contrato)',
        'fabricante\s+por\s+contrato',
        'servicios?\s+de\s+(fabricación|ensamblaje)',

        // ── NL: Contractfabrikant ──
        'contract\s+fabrikant',
        'elektronica\s+productie\s+(dienst|bedrijf|partner)',
        'loonproducent|loonproductie',

        // ── PL: Produkcja kontraktowa ──
        'produkcja\s+(kontraktowa|na\s+zlecenie)',
        'montaż\s+(elektroniki|podzespołów)',
        'usługi\s+(montażowe|produkcyjne|EMS)',

        // ── CZ: Smluvní výroba ──
        'smluvní\s+výroba|zakázková\s+výroba',
        'montáž\s+elektroniky|osazování\s+DPS',
        'služby\s+(montáže|výroby|EMS)',
    ];

    /**
     * Buyer language that overrides the proximity signal —
     * indicates the candidate is merely naming its suppliers/partners.
     */
    private const BUYER_OVERRIDE_PATTERNS = [
        // ── English ──
        'we\s+design',
        'we\s+develop',
        'our\s+products?',
        'product\s+(line|range|portfolio)',
        'r&d\s+(center|lab|team|department)',
        'research\s+and\s+development',
        'we\s+outsource\s+to',
        'our\s+(ems|manufacturing)\s+partner',
        'looking\s+for\s+(an?\s+)?ems',

        // ── DE: Buyer language ──
        'wir\s+(entwickeln|konstruieren|entwerfen)',
        'unsere\s+Produkte?',
        'Produkt(palette|reihe|portfolio)',
        'F&E[\s-]?(Zentrum|Labor|Abteilung|Team)',
        'Forschung\s+und\s+Entwicklung',
        'wir\s+lagern\s+.{0,20}(Fertigung|Produktion)\s+aus',
        'unser\s+(EMS|Fertigungs)[\s-]?Partner',
        'suchen\s+.{0,20}(EMS|Auftrags|Fertigungs)',

        // ── FR: Buyer language ──
        'nous\s+(concevons|développons)',
        'nos\s+produits?',
        'gamme\s+de\s+produits?',
        'centre\s+de\s+R&D',
        'recherche\s+et\s+développement',
        'nous\s+externalisons|nous\s+sous[\s-]?traitons',
        'notre\s+partenaire\s+(EMS|de\s+fabrication)',
        'à\s+la\s+recherche\s+d.un\s+(EMS|fabricant)',

        // ── IT: Buyer language ──
        'progettiamo|sviluppiamo',
        'i\s+nostri\s+prodotti',
        'gamma\s+di\s+prodotti',
        'centro\s+R&S',
        'ricerca\s+e\s+sviluppo',
        'nostro\s+partner\s+(EMS|di\s+produzione)',

        // ── ES: Buyer language ──
        'diseñamos|desarrollamos',
        'nuestros\s+productos',
        'gama\s+de\s+productos',
        'centro\s+de\s+I\+D',
        'investigación\s+y\s+desarrollo',
        'nuestro\s+socio\s+(EMS|de\s+fabricación)',

        // ── NL: Buyer language ──
        'wij\s+(ontwerpen|ontwikkelen)',
        'onze\s+producten',
        'productassortiment|productportfolio',
        'R&D[\s-]?(centrum|afdeling)',
        'onze\s+(EMS|productie)[\s-]?partner',

        // ── PL: Buyer language ──
        'projektujemy|rozwijamy',
        'nasze\s+produkty',
        'portfolio\s+produktów',
        'centrum\s+badawczo[\s-]?rozwojowe',
        'nasz\s+partner\s+(EMS|produkcyjny)',

        // ── CZ: Buyer language ──
        'navrhujeme|vyvíjíme',
        'naše\s+produkty',
        'portfolio\s+produktů',
        'výzkum\s+a\s+vývoj',
        'náš\s+(EMS|výrobní)\s+partner',
    ];

    /** Kept injectable/overridable for subclasses; reserved for future normalization hooks. */
    protected TextNormalizer $normalizer;

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
