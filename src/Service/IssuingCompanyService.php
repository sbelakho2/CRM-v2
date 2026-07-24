<?php

declare(strict_types=1);

namespace App\Service;

/**
 * IssuingCompanyService — manages the Starz group company profiles
 * 
 * Provides company identity data for quotes, PDFs, emails, and compliance docs.
 * Three operating entities:
 *   - Starz Morocco (Morocco)       → starz_morocco
 *   - Starz Electronics (Tunisia)   → starz_electronics
 *   - Starz Energies (United States) → starz_energies
 */
class IssuingCompanyService
{
    /** @var array<string, array<string, string>> */
    private const COMPANIES = [
        'starz_morocco' => [
            'name'     => 'Starz Morocco',
            'country'  => 'Morocco',
            'location' => 'Casablanca, Morocco',
            'email'    => 'sales@starzelectronics.site',
            'website'  => 'starzelectronics.site',
            'currency' => 'USD',
        ],
        'starz_electronics' => [
            'name'     => 'Starz Electronics',
            'country'  => 'Tunisia',
            'location' => 'Tunis, Tunisia',
            'email'    => 'sales@starzelectronics.site',
            'website'  => 'starzelectronics.site',
            'currency' => 'USD',
        ],
        'starz_energies' => [
            'name'     => 'Starz Energies',
            'country'  => 'United States',
            'location' => 'United States',
            'email'    => 'sales@starzenergies.com',
            'website'  => 'starzenergies.com',
            'currency' => 'USD',
        ],
    ];

    public const DEFAULT_COMPANY = 'starz_morocco';

    /**
     * Get the full profile for an issuing company.
     *
     * @param string|null $key Company key (starz_morocco, starz_electronics, starz_energies)
     * @return array{name: string, country: string, location: string, email: string, website: string, currency: string}
     */
    public function getCompanyProfile(?string $key = null): array
    {
        $key = $key ?? self::DEFAULT_COMPANY;

        return self::COMPANIES[$key] ?? self::COMPANIES[self::DEFAULT_COMPANY];
    }

    /**
     * Get all available issuing companies.
     *
     * @return array<string, array<string, string>>
     */
    public function getAllCompanies(): array
    {
        return self::COMPANIES;
    }

    /**
     * Get a simple label → key map for form dropdowns.
     *
     * @return array<string, string> e.g. ["Starz Morocco (Morocco)" => "starz_morocco", ...]
     */
    public function getCompanyChoices(): array
    {
        $choices = [];
        foreach (self::COMPANIES as $key => $profile) {
            $choices[sprintf('%s (%s)', $profile['name'], $profile['country'])] = $key;
        }
        return $choices;
    }

    /**
     * Validate that a company key is known.
     */
    public function isValidCompany(?string $key): bool
    {
        return $key !== null && isset(self::COMPANIES[$key]);
    }

    /**
     * Get just the company name.
     */
    public function getCompanyName(?string $key = null): string
    {
        return $this->getCompanyProfile($key)['name'];
    }
}
