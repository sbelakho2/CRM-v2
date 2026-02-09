#!/usr/bin/env php
<?php
/**
 * One-time script: Use Gemini Flash to build a knowledge base for the
 * local company classifier. This generates labeled training data that
 * the on-device classifier uses — Gemini is NOT called at runtime.
 *
 * Run once: php scripts/generate_classifier_knowledge.php
 * Output:   external_data/classifier_knowledge.json
 */

$apiKey = getenv('GOOGLE_API_KEY') ?: '***REMOVED***';
$outputFile = __DIR__ . '/../external_data/classifier_knowledge.json';

echo "=== Generating Company Classifier Knowledge Base ===\n\n";

// ─── Define the business type taxonomy ────────────────────────────
// Instead of classifying individual company names (infinite), we classify
// BUSINESS TYPE PATTERNS so the local model can match any new company.

$prompt = <<<'PROMPT'
You are building a knowledge base for an EMS (Electronics Manufacturing Services) company called Starz Electronics. They make PCB assemblies, wire harnesses, and electronic assemblies for OEMs.

Generate a comprehensive JSON object with these categories:

1. "buyer_keywords": array of 100+ keywords/phrases found in snippets/titles of REAL EMS BUYER companies (companies that would outsource PCB/wire harness manufacturing). These are mid-size OEMs that DESIGN products containing electronics. Examples: "inverter manufacturer", "designs controllers", "develops sensors", "avionics systems", "power electronics", "medical devices company", "defense contractor", "radar systems", "battery management", "motor drives", "automotive tier-1"

2. "reject_keywords": array of 100+ keywords/phrases that indicate NON-BUYERS. Examples: "authorized distributor", "news agency", "government body", "trade show", "free zone authority", "job vacancies", "insurance company", "banking", "real estate", "restaurant", "hotel", "car dealer", "recruitment agency"

3. "buyer_business_types": array of 50+ business types that are GOOD EMS prospects. Each entry: {"type": "...", "weight": 1-10}. Example: {"type": "defense electronics company", "weight": 9}

4. "reject_business_types": array of 50+ business types that are NEVER EMS prospects. Each entry: {"type": "...", "weight": 1-10}. Example: {"type": "newspaper", "weight": 10}

5. "name_patterns_reject": array of 40+ regex-compatible patterns for company names that are NEVER real EMS buyers. Example: "\\bAuthority\\b", "\\bFree Zone\\b", "Official Site$"

6. "name_patterns_buyer": array of 20+ regex-compatible patterns for names that suggest REAL companies. Example: "\\bElectronics\\b", "\\bSystems\\b", "\\bTechnolog"

7. "giant_oem_names": array of 80+ company names that are TOO LARGE to be realistic EMS outsourcing prospects (they have in-house manufacturing). Include full official names AND common abbreviations. Example: "Samsung", "Siemens", "Boeing", "Airbus", "Honeywell", "General Electric"

8. "distributor_indicators": array of 30+ phrases that indicate a company is a DISTRIBUTOR/RESELLER (not an OEM that makes products). Example: "authorized dealer", "exclusive distributor", "reseller of", "trading company", "wholesale supplier"

9. "domain_reject_patterns": array of 20+ domain substrings that indicate non-buyer websites. Example: "news", "jobs", "careers", "gov.", "magazine"

Reply with ONLY valid JSON (no markdown, no explanation). Make each category as comprehensive as possible - this is the complete knowledge base for the classifier.
PROMPT;

echo "Calling Gemini Flash to generate knowledge base...\n";

$payload = json_encode([
    'contents' => [['parts' => [['text' => $prompt]]]],
    'generationConfig' => [
        'temperature' => 0.1,
        'maxOutputTokens' => 8192,
        'responseMimeType' => 'application/json',
    ],
]);

$ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 60,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo "ERROR: Gemini API returned HTTP {$httpCode}\n";
    echo $response . "\n";
    exit(1);
}

$result = json_decode($response, true);
$text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

if (empty($text)) {
    echo "ERROR: Empty response from Gemini\n";
    exit(1);
}

// Parse the JSON response
$knowledge = json_decode($text, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    // Try to extract JSON from markdown code blocks
    if (preg_match('/```json\s*([\s\S]+?)\s*```/', $text, $m)) {
        $knowledge = json_decode($m[1], true);
    }
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "ERROR: Could not parse Gemini response as JSON\n";
        echo "Raw response:\n" . substr($text, 0, 500) . "\n";
        exit(1);
    }
}

// Add metadata
$knowledge['_meta'] = [
    'generated_at' => date('Y-m-d H:i:s'),
    'model' => 'gemini-2.0-flash',
    'purpose' => 'Local company classifier knowledge base for Starz Electronics CRM',
    'usage' => 'Loaded by CompanyClassifierService at runtime. No API calls needed.',
];

// Validate we got all categories
$required = ['buyer_keywords', 'reject_keywords', 'buyer_business_types', 'reject_business_types',
             'name_patterns_reject', 'name_patterns_buyer', 'giant_oem_names', 'distributor_indicators',
             'domain_reject_patterns'];

$missing = [];
foreach ($required as $key) {
    if (empty($knowledge[$key])) {
        $missing[] = $key;
    }
}

if (!empty($missing)) {
    echo "WARNING: Missing categories: " . implode(', ', $missing) . "\n";
    echo "Proceeding with partial data...\n";
}

// Write to file
file_put_contents($outputFile, json_encode($knowledge, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "\n✅ Knowledge base written to: {$outputFile}\n";

// Print stats
foreach ($required as $key) {
    $count = count($knowledge[$key] ?? []);
    echo "  {$key}: {$count} entries\n";
}

$totalBytes = filesize($outputFile);
echo "\n  Total size: " . round($totalBytes / 1024, 1) . " KB\n";
echo "\nThis knowledge base is loaded by CompanyClassifierService at runtime.\n";
echo "No Gemini API calls are made during discovery — all classification is local.\n";
