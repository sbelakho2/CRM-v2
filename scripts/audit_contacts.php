#!/usr/bin/env php
<?php
/**
 * Contact Quality Audit Script
 * Checks all contacts for junk/invalid entries.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

$dotenv = new Dotenv();
$dotenv->loadEnv(dirname(__DIR__) . '/.env');

$dsn = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? null;
if (!$dsn) {
    die("No DATABASE_URL found\n");
}

// Parse DSN: mysql://root:@127.0.0.1:3308/starz_crm?...
preg_match('/mysql:\/\/([^:]*):?([^@]*)@([^:\/]+):?(\d+)?\/([^?]+)/', $dsn, $m);
$user = $m[1]; $pass = $m[2]; $host = $m[3]; $port = $m[4] ?: 3306; $db = $m[5];

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get all contacts with company info
$stmt = $pdo->query("
    SELECT co.id, co.first_name, co.last_name, co.job_title, co.email, 
           co.linked_in_url, co.phone, co.source, co.primary_contact,
           c.name as company_name, c.region, c.sector
    FROM contacts co
    JOIN companies c ON co.company_id = c.id
    ORDER BY c.region, c.name, co.primary_contact DESC
");
$contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║           CONTACT QUALITY AUDIT REPORT                      ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$total = count($contacts);
echo "Total contacts: $total\n\n";

// Junk detection patterns
$junkNames = [
    // Not person names
    '/^(the|our|meet|about|team|company|contact|services|news|blog|home|menu|click|read|learn|view|submit|more|all|free|new|top|best|get|join|sign|log|help|faq)$/i',
    // Single character names
    '/^[A-Z]$/i',
    // Company name used as person name
    '/\b(LLC|Inc|Corp|Ltd|Group|GmbH|S\.A\.|Pty|Co\.|Company)\b/i',
    // URLs
    '/^(http|www\.)/i',
    // Numbers only
    '/^\d+$/',
];

$junkTitles = [
    // Navigation / UI elements
    '/^(click|read more|learn more|view|submit|home|menu|contact|about|services|products|news|blog|faq|login|sign up|register|search|download)/i',
    // Too long (probably descriptions, not titles)
    // Company names repeated as titles
];

$junk = [];
$good = [];
$warnings = [];

$regionStats = [];
$companyStats = [];

foreach ($contacts as $c) {
    $isJunk = false;
    $reasons = [];
    $fn = trim($c['first_name'] ?? '');
    $ln = trim($c['last_name'] ?? '');
    $title = trim($c['job_title'] ?? '');
    $fullName = "$fn $ln";
    $region = $c['region'];
    $company = $c['company_name'];

    // 1. Missing name
    if (strlen($fn) < 2 || strlen($ln) < 2) {
        $isJunk = true;
        $reasons[] = 'name too short';
    }

    // 2. Name is clearly not a person
    foreach ($junkNames as $pat) {
        if (preg_match($pat, $fn) || preg_match($pat, $ln)) {
            $isJunk = true;
            $reasons[] = "bad name pattern: $fullName";
            break;
        }
    }

    // 3. Name contains numbers
    if (preg_match('/\d{2,}/', $fullName)) {
        $isJunk = true;
        $reasons[] = "numbers in name: $fullName";
    }

    // 4. First name == Last name
    if (strtolower($fn) === strtolower($ln)) {
        $isJunk = true;
        $reasons[] = "duplicate first/last: $fullName";
    }

    // 5. Title is actually a company name (same as the company they're in)
    if ($title && strtolower($title) === strtolower($company)) {
        $isJunk = true;
        $reasons[] = "title = company name: $title";
    }

    // 6. No useful identifier (no email, no phone, no LinkedIn)
    $hasEmail = !empty($c['email']);
    $hasLi = !empty($c['linked_in_url']);
    $hasPhone = !empty($c['phone']);
    if (!$hasEmail && !$hasLi && !$hasPhone) {
        // Name-only contacts — these are low quality but not necessarily junk
        $reasons[] = 'name-only (no email/phone/LinkedIn)';
        // Only flag as junk if also missing title
        if (empty($title)) {
            $isJunk = true;
            $reasons[] = 'no identifiers AND no title';
        }
    }

    // 7. Generic email prefix used as a contact
    if ($hasEmail) {
        $emailPrefix = strtolower(explode('@', $c['email'])[0]);
        $generic = ['info', 'sales', 'contact', 'support', 'admin', 'hr', 'marketing',
            'webmaster', 'noreply', 'no-reply', 'office', 'careers', 'jobs',
            'press', 'media', 'general', 'enquiries', 'hello', 'service',
            'help', 'billing', 'accounts', 'orders', 'team', 'news',
            'feedback', 'privacy', 'legal', 'compliance', 'reception'];
        if (in_array($emailPrefix, $generic)) {
            $isJunk = true;
            $reasons[] = "generic email: {$c['email']}";
        }
    }

    // 8. Title contains suspicious patterns (company name in title from LinkedIn)
    if ($title && preg_match('/^(ABB|Group|Inc|LLC|Corp|Ltd)$/i', $title)) {
        $isJunk = true;
        $reasons[] = "junk title: $title";
    }

    // 9. Name has non-latin gibberish (not Arabic/Chinese which are legit)
    if (preg_match('/[<>{}|\\\\\/]/', $fullName)) {
        $isJunk = true;
        $reasons[] = "special chars in name";
    }

    // 10. LinkedIn title that's clearly wrong company
    if ($title && strlen($company) > 3) {
        // Check if title mentions another company entirely (with "at" or "en" pattern)
        if (preg_match('/\b(at|en|bei|chez|bij)\s+(?!'.preg_quote($company, '/').')/i', $title)) {
            // Only flag if the company after "at" is clearly different  
            if (preg_match('/\b(?:at|en|bei)\s+([A-Z][a-zA-Z\s]+)$/i', $title, $tm)) {
                $atCompany = trim($tm[1]);
                $companyLower = strtolower($company);
                $atLower = strtolower($atCompany);
                // If the "at X" company is completely different, it's wrong-company junk
                if (strlen($atCompany) > 3 && 
                    !str_contains($companyLower, $atLower) && 
                    !str_contains($atLower, $companyLower)) {
                    $isJunk = true;
                    $reasons[] = "wrong company in title: '$title' (expected: $company)";
                }
            }
        }
    }

    if ($isJunk) {
        $junk[] = ['contact' => $c, 'reasons' => $reasons];
    } else {
        if (!empty($reasons)) {
            $warnings[] = ['contact' => $c, 'reasons' => $reasons];
        }
        $good[] = $c;
    }

    // Track per-region and per-company
    if (!isset($regionStats[$region])) {
        $regionStats[$region] = ['total' => 0, 'good' => 0, 'junk' => 0];
    }
    $regionStats[$region]['total']++;
    $regionStats[$region][$isJunk ? 'junk' : 'good']++;

    if (!isset($companyStats[$company])) {
        $companyStats[$company] = ['region' => $region, 'total' => 0, 'good' => 0, 'junk' => 0, 'with_title' => 0, 'with_email' => 0, 'with_li' => 0];
    }
    $companyStats[$company]['total']++;
    $companyStats[$company][$isJunk ? 'junk' : 'good']++;
    if (!empty($title)) $companyStats[$company]['with_title']++;
    if ($hasEmail) $companyStats[$company]['with_email']++;
    if ($hasLi) $companyStats[$company]['with_li']++;
}

// ── Region Summary ──
echo "┌─────────┬───────┬──────┬──────┬────────────┐\n";
echo "│ Region  │ Total │ Good │ Junk │ Good Rate  │\n";
echo "├─────────┼───────┼──────┼──────┼────────────┤\n";
foreach ($regionStats as $region => $stats) {
    $rate = $stats['total'] > 0 ? round($stats['good'] / $stats['total'] * 100, 1) : 0;
    $rateColor = $rate >= 95 ? '✅' : ($rate >= 85 ? '⚠️' : '❌');
    printf("│ %-7s │ %5d │ %4d │ %4d │ %5.1f%% %s │\n", 
        $region, $stats['total'], $stats['good'], $stats['junk'], $rate, $rateColor);
}
echo "└─────────┴───────┴──────┴──────┴────────────┘\n\n";

// Overall
$goodRate = $total > 0 ? round(count($good) / $total * 100, 1) : 0;
echo "OVERALL: " . count($good) . "/$total good = {$goodRate}%\n\n";

// ── Junk contacts detail ──
if (!empty($junk)) {
    echo "═══ JUNK CONTACTS (" . count($junk) . ") ═══\n";
    foreach ($junk as $j) {
        $c = $j['contact'];
        printf("  ❌ %-25s | %-30s | %-20s | %s\n",
            $c['first_name'] . ' ' . $c['last_name'],
            $c['company_name'],
            $c['job_title'] ?: '(no title)',
            implode('; ', $j['reasons'])
        );
    }
    echo "\n";
}

// ── Warnings ──
if (!empty($warnings)) {
    echo "═══ WARNINGS (" . count($warnings) . ") ═══\n";
    foreach ($warnings as $w) {
        $c = $w['contact'];
        printf("  ⚠️  %-25s | %-30s | %-20s | %s\n",
            $c['first_name'] . ' ' . $c['last_name'],
            $c['company_name'],
            $c['job_title'] ?: '(no title)',
            implode('; ', $w['reasons'])
        );
    }
    echo "\n";
}

// ── Per-Company breakdown ──
echo "═══ PER-COMPANY BREAKDOWN ═══\n";
echo "┌──────────────────────────────────────────┬────────┬───────┬──────┬──────┬───────┬──────┬──────┐\n";
echo "│ Company                                  │ Region │ Total │ Good │ Junk │ Title │ Email│  LI  │\n";
echo "├──────────────────────────────────────────┼────────┼───────┼──────┼──────┼───────┼──────┼──────┤\n";
ksort($companyStats);
foreach ($companyStats as $name => $s) {
    printf("│ %-40s │ %-6s │ %5d │ %4d │ %4d │ %5d │ %4d │ %4d │\n",
        mb_substr($name, 0, 40), $s['region'], $s['total'], $s['good'], $s['junk'],
        $s['with_title'], $s['with_email'], $s['with_li']);
}
echo "└──────────────────────────────────────────┴────────┴───────┴──────┴──────┴───────┴──────┴──────┘\n";

echo "\n=== QUALITY VERDICT ===\n";
if ($goodRate >= 95) {
    echo "✅ PASS — Non-junk ratio: {$goodRate}% (target: 95%+)\n";
} else {
    echo "❌ FAIL — Non-junk ratio: {$goodRate}% (target: 95%+)\n";
    echo "Need to fix " . count($junk) . " junk contacts.\n";
}
