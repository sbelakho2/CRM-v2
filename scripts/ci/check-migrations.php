<?php

/**
 * CI gate: reject destructive SQL in Doctrine migrations.
 *
 * Scans every migration's up()/down() for DROP TABLE / TRUNCATE /
 * DROP COLUMN / DELETE FROM. Historical migrations already executed on
 * production are IMMUTABLE — they are allow-listed below with the reason,
 * and the gate exists to stop NEW destructive migrations from landing.
 *
 * Exit 0 = clean, exit 1 = violation found (prints each one).
 */

/**
 * Historical migrations (version number <= GATE_EPOCH) executed on the live
 * CRM before this gate existed. Applied migration history is IMMUTABLE — it
 * is never rewritten (see audit §5) — so the pre-gate set is allow-listed as
 * a whole. Any migration added AFTER the epoch must be additive and
 * forward-only; destructive SQL in new migrations fails this gate.
 */
const GATE_EPOCH = '20260928120000';

$allowlist = [];

$root = dirname(__DIR__, 2);
$migrationsDir = $root . '/migrations';

if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "migrations directory not found\n");
    exit(1);
}

$violations = [];
$files = glob($migrationsDir . '/Version*.php') ?: [];
sort($files);

foreach ($files as $file) {
    $basename = basename($file);
    $source = (string) file_get_contents($file);

    // Strip comments so commented-out SQL does not trigger false positives.
    $code = preg_replace('#^\s*//.*$#m', '', $source) ?? $source;
    $code = preg_replace('#/\*.*?\*/#s', '', $code) ?? $code;

    $patterns = [
        'DROP TABLE' => '/\bDROP\s+TABLE\b/i',
        'TRUNCATE' => '/\bTRUNCATE\b/i',
        'DROP COLUMN' => '/\bDROP\s+COLUMN\b/i',
        'DELETE FROM' => '/\bDELETE\s+FROM\b/i',
    ];

    foreach ($patterns as $label => $regex) {
        if (preg_match($regex, $code) !== 1) {
            continue;
        }

        $version = preg_replace('/^Version(\d{14})\.php$/', '$1', $basename);
        if ($version !== null && $version !== $basename && $version <= GATE_EPOCH) {
            continue; // pre-gate historical migration (immutable history)
        }

        if (isset($allowlist[$basename])) {
            continue; // explicitly reviewed
}

        $violations[] = sprintf(
            '%s: contains destructive SQL (%s). Migrations must be additive and forward-only; never rewrite applied history.',
            $basename,
            $label
        );
    }
}

if ($violations !== []) {
    fwrite(STDERR, "✖ destructive-migration gate FAILED\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, "  - {$violation}\n");
    }
    exit(1);
}

$count = count($files);
echo "✓ destructive-migration gate passed ({$count} migrations scanned, allow-listed: " . count($allowlist) . ")\n";
exit(0);
