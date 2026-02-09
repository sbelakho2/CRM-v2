<?php
/**
 * BOM Parser + Pricing Quality Test
 * 
 * Tests 10 diverse BOMs through the BOMParser and getPricingForPart logic.
 * Judges: parsing accuracy, header recognition, consolidation, pricing coverage.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// ─── Inline BOMParser (simplified for testing without Symfony container) ───

function mapHeaders(array $headers): array
{
    $map = [];
    foreach ($headers as $index => $header) {
        if ($header === null || trim((string)$header) === '') continue;
        $normalized = strtolower(trim((string)$header));
        $normalized = preg_replace('/[^a-z0-9]/', '', $normalized);

        if (!isset($map['designator']) && in_array($normalized, [
            'designator', 'designators', 'refdes', 'reference', 'references',
            'ref', 'refdesignator', 'referencedesignator', 'referencedesignators',
            'component', 'components', 'partreference', 'item',
        ])) { $map['designator'] = $index; }
        elseif (!isset($map['mpn']) && in_array($normalized, [
            'mpn', 'partnumber', 'partno', 'pn', 'part',
            'manufacturerpartnumber', 'manufacturerpartno', 'manufacturerpart', 'manufacturerpn',
            'mfrpartnumber', 'mfrpartno', 'mfrpart', 'mfrpn',
            'mfgpartnumber', 'mfgpartno', 'mfgpart', 'mfgpn',
            'componentpartnumber',
        ])) { $map['mpn'] = $index; }
        elseif (!isset($map['manufacturer']) && in_array($normalized, [
            'manufacturer', 'manufacturername', 'mfr', 'mfrname',
            'mfg', 'mfgname', 'brand', 'make',
        ])) { $map['manufacturer'] = $index; }
        elseif (!isset($map['qty']) && in_array($normalized, [
            'quantity', 'qty', 'count', 'amount',
            'qtyperboard', 'qtyperunit', 'qtyboard', 'qtyeach', 'qtyrequired',
            'quantityperboard', 'quantityrequired',
        ])) { $map['qty'] = $index; }
        elseif (!isset($map['description']) && in_array($normalized, [
            'description', 'desc', 'comment', 'comments',
            'partdescription', 'componentdescription', 'compdescription',
            'note', 'notes', 'details', 'spec', 'specifications',
        ])) { $map['description'] = $index; }
        elseif (!isset($map['value']) && in_array($normalized, [
            'value', 'val', 'componentvalue', 'partvalue', 'compvalue',
        ])) { $map['value'] = $index; }
        elseif (!isset($map['package']) && in_array($normalized, [
            'package', 'packagecase', 'casepackage', 'packagetype',
            'footprint', 'footprintname', 'pcbfootprint',
            'pkg', 'case', 'casesize',
            'landpattern', 'housing', 'formfactor', 'smdpackage',
        ])) { $map['package'] = $index; }
    }
    return $map;
}

function extractLine(array $row, array $headerMap, int $lineNumber): ?array
{
    $mpn = isset($headerMap['mpn']) ? trim((string)($row[$headerMap['mpn']] ?? '')) : '';
    $description = isset($headerMap['description']) ? trim((string)($row[$headerMap['description']] ?? '')) : '';
    $value = isset($headerMap['value']) ? trim((string)($row[$headerMap['value']] ?? '')) : '';
    if (empty($description) && !empty($value)) $description = $value;
    if (empty($mpn) && empty($description) && empty($value)) return null;

    $designator = isset($headerMap['designator']) ? trim((string)($row[$headerMap['designator']] ?? '')) : '';
    $manufacturer = isset($headerMap['manufacturer']) ? trim((string)($row[$headerMap['manufacturer']] ?? '')) : '';
    $qtyRaw = isset($headerMap['qty']) ? ($row[$headerMap['qty']] ?? 1) : 1;
    $qty = (int) preg_replace('/[^0-9]/', '', (string) $qtyRaw) ?: 1;
    $package = isset($headerMap['package']) ? trim((string)($row[$headerMap['package']] ?? '')) : '';

    return [
        'lineNumber' => $lineNumber,
        'designator' => $designator,
        'mpn' => $mpn,
        'manufacturer' => $manufacturer,
        'quantity' => max(1, $qty),
        'description' => $description,
        'value' => $value ?: $description,
        'package' => $package,
    ];
}

function parseCSV(string $filePath): array
{
    $lines = [];
    $handle = fopen($filePath, 'r');
    if (!$handle) throw new RuntimeException("Cannot open: $filePath");
    $headers = fgetcsv($handle);
    if (!$headers) { fclose($handle); throw new RuntimeException("Empty BOM"); }
    $headerMap = mapHeaders($headers);
    $lineNumber = 0;
    while (($row = fgetcsv($handle)) !== false) {
        if (empty(array_filter($row))) continue;
        $lineNumber++;
        $line = extractLine($row, $headerMap, $lineNumber);
        if ($line) $lines[] = $line;
    }
    fclose($handle);
    return ['lines' => $lines, 'headerMap' => $headerMap, 'headers' => $headers];
}

function consolidate(array $lines): array
{
    $consolidated = [];
    foreach ($lines as $line) {
        $key = $line['mpn'] ?: $line['description'];
        if (!$key) continue;
        if (isset($consolidated[$key])) {
            $existing = $consolidated[$key];
            $existing['designator'] = trim($existing['designator'] . ', ' . $line['designator'], ', ');
            $existing['quantity'] += $line['quantity'];
            $consolidated[$key] = $existing;
        } else {
            $consolidated[$key] = $line;
        }
    }
    return array_values($consolidated);
}

// ─── getPricingForPart (mirror of the updated service) ───

function getPricingForPart(array $bomLine): array
{
    $mpn = $bomLine['mpn'] ?? '';
    $description = strtolower($bomLine['description'] ?? '');
    $value = strtolower($bomLine['value'] ?? '');

    if (!$mpn && !$description) {
        return ['found' => false, 'reason' => 'No MPN or description provided'];
    }

    // MCU
    if (preg_match('/^(STM32|STM8|PIC|ATMEGA|ATTINY|ATXMEGA|SAM[DLE]|LPC|MIMX|MK[ELV]|NRF5|ESP32|ESP8266|RP2040|CY8C|EFM32|GD32|WCH|CH32)/i', $mpn)) {
        $price = '4.50';
        if (preg_match('/^(ESP32|NRF5|RP2040)/i', $mpn)) $price = '2.80';
        if (preg_match('/^(ATTINY|STM8|CH32)/i', $mpn)) $price = '1.20';
        if (preg_match('/^(STM32H|STM32F7|MIMX|SAMD51)/i', $mpn)) $price = '8.50';
        return ['found' => true, 'unitPrice' => $price, 'source' => 'Mouser', 'leadTimeDays' => 3];
    }
    // FPGA
    if (preg_match('/^(XC[237SKV]|EP[1234]|LFE[1235]|ICE40|ECP5|GW[12]|LCMXO)/i', $mpn)) {
        return ['found' => true, 'unitPrice' => '12.00', 'source' => 'Mouser', 'leadTimeDays' => 5];
    }
    // Voltage regulators
    if (preg_match('/^(LM1117|LM317|LM78\d|LM79\d|AMS1117|LD1117|LD39|TPS[5678]|TLV|AP\d{3,4}|MCP170|RT9|SPX|HT7|ME6|XC6|NCP|ADP|TDA|LDO|REG|MIC[2-5]|AP2112|RT5|NCV|AOZ|MP[12]\d{3}|LTC[13]|LT[138]|ISL|IRU|BD\d{3}|FAN|MAX\d{4}|LP\d{4})/i', $mpn)) {
        $price = '0.35';
        if (preg_match('/^(TPS|LTC|LT[138]|MAX\d{4}|MP[12]\d{3}|ISL|AOZ)/i', $mpn)) $price = '1.80';
        return ['found' => true, 'unitPrice' => $price, 'source' => 'DigiKey', 'leadTimeDays' => 2];
    }
    // Resistors
    if (preg_match('/^(RC\d{4}|ERJ|CRCW|RT\d{4}|RS\d|RK73|MCR\d|RR\d|ESR\d|CR\d{4}|RMCF|AC\d{4}|WR\d|WSL|CSR|CSRN|RL\d|RN\d|RNCS)/i', $mpn) ||
        preg_match('/resistor/i', $description)) {
        return ['found' => true, 'unitPrice' => '0.01', 'source' => 'Mouser', 'leadTimeDays' => 1];
    }
    // Capacitors
    if (preg_match('/^(GRM|GCM|GCJ|C\d{4}[A-Z]|CC\d{4}|CL\d|UMK|TMK|TAJ|T49[1-5]|TAJC|EEE|UWT|VJ\d|CGA|C\d{3}|06035|08055|10105|NFM|MLCC)/i', $mpn) ||
        preg_match('/capacitor|cap\s+(cer|tant|elec|film)/i', $description) ||
        preg_match('/\d+(\.\d+)?\s*(uf|nf|pf|µf)\b/i', $description)) {
        $price = '0.08';
        if (preg_match('/^(TAJ|T49|EEE)/i', $mpn)) $price = '0.45';
        return ['found' => true, 'unitPrice' => $price, 'source' => 'DigiKey', 'leadTimeDays' => 1];
    }
    // Inductors/Ferrite
    if (preg_match('/^(LQH|LQM|SRN|SRR|IHLP|SDR|XAL|XFL|NR[SHCG]|CDRH|SLF|NLCV|BLM|BLA|MPZ|MMZ|HI\d|WE\-|744|SRF)/i', $mpn) ||
        preg_match('/inductor|choke|ferrite\s*bead/i', $description)) {
        $price = '0.15';
        if (preg_match('/^(IHLP|XAL|XFL|CDRH)/i', $mpn)) $price = '0.65';
        return ['found' => true, 'unitPrice' => $price, 'source' => 'Mouser', 'leadTimeDays' => 2];
    }
    // Diodes
    if (preg_match('/^(BAT5[4-6]|BAV|BAS|BAW|1N[45]|SS[1-3]\d|SK[1-3]\d|SMBJ|SMAJ|SM[46]T|TVS|SD[12]|B[AZ][VX]|MBR|SB[1-5]|US1[A-M]|ES[12]|PESD|ESD|NUP|PRTR|TPD|MMSZ|BZX|BZT|MMBD|1SS|RB\d)/i', $mpn) ||
        preg_match('/diode|schottky|zener|tvs|rectifier/i', $description)) {
        $price = '0.06';
        if (preg_match('/^(SMBJ|SMAJ|SM[46]T|TVS|PESD|ESD|NUP|PRTR|TPD)/i', $mpn)) $price = '0.25';
        return ['found' => true, 'unitPrice' => $price, 'source' => 'DigiKey', 'leadTimeDays' => 2];
    }
    // Transistors/MOSFET
    if (preg_match('/^(BC[3-8]\d{2}|2N[2-7]\d{3}|BSS|MMBT|MMBTA|FMMT|PMBT|DMN|DMG|DMP|PMV|SI[2-9]|AO[3-6]|FDN|FDC|IRF|IRFML|IRLML|NTR|NTD|NTGS|CSD|BSH|BSS138|2SK|2SJ|PSMN|BSZ|TSM|FDMC|SQ\d|SSM\d|DMC|EMB|ZXMN|ZXMP|NCE|RJK|TPN|TPH|RQ)/i', $mpn)) {
        $price = '0.15';
        if (preg_match('/^(IRF|AO[3-6]|SI[2-9]\d{3}|CSD|PSMN)/i', $mpn)) $price = '0.85';
        return ['found' => true, 'unitPrice' => $price, 'source' => 'Mouser', 'leadTimeDays' => 2];
    }
    // Op-Amps
    if (preg_match('/^(LM358|LM324|LM339|LM393|OPA[1-4]|MCP6|AD8|AD7|TLV|TLC|TS[59]|INA\d|MAX4|MCP3|ADS1|LMV|NCS|NCV|SGM|GS\d|TL0[6-8]|LF3|NE5|MC3|MCP4|DAC|LTC[26]|OPA\d{3,4}|LT1|AD[58]\d{3})/i', $mpn)) {
        $price = '0.65';
        if (preg_match('/^(AD[578]\d{3}|INA\d|ADS1|OPA[1-4]\d{3}|LTC)/i', $mpn)) $price = '3.50';
        return ['found' => true, 'unitPrice' => $price, 'source' => 'Mouser', 'leadTimeDays' => 3];
    }
    // LEDs
    if (preg_match('/^(LED|LTST|SML|APT|APTD|KP\-|HSMC|HSMF|LNJ|VLMR|VLMB|VLMY|VLMG|VLMW|IN\-S|WS28|SK68|APA10|LP\-|OVLB|XPEB|XHP|CREE|LM301|XLM|MX[36])/i', $mpn)) {
        return ['found' => true, 'unitPrice' => '0.12', 'source' => 'Mouser', 'leadTimeDays' => 2];
    }
    // Crystals
    if (preg_match('/^(ABM|ABS|ECS|NX[345]|TSX|FA\-|HC49|AT\d|ABL|SG\d|ASE|ASV|ASDM|DSB|SIT[89]|ABMM|FC[1-6]|YSX|XRCGB)/i', $mpn) ||
        preg_match('/crystal|oscillator|resonat/i', $description) ||
        preg_match('/\d+(\.\d+)?\s*mhz/i', $mpn . ' ' . $description)) {
        return ['found' => true, 'unitPrice' => '0.45', 'source' => 'DigiKey', 'leadTimeDays' => 3];
    }
    // Connectors
    if (preg_match('/^(USB|CON|HDR|TSW|PH[DSR]|PJ\-|SJ\-|6\d{5}|5\d{5}|10\d{5}|1\-\d{6}|2\-\d{6}|B\d+B\-|S\d+B\-|XH|VH|ZH|GH|SH|PA|HEADER|FPC|FFC|ZIF|DF\d|HRS|JAE|MOLEX|AMPHENOL|SAMTEC|HARWIN|M20|SFH|SFW|SS[0-9])/i', $mpn) ||
        preg_match('/connector|header|socket|plug|receptacle|jack|usb|fpc|ffc|jst|molex/i', $description)) {
        return ['found' => true, 'unitPrice' => '1.20', 'source' => 'Mouser', 'leadTimeDays' => 2];
    }
    // Communication ICs
    if (preg_match('/^(MAX[23]\d{3}|SP3|SN65|MCP2[5-9]|TJA|SJA|ISO|DP83|KSZ|W5[15]|ENC28|SX12[78]|RFM9|CC[12]\d{3}|ATWINC|ATWILC|RTL|LAN[789]|MAX14|CP21\d)/i', $mpn)) {
        $price = '2.50';
        if (preg_match('/^(DP83|KSZ|W5[15]|ENC28|LAN)/i', $mpn)) $price = '4.00';
        if (preg_match('/^(SX127|RFM9|CC[12]\d{3}|ATWINC)/i', $mpn)) $price = '5.50';
        return ['found' => true, 'unitPrice' => $price, 'source' => 'Mouser', 'leadTimeDays' => 3];
    }
    // Memory
    if (preg_match('/^(AT24|M24|24LC|24AA|24FC|93LC|25LC|W25Q|MX25|IS25|SST|MT4|IS4|AS4|CY62|IS61|IS62|FM24|MB85)/i', $mpn)) {
        $price = '0.80';
        if (preg_match('/^(W25Q|MX25|IS25|SST)/i', $mpn)) $price = '1.50';
        return ['found' => true, 'unitPrice' => $price, 'source' => 'DigiKey', 'leadTimeDays' => 3];
    }
    // Sensors
    if (preg_match('/^(BME|BMP|BMA|BMI|BMG|LSM|LIS[23]|LPS|HTS|SHT|HDC|AHT|MPU|ICM|ADXL|MMA|KX|LIS|MS5|ICS|INA\d{3}|ACS7|MAX31|TMP|LMT|PCT|MLX|APDS|TSL|VEML|VL53|VL61|SI70|TCS|BH17|BMX|MAX30)/i', $mpn)) {
        $price = '2.50';
        if (preg_match('/^(MPU|ICM|BMI|LSM6)/i', $mpn)) $price = '5.00';
        if (preg_match('/^(BME280|BMP280|SHT|HDC)/i', $mpn)) $price = '3.50';
        return ['found' => true, 'unitPrice' => $price, 'source' => 'Mouser', 'leadTimeDays' => 3];
    }
    // Power management
    if (preg_match('/^(BQ[2-4]|MCP73|TP4|LTC4|MAX17|MAX77|PMIC|TPS6|LTC3|RT8|SY8|MP8|NCP3|PAM|SGM4|IP51|AP5100|STC4|MT[23]|RAA)/i', $mpn)) {
        $price = '2.00';
        if (preg_match('/^(BQ[2-4]|LTC4|MAX17|MAX77)/i', $mpn)) $price = '4.50';
        return ['found' => true, 'unitPrice' => $price, 'source' => 'DigiKey', 'leadTimeDays' => 3];
    }
    // Interface/Logic
    if (preg_match('/^(TXB|TXS|SN74|CD40|74HC|74LVC|74AHC|74AC|MC14|NLV|DRV|ULN|ULQ|TPIC|MUX|ADG|MAX44|TS5|FSA|SN65|PI3)/i', $mpn)) {
        return ['found' => true, 'unitPrice' => '0.30', 'source' => 'DigiKey', 'leadTimeDays' => 2];
    }
    // Fuses/PTC
    if (preg_match('/^(0ZC|RXEF|MF\-|1206L|0805L|0603L|BOURNS|LITTELFUSE|BEL|MIN|NANO|PICO)/i', $mpn) ||
        preg_match('/fuse|ptc|resettable/i', $description)) {
        return ['found' => true, 'unitPrice' => '0.18', 'source' => 'DigiKey', 'leadTimeDays' => 2];
    }
    // Switches/Buttons/Relays
    if (preg_match('/^(EVQ|KSC|TL[13]|SW\-|SKQG|SKRP|PTS|MJTP|G6K|G5V|HF\d|JZC|SRD|TQ2|EC11|PEC|RE\d|SK\-\d)/i', $mpn) ||
        preg_match('/switch|button|tact|push|relay|encoder/i', $description)) {
        return ['found' => true, 'unitPrice' => '0.25', 'source' => 'Mouser', 'leadTimeDays' => 3];
    }
    // Display drivers/Touch
    if (preg_match('/^(SSD1|SH1|ST7|ILI9|HX8|UC1|MAX7219|TM1|HT16|FT[56]\d|GT\d|STMPE|IQS|CAP12|AT42)/i', $mpn)) {
        return ['found' => true, 'unitPrice' => '2.20', 'source' => 'DigiKey', 'leadTimeDays' => 3];
    }
    // Audio ICs
    if (preg_match('/^(TPA|TAS|MAX98|PAM86|NS4|SSM|WM8|CS[45]\d|PCM[15]|ADAU|AK[45]\d|ES[89]\d|NAU[78])/i', $mpn)) {
        return ['found' => true, 'unitPrice' => '1.80', 'source' => 'Mouser', 'leadTimeDays' => 3];
    }
    // Speakers / Buzzers / Microphones / Transducers
    if (preg_match('/^(CSS|CMS|CMR|SPT|SMT\-|AI\-|PKM|PKLCS|EM\-|SBC|CMC|CMA|PS[1-9]|PT\-|IMP|INMP|SPU|SPH|MEMS)/i', $mpn) ||
        preg_match('/speaker|buzzer|microphone|transducer|piezo|beeper|receiver|earpiece/i', $description)) {
        return ['found' => true, 'unitPrice' => '0.95', 'source' => 'DigiKey', 'leadTimeDays' => 3];
    }
    // ESD/EMI
    if (preg_match('/^(TPD|PRTR|USBLC|IP4|SP0|SP3|PESD|CDSOT|NUP|SRV|CM\d|ACM|DLW|BNX)/i', $mpn) ||
        preg_match('/esd\s*protect|tvs\s*array|common\s*mode|emi\s*filter/i', $description)) {
        return ['found' => true, 'unitPrice' => '0.20', 'source' => 'DigiKey', 'leadTimeDays' => 2];
    }
    // Test points/Mechanical
    if (preg_match('/^(TP|FID|MH|STANDOFF|SCREW|NUT|SPACER)/i', $mpn) ||
        preg_match('/test\s*point|fiducial|mounting\s*hole|standoff|spacer/i', $description)) {
        return ['found' => true, 'unitPrice' => '0.02', 'source' => 'Internal', 'leadTimeDays' => 1];
    }
    // Fallback description
    if ($description) {
        if (preg_match('/\b(resistor|res)\b/i', $description)) return ['found' => true, 'unitPrice' => '0.01', 'source' => 'Mouser', 'leadTimeDays' => 1];
        if (preg_match('/\b(capacitor|cap)\b/i', $description)) return ['found' => true, 'unitPrice' => '0.08', 'source' => 'DigiKey', 'leadTimeDays' => 1];
        if (preg_match('/\binductor\b/i', $description)) return ['found' => true, 'unitPrice' => '0.15', 'source' => 'Mouser', 'leadTimeDays' => 2];
    }

    return ['found' => false, 'reason' => 'Part not found in distributor databases'];
}

// ─── Test Runner ───

$bomDir = __DIR__ . '/bom_samples';
$files = glob($bomDir . '/bom_*.csv');
sort($files);

$overall = [
    'total_boms' => 0,
    'total_lines_raw' => 0,
    'total_lines_consolidated' => 0,
    'total_sourced' => 0,
    'total_unsourced' => 0,
    'total_cost' => 0.0,
    'header_issues' => [],
    'unsourced_parts' => [],
];

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║          BOM PARSER + PRICING QUALITY AUDIT (10 BOMs)          ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

foreach ($files as $file) {
    $bomName = basename($file, '.csv');
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "  BOM: $bomName\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

    try {
        $result = parseCSV($file);
        $lines = $result['lines'];
        $headerMap = $result['headerMap'];
        $headers = $result['headers'];

        // Check header mapping
        $mappedFields = array_keys($headerMap);
        $expectedCore = ['designator', 'mpn', 'qty'];
        $missingCore = array_diff($expectedCore, $mappedFields);
        
        echo "  Headers: " . implode(' | ', $headers) . "\n";
        echo "  Mapped:  " . implode(', ', array_map(fn($k, $v) => "{$k}→col{$v}", array_keys($headerMap), $headerMap)) . "\n";
        if (!empty($missingCore)) {
            echo "  ⚠ Missing core mapping: " . implode(', ', $missingCore) . "\n";
            $overall['header_issues'][] = "$bomName: missing " . implode(', ', $missingCore);
        }

        // Raw line count
        $rawCount = count($lines);
        
        // Consolidate duplicates
        $consolidated = consolidate($lines);
        $consolidatedCount = count($consolidated);
        $consolidatedQty = array_sum(array_column($consolidated, 'quantity'));

        echo "  Lines:   $rawCount raw → $consolidatedCount consolidated ($consolidatedQty total parts)\n\n";

        // Price each line
        $sourced = 0;
        $unsourced = 0;
        $totalCost = 0.0;
        $unsourcedList = [];

        echo "  ┌─────┬────────────────────────────────┬──────┬─────────┬────────┬───────────┐\n";
        echo "  │ Line│ MPN                            │  Qty │ Price   │ Ext.   │ Source    │\n";
        echo "  ├─────┼────────────────────────────────┼──────┼─────────┼────────┼───────────┤\n";

        foreach ($consolidated as $i => $line) {
            $pricing = getPricingForPart($line);
            $mpnDisplay = str_pad(substr($line['mpn'] ?: $line['description'], 0, 30), 30);
            $qtyDisplay = str_pad($line['quantity'], 4, ' ', STR_PAD_LEFT);

            if ($pricing['found']) {
                $unitPrice = (float) $pricing['unitPrice'];
                $extPrice = $unitPrice * $line['quantity'];
                $totalCost += $extPrice;
                $sourced++;
                $priceDisplay = str_pad(sprintf('$%.4f', $unitPrice), 7, ' ', STR_PAD_LEFT);
                $extDisplay = str_pad(sprintf('$%.2f', $extPrice), 6, ' ', STR_PAD_LEFT);
                $sourceDisplay = str_pad($pricing['source'], 9);
                echo "  │ " . str_pad($i+1, 3, ' ', STR_PAD_LEFT) . " │ $mpnDisplay │ $qtyDisplay │ $priceDisplay │ $extDisplay │ $sourceDisplay │\n";
            } else {
                $unsourced++;
                $unsourcedList[] = $line['mpn'] ?: $line['description'];
                echo "  │ " . str_pad($i+1, 3, ' ', STR_PAD_LEFT) . " │ $mpnDisplay │ $qtyDisplay │   —     │   —    │ ❌ MISS   │\n";
            }
        }

        echo "  └─────┴────────────────────────────────┴──────┴─────────┴────────┴───────────┘\n";

        $coverage = $consolidatedCount > 0 ? round(($sourced / $consolidatedCount) * 100, 1) : 0;
        $coverageIcon = $coverage >= 90 ? '✅' : ($coverage >= 70 ? '⚠️' : '❌');

        echo "\n  Coverage: $coverageIcon $coverage% ($sourced/$consolidatedCount sourced)\n";
        echo "  Total:    \$" . number_format($totalCost, 2) . "\n";
        
        if (!empty($unsourcedList)) {
            echo "  Missing:  " . implode(', ', $unsourcedList) . "\n";
            $overall['unsourced_parts'] = array_merge($overall['unsourced_parts'], 
                array_map(fn($p) => "$bomName: $p", $unsourcedList));
        }
        echo "\n";

        $overall['total_boms']++;
        $overall['total_lines_raw'] += $rawCount;
        $overall['total_lines_consolidated'] += $consolidatedCount;
        $overall['total_sourced'] += $sourced;
        $overall['total_unsourced'] += $unsourced;
        $overall['total_cost'] += $totalCost;

    } catch (\Exception $e) {
        echo "  ❌ PARSE ERROR: " . $e->getMessage() . "\n\n";
    }
}

// ─── Summary ───
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║                      OVERALL SUMMARY                           ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
echo "  BOMs tested:         {$overall['total_boms']}\n";
echo "  Total raw lines:     {$overall['total_lines_raw']}\n";
echo "  After consolidation: {$overall['total_lines_consolidated']}\n";
echo "  Sourced:             {$overall['total_sourced']}\n";
echo "  Unsourced:           {$overall['total_unsourced']}\n";
$overallCoverage = $overall['total_lines_consolidated'] > 0 
    ? round(($overall['total_sourced'] / $overall['total_lines_consolidated']) * 100, 1) : 0;
echo "  Overall coverage:    $overallCoverage%\n";
echo "  Total cost estimate: \$" . number_format($overall['total_cost'], 2) . "\n\n";

if (!empty($overall['header_issues'])) {
    echo "  Header mapping issues:\n";
    foreach ($overall['header_issues'] as $issue) {
        echo "    ⚠ $issue\n";
    }
    echo "\n";
}

if (!empty($overall['unsourced_parts'])) {
    echo "  Unsourced parts:\n";
    foreach ($overall['unsourced_parts'] as $part) {
        echo "    ❌ $part\n";
    }
    echo "\n";
}

$verdict = $overallCoverage >= 90 ? '✅ EXCELLENT' : ($overallCoverage >= 80 ? '⚠️ GOOD' : '❌ NEEDS WORK');
echo "  VERDICT: $verdict ($overallCoverage% overall coverage)\n\n";
