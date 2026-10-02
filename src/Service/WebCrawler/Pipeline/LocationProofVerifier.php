<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Pipeline;

/**
 * Verifies that a candidate domain has real presence in the target location
 * using structured address data, ccTLD, phone country codes, and text mentions.
 *
 * Does NOT trust search-snippet echoes — only crawled page content.
 */
final class LocationProofVerifier
{
    /** Confirmation threshold (sum of signal weights). */
    private const THRESHOLD = 3;

    private const WEIGHT_JSON_LD  = 5;
    private const WEIGHT_CCTLD    = 3;
    private const WEIGHT_PHONE    = 3;
    private const WEIGHT_TEXT     = 3;

    /**
     * Country/location name → ccTLD suffixes.
     * Matched against the domain name via str_ends_with.
     */
    private const LOCATION_CCTLDS = [
        'morocco'        => ['.ma'],
        'maroc'          => ['.ma'],
        'united states'  => ['.us'],
        'us'             => ['.us'],
        'germany'        => ['.de'],
        'deutschland'    => ['.de'],
        'france'         => ['.fr'],
        'finland'        => ['.fi'],
        'italy'          => ['.it'],
        'italia'         => ['.it'],
        'spain'          => ['.es'],
        'españa'         => ['.es'],
        'turkey'         => ['.tr', '.com.tr'],
        'türkiye'        => ['.tr', '.com.tr'],
        'poland'         => ['.pl'],
        'polska'         => ['.pl'],
        'romania'        => ['.ro'],
        'czech republic' => ['.cz'],
        'hungary'        => ['.hu'],
        'netherlands'    => ['.nl'],
        'belgium'        => ['.be'],
        'sweden'         => ['.se'],
        'austria'        => ['.at'],
        'switzerland'    => ['.ch'],
        'united kingdom' => ['.uk', '.co.uk'],
        'uk'             => ['.uk', '.co.uk'],
        'portugal'       => ['.pt'],
        'tunisia'        => ['.tn'],
        'egypt'          => ['.eg'],
        'united arab emirates' => ['.ae'],
        'uae'            => ['.ae'],
        'saudi arabia'   => ['.sa'],
        'qatar'          => ['.qa'],
        'bahrain'        => ['.bh'],
        'oman'           => ['.om'],
        'kuwait'         => ['.kw'],
        'south africa'   => ['.za'],
    ];

    /**
     * Country/location name → phone country code prefixes.
     */
    private const LOCATION_PHONE_CODES = [
        'morocco'        => ['+212'],
        'maroc'          => ['+212'],
        'united states'  => ['+1'],
        'us'             => ['+1'],
        'germany'        => ['+49'],
        'deutschland'    => ['+49'],
        'france'         => ['+33'],
        'finland'        => ['+358'],
        'italy'          => ['+39'],
        'italia'         => ['+39'],
        'spain'          => ['+34'],
        'españa'         => ['+34'],
        'turkey'         => ['+90'],
        'türkiye'        => ['+90'],
        'poland'         => ['+48'],
        'polska'         => ['+48'],
        'romania'        => ['+40'],
        'czech republic' => ['+420'],
        'hungary'        => ['+36'],
        'netherlands'    => ['+31'],
        'belgium'        => ['+32'],
        'sweden'         => ['+46'],
        'austria'        => ['+43'],
        'switzerland'    => ['+41'],
        'united kingdom' => ['+44'],
        'uk'             => ['+44'],
        'portugal'       => ['+351'],
        'tunisia'        => ['+216'],
        'egypt'          => ['+20'],
        'united arab emirates' => ['+971'],
        'uae'            => ['+971'],
        'saudi arabia'   => ['+966'],
        'qatar'          => ['+974'],
        'bahrain'        => ['+973'],
        'oman'           => ['+968'],
        'kuwait'         => ['+965'],
        'south africa'   => ['+27'],
    ];

    /**
     * Country codes (ISO 3166) → location name for JSON-LD addressCountry matching.
     */
    private const COUNTRY_CODES = [
        'morocco' => ['MA', 'MAR'],
        'maroc'   => ['MA', 'MAR'],
        'united states' => ['US', 'USA'],
        'us' => ['US', 'USA'],
        'germany' => ['DE', 'DEU'],
        'france'  => ['FR', 'FRA'],
        'finland' => ['FI', 'FIN'],
        'italy'   => ['IT', 'ITA'],
        'spain'   => ['ES', 'ESP'],
        'turkey'  => ['TR', 'TUR'],
        'poland'  => ['PL', 'POL'],
        'romania' => ['RO', 'ROU'],
        'czech republic' => ['CZ', 'CZE'],
        'hungary' => ['HU', 'HUN'],
        'netherlands' => ['NL', 'NLD'],
        'belgium' => ['BE', 'BEL'],
        'sweden'  => ['SE', 'SWE'],
        'austria' => ['AT', 'AUT'],
        'switzerland' => ['CH', 'CHE'],
        'united kingdom' => ['GB', 'GBR', 'UK'],
        'uk'      => ['GB', 'GBR', 'UK'],
        'portugal' => ['PT', 'PRT'],
        'tunisia' => ['TN', 'TUN'],
        'egypt'   => ['EG', 'EGY'],
        'united arab emirates' => ['AE', 'ARE', 'UAE'],
        'uae'     => ['AE', 'ARE', 'UAE'],
        'saudi arabia' => ['SA', 'SAU'],
        'qatar'   => ['QA', 'QAT'],
        'bahrain' => ['BH', 'BHR'],
        'oman'    => ['OM', 'OMN'],
        'kuwait'  => ['KW', 'KWT'],
        'south africa' => ['ZA', 'ZAF'],
    ];

    /**
     * Common aliases used in page text for location mentions.
     *
     * @var array<string, string[]>
     */
    private const LOCATION_TEXT_ALIASES = [
        'united states' => ['united states', 'usa', 'u.s.a'],
        'united kingdom' => ['united kingdom', 'uk', 'britain'],
        'united arab emirates' => ['united arab emirates', 'uae'],
    ];

    public function verify(CrawledDomain $domain, ?string $targetLocation): LocationVerdict
    {
        // No target → always confirmed (location not relevant)
        if ($targetLocation === null || $targetLocation === '') {
            return new LocationVerdict(true, 1.0, []);
        }

        $locationLower = mb_strtolower(trim($targetLocation));
        $allText = mb_strtolower($domain->getAllText());
        $structuredData = $domain->getAllStructuredData();
        $domainName = $domain->getDomain();

        $signals = [];
        $totalWeight = 0;

        // 1. JSON-LD address
        $jsonLdWeight = $this->checkJsonLdAddress($structuredData, $locationLower);
        if ($jsonLdWeight > 0) {
            $signals['json_ld_address'] = $jsonLdWeight;
            $totalWeight += $jsonLdWeight;
        }

        // 2. ccTLD
        $ccTldWeight = $this->checkCcTld($domainName, $locationLower);
        if ($ccTldWeight > 0) {
            $signals['cctld'] = $ccTldWeight;
            $totalWeight += $ccTldWeight;
        }

        // 3. Phone country code
        $phoneWeight = $this->checkPhoneCode($allText, $locationLower);
        if ($phoneWeight > 0) {
            $signals['phone_code'] = $phoneWeight;
            $totalWeight += $phoneWeight;
        }

        // 4. Text mention of location
        $textWeight = $this->checkTextMention($allText, $locationLower);
        if ($textWeight > 0) {
            $signals['text_mention'] = $textWeight;
            $totalWeight += $textWeight;
        }

        $confirmed = $totalWeight >= self::THRESHOLD;
        $maxPossible = self::WEIGHT_JSON_LD + self::WEIGHT_CCTLD + self::WEIGHT_PHONE + self::WEIGHT_TEXT; // constant 14
        $confidence = round(min(1.0, $totalWeight / $maxPossible), 3);

        return new LocationVerdict($confirmed, $confidence, $signals);
    }

    /**
     * JSON-LD values come from decoded JSON: coerce scalars to string,
     * default anything else (null/array/object) to ''.
     */
    private static function coerceString(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<int, array<string, mixed>> $structuredData
     */
    private function checkJsonLdAddress(array $structuredData, string $location): int
    {
        $countryCodes = self::COUNTRY_CODES[$location] ?? [];

        foreach ($structuredData as $item) {
            $address = $item['address'] ?? null;
            if (!\is_array($address)) {
                continue;
            }

            $fields = [
                mb_strtolower(self::coerceString($address['addressLocality'] ?? '')),
                mb_strtolower(self::coerceString($address['addressRegion'] ?? '')),
                mb_strtolower(self::coerceString($address['addressCountry'] ?? '')),
            ];

            foreach ($fields as $field) {
                if ($field === '') {
                    continue;
                }
                // Direct match
                if (str_contains($field, $location)) {
                    return self::WEIGHT_JSON_LD;
                }
                // ISO code match
                foreach ($countryCodes as $code) {
                    if (mb_strtolower($code) === $field || str_contains($field, mb_strtolower($code))) {
                        return self::WEIGHT_JSON_LD;
                    }
                }
            }
        }

        return 0;
    }

    private function checkCcTld(string $domain, string $location): int
    {
        $tlds = self::LOCATION_CCTLDS[$location] ?? [];

        foreach ($tlds as $tld) {
            if (str_ends_with($domain, $tld)) {
                return self::WEIGHT_CCTLD;
            }
        }

        return 0;
    }

    private function checkPhoneCode(string $text, string $location): int
    {
        $codes = self::LOCATION_PHONE_CODES[$location] ?? [];

        foreach ($codes as $code) {
            if (str_contains($text, $code)) {
                return self::WEIGHT_PHONE;
            }
        }

        return 0;
    }

    private function checkTextMention(string $text, string $location): int
    {
        $aliases = self::LOCATION_TEXT_ALIASES[$location] ?? [$location];

        foreach ($aliases as $alias) {
            if (str_contains($text, $alias)) {
                return self::WEIGHT_TEXT;
            }
        }

        return 0;
    }
}
