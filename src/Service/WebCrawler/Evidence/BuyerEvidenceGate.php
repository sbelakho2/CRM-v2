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
 *   5. SECTOR_ALIGNMENT — Snippet mentions target sector vocabulary (Automotive, Aerospace, etc.)
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
     * @param string|null $sector  Target sector for sector-relevance check
     *
     * @return BuyerEvidenceResult
     */
    public function evaluate(
        string $companyName,
        string $snippet,
        string $title,
        string $domain,
        string $homepageText = '',
        ?string $sector = null,
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
            // EN
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
            // DE
            'wir entwickeln'                => 15,
            'wir fertigen'                  => 15,
            'wir konstruieren'              => 15,
            'unsere produkte'               => 12,
            'produktpalette'                => 10,
            'produktportfolio'              => 12,
            'produktprogramm'               => 10,
            'produktfamilie'                => 10,
            'eigenentwicklung'              => 15,
            // FR
            'nous concevons'                => 15,
            'nous développons'              => 15,
            'nous developpons'              => 15,
            'nos produits'                  => 12,
            'gamme de produits'             => 10,
            'portefeuille de produits'      => 12,
            'catalogue produits'            => 10,
            'notre offre'                   => 8,
            // IT
            'progettiamo'                   => 15,
            'sviluppiamo'                   => 15,
            'i nostri prodotti'             => 12,
            'gamma di prodotti'             => 10,
            'portafoglio prodotti'          => 12,
            // ES
            'diseñamos'                     => 15,
            'desarrollamos'                 => 15,
            'nuestros productos'            => 12,
            'gama de productos'             => 10,
            'cartera de productos'          => 12,
            // NL
            'onze producten'                => 12,
            'productassortiment'            => 10,
            'productportfolio'              => 12,
            // PL / CZ
            'nasze produkty'                => 12,
            'naše produkty'                 => 12,
            'nase produkty'                 => 12,
            // Additional product signals for sparse snippets
            'our solutions'                 => 10,
            'our capabilities'              => 10,
            'our technology'                => 10,
            'we provide'                    => 8,
            'we supply'                     => 8,
            'we deliver'                    => 8,
            'custom solutions'              => 10,
            'our systems'                   => 10,
            'we specialize'                 => 10,
            'we specialise'                 => 10,
            // DE
            'unsere lösungen'               => 10,
            'unsere loesungen'              => 10,
            'wir liefern'                   => 8,
            // FR
            'nos solutions'                 => 10,
            'nous fournissons'              => 8,
        ];
        $productPatterns = [
            '/\b(inverter|converter|controller|sensor|actuator|module|radar|lidar|avionics|telematics|infotainment|instrument\s+cluster|battery\s+management|bms|ecu|power\s+supply|ups|generator|switchgear|transformer|motor\s+drive|vfd|plc|hmi|scada)\b/i' => 15,
            '/\b(our\s+range\s+of|our\s+line\s+of|we\s+offer\s+a\s+range|our\s+solutions?\s+include|our\s+system)\b/i' => 10,
            '/\b(designed\s+and\s+(manufactured|produced)|engineered\s+for|proprietary\s+(technology|design|system))\b/i' => 15,
            '/\b(leading\s+(provider|supplier|manufacturer)\s+of)\b/i' => 10,
            '/\b(products?\s+for\s+the\s+(automotive|aerospace|medical|industrial|defense|energy|marine))\b/i' => 10,
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
            // EN
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
            // DE
            'werk'                          => 5,
            'fertigungslinie'               => 12,
            'produktionsanlage'             => 12,
            'produktionsstandort'           => 12,
            'produktionskapazität'          => 10,
            'produktionskapazitaet'         => 10,
            'qualitätskontrolle'            => 8,
            'qualitaetskontrolle'           => 8,
            'forschung und entwicklung'     => 10,
            'innovationszentrum'            => 10,
            'eigene fertigung'              => 15,
            'hauseigene fertigung'          => 15,
            // FR
            'usine'                         => 10,
            'ligne de production'           => 12,
            'site de production'            => 12,
            'capacité de production'        => 10,
            'capacite de production'        => 10,
            'contrôle qualité'              => 8,
            'controle qualite'              => 8,
            'recherche et développement'    => 10,
            'recherche et developpement'    => 10,
            'centre d\'innovation'          => 10,
            // IT
            'stabilimento'                  => 10,
            'linea di produzione'           => 12,
            'impianto produttivo'           => 12,
            'controllo qualità'             => 8,
            'controllo qualita'             => 8,
            'ricerca e sviluppo'            => 10,
            // ES
            'fábrica'                       => 10,
            'fabrica'                       => 10,
            'línea de producción'           => 12,
            'linea de produccion'           => 12,
            'planta de producción'          => 12,
            'planta de produccion'          => 12,
            'control de calidad'            => 8,
            'investigación y desarrollo'    => 10,
            'investigacion y desarrollo'    => 10,
            // NL
            'productielijn'                 => 12,
            'productiefaciliteit'           => 12,
            'kwaliteitscontrole'            => 8,
            'onderzoek en ontwikkeling'     => 10,
            // PL
            'linia produkcyjna'             => 12,
            'zakład produkcyjny'            => 12,
            'zaklad produkcyjny'            => 12,
            'kontrola jakości'              => 8,
            'kontrola jakosci'              => 8,
            // CZ
            'výrobní linka'                 => 12,
            'vyrobni linka'                 => 12,
            'výrobní závod'                 => 12,
            'vyrobni zavod'                 => 12,
            'kontrola kvality'              => 8,
            // Additional manufacturing signals for sparse snippets
            'manufacturer'                  => 8,
            'we manufacture'                => 15,
            'we produce'                    => 12,
            'producer'                      => 5,
            'production'                    => 5,
            'manufactures'                  => 10,
            'manufactured by'               => 10,
            'custom manufactur'             => 12,
            // DE
            'hersteller'                    => 10,
            'wir produzieren'               => 12,
            'produziert'                    => 5,
            // FR
            'fabricant'                     => 10,
            'nous fabriquons'               => 12,
            // IT
            'produttore'                    => 10,
            'fabbricante'                   => 10,
            // ES
            'fabricante'                    => 10,
            // NL
            'fabrikant'                     => 10,
            // PL
            'producent'                     => 10,
            // CZ
            'výrobce'                       => 10,
            'vyrobce'                       => 10,
        ];
        $mfgPatterns = [
            '/\b(our\s+factory|our\s+plant|our\s+production|our\s+facility|in[\s-]house\s+manufactur)\b/i' => 15,
            '/\b(own\s+(factory|plant|production|facility|manufactur))\b/i' => 15,
            '/\b(iso\s+9001|iatf\s+16949|as9100|iso\s+13485|iso\s+14001|nadcap)\b/i' => 10,
            '/\b(manufactur(er|ing|es?)\s+of\b)/i' => 10,
            '/\b(global|world|international)\s+(manufactur|leader)\b/i' => 8,
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
            // EN
            'supply chain'                  => 10,
            'procurement'                   => 10,
            'outsourc'                      => 10,
            'vendor'                        => 5,
            'supplier to'                   => 10,
            'supply to'                     => 8,
            'deliver to'                    => 5,
            'oem partner'                   => 12,
            // DE
            'lieferkette'                   => 10,
            'beschaffung'                   => 10,
            'einkauf'                       => 10,
            'zulieferer'                    => 10,
            'lieferant'                     => 8,
            'auslagerung'                   => 10,
            // FR
            'chaîne d\'approvisionnement'   => 10,
            'chaine d\'approvisionnement'   => 10,
            'approvisionnement'             => 10,
            'achats'                        => 8,
            'fournisseur'                   => 8,
            'sous-traitance'                => 10,
            // IT
            'catena di fornitura'           => 10,
            'approvvigionamento'            => 10,
            'acquisti'                      => 8,
            'fornitore'                     => 8,
            // ES
            'cadena de suministro'          => 10,
            'adquisiciones'                 => 10,
            'compras'                       => 8,
            'proveedor'                     => 8,
            // NL
            'toeleveringsketen'             => 10,
            'inkoop'                        => 10,
            'leverancier'                   => 8,
            'uitbesteding'                  => 10,
            // PL
            'łańcuch dostaw'                => 10,
            'lancuch dostaw'                => 10,
            'zaopatrzenie'                  => 10,
            'dostawca'                      => 8,
            // CZ
            'dodavatelský řetězec'          => 10,
            'dodavatelsky retezec'          => 10,
            'zásobování'                    => 10,
            'zasobovani'                    => 10,
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
            // EN
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
            // DE
            'hauptsitz'                     => 8,
            'firmensitz'                    => 8,
            'gegründet'                     => 8,
            'gegruendet'                    => 8,
            'mitarbeiter'                   => 8,
            'standorte'                     => 5,
            'tochtergesellschaft'           => 8,
            'weltweit'                      => 5,
            // FR
            'siège social'                  => 8,
            'siege social'                  => 8,
            'fondée en'                     => 8,
            'fondee en'                     => 8,
            'collaborateurs'                => 8,
            'salariés'                      => 8,
            'salaries'                      => 5,
            'filiales'                      => 8,
            'présence mondiale'             => 8,
            'presence mondiale'             => 8,
            // IT
            'sede centrale'                 => 8,
            'fondata nel'                   => 8,
            'dipendenti'                    => 8,
            'filiali'                       => 8,
            'presenza globale'              => 8,
            // ES
            'sede central'                  => 8,
            'fundada en'                    => 8,
            'empleados'                     => 8,
            'filiales'                      => 8,
            'presencia global'              => 8,
            // NL
            'hoofdkantoor'                  => 8,
            'opgericht in'                  => 8,
            'medewerkers'                   => 8,
            'dochterondernemingen'          => 8,
            'wereldwijd'                    => 5,
            // PL
            'siedziba'                      => 8,
            'założona w'                    => 8,
            'zalozona w'                    => 8,
            'pracowników'                   => 8,
            'pracownikow'                   => 8,
            // CZ
            'sídlo'                         => 8,
            'sidlo'                         => 8,
            'založena v'                    => 8,
            'zalozena v'                    => 8,
            'zaměstnanců'                   => 8,
            'zamestnancu'                   => 8,
        ];
        $footprintPatterns = [
            '/\bsince\s+\d{4}\b/i' => 8,
            '/\b\d{1,3}[,.]?\d{3}\+?\s+employees\b/i' => 10,
            '/\b(ltd|llc|inc|corp|gmbh|sa|sas|bv|nv|ag|plc|co|pty|srl|spa|fze|fzc|group|holding)\b/i' => 5,
            '/\b(systems|electronics|electric|power|energy|tech|technologies|automation|robotics|aerospace|defense|defence|marine|medical|instruments|motors|drives|controls|optics|photonics)\b/i' => 5,
            '/\b(ce\s+mark|rohs\s+complian|reach\s+complian|ul\s+listed|etl\s+listed|csa\s+approved|atex)\b/i' => 10,
        ];
        // Domain-based footprint
        // NOTE: Removed commercial_tld (.com/.co/.net = 5pts) — too permissive.
        // A .com domain is NOT evidence of being an EMS buyer. Every news site,
        // standards body, and chemical company also has a .com domain.
        // Only country-code TLDs for target regions give a small signal.
        if (preg_match('/\.(ae|eg|ma|de|fr|nl|cz|pl|ro|us|it|gb|es|uk|tn|sa|qa|kw|bh|om|ch|at|be|dk|se|no|fi|hu|hr|si|sk|bg)$/i', $normalizedDomain)) {
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
            'GOVERNMENT'    => '/\b(government|authority|ministry|department\s+of|bureau\s+of|municipality|behörde|behoerde|ministerium|gemeinde|préfecture|prefecture|municipalité|municipalite|ministerio|gemeente|urząd|urzad|ministerstvo)\b/i',
            'ACADEMIC'      => '/\b(university|universit[éèeäa]|college|school\s+of|institute\s+of|research\s+cent[er]|academic|professor|doctoral|hochschule|fachhochschule|politecnico|universidad|uczelnia|univerzita|szkoła\s+wyższa|szkola\s+wyzsza)\b/i',
            'MEDIA'         => '/\b(newspaper|news\s+agency|media\s+company|publishing|journalist|magazine|podcast|broadcast|zeitung|zeitschrift|verlag|redaktion|quotidiano|giornale|periódico|periodico|gazeta|wydawnictwo|noviny|časopis|casopis)\b/i',
            'FINANCIAL'     => '/\b(bank|banking|insurance|fintech|financial\s+services|credit\s+union|stock\s+exchange|brokerage|versicherung|assurance|assicurazione|seguro|verzekering|ubezpieczenie|pojišťovna|pojistovna)\b/i',
            'HEALTHCARE'    => '/\b(hospital|clinic|medical\s+center|patient\s+care|nursing|physician|pharmacy|krankenhaus|klinik|hôpital|hopital|ospedale|ziekenhuis|szpital|nemocnice|apotheke|pharmacie|farmacia|apteka|lékárna|lekarna)\b/i',
            'CONSULTING'    => '/\b(consulting\s+firm|law\s+firm|legal\s+services|accounting\s+firm|audit\s+firm|management\s+consult|advisory\s+firm|beratungsunternehmen|anwaltskanzlei|cabinet\s+d.avocat|cabinet\s+de\s+conseil|studio\s+legale|advocatenkantoor|kancelaria\s+prawna|advokátní\s+kancelář|advokatni\s+kancelar)\b/i',
            'EVENT'         => '/\b(trade\s+show|exhibition|expo|conference|summit|forum|congress|symposium|convention|messe|salon|foire|fiera|feria|targi|veletrh)\b/i',
            'NGO'           => '/\b(humanitarian|refugee|development\s+aid|ngo|non[\s-]?governmental|unicef)\b/i',
            'REAL_ESTATE'   => '/\b(real\s+estate|property\s+develop|construction\s+company|general\s+contractor|building\s+contractor|immobilien|immobilier|immobiliare|inmobiliaria|vastgoed|nieruchomości|nieruchomosci|nemovitosti)\b/i',
            'FOOD_AGRI'     => '/\b(food\s+(and|&)\s+beverage|bottling|brewery|dairy|bakery|agriculture|farming|lebensmittel|bäckerei|baeckerei|boulangerie|panificio|panadería|panaderia|landbouw|rolnictwo|zemědělství|zemedelstvi)\b/i',
            'SOFTWARE'      => '/\b(software\s+(company|development|solutions?|house|firm)|ERP\s+(software|solutions?|vendor)|SaaS\s+(platform|provider))\b/i',
            'MARKET_REPORT' => '/\b(market\s+(report|research|insight|intelligence|forecast)|industry\s+report|CAGR|sample\s+pdf|buy\s+(this\s+)?report|marktbericht|marktforschung|étude\s+de\s+marché|etude\s+de\s+marche|ricerca\s+di\s+mercato|informe\s+de\s+mercado)\b/i',
            // ─── NEW anti-evidence families ────────────────────────────
            'AUTOMOTIVE_RETAIL' => '/\b(car\s+dealer|autohaus|concession(n)?aire\s+auto|concessionari[ao]|concesionario|autobazar|auto\s+parts\s+shop|autoteile|pièces\s+auto|pieces\s+auto|ricambi|car\s+rental|autovermietung|autonoleggio|fahrschule|auto[\s-]?école|auto[\s-]?ecole|autoescuela|tire\s+shop|reifenhandel|pneumatici|driving\s+school|autohaus|gebrauchtwagen|used\s+cars)\b/i',
            'TELECOM'       => '/\b(telecom\s+operator|telekommunikation|opérateur\s+télécom|operateur\s+telecom|operatore\s+telecomunicazion|mobile\s+network|mobilfunk|réseau\s+mobile|reseau\s+mobile|rete\s+mobile|internet\s+provider|fournisseur\s+d.accès|aanbieder)\b/i',
            'LOGISTICS'     => '/\b(freight\s+forward|spediteur|spedition|transitaire|spedizioniere|transportista|courier\s+service|kurierdienst|livraison|corriere|mensajería|mensajeria|bezorgdienst|kurierski)\b/i',
            'GAMBLING'      => '/\b(casino|poker|bet365|betting|gambling|spielhalle|spielothek|slot\s+machine|sportwetten|bookmaker|pari[\s-]?sportif|scommesse)\b/i',
            'STANDARDS_BODY' => '/\b(standards?\s+(body|organization|organisation|institute|authority|committee)|standardization|standardisation|normalization|normalisation|technick[\x{00e9}e]\s+normy|normes?\s+techniques?|DIN\s+standard|ANSI\s+standard|BSI\s+Group|ISO\s+(committee|standard|certification\s+body)|IEC\s+standard|CEN\b|CENELEC|norms?\s+(database|catalog|catalogue|search|portal)|technick[\x{00e9}e]\s+předpisy|certification\s+(body|institute|organisation|organization))\b/iu',
            'CHEMICAL_MATERIALS' => '/\b(chemical\s+(company|producer|supplier|group|division)|commodity\s+chemical|specialty\s+chemical|petrochemical|polymer\s+(producer|supplier|manufacturer)|resin\s+(producer|supplier)|styrene|polystyrene|polyethylene|polypropylene|polyurethane|styrolution|styrenics|plastics?\s+(supplier|producer|manufacturer|company)|raw\s+material\s+(supplier|producer)|basic\s+materials?|chemical\s+industry|bulk\s+chemical|chemical\s+distribution)\b/i',
            'NEWS_CONTENT' => '/\b(breaking\s+news|latest\s+news|top\s+stories|headlines|trending|opinion\s+column|exclusive\s+interview|showbiz|celebrity|tabloid|read\s+more\s+at|subscribe\s+to\s+(our|the)\s+newsletter|news\s+desk|news\s+feed|royal\s+family)\b/i',
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

        // ══════════════════════════════════════════════════════════════
        // SECTOR RELEVANCE CHECK — if a sector is specified, require
        // at least minimal vocabulary match for that sector. This
        // prevents generic companies (news sites, chemical companies,
        // standards bodies) from passing just because they have
        // "factory" + "employees" in their text.
        //
        // Also: matching sector vocabulary adds a positive SECTOR_ALIGNMENT
        // signal, helping companies with sparse snippets that clearly
        // mention the target sector.
        // ══════════════════════════════════════════════════════════════
        if ($sector !== null) {
            $sectorRelevancePatterns = [
                'Automotive' => '/\b(automotive|vehicle|car\s+manufactur|auto(mobile)?\s+(industry|sector|manufactur|OEM|supplier)|IATF\s+16949|powertrain|chassis|body\s+electronics|ADAS|ECU|engine\s+control|infotainment|dashboard|steering|braking|suspension|drivetrain|EV\s+(platform|battery|motor)|electric\s+vehicle|connected\s+car|autonomous\s+driv|tier[\s-]?[12]|Fahrzeug|Automobilzulieferer|Automobilindustrie|automobile|véhicule|industrie\s+automobile|costruttore\s+auto|fabricante\s+de\s+automóvil|motoryzacja)\b/iu',
                'Aerospace' => '/\b(aerospace|aviation|aircraft|airframe|avionics|aerostructure|space\s+(industry|sector)|satellite|rocket|propulsion|AS9100|DO-178|DO-254|Luftfahrt|aéronautique|aeronautica|aeroespacial)\b/iu',
                'Medical' => '/\b(medical\s+device|medtech|healthcare\s+equipment|surgical|diagnostic|implant|clinical|patient\s+monitor|ISO\s+13485|Medizintechnik|dispositif\s+médical|dispositivo\s+medico)\b/iu',
                'Defense' => '/\b(defense|defence|military|naval|army|tactical|ammunition|missile|radar\s+system|ITAR|mil[\s-]?spec|Rüstung|défense|difesa|defensa)\b/iu',
                'Energy' => '/\b(energy|renewable|solar|wind\s+turbine|power\s+generation|grid|smart\s+grid|energy\s+storage|battery\s+system|photovoltaic|Energie|énergie|energia)\b/iu',
                'Industrial' => '/\b(industrial\s+(automation|control|equipment|machinery)|factory\s+automation|process\s+control|PLC|SCADA|HMI|motion\s+control|Industrieautomation|automatisation\s+industrielle|automazione\s+industriale)\b/iu',
                'Telecom' => '/\b(telecom|5G|antenna|base\s+station|network\s+equipment|fiber\s+optic|optical\s+transport|Telekommunikation|télécommunication|telecomunicazioni)\b/iu',
                'Marine' => '/\b(marine|maritime|shipbuilding|naval\s+architect|offshore|vessel|ship\s+system|Schiffbau|maritime\s+industrie|costruzione\s+navale)\b/iu',
            ];

            $sectorNorm = ucfirst(strtolower(trim($sector)));
            if (isset($sectorRelevancePatterns[$sectorNorm])) {
                $hasSectorRelevance = (bool) preg_match($sectorRelevancePatterns[$sectorNorm], $text);
                if ($hasSectorRelevance) {
                    // Positive: sector vocabulary found → boost as 5th evidence family
                    $evidence[] = new EvidenceItem('SECTOR_ALIGNMENT', $sectorNorm . ' vocabulary', 10, 'sector_check');
                } else {
                    // Negative: completely missing sector vocabulary → anti-evidence
                    $antiEvidence[] = new EvidenceItem('NO_SECTOR_RELEVANCE', 'no ' . $sectorNorm . ' vocabulary', -20, 'sector_check');
                }
            }
        }

        return new BuyerEvidenceResult($evidence, $antiEvidence, $companyName, $domain);
    }
}
