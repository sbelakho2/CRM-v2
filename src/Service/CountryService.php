<?php

namespace App\Service;

/**
 * Country Service - Manages country data for quotes, freight, and compliance
 * 
 * Provides ISO 3166-1 alpha-2 country codes and names for:
 * - Quote destination selection
 * - Freight routing calculations
 * - Trade compliance (FTA, duty calculations)
 */
class CountryService
{
    /**
     * Get list of supported destination countries
     * 
     * Uses ISO 3166-1 alpha-2 standard country codes
     * 
     * @return array Associative array of country codes => names
     */
    public function getCountryList(): array
    {
        return [
            // North America
            'US' => 'United States',
            'CA' => 'Canada',
            'MX' => 'Mexico',
            
            // Europe
            'FR' => 'France',
            'DE' => 'Germany',
            'GB' => 'United Kingdom',
            'IT' => 'Italy',
            'ES' => 'Spain',
            'NL' => 'Netherlands',
            'BE' => 'Belgium',
            'PL' => 'Poland',
            'SE' => 'Sweden',
            'NO' => 'Norway',
            'CH' => 'Switzerland',
            'AT' => 'Austria',
            'IE' => 'Ireland',
            'DK' => 'Denmark',
            'FI' => 'Finland',
            'PT' => 'Portugal',
            'CZ' => 'Czech Republic',
            'GR' => 'Greece',
            'HU' => 'Hungary',
            'RO' => 'Romania',
            
            // Middle East
            'AE' => 'United Arab Emirates',
            'SA' => 'Saudi Arabia',
            'IL' => 'Israel',
            'TR' => 'Turkey',
            'QA' => 'Qatar',
            'KW' => 'Kuwait',
            'OM' => 'Oman',
            'JO' => 'Jordan',
            'LB' => 'Lebanon',
            'BH' => 'Bahrain',
            
            // Asia Pacific
            'CN' => 'China',
            'JP' => 'Japan',
            'KR' => 'South Korea',
            'IN' => 'India',
            'SG' => 'Singapore',
            'MY' => 'Malaysia',
            'TH' => 'Thailand',
            'VN' => 'Vietnam',
            'ID' => 'Indonesia',
            'PH' => 'Philippines',
            'TW' => 'Taiwan',
            'HK' => 'Hong Kong',
            'AU' => 'Australia',
            'NZ' => 'New Zealand',
            
            // Africa
            'MA' => 'Morocco',
            'ZA' => 'South Africa',
            'EG' => 'Egypt',
            'NG' => 'Nigeria',
            'KE' => 'Kenya',
            'TN' => 'Tunisia',
            'DZ' => 'Algeria',
            
            // South America
            'BR' => 'Brazil',
            'AR' => 'Argentina',
            'CL' => 'Chile',
            'CO' => 'Colombia',
            'PE' => 'Peru',
            'VE' => 'Venezuela',
        ];
    }
    
    /**
     * Get country name by ISO code
     * 
     * @param string $code ISO 3166-1 alpha-2 country code
     * @return string|null Country name or null if not found
     */
    public function getCountryName(string $code): ?string
    {
        $countries = $this->getCountryList();
        return $countries[strtoupper($code)] ?? null;
    }
    
    /**
     * Check if country code is valid
     * 
     * @param string $code ISO 3166-1 alpha-2 country code
     * @return bool True if valid country code
     */
    public function isValidCountry(string $code): bool
    {
        return isset($this->getCountryList()[strtoupper($code)]);
    }
    
    /**
     * Get countries by region
     * 
     * @param string $region Region name (north_america, europe, asia_pacific, middle_east, africa, south_america)
     * @return array Filtered country list
     */
    public function getCountriesByRegion(string $region): array
    {
        $regionMap = [
            'north_america' => ['US', 'CA', 'MX'],
            'europe' => ['FR', 'DE', 'GB', 'IT', 'ES', 'NL', 'BE', 'PL', 'SE', 'NO', 'CH', 'AT', 'IE', 'DK', 'FI', 'PT', 'CZ', 'GR', 'HU', 'RO'],
            'middle_east' => ['AE', 'SA', 'IL', 'TR', 'QA', 'KW', 'OM', 'JO', 'LB', 'BH'],
            'asia_pacific' => ['CN', 'JP', 'KR', 'IN', 'SG', 'MY', 'TH', 'VN', 'ID', 'PH', 'TW', 'HK', 'AU', 'NZ'],
            'africa' => ['MA', 'ZA', 'EG', 'NG', 'KE', 'TN', 'DZ'],
            'south_america' => ['BR', 'AR', 'CL', 'CO', 'PE', 'VE'],
        ];
        
        $codes = $regionMap[$region] ?? [];
        $allCountries = $this->getCountryList();
        
        return array_filter(
            $allCountries,
            fn($code) => in_array($code, $codes),
            ARRAY_FILTER_USE_KEY
        );
    }
}
