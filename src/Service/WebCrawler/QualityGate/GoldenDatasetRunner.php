<?php

declare(strict_types=1);

namespace App\Service\WebCrawler\QualityGate;

use App\Service\WebCrawler\Classifier\CompetitorProximityVeto;
use App\Service\WebCrawler\Classifier\ServiceProductClassifier;
use App\Service\WebCrawler\CompanyClassifierService;
use App\Service\WebCrawler\Evidence\BuyerEvidenceGate;
use App\Service\WebCrawler\Rules\RuleEngine;
use App\Service\WebCrawler\Text\LanguageDetector;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs the full classification pipeline against a labelled golden dataset
 * and reports pass/fail for each entry — used for regression testing.
 *
 * Each gate is optional (null-safe) so unit tests can inject subsets.
 */
final class GoldenDatasetRunner
{
    /** @var array<string, array{expected: string, name: string, domain: string, snippet: string, title: string, country: string, sector: string, category: string}> */
    private array $entries = [];

    public function __construct(
        private ?BuyerEvidenceGate $buyerEvidenceGate = null,
        private ?ServiceProductClassifier $serviceProductClassifier = null,
        private ?CompetitorProximityVeto $competitorProximityVeto = null,
        private ?RuleEngine $ruleEngine = null,
        private ?CompanyClassifierService $companyClassifier = null,
        private ?LanguageDetector $languageDetector = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    // ──────────────────────────────────────────────────
    // Loading
    // ──────────────────────────────────────────────────

    /**
     * Load entries from a YAML file.
     */
    public function loadFromFile(string $path): void
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Golden dataset not found: $path");
        }

        $data = Yaml::parseFile($path);

        if (!isset($data['entries']) || !is_array($data['entries'])) {
            throw new \RuntimeException("Invalid golden dataset format: missing 'entries' key");
        }

        $this->entries = [];
        foreach ($data['entries'] as $i => $entry) {
            $this->validateEntry($entry, $i);
            $key = $entry['name'] . '|' . $entry['domain'];
            $this->entries[$key] = $entry;
        }
    }

    /**
     * Load entries programmatically (for tests).
     *
     * @param list<array{expected: string, name: string, domain: string, snippet: string, title: string, country: string, sector: string, category: string}> $entries
     */
    public function loadFromArray(array $entries): void
    {
        $this->entries = [];
        foreach ($entries as $i => $entry) {
            $this->validateEntry($entry, $i);
            $key = $entry['name'] . '|' . $entry['domain'];
            $this->entries[$key] = $entry;
        }
    }

    // ──────────────────────────────────────────────────
    // Running
    // ──────────────────────────────────────────────────

    /**
     * Run the full pipeline against every entry and return structured results.
     *
     * @return GoldenDatasetReport
     */
    public function run(): GoldenDatasetReport
    {
        $results = [];

        foreach ($this->entries as $entry) {
            $results[] = $this->evaluateSingle($entry);
        }

        return new GoldenDatasetReport($results);
    }

    /**
     * Evaluate one entry through the full gate chain.
     *
     * @return array{
     *   name: string,
     *   domain: string,
     *   expected: string,
     *   actual: string,
     *   correct: bool,
     *   category: string,
     *   gates: array<string, array{passed: bool, detail: string}>,
     * }
      * @param array<string|int, mixed> $entry
     */
    public function evaluateSingle(array $entry): array
    {
        $name    = $entry['name'];
        $domain  = $entry['domain'];
        $snippet = $entry['snippet'];
        $title   = $entry['title'];
        $country = $entry['country'] ?? '';

        $gates      = [];
        $rejected   = false;
        $rejectGate = null;

        // ── Gate 1: BuyerEvidenceGate ────────────────────
        if ($this->buyerEvidenceGate !== null) {
            $evidenceResult = $this->buyerEvidenceGate->evaluate($name, $snippet, $title, $domain);
            $gatePassed = $evidenceResult->passed();
            $gates['buyer_evidence'] = [
                'passed' => $gatePassed,
                'detail' => $evidenceResult->getReason(),
            ];
            if (!$gatePassed && !$rejected) {
                $rejected = true;
                $rejectGate = 'buyer_evidence';
            }
        }

        // ── Gate 2: ServiceProductClassifier ─────────────
        if ($this->serviceProductClassifier !== null) {
            $spVerdict = $this->serviceProductClassifier->classify($name, $snippet, $title, $domain);
            $gatePassed = !$spVerdict->isRejected();
            $gates['service_product'] = [
                'passed' => $gatePassed,
                'detail' => $spVerdict->reason,
            ];
            if (!$gatePassed && !$rejected) {
                $rejected = true;
                $rejectGate = 'service_product';
            }
        }

        // ── Gate 3: CompetitorProximityVeto ──────────────
        if ($this->competitorProximityVeto !== null) {
            $vetoResult = $this->competitorProximityVeto->evaluate($name, $snippet, $title, $domain);
            $gatePassed = !$vetoResult['vetoed'];
            $gates['competitor_veto'] = [
                'passed' => $gatePassed,
                'detail' => $vetoResult['reason'],
            ];
            if (!$gatePassed && !$rejected) {
                $rejected = true;
                $rejectGate = 'competitor_veto';
            }
        }

        // ── Gate 4: RuleEngine ───────────────────────────
        if ($this->ruleEngine !== null) {
            $ruleVerdict = $this->ruleEngine->evaluate($domain, $name, $snippet, $title);
            $gatePassed = !$ruleVerdict->isRejected();
            $gates['rule_engine'] = [
                'passed' => $gatePassed,
                'detail' => $ruleVerdict->reason,
            ];
            if (!$gatePassed && !$rejected) {
                $rejected = true;
                $rejectGate = 'rule_engine';
            }
        }

        // ── Gate 5: CompanyClassifierService ─────────────
        if ($this->companyClassifier !== null) {
            $clResult = $this->companyClassifier->classifyCompany($name, $snippet, $title, $domain);
            $gatePassed = ($clResult['verdict'] ?? 'UNCERTAIN') !== 'REJECT';
            $gates['company_classifier'] = [
                'passed' => $gatePassed,
                'detail' => implode('; ', $clResult['reasons'] ?? []),
            ];
            if (!$gatePassed && !$rejected) {
                $rejected = true;
                $rejectGate = 'company_classifier';
            }
        }

        // ── Gate 6: LanguageDetector (informational) ─────
        if ($this->languageDetector !== null) {
            $langResult = $this->languageDetector->detectWithRegionRelevance(
                $snippet . ' ' . $title,
                $country,
            );
            $gates['language'] = [
                'passed' => true, // Language is informational, not a gate
                'detail' => sprintf(
                    'lang=%s conf=%.2f relevant=%s',
                    $langResult['language'],
                    $langResult['confidence'],
                    $langResult['region_relevant'] ? 'yes' : 'no',
                ),
            ];
        }

        $actual = $rejected ? 'REJECT' : 'PASS';
        $expected = strtoupper($entry['expected']);
        $correct = ($actual === $expected);

        return [
            'name'        => $name,
            'domain'      => $domain,
            'expected'    => $expected,
            'actual'      => $actual,
            'correct'     => $correct,
            'category'    => $entry['category'] ?? '',
            'reject_gate' => $rejectGate,
            'gates'       => $gates,
        ];
    }

    // ──────────────────────────────────────────────────

    private function validateEntry(array $entry, int $index): void
    {
        $required = ['expected', 'name', 'domain', 'snippet', 'title'];
        foreach ($required as $field) {
            if (!isset($entry[$field]) || !is_string($entry[$field])) {
                throw new \RuntimeException("Golden dataset entry #$index missing required field '$field'");
            }
        }

        $expected = strtoupper($entry['expected']);
        if (!in_array($expected, ['PASS', 'REJECT'], true)) {
            throw new \RuntimeException("Golden dataset entry #$index has invalid 'expected': {$entry['expected']}");
        }
    }
}
