<?php

namespace App\Tests;

use App\Service\WebCrawler\Text\TextNormalizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests for the Text Normalization Layer (Improvement 3A).
 */
class TextNormalizerTest extends KernelTestCase
{
    private TextNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new TextNormalizer();
    }

    // ── Basic normalization ──────────────────────────────────────

    public function testEmptyString(): void
    {
        $this->assertSame('', $this->normalizer->normalize(''));
    }

    public function testPlainText(): void
    {
        $this->assertSame('hello world', $this->normalizer->normalize('Hello World'));
    }

    public function testPreserveCaseWhenRequested(): void
    {
        $this->assertSame('Hello World', $this->normalizer->normalize('Hello World', lowercase: false));
    }

    // ── Unicode NFKC ────────────────────────────────────────────

    public function testNfkcLigatures(): void
    {
        // ﬁ → fi, ﬂ → fl
        $this->assertSame('file flow', $this->normalizer->normalize("ﬁle ﬂow"));
    }

    public function testNfkcFractions(): void
    {
        // ½ NFKC → 1⁄2 (with fraction slash U+2044) — verify it decomposes
        $result = $this->normalizer->normalize("½ inch");
        // After NFKC, ½ becomes 1⁄2 (with fraction slash) which is acceptable
        $this->assertStringContainsString('1', $result);
        $this->assertStringContainsString('2', $result);
        $this->assertStringContainsString('inch', $result);
    }

    public function testNfkcSuperscript(): void
    {
        // ² → 2, ³ → 3
        $result = $this->normalizer->normalize("m² area");
        $this->assertSame('m2 area', $result);
    }

    // ── HTML entities ───────────────────────────────────────────

    public function testHtmlEntityDecode(): void
    {
        $this->assertSame('r&d department', $this->normalizer->normalize('R&amp;D Department'));
    }

    public function testNestedHtmlEntities(): void
    {
        $this->assertSame('r&d', $this->normalizer->normalize('R&amp;amp;D'));
    }

    public function testHtmlNumericEntities(): void
    {
        // &#8220; = left double quotation mark → "
        $this->assertStringContainsString('"', $this->normalizer->normalize('&#8220;quoted&#8221;'));
    }

    // ── Zero-width characters ───────────────────────────────────

    public function testZeroWidthSpaceRemoval(): void
    {
        $this->assertSame('manufacturing', $this->normalizer->normalize("manufac\u{200B}turing"));
    }

    public function testSoftHyphenRemoval(): void
    {
        $this->assertSame('manufacturing', $this->normalizer->normalize("manu\u{00AD}facturing"));
    }

    public function testBomRemoval(): void
    {
        $this->assertSame('test', $this->normalizer->normalize("\u{FEFF}test"));
    }

    // ── Arabic diacritics ───────────────────────────────────────

    public function testArabicTashkeelRemoval(): void
    {
        // مُصَنَّع (mu-san-na') → مصنع (manufacturer)
        $withTashkeel = "\u{0645}\u{064F}\u{0635}\u{064E}\u{0646}\u{064E}\u{0651}\u{0639}";
        $withoutTashkeel = "\u{0645}\u{0635}\u{0646}\u{0639}";
        $this->assertSame($withoutTashkeel, $this->normalizer->normalize($withTashkeel, lowercase: false));
    }

    public function testArabicMixedText(): void
    {
        // "شركة الصناعات" with shadda on ص
        $text = "company \u{0634}\u{0650}\u{0631}\u{0643}\u{0629}";
        $result = $this->normalizer->normalize($text, lowercase: false);
        // Should strip the kasra (\u{0650}) but keep the letters
        $this->assertSame("company \u{0634}\u{0631}\u{0643}\u{0629}", $result);
    }

    // ── Smart quotes ────────────────────────────────────────────

    public function testSmartDoubleQuotes(): void
    {
        $this->assertSame('"quoted"', $this->normalizer->normalize("\u{201C}quoted\u{201D}"));
    }

    public function testSmartSingleQuotes(): void
    {
        $this->assertSame("it's", $this->normalizer->normalize("it\u{2019}s"));
    }

    public function testGuillemets(): void
    {
        $this->assertSame('"bonjour"', $this->normalizer->normalize("\u{00AB}Bonjour\u{00BB}"));
    }

    // ── Dashes ──────────────────────────────────────────────────

    public function testEmDash(): void
    {
        $this->assertSame('a - b', $this->normalizer->normalize("A \u{2014} B"));
    }

    public function testEnDash(): void
    {
        $this->assertSame('2020-2025', $this->normalizer->normalize("2020\u{2013}2025"));
    }

    // ── Whitespace ──────────────────────────────────────────────

    public function testMultipleSpaces(): void
    {
        $this->assertSame('a b c', $this->normalizer->normalize('a   b    c'));
    }

    public function testNonBreakingSpace(): void
    {
        $this->assertSame('10 000', $this->normalizer->normalize("10\u{00A0}000"));
    }

    public function testTabsAndNewlines(): void
    {
        $this->assertSame('line one line two', $this->normalizer->normalize("line one\n\tline two"));
    }

    // ── Domain normalization ────────────────────────────────────

    public function testDomainLowercase(): void
    {
        $this->assertSame('example.com', $this->normalizer->normalizeDomain('EXAMPLE.COM'));
    }

    public function testDomainStripWww(): void
    {
        $this->assertSame('example.com', $this->normalizer->normalizeDomain('www.example.com'));
    }

    public function testDomainStripTrailingDot(): void
    {
        $this->assertSame('example.com', $this->normalizer->normalizeDomain('example.com.'));
    }

    public function testDomainPunycode(): void
    {
        if (!function_exists('idn_to_utf8')) {
            $this->markTestSkipped('intl extension not available');
        }
        // xn--e1afmapc.xn--p1ai = пример.рф (Russian domain)
        $result = $this->normalizer->normalizeDomain('xn--e1afmapc.xn--p1ai');
        $this->assertNotSame('xn--e1afmapc.xn--p1ai', $result);
    }

    // ── Company name normalization ──────────────────────────────

    public function testCompanyNameStripSuffix(): void
    {
        $this->assertSame('siemens energy', $this->normalizer->normalizeCompanyName('Siemens Energy AG'));
    }

    public function testCompanyNameStripLtd(): void
    {
        $this->assertSame('rolls-royce', $this->normalizer->normalizeCompanyName('Rolls-Royce Ltd.'));
    }

    public function testCompanyNameStripPunctuation(): void
    {
        $this->assertSame('acme corp', $this->normalizer->normalizeCompanyName('ACME Corp.,'));
    }

    public function testCompanyNameArabicSuffix(): void
    {
        $this->assertSame('test company', $this->normalizer->normalizeCompanyName('Test Company SARL'));
    }

    // ── Container wiring ────────────────────────────────────────

    public function testServiceCanBeInstantiated(): void
    {
        // TextNormalizer has no dependencies — just verify it works standalone
        $normalizer = new TextNormalizer();
        $this->assertSame('test', $normalizer->normalize('TEST'));
    }
}
