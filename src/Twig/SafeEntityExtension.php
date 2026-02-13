<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension providing safe entity access functions.
 *
 * When a Company (or other referenced entity) has been deleted from the database
 * but is still referenced via a foreign key, Doctrine creates a proxy object.
 * The proxy passes truthiness checks and nullsafe operators (?->), but throws
 * \Doctrine\ORM\EntityNotFoundException when any property is accessed.
 *
 * These functions catch that exception and return a fallback value.
 */
class SafeEntityExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('safe_company_name', [$this, 'safeCompanyName']),
            new TwigFunction('safe_company_id', [$this, 'safeCompanyId']),
        ];
    }

    /**
     * Safely get company name from any entity that has getCompany().
     *
     * Usage in Twig: {{ safe_company_name(quote) }}
     *                {{ safe_company_name(rfq, 'Unknown Client') }}
     */
    public function safeCompanyName(mixed $entity, string $fallback = 'N/A'): string
    {
        if (!$entity || !method_exists($entity, 'getCompany')) {
            return $fallback;
        }

        try {
            $company = $entity->getCompany();
            return $company?->getName() ?? $fallback;
        } catch (\Doctrine\ORM\EntityNotFoundException) {
            return $fallback;
        }
    }

    /**
     * Safely get company ID from any entity that has getCompany().
     *
     * Usage in Twig: {{ safe_company_id(quote) }}
     */
    public function safeCompanyId(mixed $entity): ?int
    {
        if (!$entity || !method_exists($entity, 'getCompany')) {
            return null;
        }

        try {
            return $entity->getCompany()?->getId();
        } catch (\Doctrine\ORM\EntityNotFoundException) {
            return null;
        }
    }
}
