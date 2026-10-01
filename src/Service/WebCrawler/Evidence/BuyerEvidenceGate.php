<?php

namespace App\Service\WebCrawler\Evidence;

use App\Service\WebCrawler\Text\TextNormalizer;

/**
 * Buyer Evidence Gate (improved)
 *
 * Goals:
 *  - Better at finding REAL industrial/OEM buyers (not just generic companies)
 *  - Fewer false negatives from OEM sites that also have news/shop/distribution pages
 *  - More robust scoring on sparse snippets + stronger confidence on homepage-confirmed leads
 *
 * Families:
 *   - PRODUCT_PORTFOLIO
 *   - MANUFACTURING_OEM
 *   - BUYER_PROCUREMENT
 *   - ORG_FOOTPRINT
 *   - SECTOR_ALIGNMENT
 *   - BUYER_INTENT_COMPOSITE (derived signals / synergy)
 *
 * Anti-evidence:
 *   - Hard veto families (immediate fail in result layer)
 *   - Soft anti families (countable, suppressible with strong industrial profile)
 */
class BuyerEvidenceGate
{
    // Minimum number of distinct positive families required to pass
    // Keep permissive (snippet-only contexts), but result layer blocks generic-only passes.
    public const MIN_FAMILIES = 1;

    // Minimum positive score (after family caps) required to pass
    public const MIN_TOTAL_POSITIVE_SCORE = 8;

    // Maximum distinct anti families (result layer may dynamically allow +1 for strong industrial profiles)
    public const MAX_ANTI_FAMILIES = 1;

    // At least one of these "buyer intent" families should usually be present.
    public const CORE_FAMILIES = [
        'PRODUCT_PORTFOLIO',
        'MANUFACTURING_OEM',
        'BUYER_PROCUREMENT',
    ];

    // Caps prevent score inflation from many weak synonymous matches in one family.
    public const FAMILY_SCORE_CAPS = [
        'PRODUCT_PORTFOLIO'      => 38,
        'MANUFACTURING_OEM'      => 42,
        'BUYER_PROCUREMENT'      => 30,
        'ORG_FOOTPRINT'          => 24,
        'SECTOR_ALIGNMENT'       => 20,
        'BUYER_INTENT_COMPOSITE' => 18,
    ];

    // Single-hit hard veto anti-families.
    // Keep strict for clear junk categories, but avoid over-penalizing real OEMs.
    public const HARD_VETO_ANTI_FAMILIES = [
        'NEWS_CONTENT',
        'STANDARDS_BODY',
        'CHEMICAL_MATERIALS',
        'MARKET_REPORT',
        'GOVERNMENT',
        'ACADEMIC',
        'CERTIFICATION_TESTING',
        'EVENT',
        'CONSULTING',
        'AUTOMOTIVE_RETAIL',
        'INFRASTRUCTURE',
        'RECRUITMENT',
        'TRAVEL_TOURISM',
        'TRAINING',
        'GAMBLING',
        'NGO',
    ];

    // Soft anti-families: count against score, but may be suppressed for strong OEMs.
    public const SOFT_ANTI_FAMILIES = [
        'DISTRIBUTOR_RESELLER',
        'LOGISTICS',
        'RECYCLING',
        'E_COMMERCE',
        'MEDIA',
        'NO_SECTOR_RELEVANCE',
    ];

    private TextNormalizer $normalizer;

    public function __construct(?TextNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new TextNormalizer();
    }

    /**
     * Evaluate all evidence for a candidate and produce a verdict.
     *
     * @param string      $companyName
     * @param string      $snippet      Google search snippet
     * @param string      $title        Google search title
     * @param string      $domain       Root domain
     * @param string      $homepageText Homepage body text (if fetched)
     * @param string|null $sector       Target sector for sector relevance check
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
        /** @var EvidenceItem[] $evidence */
        $evidence = [];
        /** @var EvidenceItem[] $antiEvidence */
        $antiEvidence = [];

        $dedupePos = [];
        $dedupeAnti = [];

        $segments = $this->buildNormalizedSegments($companyName, $snippet, $title, $homepageText);
        $text = trim(implode(' ', $segments));
        $normalizedDomain = $this->normalizer->normalizeDomain($domain);

        // ══════════════════════════════════════════════════════════════
        // 1) PRODUCT_PORTFOLIO — designs/sells own products/systems
        // ══════════════════════════════════════════════════════════════
        $productSignals = [
            // EN (ownership / product intent)
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
            'our solutions'                 => 10,
            'our capabilities'              => 8,
            'our technology'                => 8,
            'custom solutions'              => 10,
            'our systems'                   => 10,
            'we specialize'                 => 10,
            'we specialise'                 => 10,
            'odm'                           => 10,
            'oem solutions'                 => 12,
            'engineered solutions'          => 12,

            // Electronics/OEM buyer relevance
            'embedded systems'              => 10,
            'electronics systems'           => 10,
            'control systems'               => 10,
            'power electronics'             => 12,
            'industrial electronics'        => 12,
            'wire harness'                  => 12,
            'cable assembly'                => 12,
            'pcba'                          => 12,
            'pcb assembly'                  => 12,
            'bms'                           => 12,
            'battery management system'     => 12,
            'custom electronics'            => 12,

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
            'unsere lösungen'               => 10,
            'unsere loesungen'              => 10,

            // FR
            'nous concevons'                => 15,
            'nous développons'              => 15,
            'nous developpons'              => 15,
            'nos produits'                  => 12,
            'gamme de produits'             => 10,
            'portefeuille de produits'      => 12,
            'catalogue produits'            => 10,
            'notre offre'                   => 8,
            'nos solutions'                 => 10,

            // IT
            'progettiamo'                   => 15,
            'sviluppiamo'                   => 15,
            'i nostri prodotti'             => 12,
            'gamma di prodotti'             => 10,
            'portafoglio prodotti'          => 12,

            // ES
            'diseñamos'                     => 15,
            'disenamos'                     => 15,
            'desarrollamos'                 => 15,
            'nuestros productos'            => 12,
            'gama de productos'             => 10,
            'cartera de productos'          => 12,

            // NL / PL / CZ
            'onze producten'                => 12,
            'productassortiment'            => 10,
            'productportfolio'              => 12,
            'nasze produkty'                => 12,
            'naše produkty'                 => 12,
            'nase produkty'                 => 12,

            // Arabic (basic)
            'منتجاتنا'                      => 12,
            'نقوم بتصميم'                   => 12,
            'نطور'                          => 10,
            'حلولنا'                        => 10,
        ];

        $productPatterns = [
            '/\b(inverter|converter|controller|sensor|actuator|module|radar|lidar|avionics|telematics|infotainment|instrument\s+cluster|battery\s+management|bms|ecu|power\s+supply|ups|generator|switchgear|transformer|motor\s+drive|vfd|plc|hmi|scada|pcb(?:a)?|pcba|wire\s+harness|cable\s+assembly|control\s+panel|charger|dc-dc|onboard\s+charger)\b/iu' => 15,
            '/\b(our\s+range\s+of|our\s+line\s+of|we\s+offer\s+a\s+range|our\s+solutions?\s+include|our\s+system[s]?)\b/i' => 10,
            '/\b(designed\s+and\s+(manufactured|produced)|engineered\s+for|proprietary\s+(technology|design|system))\b/i' => 15,
            '/\b(leading\s+(provider|supplier|manufacturer)\s+of)\b/i' => 10,
            '/\b(products?\s+for\s+the\s+(automotive|aerospace|medical|industrial|defense|defence|energy|marine|rail|telecom))\b/i' => 10,
            '/\b(oem|odm)\s+(manufacturer|partner|solutions?)\b/i' => 12,
        ];

        $this->addTextSignals($evidence, $dedupePos, 'PRODUCT_PORTFOLIO', $productSignals, $segments);
        $this->addRegexSignals($evidence, $dedupePos, 'PRODUCT_PORTFOLIO', $productPatterns, $segments);

        // ══════════════════════════════════════════════════════════════
        // 2) MANUFACTURING_OEM — owns production/factory/OEM capability
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
            'warehouse'                     => 4,
            'r&d'                           => 8,
            'research and development'      => 10,
            'innovation center'             => 10,
            'engineering team'              => 8,
            'manufacturer'                  => 8,
            'we manufacture'                => 15,
            'we produce'                    => 12,
            'manufactures'                  => 10,
            'manufactured by'               => 8,
            'custom manufactur'             => 12,
            'in-house production'           => 15,
            'in house production'           => 15,
            'in-house assembly'             => 15,
            'contract manufacturing'        => 12,
            'electronics manufacturing'     => 12,
            'ems'                           => 10,
            'cm'                            => 4,

            // Quality / industrial capability
            'iatf 16949'                    => 12,
            'as9100'                        => 12,
            'iso 9001'                      => 10,
            'iso 13485'                     => 10,
            'nadcap'                        => 12,
            'ppap'                          => 10,
            'apqp'                          => 10,
            'fmea'                          => 8,
            'spc'                           => 8,

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
            'hersteller'                    => 10,
            'wir produzieren'               => 12,

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
            'fabricant'                     => 10,
            'nous fabriquons'               => 12,
            'sous-traitance industrielle'   => 10,

            // IT
            'stabilimento'                  => 10,
            'linea di produzione'           => 12,
            'impianto produttivo'           => 12,
            'controllo qualità'             => 8,
            'controllo qualita'             => 8,
            'ricerca e sviluppo'            => 10,
            'produttore'                    => 10,
            'fabbricante'                   => 10,

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
            'fabricante'                    => 10,

            // NL / PL / CZ
            'productielijn'                 => 12,
            'productiefaciliteit'           => 12,
            'kwaliteitscontrole'            => 8,
            'onderzoek en ontwikkeling'     => 10,
            'fabrikant'                     => 10,
            'linia produkcyjna'             => 12,
            'zakład produkcyjny'            => 12,
            'zaklad produkcyjny'            => 12,
            'kontrola jakości'              => 8,
            'kontrola jakosci'              => 8,
            'producent'                     => 10,
            'výrobní linka'                 => 12,
            'vyrobni linka'                 => 12,
            'výrobní závod'                 => 12,
            'vyrobni zavod'                 => 12,
            'kontrola kvality'              => 8,
            'výrobce'                       => 10,
            'vyrobce'                       => 10,

            // Arabic (basic)
            'مصنع'                          => 10,
            'خط إنتاج'                      => 12,
            'خط انتاج'                      => 12,
            'القدرة الإنتاجية'              => 10,
            'القدرة الانتاجية'              => 10,
            'تصنيع'                         => 10,
            'ضبط الجودة'                    => 8,
            'مراقبة الجودة'                 => 8,
        ];

        $mfgPatterns = [
            '/\b(our\s+factory|our\s+plant|our\s+production|our\s+facility|in[\s-]house\s+manufactur(?:ing)?|in[\s-]house\s+assembly)\b/i' => 15,
            '/\b(own\s+(factory|plant|production|facility|manufactur(?:ing)?))\b/i' => 15,
            '/\b(iso\s+9001|iatf\s*16949|as9100|iso\s+13485|iso\s+14001|nadcap)\b/i' => 10,
            '/\b(manufactur(er|ing|es?)\s+of)\b/i' => 10,
            '/\b(contract\s+manufactur(?:ing|er)|electronics\s+manufactur(?:ing|er)|ems\s+provider)\b/i' => 12,
            '/\b(oem|odm)\s+(factory|manufacturer|production)\b/i' => 12,
            '/\b(cnc|stamping|injection\s+mold(?:ing)?|assembly|smt|tht|wire\s+harness)\s+(line|facility|plant|capability)\b/i' => 12,
        ];

        $this->addTextSignals($evidence, $dedupePos, 'MANUFACTURING_OEM', $mfgSignals, $segments);
        $this->addRegexSignals($evidence, $dedupePos, 'MANUFACTURING_OEM', $mfgPatterns, $segments);

        // ══════════════════════════════════════════════════════════════
        // 3) BUYER_PROCUREMENT — sourcing/procurement/vendor language
        // ══════════════════════════════════════════════════════════════
        $procureSignals = [
            // EN
            'supply chain'                  => 10,
            'procurement'                   => 10,
            'purchasing'                    => 10,
            'strategic sourcing'            => 12,
            'global sourcing'               => 12,
            'vendor'                        => 5,
            'supplier to'                   => 10,
            'supply to'                     => 8,
            'deliver to'                    => 5,
            'oem partner'                   => 12,
            'approved vendor'               => 10,
            'approved supplier'             => 10,
            'vendor qualification'          => 12,
            'supplier quality'              => 10,
            'commodity manager'             => 10,
            'purchasing manager'            => 10,
            'procurement manager'           => 10,
            'buyer'                         => 5,
            'rfq'                           => 12,
            'rfi'                           => 8,
            'rfp'                           => 8,
            'bom'                           => 10,
            'bill of materials'             => 12,
            'request for quotation'         => 12,
            'outsourc'                      => 10,
            'subcontract'                   => 10,
            'sub-contract'                  => 10,
            'contract manufacturer partner' => 12,

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
            'demande de devis'              => 12,

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
            'solicitud de cotización'       => 12,
            'solicitud de cotizacion'       => 12,

            // NL / PL / CZ
            'toeleveringsketen'             => 10,
            'inkoop'                        => 10,
            'leverancier'                   => 8,
            'uitbesteding'                  => 10,
            'łańcuch dostaw'                => 10,
            'lancuch dostaw'                => 10,
            'zaopatrzenie'                  => 10,
            'dostawca'                      => 8,
            'dodavatelský řetězec'          => 10,
            'dodavatelsky retezec'          => 10,
            'zásobování'                    => 10,
            'zasobovani'                    => 10,

            // Arabic (basic)
            'المشتريات'                     => 10,
            'سلسلة التوريد'                 => 10,
            'توريد'                         => 8,
            'مورد'                          => 8,
            'طلب عرض سعر'                   => 12,
            'عرض سعر'                       => 8,
        ];

        $procurePatterns = [
            '/\b(tier[\s-]?[12]\s+(supplier|partner)|original\s+equipment\s+manufactur(?:er|ing))\b/i' => 15,
            '/\b(supply\s+chain\s+manage(?:ment)?|global\s+sourcing|strategic\s+sourcing|purchasing\s+department|procurement\s+department)\b/i' => 10,
            '/\b(bom|bill\s+of\s+materials?|rfq|rfi|rfp|request\s+for\s+quot(?:e|ation))\b/i' => 12,
            '/\b(approved\s+(supplier|vendor)\s+list|asl|supplier\s+qualification|vendor\s+qualification)\b/i' => 12,
        ];

        $this->addTextSignals($evidence, $dedupePos, 'BUYER_PROCUREMENT', $procureSignals, $segments);
        $this->addRegexSignals($evidence, $dedupePos, 'BUYER_PROCUREMENT', $procurePatterns, $segments);

        // ══════════════════════════════════════════════════════════════
        // 4) ORG_FOOTPRINT — credible corporate/industrial presence
        // ══════════════════════════════════════════════════════════════
        $footprintSignals = [
            // EN
            'headquarters'                  => 8,
            'founded'                       => 8,
            'established'                   => 8,
            'employees'                     => 8,
            'annual revenue'                => 8,
            'revenue'                       => 5,
            'global presence'               => 8,
            'worldwide'                     => 5,
            'subsidiaries'                  => 8,
            'locations in'                  => 5,
            'certified'                     => 6,
            'quality management system'     => 8,
            'compliance'                    => 5,

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

            // IT / ES
            'sede centrale'                 => 8,
            'fondata nel'                   => 8,
            'dipendenti'                    => 8,
            'filiali'                       => 8,
            'presenza globale'              => 8,
            'sede central'                  => 8,
            'fundada en'                    => 8,
            'empleados'                     => 8,
            'presencia global'              => 8,

            // NL / PL / CZ
            'hoofdkantoor'                  => 8,
            'opgericht in'                  => 8,
            'medewerkers'                   => 8,
            'dochterondernemingen'          => 8,
            'wereldwijd'                    => 5,
            'siedziba'                      => 8,
            'założona w'                    => 8,
            'zalozona w'                    => 8,
            'pracowników'                   => 8,
            'pracownikow'                   => 8,
            'sídlo'                         => 8,
            'sidlo'                         => 8,
            'založena v'                    => 8,
            'zalozena v'                    => 8,
            'zaměstnanců'                   => 8,
            'zamestnancu'                   => 8,

            // Arabic (basic)
            'المقر الرئيسي'                 => 8,
            'تأسست'                         => 8,
            'موظف'                          => 6,
            'موظفين'                        => 6,
            'فروع'                          => 6,
            'شهادات'                        => 6,
        ];

        $footprintPatterns = [
            '/\bsince\s+\d{4}\b/i' => 8,
            '/\b(est\.?\s*\d{4}|founded\s+in\s+\d{4}|established\s+in\s+\d{4})\b/i' => 8,
            '/\b\d{1,3}[,.]?\d{3}\+?\s+employees\b/i' => 10,
            '/\b(ltd|llc|inc|corp|gmbh|sa|sas|bv|nv|ag|plc|co|pty|srl|spa|fze|fzc|group|holding)\b/i' => 5,
            '/\b(systems|electronics|electric|power|energy|tech|technologies|automation|robotics|aerospace|defense|defence|marine|medical|instruments|motors|drives|controls|optics|photonics)\b/i' => 5,
            '/\b(ce\s+mark|rohs\s+complian|reach\s+complian|ul\s+listed|etl\s+listed|csa\s+approved|atex|iso\s+9001|iatf\s*16949|as9100|iso\s+13485)\b/i' => 10,
            '/\b(global\s+locations|manufacturing\s+sites?|production\s+sites?)\b/i' => 8,
        ];

        // Domain-based footprint (light but useful)
        if (preg_match('/\.(ae|eg|ma|de|fr|nl|cz|pl|ro|us|it|gb|es|uk|tn|sa|qa|kw|bh|om|ch|at|be|dk|se|no|fi|hu|hr|si|sk|bg)$/i', $normalizedDomain)) {
            $this->pushEvidence(
                $evidence,
                $dedupePos,
                'ORG_FOOTPRINT',
                'target_region_tld',
                5,
                'domain:domain_match'
            );
        }

        // Industrial lexical hints in domain/company name
        if (preg_match('/\b(electronic|electronics|automation|controls?|systems?|power|energy|industrial|aero|avionic|defense|defence|robotics|motion|drives|battery|harness|cable|smt|ems)\b/i', $text)) {
            $this->pushEvidence(
                $evidence,
                $dedupePos,
                'ORG_FOOTPRINT',
                'industrial_lexicon',
                6,
                'composite:lexicon'
            );
        }

        $this->addTextSignals($evidence, $dedupePos, 'ORG_FOOTPRINT', $footprintSignals, $segments);
        $this->addRegexSignals($evidence, $dedupePos, 'ORG_FOOTPRINT', $footprintPatterns, $segments);

        // ══════════════════════════════════════════════════════════════
        // ANTI-EVIDENCE — strong negative signals (with smarter segment policies)
        // ══════════════════════════════════════════════════════════════
        $antiPatterns = [
            'GOVERNMENT'    => '/\b(government|authority|ministry|department\s+of|bureau\s+of|municipality|behörde|behoerde|ministerium|gemeinde|préfecture|prefecture|municipalité|municipalite|ministerio|gemeente|urząd|urzad|ministerstvo)\b/iu',
            'ACADEMIC'      => '/\b(university|universit[éèeäa]|college|school\s+of|institute\s+of|research\s+cent(?:er|re)|academic|professor|doctoral|hochschule|fachhochschule|politecnico|universidad|uczelnia|univerzita|szkoła\s+wyższa|szkola\s+wyzsza)\b/iu',
            'MEDIA'         => '/\b(newspaper|news\s+agency|media\s+company|publishing|journalist|magazine|podcast|broadcast|zeitung|zeitschrift|verlag|redaktion|quotidiano|giornale|periódico|periodico|gazeta|wydawnictwo|noviny|časopis|casopis|press\s+(agency|office|review|group)|newsroom|correspondent|reporter|editorial|tabloid|media\s+group|media\s+house|news\s+portal|news\s+site|online\s+news|digital\s+news|noticias|actualit[ée]s|nachrichten|dagblad|krant|tageszeitung|wochenzeitung)\b/iu',
            'FINANCIAL'     => '/\b(bank|banking|insurance|fintech|financial\s+services|credit\s+union|stock\s+exchange|brokerage|versicherung|assurance|assicurazione|seguro|verzekering|ubezpieczenie|pojišťovna|pojistovna)\b/iu',
            'HEALTHCARE'    => '/\b(hospital|clinic|medical\s+center|patient\s+care|nursing|physician|pharmacy|krankenhaus|klinik|hôpital|hopital|ospedale|ziekenhuis|szpital|nemocnice|apotheke|pharmacie|farmacia|apteka|lékárna|lekarna)\b/iu',
            'CONSULTING'    => '/\b(consulting\s+firm|law\s+firm|legal\s+services|legal\s+consult(?:ing|ancy)|accounting\s+firm|audit\s+firm|management\s+consult|advisory\s+firm|attorneys?\s+at\s+law|law\s+office|law\s+offices|lawyers?\b|solicitors?\b|beratungsunternehmen|anwaltskanzlei|cabinet\s+d.avocat|cabinet\s+de\s+conseil|studio\s+legale|advocatenkantoor|kancelaria\s+prawna|advokátní\s+kancelář|advokatni\s+kancelar|avocats?)\b/iu',
            'EVENT'         => '/\b(trade\s+show|exhibition|expo\b|conference|summit|forum|congress|symposium|convention|register\s+to\s+visit|book\s+a\s+stand|speaker\s+lineup|speakers?\b|exhibitors?\b|media\s+registration|visitor\s+registration|world\s+automotive\s+manufacturing|messe|salon|foire|fiera|feria|targi|veletrh)\b/iu',
            'NGO'           => '/\b(humanitarian|refugee|development\s+aid|ngo|non[\s-]?governmental|unicef)\b/i',
            'REAL_ESTATE'   => '/\b(real\s+estate|property\s+develop|construction\s+company|general\s+contractor|building\s+contractor|immobilien|immobilier|immobiliare|inmobiliaria|vastgoed|nieruchomości|nieruchomosci|nemovitosti)\b/iu',
            'FOOD_AGRI'     => '/\b(food\s+(and|&)\s+beverage|bottling|brewery|dairy|bakery|agriculture|farming|lebensmittel|bäckerei|baeckerei|boulangerie|panificio|panadería|panaderia|landbouw|rolnictwo|zemědělství|zemedelstvi)\b/iu',
            'SOFTWARE'      => '/\b(software\s+(company|development|solutions?|house|firm)|ERP\s+(software|solutions?|vendor)|SaaS\s+(platform|provider))\b/i',
            'MARKET_REPORT' => '/\b(market\s+(report|research|insight|intelligence|forecast)|industry\s+report|CAGR|sample\s+pdf|buy\s+(this\s+)?report|marktbericht|marktforschung|étude\s+de\s+marché|etude\s+de\s+marche|ricerca\s+di\s+mercato|informe\s+de\s+mercado)\b/iu',

            // Automotive retail / spare-parts shops (not B2B OEM buyers)
            'AUTOMOTIVE_RETAIL' => '/\b(car\s+dealer(?:ship)?|auto(?:mobile)?\s+dealer(?:ship)?|vehicle\s+(import|trading|distribution)|authorized\s+(dealer|distributor|importer)|showroom|book\s+now|book\s+an\s+appointment|aftersales?|after\s+sales|service\s+cent(?:er|re)s?|car\s+accessories|autohaus|concession(?:n)?aire\s+auto|concessionari[ao]|concesionario|autobazar|auto\s+parts\s+shop|autoteile|pièces\s+auto|pieces\s+auto|ricambi|auto(?:motive)?\s+spare\s+parts?|car\s+spare\s+parts?|car\s+rental|autovermietung|autonoleggio|fahrschule|auto[\s-]?école|auto[\s-]?ecole|autoescuela|tire\s+shop|reifenhandel|pneumatici|driving\s+school|gebrauchtwagen|used\s+cars)\b/iu',

            // Pure distributor/reseller patterns (soft anti)
            'DISTRIBUTOR_RESELLER' => '/\b(authorized\s+distributor|official\s+distributor|regional\s+distributor|sole\s+distributor|exclusive\s+distributor|we\s+are\s+(a\s+)?(distributor|reseller|wholesaler)|authorized\s+dealer|official\s+dealer|value[\s-]?added\s+reseller|channel\s+partner|we\s+distribute\s+products?\s+(from|of)|we\s+supply\s+products?\s+(from|of)|we\s+stock\s+products?\s+(from|of)|trading\s+company|general\s+trading|parts\s+catalog|aftermarket\s+parts?\s+(supplier|dealer|wholesale)|genuine\s+parts?\s+(supplier|dealer)|replacement\s+parts?\s+(supplier|dealer|wholesale)|spare\s+parts?\s+(supplier|distributor|dealer|wholesale|trading)|importer\s+of\s+(spare\s+)?parts?|وكيل|موزع\s+معتمد|تاجر\s+جملة|قطع\s+غيار\s+(تاجر|موزع)|معرض\s+سيارات)\b/iu',

            'TELECOM'       => '/\b(telecom\s+operator|telekommunikation|opérateur\s+télécom|operateur\s+telecom|operatore\s+telecomunicazion|mobile\s+network|mobilfunk|réseau\s+mobile|reseau\s+mobile|rete\s+mobile|internet\s+provider|fournisseur\s+d.accès|aanbieder)\b/iu',
            'LOGISTICS'     => '/\b(freight\s+forward(?:er)?|spediteur|spedition|transitaire|spedizioniere|transportista|courier\s+service|kurierdienst|corriere|mensajería|mensajeria|bezorgdienst|kurierski|logistics\s+services?)\b/iu',
            'GAMBLING'      => '/\b(casino|poker|bet365|betting|gambling|spielhalle|spielothek|slot\s+machine|sportwetten|bookmaker|pari[\s-]?sportif|scommesse)\b/iu',
            'STANDARDS_BODY' => '/\b(standards?\s+(body|organization|organisation|institute|authority|committee)|standardization|standardisation|normalization|normalisation|normes?\s+techniques?|DIN\s+standard|ANSI\s+standard|BSI\s+Group|ISO\s+(committee|standard|certification\s+body)|IEC\s+standard|CEN\b|CENELEC|norms?\s+(database|catalog|catalogue|search|portal)|certification\s+(body|institute|organisation|organization))\b/iu',
            'CHEMICAL_MATERIALS' => '/\b(chemical\s+(company|producer|supplier|group|division)|commodity\s+chemical|specialty\s+chemical|petrochemical|polymer\s+(producer|supplier|manufacturer)|resin\s+(producer|supplier)|styrene|polystyrene|polyethylene|polypropylene|polyurethane|styrolution|styrenics|plastics?\s+(supplier|producer|manufacturer|company)|raw\s+material\s+(supplier|producer)|basic\s+materials?|chemical\s+industry|bulk\s+chemical|chemical\s+distribution)\b/iu',
            'NEWS_CONTENT' => '/\b(breaking\s+news|latest\s+news|top\s+stories|headlines|trending|opinion\s+column|exclusive\s+interview|showbiz|celebrity|tabloid|subscribe\s+to\s+(our|the)\s+newsletter|news\s+desk|news\s+feed|royal\s+family|wire\s+service|syndicated|news\s+wire|dateline|byline|special\s+report|live\s+coverage)\b/iu',
            'INFRASTRUCTURE' => '/\b(autoroutes?|highway\s+(authority|operator|agency|administration)|toll\s+(road|plaza|booth|operator|gate)|péage|peage|grille\s+tarifaire|trafic\s+en\s+temps\s+réel|trafic\s+en\s+temps\s+reel|motorway\s+(authority|operator)|road\s+(authority|agency|administration)|autobahn(?:amt)?|straßenbau|strassenbau|infrastructure\s+(routière|routiere|authority|operator)|aires?\s+de\s+(repos|service))\b/iu',

            // E-commerce (soft anti, because some OEMs also sell direct)
            'E_COMMERCE' => '/\b(add\s+to\s+cart|ajouter\s+au\s+panier|mon\s+panier|shopping\s+cart|shop\s+now|buy\s+online|livraison\s+gratuite|free\s+shipping|powered\s+by\s+shopify|woocommerce|magento|e[\s-]?commerce|our\s+best\s+sellers)\b/iu',

            'RECYCLING' => '/\b(recycl(?:ing|ed|er)|rPET|PET\s+recycl|waste\s+management|waste\s+processing|bottles?\s+recycled|post[\s-]?consumer|plastic\s+recycl|abfallwirtschaft|recyclage|riciclaggio|reciclaje|afvalbeheer|waste\s+to\s+energy|circular\s+economy|déchets|dechets)\b/iu',
            'RECRUITMENT' => '/\b(recruitment\s+(agency|firm|company|services?|consultant)|staffing\s+(agency|company|firm)|headhunt(?:er|ing)|job\s+(board|portal|listing|vacancies|openings)|career\s+(portal|site|opportunit)|we\s+are\s+hiring|apply\s+now|submit\s+your\s+(cv|resume|candidature)|offre[s]?\s+d.emploi|cabinet\s+de\s+recrutement|agence\s+d.intérim|agence\s+d.interim|agence\s+de\s+recrutement|Zeitarbeit|Personalvermittlung|Personalberatung|Stellenangebot|Stellenbörse|Stellenboerse|bolsa\s+de\s+empleo|agenzia\s+interinale|uitzendbureau|temporary\s+staffing|manpower|randstad|adecco)\b/iu',
            'TRAVEL_TOURISM' => '/\b(travel\s+(agency|agent|package|booking|operator)|tour\s+(operator|package|guide)|tourism\s+(company|board|office|authority)|hotel[s]?\b|hostel[s]?\b|resort[s]?\b|book\s+(a\s+)?room|check[\s-]?in\s+date|check[\s-]?out\s+date|room\s+(rate|type|availability)|reservation|agence\s+de\s+voyage|voyages?\s+organis[ée]s|hôtel|Reisebüro|Reisebuero|Reiseveranstalter|agenzia\s+di\s+viaggio|agencia\s+de\s+viajes|reisbureau|biuro\s+podróży|biuro\s+podrozy)\b/iu',
            'TRAINING' => '/\b(training\s+(center|centre|institute|provider|academy|company|services?)|formation\s+(professionnelle|continue|en\s+entreprise)|centre\s+de\s+formation|organisme\s+de\s+formation|Weiterbildung|Schulungszentrum|Fortbildung|Bildungszentrum|centro\s+de\s+formaci[oó]n|centro\s+formazione|opleidingscentrum|coaching\s+(services?|company|firm|academy)|e[\s-]?learning\s+(platform|provider|company)|online\s+course|cours\s+en\s+ligne|driving\s+school|auto[\s-]?école|auto[\s-]?ecole|Fahrschule|autoescuela|autoscuola|rijschool)\b/iu',

            // TIC / testing / certification providers (hard)
            'CERTIFICATION_TESTING' => '/\b(certification\s+(services?|company|authority|provider|scheme|program)|we\s+(provide\s+)?certif(?:y|ication)|we\s+offer\s+certification|our\s+certification\s+services|certif(?:y|ying|ication)\s+your\s+(products?|company|business)|certified?\s+auditor|accreditation\s+(body|services?|authority|scheme)|accredited\s+(body|laboratory|lab)|testing\s+(and|&)\s+(certification|inspection)|inspection\s+(and|&)\s+(certification|testing)|inspection\s+(services?|body|company|authority|provider)|TIC\s+(industry|services?|sector|company)|third[\s-]?party\s+(audit|inspection|testing|certification|assessment)\s+(services?|company|provider)|conformity\s+assessment\s+(body|services?)|notified\s+body|type[\s-]?approval\s+(services?|body)|homologation\s+(services?|body)|product\s+certification\s+(body|services?|company)|management\s+system\s+certification\s+(body|services?)|certification\s+mark|kitemark|CE[\s-]?marking\s+(services?|body|notified)|Zertifizierung(?:sstelle|sdienst|sdienstleister)|Pr[üu]f[\s-]?(?:stelle|labor|institut|dienst|ung)\b(?!\s+für\s+(unser|ihr))|organisme\s+de\s+certification|organismo\s+di\s+certificazione|organismo\s+de\s+certificaci[oó]n|Ente\s+di\s+certificazione|certificeringsinstantie|jednostka\s+certyfikuj[aą]ca)\b/iu',
        ];

        $antiDomain = [
            'GOVERNMENT' => '/\.(gov|mil|edu)(\.[a-z]{2,3})?$/i',
            'ACADEMIC'   => '/\.ac\.(uk|za|nz|jp|kr)$/i',
            'CERTIFICATION_TESTING' => '/\b(tuv|t[üu]v|tuev|dekra|sgs|intertek|bureauveritas|lrqa|dnv|eurofins|applus|nqa|proficert)\b/iu',
            'MEDIA'      => '/(news|times|tribune|herald|gazette|chronicle|dispatch|observer|telegraph|daily|journal|digest|magazine|monitor|post|media)(\.|\b)/i',
            'E_COMMERCE' => '/\b(shopify|myshopify)\b/i',
            'RECRUITMENT' => '/\b(indeed|glassdoor|monster|rekrute|bayt|tanqeeb|wuzzuf|jobrapido|stepstone|jobberman)\b/i',
            'TRAVEL_TOURISM' => '/\b(booking|trivago|hotels|tripadvisor|expedia|agoda|hostelworld|airbnb|kayak)\b/i',
        ];

        // Pattern-based anti (with family-specific segment policies)
        foreach ($antiPatterns as $family => $pattern) {
            $antiSegments = $this->antiSegmentsForFamily($family, $segments);

            // Pick best (strongest) match across allowed segments, not multiple duplicates
            $best = null;
            foreach ($antiSegments as $segmentName => $segmentText) {
                if ($segmentText === '') {
                    continue;
                }

                if (preg_match($pattern, $segmentText, $m)) {
                    $base = $this->antiBaseWeightForFamily($family);
                    $weight = -$this->scaleWeightBySource($base, $segmentName, true);

                    if ($best === null || abs($weight) > abs($best['weight'])) {
                        $best = [
                            'signal' => (string) $m[0],
                            'weight' => $weight,
                            'source' => $segmentName . ':anti_pattern',
                        ];
                    }
                }
            }

            if ($best !== null) {
                $this->pushAntiEvidence(
                    $antiEvidence,
                    $dedupeAnti,
                    $family,
                    $best['signal'],
                    $best['weight'],
                    $best['source']
                );
            }
        }

        // Domain anti-evidence
        foreach ($antiDomain as $family => $pattern) {
            if (preg_match($pattern, $normalizedDomain)) {
                $this->pushAntiEvidence(
                    $antiEvidence,
                    $dedupeAnti,
                    $family,
                    $normalizedDomain,
                    -50,
                    'domain:anti_domain'
                );
            }
        }

        // ══════════════════════════════════════════════════════════════
        // SECTOR RELEVANCE CHECK — adds SECTOR_ALIGNMENT evidence.
        // Missing sector relevance is a soft anti (not hard rejection),
        // and only applied when we have enough text to evaluate.
        // ══════════════════════════════════════════════════════════════
        if ($sector !== null) {
            $sectorRelevancePatterns = [
                'Automotive' => '/\b(automotive|vehicle|car\s+manufactur(?:er|ing)|auto(?:mobile)?\s+(industry|sector|manufactur(?:er|ing)|oem|supplier)|iatf\s*16949|powertrain|chassis|body\s+electronics|adas|ecu|engine\s+control|infotainment|dashboard|steering|braking|suspension|drivetrain|ev\s+(platform|battery|motor)|electric\s+vehicle|connected\s+car|autonomous\s+driv|tier[\s-]?[12]|fahrzeug|automobilzulieferer|automobilindustrie|automobile|véhicule|industrie\s+automobile|costruttore\s+auto|fabricante\s+de\s+autom[oó]vil|motoryzacja|سيارات|مركبات|مورد\s+سيارات)\b/iu',

                'Aerospace' => '/\b(aerospace|aviation|aircraft|airframe|avionics|aerostructure|space\s+(industry|sector)|satellite|rocket|propulsion|as9100|do-178|do-254|luftfahrt|aéronautique|aeronautique|aeronautica|aeroespacial|طيران|فضاء|مكونات\s+طيران)\b/iu',

                'Medical' => '/\b(medical\s+device|medtech|healthcare\s+equipment|surgical|diagnostic|implant|clinical|patient\s+monitor|iso\s+13485|medizintechnik|dispositif\s+médical|dispositif\s+medical|dispositivo\s+medico|أجهزة\s+طبية|معدات\s+طبية)\b/iu',

                'Defense' => '/\b(defense|defence|military|naval|army|tactical|ammunition|missile|radar\s+system|itar|mil[\s-]?spec|rüstung|défense|defense\s+industry|difesa|defensa|دفاع|عسكري|أنظمة\s+رادار)\b/iu',

                'Energy' => '/\b(energy|renewable|solar|wind\s+turbine|power\s+generation|grid|smart\s+grid|energy\s+storage|battery\s+system|photovoltaic|energie|énergie|energia|طاقة|تخزين\s+الطاقة|بطاريات)\b/iu',

                'Industrial' => '/\b(industrial\s+(automation|control|equipment|machinery)|factory\s+automation|process\s+control|plc|scada|hmi|motion\s+control|industrieautomation|automatisation\s+industrielle|automazione\s+industriale|أتمتة\s+صناعية|تحكم\s+صناعي)\b/iu',

                'Telecom' => '/\b(telecom|5g|antenna|base\s+station|network\s+equipment|fiber\s+optic|optical\s+transport|telekommunikation|télécommunication|telecomunicazioni|اتصالات|شبكات)\b/iu',

                'Marine' => '/\b(marine|maritime|shipbuilding|naval\s+architect|offshore|vessel|ship\s+system|schiffbau|maritime\s+industrie|costruzione\s+navale|بحري|سفن|منصات\s+بحرية)\b/iu',
            ];

            $sectorNorm = ucfirst(strtolower(trim($sector)));
            if (isset($sectorRelevancePatterns[$sectorNorm])) {
                $hasSectorRelevance = false;

                // Prefer title/snippet for sector relevance (higher signal quality), then homepage
                foreach (['title', 'snippet', 'homepage'] as $segmentName) {
                    $segmentText = $segments[$segmentName] ?? '';
                    if ($segmentText === '') {
                        continue;
                    }

                    if (preg_match($sectorRelevancePatterns[$sectorNorm], $segmentText, $m)) {
                        $weight = $segmentName === 'title' ? 12 : ($segmentName === 'snippet' ? 10 : 8);

                        $this->pushEvidence(
                            $evidence,
                            $dedupePos,
                            'SECTOR_ALIGNMENT',
                            $sectorNorm . ' vocabulary: ' . (string) $m[0],
                            $weight,
                            $segmentName . ':sector_check'
                        );

                        $hasSectorRelevance = true;
                        break;
                    }
                }

                // Soft anti only if we have enough text to make a fair judgment
                $evaluatedTextLen = strlen(($segments['title'] ?? '') . ' ' . ($segments['snippet'] ?? '') . ' ' . ($segments['homepage'] ?? ''));
                if (!$hasSectorRelevance && $evaluatedTextLen >= 80) {
                    $this->pushAntiEvidence(
                        $antiEvidence,
                        $dedupeAnti,
                        'NO_SECTOR_RELEVANCE',
                        'no ' . $sectorNorm . ' vocabulary',
                        -12,
                        'composite:sector_check'
                    );
                }
            }
        }

        // ══════════════════════════════════════════════════════════════
        // COMPOSITE / SYNERGY SIGNALS — boosts real industrial buyers
        // ══════════════════════════════════════════════════════════════
        $familyTotals = $this->sumEvidenceByFamily($evidence);

        $hasProduct = ($familyTotals['PRODUCT_PORTFOLIO'] ?? 0) > 0;
        $hasMfg = ($familyTotals['MANUFACTURING_OEM'] ?? 0) > 0;
        $hasProc = ($familyTotals['BUYER_PROCUREMENT'] ?? 0) > 0;
        $hasFoot = ($familyTotals['ORG_FOOTPRINT'] ?? 0) > 0;
        $hasSector = ($familyTotals['SECTOR_ALIGNMENT'] ?? 0) > 0;

        if ($hasProduct && $hasMfg) {
            $this->pushEvidence(
                $evidence,
                $dedupePos,
                'BUYER_INTENT_COMPOSITE',
                'product_manufacturing_synergy',
                10,
                'composite:synergy'
            );
        }

        if ($hasProc && ($hasProduct || $hasMfg)) {
            $this->pushEvidence(
                $evidence,
                $dedupePos,
                'BUYER_INTENT_COMPOSITE',
                'procurement_with_industrial_context',
                10,
                'composite:synergy'
            );
        }

        if ($hasFoot && ($hasProduct || $hasMfg) && preg_match('/\b(iso\s+9001|iatf\s*16949|as9100|iso\s+13485)\b/i', $text)) {
            $this->pushEvidence(
                $evidence,
                $dedupePos,
                'BUYER_INTENT_COMPOSITE',
                'industrial_certified_org',
                8,
                'composite:synergy'
            );
        }

        if ($hasSector && ($hasProduct || $hasMfg || $hasProc)) {
            $this->pushEvidence(
                $evidence,
                $dedupePos,
                'BUYER_INTENT_COMPOSITE',
                'sector_plus_buyer_intent',
                8,
                'composite:synergy'
            );
        }

        // Final context for diagnostics/persistence
        $context = [
            'sector' => $sector,
            'has_homepage_text' => trim($homepageText) !== '',
            'input_lengths' => [
                'company' => strlen($companyName),
                'title' => strlen($title),
                'snippet' => strlen($snippet),
                'homepage' => strlen($homepageText),
            ],
            'normalized_domain' => $normalizedDomain,
        ];

        return new BuyerEvidenceResult($evidence, $antiEvidence, $companyName, $domain, $context);
    }

    /**
     * Build normalized text segments. Homepage is truncated before normalization
     * to reduce nav/footer noise and CPU cost.
     *
     * @return array<string,string>
     */
    private function buildNormalizedSegments(
        string $companyName,
        string $snippet,
        string $title,
        string $homepageText,
    ): array {
        $homepageTrimmed = trim($homepageText);
        if ($homepageTrimmed !== '' && strlen($homepageTrimmed) > 8000) {
            $homepageTrimmed = substr($homepageTrimmed, 0, 8000);
        }

        return [
            'company'  => $this->normalizer->normalize($companyName),
            'title'    => $this->normalizer->normalize($title),
            'snippet'  => $this->normalizer->normalize($snippet),
            'homepage' => $this->normalizer->normalize($homepageTrimmed),
        ];
    }

    /**
     * Adds one evidence item per signal (best matching segment wins).
     *
     * @param EvidenceItem[] $bucket
     * @param array<string,bool> $dedupe
     * @param array<string,int> $signals
     * @param array<string,string> $segments
     */
    private /**
 * @param array<string|int, mixed> $signals
 * @param array<string|int, mixed> $segments
 */
function addTextSignals(
        array &$bucket,
        array &$dedupe,
        string $family,
        array $signals,
        array $segments,
    ): void {
        foreach ($signals as $signal => $baseWeight) {
            $best = null;

            foreach ($segments as $segmentName => $segmentText) {
                if ($segmentText === '') {
                    continue;
                }

                if (str_contains($segmentText, $signal)) {
                    $weight = $this->scaleWeightBySource($baseWeight, $segmentName, false);
                    if ($best === null || $weight > $best['weight']) {
                        $best = [
                            'weight' => $weight,
                            'source' => $segmentName . ':text_match',
                        ];
                    }
                }
            }

            if ($best !== null) {
                $this->pushEvidence($bucket, $dedupe, $family, $signal, $best['weight'], $best['source']);
            }
        }
    }

    /**
     * Adds one evidence item per regex pattern (best matching segment wins).
     *
     * @param EvidenceItem[] $bucket
     * @param array<string,bool> $dedupe
     * @param array<string,int> $patterns
     * @param array<string,string> $segments
     */
    private /**
 * @param array<string|int, mixed> $patterns
 * @param array<string|int, mixed> $segments
 */
function addRegexSignals(
        array &$bucket,
        array &$dedupe,
        string $family,
        array $patterns,
        array $segments,
    ): void {
        foreach ($patterns as $pattern => $baseWeight) {
            $best = null;

            foreach ($segments as $segmentName => $segmentText) {
                if ($segmentText === '') {
                    continue;
                }

                if (preg_match($pattern, $segmentText, $m)) {
                    $weight = $this->scaleWeightBySource($baseWeight, $segmentName, false);
                    if ($best === null || $weight > $best['weight']) {
                        $best = [
                            'signal' => (string) $m[0],
                            'weight' => $weight,
                            'source' => $segmentName . ':regex_match',
                        ];
                    }
                }
            }

            if ($best !== null) {
                $this->pushEvidence($bucket, $dedupe, $family, $best['signal'], $best['weight'], $best['source']);
            }
        }
    }

    /**
     * @param EvidenceItem[] $bucket
     * @param array<string,bool> $dedupe
     */
    private function pushEvidence(
        array &$bucket,
        array &$dedupe,
        string $family,
        string $signal,
        int $weight,
        string $source,
    ): void {
        if ($weight <= 0) {
            return;
        }

        $key = $family . '|' . mb_strtolower($signal) . '|' . $source;
        if (isset($dedupe[$key])) {
            return;
        }

        $dedupe[$key] = true;
        $bucket[] = new EvidenceItem($family, $signal, $weight, $source);
    }

    /**
     * @param EvidenceItem[] $bucket
     * @param array<string,bool> $dedupe
     */
    private function pushAntiEvidence(
        array &$bucket,
        array &$dedupe,
        string $family,
        string $signal,
        int $weight,
        string $source,
    ): void {
        if ($weight >= 0) {
            return;
        }

        $key = $family . '|' . mb_strtolower($signal) . '|' . $source;
        if (isset($dedupe[$key])) {
            return;
        }

        $dedupe[$key] = true;
        $bucket[] = new EvidenceItem($family, $signal, $weight, $source);
    }

    /**
     * Source-aware weighting:
     *  - Title is strongest
     *  - Homepage is strong for confirmation
     *  - Snippet is baseline
     *  - Company name is weaker
     *  - Composite/domain fixed-ish
     */
    private function scaleWeightBySource(int $weight, string $segmentName, bool $isAnti): int
    {
        $mult = match ($segmentName) {
            'title' => $isAnti ? 1.20 : 1.20,
            'snippet' => $isAnti ? 1.00 : 1.00,
            'homepage' => $isAnti ? 0.90 : 1.10, // homepage anti is noisier
            'company' => $isAnti ? 0.80 : 0.70,
            'domain' => 1.00,
            default => 1.00,
        };

        return max(1, (int) round($weight * $mult));
    }

    /**
     * Anti family-specific base weights.
     * Hard junk categories are stronger than soft/noisy categories.
     */
    private function antiBaseWeightForFamily(string $family): int
    {
        if (in_array($family, self::HARD_VETO_ANTI_FAMILIES, true)) {
            return 35;
        }

        if (in_array($family, self::SOFT_ANTI_FAMILIES, true)) {
            return 20;
        }

        // Other anti families are still countable and important
        return 25;
    }

    /**
     * Smarter anti scan policy:
     * Some anti families are very noisy on homepages because of nav/footer links.
     * We rely mostly on title/snippet/company for those.
     *
     * @param array<string,string> $segments
     * @return array<string,string>
     */
    private /**
 * @param array<string|int, mixed> $segments
 */
function antiSegmentsForFamily(string $family, array $segments): array
    {
        $homepageNoisyFamilies = [
            'MEDIA',
            'NEWS_CONTENT',
            'EVENT',
            'E_COMMERCE',
            'RECRUITMENT',
            'TRAVEL_TOURISM',
            'TRAINING',
        ];

        if (in_array($family, $homepageNoisyFamilies, true)) {
            return [
                'company' => $segments['company'] ?? '',
                'title'   => $segments['title'] ?? '',
                'snippet' => $segments['snippet'] ?? '',
            ];
        }

        return $segments;
    }

    /**
     * @param EvidenceItem[] $evidence
     * @return array<string,int>
     */
    private /**
 * @param array<string|int, mixed> $evidence
 */
function sumEvidenceByFamily(array $evidence): array
    {
        $totals = [];
        foreach ($evidence as $item) {
            if ($item->weight > 0) {
                $totals[$item->family] = ($totals[$item->family] ?? 0) + (int) $item->weight;
            }
        }
        return $totals;
    }
}