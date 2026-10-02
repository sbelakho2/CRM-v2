<?php

namespace App\Service\WebCrawler\Rules;

use App\Service\WebCrawler\Text\TextNormalizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * RuleEngine (Improvement 4A)
 *
 * Loads versioned rule packs from YAML and evaluates them against candidate data.
 * Every rule that fires is traced in the verdict, enabling full auditability.
 *
 * The engine replaces hundreds of inline preg_match calls in GoogleDorkService
 * with a single structured evaluation. Rules are externalized to YAML so they
 * can be versioned, tested, and updated without touching PHP code.
 *
 * Usage:
 *   $verdict = $engine->evaluate($domain, $companyName, $snippet, $title);
 *   if ($verdict->isRejected()) { // skip }
 *
 * @phpstan-type CompetitorRule array{name?: string, pattern?: string, action?: string, weight?: int, description?: string|null}
 * @phpstan-type PositiveRule array{name?: string, pattern?: string, weight?: int, family?: string|null}
 * @phpstan-type RulePackShape array{version?: string|null, blocked_domains?: list<string>, competitor_patterns?: list<CompetitorRule>, wrong_type_patterns?: list<CompetitorRule>, junk_name_patterns?: list<string>, positive_patterns?: list<PositiveRule>}
 */
class RuleEngine
{
    /** @var RulePackShape */
    private array $rulePack = [];
    private bool $loaded = false;

    public function __construct(
        private readonly TextNormalizer $normalizer,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Load the rule pack from YAML. Lazy-loaded on first evaluate() call.
     */
    public function loadRulePack(?string $path = null): void
    {
        $path ??= $this->projectDir . '/config/rules/default_rules_v1.yaml';

        if (!file_exists($path)) {
            $this->logger->warning('Rule pack not found', ['path' => $path]);
            $this->rulePack = [];
            $this->loaded = true;
            return;
        }

        $parsed = Yaml::parseFile($path);
        /** @var RulePackShape $rulePack */
        $rulePack = is_array($parsed) ? $parsed : [];
        $this->rulePack = $rulePack;
        $this->loaded = true;

        $this->logger->debug('Rule pack loaded', [
            'version'    => $this->rulePack['version'] ?? 'unknown',
            'domains'    => count($this->rulePack['blocked_domains'] ?? []),
            'competitor' => count($this->rulePack['competitor_patterns'] ?? []),
            'wrong_type' => count($this->rulePack['wrong_type_patterns'] ?? []),
            'positive'   => count($this->rulePack['positive_patterns'] ?? []),
        ]);
    }

    /**
     * Get the loaded rule pack version.
     */
    public function getVersion(): string
    {
        if (!$this->loaded) {
            $this->loadRulePack();
        }
        return $this->rulePack['version'] ?? 'unknown';
    }

    /**
     * Evaluate all rules against a candidate.
     *
     * @param string $domain      Root domain (e.g. 'acme.com')
     * @param string $companyName Extracted company name
     * @param string $snippet     Google search snippet
     * @param string $title       Google search title
     *
     * @return RuleEngineVerdict
     */
    public function evaluate(string $domain, string $companyName, string $snippet, string $title): RuleEngineVerdict
    {
        if (!$this->loaded) {
            $this->loadRulePack();
        }

        $firedRules = [];
        $totalScore = 0;
        $hardReject = false;
        $rejectReason = '';

        // Normalize inputs
        $normDomain = $this->normalizer->normalizeDomain($domain);
        $normName = $this->normalizer->normalize($companyName);
        $normText = $this->normalizer->normalize($snippet . ' ' . $title . ' ' . $companyName);

        // ── 1. Blocked domain check ──────────────────────────────
        $blockedDomains = $this->rulePack['blocked_domains'] ?? [];
        foreach ($blockedDomains as $blocked) {
            $blocked = strtolower(trim($blocked));
            if ($normDomain === $blocked || str_ends_with($normDomain, '.' . $blocked)) {
                $rule = new RuleResult(
                    'blocked_domain:' . $blocked,
                    'blocked_domain',
                    'reject',
                    -100,
                    $normDomain,
                    'Domain is in blocklist',
                );
                $firedRules[] = $rule;
                $hardReject = true;
                $rejectReason = "Blocked domain: {$blocked}";
                break; // One blocked domain is enough
            }
        }

        // ── 2. Competitor patterns ───────────────────────────────
        if (!$hardReject) {
            foreach ($this->rulePack['competitor_patterns'] ?? [] as $rule) {
                $pattern = $rule['pattern'] ?? '';
                if ($pattern && preg_match('/' . $pattern . '/iu', $normText, $m)) {
                    $weight = $rule['weight'] ?? -50;
                    $firedRules[] = new RuleResult(
                        $rule['name'] ?? 'competitor',
                        'competitor',
                        $rule['action'] ?? 'reject',
                        $weight,
                        $m[0],
                        $rule['description'] ?? null,
                    );
                    $totalScore += $weight;
                    if (($rule['action'] ?? 'reject') === 'reject') {
                        $hardReject = true;
                        $rejectReason = 'Competitor: ' . ($rule['description'] ?? $m[0]);
                        break;
                    }
                }
            }
        }

        // ── 3. Wrong-type patterns ───────────────────────────────
        if (!$hardReject) {
            foreach ($this->rulePack['wrong_type_patterns'] ?? [] as $rule) {
                $pattern = $rule['pattern'] ?? '';
                if ($pattern && preg_match('/' . $pattern . '/iu', $normText, $m)) {
                    $weight = $rule['weight'] ?? -35;
                    $firedRules[] = new RuleResult(
                        $rule['name'] ?? 'wrong_type',
                        'wrong_type',
                        $rule['action'] ?? 'reject',
                        $weight,
                        $m[0],
                        $rule['description'] ?? null,
                    );
                    $totalScore += $weight;
                }
            }
        }

        // ── 4. Junk name patterns ────────────────────────────────
        if (!$hardReject) {
            foreach ($this->rulePack['junk_name_patterns'] ?? [] as $pattern) {
                if (preg_match('/' . $pattern . '/iu', $normName)) {
                    $firedRules[] = new RuleResult(
                        'junk_name',
                        'junk_name',
                        'reject',
                        -100,
                        $normName,
                        'Company name matches junk pattern',
                    );
                    $hardReject = true;
                    $rejectReason = "Junk company name: {$companyName}";
                    break;
                }
            }
        }

        // ── 5. Positive patterns ─────────────────────────────────
        foreach ($this->rulePack['positive_patterns'] ?? [] as $rule) {
            $pattern = $rule['pattern'] ?? '';
            if ($pattern && preg_match('/' . $pattern . '/iu', $normText, $m)) {
                $weight = $rule['weight'] ?? 10;
                $firedRules[] = new RuleResult(
                    $rule['name'] ?? 'positive',
                    'positive',
                    'boost',
                    $weight,
                    $m[0],
                    $rule['family'] ?? null,
                );
                $totalScore += $weight;
            }
        }

        // ── Decision ─────────────────────────────────────────────
        if ($hardReject) {
            return new RuleEngineVerdict($firedRules, 'REJECT', $rejectReason, $totalScore);
        }

        // Accumulated wrong-type score: reject if too negative
        $wrongTypeScore = array_sum(
            array_map(
                fn(RuleResult $r) => $r->ruleType === 'wrong_type' ? $r->weight : 0,
                $firedRules,
            )
        );
        if ($wrongTypeScore <= -35) {
            return new RuleEngineVerdict(
                $firedRules,
                'REJECT',
                'Accumulated wrong-type score: ' . $wrongTypeScore,
                $totalScore,
            );
        }

        return new RuleEngineVerdict(
            $firedRules,
            'PASS',
            'No reject rules triggered (score: ' . $totalScore . ')',
            $totalScore,
        );
    }

    /**
     * Check if a domain is in the blocklist.
     * Used as a quick pre-filter before full evaluation.
     */
    public function isDomainBlocked(string $domain): bool
    {
        if (!$this->loaded) {
            $this->loadRulePack();
        }

        $normDomain = $this->normalizer->normalizeDomain($domain);
        foreach ($this->rulePack['blocked_domains'] ?? [] as $blocked) {
            $blocked = strtolower(trim($blocked));
            if ($normDomain === $blocked || str_ends_with($normDomain, '.' . $blocked)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get stats about the loaded rule pack.
     *
     * @return array<string, int|string>
     */
    public function getStats(): array
    {
        if (!$this->loaded) {
            $this->loadRulePack();
        }

        return [
            'version'            => $this->rulePack['version'] ?? 'unknown',
            'blocked_domains'    => count($this->rulePack['blocked_domains'] ?? []),
            'competitor_rules'   => count($this->rulePack['competitor_patterns'] ?? []),
            'wrong_type_rules'   => count($this->rulePack['wrong_type_patterns'] ?? []),
            'junk_name_rules'    => count($this->rulePack['junk_name_patterns'] ?? []),
            'positive_rules'     => count($this->rulePack['positive_patterns'] ?? []),
        ];
    }
}
