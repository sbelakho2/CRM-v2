<?php

namespace App\Service;

use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Subdivisions;

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
        $countries = Countries::getNames('en');
        ksort($countries);

        return $countries;
    }

    /**
     * Get US subdivisions (states and territories)
     *
     * @return array Associative array of subdivision codes => names
     */
    public function getUsRegionList(): array
    {
        $regions = Subdivisions::getNames('US', 'en');
        ksort($regions);

        return $regions;
    }

    /**
     * Get combined region options for UI selectors
     *
     * @param array<string, string> $extraOptions
     * @return array<string, string>
     */
    public function getRegionOptions(array $extraOptions = []): array
    {
        $options = $this->getCountryList();

        foreach ($this->getUsRegionList() as $code => $name) {
            $options[$code] = 'United States - ' . $name;
        }

        foreach ($extraOptions as $code => $label) {
            $options[$code] = $label;
        }

        ksort($options);

        return $options;
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
     * Get region name by ISO country code or US subdivision code
     */
    public function getRegionName(?string $codeOrName): ?string
    {
        if ($codeOrName === null) {
            return null;
        }

        $trimmed = trim($codeOrName);
        if ($trimmed === '') {
            return null;
        }

        $upper = strtoupper($trimmed);
        $countries = $this->getCountryList();
        if (isset($countries[$upper])) {
            return $countries[$upper];
        }

        $usRegions = $this->getUsRegionList();
        if (isset($usRegions[$upper])) {
            return 'United States - ' . $usRegions[$upper];
        }

        return $trimmed;
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
     * Check if US subdivision code is valid
     */
    public function isValidUsRegion(string $code): bool
    {
        return isset($this->getUsRegionList()[strtoupper($code)]);
    }

    /**
     * Normalize a region input to ISO country code or US subdivision code
     */
    public function normalizeRegionCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $upper = strtoupper($trimmed);
        if ($this->isValidCountry($upper)) {
            return $upper;
        }

        if ($this->isValidUsRegion($upper)) {
            return $upper;
        }

        $normalized = strtolower($trimmed);
        $normalized = str_replace(['.', ','], '', $normalized);

        $aliases = [
            'usa' => 'US',
            'us' => 'US',
            'u s a' => 'US',
            'u s' => 'US',
            'united states' => 'US',
            'united states of america' => 'US',
            'uk' => 'GB',
            'u k' => 'GB',
            'united kingdom' => 'GB',
        ];

        if (isset($aliases[$normalized])) {
            return $aliases[$normalized];
        }

        foreach ($this->getCountryList() as $code => $name) {
            if (strtolower($name) === $normalized) {
                return $code;
            }
        }

        foreach ($this->getUsRegionList() as $code => $name) {
            if (strtolower($name) === $normalized) {
                return $code;
            }

            if (strtolower('United States - ' . $name) === $normalized) {
                return $code;
            }
        }

        return null;
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
