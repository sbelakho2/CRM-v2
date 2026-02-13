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
        // Domains with "services", "consulting", "solutions" lean service
        if (preg_match('/(services?|consulting|consult|advisory|staffing|recruit|logistics|freight|training)/', $domainLower)) {
            $serviceScore += 15;
            $breakdown['domain_service_hint'] = 15;
        }

        // Domains with "systems", "tech", "electronics", "devices" lean product
        if (preg_match('/(systems?|tech|electronics?|devices?|instruments?|controls?|sensors?|power|energy|motors?|drives?|optics?|photonics?)/', $domainLower)) {
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
