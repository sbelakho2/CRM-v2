<?php

namespace App\Twig;

use App\Service\Geo\RegionCatalog;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Region display: renders a STORED region value (any vocabulary —
 * canonical territory slug, legacy coarse code, or free text) as a
 * translated human label, with the raw value as the last resort.
 */
class RegionExtension extends AbstractExtension
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('region_label', [$this, 'regionLabel']),
        ];
    }

    public function regionLabel(?string $stored): string
    {
        $labelKey = RegionCatalog::labelKeyFor($stored);
        if ($labelKey !== null) {
            return $this->translator->trans($labelKey);
        }

        return (string) $stored;
    }
}
