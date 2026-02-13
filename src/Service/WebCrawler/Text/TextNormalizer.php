<?php

namespace App\Service\WebCrawler\Text;

/**
 * Text Normalization Layer (Improvement 3A)
 *
 * All text entering the rule engine MUST pass through this normalizer first.
 * This ensures rules match consistently regardless of encoding, language, or
 * HTML artifacts.
 *
 * Operations (in order):
 *   1. Unicode NFKC normalization (ﬁ → fi, ½ → 1/2, ™ → TM)
 *   2. HTML entity decode (&amp; → &, &#x200B; → "")
 *   3. Zero-width character removal (ZWSP, ZWJ, ZWNJ, soft hyphens)
 *   4. Arabic diacritics removal (tashkeel / harakat)
 *   5. IDN punycode → Unicode domain conversion
 *   6. Smart quote normalization (" " ' ' → " ')
 *   7. Whitespace collapsing (multiple spaces/tabs → single space)
 *   8. Optional: lowercase
 */
class TextNormalizer
{
    /**
     * Unicode zero-width characters to strip.
     */
    private const ZERO_WIDTH = [
        "\u{200B}", // Zero Width Space
        "\u{200C}", // Zero Width Non-Joiner
        "\u{200D}", // Zero Width Joiner
        "\u{FEFF}", // Byte Order Mark / Zero Width No-Break Space
        "\u{00AD}", // Soft Hyphen
        "\u{200E}", // Left-to-Right Mark
        "\u{200F}", // Right-to-Left Mark
        "\u{2060}", // Word Joiner
        "\u{2061}", // Function Application
        "\u{2062}", // Invisible Times
        "\u{2063}", // Invisible Separator
        "\u{2064}", // Invisible Plus
    ];

    /**
     * Arabic tashkeel / diacritics Unicode range.
     * Fathah, Dammah, Kasrah, Sukun, Shadda, Tanwin, etc.
     */
    private const ARABIC_DIACRITICS_PATTERN = '/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06DC}\x{06DF}-\x{06E4}\x{06E7}-\x{06E8}\x{06EA}-\x{06ED}]/u';

    /**
     * Smart quotes → ASCII equivalents.
     */
    private const SMART_QUOTES = [
        "\u{201C}" => '"', // Left Double Quotation Mark
        "\u{201D}" => '"', // Right Double Quotation Mark
        "\u{201E}" => '"', // Double Low-9 Quotation Mark
        "\u{201F}" => '"', // Double High-Reversed-9 Quotation Mark
        "\u{2018}" => "'", // Left Single Quotation Mark
        "\u{2019}" => "'", // Right Single Quotation Mark
        "\u{201A}" => "'", // Single Low-9 Quotation Mark
        "\u{201B}" => "'", // Single High-Reversed-9 Quotation Mark
        "\u{2039}" => "'", // Single Left-Pointing Angle Quotation Mark
        "\u{203A}" => "'", // Single Right-Pointing Angle Quotation Mark
        "\u{00AB}" => '"', // Left-Pointing Double Angle Quotation Mark
        "\u{00BB}" => '"', // Right-Pointing Double Angle Quotation Mark
    ];

    /**
     * Various dash characters → standard hyphen-minus.
     */
    private const DASHES = [
        "\u{2010}" => '-', // Hyphen
        "\u{2011}" => '-', // Non-Breaking Hyphen
        "\u{2012}" => '-', // Figure Dash
        "\u{2013}" => '-', // En Dash
        "\u{2014}" => '-', // Em Dash
        "\u{2015}" => '-', // Horizontal Bar
        "\u{FE58}" => '-', // Small Em Dash
        "\u{FE63}" => '-', // Small Hyphen-Minus
        "\u{FF0D}" => '-', // Fullwidth Hyphen-Minus
    ];

    /**
     * Normalize text for rule matching.
     *
     * @param string $text      Raw input text
     * @param bool   $lowercase Whether to lowercase the result (default true)
     *
     * @return string Normalized text
     */
    public function normalize(string $text, bool $lowercase = true): string
    {
        if ($text === '') {
            return '';
        }

        // 1. Unicode NFKC normalization
        if (function_exists('normalizer_normalize')) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        }

        // 2. HTML entity decode (double-pass for nested entities like &amp;amp;)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 3. Strip zero-width characters
        $text = str_replace(self::ZERO_WIDTH, '', $text);

        // 4. Arabic diacritics removal
        $text = preg_replace(self::ARABIC_DIACRITICS_PATTERN, '', $text) ?? $text;

        // 5. Smart quotes → ASCII
        $text = strtr($text, self::SMART_QUOTES);

        // 6. Dashes → standard hyphen
        $text = strtr($text, self::DASHES);

        // 7. Collapse whitespace (including non-breaking spaces)
        $text = preg_replace('/[\s\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]+/u', ' ', $text) ?? $text;
        $text = trim($text);

        // 8. Lowercase
        if ($lowercase) {
            $text = mb_strtolower($text, 'UTF-8');
        }

        return $text;
    }

    /**
     * Normalize a domain name.
     *
     * Handles:
     *   - IDN punycode → Unicode (xn--nxasmq6b.com → مثال.com)
     *   - Strip www. prefix
     *   - Lowercase
     *   - Trim trailing dots
     *
     * @param string $domain Raw domain
     * @return string Normalized domain
     */
    public function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain, " \t\n\r\0\x0B."));

        // Strip www.
        $domain = preg_replace('/^www\./', '', $domain);

        // IDN punycode → Unicode if intl extension available
        if (function_exists('idn_to_utf8')) {
            $unicode = idn_to_utf8($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($unicode !== false) {
                $domain = $unicode;
            }
        }

        return $domain;
    }

    /**
     * Normalize a company name for comparison.
     *
     * Additional operations beyond standard normalize:
     *   - Strip common legal suffixes (GmbH, LLC, Ltd, etc.)
     *   - Strip trailing punctuation
     *   - Collapse multiple spaces
     *
     * @param string $name Raw company name
     * @return string Normalized name
     */
    public function normalizeCompanyName(string $name): string
    {
        $name = $this->normalize($name, lowercase: true);

        // Strip legal suffixes
        $name = preg_replace(
            '/\s*(gmbh|llc|inc\.?|ltd\.?|corp\.?|s\.?a\.?|s\.?a\.?r\.?l\.?|ag|se|sas|sarl|co\.?|pty|plc|bv|nv|srl|spa|fze|fzc|group|holding|oy|ab|as|aps|ehf|hf|kft|zrt|nyrt|doo|dd|ad)\s*$/i',
            '',
            $name
        ) ?? $name;

        // Strip trailing punctuation
        $name = rtrim($name, ' ,;:.-|/\\()[]{}');

        return trim($name);
    }
}
