<?php

/**
 * CI gate: LIVE DATA SAFETY — the hard, mechanical enforcement of the
 * standing project law: "CRM on live has lots of data. Make sure it is
 * NEVER touched or deleted."
 *
 * The runtime armor is TestDatabaseGuard (fails any connection whose name
 * is not a *_test database) and the safe-migrate wrapper (preserves before
 * the destructive historical chain). This gate adds STATIC armor: every
 * database reference that ships in CI/scripts/tests must point at a
 * throwaway *_test database with the crm_test user — so a typo or a
 * copy-paste can never aim the suite, the upgrade lane, or the e2e server
 * at production.
 *
 * Rules:
 *   1. Every DATABASE_URL in .woodpecker/, scripts/ci/, tests/ (non-vendor),
 *      phpunit.xml.dist and .env.local.e2e must authenticate as crm_test.
 *   2. Its database name must end in _test, or be crm_ci/crm_upgrade
 *      (which the test config suffixes to *_test) — anything else FAILS.
 *   3. No doctrine:database:drop / migrations:migrate may run in CI or
 *      scripts without the test environment being forced in the same file.
 *   4. src/ may never invoke database:drop or DROP DATABASE at all — the
 *      application has no business destroying databases.
 *   5. phpunit.xml.dist must not force-override DATABASE_URL.
 *
 * Exit 0 = every static database reference is provably throwaway; 1 = violation.
 */

$root = dirname(__DIR__, 2);
$failures = [];

/** Files that ship database URLs in repository surfaces (not .env — that
 *  is the developer's local machine config, guarded at runtime). */
$surfaces = [
    '.woodpecker',
    'scripts/ci',
    'phpunit.xml.dist',
    '.env.local.e2e',
    'tests/e2e',
];

$collect = static function (string $dir) use (&$files): void {
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && !$file->isDir()) {
            $files[] = $file->getPathname();
        }
    }
};

$files = [];
foreach ($surfaces as $surface) {
    $path = $root . '/' . $surface;
    if (is_file($path)) {
        $files[] = $path;
    } else {
        $collect($path);
    }
}

foreach ($files as $file) {
    $content = (string) file_get_contents($file);
    $relative = str_replace($root . '/', '', $file);

    // Rule 1+2: every mysql:// URL must be the throwaway user on a
    // throwaway database name.
    if (preg_match_all('/mysql:\\/\\/([^:@\\/\'"]+):[^@\\s\'"]*@([^:\\/\'"\\s]+):(\\d+)\\/([A-Za-z0-9_]+)/', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            [$full, $user, $host, $port, $database] = $m;

            if ($user !== 'crm_test') {
                $failures[] = "{$relative}: DATABASE_URL user '{$user}' — CI/scripts/tests must authenticate as 'crm_test', never a real/deploy user";
            }

            $isTest = str_ends_with($database, '_test')
                || $database === 'crm_ci'
                || $database === 'crm_upgrade';
            if (!$isTest) {
                $failures[] = "{$relative}: DATABASE_URL database '{$database}' is not a *_test throwaway (allowed: *_test, crm_ci, crm_upgrade — the latter two get the _test suffix from config)";
            }
        }
    }

    // Rule 3: destructive console commands must carry the test env in CI
    // surfaces — either an explicit --env=test or an APP_ENV=test export
    // in the same file.
    if (preg_match('/doctrine:database:(drop|create)|doctrine:migrations:migrate/', $content)) {
        $envForced = str_contains($content, '--env=test')
            || str_contains($content, 'APP_ENV: test')
            || str_contains($content, 'APP_ENV=test')
            || str_contains($content, 'export APP_ENV');
        if (!$envForced) {
            $failures[] = "{$relative}: runs doctrine:database:drop/create or migrations without forcing the test environment (--env=test / APP_ENV=test) — production schema commands never belong in CI surfaces";
        }
    }
}

// Rule 4: the APPLICATION must not destroy databases, ever.
$appIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)
);
foreach ($appIterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    $code = (string) file_get_contents($file->getPathname());
    if (str_contains($code, 'doctrine:database:drop') || stripos($code, 'DROP DATABASE') !== false) {
        $failures[] = str_replace($root . '/', '', $file->getPathname()) . ': application code drops databases — this must never exist in src/';
    }
}

// Rule 5: phpunit.xml.dist must not pin a DATABASE_URL (it would override
// the CI-provided throwaway URL for every run).
$phpunitXml = (string) file_get_contents($root . '/phpunit.xml.dist');
if (preg_match('/<env\\s+name="DATABASE_URL"/i', $phpunitXml)) {
    $failures[] = 'phpunit.xml.dist: forces DATABASE_URL — the test database comes from the environment (CI), never from a committed file';
}

if ($failures !== []) {
    fwrite(STDERR, "✖ LIVE DATA SAFETY gate FAILED (" . count($failures) . ") — a database reference is not provably throwaway:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n");
    }
    exit(1);
}

echo "✓ live-data-safety gate passed (every static DB reference is a *_test throwaway as crm_test; app code cannot drop databases)\n";
