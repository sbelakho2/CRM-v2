<?php

namespace App\Service;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Psr\Log\LoggerInterface;

/**
 * Custom read filter for memory-efficient Excel parsing.
 * Always loads the header row plus the requested chunk range.
 */
class ChunkReadFilter implements IReadFilter
{
    private int $startRow = 1;
    private int $endRow = 1;
    private int $headerRow = 1;

    public function setRows(int $startRow, int $chunkSize): void
    {
        $this->startRow = $startRow;
        $this->endRow   = $startRow + $chunkSize - 1;
    }

    public function setHeaderRow(int $headerRow): void
    {
        $this->headerRow = $headerRow;
    }

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        return $row === $this->headerRow
            || ($row >= $this->startRow && $row <= $this->endRow);
    }
}

/**
 * Robust BOM Parser Service
 *
 * Parses Bill of Materials files from virtually any EDA / ERP source:
 *   CSV  (comma, semicolon, tab, pipe delimited)
 *   TSV
 *   Excel (XLSX, XLS, ODS)
 *
 * Robustness features:
 *   - Auto-detects the header row (scans first 25 rows for best match)
 *   - Auto-detects CSV delimiter  (,  ;  TAB  |)
 *   - Fuzzy / substring header matching as fallback
 *   - Handles  *  #  ()  prefixes / suffixes in headers
 *   - Cleans MPN values  (strips whitespace, quotes, invisible chars)
 *   - Parses quantity from "2 pcs", "x3", "3 ea", "2,000", etc.
 *   - Designator-counting as quantity fallback  (R1-R10 → qty 10)
 *   - UTF-8 BOM stripping
 *   - Memory-efficient chunked Excel reading
 *   - Infers MPN column from data patterns when headers are ambiguous
 */
class BOMParser
{
    private const EXCEL_CHUNK_SIZE = 500;
    private const HEADER_SCAN_DEPTH = 25;

    // ─── Header dictionaries ────────────────────────────────────

    /**
     * Exact-match tokens per field (normalized = lowercase, non-alnum stripped).
     * Checked first; +3 score per match.
     */
    private const HEADER_EXACT = [
        'designator' => [
            'designator', 'designators', 'refdes', 'reference', 'references',
            'ref', 'refdesignator', 'referencedesignator', 'referencedesignators',
            'component', 'components', 'partreference', 'circuitdesignator',
            'refid', 'referenceid', 'name',
        ],
        'mpn' => [
            'mpn', 'partnumber', 'partno', 'pn',
            'manufacturerpartnumber', 'manufacturerpartno', 'manufacturerpart', 'manufacturerpn',
            'mfrpartnumber', 'mfrpartno', 'mfrpart', 'mfrpn',
            'mfgpartnumber', 'mfgpartno', 'mfgpart', 'mfgpn',
            'componentpartnumber', 'partnum', 'mfrnumber', 'mfgnumber',
        ],
        'manufacturer' => [
            'manufacturer', 'manufacturername', 'mfr', 'mfrname',
            'mfg', 'mfgname', 'brand', 'make', 'maker',
            'componentmanufacturer',
        ],
        'qty' => [
            'quantity', 'qty', 'count', 'amount', 'nbr',
            'qtyperboard', 'qtyperunit', 'qtyboard', 'qtyeach', 'qtyrequired',
            'quantityperboard', 'quantityrequired', 'quantityneeded',
            'pcsperboard', 'pcs', 'numberofparts', 'numparts', 'num',
            'bomqty', 'orderqty', 'orderquantity',
        ],
        'description' => [
            'description', 'desc', 'comment', 'comments',
            'partdescription', 'componentdescription', 'compdescription',
            'descriptionvalue', 'valuedescription',
            'note', 'notes', 'details', 'spec', 'specifications',
            'partname', 'componentname',
        ],
        'value' => [
            'value', 'val', 'componentvalue', 'partvalue', 'compvalue',
            'nominalvalue', 'rating',
        ],
        'package' => [
            'package', 'packagecase', 'casepackage', 'packagetype',
            'footprint', 'footprintname', 'pcbfootprint',
            'packagefootprint', 'footprintpackage',
            'pkg', 'case', 'casesize', 'casecode',
            'landpattern', 'housing', 'formfactor', 'smdpackage',
            'mountingtype', 'bodysize',
        ],
        'supplier' => [
            'supplier', 'suppliername', 'supplier1',
            'distributor', 'distributorname', 'dist',
            'vendor', 'vendorname', 'purchasefrom', 'source',
        ],
        'supplier_pn' => [
            'supplierpartnumber', 'supplierpartnumber1', 'supplierpartno', 'supplierpart',
            'spn', 'supplierpn',
            'distributorpartnumber', 'distributorpartno', 'distributorpn',
            'vendorpartnumber', 'vendorpn',
            'digikeypartnumber', 'digikeypn', 'digikeypart',
            'mouserpartnumber', 'mouserpn', 'mouserpart',
            'ordernumber', 'orderingcode', 'dpn', 'ordercode',
        ],
        'category' => [
            'category', 'classification', 'partcategory', 'componentcategory',
            'type', 'parttype', 'componenttype', 'class', 'group',
        ],
        'remark' => [
            'remark', 'remarks', 'alternativepn', 'altpn', 'alternatepn',
            'substitutepart', 'substitute', 'altpartnumber', 'replacement',
            'alternate', 'alternative', 'crossreference', 'xref',
        ],
        'stock_quantity' => [
            'stockquantity', 'stockqty', 'orderquantity', 'orderqty',
            'qtesouhaitestock', 'qtesouhaite', 'qtestock',
            'totalquantity', 'totalqty', 'buyqty', 'purchaseqty',
            'extendedquantity', 'extqty', 'fullqty',
            'buildquantity', 'buildqty', 'lotqty', 'lotsize',
            'annualqty', 'annualquantity', 'annualusage',
            'projectqty', 'projectquantity',
            'moq', 'moqqty', 'minimumorderquantity',
        ],
        'unit_price' => [
            'unitprice', 'unitpriceusd', 'unitcost', 'unitcostusd',
            'priceperunit', 'ppu', 'priceea', 'priceeach',
            'costea', 'costeach', 'costperunit',
            'price', 'cost', 'rate',
            'supplierprice', 'quotedprice', 'vendorprice',
            'buyprice', 'purchaseprice',
        ],
        'total_price' => [
            'totalprice', 'totalpriceusd', 'totalcost', 'totalcostusd',
            'extendedprice', 'extprice', 'extcost', 'extendedcost',
            'lineprice', 'linecost', 'linetotal',
            'amount', 'amountusd', 'subtotal',
        ],
    ];

    /**
     * Fuzzy substrings per field: if exact match fails, check if the normalized
     * header *contains* any of these.  +1 score per match.
     */
    private const HEADER_FUZZY = [
        'mpn'          => ['partnum', 'partno', 'partnumber', 'mfgpart', 'mfrpart', 'mpn'],
        'designator'   => ['designat', 'refdes', 'reference'],
        'qty'          => ['qty', 'quant', 'count', 'amount', 'pcs'],
        'description'  => ['descri', 'comment', 'note'],
        'value'        => ['value'],
        'package'      => ['package', 'footprint', 'case', 'housing', 'mounting'],
        'manufacturer' => ['manufactur', 'mfr', 'mfg', 'brand'],
        'supplier'     => ['supplier', 'distribut', 'vendor'],
        'supplier_pn'  => ['supplierpn', 'distributorpn', 'orderno', 'ordercode'],
        'category'     => ['category', 'classifi'],
        'remark'       => ['remark', 'alternat', 'substitut', 'replacement'],
        'stock_quantity' => ['stockq', 'orderq', 'souhaite', 'buildq', 'lotq', 'annualq', 'projectq', 'totalq', 'purchaseq', 'buyq', 'moq'],
        'unit_price'   => ['unitpri', 'unitcost', 'priceper', 'priceea', 'costper', 'costea'],
        'total_price'  => ['totalpri', 'totalcost', 'extpri', 'extcost', 'linepri', 'linecost', 'linetotal', 'subtotal'],
    ];

    /**
     * Headers that must NOT be mapped as MPN (line-number / index columns).
     */
    private const MPN_BLACKLIST = [
        'item', 'line', 'row', 'no', 'number', 'sno', 'srno', 'serialnumber',
        'index', 'id', 'linenumber', 'itemnumber', 'itemno', 'lineno',
        'rownumber', 'rowno', 'seq', 'sequence',
    ];

    public function __construct(
        private LoggerInterface $logger,
    ) {}

    // ═══════════════════════════════════════════════════════════════
    //  Public API  (unchanged signatures)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Parse a BOM file and return a standardized array of lines.
     *
     * @param  string      $filePath  Absolute path to the BOM file
     * @param  string|null $extension Optional override (csv, xlsx, xls, tsv, ods, txt)
     * @return array<int, array{lineNumber:int, designator:string, mpn:string,
     *     manufacturer:string, quantity:int, description:string, value:string,
     *     package:string, supplier:string, supplier_pn:string, category:string,
     *     remark:string}>
     */
    public function parse(string $filePath, ?string $extension = null): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File does not exist: {$filePath}");
        }

        $extension = $extension
            ? strtolower(trim($extension, '. '))
            : strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv', 'tsv', 'txt' => $this->parseCSV($filePath),
            'xlsx', 'xls', 'ods' => $this->parseExcel($filePath),
            default => throw new \RuntimeException("Unsupported file format: {$extension}"),
        };
    }

    /**
     * Consolidate duplicate lines (same MPN / description).
     * Merges designators and sums quantities.
     */
    public function consolidate(array $lines): array
    {
        $consolidated = [];

        foreach ($lines as $line) {
            $key = $this->normalizeMPN($line['mpn']) ?: trim($line['description']);
            if ($key === '') {
                continue;
            }

            if (isset($consolidated[$key])) {
                $existing = &$consolidated[$key];
                $existing['designator'] = $this->mergeDesignators(
                    $existing['designator'], $line['designator']
                );
                $existing['quantity'] += $line['quantity'];
                // Accumulate stock quantity and total price
                if (!empty($line['stock_quantity'])) {
                    $existing['stock_quantity'] = ($existing['stock_quantity'] ?? 0) + $line['stock_quantity'];
                }
                if (!empty($line['total_price'])) {
                    $existing['total_price'] = ($existing['total_price'] ?? 0) + $line['total_price'];
                }
                // Keep unit_price from the first occurrence (same part = same price)
                if (empty($existing['unit_price']) && !empty($line['unit_price'])) {
                    $existing['unit_price'] = $line['unit_price'];
                }
                // Fill in richer metadata from later rows
                foreach (['description', 'package', 'manufacturer', 'value', 'category', 'remark'] as $f) {
                    if (empty($existing[$f]) && !empty($line[$f])) {
                        $existing[$f] = $line[$f];
                    }
                }
                unset($existing);
            } else {
                $consolidated[$key] = $line;
            }
        }

        return array_values($consolidated);
    }

    /**
     * Round all BOM line quantities UP to the nearest multiple of $orderMultiple.
     *
     * For example, with $orderMultiple = 10:
     *   qty=1 → 10, qty=3 → 10, qty=12 → 20, qty=20 → 20
     *
     * @param array $lines       Parsed/consolidated BOM lines
     * @param int   $orderMultiple  The quantity multiple (e.g. 10, 50, 100). Must be ≥ 1.
     * @return array  Lines with quantities rounded up
     */
    public function applyOrderMultiple(array $lines, int $orderMultiple): array
    {
        if ($orderMultiple <= 1) {
            return $lines;
        }

        foreach ($lines as &$line) {
            $qty = $line['quantity'] ?? 1;
            $line['quantity'] = (int) ceil($qty / $orderMultiple) * $orderMultiple;
            $line['firm_quantity'] = true; // Signal PricingEngine not to inflate with vendor MOQ
        }
        unset($line);

        $this->logger->info('Applied order multiple to BOM', [
            'order_multiple' => $orderMultiple,
            'lines_count' => count($lines),
        ]);

        return $lines;
    }

    /**
     * Validate BOM structure and return human-readable warnings.
     */
    public function validate(array $lines): array
    {
        $errors = [];

        if (empty($lines)) {
            $errors[] = 'BOM file is empty or has no valid data';
            return $errors;
        }

        foreach ($lines as $i => $line) {
            $num = $i + 1;
            if (empty($line['mpn']) && empty($line['description'])) {
                $errors[] = "Line {$num}: Missing MPN and description";
            }
            if ($line['quantity'] <= 0) {
                $errors[] = "Line {$num}: Invalid quantity ({$line['quantity']})";
            }
        }

        return $errors;
    }

    // ═══════════════════════════════════════════════════════════════
    //  CSV Parsing
    // ═══════════════════════════════════════════════════════════════

    private function parseCSV(string $filePath): array
    {
        $raw = file_get_contents($filePath);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read file: {$filePath}");
        }

        // Strip UTF-8 BOM
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        // Normalize line endings
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        // Auto-detect delimiter
        $delimiter = $this->detectCSVDelimiter($raw);

        // Parse into rows
        $rows = [];
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $raw);
        rewind($handle);
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        if (count($rows) < 2) {
            throw new \RuntimeException("BOM file has fewer than 2 rows — nothing to parse");
        }

        // Auto-detect header row
        [$headerRowIndex, $headerMap] = $this->detectHeaderRow($rows);

        $this->logger->info('CSV BOM parsed', [
            'file'       => basename($filePath),
            'delimiter'  => $delimiter === "\t" ? 'TAB' : $delimiter,
            'header_row' => $headerRowIndex + 1,
            'total_rows' => count($rows),
            'mapped'     => array_keys($headerMap),
        ]);

        // Extract data rows (everything after the header)
        $lines = [];
        $lineNumber = 0;
        for ($i = $headerRowIndex + 1; $i < count($rows); $i++) {
            if ($this->isEmptyRow($rows[$i])) {
                continue;
            }
            $lineNumber++;
            $line = $this->extractLine($rows[$i], $headerMap, $lineNumber);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Auto-detect CSV delimiter by scoring candidates on consistency.
     */
    private function detectCSVDelimiter(string $raw): string
    {
        $candidates = [',', ';', "\t", '|'];
        $sampleLines = array_slice(explode("\n", $raw), 0, 15);
        $scores = [];

        foreach ($candidates as $d) {
            $counts = [];
            foreach ($sampleLines as $text) {
                $text = trim($text);
                if ($text === '') continue;
                $counts[] = substr_count($text, $d);
            }
            $counts = array_filter($counts, fn($c) => $c > 0);
            if (empty($counts)) {
                $scores[$d] = 0;
                continue;
            }
            $avg = array_sum($counts) / count($counts);
            $variance = 0;
            foreach ($counts as $c) {
                $variance += ($c - $avg) ** 2;
            }
            $variance = count($counts) > 1 ? $variance / (count($counts) - 1) : 0;
            // High average + low variance = good delimiter
            $scores[$d] = $avg / (1 + sqrt($variance));
        }

        arsort($scores);
        $best = array_key_first($scores);
        return ($scores[$best] > 0) ? $best : ',';
    }

    // ═══════════════════════════════════════════════════════════════
    //  Excel Parsing  (chunked, memory-efficient)
    // ═══════════════════════════════════════════════════════════════

    private function parseExcel(string $filePath): array
    {
        try {
            $inputFileType = IOFactory::identify($filePath);
            $reader = IOFactory::createReader($inputFileType);
            $reader->setReadDataOnly(true);

            // Real dimensions from worksheet metadata
            $worksheetInfo = $reader->listWorksheetInfo($filePath);
            $totalRows     = $worksheetInfo[0]['totalRows'] ?? 0;
            $highestColIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
                $worksheetInfo[0]['lastColumnLetter'] ?? 'A'
            );

            if ($totalRows < 2) {
                throw new \RuntimeException("Empty BOM file — no data rows found");
            }

            // Load the first N rows to auto-detect the header row
            $scanDepth   = min(self::HEADER_SCAN_DEPTH, $totalRows);
            $chunkFilter = new ChunkReadFilter();
            $chunkFilter->setRows(1, $scanDepth);
            $reader->setReadFilter($chunkFilter);

            $spreadsheet = $reader->load($filePath);
            $sheet       = $spreadsheet->getActiveSheet();

            $scanRows = [];
            for ($r = 1; $r <= $scanDepth; $r++) {
                $row = [];
                for ($c = 1; $c <= $highestColIdx; $c++) {
                    $row[] = $sheet->getCellByColumnAndRow($c, $r)->getValue();
                }
                $scanRows[] = $row;
            }
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            // Detect header
            [$headerRowIndex, $headerMap] = $this->detectHeaderRow($scanRows);
            $headerRowExcel = $headerRowIndex + 1; // 1-based

            $this->logger->info('Processing Excel BOM', [
                'file'       => basename($filePath),
                'total_rows' => $totalRows,
                'header_row' => $headerRowExcel,
                'mapped'     => array_keys($headerMap),
                'chunk_size' => self::EXCEL_CHUNK_SIZE,
            ]);

            // Collect data rows already in the scan window
            $lines      = [];
            $lineNumber = 0;
            for ($i = $headerRowIndex + 1; $i < count($scanRows); $i++) {
                if ($this->isEmptyRow($scanRows[$i])) continue;
                $lineNumber++;
                $line = $this->extractLine($scanRows[$i], $headerMap, $lineNumber);
                if ($line !== null) $lines[] = $line;
            }

            // Chunk-read remaining rows beyond the scan window
            $nextRow = $scanDepth + 1;
            if ($nextRow <= $totalRows) {
                $chunkFilter->setHeaderRow($headerRowExcel);

                for ($startRow = $nextRow; $startRow <= $totalRows; $startRow += self::EXCEL_CHUNK_SIZE) {
                    $chunkFilter->setRows($startRow, self::EXCEL_CHUNK_SIZE);
                    $spreadsheet = $reader->load($filePath);
                    $sheet       = $spreadsheet->getActiveSheet();
                    $endRow      = min($startRow + self::EXCEL_CHUNK_SIZE - 1, $totalRows);

                    for ($rowNum = $startRow; $rowNum <= $endRow; $rowNum++) {
                        $row = [];
                        for ($c = 1; $c <= $highestColIdx; $c++) {
                            $row[] = $sheet->getCellByColumnAndRow($c, $rowNum)->getValue();
                        }
                        if ($this->isEmptyRow($row)) continue;
                        $lineNumber++;
                        $line = $this->extractLine($row, $headerMap, $lineNumber);
                        if ($line !== null) $lines[] = $line;
                    }

                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);
                }
            }

            $this->logger->info('Excel BOM parsing complete', [
                'file'         => basename($filePath),
                'lines_parsed' => count($lines),
            ]);

            return $lines;

        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Excel parsing failed', [
                'file'  => $filePath,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("Failed to parse Excel file: " . $e->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  Header Auto-Detection
    // ═══════════════════════════════════════════════════════════════

    /**
     * Scan the first N rows and pick the best header row.
     *
     * Scoring: +3 exact match, +1 fuzzy match.
     * Row must have ≥ 2 matched fields and mostly non-numeric cells.
     *
     * @return array{0: int, 1: array<string, int>}  [rowIndex, headerMap]
     */
    private function detectHeaderRow(array $rows): array
    {
        $bestScore = 0;
        $bestIndex = 0;
        $bestMap   = [];
        $limit     = min(count($rows), self::HEADER_SCAN_DEPTH);

        for ($i = 0; $i < $limit; $i++) {
            $row = $rows[$i];

            // Must have ≥ 2 non-empty cells
            $nonEmpty = array_filter($row, fn($v) => $v !== null && trim((string)$v) !== '');
            if (count($nonEmpty) < 2) continue;

            // A header row should have mostly non-numeric cells
            $stringCount = 0;
            foreach ($nonEmpty as $cell) {
                if (!is_numeric($cell)) $stringCount++;
            }
            if ($stringCount < 2) continue;

            [$map, $score] = $this->scoreHeaderRow($row);

            if ($score > $bestScore && count($map) >= 2) {
                $bestScore = $score;
                $bestIndex = $i;
                $bestMap   = $map;
            }
        }

        // Fallback: if nothing scored ≥ 2, try row 0 with simple mapping
        if (empty($bestMap)) {
            $bestMap   = $this->mapHeadersSimple($rows[0]);
            $bestIndex = 0;
        }

        // If still no MPN column, infer from data patterns
        if (!isset($bestMap['mpn'])) {
            $bestMap = $this->inferMPNFromData($rows, $bestIndex, $bestMap);
        }

        return [$bestIndex, $bestMap];
    }

    /**
     * Score a single candidate header row.
     *
     * @return array{0: array<string, int>, 1: int}  [map, score]
     */
    private function scoreHeaderRow(array $row): array
    {
        $map   = [];
        $score = 0;

        foreach ($row as $index => $cell) {
            if ($cell === null || trim((string)$cell) === '') continue;

            $normalized = $this->normalizeHeader((string)$cell);
            if ($normalized === '') continue;

            $matched = false;

            // ── Exact match (+3) ──
            foreach (self::HEADER_EXACT as $field => $tokens) {
                if (isset($map[$field])) continue;
                if (in_array($normalized, $tokens, true)) {
                    $map[$field] = $index;
                    $score += 3;
                    $matched = true;
                    break;
                }
            }

            // ── Fuzzy substring match (+1) ──
            if (!$matched) {
                foreach (self::HEADER_FUZZY as $field => $substrings) {
                    if (isset($map[$field])) continue;
                    foreach ($substrings as $sub) {
                        if (str_contains($normalized, $sub)) {
                            $map[$field] = $index;
                            $score += 1;
                            $matched = true;
                            break 2;
                        }
                    }
                }
            }
        }

        // Guard: reject MPN matches on line-number columns
        if (isset($map['mpn'])) {
            $mpnHeader = $this->normalizeHeader((string)($row[$map['mpn']] ?? ''));
            if (in_array($mpnHeader, self::MPN_BLACKLIST, true)) {
                unset($map['mpn']);
                $score -= 3;
            }
        }

        // Promote remark → MPN when MPN is unmapped and the remark header
        // indicates alternative/substitute part numbers (common in sourcing BOMs)
        if (!isset($map['mpn']) && isset($map['remark'])) {
            $remarkHeader = $this->normalizeHeader((string)($row[$map['remark']] ?? ''));
            if (str_contains($remarkHeader, 'alternative')
                || str_contains($remarkHeader, 'alternate')
                || str_contains($remarkHeader, 'substitute')
                || str_contains($remarkHeader, 'replacement')
            ) {
                $map['mpn'] = $map['remark'];
                unset($map['remark']);
            }
        }

        return [$map, $score];
    }

    /**
     * Simple (legacy) header mapping — direct exact-match only, no scoring.
     * Used as fallback when auto-detection finds nothing.
     */
    private function mapHeadersSimple(array $headers): array
    {
        $map = [];

        foreach ($headers as $index => $header) {
            if ($header === null || trim((string)$header) === '') continue;
            $n = $this->normalizeHeader((string)$header);
            if ($n === '') continue;

            foreach (self::HEADER_EXACT as $field => $tokens) {
                if (isset($map[$field])) continue;
                if (in_array($n, $tokens, true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        // Also try fuzzy if exact pass found < 2 fields
        if (count($map) < 2) {
            foreach ($headers as $index => $header) {
                if ($header === null || trim((string)$header) === '') continue;
                if (in_array($index, array_values($map))) continue;
                $n = $this->normalizeHeader((string)$header);

                foreach (self::HEADER_FUZZY as $field => $substrings) {
                    if (isset($map[$field])) continue;
                    foreach ($substrings as $sub) {
                        if (str_contains($n, $sub)) {
                            $map[$field] = $index;
                            break 2;
                        }
                    }
                }
            }
        }

        // Reject blacklisted MPN columns
        if (isset($map['mpn'])) {
            $h = $this->normalizeHeader((string)($headers[$map['mpn']] ?? ''));
            if (in_array($h, self::MPN_BLACKLIST, true)) {
                unset($map['mpn']);
            }
        }

        return $map;
    }

    /**
     * Heuristically infer the MPN column by scanning data rows for
     * values that look like part numbers (mixed alphanumeric, ≥ 6 chars or has dash).
     */
    private function inferMPNFromData(array $rows, int $headerRowIndex, array $map): array
    {
        $dataStart  = $headerRowIndex + 1;
        $sampleRows = array_slice($rows, $dataStart, min(10, count($rows) - $dataStart));
        if (empty($sampleRows)) return $map;

        $colCount  = max(array_map('count', $sampleRows));
        $bestCol   = -1;
        $bestScore = 0;

        // Patterns that indicate internal library references, NOT real MPNs
        $internalRefPatterns = [
            '/^CMP-\d{2,6}-\d{3,8}-\d{1,3}$/',   // Altium-style library ref
            '/^LIB[-_]\d+/',                        // Generic library prefix
            '/^COMP[-_]\d{4,}/',                    // Component library ID
        ];

        for ($col = 0; $col < $colCount; $col++) {
            if (in_array($col, array_values($map), true)) continue;

            $mpnScore = 0;
            $internalRefCount = 0;
            foreach ($sampleRows as $row) {
                $val = trim((string)($row[$col] ?? ''));
                if ($val === '') continue;

                // Check if value looks like an internal library reference
                foreach ($internalRefPatterns as $pattern) {
                    if (preg_match($pattern, $val)) {
                        $internalRefCount++;
                        continue 2; // Skip this value entirely
                    }
                }

                // MPN heuristic: has both letters and digits, ≥6 chars or has dash
                if (preg_match('/[a-zA-Z]/', $val) && preg_match('/\d/', $val)) {
                    if (strlen($val) >= 6 || str_contains($val, '-')) {
                        $mpnScore++;
                    }
                }
            }

            // Heavily penalize columns dominated by internal references
            if ($internalRefCount > count($sampleRows) / 3) {
                $mpnScore = max(0, $mpnScore - $internalRefCount);
            }

            if ($mpnScore > $bestScore) {
                $bestScore = $mpnScore;
                $bestCol   = $col;
            }
        }

        if ($bestCol >= 0 && $bestScore >= 2) {
            $map['mpn'] = $bestCol;
            $this->logger->info('MPN column inferred from data patterns', ['column_index' => $bestCol]);
        }

        return $map;
    }

    // ═══════════════════════════════════════════════════════════════
    //  Data Extraction
    // ═══════════════════════════════════════════════════════════════

    /**
     * Extract a standardized BOM line from a single data row.
     */
    private function extractLine(array $row, array $headerMap, int $lineNumber): ?array
    {
        $mpn         = $this->cleanMPN($this->getField($row, $headerMap, 'mpn'));
        $description = $this->getField($row, $headerMap, 'description');
        $value       = $this->getField($row, $headerMap, 'value');

        // Use value as description if description column is empty/missing
        if ($description === '' && $value !== '') {
            $description = $value;
        }

        // ── MPN / Description swap for Altium-style BOMs ──
        // When the MPN looks like a generic description word (e.g., "Diode",
        // "Capacitor") but the description column contains an MPN-like value,
        // swap them. This handles Altium BOMs where the "Description" column
        // sometimes has generic part types instead of real MPNs.
        if ($mpn !== '' && $description !== '') {
            $mpnLooksGeneric = preg_match(
                '/^(diode|capacitor|resistor|inductor|connector|transistor|IC|LED|fuse|crystal|relay|sensor|ferrite|filter|transformer|schottky\s*diode|zener\s*diode|tvs\s*diode|power\s*diode)s?$/i',
                trim($mpn)
            );
            $descLooksMPN = preg_match('/[a-zA-Z]/', $description)
                         && preg_match('/\d/', $description)
                         && (strlen($description) >= 6 || str_contains($description, '-'))
                         && !preg_match('/\s{2,}/', $description);

            if ($mpnLooksGeneric && $descLooksMPN) {
                // Description has the real MPN, swap them
                [$mpn, $description] = [$this->cleanMPN($description), $mpn];
            }

            // Also swap when the MPN is a long natural-language description
            // (contains spaces and common description words) but description is a compact MPN
            if (!$mpnLooksGeneric) {
                $mpnWordCount = str_word_count($mpn);
                $mpnHasDescWords = preg_match(
                    '/\b(voltage|precision|adjustable|low|high|dual|single|channel|output|input|regulator|amplifier|converter|controller|driver|receiver|transmitter|isolator|optocoupler|shunt|hard|rad|NPN|PNP|MOSFET|op.?amp)\b/i',
                    $mpn
                );
                $descIsCompact = !str_contains($description, ' ')
                              || (strlen($description) <= 20 && preg_match('/[A-Z0-9]{3,}/', $description));

                if ($mpnWordCount >= 3 && $mpnHasDescWords && $descIsCompact && $descLooksMPN) {
                    [$mpn, $description] = [$this->cleanMPN($description), $mpn];
                }
            }
        }

        // Must have at least MPN or description
        if ($mpn === '' && $description === '' && $value === '') {
            return null;
        }

        // Skip DNP (Do Not Populate) / custom lines with no real MPN
        $dnpTokens = ['dnp', 'donotpopulate', 'donotplace', 'noload', 'custom'];
        $mpnNorm = strtolower(preg_replace('/[\s\-_]/', '', $mpn));
        $valNorm = strtolower(preg_replace('/[\s\-_]/', '', $value ?: $description));
        if (in_array($mpnNorm, $dnpTokens, true)
            || ($mpn === '' && in_array($valNorm, $dnpTokens, true))
        ) {
            return null;
        }

        $designator = $this->getField($row, $headerMap, 'designator');
        $qtyRaw     = $this->getField($row, $headerMap, 'qty');
        $qty        = $this->parseQuantity($qtyRaw);

        // If qty ≤ 1 and designator has a range/list, count them
        if ($qty <= 1 && $designator !== '') {
            $counted = $this->countDesignators($designator);
            if ($counted > $qty) {
                $qty = $counted;
            }
        }

        // Parse numeric pricing / stock-quantity fields
        $stockQtyRaw = $this->getField($row, $headerMap, 'stock_quantity');
        $stockQty    = $stockQtyRaw !== '' ? $this->parseQuantity($stockQtyRaw) : null;
        $unitPriceRaw  = $this->getField($row, $headerMap, 'unit_price');
        $unitPrice     = ($unitPriceRaw !== '' && is_numeric(str_replace(',', '', $unitPriceRaw)))
                       ? (float)str_replace(',', '', $unitPriceRaw) : null;
        $totalPriceRaw = $this->getField($row, $headerMap, 'total_price');
        $totalPrice    = ($totalPriceRaw !== '' && is_numeric(str_replace(',', '', $totalPriceRaw)))
                       ? (float)str_replace(',', '', $totalPriceRaw) : null;

        return [
            'lineNumber'     => $lineNumber,
            'designator'     => $designator,
            'mpn'            => $mpn,
            'manufacturer'   => $this->getField($row, $headerMap, 'manufacturer'),
            'quantity'       => max(1, $qty),
            'stock_quantity' => $stockQty,
            'unit_price'     => $unitPrice,
            'total_price'    => $totalPrice,
            'description'    => $description,
            'value'          => $value ?: $description,
            'package'        => $this->getField($row, $headerMap, 'package'),
            'supplier'       => $this->getField($row, $headerMap, 'supplier'),
            'supplier_pn'    => $this->getField($row, $headerMap, 'supplier_pn'),
            'category'       => $this->getField($row, $headerMap, 'category'),
            'remark'         => $this->getField($row, $headerMap, 'remark'),
        ];
    }

    /**
     * Get a trimmed string value from the row for a mapped field.
     */
    private function getField(array $row, array $headerMap, string $field): string
    {
        if (!isset($headerMap[$field])) return '';
        return trim((string)($row[$headerMap[$field]] ?? ''));
    }

    // ═══════════════════════════════════════════════════════════════
    //  Cleaning & Normalization Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Normalize a header cell for dictionary lookup.
     * Strips *, #, (), [], whitespace, punctuation → lowercase alphanumeric only.
     */
    private function normalizeHeader(string $header): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($header)));
    }

    /**
     * Clean an MPN value: strip invisible characters, quotes, excess whitespace.
     */
    private function cleanMPN(string $mpn): string
    {
        // Remove invisible / control characters
        $mpn = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $mpn);
        // Remove non-breaking space (UTF-8: C2 A0)
        $mpn = str_replace("\xC2\xA0", ' ', $mpn);
        // Remove surrounding quotes
        $mpn = trim($mpn, " \t\n\r\0\x0B\"'`");
        // Collapse internal whitespace
        $mpn = preg_replace('/\s+/', ' ', $mpn);
        return $mpn;
    }

    /**
     * Normalize MPN for use as a consolidation key (uppercase, no whitespace/dashes).
     */
    private function normalizeMPN(string $mpn): string
    {
        $clean = $this->cleanMPN($mpn);
        return strtoupper(preg_replace('/[\s\-]/', '', $clean));
    }

    /**
     * Parse a quantity from various human-readable formats:
     *   "10"      → 10       "2,000"   → 2000
     *   "3 pcs"   → 3        "x5"      → 5
     *   "5 ea"    → 5        ""        → 1
     *   "0"       → 1        "2.0"     → 2
     */
    private function parseQuantity(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') return 1;

        // Remove suffixes: pcs, ea, each, pc, unit(s), piece(s)
        $clean = preg_replace('/\s*(pcs|ea|each|pc|units?|pieces?)\s*$/i', '', $raw);
        // Remove leading x / X  (e.g. "x3")
        $clean = preg_replace('/^[xX]\s*/', '', $clean);
        // Remove thousand separators  (1,000 → 1000)
        $clean = preg_replace('/(\d),(\d{3})/', '$1$2', $clean);
        // Try to extract the first integer or decimal
        if (preg_match('/(\d+(?:\.\d+)?)/', $clean, $m)) {
            return max(1, (int)round((float)$m[1]));
        }

        return 1;
    }

    /**
     * Count individual designators from a designator string.
     *
     *   "R1, R2, R3"       → 3
     *   "R1-R10"           → 10
     *   "C1, C2, C5-C8"   → 6
     *   "U1"               → 1
     */
    private function countDesignators(string $designator): int
    {
        $count = 0;
        $parts = preg_split('/[,;\s]+/', $designator, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;

            // Range: "R1-R10", "C5–C8"
            if (preg_match('/^([A-Za-z]+)(\d+)\s*[-–—]\s*(?:[A-Za-z]+)?(\d+)$/', $part, $m)) {
                $start = (int)$m[2];
                $end   = (int)$m[3];
                $count += ($end >= $start) ? ($end - $start + 1) : 1;
            } else {
                $count++;
            }
        }

        return max(1, $count);
    }

    /**
     * Merge two designator strings, deduplicating entries.
     */
    private function mergeDesignators(string $a, string $b): string
    {
        $a = trim($a);
        $b = trim($b);
        if ($a === '') return $b;
        if ($b === '') return $a;

        $parts = array_unique(array_filter(
            preg_split('/[,;\s]+/', "$a, $b"),
            fn($p) => trim($p) !== ''
        ));

        return implode(', ', $parts);
    }

    /**
     * Check if a row is effectively empty (all cells null or whitespace).
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string)$cell) !== '') {
                return false;
            }
        }
        return true;
    }
}
