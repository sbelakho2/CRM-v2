<?php

/**
 * CI gate: adversarial pattern checks over src/ and templates/.
 *
 * Each rule encodes a security/data-loss invariant that was violated before
 * and must never regress:
 *
 *   1. No shell-execution functions in src/ (shell_exec/exec/system/passthru/
 *      proc_open/popen) — use Symfony Process with argv arrays.
 *   2. No `striptags(...)|raw` in templates — striptags is not a sanitizer.
 *   3. No `|json_encode|raw` in templates — script-context JSON must use
 *      the JSON_HEX_* flags.
 *   4. No `->remove($company)` / `->remove($user)` / `->remove($quote)`
 *      hard-deletes in controllers — archive/deactivate instead.
 *   5. Crawler services must reference SafeOutboundUrlGuard (SSRF gate).
 *   6. phpunit.xml.dist must not point at a production-named database and
 *      must not force-override DATABASE_URL.
 *
 * Exit 0 = clean, exit 1 = violation.
 */

$root = dirname(__DIR__, 2);
$failures = [];

function fail(array &$failures, string $message): void
{
    $failures[] = $message;
}

/**
 * Remove comments AND string-literal contents so identifiers inside strings
 * (help texts, log messages) cannot trigger false positives.
 */
function stripPhpNoise(string $code): string
{
    $code = preg_replace('#^\s*//.*$#m', '', $code) ?? $code;
    $code = preg_replace('#/\*.*?\*/#s', '', $code) ?? $code;
    // Replace string literal contents (keep quotes) — handles escapes.
    $code = preg_replace("/'(?:\\\\.|[^'\\\\])*'/", "''", $code) ?? $code;
    $code = preg_replace('/"(?:\\\\.|[^"\\\\])*"/', '""', $code) ?? $code;

    return $code;
}

function phpFiles(string $root): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    $files = [];
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

// ── 1. shell execution functions in src/ ─────────────────────────────
foreach (phpFiles($root . '/src') as $file) {
    $code = (string) file_get_contents($file);
    $stripped = stripPhpNoise($code);

    if (preg_match('/\b(shell_exec|passthru|popen|proc_open)\s*\(/', $stripped)
        || preg_match('/(?<![:\w>)$])\bexec\s*\(/', $stripped)
        || preg_match('/(?<![:\w>)$])\bsystem\s*\(/', $stripped)
    ) {
        fail($failures, "shell execution function used in {$file} — use Symfony\\Component\\Process\\Process with an argv array");
    }
}

// ── 2/3. unsafe template constructs ──────────────────────────────────
$twigIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/templates', FilesystemIterator::SKIP_DOTS)
);
foreach ($twigIterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'twig') {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());

    if (preg_match('/striptags\s*\([^)]*\)\s*\|\s*raw/U', $source)) {
        fail($failures, "{$file->getPathname()}: striptags(...)|raw — striptags is not an HTML sanitizer; escape the message instead");
    }

    if (preg_match('/\|\s*json_encode\s*\|\s*raw/U', $source)) {
        fail($failures, "{$file->getPathname()}: json_encode|raw without JSON_HEX_* flags — script-context escape risk");
    }
}

// ── 7. native browser dialogs in templates ───────────────────────────
// alert()/confirm()/prompt() are visually inconsistent, poor on mobile,
// and inaccessible. The designated compat components (which DOCUMENT the
// replacements in comments) are the only allowed locations. Comment text
// is stripped before matching so docs don't false-positive.
$nativeDialogAllowed = [
    'confirm_modal.html.twig', // documents ramsConfirm/ramsPrompt
    'alert_modal.html.twig',   // documents ramsAlert
];
foreach ($twigIterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'twig') {
        continue;
    }
    if (in_array($file->getFilename(), $nativeDialogAllowed, true)) {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    // Strip HTML comments, JS line/block comments, and Twig comments.
    $code = preg_replace('/<!--.*?-->/s', '', $source) ?? $source;
    $code = preg_replace('/\{#.*?#\}/s', '', $code) ?? $code;
    $code = preg_replace('#^\s*//.*$#m', '', $code) ?? $code;
    $code = preg_replace('/\/\*.*?\*\//s', '', $code) ?? $code;
    if (preg_match('/\b(alert|confirm|prompt)\s*\(/', $code)) {
        fail($failures, "{$file->getPathname()}: native alert()/confirm()/prompt() — use ramsAlert()/ramsConfirm()/ramsPrompt() from the modal components");
    }
}

// ── 4. hard-delete calls on preserved entities ───────────────────────
$preservedRemovals = [
    'src/Controller' => '/->remove\(\s*\$(company|user|quote)\b/i',
];
foreach ($preservedRemovals as $dir => $regex) {
    foreach (phpFiles($root . '/' . $dir) as $file) {
        $stripped = stripPhpNoise((string) file_get_contents($file));
        if (preg_match($regex, $stripped)) {
            fail($failures, "{$file}: hard delete of \$company/\$user/\$quote — archive or deactivate instead");
        }
    }
}

// ── 5. crawler fetch services must keep the SSRF guard ───────────────
$guardRequired = [
    'src/Service/FastWebScraperService.php',
    'src/Service/HeadlessBrowserService.php',
    'src/Service/DeepScrapingService.php',
    'src/Service/PortalCrawlerService.php',
    'src/Service/WebCrawler/Crawl/SitemapPageDiscovery.php',
    'src/Service/WebCrawler/Pipeline/DomainCrawler.php',
    'src/Service/WebCrawler/Seed/DirectorySeedExtractor.php',
];
foreach ($guardRequired as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        fail($failures, "{$relative}: expected crawler service is missing");
        continue;
    }
    $code = (string) file_get_contents($path);
    if (!str_contains($code, 'SafeOutboundUrlGuard')) {
        fail($failures, "{$relative}: outbound fetching without App\\Security\\SafeOutboundUrlGuard");
    }
}

// ── 6. Doctrine where() overwriting archive predicates ───────────────
// where() REPLACES the whole WHERE expression: calling it after an
// archivedAt IS NULL andWhere() erases the archive filter (a real bug in
// two rounds of this codebase).
$predTargets = [$root . '/src/Controller', $root . '/src/Repository'];
foreach ($predTargets as $dir) {
    foreach (phpFiles($dir) as $file) {
        $code = (string) file_get_contents($file);
        $archLine = null;
        $lineNo = 0;
        foreach (explode("\n", $code) as $line) {
            $lineNo++;
            // A new query builder starts an independent chain: where() as
            // its first predicate is fine.
            if (str_contains($line, 'createQueryBuilder(')) {
                $archLine = null;
            }
            if (str_contains($line, 'archivedAt IS NULL')) {
                $archLine = $lineNo;
            }
            if ($archLine !== null && preg_match('/->where\(/', $line)) {
                fail($failures, sprintf(
                    '%s:%d calls where() after an archivedAt filter (line %d) — where() REPLACES the WHERE clause and resurrects archived rows; use andWhere()',
                    $file,
                    $lineNo,
                    $archLine
                ));
            }
        }
    }
}

// ── 7. phpunit database safety ───────────────────────────────────────
$phpunit = (string) file_get_contents($root . '/phpunit.xml.dist');
if (preg_match('#<server name="DATABASE_URL"[^>]*value="([^"]+)"#', $phpunit, $m)) {
    $url = html_entity_decode($m[1], ENT_QUOTES);
    if (preg_match('#/(starz_crm|crm|starzcrm)(\?|&|$)#', $url)) {
        fail($failures, 'phpunit.xml.dist DATABASE_URL names the production database');
    }
    if (preg_match('#<server name="DATABASE_URL"[^>]*force="true"#', $phpunit)) {
        fail($failures, 'phpunit.xml.dist must not force-override DATABASE_URL (CI injects its own throwaway database)');
    }
}
if (!is_file($root . '/tests/Bootstrap/TestDatabaseGuard.php')) {
    fail($failures, 'src/Infrastructure/TestDatabaseGuard.php (fail-closed destructive-test guard) is missing');
}

if ($failures !== []) {
    fwrite(STDERR, "✖ dangerous-pattern gate FAILED\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "✓ dangerous-pattern gate passed\n";
exit(0);
