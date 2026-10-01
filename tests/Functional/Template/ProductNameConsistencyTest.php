<?php

namespace App\Tests\Functional\Template;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Round-9: the system is named StarzCRM. Templates must carry the name via
 * the `app.name` translation key (so locale files stay the single source),
 * and the shipped translations must agree on it.
 */
class ProductNameConsistencyTest extends TestCase
{
    private const PRODUCT_NAME = 'StarzCRM';

    private const FORBIDDEN_LEGACY_NAMES = [
        'Starz Morocco CRM',
        'Starz CRM',
        'StarzCRM Morocco',
    ];

    public function testAppKeyIsStarzCrmInEveryLocale(): void
    {
        foreach (['en', 'fr', 'ar'] as $locale) {
            $data = json_decode(
                (string) file_get_contents(__DIR__ . '/../../../translations/messages.' . $locale . '.json'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $this->assertSame(
                self::PRODUCT_NAME,
                $data['app']['name'] ?? null,
                "app.name in {$locale} must be exactly '" . self::PRODUCT_NAME . "'"
            );
        }
    }

    public function testTemplatesDoNotHardcodeLegacyProductNames(): void
    {
        $finder = new Finder();
        $finder->files()->in(__DIR__ . '/../../../templates')->name('*.twig');

        $violations = [];
        foreach ($finder as $file) {
            $content = $file->getContents();
            foreach (self::FORBIDDEN_LEGACY_NAMES as $legacy) {
                if (str_contains($content, $legacy)) {
                    $violations[$file->getRelativePathname()][] = $legacy;
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Templates hardcoding legacy product names — use {{ 'app.name'|trans }}:\n" . print_r($violations, true)
        );
    }
}
