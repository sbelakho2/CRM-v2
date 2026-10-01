<?php

namespace App\Tests\Unit\Service\Geo;

use App\Service\Geo\RegionCatalog;
use App\Service\RegionStandardizationService;
use PHPUnit\Framework\TestCase;

/**
 * Round-9: the canonical region catalog is the single source for territory
 * selection everywhere (company form, company filter, region display).
 * These expectations are the consistency contract: complete availability,
 * one name per territory, and legacy-vocabulary compatibility — stored
 * data is never rewritten, so old codes MUST keep resolving.
 */
class RegionCatalogTest extends TestCase
{
    public function testCatalogCoversEveryStandardizedTerritory(): void
    {
        $territories = RegionCatalog::territories();

        foreach (RegionStandardizationService::VALID_REGIONS as $valid) {
            $this->assertArrayHasKey(
                $valid,
                $territories,
                "standardized territory {$valid} must be selectable — no missing regions"
            );
        }
        $this->assertCount(count(RegionStandardizationService::VALID_REGIONS), $territories, 'no extra/undefined territories');
    }

    public function testEveryTerritoryHasATranslationKeyAndAliases(): void
    {
        foreach (RegionCatalog::territories() as $value => $labelKey) {
            $this->assertStringStartsWith('region.territory.', $labelKey);
            $this->assertNotEmpty(RegionCatalog::filterTokensFor($value), "territory {$value} must match itself");
        }
    }

    public function testLegacyCoarseCodesResolveToCanonicalTerritories(): void
    {
        foreach (['MA', 'US', 'EU', 'GB', 'EG', 'GCC'] as $legacy) {
            $tokens = RegionCatalog::filterTokensFor($legacy);
            $this->assertNotNull($tokens, "legacy code {$legacy} must resolve");
            $this->assertContains($legacy, $tokens, "the legacy code itself must match stored rows");
        }

        // 'MA' must match both the old code and the canonical slug.
        $ma = RegionCatalog::filterTokensFor('MA');
        $this->assertContains('MOROCCO', $ma);
        $this->assertContains('MA', $ma);
    }

    public function testCanonicalSlugMatchesLegacyCodeBothWays(): void
    {
        $uk = RegionCatalog::filterTokensFor('uk');
        $this->assertContains('GB', $uk, 'selecting the canonical UK territory must surface rows stored as GB');
        $this->assertContains('UNITED KINGDOM', $uk);

        $gcc = RegionCatalog::filterTokensFor('GCC');
        $this->assertContains('MIDDLE_EAST', $gcc, 'legacy GCC selection must surface the canonical Middle East territory');
    }

    public function testUnknownSelectionReturnsNullForRawMatching(): void
    {
        $this->assertNull(RegionCatalog::filterTokensFor('NOWHERE-XYZ'));
        $this->assertNull(RegionCatalog::filterTokensFor(''));
        $this->assertNull(RegionCatalog::filterTokensFor('  '));
    }

    public function testLabelKeyResolutionAcrossVocabularies(): void
    {
        $this->assertSame('region.territory.morocco', RegionCatalog::labelKeyFor('morocco'));
        $this->assertSame('region.territory.morocco', RegionCatalog::labelKeyFor('Morocco'));
        $this->assertSame('region.legacy.ma', RegionCatalog::labelKeyFor('MA'));
        $this->assertNull(RegionCatalog::labelKeyFor('NOWHERE-XYZ'));
        $this->assertNull(RegionCatalog::labelKeyFor(null));
        $this->assertNull(RegionCatalog::labelKeyFor('  '));
    }

    public function testFormChoicesGroupCanonicalAndLegacy(): void
    {
        $choices = RegionCatalog::formChoices();

        $this->assertSame(['region.group.canonical', 'region.group.legacy'], array_keys($choices));
        // Every standardized territory selectable by VALUE.
        foreach (RegionStandardizationService::VALID_REGIONS as $valid) {
            $this->assertContains($valid, $choices['region.group.canonical']);
        }
        // The 6 legacy coarse codes remain submittable so old rows edit cleanly.
        foreach (['MA', 'US', 'EU', 'GB', 'EG', 'GCC'] as $legacy) {
            $this->assertContains($legacy, $choices['region.group.legacy']);
        }
    }
}
