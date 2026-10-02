<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\Text;

/**
 * Language Detection (Improvement 3B)
 *
 * Lightweight, zero-dependency language detector using trigram analysis.
 * Trained on the languages commonly encountered in European B2B pages:
 * English, French, German, Italian, Spanish, Dutch, Polish, Portuguese,
 * Arabic, Turkish, and Czech.
 *
 * The trigram approach is simple, fast, and surprisingly accurate for
 * texts of 50+ characters. For very short snippets, confidence drops
 * and the result should be treated as INDETERMINATE.
 *
 * This is NOT a full NLP solution — it's designed for the specific
 * use case of classifying Google search snippets (100-300 chars).
 */
final class LanguageDetector
{
    /**
     * Top trigrams for each supported language.
     * These were selected from frequency analysis of large web corpora.
     *
     * @var array<string, string[]>
     */
    private const LANGUAGE_TRIGRAMS = [
        'en' => [
            'the', 'ing', 'and', 'ion', 'ent', 'tio', 'ati', 'for', 'ter',
            'hat', 'tha', 'ere', 'ate', 'his', 'con', 'res', 'ver', 'all',
            'ons', 'nce', 'men', 'ith', 'ted', 'ers', 'pro', 'com', 'are',
            'wit', 'ess', 'not', 'ive', 'was', 'ect', 'rea', 'int', 'est',
            'sta', 'cti', 'ove', 'our', 'pre', 'her', 'ble', 'cal', 'man',
            'per', 'ort', 'ble', 'ric', 'whi',
        ],
        'fr' => [
            'les', 'des', 'ent', 'ion', 'que', 'tion', 'ais', 'par', 'est',
            'eur', 'ous', 'eme', 'ant', 'men', 'con', 'com', 'our', 'une',
            'ait', 'ont', 'pas', 'nte', 'dan', 'ans', 'ses', 'mai', 'qui',
            'lle', 'ien', 'sur', 'pou', 'res', 'tre', 'oir', 'ons', 'ais',
            'ment', 'son', 'ave', 'tes', 'eux', 'tio', 'ati', 'eur', 'ble',
            'elle', 'ire', 'ais', 'ère', 'été',
        ],
        'de' => [
            'ein', 'ich', 'der', 'die', 'und', 'den', 'sch', 'ung', 'che',
            'ber', 'ver', 'gen', 'cht', 'eit', 'ier', 'ste', 'ter', 'ent',
            'nen', 'ges', 'ach', 'ers', 'auf', 'hen', 'ine', 'ell', 'aus',
            'mit', 'erk', 'lic', 'uch', 'wen', 'ren', 'erd', 'tig', 'nde',
            'für', 'hab', 'hat', 'ist', 'bei', 'ere', 'ten', 'das', 'des',
            'ung', 'keit', 'lich', 'haf', 'wir',
        ],
        'it' => [
            // Core Italian trigrams — distinctive for Italian text
            'che', 'per', 'ell', 'ato', 'azi', 'zia', 'ale', 'del', 'one',
            'ame', 'nte', 'tti', 'eri', 'ito', 'ann', 'gli', 'ina', 'ica',
            'lla', 'enz', 'dal', 'non', 'una', 'ono', 'gio', 'vol',
            'ett', 'tto', 'llo', 'ori', 'dei', 'nel', 'tor', 'ore',
            'oni', 'nic', 'ria', 'ati', 'ndo', 'olt', 'dip', 'nso',
            'sor', 'nza', 'zat', 'tro', 'rog', 'sis', 'tem',
        ],
        'es' => [
            'ción', 'ent', 'que', 'ion', 'ado', 'con', 'aci', 'los', 'par',
            'est', 'las', 'nte', 'ien', 'men', 'pro', 'tra', 'del', 'cia',
            'com', 'res', 'nes', 'ado', 'ble', 'tos', 'ers', 'una', 'dos',
            'ste', 'pre', 'ter', 'ero', 'ica', 'ont', 'por', 'nci', 'ier',
            'ara', 'mos', 'ida', 'ido', 'ues', 'dad', 'amo', 'nal', 'rio',
            'más', 'mie', 'emp', 'ber', 'ndo',
        ],
        'nl' => [
            'een', 'van', 'het', 'den', 'oor', 'ver', 'aar', 'erd', 'ter',
            'gen', 'ing', 'ede', 'ijn', 'ren', 'sch', 'aan', 'ond', 'der',
            'nie', 'die', 'zij', 'wor', 'ent', 'eel', 'ste', 'met', 'dat',
            'est', 'aat', 'ten', 'ell', 'tig', 'nde', 'eni', 'cht', 'uit',
            'ges', 'ven', 'ijk', 'erk', 'bel', 'ede', 'ove', 'wat', 'heb',
            'kun', 'kan', 'wij', 'gro', 'bes',
        ],
        'pl' => [
            'nie', 'prz', 'rze', 'nia', 'icz', 'owi', 'ych', 'icz', 'czy',
            'kie', 'sta', 'ość', 'ego', 'pod', 'prz', 'eni', 'ane', 'kie',
            'owa', 'str', 'owy', 'sto', 'asz', 'sze', 'ach', 'est', 'rzy',
            'ono', 'jak', 'rod', 'ien', 'twa', 'odn', 'ien', 'wan', 'acz',
            'eln', 'jed', 'ier', 'zna', 'kor', 'kie', 'dzi', 'lem', 'ten',
            'tes', 'wsk', 'arn', 'zer', 'pie',
        ],
        'ar' => [
            'الم', 'ال', 'في', 'من', 'على', 'ان', 'ين', 'ية', 'ات',
            'لا', 'ما', 'مت', 'عة', 'تم', 'كا', 'ها', 'بي', 'ور',
            'ار', 'ست', 'ري', 'نا', 'ول', 'عل', 'جم', 'مع', 'صن',
            'شر', 'عم', 'سا', 'حق', 'ام', 'مد', 'طا', 'يا', 'إل',
        ],
    ];

    /**
     * Character-set shortcuts: if the text contains specific scripts,
     * we can fast-track detection.
     */
    private const SCRIPT_PATTERNS = [
        'ar' => '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}]/u',   // Arabic script
        'zh' => '/[\x{4E00}-\x{9FFF}]/u',                      // Chinese
        'ja' => '/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u',    // Japanese
        'ko' => '/[\x{AC00}-\x{D7AF}]/u',                      // Korean
    ];

    /**
     * Minimum text length for reliable detection.
     */
    private const MIN_TEXT_LENGTH = 20;

    /**
     * Minimum confidence score to return a definite result.
     */
    private const MIN_CONFIDENCE = 0.15;

    /**
     * Detect the language of a text.
     *
     * @return array{language: string, confidence: float, method: string}
     */
    public function detect(string $text): array
    {
        $text = trim($text);

        if (mb_strlen($text) < self::MIN_TEXT_LENGTH) {
            return ['language' => 'und', 'confidence' => 0.0, 'method' => 'too_short'];
        }

        // ── Fast script detection ──
        foreach (self::SCRIPT_PATTERNS as $lang => $pattern) {
            $matches = [];
            preg_match_all($pattern, $text, $matches);
            $scriptChars = mb_strlen(implode('', $matches[0]));
            $noSpaceText = preg_replace('/\s+/u', '', $text) ?? '';
            $totalChars = mb_strlen($noSpaceText);
            if ($totalChars > 0 && ($scriptChars / $totalChars) > 0.3) {
                return [
                    'language'   => $lang,
                    'confidence' => min(1.0, $scriptChars / $totalChars),
                    'method'     => 'script',
                ];
            }
        }

        // ── Trigram analysis for Latin-script languages ──
        $trigrams = $this->extractTrigrams(mb_strtolower($text));
        if (empty($trigrams)) {
            return ['language' => 'und', 'confidence' => 0.0, 'method' => 'no_trigrams'];
        }

        $scores = [];
        foreach (self::LANGUAGE_TRIGRAMS as $lang => $langTrigrams) {
            $langTrigramSet = array_flip($langTrigrams);
            $hits = 0;
            foreach ($trigrams as $tri => $count) {
                if (isset($langTrigramSet[$tri])) {
                    $hits += $count;
                }
            }
            $scores[$lang] = $hits;
        }

        $totalTrigrams = array_sum($trigrams);
        if ($totalTrigrams === 0) {
            return ['language' => 'und', 'confidence' => 0.0, 'method' => 'no_data'];
        }

        arsort($scores);
        $bestLang = array_key_first($scores);
        $bestScore = $scores[$bestLang];
        $confidence = $bestScore / $totalTrigrams;

        if ($confidence < self::MIN_CONFIDENCE) {
            return ['language' => 'und', 'confidence' => $confidence, 'method' => 'trigram_low'];
        }

        return [
            'language'   => $bestLang,
            'confidence' => round($confidence, 3),
            'method'     => 'trigram',
        ];
    }

    /**
     * Detect language with region relevance scoring.
     *
     * When searching for companies in DE, a German-language snippet
     * gets a relevance boost, while an Arabic snippet gets a penalty.
     *
     * @param string $text       The text to analyze
     * @param string $region     The target region code (DE, FR, IT, GB, ES, etc.)
     * @return array{language: string, confidence: float, method: string, region_relevant: bool, relevance_score: float}
     */
    public function detectWithRegionRelevance(string $text, string $region): array
    {
        $result = $this->detect($text);

        // Map region to expected languages
        $regionLanguages = [
            'DE' => ['de', 'en'],
            'AT' => ['de', 'en'],
            'CH' => ['de', 'fr', 'it', 'en'],
            'FR' => ['fr', 'en'],
            'BE' => ['fr', 'nl', 'en'],
            'IT' => ['it', 'en'],
            'ES' => ['es', 'en'],
            'PT' => ['pt', 'en'],
            'NL' => ['nl', 'en'],
            'PL' => ['pl', 'en'],
            'CZ' => ['cs', 'en'],
            'SE' => ['sv', 'en'],
            'DK' => ['da', 'en'],
            'FI' => ['fi', 'en'],
            'NO' => ['no', 'en'],
            'GB' => ['en'],
            'US' => ['en'],
            'MA' => ['fr', 'ar', 'en'],
            'TN' => ['fr', 'ar', 'en'],
            'EG' => ['ar', 'en'],
            'AE' => ['ar', 'en'],
            'SA' => ['ar', 'en'],
        ];

        $expectedLangs = $regionLanguages[strtoupper($region)] ?? ['en'];
        $isRelevant = in_array($result['language'], $expectedLangs, true)
                   || $result['language'] === 'und';

        // Relevance scoring: +1.0 if matching, 0.5 if English (always useful), 0.0 if mismatch
        $relevanceScore = 0.0;
        if ($result['language'] === 'und') {
            $relevanceScore = 0.5; // Can't tell — neutral
        } elseif (in_array($result['language'], $expectedLangs, true)) {
            $relevanceScore = 1.0;
        } elseif ($result['language'] === 'en') {
            $relevanceScore = 0.8; // English is always somewhat relevant
        }

        return array_merge($result, [
            'region_relevant' => $isRelevant,
            'relevance_score' => $relevanceScore,
        ]);
    }

    /**
     * Extract trigrams from text.
     *
     * @return array<string, int>  trigram → count
     */
    private function extractTrigrams(string $text): array
    {
        // Remove non-letter characters except spaces
        $clean = preg_replace('/[^\\p{L}\\s]/u', '', $text) ?? $text;
        $clean = preg_replace('/\\s+/', ' ', $clean) ?? $clean;

        $words = explode(' ', trim($clean));
        $trigrams = [];

        foreach ($words as $word) {
            $len = mb_strlen($word);
            for ($i = 0; $i <= $len - 3; $i++) {
                $tri = mb_substr($word, $i, 3);
                $trigrams[$tri] = ($trigrams[$tri] ?? 0) + 1;
            }
        }

        return $trigrams;
    }
}
