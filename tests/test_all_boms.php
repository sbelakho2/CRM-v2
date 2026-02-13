<?php
/**
 * Comprehensive BOM Parser Test — tests all sample BOM files
 * against the robust BOMParser to verify correct header detection,
 * MPN extraction, quantity parsing, and designator counting.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Service\BOMParser;
use Psr\Log\AbstractLogger;

class AllBomTestLogger extends AbstractLogger {
    public function log($level, $message, array $context = []): void {
        if ($level === 'error') {
            fprintf(STDERR, "[%s] %s\n", strtoupper($level), $message);
        }
    }
}

$parser = new BOMParser(new AllBomTestLogger());

// Expected results: [file, expectedLines, expectedMinQty, description]
$testFiles = [
    ['tests/bom_samples/bom_01_basic_stm32.csv',     7,  'Basic STM32 — standard headers'],
    ['tests/bom_samples/bom_02_esp32_iot.csv',       13,  'ESP32 IoT — Reference/Part Number headers'],
    ['tests/bom_samples/bom_03_power_supply.csv',    13,  'Power Supply — Ref Des/MPN/Count headers'],
    ['tests/bom_samples/bom_04_altium_format.csv',   14,  'Altium — long header names'],
    ['tests/bom_samples/bom_05_kicad_format.csv',    15,  'KiCad — References/Value/MPN headers, designator ranges'],
    ['tests/bom_samples/bom_06_motor_control.csv',   13,  'Motor Control — Part No/Mfg/Pkg headers'],
    ['tests/bom_samples/bom_07_audio_board.csv',     16,  'Audio Board — Component/Brand/Amount headers'],
    ['tests/bom_samples/bom_08_lora_gateway.csv',    19,  'LoRa Gateway — RefDes/MPN headers'],
    ['tests/bom_samples/bom_09_minimal_headers.csv', 10,  'Minimal — only Part Number/Qty/Description'],
    ['tests/bom_samples/bom_10_complex_mixed.csv',   22,  'Complex Mixed — Mfr Part No./Package / Case'],
    ['tests/bom_samples/bom_real_ems_commodity.csv',  38,  'Real EMS Commodity — standard headers, 38 lines'],
    ['sample_bom.csv',                                 7,  'Root sample BOM — standard headers'],
    ['public/samples/sample-bom.csv',                 10,  'Public sample — Part Number first column'],
    ['ab.xls',                                        23,  'ab.xls Excel — * prefix headers, 12 columns'],
];

$passCount = 0;
$failCount = 0;
$results = [];

echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║            BOM Parser Comprehensive Test Suite                      ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n\n";

foreach ($testFiles as [$relPath, $expectedLines, $desc]) {
    $absPath = __DIR__ . '/../' . $relPath;
    
    if (!file_exists($absPath)) {
        echo "  ⚠️  SKIP: {$relPath} — file not found\n";
        continue;
    }
    
    echo "━━━ Testing: {$desc} ━━━\n";
    echo "  File: {$relPath}\n";
    
    try {
        $parsed = $parser->parse($absPath);
        $lineCount = count($parsed);
        $consolidated = $parser->consolidate($parsed);
        $consCount = count($consolidated);
        $totalQty = array_sum(array_column($parsed, 'quantity'));
        
        // Check that all lines have an MPN
        $mpnMissing = 0;
        $mpnEmpty = 0;
        $qtyZero = 0;
        foreach ($parsed as $line) {
            if (!isset($line['mpn']) || $line['mpn'] === null) {
                $mpnMissing++;
            } elseif (trim($line['mpn']) === '') {
                $mpnEmpty++;
            }
            if (isset($line['quantity']) && $line['quantity'] <= 0) {
                $qtyZero++;
            }
        }
        
        $pass = true;
        $issues = [];
        
        if ($lineCount !== $expectedLines) {
            $issues[] = "Expected {$expectedLines} lines, got {$lineCount}";
            $pass = false;
        }
        if ($mpnMissing > 0 || $mpnEmpty > 0) {
            $issues[] = "MPN issues: {$mpnMissing} missing, {$mpnEmpty} empty";
            $pass = false;
        }
        if ($qtyZero > 0) {
            $issues[] = "{$qtyZero} lines with qty ≤ 0";
            $pass = false;
        }
        if ($lineCount === 0) {
            $issues[] = "No lines parsed!";
            $pass = false;
        }
        
        $status = $pass ? '✅ PASS' : '❌ FAIL';
        echo "  {$status}: {$lineCount} raw lines, {$consCount} consolidated, total qty {$totalQty}\n";
        
        if (!$pass) {
            foreach ($issues as $issue) {
                echo "    ⚠️  {$issue}\n";
            }
        }
        
        // Show first 3 MPNs as sample
        echo "  Sample MPNs: ";
        $sample = array_slice($parsed, 0, 3);
        $mpns = array_map(fn($l) => $l['mpn'] ?? '???', $sample);
        echo implode(', ', $mpns) . "\n";
        
        // Show first 3 quantities
        echo "  Sample Qtys: ";
        $qtys = array_map(fn($l) => $l['quantity'] ?? 0, $sample);
        echo implode(', ', $qtys) . "\n";
        
        if ($pass) $passCount++; else $failCount++;
        $results[] = [$relPath, $lineCount, $expectedLines, $pass, $issues];
        
    } catch (\Throwable $e) {
        echo "  ❌ ERROR: " . $e->getMessage() . "\n";
        echo "    at " . $e->getFile() . ":" . $e->getLine() . "\n";
        $failCount++;
        $results[] = [$relPath, 0, $expectedLines, false, [$e->getMessage()]];
    }
    
    echo "\n";
}

// Summary
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║                         SUMMARY                                    ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n";
echo "  Passed: {$passCount} / " . ($passCount + $failCount) . "\n";
echo "  Failed: {$failCount}\n\n";

if ($failCount > 0) {
    echo "  ❌ Failures:\n";
    foreach ($results as [$file, $got, $expected, $pass, $issues]) {
        if (!$pass) {
            echo "    • {$file}: " . implode('; ', $issues) . "\n";
        }
    }
    echo "\n";
}

$allPass = $failCount === 0;
echo $allPass 
    ? "  🎉 All BOM formats parsed successfully!\n" 
    : "  ⚠️  Some formats need attention.\n";

exit($allPass ? 0 : 1);
