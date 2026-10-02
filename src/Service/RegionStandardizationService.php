<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Region Standardization Service
 * 
 * Provides consistent region tagging across the system:
 * - Normalizes region names
 * - Maps countries to regions
 * - Handles Morocco free zones specifically
 * - Expanded to 25+ global regions for comprehensive market coverage
 */
class RegionStandardizationService
{
    // Standardized region codes - Expanded for global coverage
    public const REGION_MOROCCO = 'morocco';
    public const REGION_US_EAST = 'us_east';
    public const REGION_US_WEST = 'us_west';
    public const REGION_US_CENTRAL = 'us_central';
    public const REGION_US_SOUTH = 'us_south';
    public const REGION_EU_WEST = 'eu_west';
    public const REGION_EU_CENTRAL = 'eu_central';
    public const REGION_EU_SOUTH = 'eu_south';
    public const REGION_EU_NORTH = 'eu_north';
    public const REGION_UK = 'uk';
    public const REGION_CHINA = 'china';
    public const REGION_TAIWAN = 'taiwan';
    public const REGION_JAPAN = 'japan';
    public const REGION_SOUTH_KOREA = 'south_korea';
    public const REGION_SOUTHEAST_ASIA = 'southeast_asia';
    public const REGION_INDIA = 'india';
    public const REGION_AUSTRALIA_NZ = 'australia_nz';
    public const REGION_MIDDLE_EAST = 'middle_east';
    public const REGION_TUNISIA = 'tunisia';
    public const REGION_EGYPT = 'egypt';
    public const REGION_AFRICA_NORTH = 'africa_north';
    public const REGION_AFRICA_SUB = 'africa_sub';
    public const REGION_MEXICO = 'mexico';
    public const REGION_BRAZIL = 'brazil';
    public const REGION_LATAM_OTHER = 'latam_other';
    public const REGION_CANADA = 'canada';
    public const REGION_TURKEY = 'turkey';
    public const REGION_EASTERN_EUROPE = 'eastern_europe';
    public const REGION_OTHER = 'other';
    
    public const VALID_REGIONS = [
        self::REGION_MOROCCO,
        self::REGION_US_EAST,
        self::REGION_US_WEST,
        self::REGION_US_CENTRAL,
        self::REGION_US_SOUTH,
        self::REGION_EU_WEST,
        self::REGION_EU_CENTRAL,
        self::REGION_EU_SOUTH,
        self::REGION_EU_NORTH,
        self::REGION_UK,
        self::REGION_CHINA,
        self::REGION_TAIWAN,
        self::REGION_JAPAN,
        self::REGION_SOUTH_KOREA,
        self::REGION_SOUTHEAST_ASIA,
        self::REGION_INDIA,
        self::REGION_AUSTRALIA_NZ,
        self::REGION_MIDDLE_EAST,
        self::REGION_TUNISIA,
        self::REGION_EGYPT,
        self::REGION_AFRICA_NORTH,
        self::REGION_AFRICA_SUB,
        self::REGION_MEXICO,
        self::REGION_BRAZIL,
        self::REGION_LATAM_OTHER,
        self::REGION_CANADA,
        self::REGION_TURKEY,
        self::REGION_EASTERN_EUROPE,
        self::REGION_OTHER,
    ];
    
    // Morocco Free Zones
    public const ZONE_TAC = 'TAC';                    // Tanger Automotive City
    public const ZONE_TFZ = 'TFZ';                    // Tanger Free Zone
    public const ZONE_AFZ_KENITRA = 'AFZ_Kenitra';    // Atlantic Free Zone Kenitra
    public const ZONE_AFZ_CASABLANCA = 'AFZ_Casablanca'; // Atlantic Free Zone Casablanca
    public const ZONE_MIDPARC = 'MidParc';            // Casablanca Aerospace Zone
    public const ZONE_NOUACEUR = 'Nouaceur';          // Casablanca
    
    public const MOROCCO_FREE_ZONES = [
        self::ZONE_TAC,
        self::ZONE_TFZ,
        self::ZONE_AFZ_KENITRA,
        self::ZONE_AFZ_CASABLANCA,
        self::ZONE_MIDPARC,
        self::ZONE_NOUACEUR,
    ];
    
    // Human-readable labels - Expanded
    public const REGION_LABELS = [
        self::REGION_MOROCCO => 'Morocco',
        self::REGION_US_EAST => 'US East Coast',
        self::REGION_US_WEST => 'US West Coast',
        self::REGION_US_CENTRAL => 'US Central / Midwest',
        self::REGION_US_SOUTH => 'US South / Texas',
        self::REGION_EU_WEST => 'Western Europe (FR, BE, NL)',
        self::REGION_EU_CENTRAL => 'Central Europe (DE, AT, CH, PL)',
        self::REGION_EU_SOUTH => 'Southern Europe (ES, IT, PT)',
        self::REGION_EU_NORTH => 'Northern Europe (Nordics)',
        self::REGION_UK => 'United Kingdom & Ireland',
        self::REGION_CHINA => 'China',
        self::REGION_TAIWAN => 'Taiwan',
        self::REGION_JAPAN => 'Japan',
        self::REGION_SOUTH_KOREA => 'South Korea',
        self::REGION_SOUTHEAST_ASIA => 'Southeast Asia (VN, TH, MY, SG)',
        self::REGION_INDIA => 'India',
        self::REGION_AUSTRALIA_NZ => 'Australia & New Zealand',
        self::REGION_MIDDLE_EAST => 'Middle East (UAE, SA, IL)',
        self::REGION_TUNISIA => 'Tunisia',
        self::REGION_EGYPT => 'Egypt',
        self::REGION_AFRICA_NORTH => 'North Africa (Other)',
        self::REGION_AFRICA_SUB => 'Sub-Saharan Africa',
        self::REGION_MEXICO => 'Mexico',
        self::REGION_BRAZIL => 'Brazil',
        self::REGION_LATAM_OTHER => 'Latin America (Other)',
        self::REGION_CANADA => 'Canada',
        self::REGION_TURKEY => 'Turkey',
        self::REGION_EASTERN_EUROPE => 'Eastern Europe (UA, RU, BY)',
        self::REGION_OTHER => 'Other',
    ];
    
    // Country to region mapping - Expanded
    private const COUNTRY_MAPPING = [
        // Morocco
        'MA' => self::REGION_MOROCCO,
        'Morocco' => self::REGION_MOROCCO,
        
        // US - Default to East, use US_STATE_REGIONS for specifics
        'US' => self::REGION_US_EAST,
        'United States' => self::REGION_US_EAST,
        
        // UK & Ireland
        'GB' => self::REGION_UK,
        'UK' => self::REGION_UK,
        'United Kingdom' => self::REGION_UK,
        'England' => self::REGION_UK,
        'Scotland' => self::REGION_UK,
        'Wales' => self::REGION_UK,
        'IE' => self::REGION_UK,
        'Ireland' => self::REGION_UK,
        
        // Western Europe
        'FR' => self::REGION_EU_WEST,
        'France' => self::REGION_EU_WEST,
        'BE' => self::REGION_EU_WEST,
        'Belgium' => self::REGION_EU_WEST,
        'NL' => self::REGION_EU_WEST,
        'Netherlands' => self::REGION_EU_WEST,
        'LU' => self::REGION_EU_WEST,
        'Luxembourg' => self::REGION_EU_WEST,
        
        // Central Europe
        'DE' => self::REGION_EU_CENTRAL,
        'Germany' => self::REGION_EU_CENTRAL,
        'AT' => self::REGION_EU_CENTRAL,
        'Austria' => self::REGION_EU_CENTRAL,
        'CH' => self::REGION_EU_CENTRAL,
        'Switzerland' => self::REGION_EU_CENTRAL,
        'PL' => self::REGION_EU_CENTRAL,
        'Poland' => self::REGION_EU_CENTRAL,
        'CZ' => self::REGION_EU_CENTRAL,
        'Czech Republic' => self::REGION_EU_CENTRAL,
        'Czechia' => self::REGION_EU_CENTRAL,
        'HU' => self::REGION_EU_CENTRAL,
        'Hungary' => self::REGION_EU_CENTRAL,
        'SK' => self::REGION_EU_CENTRAL,
        'Slovakia' => self::REGION_EU_CENTRAL,
        'SI' => self::REGION_EU_CENTRAL,
        'Slovenia' => self::REGION_EU_CENTRAL,
        
        // Southern Europe
        'ES' => self::REGION_EU_SOUTH,
        'Spain' => self::REGION_EU_SOUTH,
        'IT' => self::REGION_EU_SOUTH,
        'Italy' => self::REGION_EU_SOUTH,
        'PT' => self::REGION_EU_SOUTH,
        'Portugal' => self::REGION_EU_SOUTH,
        'GR' => self::REGION_EU_SOUTH,
        'Greece' => self::REGION_EU_SOUTH,
        'HR' => self::REGION_EU_SOUTH,
        'Croatia' => self::REGION_EU_SOUTH,
        
        // Northern Europe (Nordics)
        'SE' => self::REGION_EU_NORTH,
        'Sweden' => self::REGION_EU_NORTH,
        'NO' => self::REGION_EU_NORTH,
        'Norway' => self::REGION_EU_NORTH,
        'DK' => self::REGION_EU_NORTH,
        'Denmark' => self::REGION_EU_NORTH,
        'FI' => self::REGION_EU_NORTH,
        'Finland' => self::REGION_EU_NORTH,
        'IS' => self::REGION_EU_NORTH,
        'Iceland' => self::REGION_EU_NORTH,
        
        // China
        'CN' => self::REGION_CHINA,
        'China' => self::REGION_CHINA,
        'HK' => self::REGION_CHINA,
        'Hong Kong' => self::REGION_CHINA,
        
        // Taiwan
        'TW' => self::REGION_TAIWAN,
        'Taiwan' => self::REGION_TAIWAN,
        
        // Japan
        'JP' => self::REGION_JAPAN,
        'Japan' => self::REGION_JAPAN,
        
        // South Korea
        'KR' => self::REGION_SOUTH_KOREA,
        'South Korea' => self::REGION_SOUTH_KOREA,
        'Korea' => self::REGION_SOUTH_KOREA,
        
        // Southeast Asia
        'SG' => self::REGION_SOUTHEAST_ASIA,
        'Singapore' => self::REGION_SOUTHEAST_ASIA,
        'MY' => self::REGION_SOUTHEAST_ASIA,
        'Malaysia' => self::REGION_SOUTHEAST_ASIA,
        'TH' => self::REGION_SOUTHEAST_ASIA,
        'Thailand' => self::REGION_SOUTHEAST_ASIA,
        'VN' => self::REGION_SOUTHEAST_ASIA,
        'Vietnam' => self::REGION_SOUTHEAST_ASIA,
        'PH' => self::REGION_SOUTHEAST_ASIA,
        'Philippines' => self::REGION_SOUTHEAST_ASIA,
        'ID' => self::REGION_SOUTHEAST_ASIA,
        'Indonesia' => self::REGION_SOUTHEAST_ASIA,
        
        // India
        'IN' => self::REGION_INDIA,
        'India' => self::REGION_INDIA,
        
        // Australia & New Zealand
        'AU' => self::REGION_AUSTRALIA_NZ,
        'Australia' => self::REGION_AUSTRALIA_NZ,
        'NZ' => self::REGION_AUSTRALIA_NZ,
        'New Zealand' => self::REGION_AUSTRALIA_NZ,
        
        // Middle East
        'AE' => self::REGION_MIDDLE_EAST,
        'UAE' => self::REGION_MIDDLE_EAST,
        'United Arab Emirates' => self::REGION_MIDDLE_EAST,
        'SA' => self::REGION_MIDDLE_EAST,
        'Saudi Arabia' => self::REGION_MIDDLE_EAST,
        'IL' => self::REGION_MIDDLE_EAST,
        'Israel' => self::REGION_MIDDLE_EAST,
        'QA' => self::REGION_MIDDLE_EAST,
        'Qatar' => self::REGION_MIDDLE_EAST,
        'KW' => self::REGION_MIDDLE_EAST,
        'Kuwait' => self::REGION_MIDDLE_EAST,
        'BH' => self::REGION_MIDDLE_EAST,
        'Bahrain' => self::REGION_MIDDLE_EAST,
        'OM' => self::REGION_MIDDLE_EAST,
        'Oman' => self::REGION_MIDDLE_EAST,
        
        // Turkey
        'TR' => self::REGION_TURKEY,
        'Turkey' => self::REGION_TURKEY,
        'Türkiye' => self::REGION_TURKEY,
        
        // North Africa (excl. Morocco)
        'EG' => self::REGION_AFRICA_NORTH,
        'Egypt' => self::REGION_AFRICA_NORTH,
        'TN' => self::REGION_TUNISIA,
        'Tunisia' => self::REGION_TUNISIA,
        'DZ' => self::REGION_AFRICA_NORTH,
        'Algeria' => self::REGION_AFRICA_NORTH,
        'LY' => self::REGION_AFRICA_NORTH,
        'Libya' => self::REGION_AFRICA_NORTH,
        
        // Sub-Saharan Africa
        'ZA' => self::REGION_AFRICA_SUB,
        'South Africa' => self::REGION_AFRICA_SUB,
        'NG' => self::REGION_AFRICA_SUB,
        'Nigeria' => self::REGION_AFRICA_SUB,
        'KE' => self::REGION_AFRICA_SUB,
        'Kenya' => self::REGION_AFRICA_SUB,
        'GH' => self::REGION_AFRICA_SUB,
        'Ghana' => self::REGION_AFRICA_SUB,
        
        // Canada
        'CA' => self::REGION_CANADA,
        'Canada' => self::REGION_CANADA,
        
        // Mexico
        'MX' => self::REGION_MEXICO,
        'Mexico' => self::REGION_MEXICO,
        
        // Brazil
        'BR' => self::REGION_BRAZIL,
        'Brazil' => self::REGION_BRAZIL,
        
        // Latin America Other
        'AR' => self::REGION_LATAM_OTHER,
        'Argentina' => self::REGION_LATAM_OTHER,
        'CL' => self::REGION_LATAM_OTHER,
        'Chile' => self::REGION_LATAM_OTHER,
        'CO' => self::REGION_LATAM_OTHER,
        'Colombia' => self::REGION_LATAM_OTHER,
        'PE' => self::REGION_LATAM_OTHER,
        'Peru' => self::REGION_LATAM_OTHER,
        
        // Eastern Europe
        'RU' => self::REGION_EASTERN_EUROPE,
        'Russia' => self::REGION_EASTERN_EUROPE,
        'UA' => self::REGION_EASTERN_EUROPE,
        'Ukraine' => self::REGION_EASTERN_EUROPE,
        'BY' => self::REGION_EASTERN_EUROPE,
        'Belarus' => self::REGION_EASTERN_EUROPE,
        'RO' => self::REGION_EASTERN_EUROPE,
        'Romania' => self::REGION_EASTERN_EUROPE,
        'BG' => self::REGION_EASTERN_EUROPE,
        'Bulgaria' => self::REGION_EASTERN_EUROPE,
    ];
    
    // US State to region mapping
    private const US_STATE_REGIONS = [
        // East Coast
        'ME' => self::REGION_US_EAST, 'NH' => self::REGION_US_EAST, 'VT' => self::REGION_US_EAST,
        'MA' => self::REGION_US_EAST, 'RI' => self::REGION_US_EAST, 'CT' => self::REGION_US_EAST,
        'NY' => self::REGION_US_EAST, 'NJ' => self::REGION_US_EAST, 'PA' => self::REGION_US_EAST,
        'DE' => self::REGION_US_EAST, 'MD' => self::REGION_US_EAST, 'DC' => self::REGION_US_EAST,
        'VA' => self::REGION_US_EAST, 'WV' => self::REGION_US_EAST,
        
        // South
        'NC' => self::REGION_US_SOUTH, 'SC' => self::REGION_US_SOUTH, 'GA' => self::REGION_US_SOUTH,
        'FL' => self::REGION_US_SOUTH, 'AL' => self::REGION_US_SOUTH, 'MS' => self::REGION_US_SOUTH,
        'LA' => self::REGION_US_SOUTH, 'TN' => self::REGION_US_SOUTH, 'KY' => self::REGION_US_SOUTH,
        'TX' => self::REGION_US_SOUTH, 'OK' => self::REGION_US_SOUTH, 'AR' => self::REGION_US_SOUTH,
        
        // Central/Midwest
        'OH' => self::REGION_US_CENTRAL, 'IN' => self::REGION_US_CENTRAL, 'IL' => self::REGION_US_CENTRAL,
        'MI' => self::REGION_US_CENTRAL, 'WI' => self::REGION_US_CENTRAL, 'MN' => self::REGION_US_CENTRAL,
        'IA' => self::REGION_US_CENTRAL, 'MO' => self::REGION_US_CENTRAL, 'KS' => self::REGION_US_CENTRAL,
        'NE' => self::REGION_US_CENTRAL, 'SD' => self::REGION_US_CENTRAL, 'ND' => self::REGION_US_CENTRAL,
        
        // West
        'WA' => self::REGION_US_WEST, 'OR' => self::REGION_US_WEST, 'CA' => self::REGION_US_WEST,
        'NV' => self::REGION_US_WEST, 'AZ' => self::REGION_US_WEST, 'UT' => self::REGION_US_WEST,
        'CO' => self::REGION_US_WEST, 'NM' => self::REGION_US_WEST, 'ID' => self::REGION_US_WEST,
        'MT' => self::REGION_US_WEST, 'WY' => self::REGION_US_WEST, 'AK' => self::REGION_US_WEST,
        'HI' => self::REGION_US_WEST,
    ];
    
    /**
     * Normalize a region tag to standard format
     */
    public function normalizeRegion(?string $regionTag): string
    {
        if (!$regionTag) {
            return self::REGION_OTHER;
        }
        
        $normalized = strtolower(trim($regionTag));
        
        // Check if already valid
        if (in_array($normalized, self::VALID_REGIONS)) {
            return $normalized;
        }
        
        // Map legacy tags
        $legacyMapping = [
            'us_texas' => self::REGION_US_SOUTH,
            'eu_core' => self::REGION_EU_CENTRAL,
            'eu_nordics' => self::REGION_EU_NORTH,
            'eu_cee' => self::REGION_EU_CENTRAL,
            'africa' => self::REGION_AFRICA_NORTH,
            'tac' => self::REGION_MOROCCO,
            'tfz' => self::REGION_MOROCCO,
            'kenitra' => self::REGION_MOROCCO,
            'casablanca' => self::REGION_MOROCCO,
        ];
        
        if (isset($legacyMapping[$normalized])) {
            return $legacyMapping[$normalized];
        }
        
        return self::REGION_OTHER;
    }
    
    /**
     * Get region from country code or name
     */
    public function getRegionFromCountry(?string $country): string
    {
        if (!$country) {
            return self::REGION_OTHER;
        }
        
        $normalized = trim($country);
        
        if (isset(self::COUNTRY_MAPPING[$normalized])) {
            return self::COUNTRY_MAPPING[$normalized];
        }
        
        // Try uppercase for country codes
        $upper = strtoupper($normalized);
        if (isset(self::COUNTRY_MAPPING[$upper])) {
            return self::COUNTRY_MAPPING[$upper];
        }
        
        return self::REGION_OTHER;
    }
    
    /**
     * Get region from US state code
     */
    public function getRegionFromUsState(?string $stateCode): string
    {
        if (!$stateCode) {
            return self::REGION_US_EAST; // Default for US
        }
        
        $upper = strtoupper(trim($stateCode));
        
        return self::US_STATE_REGIONS[$upper] ?? self::REGION_US_EAST;
    }
    
    /**
     * Get human-readable label for a region
     */
    public function getRegionLabel(string $region): string
    {
        $normalized = $this->normalizeRegion($region);
        return self::REGION_LABELS[$normalized] ?? 'Unknown';
    }
    
    /**
     * Get all regions with labels
     *
     * @return array<string, string>
     */
    public function getAllRegions(): array
    {
        $regions = [];
        foreach (self::VALID_REGIONS as $code) {
            $regions[$code] = self::REGION_LABELS[$code];
        }
        return $regions;
    }
    
    /**
     * Get Morocco free zones
     *
     * @return list<string>
     */
    public function getMoroccoFreeZones(): array
    {
        return self::MOROCCO_FREE_ZONES;
    }
    
    /**
     * Check if a region is Morocco
     */
    public function isMorocco(string $region): bool
    {
        return $this->normalizeRegion($region) === self::REGION_MOROCCO;
    }
    
    /**
     * Check if a region is EU
     */
    public function isEuropeanUnion(string $region): bool
    {
        $normalized = $this->normalizeRegion($region);
        return in_array($normalized, [
            self::REGION_EU_WEST,
            self::REGION_EU_CENTRAL,
            self::REGION_EU_SOUTH,
            self::REGION_EU_NORTH,
        ]);
    }
    
    /**
     * Check if a region is US
     */
    public function isUnitedStates(string $region): bool
    {
        $normalized = $this->normalizeRegion($region);
        return in_array($normalized, [
            self::REGION_US_EAST,
            self::REGION_US_WEST,
            self::REGION_US_CENTRAL,
            self::REGION_US_SOUTH,
        ]);
    }
    
    /**
     * Get regions by group
     *
     * @return array<string, list<string>>
     */
    public function getRegionsByGroup(): array
    {
        return [
            'Morocco' => [self::REGION_MOROCCO],
            'Europe' => [
                self::REGION_EU_WEST,
                self::REGION_EU_CENTRAL,
                self::REGION_EU_SOUTH,
                self::REGION_EU_NORTH,
                self::REGION_UK,
            ],
            'Americas' => [
                self::REGION_US_EAST,
                self::REGION_US_WEST,
                self::REGION_US_CENTRAL,
                self::REGION_US_SOUTH,
                self::REGION_CANADA,
                self::REGION_MEXICO,
                self::REGION_BRAZIL,
                self::REGION_LATAM_OTHER,
            ],
            'Asia & Other' => [
                self::REGION_CHINA,
                self::REGION_JAPAN,
                self::REGION_SOUTH_KOREA,
                self::REGION_TAIWAN,
                self::REGION_SOUTHEAST_ASIA,
                self::REGION_INDIA,
                self::REGION_AUSTRALIA_NZ,
                self::REGION_MIDDLE_EAST,
                self::REGION_AFRICA_NORTH,
                self::REGION_AFRICA_SUB,
                self::REGION_OTHER,
            ],
        ];
    }
    
    /**
     * Detect region from website URL (TLD-based)
     */
    public function detectRegionFromUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }
        
        $tldMapping = [
            '.ma' => self::REGION_MOROCCO,
            '.de' => self::REGION_EU_CENTRAL,
            '.fr' => self::REGION_EU_WEST,
            '.es' => self::REGION_EU_SOUTH,
            '.it' => self::REGION_EU_SOUTH,
            '.nl' => self::REGION_EU_WEST,
            '.be' => self::REGION_EU_WEST,
            '.uk' => self::REGION_UK,
            '.co.uk' => self::REGION_UK,
            '.se' => self::REGION_EU_NORTH,
            '.no' => self::REGION_EU_NORTH,
            '.dk' => self::REGION_EU_NORTH,
            '.fi' => self::REGION_EU_NORTH,
            '.pl' => self::REGION_EU_CENTRAL,
            '.cz' => self::REGION_EU_CENTRAL,
            '.at' => self::REGION_EU_CENTRAL,
            '.ch' => self::REGION_EU_CENTRAL,
            '.jp' => self::REGION_JAPAN,
            '.cn' => self::REGION_CHINA,
            '.kr' => self::REGION_SOUTH_KOREA,
            '.tw' => self::REGION_TAIWAN,
            '.au' => self::REGION_AUSTRALIA_NZ,
            '.in' => self::REGION_INDIA,
            '.ca' => self::REGION_CANADA,
            '.mx' => self::REGION_MEXICO,
            '.br' => self::REGION_BRAZIL,
        ];
        
        $parsedHost = parse_url($url, PHP_URL_HOST);
        $host = is_string($parsedHost) ? $parsedHost : $url;

        foreach ($tldMapping as $tld => $region) {
            if (str_ends_with(strtolower($host), $tld)) {
                return $region;
            }
        }
        
        // .com is ambiguous, default to null
        return null;
    }
}
