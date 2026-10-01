<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Part Match Confidence Calculator
 * 
 * Calculates confidence scores for part matching results from distributor APIs.
 * Prevents "blind matching" by analyzing how well the API result matches
 * the original BOM request.
 * 
 * Confidence Levels:
 * - HIGH (90-100): Near-exact match, safe for auto-processing
 * - MEDIUM (70-89): Probable match, recommend human review
 * - LOW (50-69): Uncertain match, requires verification
 * - VERY_LOW (0-49): Likely wrong match, manual intervention required
 */
class PartMatchConfidenceCalculator
{
    public const CONFIDENCE_HIGH = 'HIGH';
    public const CONFIDENCE_MEDIUM = 'MEDIUM';
    public const CONFIDENCE_LOW = 'LOW';
    public const CONFIDENCE_VERY_LOW = 'VERY_LOW';
    
    private const THRESHOLD_HIGH = 90;
    private const THRESHOLD_MEDIUM = 70;
    private const THRESHOLD_LOW = 50;
    
    /**
     * Calculate confidence score for a part match
     * 
     * @param string $requestedMpn The MPN from the BOM
     * @param string|null $requestedManufacturer The manufacturer from the BOM (if provided)
     * @param string|null $requestedDescription The description from the BOM (if provided)
     * @param array $apiResult The result from the distributor API
     * 
     * @return array{
     *   score: int,
     *   level: string,
     *   reasons: array,
     *   warnings: array,
     *   requiresReview: bool
     * }
     */
    public function calculateConfidence(
        string $requestedMpn,
        ?string $requestedManufacturer,
        ?string $requestedDescription,
        array $apiResult
    ): array {
        $score = 0;
        $reasons = [];
        $warnings = [];
        
        // 1. MPN Matching (max 50 points)
        $mpnScore = $this->scoreMpnMatch(
            $requestedMpn, 
            $apiResult['mpn'] ?? ''
        );
        $score += $mpnScore['points'];
        $reasons = array_merge($reasons, $mpnScore['reasons']);
        $warnings = array_merge($warnings, $mpnScore['warnings']);
        
        // 2. Manufacturer Matching (max 25 points)
        if ($requestedManufacturer) {
            $mfrScore = $this->scoreManufacturerMatch(
                $requestedManufacturer,
                $apiResult['manufacturer'] ?? ''
            );
            $score += $mfrScore['points'];
            $reasons = array_merge($reasons, $mfrScore['reasons']);
        } else {
            // No manufacturer in BOM.  When the MPN is an exact match the
            // part identity is already proven — give full manufacturer credit.
            if ($mpnScore['points'] >= 40) {
                $score += 25;
                $reasons[] = 'Full manufacturer credit (MPN match proves identity)';
            } else {
                $score += 18;
                $reasons[] = 'No manufacturer specified in BOM (partial credit)';
            }
        }
        
        // 3. Description Matching (max 15 points)
        if ($requestedDescription) {
            $returnedDescription = $apiResult['description'] ?? '';
            // Safeguard: ensure description is a string
            if (!is_string($returnedDescription)) {
                $returnedDescription = is_array($returnedDescription) ? implode(' ', array_filter($returnedDescription, 'is_string')) : (string) $returnedDescription;
            }
            
            // Before standard description matching, check if the API result's
            // description/title contains the MPN itself — this is a strong signal
            // for Alibaba results where titles like "C0603C104K4RAC7411 ICs Electronic Component"
            // wouldn't match BOM descriptions like "100nF 0603" but ARE the right part
            $normalizedMpn = strtolower(preg_replace('/[\s\-_\.]+/', '', $requestedMpn));
            $normalizedDesc = strtolower(preg_replace('/[\s\-_\.]+/', '', $returnedDescription));
            $mpnInDescription = str_contains($normalizedDesc, $normalizedMpn);
            
            $descScore = $this->scoreDescriptionMatch(
                $requestedDescription,
                $returnedDescription
            );
            
            // If MPN matches exactly (50 pts) and description overlap is poor,
            // give generous credit — the MPN match proves identity, and BOM
            // descriptions are often garbled or minimal.
            if ($mpnScore['points'] >= 40 && $descScore['points'] < 12) {
                $descScore['points'] = 12;
                $descScore['reasons'] = ['Description credit boosted (strong MPN match overrides weak BOM description)'];
                $descScore['warnings'] = [];
            } elseif ($mpnInDescription && $descScore['points'] < 10) {
                $descScore['points'] = 10;
                $descScore['reasons'] = ['MPN found in product title/description'];
                $descScore['warnings'] = [];
            }
            
            $score += $descScore['points'];
            $reasons = array_merge($reasons, $descScore['reasons']);
            $warnings = array_merge($warnings, $descScore['warnings']);
        } else {
            // No description to compare
            $score += 10;
        }
        
        // 4. Data Quality Indicators (max 10 points)
        $qualityScore = $this->scoreDataQuality($apiResult);
        $score += $qualityScore['points'];
        $reasons = array_merge($reasons, $qualityScore['reasons']);
        
        // Determine confidence level
        $level = $this->determineLevel($score);
        
        // Check for critical warnings that force review
        $requiresReview = $this->requiresManualReview($level, $warnings, $apiResult);
        
        return [
            'score' => min(100, $score),
            'level' => $level,
            'reasons' => $reasons,
            'warnings' => $warnings,
            'requiresReview' => $requiresReview,
            'requestedMpn' => $requestedMpn,
            'matchedMpn' => $apiResult['mpn'] ?? 'N/A',
        ];
    }
    
    /**
     * Score MPN matching (0-50 points)
     */
    private function scoreMpnMatch(string $requested, string $returned): array
    {
        $points = 0;
        $reasons = [];
        $warnings = [];
        
        // Normalize both MPNs
        $normalizedRequested = $this->normalizeMpn($requested);
        $normalizedReturned = $this->normalizeMpn($returned);
        
        if ($normalizedRequested === $normalizedReturned) {
            // Exact match after normalization
            $points = 50;
            $reasons[] = 'Exact MPN match';
        } elseif (stripos($normalizedReturned, $normalizedRequested) !== false) {
            // Returned MPN contains the requested MPN
            $points = 40;
            $reasons[] = 'API result contains requested MPN';
            $warnings[] = 'MPN is substring match - verify correct variant';
        } elseif (stripos($normalizedRequested, $normalizedReturned) !== false) {
            // Requested MPN contains the returned MPN
            $points = 30;
            $reasons[] = 'Requested MPN contains API result MPN';
            $warnings[] = 'Possible partial match - verify complete part number';
        } else {
            // Calculate similarity
            $similarity = $this->calculateStringSimilarity($normalizedRequested, $normalizedReturned);
            
            if ($similarity >= 0.8) {
                $points = 35;
                $reasons[] = sprintf('High MPN similarity (%.0f%%)', $similarity * 100);
                $warnings[] = 'MPN not exact - verify part is correct';
            } elseif ($similarity >= 0.6) {
                $points = 20;
                $reasons[] = sprintf('Moderate MPN similarity (%.0f%%)', $similarity * 100);
                $warnings[] = 'Significant MPN difference - manual verification required';
            } else {
                $points = 5;
                $reasons[] = sprintf('Low MPN similarity (%.0f%%)', $similarity * 100);
                $warnings[] = 'CRITICAL: MPN significantly different from request';
            }
        }
        
        return ['points' => $points, 'reasons' => $reasons, 'warnings' => $warnings];
    }
    
    /**
     * Score manufacturer matching (0-25 points)
     */
    private function scoreManufacturerMatch(string $requested, string $returned): array
    {
        $points = 0;
        $reasons = [];
        
        // Common manufacturer aliases
        $aliases = [
            'ti' => ['texas instruments', 'ti'],
            'st' => ['stmicroelectronics', 'st', 'st micro'],
            'nxp' => ['nxp', 'nxp semiconductors', 'philips'],
            'on' => ['onsemi', 'on semiconductor', 'on semi'],
            'murata' => ['murata', 'murata manufacturing'],
            'tdk' => ['tdk', 'tdk corporation'],
            'vishay' => ['vishay', 'vishay intertechnology'],
            'avx' => ['avx', 'avx corporation', 'kyocera avx'],
            'samsung' => ['samsung', 'samsung electro-mechanics', 'sem'],
            'yageo' => ['yageo', 'yageo corporation'],
            'kemet' => ['kemet', 'kemet corporation'],
            'panasonic' => ['panasonic', 'panasonic electronic components'],
            'wurth' => ['wurth', 'wurth elektronik', 'wurth'],
            'infineon' => ['infineon', 'infineon technologies'],
            'microchip' => ['microchip', 'microchip technology'],
            'analog' => ['analog devices', 'adi', 'analog'],
            'maxim' => ['maxim', 'maxim integrated'],
            'renesas' => ['renesas', 'renesas electronics'],
            'diodes' => ['diodes', 'diodes incorporated'],
            'rohm' => ['rohm', 'rohm semiconductor'],
        ];
        
        $normalizedRequested = strtolower(trim($requested));
        $normalizedReturned = strtolower(trim($returned));
        
        // Direct match
        if ($normalizedRequested === $normalizedReturned) {
            return ['points' => 25, 'reasons' => ['Exact manufacturer match']];
        }
        
        // Check aliases
        foreach ($aliases as $key => $variants) {
            $requestedMatch = in_array($normalizedRequested, $variants, true);
            $returnedMatch = in_array($normalizedReturned, $variants, true);
            
            // 2-char keys ('on', 'st', 'ti') are only matched as exact variants
            // — substring matching them would false-positive on any word that
            // merely CONTAINS them (e.g. 'on' inside "Analog Devices").
            if (strlen($key) >= 3) {
                if (!$requestedMatch && $this->matchesManufacturerToken($normalizedRequested, $key)) {
                    $requestedMatch = true;
                }
                if (!$returnedMatch && $this->matchesManufacturerToken($normalizedReturned, $key)) {
                    $returnedMatch = true;
                }
            }
            
            if ($requestedMatch && $returnedMatch) {
                return ['points' => 23, 'reasons' => ['Manufacturer alias match']];
            }
        }
        
        // Partial string match
        if (str_contains($normalizedReturned, $normalizedRequested) ||
            str_contains($normalizedRequested, $normalizedReturned)) {
            return ['points' => 18, 'reasons' => ['Partial manufacturer match']];
        }
        
        // Similarity check
        $similarity = $this->calculateStringSimilarity($normalizedRequested, $normalizedReturned);
        if ($similarity >= 0.7) {
            return ['points' => 12, 'reasons' => [sprintf('Manufacturer similarity (%.0f%%)', $similarity * 100)]];
        }
        
        return ['points' => 0, 'reasons' => ['Manufacturer mismatch: ' . $requested . ' vs ' . $returned]];
    }

    /**
     * Token-boundary manufacturer alias match.
     *
     * Matches the alias key as a whole word or as a word prefix (e.g.
     * 'microchip' inside "microchip technology"), never as a bare substring —
     * so keys like 'avx' can't match "avx" inside a longer unrelated word.
     */
    private function matchesManufacturerToken(string $normalized, string $key): bool
    {
        $quoted = preg_quote($key, '/');
        
        return preg_match('/(?:^|[\s,.\-])' . $quoted . '(?:$|[\s,.\-])/', $normalized) === 1
            || preg_match('/^' . $quoted . '[\s,.\-]/', $normalized) === 1;
    }
    
    /**
     * Score description matching (0-15 points)
     */
    private function scoreDescriptionMatch(string $requested, string $returned): array
    {
        $points = 0;
        $reasons = [];
        $warnings = [];
        
        // Extract key terms from both descriptions
        $requestedTerms = $this->extractKeyTerms($requested);
        $returnedTerms = $this->extractKeyTerms($returned);
        
        if (empty($requestedTerms)) {
            return ['points' => 10, 'reasons' => ['No key terms in BOM description'], 'warnings' => []];
        }
        
        // Count matching terms
        $matchingTerms = array_intersect($requestedTerms, $returnedTerms);
        $matchRatio = count($matchingTerms) / count($requestedTerms);
        
        if ($matchRatio >= 0.8) {
            $points = 15;
            $reasons[] = 'Strong description match';
        } elseif ($matchRatio >= 0.5) {
            $points = 10;
            $reasons[] = 'Moderate description match';
        } elseif ($matchRatio >= 0.25) {
            $points = 5;
            $reasons[] = 'Partial description match';
            $warnings[] = 'Description may not match intended part';
        } else {
            $points = 0;
            $reasons[] = 'Description mismatch';
            $warnings[] = 'CAUTION: Description significantly different';
        }
        
        return ['points' => $points, 'reasons' => $reasons, 'warnings' => $warnings];
    }
    
    /**
     * Score data quality indicators (0-10 points)
      * @param array<string|int, mixed> $apiResult
     */
    private function scoreDataQuality(array $apiResult): array
    {
        $points = 0;
        $reasons = [];
        
        // Has pricing data
        if (!empty($apiResult['pricing']) && is_array($apiResult['pricing'])) {
            $points += 3;
            $reasons[] = 'Pricing data available';
        }
        
        // Has stock info (real or estimated)
        if (isset($apiResult['stock']) && $apiResult['stock'] > 0) {
            $points += 2;
            if ($apiResult['_stock_estimated'] ?? false) {
                $reasons[] = 'Supplier availability estimated (factory stock)';
            } else {
                $reasons[] = 'In-stock availability confirmed';
            }
        }
        
        // Has datasheet
        if (!empty($apiResult['datasheet'])) {
            $points += 2;
            $reasons[] = 'Datasheet available';
        }
        
        // Has lifecycle status
        if (!empty($apiResult['lifecycle'])) {
            $points += 2;
            $reasons[] = 'Lifecycle status provided';
            
            // Warn about obsolete parts
            $obsoleteTerms = ['obsolete', 'discontinued', 'end of life', 'eol', 'nrnd'];
            $lifecycle = is_string($apiResult['lifecycle']) ? $apiResult['lifecycle'] : '';
            if (in_array(strtolower($lifecycle), $obsoleteTerms)) {
                $reasons[] = 'WARNING: Part is obsolete/discontinued';
            }
        }
        
        // Has RoHS status
        if (!empty($apiResult['rohs'])) {
            $points += 1;
        }
        
        // Alibaba-specific: verified supplier is a quality signal
        if (!empty($apiResult['supplier_type']) && str_contains($apiResult['supplier_type'], 'Verified')) {
            $points += 1;
            $reasons[] = 'Verified supplier';
        }
        
        // Alibaba-specific: trade assurance
        if (!empty($apiResult['trade_assurance'])) {
            $points += 1;
            $reasons[] = 'Trade assurance protection';
        }
        
        // Floor: results with real pricing + stock are inherently useful
        if ($points < 5 && !empty($apiResult['pricing']) && is_array($apiResult['pricing'])) {
            $points = max($points, 5);
        }
        return ['points' => min(10, $points), 'reasons' => $reasons];
    }
    
    /**
     * Normalize MPN for comparison
     */
    public function normalizeMpn(string $mpn): string
    {
        // Convert to uppercase
        $normalized = strtoupper(trim($mpn));
        
        // Remove common separators and whitespace
        $normalized = preg_replace('/[\s\-_\.\/\\\\]+/', '', $normalized);
        
        // Remove common suffixes that indicate packaging (keep base part number)
        // But be careful not to remove significant suffixes
        $normalized = preg_replace('/\(.*?\)$/', '', $normalized);
        
        return $normalized;
    }
    
    /**
     * Calculate string similarity using Levenshtein distance
     */
    private function calculateStringSimilarity(string $str1, string $str2): float
    {
        $maxLen = max(strlen($str1), strlen($str2));
        if ($maxLen === 0) {
            return 1.0;
        }
        
        $distance = levenshtein($str1, $str2);
        return 1.0 - ($distance / $maxLen);
    }
    
    /**
     * Extract key terms from a description
     */
    private function extractKeyTerms(string $description): array
    {
        // Convert to lowercase and split on non-alphanumeric
        $words = preg_split('/[^a-zA-Z0-9]+/', strtolower($description));
        
        // Filter out common stop words and short terms
        $stopWords = ['the', 'and', 'for', 'with', 'from', 'this', 'that', 'are', 'was', 'is', 'a', 'an'];
        
        return array_values(array_filter($words, function($word) use ($stopWords) {
            return strlen($word) >= 2 && !in_array($word, $stopWords);
        }));
    }
    
    /**
     * Determine confidence level from score
     */
    private function determineLevel(int $score): string
    {
        if ($score >= self::THRESHOLD_HIGH) {
            return self::CONFIDENCE_HIGH;
        } elseif ($score >= self::THRESHOLD_MEDIUM) {
            return self::CONFIDENCE_MEDIUM;
        } elseif ($score >= self::THRESHOLD_LOW) {
            return self::CONFIDENCE_LOW;
        }
        return self::CONFIDENCE_VERY_LOW;
    }
    
    /**
     * Check if manual review is required
      * @param array<string|int, mixed> $warnings
 * @param array<string|int, mixed> $apiResult
     */
    private function requiresManualReview(string $level, array $warnings, array $apiResult): bool
    {
        // Always require review for low confidence
        if (in_array($level, [self::CONFIDENCE_LOW, self::CONFIDENCE_VERY_LOW])) {
            return true;
        }
        
        // Check for critical warnings
        foreach ($warnings as $warning) {
            if (str_contains(strtoupper($warning), 'CRITICAL')) {
                return true;
            }
        }
        
        // Check for obsolete parts
        $lifecycle = strtolower(is_string($apiResult['lifecycle'] ?? '') ? ($apiResult['lifecycle'] ?? '') : '');
        if (in_array($lifecycle, ['obsolete', 'discontinued', 'eol', 'nrnd'])) {
            return true;
        }
        
        // Check for high unit price (>$100) - could indicate wrong part
        $pricing = $apiResult['pricing'] ?? [];
        if (!empty($pricing)) {
            $minPrice = PHP_FLOAT_MAX;
            foreach ($pricing as $break) {
                $minPrice = min($minPrice, $break['price'] ?? PHP_FLOAT_MAX);
            }
            if ($minPrice > 100) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Generate variants of an MPN for fuzzy searching
     */
    public function generateMpnVariants(string $mpn): array
    {
        $variants = [$mpn];
        $normalized = $this->normalizeMpn($mpn);
        
        if ($normalized !== $mpn) {
            $variants[] = $normalized;
        }
        
        // ── HIGH-VALUE: Remove common ordering/packaging suffixes first ──
        // These are the most likely to find the correct part
        $suffixes = [
            '-TR', '-ND', '-CT', '-PBF', 'PBF', '-1', '-2', '-3', 'TR', 'ND', 'CT',
            '-DKR', '-1-ND', '-2-ND', '-3-ND', '-6-ND', '#PBF', '/TR', '-TRAY',
            '-REEL', '-BULK', '-CUT', '-TAPE', '-TUBE', 'TRAY',
        ];
        foreach ($suffixes as $suffix) {
            if (str_ends_with(strtoupper($mpn), strtoupper($suffix)) && strlen($mpn) > strlen($suffix) + 3) {
                $stripped = substr($mpn, 0, -strlen($suffix));
                $variants[] = $stripped;
                // Also try re-appending common alternatives
                if (str_ends_with(strtoupper($mpn), '-TR')) {
                    $variants[] = $stripped . '-ND';
                    $variants[] = $stripped . '-CT';
                }
            }
        }
        
        // Vishay-style packaging suffix variants: last char(s) indicate packaging
        // D/ED = 7" reel, A/EA = Cut tape, HP = Punched, C/EC = 13" reel
        // Strip to base and try common packaging variants
        $vishayPackagingSuffixes = [
            'FKED' => ['FKEA', 'FKEAHP', 'FKEC'],
            'JKED' => ['JKEA', 'JKEAHP', 'JKEC'],
            'FKEC' => ['FKEA', 'FKEAHP', 'FKED'],
            'FKEAHP' => ['FKEA', 'FKED'],
        ];
        $upperMpn = strtoupper($mpn);
        foreach ($vishayPackagingSuffixes as $from => $toList) {
            if (str_ends_with($upperMpn, $from)) {
                $base = substr($mpn, 0, -strlen($from));
                foreach ($toList as $to) {
                    $variants[] = $base . $to;
                }
                break;
            }
        }
        
        // Generic trailing packaging letter swap: D↔A for MPNs ending in common patterns
        if (preg_match('/^(.+[0-9])([DABC])$/i', $mpn, $m)) {
            $swaps = ['D' => 'A', 'A' => 'D', 'B' => 'A', 'C' => 'A'];
            $lastChar = strtoupper($m[2]);
            if (isset($swaps[$lastChar])) {
                $variants[] = $m[1] . $swaps[$lastChar];
            }
        }
        
        // ── Samsung ↔ KEMET ↔ Murata MLCC cross-reference patterns ──
        // Samsung CL series → try Murata GRM series and vice-versa
        // e.g. CL10B104KB8NNNC → GRM155R71H104KE14D (same 100nF 0402 X7R)
        // We don't do exact cross-ref but strip to try keyword search with specs
        
        // ── Manufacturer prefix variants ──
        // Some MPNs have manufacturer-specific prefixes that can be swapped:
        // RC0603 (Yageo) ↔ ERJ-3 (Panasonic) ↔ CRCW0603 (Vishay)
        // These are too different for suffix-swap; handled by keyword fallback instead.
        
        // ── Tolerance suffix variants ──
        // Many passives use tolerance codes: F(1%), J(5%), K(10%), G(2%), D(0.5%)
        // Try swapping common tolerances (e.g. K↔J for 10%↔5%)
        if (preg_match('/^(.{8,})([FJKGDB])(\d{2,3}[A-Z]*)$/i', $mpn, $m)) {
            $toleranceSwaps = [
                'F' => ['J', 'K'],  // 1% → try 5%, 10%
                'J' => ['F', 'K'],  // 5% → try 1%, 10%
                'K' => ['J', 'F'],  // 10% → try 5%, 1%
                'G' => ['F', 'J'],  // 2% → try 1%, 5%
            ];
            $tolChar = strtoupper($m[2]);
            if (isset($toleranceSwaps[$tolChar])) {
                foreach ($toleranceSwaps[$tolChar] as $swap) {
                    $variants[] = $m[1] . $swap . $m[3];
                }
            }
        }
        
        // ── Temperature range suffix variants ──
        // C = commercial (0°C to 70°C), I = industrial (-40°C to 85°C), A/E = extended
        if (preg_match('/^(.{6,})([CIAE])$/i', $mpn, $m) && strlen($mpn) > 10) {
            $tempSwaps = ['C' => ['I'], 'I' => ['C'], 'A' => ['I', 'C'], 'E' => ['I', 'C']];
            $tempChar = strtoupper($m[2]);
            if (isset($tempSwaps[$tempChar])) {
                foreach ($tempSwaps[$tempChar] as $swap) {
                    $variants[] = $m[1] . $swap;
                }
            }
        }
        
        // ── Try without hyphens entirely ──
        $noHyphen = str_replace('-', '', $mpn);
        if ($noHyphen !== $mpn && strlen($noHyphen) > 5) {
            $variants[] = $noHyphen;
        }
        
        // ── Try without spaces ──
        $noSpace = str_replace(' ', '', $mpn);
        if ($noSpace !== $mpn && strlen($noSpace) > 5) {
            $variants[] = $noSpace;
        }
        
        // Cap total variants to avoid API rate-limit issues (each variant = 1 API call)
        $unique = array_unique($variants);
        return array_slice($unique, 0, 8);
    }
}
