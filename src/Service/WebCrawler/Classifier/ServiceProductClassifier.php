<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Classifier;

use App\Service\WebCrawler\Text\TextNormalizer;

/**
 * Binary classifier: does a company sell PRODUCTS or SERVICES?
 *
 * Context: Starz is an EMS (Electronics Manufacturing Services) provider.
 * They want to find companies that DESIGN/OWN products and need someone
 * to manufacture them. They do NOT want other service companies (EMS
 * competitors, IT consultancies, staffing agencies, logistics providers, etc.)
 *
 * Decision logic:
 *  - serviceScore > 0 AND serviceScore ≥ productScore + threshold  → SERVICE_PROVIDER (reject)
 *  - productScore > 0 AND productScore ≥ serviceScore + threshold  → PRODUCT_COMPANY (pass)
 *  - Otherwise → INDETERMINATE (pass through, other gates decide)
 *
 * The threshold (default 10) prevents flipping on a single keyword.
 */
final class ServiceProductClassifier
{
    /**
     * Minimum gap between dominant score and the other to issue a firm verdict.
     */
    private const DECISION_THRESHOLD = 10;

    /**
     * Service signals: patterns → weight.
     * Higher weight = stronger evidence of being a services company.
     *
     * @var array<string, int>
     */
    private const SERVICE_SIGNALS = [
        // ── EMS competitor language ──
        'we (manufacture|assemble|produce|build) .{0,40}(for|on behalf)'                   => 40,
        '(contract|outsourced)\s+(manufactur|assembl|production)\s+(service|partner|provider)' => 40,
        '(pcb|pcba|cable|wire|harness)\s+(assembly|manufacturing)\s+service'                => 40,
        '(turnkey|full[\s-]?service)\s+(ems|electronics|contract|manufacturing)'            => 40,
        'electronics\s+manufacturing\s+services?\s+(provider|company|partner)'              => 40,
        'smt\s+(assembly|line|service|process)'                                             => 35,
        'through[\s-]?hole\s+(assembly|soldering)'                                          => 35,
        'box[\s-]?build\s+assembly'                                                         => 35,
        'prototype\s+to\s+production'                                                       => 30,
        '(low[\s-]volume.*high[\s-]mix|high[\s-]mix.*low[\s-]volume)'                       => 30,

        // ── Consulting / advisory ──
        '(management|strategy|engineering|technology|digital|operations?)\s+consult(ing|ancy)' => 35,
        'advisory\s+(firm|service|company|practice)'                                        => 30,
        'consulting\s+(firm|company|practice|service|group)'                                => 30,
        'professional\s+services?\s+(firm|company|provider|leader)'                         => 30,
        'audit\s+(&|and)\s+assurance'                                                       => 30,
        'tax\s+(&|and)\s+(legal|advisory)'                                                  => 30,

        // ── IT services / software house ──
        '(custom|offshore|nearshore)\s+software\s+(develop|company|house)'                  => 30,
        'it\s+(services?|outsourc|consulting|support)\s+(company|provider|firm)'             => 30,
        'web\s+(design|develop)\s+(company|agency|firm|service)'                             => 30,
        'app\s+develop(ment|er)\s+(company|agency|firm|service)'                             => 30,
        'saas\s+(platform|provider|company|product)'                                        => 25,
        'erp\s+(software|solutions?|vendor|implementation)'                                 => 25,
        'managed\s+(services?|hosting|it)\s+(provider|company)'                             => 25,

        // ── Staffing / recruitment ──
        'staffing\s+(agency|solution|company|service)'                                      => 35,
        'recruitment\s+(agency|firm|solution|company|service)'                               => 35,
        'talent\s+(acquisition|management|solution)'                                        => 30,
        'workforce\s+(solution|management|staffing)'                                        => 30,
        'executive\s+search\s+(firm|company)'                                               => 30,
        'headhunt(er|ing)'                                                                  => 30,

        // ── Logistics / freight ──
        'logistics\s+(provider|company|service|solutions?|operator)'                        => 30,
        'freight\s+(forward|forwarding|broker|company|services?)'                           => 30,
        'shipping\s+(company|line|services?|operator)'                                      => 30,
        'customs\s+(broker|clearance|brokerage)'                                            => 25,
        'supply\s+chain\s+(management|solutions?|services?)\s+(provider|company)'           => 30,
        'warehousing\s+(and\s+distribution|services?|solutions?)'                           => 25,
        '3pl|third[\s-]?party\s+logistics'                                                  => 30,

        // ── Training / certification body ──
        'training\s+(provider|company|center|centre|services?)'                             => 25,
        'certification\s+(body|provider|authority|services?)'                                => 25,
        'we\s+(train|certify|accredit)\s+'                                                  => 25,

        // ── Facilities / maintenance services ──
        'facilities?\s+management\s+(company|service|provider)'                             => 25,
        'cleaning\s+services?\s+(company|provider)'                                         => 25,
        'property\s+management\s+(company|service)'                                         => 25,

        // ── Engineering services (not product) ──
        'engineering\s+services?\s+(company|provider|firm|partner)'                         => 25,
        'r&?d\s+outsourc(ing|e)'                                                           => 25,
        '(nearshore|offshore)\s+engineer(ing)?\s+(services?|team)'                          => 25,
        'design\s+services?\s+(company|provider|firm)'                                      => 20,

        // ── Testing / inspection / certification services ──
        '(testing|inspection|verification)\s+services?\s+(company|provider|firm)'           => 25,
        'non[\s-]?destructive\s+testing\s+services?'                                        => 25,

        // ── Generic service language ──
        'we\s+provide\s+(service|solution|support|consulting)'                              => 20,
        'our\s+services?\s+include'                                                         => 15,
        'service\s+offerings?'                                                              => 10,
        'we\s+offer\s+(a\s+)?(wide\s+)?range\s+of\s+services?'                             => 20,

        // ══════════════ MULTI-LANGUAGE SERVICE SIGNALS ══════════════

        // ── DE: EMS / Auftragsfertigung ──
        '(Auftrags|Lohn|Kontakt)fertigung'                                                  => 40,
        'Elektronik(fertigung|produktion)\s+(Dienstleist|Partner|Anbieter)'                 => 40,
        'wir\s+(montieren|bestücken|fertigen)\s+.{0,30}(für|im Auftrag)'                   => 40,
        'SMD[\s-]?Bestückung'                                                               => 35,
        'Baugruppen(fertigung|montage)\s+(Service|Dienstleist)'                             => 35,
        'Kabelbaumfertigung\s+(Service|Dienstleist)'                                        => 35,
        '(Prototypen?|Klein|Mittel|Groß)serienfertigung'                                   => 30,

        // ── DE: Beratung / Consulting ──
        '(Unternehmens|Management|Strategie|IT|Personal)[\s-]?beratung'                     => 35,
        'Beratungsunternehmen|Beratungsfirma|Beratungsgesellschaft'                         => 30,
        'wir\s+beraten\s+(Sie|Unternehmen|Kunden)'                                         => 25,

        // ── DE: IT-Dienstleistungen ──
        'IT[\s-]?Dienstleist(ung|er)'                                                       => 30,
        'Softwareentwicklung\s+(Firma|Unternehmen|Dienstleist)'                             => 30,
        'Webentwicklung|Webagentur|Digitalagentur'                                          => 30,

        // ── DE: Personalvermittlung ──
        'Personalvermittlung|Zeitarbeit|Leiharbeit|Personaldienstleist'                      => 35,
        'Personalberatung|Headhunt'                                                         => 30,

        // ── DE: Logistik ──
        'Logistik(dienstleist|unternehmen|anbieter)'                                        => 30,
        'Spedition|Frachtführer|Transportdienstleist'                                       => 30,

        // ── DE: Generic ──
        'wir\s+bieten\s+.{0,20}Dienstleistung'                                             => 20,
        'unsere\s+Dienstleistungen\s+umfassen'                                              => 15,

        // ── FR: EMS / sous-traitance ──
        'sous[\s-]?traitance\s+électronique'                                                => 40,
        '(fabrication|assemblage)\s+(électronique\s+)?(sous[\s-]?trait|pour\s+le\s+compte)' => 40,
        'nous\s+(assemblons|fabriquons|produisons)\s+.{0,30}(pour|au nom de)'               => 40,
        'câblage|brasage\s+CMS'                                                             => 35,
        'sous[\s-]?traitant\s+(industriel|électronique)'                                    => 40,

        // ── FR: Conseil ──
        'cabinet\s+de\s+(conseil|consulting)'                                               => 30,
        'société\s+de\s+conseil'                                                            => 30,
        'nous\s+conseillons'                                                                 => 25,

        // ── FR: Intérim / recrutement ──
        'agence\s+(d.intérim|de\s+recrutement|d.emploi)'                                    => 35,
        'travail\s+temporaire|intérim'                                                      => 30,

        // ── FR: Logistique ──
        'prestataire\s+logistique|transporteur'                                              => 30,

        // ── IT: EMS / terzista ──
        '(produzione|assemblaggio)\s+(conto\s+terzi|per\s+conto)'                           => 40,
        'terzista\s+(elettronic|industriale)'                                                => 40,
        'servizi\s+di\s+(produzione|assemblaggio)\s+elettronic'                              => 40,
        'noi\s+(assembliamo|produciamo)\s+.{0,30}per\s+conto'                               => 40,

        // ── IT: Consulenza ──
        'società\s+di\s+consulenza'                                                         => 30,
        'consulenza\s+(gestionale|strategica|aziendale|informatica)'                        => 30,

        // ── IT: Logistica ──
        'operatore\s+logistico|spedizioniere'                                                => 30,

        // ── ES: Fabricación por contrato ──
        'fabricación\s+(por\s+contrato|subcontrat)'                                         => 40,
        'ensamblaje\s+(electrónico|por\s+contrato)'                                         => 40,
        'servicios?\s+de\s+(fabricación|ensamblaje|manufactura)'                             => 40,

        // ── ES: Consultoría ──
        'consultoría|consultora|firma\s+de\s+consultoría'                                   => 30,
        'asesoría|asesoramiento'                                                             => 25,

        // ── ES: Logística ──
        'operador\s+logístico|empresa\s+de\s+transporte'                                    => 30,

        // ── NL: Contract manufacturing ──
        'contract\s+fabrikant|loonproducent'                                                 => 40,
        'elektronica\s+productie(\s+dienst)?'                                               => 40,
        'wij\s+(assembleren|produceren)\s+.{0,30}(voor|in\s+opdracht)'                      => 40,

        // ── NL: Advies / detachering ──
        'adviesbureau|consultancybureau'                                                     => 30,
        'detachering|uitzendbureau'                                                          => 35,

        // ── PL: Produkcja kontraktowa ──
        'produkcja\s+(kontraktowa|na\s+zlecenie|pod\s+klucz)'                               => 40,
        'montaż\s+(elektroniki|podzespołów|na\s+zlecenie)'                                  => 40,
        'usługi\s+(montażowe|produkcyjne|EMS)'                                              => 40,

        // ── PL: Doradztwo / kadry ──
        'firma\s+(doradcza|konsultingowa)'                                                   => 30,
        'agencja\s+(pracy\s+tymczasowej|zatrudnienia|rekrutacyjna)'                         => 35,

        // ── CZ: Smluvní výroba ──
        'smluvní\s+výroba|zakázková\s+výroba|výroba\s+na\s+zakázku'                         => 40,
        'montáž\s+elektroniky|osazování\s+DPS'                                              => 40,
        'služby\s+(montáže|výroby|EMS)'                                                     => 40,

        // ── CZ: Poradenství ──
        'poradenská\s+(firma|společnost)'                                                    => 30,
        'personální\s+agentura'                                                              => 35,
    ];

    /**
     * Product signals: patterns → weight.
     * Higher weight = stronger evidence of being a product company.
     *
     * @var array<string, int>
     */
    private const PRODUCT_SIGNALS = [
        // ── OEM / product design / R&D ──
        'we\s+(design|develop|engineer|innovate)\s+.{0,30}(product|system|device|solution)' => 35,
        'our\s+(products?|devices?|instruments?|systems?|equipment)'                        => 25,
        'product\s+(line|range|portfolio|family|catalog|series)'                             => 30,
        'r&d\s+(center|lab|team|department|facility|investment)'                             => 25,
        'research\s+and\s+development\s+(center|lab|team|facility)'                         => 25,
        'engineering\s+team'                                                                 => 15,
        'innovation\s+(center|lab|hub)'                                                      => 20,
        'patent(s|ed)?'                                                                      => 20,
        'proprietary\s+(technology|design|product|platform|algorithm)'                      => 25,

        // ── Manufacturing facility / own factory ──
        'our\s+(factory|plant|production\s+(line|facility))'                                => 30,
        'production\s+capacity'                                                              => 20,
        'manufacturing\s+(plant|facility|site|campus|capacity)'                             => 25,
        'assembly\s+(plant|line|facility)'                                                   => 20,
        '(lean|agile)\s+manufactur'                                                         => 15,
        'six\s+sigma'                                                                        => 10,

        // ── Specific product types that need EMS ──
        '(inverter|converter|controller|sensor|actuator|module|transducer|encoder|decoder)s?' => 20,
        '(radar|lidar|sonar|avionics|telematics|infotainment|instrument\s+cluster)'          => 25,
        '(battery\s+management|bms|ecu|power\s+supply|ups|switchgear|transformer)'           => 20,
        '(motor\s+drive|vfd|plc|hmi|scada)\s'                                                => 15,
        '(medical\s+device|diagnostic\s+equipment|imaging\s+system)'                         => 25,
        '(industrial\s+automation\s+product|embedded\s+system|IoT\s+device)'                 => 25,
        '(smart\s+(meter|grid|home|lock|sensor)|wearable\s+(device|technology))'             => 20,
        '(drone|uav|unmanned)\s+(system|platform|vehicle)'                                   => 25,
        '(defence|defense|military)\s+(system|electronics?|equipment|platform)'              => 25,
        '(telecom(s|munication)?\s+equipment|network\s+equipment|base\s+station)'            => 25,
        '(charging\s+(station|system|infrastructure)|ev\s+(charger|charging))'               => 20,

        // ── Certifications that product companies get ──
        '(iso\s+9001|iatf\s+16949|as9100|iso\s+13485|iso\s+14001|nadcap|ce\s+mark)'        => 15,

        // ── Supply chain / procurement language (they buy components) ──
        'supply\s+chain'                                                                     => 10,
        'procurement\s+(team|department|strategy)'                                           => 15,
        'outsourc(e|ing)\s+(manufactur|production|assembly)'                                 => 20,
        'oem\s+partner'                                                                      => 20,
        'tier[\s-]?[12]\s+supplier'                                                          => 15,
        'looking\s+for\s+(a\s+)?(manufactur|ems|assembl)'                                    => 30,

        // ── Established business indicators ──
        'headquarters?\s+in'                                                                 => 10,
        'founded\s+(in\s+)?\d{4}'                                                            => 10,
        'established\s+(in\s+)?\d{4}'                                                        => 10,
        'global\s+(presence|footprint|operations?)'                                          => 10,
        '(employees?|workforce)\s+of\s+'                                                     => 5,

        // ── "We are [industry]" language showing they ARE in the business ──
        '(leading|global|premier|innovative)\s+(manufacturer|producer|developer|designer)\s+of' => 30,

        // ══════════════ MULTI-LANGUAGE PRODUCT SIGNALS ══════════════

        // ── DE: Produktentwicklung / Eigenfertigung ──
        'wir\s+(entwickeln|konstruieren|fertigen|produzieren)\s+.{0,30}(Produkt|Gerät|System|Lösung)' => 35,
        'unsere\s+(Produkte?|Geräte|Systeme|Lösungen)'                                      => 25,
        'Produkt(palette|reihe|portfolio|sortiment|familie|katalog)'                         => 30,
        'F&E[\s-]?(Zentrum|Labor|Abteilung|Team|Standort)'                                  => 25,
        'Forschung\s+und\s+Entwicklung'                                                      => 25,
        'eigene\s+(Fertigung|Produktion|Fabrik|Entwicklung)'                                 => 30,
        'Produktions(anlage|standort|stätte|kapazität)'                                     => 25,
        'Fertigungslinie|Produktionslinie'                                                    => 20,
        'proprietäre\s+(Technologie|Lösung)'                                                 => 25,
        '(führender|innovativer|globaler)\s+(Hersteller|Produzent|Entwickler)\s+(von|für)'   => 30,
        'Hauptsitz\s+in'                                                                     => 10,
        'gegründet\s+(\d{4}|im\s+Jahr)'                                                      => 10,

        // ── FR: Produits / R&D ──
        'nous\s+(concevons|développons|fabriquons|produisons)\s+.{0,30}(produit|appareil|système)' => 35,
        'nos\s+(produits?|solutions?|équipements?|appareils?)'                                => 25,
        'gamme\s+de\s+produits?'                                                              => 30,
        'centre\s+de\s+R&D|laboratoire\s+(de\s+)?R&D'                                        => 25,
        'recherche\s+et\s+développement'                                                      => 25,
        'capacité\s+de\s+production'                                                          => 20,
        'notre\s+(usine|site\s+de\s+production)'                                              => 30,
        'technologie\s+propriétaire|brevet(s|é)?'                                             => 25,
        '(leader|fabricant)\s+(mondial|innovant)\s+(de|en)'                                   => 30,
        'siège\s+social\s+(à|en|au)'                                                          => 10,
        'fondée?\s+en\s+\d{4}'                                                                => 10,

        // ── IT: Prodotti / Ricerca ──
        '(progettiamo|sviluppiamo|produciamo)\s+.{0,30}(prodott|dispostiv|sistem)'            => 35,
        'i\s+nostri\s+(prodotti|dispositivi|sistemi|soluzioni)'                               => 25,
        'gamma\s+di\s+prodotti|portafoglio\s+prodotti'                                       => 30,
        'centro\s+R&S|laboratorio\s+R&S'                                                     => 25,
        'ricerca\s+e\s+sviluppo'                                                              => 25,
        'il\s+nostro\s+stabilimento|capacità\s+produttiva'                                   => 25,
        'tecnologia\s+proprietaria|brevett'                                                   => 25,
        '(leader|produttore)\s+(mondiale|innovativo)\s+(di|in)'                               => 30,
        'sede\s+centrale\s+(a|in)'                                                            => 10,
        'fondata\s+nel\s+\d{4}'                                                               => 10,

        // ── ES: Productos / I+D ──
        '(diseñamos|desarrollamos|fabricamos|producimos)\s+.{0,30}(producto|dispositivo|sistema)' => 35,
        'nuestros\s+(productos|dispositivos|sistemas|soluciones|equipos)'                    => 25,
        'gama\s+de\s+productos|cartera\s+de\s+productos'                                    => 30,
        'centro\s+de\s+I\+D|laboratorio\s+de\s+I\+D'                                        => 25,
        'investigación\s+y\s+desarrollo'                                                      => 25,
        'nuestra\s+(fábrica|planta\s+de\s+producción)'                                       => 30,
        'tecnología\s+propia|patentad'                                                        => 25,
        '(líder|fabricante)\s+(mundial|innovador)\s+(de|en)'                                  => 30,
        'sede\s+central\s+en'                                                                 => 10,
        'fundada\s+en\s+\d{4}'                                                                => 10,

        // ── NL: Producten / R&D ──
        'wij\s+(ontwerpen|ontwikkelen|produceren|vervaardigen)\s+.{0,30}(product|apparaat|systeem)' => 35,
        'onze\s+(producten|apparaten|systemen|oplossingen)'                                  => 25,
        'productassortiment|productportfolio|productreeks'                                   => 30,
        'R&D[\s-]?(centrum|laboratorium|team|afdeling)'                                     => 25,
        'onze\s+(fabriek|productielocatie)'                                                  => 30,
        'gepatenteerd|propriëtaire\s+technologie'                                            => 25,
        '(toonaangevende|innovatieve|wereldwijde)\s+(fabrikant|producent|ontwikkelaar)\s+van' => 30,
        'hoofdkantoor\s+in'                                                                  => 10,
        'opgericht\s+in\s+\d{4}'                                                              => 10,

        // ── PL: Produkty / Badania ──
        '(projektujemy|rozwijamy|produkujemy|wytwarzamy)\s+.{0,30}(produkt|urządzen|system)'  => 35,
        'nasze\s+(produkty|urządzenia|systemy|rozwiązania)'                                  => 25,
        'portfolio\s+produktów|gama\s+produktów'                                             => 30,
        'centrum\s+badawczo[\s-]?rozwojowe|dział\s+R&D'                                     => 25,
        'badania\s+i\s+rozwój'                                                                => 25,
        'nasz(a\s+fabryka|\s+zakład\s+produkcyjny)'                                          => 30,
        'opatentowany|własna\s+technologia'                                                   => 25,
        '(wiodący|innowacyjny)\s+(producent|wytwórca)\s+'                                    => 30,
        'siedziba\s+w'                                                                        => 10,
        'założona\s+w\s+\d{4}'                                                                => 10,

        // ── CZ: Produkty / Výzkum ──
        '(navrhujeme|vyvíjíme|vyrábíme)\s+.{0,30}(produkt|zařízení|systém)'                  => 35,
        'naše\s+(produkty|zařízení|systémy|řešení)'                                          => 25,
        'portfolio\s+produktů|sortiment\s+produktů'                                          => 30,
        'výzkumné\s+centrum|oddělení\s+výzkumu'                                               => 25,
        'výzkum\s+a\s+vývoj'                                                                  => 25,
        'náš\s+(závod|výrobní\s+závod)|výrobní\s+kapacita'                                   => 30,
        'patentovaný|vlastní\s+technologie'                                                   => 25,
        '(přední|inovativní)\s+(výrobce|producent)\s+'                                       => 30,
        'sídlo\s+v'                                                                           => 10,
        'založena\s+v\s+\d{4}'                                                                => 10,
    ];

    private TextNormalizer $normalizer;

    public function __construct(?TextNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new TextNormalizer();
    }

    /**
     * Classify a candidate as PRODUCT_COMPANY, SERVICE_PROVIDER, or INDETERMINATE.
     */
    public function classify(
        string $companyName,
        string $snippet,
        string $title,
        string $domain,
    ): ServiceProductVerdict {
        $text = $this->normalizer->normalize($snippet . ' ' . $title . ' ' . $companyName);
        $domainLower = strtolower($domain);

        $productScore   = 0.0;
        $serviceScore   = 0.0;
        $breakdown      = [];

        // ── Score product signals ──
        foreach (self::PRODUCT_SIGNALS as $pattern => $weight) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $productScore += $weight;
                $breakdown['product:' . substr($pattern, 0, 50)] = $weight;
            }
        }

        // ── Score service signals ──
        foreach (self::SERVICE_SIGNALS as $pattern => $weight) {
            if (preg_match('/' . $pattern . '/i', $text)) {
                $serviceScore += $weight;
                $breakdown['service:' . substr($pattern, 0, 50)] = $weight;
            }
        }

        // ── Domain-based hints ──
        // Domains with "services", "consulting", "solutions" lean service (EN + multi-language)
        if (preg_match('/(services?|consulting|consult|advisory|staffing|recruit|logistics|freight|training|beratung|dienstleist|zeitarbeit|spedition|conseil|recrutement|logistique|consulenza|logistica|consultor|asesor|advies|detacher|doradz|poradenst)/', $domainLower)) {
            $serviceScore += 15;
            $breakdown['domain_service_hint'] = 15;
        }

        // Domains with "systems", "tech", "electronics", "devices" lean product (EN + multi-language)
        if (preg_match('/(systems?|tech|electronics?|devices?|instruments?|controls?|sensors?|power|energy|motors?|drives?|optics?|photonics?|elektronik|technik|messtechnik|sensorik|steuerung|antrieb|électronique|capteur|optique|elettronica|sensori|electrónica|technologie|techniek|elektronika)/', $domainLower)) {
            $productScore += 10;
            $breakdown['domain_product_hint'] = 10;
        }

        // ── Decision ──
        $threshold = self::DECISION_THRESHOLD;

        if ($serviceScore > 0 && $serviceScore >= $productScore + $threshold) {
            return new ServiceProductVerdict(
                ServiceProductVerdict::TYPE_SERVICE,
                $productScore,
                $serviceScore,
                sprintf(
                    'Service score %.0f dominates product score %.0f (gap %.0f ≥ threshold %d)',
                    $serviceScore, $productScore, $serviceScore - $productScore, $threshold,
                ),
                $breakdown,
            );
        }

        if ($productScore > 0 && $productScore >= $serviceScore + $threshold) {
            return new ServiceProductVerdict(
                ServiceProductVerdict::TYPE_PRODUCT,
                $productScore,
                $serviceScore,
                sprintf(
                    'Product score %.0f dominates service score %.0f (gap %.0f ≥ threshold %d)',
                    $productScore, $serviceScore, $productScore - $serviceScore, $threshold,
                ),
                $breakdown,
            );
        }

        return new ServiceProductVerdict(
            ServiceProductVerdict::TYPE_INDETERMINATE,
            $productScore,
            $serviceScore,
            sprintf(
                'Scores too close: product=%.0f, service=%.0f (gap %.0f < threshold %d)',
                $productScore, $serviceScore, abs($productScore - $serviceScore), $threshold,
            ),
            $breakdown,
        );
    }
}
