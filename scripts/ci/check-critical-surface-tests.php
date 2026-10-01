<?php

/**
 * CI gate: critical-surface test existence.
 *
 * SYSTEM-AWARE CONTRACT: certain classes are load-bearing for money,
 * authorization, data preservation or network safety. The round-8 audit
 * proved that "green CI" can coexist with a completely broken service
 * (RouteSelectionService queried a schema that did not exist) when NO test
 * executes the code path. This gate forces a MINIMUM existence proof: for
 * every critical class below, every public method must be NAMED in at
 * least one test file. It does not prove the test is good — it proves the
 * surface is not invisible to the suite, which is the exact failure mode
 * that produced the round-8 P0.
 *
 * When adding a public method to a critical class, add a test that calls
 * it — or explicitly move the method out of the critical list if the class
 * stops being load-bearing (with a reason in the comment column).
 *
 * Exit 0 = every critical public method is referenced by the suite.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

/**
 * class => [reason, excludedMethods]
 * Excluded: constructors and trivial accessors whose coverage is implied
 * by the methods that return the values.
 */
$critical = [
    \App\Service\PricingEngine::class => ['canonical quote totals — the single pricing model the whole CRM bills through', []],
    \App\Service\EmailSendPolicy::class => ['send eligibility: suppression lists, archived campaigns, consent — legal surface', []],
    \App\Service\RouteSelectionService::class => ['freight selection and landed-cost ranking — round-8 P0 lived here unseen', []],
    \App\Service\DutyCalculationService::class => ['customs duty math (ad valorem/specific/compound) — financial correctness', []],
    \App\Service\InteractiveLiveQuoteService::class => ['PUBLIC revenue surface: token auth, tier repricing, durable acceptances, rate limits', []],
    \App\Security\SafeOutboundUrlGuard::class => ['SSRF armor for every crawler and portal fetch', []],
    \App\Service\CsvExportService::class => ['export safety: formula injection sanitizer + streaming exports', []],
    \App\Security\Voter\TaskVoter::class => ['task authorization — the round-8 IDOR fix', ['supports', 'voteOnAttribute']],
    \App\Command\PreserveComplianceLegacyCommand::class => ['legacy compliance data preservation before destructive schema history', ['execute']],
    \App\Service\CurrencyConverter::class => ['convertOrFail fail-closed FX for financial ranking', []],
    \App\Infrastructure\TestDatabaseGuard::class => ['the armor standing between the suite and the live database', ['assertSafeTestDatabase', 'currentDatabase']],
];

$failures = [];
$testedCorpus = '';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/tests', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !in_array($file->getExtension(), ['php', 'js'], true)) {
        continue;
    }
    $testedCorpus .= "\n" . (string) file_get_contents($file->getPathname());
}

foreach ($critical as $class => [$reason, $excluded]) {
    if (!class_exists($class)) {
        $failures[] = "{$class}: critical class listed in the gate NO LONGER EXISTS — update the gate (this is itself a regression signal)";
        continue;
    }

    $short = substr($class, (int) strrpos($class, '\\') + 1);
    if (strpos($testedCorpus, $short) === false) {
        $failures[] = "{$class}: ZERO references in tests/ — an invisible critical surface ({$reason})";
        continue;
    }

    try {
        $reflection = new ReflectionClass($class);
    } catch (\Throwable $e) {
        $failures[] = "{$class}: reflection failed ({$e->getMessage()})";
        continue;
    }

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
            continue; // inherited — covered where it is declared
        }
        $name = $method->getName();
        if ($name === '__construct' || in_array($name, $excluded, true)) {
            continue;
        }
        if (strpos($testedCorpus, $name) === false) {
            $failures[] = "{$class}::{$name}(): public method on a critical surface ({$reason}) is not referenced by ANY test — add one that exercises it";
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "✖ critical-surface test gate FAILED (" . count($failures) . "):\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }
    exit(1);
}

echo '✓ critical-surface test gate passed (' . count($critical) . ' critical classes, every public method referenced by the suite)' . "\n";
