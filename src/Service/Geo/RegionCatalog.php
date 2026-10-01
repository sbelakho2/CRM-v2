<?php

declare(strict_types=1);

namespace App\Service\Geo;

use App\Service\RegionStandardizationService;

/**
 * THE canonical sales-territory catalog.
 *
 * Round-9 finding: country/region selection was inconsistent across the
 * system — the company form offered 6 coarse codes ('MA', 'US', 'EU', …),
 * the region filter offered countries + US subdivisions + ad-hoc extras,
 * the standardization service used 29 slug territories, and stored data
 * mixed all three vocabularies. This catalog is the single source both the
 * FORM and the FILTER now build from: every territory is always available,
 * with one name per territory, in every language.
 *
 * DATA COMPATIBILITY (live data is never rewritten): territory values are
 * the standardization-service slugs; every legacy token already present in
 * stored rows ('MA', 'US', 'EU', 'GB', 'EG', 'GCC', …) stays valid — it is
 * selectable in forms (labeled as legacy) and matched by the filter through
 * aliasesFor().
 */
final class RegionCatalog
{
    /** @var array<string, string> territory value => default English label */
    private const TERRITORIES = [
        RegionStandardizationService::REGION_MOROCCO => 'Morocco',
        RegionStandardizationService::REGION_TUNISIA => 'Tunisia',
        RegionStandardizationService::REGION_EGYPT => 'Egypt',
        RegionStandardizationService::REGION_AFRICA_NORTH => 'Africa — North',
        RegionStandardizationService::REGION_AFRICA_SUB => 'Africa — Sub-Saharan',
        RegionStandardizationService::REGION_MIDDLE_EAST => 'Middle East',
        RegionStandardizationService::REGION_UK => 'United Kingdom',
        RegionStandardizationService::REGION_EU_WEST => 'Europe — West',
        RegionStandardizationService::REGION_EU_CENTRAL => 'Europe — Central',
        RegionStandardizationService::REGION_EU_SOUTH => 'Europe — South',
        RegionStandardizationService::REGION_EU_NORTH => 'Europe — North',
        RegionStandardizationService::REGION_EASTERN_EUROPE => 'Eastern Europe',
        RegionStandardizationService::REGION_US_EAST => 'United States — East',
        RegionStandardizationService::REGION_US_CENTRAL => 'United States — Central',
        RegionStandardizationService::REGION_US_SOUTH => 'United States — South',
        RegionStandardizationService::REGION_US_WEST => 'United States — West',
        RegionStandardizationService::REGION_CANADA => 'Canada',
        RegionStandardizationService::REGION_MEXICO => 'Mexico',
        RegionStandardizationService::REGION_BRAZIL => 'Brazil',
        RegionStandardizationService::REGION_LATAM_OTHER => 'Latin America — Other',
        RegionStandardizationService::REGION_CHINA => 'China',
        RegionStandardizationService::REGION_TAIWAN => 'Taiwan',
        RegionStandardizationService::REGION_JAPAN => 'Japan',
        RegionStandardizationService::REGION_SOUTH_KOREA => 'South Korea',
        RegionStandardizationService::REGION_INDIA => 'India',
        RegionStandardizationService::REGION_SOUTHEAST_ASIA => 'Southeast Asia',
        RegionStandardizationService::REGION_AUSTRALIA_NZ => 'Australia & New Zealand',
        RegionStandardizationService::REGION_TURKEY => 'Turkey',
        RegionStandardizationService::REGION_OTHER => 'Other / Unassigned',
    ];

    /**
     * Every stored token (UPPERCASED) that means this territory — legacy
     * coarse codes, spelled-out names and punctuation variants. Used by the
     * region filter so a canonical selection matches historical rows.
     *
     * @var array<string, list<string>>
     */
    private const ALIASES = [
        RegionStandardizationService::REGION_MOROCCO => ['MOROCCO', 'MA'],
        RegionStandardizationService::REGION_TUNISIA => ['TUNISIA', 'TN'],
        RegionStandardizationService::REGION_EGYPT => ['EGYPT', 'EG'],
        RegionStandardizationService::REGION_AFRICA_NORTH => ['AFRICA_NORTH', 'AFRICA-NORTH', 'AFRICA NORTH', 'NORTH AFRICA', 'NA'],
        RegionStandardizationService::REGION_AFRICA_SUB => ['AFRICA_SUB', 'AFRICA-SUB', 'AFRICA SUB-SAHARAN', 'SUB-SAHARAN AFRICA'],
        RegionStandardizationService::REGION_MIDDLE_EAST => ['MIDDLE_EAST', 'MIDDLE-EAST', 'MIDDLE EAST', 'ME'],
        RegionStandardizationService::REGION_UK => ['UK', 'GB', 'UNITED KINGDOM'],
        RegionStandardizationService::REGION_EU_WEST => ['EU_WEST', 'EU-WEST', 'EU WEST'],
        RegionStandardizationService::REGION_EU_CENTRAL => ['EU_CENTRAL', 'EU-CENTRAL', 'EU CENTRAL'],
        RegionStandardizationService::REGION_EU_SOUTH => ['EU_SOUTH', 'EU-SOUTH', 'EU SOUTH'],
        RegionStandardizationService::REGION_EU_NORTH => ['EU_NORTH', 'EU-NORTH', 'EU NORTH'],
        RegionStandardizationService::REGION_EASTERN_EUROPE => ['EASTERN_EUROPE', 'EASTERN-EUROPE', 'EASTERN EUROPE', 'EE'],
        RegionStandardizationService::REGION_US_EAST => ['US_EAST', 'US-EAST', 'US EAST'],
        RegionStandardizationService::REGION_US_CENTRAL => ['US_CENTRAL', 'US-CENTRAL', 'US CENTRAL'],
        RegionStandardizationService::REGION_US_SOUTH => ['US_SOUTH', 'US-SOUTH', 'US SOUTH'],
        RegionStandardizationService::REGION_US_WEST => ['US_WEST', 'US-WEST', 'US WEST'],
        RegionStandardizationService::REGION_CANADA => ['CANADA', 'CA'],
        RegionStandardizationService::REGION_MEXICO => ['MEXICO', 'MX'],
        RegionStandardizationService::REGION_BRAZIL => ['BRAZIL', 'BR'],
        RegionStandardizationService::REGION_LATAM_OTHER => ['LATAM_OTHER', 'LATAM-OTHER', 'LATAM', 'LATIN AMERICA'],
        RegionStandardizationService::REGION_CHINA => ['CHINA', 'CN'],
        RegionStandardizationService::REGION_TAIWAN => ['TAIWAN', 'TW'],
        RegionStandardizationService::REGION_JAPAN => ['JAPAN', 'JP'],
        RegionStandardizationService::REGION_SOUTH_KOREA => ['SOUTH_KOREA', 'SOUTH-KOREA', 'SOUTH KOREA', 'KR'],
        RegionStandardizationService::REGION_INDIA => ['INDIA', 'IN'],
        RegionStandardizationService::REGION_SOUTHEAST_ASIA => ['SOUTHEAST_ASIA', 'SOUTHEAST-ASIA', 'SOUTHEAST ASIA', 'SEA'],
        RegionStandardizationService::REGION_AUSTRALIA_NZ => ['AUSTRALIA_NZ', 'AUSTRALIA-NZ', 'AUSTRALIA & NEW ZEALAND', 'AUSTRALIA AND NEW ZEALAND', 'ANZ', 'AU', 'NZ'],
        RegionStandardizationService::REGION_TURKEY => ['TURKEY', 'TR', 'TÜRKIYE'],
        RegionStandardizationService::REGION_OTHER => ['OTHER', 'UNASSIGNED'],
    ];

    /**
     * Legacy coarse codes the OLD company form stored, resolved to their
     * canonical territory. These remain form-selectable (grouped under a
     * legacy label) so historical rows can be edited without a data
     * migration; new selections prefer canonical values.
     *
     * @var array<string, string>
     */
    private const LEGACY_CODES = [
        'MA' => RegionStandardizationService::REGION_MOROCCO,
        'US' => RegionStandardizationService::REGION_US_EAST,
        'EU' => RegionStandardizationService::REGION_EU_WEST,
        'GB' => RegionStandardizationService::REGION_UK,
        'EG' => RegionStandardizationService::REGION_EGYPT,
        'GCC' => RegionStandardizationService::REGION_MIDDLE_EAST,
    ];

    /**
     * All territories, canonical value => translation key.
     *
     * @return array<string, string>
     */
    public static function territories(): array
    {
        $out = [];
        foreach (self::TERRITORIES as $value => $label) {
            $out[$value] = 'region.territory.' . $value;
        }

        return $out;
    }

    /**
     * Form choices: the full canonical territory list, then the legacy
     * coarse codes (labeled legacy) so stored historical values remain
     * editable. Symfony choice VALUE (stored) is the key on the right.
     *
     * @return array<string, array<string, string>>
     */
    public static function formChoices(): array
    {
        $canonical = [];
        foreach (self::territories() as $value => $key) {
            $canonical['region.territory.' . $value] = $value;
        }

        $legacy = [];
        foreach (self::LEGACY_CODES as $code => $territory) {
            $legacy['region.legacy.' . strtolower($code)] = $code;
        }

        return [
            'region.group.canonical' => $canonical,
            'region.group.legacy' => $legacy,
        ];
    }

    /**
     * Human label for a STORED value (any vocabulary): canonical territory,
     * legacy coarse code, or the raw value as last resort.
     */
    public static function labelKeyFor(?string $stored): ?string
    {
        if ($stored === null || trim($stored) === '') {
            return null;
        }
        $stored = trim($stored);

        if (isset(self::TERRITORIES[$stored])) {
            return 'region.territory.' . $stored;
        }
        $lower = strtolower($stored);
        if (isset(self::TERRITORIES[$lower])) {
            return 'region.territory.' . $lower;
        }
        $upper = strtoupper($stored);
        if (isset(self::LEGACY_CODES[$upper])) {
            return 'region.legacy.' . strtolower($upper);
        }

        return null;
    }

    /**
     * Is this stored value a canonical territory or a legacy code?
     */
    public static function isKnown(?string $stored): bool
    {
        return self::labelKeyFor($stored) !== null;
    }

    /**
     * All stored tokens (UPPERCASED) equivalent to the given selection —
     * the selection itself (any vocabulary), plus every alias of the
     * resolved canonical territory, plus any legacy codes that map to it.
     *
     * @return list<string>|null null when the selection is not a known
     *                           territory (caller falls back to raw matching)
     */
    public static function filterTokensFor(string $selected): ?array
    {
        $selected = trim($selected);
        if ($selected === '') {
            return null;
        }

        $territory = null;
        if (isset(self::TERRITORIES[$selected]) || isset(self::TERRITORIES[strtolower($selected)])) {
            $territory = isset(self::TERRITORIES[$selected]) ? $selected : strtolower($selected);
        } elseif (isset(self::LEGACY_CODES[strtoupper($selected)])) {
            $territory = self::LEGACY_CODES[strtoupper($selected)];
        }

        if ($territory === null) {
            return null;
        }

        $tokens = [$territory];
        foreach (self::ALIASES[$territory] ?? [] as $alias) {
            $tokens[] = $alias;
        }
        // Legacy codes that resolve to this territory.
        foreach (self::LEGACY_CODES as $code => $mapsTo) {
            if ($mapsTo === $territory) {
                $tokens[] = $code;
            }
        }

        /** @var list<string> */
        return array_values(array_unique(array_map('strtoupper', $tokens)));
    }
}
