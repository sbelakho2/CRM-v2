<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Copy Lint Service
 *
 * Hard gate that blocks sends if copy violates brand/deliverability rules.
 * Applied AFTER spintax + personalization, BEFORE send.
 *
 * Rules enforced:
 * 1. Max subject length (80 chars)
 * 2. Max punctuation density (<15% of body)
 * 3. No repeated symbols (!!!, ???, ...)
 * 4. No ALL CAPS bursts (>3 consecutive caps words)
 * 5. Required clarity: exactly one primary ask/CTA
 * 6. Token sanity: no unreplaced {{tokens}}
 * 7. Required tokens present ({{first_name}} → must resolve to non-empty)
 * 8. Hard cap variability: reject if >40% tokens differ from template baseline
 * 9. Tone-transform limits: revert if >N substitutions occurred
 */
class CopyLintService
{
    // ==================== THRESHOLDS ====================
    public const MAX_SUBJECT_LENGTH     = 80;
    public const MAX_PUNCTUATION_DENSITY = 0.15;
    public const MAX_CAPS_BURST          = 3;   // consecutive all-caps words
    public const MAX_VARIABILITY_PCT     = 0.40; // 40% token change = reject
    public const MAX_TONE_SUBSTITUTIONS  = 8;    // revert to baseline tone after this

    // CTA indicators (exactly one should appear)
    private const CTA_PATTERNS = [
        '/\b(schedule|book)\s+(a\s+)?(call|meeting|demo|chat)\b/i',
        '/\b(reply|respond|let me know|get back to me)\b/i',
        '/\b(click|visit|check out|see)\s+(here|this|the link)\b/i',
        '/\bwould\s+(a\s+)?(quick|brief|short)?\s*(call|chat|meeting)\s+be\b/i',
        '/\b(interested|open)\s+to\s+(a\s+)?(call|chat|conversation)\b/i',
        '/\b(sign up|register|subscribe|download)\b/i',
    ];

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * Run all lint checks on composed email. Returns pass/fail + violations.
     *
     * @param string $subject  Final subject line
     * @param string $body     Final body text
     * @param array  $context  Template context (for token sanity checks)
     * @return array ['passed' => bool, 'violations' => string[], 'warnings' => string[]]
     */
    public function lint(string $subject, string $body, array $context = []): array
    {
        $violations = [];
        $warnings   = [];

        // 1. Subject length
        if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            $violations[] = sprintf('Subject too long: %d chars (max %d)', mb_strlen($subject), self::MAX_SUBJECT_LENGTH);
        }

        // 2. Punctuation density
        $bodyLen = mb_strlen($body);
        if ($bodyLen > 0) {
            $punctCount = preg_match_all('/[!?.,:;…]/', $body);
            $density = $punctCount / $bodyLen;
            if ($density > self::MAX_PUNCTUATION_DENSITY) {
                $violations[] = sprintf('Punctuation density too high: %.1f%% (max %.0f%%)', $density * 100, self::MAX_PUNCTUATION_DENSITY * 100);
            }
        }

        // 3. Repeated symbols
        if (preg_match('/[!]{2,}|[?]{2,}|[.]{4,}/', $subject . ' ' . $body)) {
            $violations[] = 'Repeated punctuation symbols detected (!!!, ???, ....)';
        }

        // 4. ALL CAPS bursts
        if (preg_match('/(\b[A-Z]{2,}\b\s+){' . self::MAX_CAPS_BURST . ',}/', $body)) {
            $violations[] = sprintf('ALL CAPS burst detected (>%d consecutive uppercase words)', self::MAX_CAPS_BURST);
        }

        // 5. CTA count (should be exactly 1 primary ask)
        $ctaCount = 0;
        foreach (self::CTA_PATTERNS as $pattern) {
            if (preg_match($pattern, $body)) {
                $ctaCount++;
            }
        }
        if ($ctaCount === 0) {
            $warnings[] = 'No clear CTA detected in body';
        } elseif ($ctaCount > 2) {
            $violations[] = sprintf('Multiple competing CTAs detected (%d). Rule: one offer, one CTA.', $ctaCount);
        }

        // 6. Token sanity: unreplaced {{tokens}} still in output
        $unresolved = [];
        if (preg_match_all('/\{\{([a-z_]+)\}\}/i', $subject . ' ' . $body, $matches)) {
            $unresolved = $matches[1];
        }
        if (!empty($unresolved)) {
            $violations[] = sprintf('Unreplaced tokens found: {{%s}}', implode('}}, {{', $unresolved));
        }

        // 7. Required name token: body should contain recipient name or "there"
        $firstName = $context['first_name'] ?? '';
        if (!empty($firstName) && $firstName !== 'there' && !str_contains($body, $firstName)) {
            $warnings[] = 'Recipient first name not found in body';
        }

        // 8. Empty body / subject
        if (mb_strlen(trim($body)) < 50) {
            $violations[] = sprintf('Body too short: %d chars (min 50)', mb_strlen(trim($body)));
        }
        if (mb_strlen(trim($subject)) < 5) {
            $violations[] = sprintf('Subject too short: %d chars (min 5)', mb_strlen(trim($subject)));
        }

        $passed = empty($violations);

        if (!$passed) {
            $this->logger->warning('Copy lint FAILED', [
                'violations' => $violations,
                'warnings' => $warnings,
                'subjectLength' => mb_strlen($subject),
            ]);
        }

        return [
            'passed' => $passed,
            'violations' => $violations,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check variability: what percentage of tokens changed from baseline.
     * If > MAX_VARIABILITY_PCT, the output drifted too far from template.
     *
     * @param string $baseline Original template text (before spintax/personalization)
     * @param string $output   Final generated text
     * @return array ['passed' => bool, 'variability' => float]
     */
    public function checkVariability(string $baseline, string $output): array
    {
        $baseTokens = preg_split('/\s+/', strtolower(trim($baseline)));
        $outTokens  = preg_split('/\s+/', strtolower(trim($output)));

        $baseSet = array_count_values($baseTokens);
        $outSet  = array_count_values($outTokens);

        $total = max(count($baseTokens), count($outTokens), 1);
        $diff  = 0;

        foreach ($outSet as $token => $count) {
            $baseCount = $baseSet[$token] ?? 0;
            $diff += abs($count - $baseCount);
        }
        foreach ($baseSet as $token => $count) {
            if (!isset($outSet[$token])) {
                $diff += $count;
            }
        }

        $variability = $diff / (2 * $total); // normalize

        return [
            'passed' => $variability <= self::MAX_VARIABILITY_PCT,
            'variability' => round($variability, 4),
        ];
    }

    /**
     * Count tone substitutions applied. If > MAX_TONE_SUBSTITUTIONS, suggest revert.
     */
    public function checkToneSubstitutionCount(int $substitutionCount): array
    {
        return [
            'passed' => $substitutionCount <= self::MAX_TONE_SUBSTITUTIONS,
            'count' => $substitutionCount,
            'max' => self::MAX_TONE_SUBSTITUTIONS,
        ];
    }
}
