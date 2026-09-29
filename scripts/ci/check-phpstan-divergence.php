<?php

/**
 * CI gate: PHPStan entity/service divergence check.
 *
 * Round-6 audit P0 findings were services calling methods that do not exist
 * on their entities ("Call to an undefined method ...") — a class runtime
 * coverage missed entirely. This gate:
 *
 *   1. Fails on ANY undefined-method divergence in the P0-reconciled
 *      modules (zero tolerance — these were just fixed).
 *   2. Enforces a frozen baseline count for the remaining known legacy
 *      divergences (DfmLint/DocumentManager/CostingEngine/...) — it may
 *      shrink, never grow.
 *
 * Interface-imprecision false positives (DateTimeInterface::modify,
 * UserInterface::get*, TranslatorInterface::setLocale — the runtime objects
 * DO implement them) are filtered before counting.
 */

$root = dirname(__DIR__, 2);
$phpstan = $root . '/vendor/bin/phpstan';

if (!is_file($phpstan)) {
    fwrite(STDERR, "✖ phpstan not installed (composer require --dev phpstan/phpstan)\n");
    exit(1);
}

exec(
    sprintf('%s analyse src --no-progress --memory-limit=2G --error-format=raw 2>/dev/null', escapeshellarg($phpstan)),
    $lines,
    $exitCode
);

// Modules reconciled in round 6: new divergence here fails hard.
$zeroTolerance = [
    'src/Service/OnboardingPackService.php',
    'src/Service/PortalCrawlerService.php',
    'src/Service/RouteSelectionService.php',
    'src/Service/VendorPortalApiService.php',
    'src/Service/EmailCampaignService.php',
    'src/Service/EmailSchedulerService.php',
    'src/Service/DutyCalculationService.php',
    'src/Service/WebinarService.php',
    'src/Service/DatasetImportService.php',
    'src/Controller/QuoteReviewController.php',
    'src/Controller/QuoteCoPilotController.php',
];



$falsePositivePatterns = [
    'DateTimeInterface::modify',
    'DateTimeInterface::setTime',
    'UserInterface::getId',
    'UserInterface::getEmail',
    'UserInterface::getPreferredLocale',
    'UserInterface::setPassword',
    'TranslatorInterface::setLocale',
];

// Known legacy divergences still to reconcile (frozen baseline — may
// shrink as modules are reconciled, may never grow).
$baseline = 55;
$total = 0;
$legacyDivergence = 0;
$criticalDivergence = [];
foreach ($lines as $line) {
    if ($line === '') {
        continue;
    }
    $total++;
    if (!str_contains($line, 'Call to an undefined method')) {
        continue;
    }
    foreach ($falsePositivePatterns as $pattern) {
        if (str_contains($line, $pattern)) {
            continue 2;
        }
    }
    $legacyDivergence++;
    foreach ($zeroTolerance as $module) {
        if (str_contains($line, $module)) {
            $criticalDivergence[] = $line;
        }
    }
}

if ($criticalDivergence !== []) {
    fwrite(STDERR, '✖ divergence gate FAILED — undefined-method call(s) in reconciled module(s):' . "\n");
    foreach ($criticalDivergence as $error) {
        fwrite(STDERR, '  - ' . str_replace($root . '/', '', $error) . "\n");
    }
    exit(1);
}

if ($legacyDivergence > 55) {
    fwrite(STDERR, sprintf(
        "✖ legacy divergence count INCREASED: %d > baseline %d — reconcile the new call or fix the entity\n",
        $legacyDivergence,
        $baseline
    ));
    exit(1);
}

echo sprintf(
    "✓ divergence gate passed (0 in reconciled modules; legacy %d ≤ baseline %d; total findings %d)\n",
    $legacyDivergence,
    $baseline,
    $total
);
exit(0);
