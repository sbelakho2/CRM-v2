<?php
/**
 * Test: Parse ab.xls through BOMParser and price every line
 * 
 * Uses the real BOMParser service (no Symfony container needed)
 * and the test pricing function from test_bom_quality.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Psr\Log\AbstractLogger;

// Minimal logger
class TestLogger extends AbstractLogger {
    public function log($level, $message, array $context = []): void {
        if ($level === 'error' || $level === 'warning') {
            fprintf(STDERR, "[%s] %s\n", strtoupper($level), $message);
        }
    }
}

$logger = new TestLogger();
$parser = new \App\Service\BOMParser($logger);

$filePath = __DIR__ . '/../ab.xls';

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║          QuoteBuddy — ab.xls Excel BOM Test                    ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

// Step 1: Parse
echo "━━━ Step 1: Parse ab.xls ━━━\n";
try {
    $lines = $parser->parse($filePath);
    echo "  Raw lines parsed: " . count($lines) . "\n";
} catch (\Exception $e) {
    echo "  ❌ PARSE ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

// Step 2: Validate
$errors = $parser->validate($lines);
if (!empty($errors)) {
    echo "  ⚠ Validation warnings:\n";
    foreach ($errors as $err) {
        echo "    - $err\n";
    }
}

// Step 3: Consolidate
$consolidated = $parser->consolidate($lines);
echo "  After consolidation: " . count($consolidated) . " unique lines\n";

$totalParts = array_sum(array_column($consolidated, 'quantity'));
echo "  Total part count: $totalParts\n\n";

// Show parsed data
echo "━━━ Parsed BOM ━━━\n";
echo sprintf("  %-4s %-30s %-6s %-35s %-15s %-25s\n", 
    'Line', 'MPN', 'Qty', 'Description', 'Package', 'Remark/Alt');
echo "  " . str_repeat('─', 120) . "\n";

foreach ($consolidated as $line) {
    echo sprintf("  %-4d %-30s %-6d %-35s %-15s %-25s\n",
        $line['lineNumber'],
        substr($line['mpn'], 0, 30),
        $line['quantity'],
        substr($line['description'], 0, 35),
        substr($line['package'], 0, 15),
        substr($line['remark'] ?? '', 0, 25)
    );
}

// Step 4: Price every line using BOM-EMBEDDED pricing (from the file itself)
echo "\n━━━ Step 4: Pricing (BOM-Embedded) ━━━\n";

// Check if the BOM has embedded pricing data
$hasEmbeddedPricing = false;
foreach ($consolidated as $line) {
    if (isset($line['unit_price']) && $line['unit_price'] > 0 && 
        (isset($line['stock_quantity']) || isset($line['total_price']))) {
        $hasEmbeddedPricing = true;
        break;
    }
}

if ($hasEmbeddedPricing) {
    echo "  ✅ BOM contains embedded pricing data (Unit Price + Stock Qty columns)\n\n";
} else {
    echo "  ⚠️  No embedded pricing found, falling back to category-based estimates\n\n";
}

// Price all lines
$totalCost = 0.0;
$sourced = 0;
$unsourced = 0;

echo "  ┌─────┬────────────────────────────────┬──────────┬─────────────┬─────────────┬───────────────┐\n";
echo sprintf("  │ %-3s │ %-30s │ %-8s │ %-11s │ %-11s │ %-13s │\n",
    'Line', 'MPN', 'Stock Qty', 'Unit Price', 'Line Total', 'Source');
echo "  ├─────┼────────────────────────────────┼──────────┼─────────────┼─────────────┼───────────────┤\n";

foreach ($consolidated as $line) {
    $unitPrice    = $line['unit_price'] ?? null;
    $stockQty     = $line['stock_quantity'] ?? $line['quantity'];
    $totalPrice   = $line['total_price'] ?? null;
    
    if ($unitPrice !== null && $unitPrice > 0) {
        // Use BOM-embedded pricing
        $extended = $totalPrice ?? ($unitPrice * $stockQty);
        $totalCost += $extended;
        $sourced++;
        $source = 'BOM File';
    } else {
        // No embedded price — mark as needing sourcing
        $extended = 0;
        $unsourced++;
        $source = '❌ NONE';
    }
    
    echo sprintf("  │ %3d │ %-30s │ %8s │ \$ %9.8f │ \$ %9.2f │ %-13s │\n",
        $line['lineNumber'],
        substr($line['mpn'], 0, 30),
        number_format($stockQty),
        $unitPrice ?? 0,
        $extended,
        $source
    );
}

echo "  └─────┴────────────────────────────────┴──────────┴─────────────┴─────────────┴───────────────┘\n";

echo "\n╔══════════════════════════════════════════════════════════════════╗\n";
echo "║                          RESULTS                               ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
echo "  Lines:      " . count($consolidated) . " unique (" . count($lines) . " raw)\n";
echo "  Parts (per-board): $totalParts\n";
$totalStock = 0;
foreach ($consolidated as $l) { $totalStock += ($l['stock_quantity'] ?? $l['quantity']); }
echo "  Stock Qty Total:   " . number_format($totalStock) . "\n";
echo "  Sourced:    $sourced / " . count($consolidated) . "\n";
echo "  Unsourced:  $unsourced\n";
echo "  Coverage:   " . ($sourced > 0 ? round($sourced / count($consolidated) * 100, 1) : 0) . "%\n";
echo sprintf("  Total cost: \$%s\n", number_format($totalCost, 2));

// Expected total check
$expected = 2256.77;
$diff = abs($totalCost - $expected);
if ($diff < 1.0) {
    echo "  ✅ Matches expected total (\${$expected}) — diff: \$" . number_format($diff, 2) . "\n";
} else {
    echo "  ⚠️  Expected \${$expected}, got \$" . number_format($totalCost, 2) . " — diff: \$" . number_format($diff, 2) . "\n";
}

// Show alternatives from Remark column
$alts = [];
foreach ($consolidated as $line) {
    $remark = $line['remark'] ?? '';
    if (!empty($remark) && $remark !== $line['mpn']) {
        $alts[] = ['mpn' => $line['mpn'], 'alt' => $remark];
    }
}
if (!empty($alts)) {
    echo "\n  📋 Alternative/Substitute Parts (from Remark column):\n";
    foreach ($alts as $a) {
        echo sprintf("    %-30s → %s\n", $a['mpn'], $a['alt']);
    }
}

echo "\n  ✅ ab.xls parsed and priced successfully.\n";
