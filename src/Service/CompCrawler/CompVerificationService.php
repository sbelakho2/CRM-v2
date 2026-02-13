<?php

namespace App\Service\CompCrawler;

use App\Entity\Competitor;
use App\Service\WebCrawler\Text\TextNormalizer;
use Psr\Log\LoggerInterface;

/**
 * CompVerificationService — Type-specific evidence gate for competitor verification.
 *
 * Each competitor type has its own evidence families and veto rules.
 * A domain passes only if it satisfies ≥ min_families evidence families on-domain,
 * and no hard veto fires.
 */
class CompVerificationService
{
    private TextNormalizer $normalizer;

    public function __construct(
        private readonly CompCrawlerConfig $config,
        private readonly LoggerInterface $logger,
    ) {
        $this->normalizer = new TextNormalizer();
    }

    /**
     * Verify a competitor candidate against type-specific evidence gate.
     *
     * @param Competitor $competitor The candidate
     * @param string $homepageText   Full text from homepage/key pages
     * @param array $discoveredUrls  URLs discovered on the domain
     *
     * @return array{passed: bool, families_passed: string[], veto: ?string, evidence: array, score: int}
     */
    public function verify(Competitor $competitor, string $homepageText, array $discoveredUrls = []): array
    {
        $types = $competitor->getCompetitorTypes();
        $primaryType = $this->getVerificationType($types);

        $verificationConfig = $this->config->getVerificationConfig($primaryType);
        if (empty($verificationConfig)) {
            // Fallback to EMS verification
            $verificationConfig = $this->config->getVerificationConfig('ems');
        }

        $requiredFamilies = $verificationConfig['required_families'] ?? 2;
        $evidenceFamilies = $verificationConfig['evidence_families'] ?? [];
        $vetoRules = $verificationConfig['veto_rules'] ?? [];

        $text = $this->normalizer->normalize($homepageText);
        $urlString = implode(' ', $discoveredUrls);

        $familiesPassed = [];
        $allEvidence = [];
        $totalScore = 0;

        // ── Evaluate each evidence family ──
        foreach ($evidenceFamilies as $familyName => $familyConfig) {
            $familyHits = [];

            // Check text signals
            $signals = $familyConfig['signals'] ?? $familyConfig['signals_en'] ?? [];
            foreach ($signals as $signal) {
                if (stripos($text, strtolower($signal)) !== false) {
                    $familyHits[] = [
                        'signal' => $signal,
                        'source' => 'text_match',
                        'weight' => $familyConfig['weight'] ?? 10,
                    ];
                }
            }

            // Check Arabic signals
            $signalsAr = $familyConfig['signals_ar'] ?? [];
            foreach ($signalsAr as $signal) {
                if (str_contains($homepageText, $signal)) {
                    $familyHits[] = [
                        'signal' => $signal,
                        'source' => 'arabic_text_match',
                        'weight' => $familyConfig['weight'] ?? 10,
                    ];
                }
            }

            // Check URL patterns
            $urlPatterns = $familyConfig['url_patterns'] ?? [];
            foreach ($urlPatterns as $pattern) {
                if (stripos($urlString, $pattern) !== false) {
                    $familyHits[] = [
                        'signal' => "URL pattern: {$pattern}",
                        'source' => 'url_match',
                        'weight' => ($familyConfig['weight'] ?? 10) / 2,
                    ];
                }
            }

            if (!empty($familyHits)) {
                $familiesPassed[] = $familyName;
                $allEvidence[$familyName] = $familyHits;
                $familyWeight = $familyConfig['weight'] ?? 10;
                $totalScore += min($familyWeight, count($familyHits) * 5);
            }
        }

        // ── Check veto rules ──
        $vetoResult = null;
        foreach ($vetoRules as $rule) {
            $pattern = $rule['pattern'] ?? '';
            if (empty($pattern)) continue;

            if (preg_match("/{$pattern}/i", $text)) {
                // Check if there's a require_absence condition
                $requireAbsence = $rule['require_absence'] ?? null;
                if ($requireAbsence && preg_match("/{$requireAbsence}/i", $text)) {
                    // The counter-evidence exists, so don't veto
                    continue;
                }

                $vetoResult = $rule['label'] ?? 'veto_fired';
                $this->logger->info("CompVerification: VETO fired for {$competitor->getCanonicalDomain()}", [
                    'veto' => $vetoResult,
                    'pattern' => $pattern,
                ]);
                break;
            }
        }

        $passed = ($vetoResult === null) && (count($familiesPassed) >= $requiredFamilies);

        $this->logger->info("CompVerification: {$competitor->getCanonicalDomain()}", [
            'passed' => $passed,
            'families' => count($familiesPassed),
            'required' => $requiredFamilies,
            'veto' => $vetoResult,
            'type' => $primaryType,
        ]);

        return [
            'passed' => $passed,
            'families_passed' => $familiesPassed,
            'veto' => $vetoResult,
            'evidence' => $allEvidence,
            'score' => $totalScore,
            'type_checked' => $primaryType,
        ];
    }

    /**
     * Determine which verification type to use based on competitor types.
     */
    private function getVerificationType(array $types): string
    {
        $first = $types[0] ?? '';

        if (in_array($first, Competitor::TYPE_GROUP_CORE_EMS, true)) return 'ems';
        if (in_array($first, Competitor::TYPE_GROUP_MACHINING, true)) return 'machining';
        if (in_array($first, Competitor::TYPE_GROUP_INTERCONNECT, true)) return 'harness';
        if (in_array($first, Competitor::TYPE_GROUP_ENERGY, true)) return 'supercapacitor';

        return 'ems'; // Default fallback
    }
}
