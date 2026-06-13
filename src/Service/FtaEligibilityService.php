<?php

namespace App\Service;

use App\Entity\FtaRule;
use App\Entity\CooSupplierDecl;
use App\Repository\FtaRuleRepository;
use App\Repository\CooSupplierDeclRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * FtaEligibilityService
 * 
 * Evaluates Free Trade Agreement (FTA) and Rules of Origin (ROO) eligibility for shipments.
 * Determines if a shipment qualifies for preferential duty rates under FTA agreements.
 * 
 * Eligibility States:
 * - ELIGIBLE: All ROO requirements met, can claim FTA rate
 * - CONDITIONAL: ROO requirements partially met, missing evidence (watermark on PDF)
 * - INELIGIBLE: Does not meet ROO requirements, use MFN rate
 * 
 * Supported FTAs:
 * - Morocco-US FTA
 * - Morocco-EU Association Agreement
 * - Future: Agadir Agreement, African Continental FTA, etc.
 * 
 * Used by: Quote Co-Pilot
 */
class FtaEligibilityService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FtaRuleRepository $ftaRuleRepository,
        private CooSupplierDeclRepository $cooSupplierDeclRepository
    ) {}

    /**
     * Check FTA eligibility for a shipment
     * 
     * Evaluates whether a shipment qualifies for preferential duty rates under applicable
     * Free Trade Agreements by checking Rules of Origin (ROO) requirements.
     * 
     * Algorithm:
     * 1. Identify applicable FTA agreement based on origin/destination country pair
     * 2. Evaluate ROO requirements for each BOM component
     * 3. Verify COO supplier declarations exist for BOM MPNs
     * 4. Calculate Regional Value Content (RVC) if required by the FTA
     * 5. Determine eligibility status based on all criteria
     * 
     * @param string $originCountry Origin country code (e.g., 'MA')
     * @param string $destinationCountry Destination country code (e.g., 'US', 'FR')
     * @param array $bomData BOM data with HTS codes and COO info
     * @param float $totalValue Total shipment value
     * @return array{
     *   eligible: string,
     *   status: string,
     *   fta_agreement: ?string,
     *   basis: string,
     *   confidence: int,
     *   missing_evidence: array,
     *   declaration_template: ?string
     * }
     */
    public function checkEligibility(
        string $originCountry,
        string $destinationCountry,
        array $bomData = [],
        float $totalValue = 0
    ): array {
        // 1. Get applicable FTA agreements
        $ftaRules = $this->getApplicableFtas($originCountry, $destinationCountry);
        
        if (empty($ftaRules)) {
            return [
                'eligible' => 'INELIGIBLE',
                'status' => 'INELIGIBLE',
                'fta_agreement' => null,
                'basis' => 'No FTA agreement exists between ' . $originCountry . ' and ' . $destinationCountry,
                'confidence' => 100,
                'missing_evidence' => [],
                'declaration_template' => null
            ];
        }
        
        // Use the most recent FTA rule
        $ftaRule = $ftaRules[0];
        
        // 2. Evaluate ROO requirements
        $rooEvaluation = $this->evaluateRoo($ftaRule, $bomData);
        
        // 3. Check COO supplier declarations
        $cooVerification = $this->verifyCooDeclarations($bomData);
        
        // 4. Calculate regional value content (if required)
        // getMinimumValueContent() returns a decimal string (e.g., "35.00") or null
        $minimumRvc = (float) ($ftaRule->getMinimumValueContent() ?? 0);
        $rvcCalculation = null;
        
        if ($minimumRvc > 0 && $totalValue > 0) {
            $rvcCalculation = $this->calculateRegionalValueContent(
                $totalValue,
                $bomData,
                $ftaRule->getFtaAgreement()
            );
        }
        
        // 5. Determine eligibility status
        $missingEvidence = [];
        $confidence = 95;
        
        // Check if ROO passes
        if (!$rooEvaluation['passes']) {
            return [
                'eligible' => 'INELIGIBLE',
                'status' => 'INELIGIBLE',
                'fta_agreement' => $ftaRule->getFtaAgreement(),
                'basis' => 'Does not meet Rules of Origin: ' . $rooEvaluation['details'],
                'confidence' => 100,
                'missing_evidence' => [],
                'declaration_template' => null
            ];
        }
        
        // Check if RVC meets threshold
        if ($rvcCalculation && !$rvcCalculation['meets_threshold']) {
            return [
                'eligible' => 'INELIGIBLE',
                'status' => 'INELIGIBLE',
                'fta_agreement' => $ftaRule->getFtaAgreement(),
                'basis' => sprintf('Regional Value Content %.1f%% below required %.1f%%', 
                    $rvcCalculation['rvc_percent'], 
                    $rvcCalculation['required_percent']
                ),
                'confidence' => 100,
                'missing_evidence' => [],
                'declaration_template' => null
            ];
        }
        
        // Check if COO declarations are missing
        if ($cooVerification['verified_percent'] < 100) {
            $missingEvidence[] = sprintf(
                'Missing COO declarations for %d MPN(s)', 
                count($cooVerification['missing_mpns'])
            );
            $confidence = 70;
        }
        
        $status = empty($missingEvidence) ? 'ELIGIBLE' : 'CONDITIONAL';
        
        return [
            'eligible' => $status,
            'status' => $status,
            'fta_agreement' => $ftaRule->getFtaAgreement(),
            'basis' => $rooEvaluation['method'],
            'confidence' => $confidence,
            'missing_evidence' => $missingEvidence,
            'declaration_template' => null,
            'roo_evaluation' => $rooEvaluation,
            'coo_verification' => $cooVerification,
            'rvc_calculation' => $rvcCalculation
        ];
    }

    /**
     * Evaluate specific ROO rule for FTA
     *
     * Determines whether a shipment meets the Rules of Origin requirements
     * for preferential tariff treatment under an FTA agreement.
     *
     * ROO Methods (parsed from ftaRule.rooRequirement text):
     *   a) CTH (Change in Tariff Heading):
     *       Product's HS heading (first 4 digits) differs from all component materials
     *   b) CTC (Change in Tariff Classification/Chapter):
     *       Product's HS chapter (first 2 digits) differs from all component materials
     *   c) RVC (Regional Value Content):
     *       Minimum % of value must originate from FTA region (checked separately)
     *   d) WHOLLY_OBTAINED:
     *       All materials must originate from FTA region countries
     *   e) Specific Process:
     *       Product must undergo specific manufacturing process in FTA region
     *
     * @param FtaRule $ftaRule FTA rule entity
     * @param array $bomData BOM with hts_code per line item
     * @return array ROO evaluation: ['passes' => bool, 'method' => string, 'details' => string]
     */
    public function evaluateRoo(FtaRule $ftaRule, array $bomData): array
    {
        // Parse ROO requirement text from the entity's rooRequirement field
        // The text contains the rule (e.g., "CTH", "CTC 4-6", "RVC 60%", "WHOLLY_OBTAINED")
        $rooText = strtoupper($ftaRule->getRooRequirement() ?? '');
        
        if (empty($rooText)) {
            // No specific ROO requirement defined — assume passes
            return [
                'passes' => true,
                'method' => 'No specific ROO requirements',
                'details' => 'FTA rule does not specify ROO evaluation logic',
                'roo_text' => null,
            ];
        }
        
        try {
            // Determine the ROO method from the requirement text
            $method = 'UNKNOWN';
            
            if (str_contains($rooText, 'WHOLLY OBTAINED') || str_contains($rooText, 'WHOLLY_OBTAINED') || str_contains($rooText, 'WHOLLYOBTAINED')) {
                $method = 'WHOLLY_OBTAINED';
            } elseif (str_contains($rooText, 'CTC') || str_contains($rooText, 'CHANGE IN TARIFF CLASSIFICATION') || str_contains($rooText, 'CC')) {
                $method = 'CTC';
            } elseif (str_contains($rooText, 'CTH') || str_contains($rooText, 'CHANGE IN TARIFF HEADING') || str_contains($rooText, 'CH') || str_contains($rooText, 'HEADING')) {
                $method = 'CTH';
            } elseif (str_contains($rooText, 'RVC') || str_contains($rooText, 'REGIONAL VALUE') || str_contains($rooText, 'VALUE CONTENT')) {
                $method = 'RVC';
            } elseif (str_contains($rooText, 'SPECIFIC PROCESS') || str_contains($rooText, 'MANUFACTURING PROCESS') || str_contains($rooText, 'TECHNICAL REQUIREMENT')) {
                $method = 'SPECIFIC_PROCESS';
            }
            
            // Extract the product's HS code from the rule
            $productHsCode = $ftaRule->getHsCode() ?? '';
            $productNormalized = str_replace('.', '', $productHsCode);
            
            switch ($method) {
                case 'CTH':
                    // CTH: Product's HS heading (first 4 digits) must differ from all component headings
                    $productHeading = substr($productNormalized, 0, 4);
                    $componentHeadings = [];
                    
                    foreach ($bomData as $item) {
                        $itemHts = $item['hts_code'] ?? '';
                        $itemNormalized = str_replace('.', '', $itemHts);
                        $itemHeading = substr($itemNormalized, 0, 4);
                        if (!empty($itemHeading)) {
                            $componentHeadings[] = $itemHeading;
                        }
                    }
                    
                    // Check if any component shares the same heading as the product
                    $sameHeading = in_array($productHeading, $componentHeadings);
                    $passes = !$sameHeading || empty($componentHeadings);
                    
                    return [
                        'passes' => $passes,
                        'method' => 'CTH',
                        'details' => $passes
                            ? sprintf('Tariff heading change: product %s differs from component headings [%s]', $productHeading, implode(', ', array_unique($componentHeadings)))
                            : sprintf('No tariff heading change: product %s shares heading with components', $productHeading),
                        'product_heading' => $productHeading,
                        'component_headings' => array_unique($componentHeadings),
                        'roo_text' => $rooText,
                    ];
                    
                case 'CTC':
                    // CTC: Product's HS chapter (first 2 digits) must differ from all component chapters
                    $productChapter = substr($productNormalized, 0, 2);
                    $componentChapters = [];
                    
                    foreach ($bomData as $item) {
                        $itemHts = $item['hts_code'] ?? '';
                        $itemNormalized = str_replace('.', '', $itemHts);
                        $itemChapter = substr($itemNormalized, 0, 2);
                        if (!empty($itemChapter)) {
                            $componentChapters[] = $itemChapter;
                        }
                    }
                    
                    // Check if any component shares the same chapter as the product
                    $sameChapter = in_array($productChapter, $componentChapters);
                    $passes = !$sameChapter || empty($componentChapters);
                    
                    return [
                        'passes' => $passes,
                        'method' => 'CTC',
                        'details' => $passes
                            ? sprintf('Tariff chapter change: product %s differs from component chapters [%s]', $productChapter, implode(', ', array_unique($componentChapters)))
                            : sprintf('No tariff chapter change: product %s shares chapter with components', $productChapter),
                        'product_chapter' => $productChapter,
                        'component_chapters' => array_unique($componentChapters),
                        'roo_text' => $rooText,
                    ];
                    
                case 'RVC':
                    // RVC is evaluated separately in checkEligibility()
                    return [
                        'passes' => true,
                        'method' => 'RVC',
                        'details' => 'Regional Value Content checked separately in eligibility calculation',
                        'roo_text' => $rooText,
                    ];
                    
                case 'WHOLLY_OBTAINED':
                    // All BOM items must have COO within the FTA region
                    $ftaAgreement = $ftaRule->getFtaAgreement() ?? '';
                    $regionCountries = $this->getFtaRegionCountries($ftaAgreement);
                    $nonOriginating = [];
                    
                    foreach ($bomData as $item) {
                        $coo = $item['country_of_origin'] ?? null;
                        if ($coo === null) {
                            $nonOriginating[] = $item['mpn'] ?? 'unknown';
                        } elseif (!in_array($coo, $regionCountries)) {
                            $nonOriginating[] = sprintf('%s (%s)', $item['mpn'] ?? 'unknown', $coo);
                        }
                    }
                    
                    $passes = empty($nonOriginating);
                    
                    return [
                        'passes' => $passes,
                        'method' => 'Wholly Obtained',
                        'details' => $passes
                            ? 'All materials originate from FTA region'
                            : sprintf('Materials from outside FTA region: %s', implode('; ', $nonOriginating)),
                        'non_originating' => $nonOriginating,
                        'roo_text' => $rooText,
                    ];
                    
                case 'SPECIFIC_PROCESS':
                    // Specific process requirements — assume passes with note
                    return [
                        'passes' => true,
                        'method' => 'Specific Process',
                        'details' => 'Specific manufacturing process requirement: ' . $rooText,
                        'roo_text' => $rooText,
                    ];
                    
                default:
                    // Unknown/unspecified method — assume passes
                    return [
                        'passes' => true,
                        'method' => 'UNKNOWN',
                        'details' => sprintf('ROO method "%s" not explicitly implemented; defaulting to pass', $rooText),
                        'roo_text' => $rooText,
                    ];
            }
        } catch (\Exception $e) {
            return [
                'passes' => true,
                'method' => 'UNKNOWN',
                'details' => 'ROO evaluation error: ' . $e->getMessage(),
                'roo_text' => $rooText,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate FTA declaration template
     *
     * Builds a standard FTA certificate of origin text template with
     * the applicable rule language and shipment details.
     *
     * @param string $htsCode The classified HTS code
     * @param string $originCountry Origin country code
     * @param string $destinationCountry Destination country code
     * @param array $eligibilityResult Result from checkEligibility()
     * @return string Pre-filled declaration text
     */
    public function generateDeclarationTemplate(
        string $htsCode,
        string $originCountry,
        string $destinationCountry,
        array $eligibilityResult
    ): string {
        // Build a standard FTA declaration template
        $ftaAgreement = $eligibilityResult['fta_agreement'] ?? 'Unknown FTA';
        $rooBasis = $eligibilityResult['basis'] ?? 'Not specified';
        
        $template = "FREE TRADE AGREEMENT DECLARATION\n\n";
        $template .= "FTA Agreement: {$ftaAgreement}\n";
        $template .= "Origin Country: {$originCountry}\n";
        $template .= "Destination Country: {$destinationCountry}\n";
        $template .= "HTS Code: {$htsCode}\n\n";
        
        $template .= "CERTIFICATION\n\n";
        $template .= "I certify that the goods described in this document qualify as originating goods ";
        $template .= "for purposes of preferential tariff treatment under the {$ftaAgreement}.\n\n";
        
        $template .= "These goods meet the origin requirements because:\n";
        $template .= "- {$rooBasis}\n\n";
        
        if (isset($eligibilityResult['rvc_calculation'])) {
            $rvc = $eligibilityResult['rvc_calculation'];
            $template .= sprintf(
                "- Regional Value Content: %.1f%% (Minimum required: %.1f%%)\n\n",
                $rvc['rvc_percent'] ?? 0,
                $rvc['required_percent'] ?? 0
            );
        }
        
        $template .= "I declare that the information provided is true and accurate to the best of my knowledge.\n\n";
        $template .= "Signature: ___________________________\n";
        $template .= "Name: ________________________________\n";
        $template .= "Title: ________________________________\n";
        $template .= "Date: " . date('Y-m-d') . "\n";
        
        return $template;
    }

    /**
     * Calculate regional value content (RVC)
     * 
     * @param float $totalValue Total shipment value
     * @param array $bomData BOM with COO and values
     * @param string $ftaRegion FTA region (e.g., 'NAFTA', 'EU', 'MA-US')
     * @return array RVC calculation: ['rvc_percent' => 65.5, 'originating_value' => 6550.00, 'non_originating_value' => 3450.00, 'meets_threshold' => true, 'required_percent' => 60]
     * 
     * TODO Implementation:
     * 1. Sum originating material values:
     *    → Materials with COO in FTA region
     * 2. Sum non-originating material values:
     *    → Materials with COO outside FTA region
     * 3. Calculate RVC:
     *    → RVC = ((totalValue - nonOriginatingValue) / totalValue) * 100
     * 4. Compare to FTA threshold (typically 50-75%)
     * 5. Return calculation breakdown
     */
    public function calculateRegionalValueContent(
        float $totalValue,
        array $bomData,
        string $ftaRegion,
        float $requiredPercent = 60.0
    ): array {
        if ($totalValue <= 0) {
            return [
                'rvc_percent' => 0,
                'originating_value' => 0,
                'non_originating_value' => 0,
                'meets_threshold' => false,
                'required_percent' => $requiredPercent
            ];
        }
        
        $originatingValue = 0;
        $nonOriginatingValue = 0;
        
        // Determine FTA region countries (simplified)
        $regionCountries = $this->getFtaRegionCountries($ftaRegion);
        
        foreach ($bomData as $item) {
            $itemValue = ($item['unit_price'] ?? 0) * ($item['quantity'] ?? 0);
            $coo = $item['country_of_origin'] ?? null;
            
            if ($coo && in_array($coo, $regionCountries)) {
                $originatingValue += $itemValue;
            } else {
                $nonOriginatingValue += $itemValue;
            }
        }
        
        // RVC = ((Total Value - Non-Originating) / Total Value) * 100
        $rvcPercent = (($totalValue - $nonOriginatingValue) / $totalValue) * 100;
        $meetsThreshold = $rvcPercent >= $requiredPercent;
        
        return [
            'rvc_percent' => round($rvcPercent, 1),
            'originating_value' => round($originatingValue, 2),
            'non_originating_value' => round($nonOriginatingValue, 2),
            'meets_threshold' => $meetsThreshold,
            'required_percent' => $requiredPercent
        ];
    }
    
    /**
     * Get list of countries in FTA region
     */
    private function getFtaRegionCountries(string $ftaRegion): array
    {
        $euCountries = [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        ];
        
        $regionMap = [
            'MA-US FTA' => ['MA', 'US'],
            'Morocco-US FTA' => ['MA', 'US'],
            'EU-Morocco Association Agreement' => array_merge(['MA'], $euCountries),
            'EU' => $euCountries,
            'NAFTA' => ['US', 'CA', 'MX'],
            'USMCA' => ['US', 'CA', 'MX'],
            'CETA' => array_merge(['CA'], $euCountries),
            'EU-Turkey Customs Union' => array_merge(['TR'], $euCountries),
        ];
        
        return $regionMap[$ftaRegion] ?? [];
    }

    /**
     * Verify COO supplier declarations
     * 
     * @param array $bomData BOM with MPNs and manufacturers
     * @return array Verification result: ['verified_mpns' => ['STM32F407VGT6'], 'missing_mpns' => ['TPS62140'], 'verified_percent' => 75.5]
     * 
     * TODO Implementation:
     * 1. Extract MPNs from BOM
     * 2. Query coo_supplier_decls table:
     *    → WHERE mpn IN (:mpns) AND is_verified = true
     * 3. Match found declarations to BOM lines
     * 4. Calculate verification percentage:
     *    → (verified_mpns / total_mpns) * 100
     * 5. Return verification summary
     */
    public function verifyCooDeclarations(array $bomData): array
    {
        if (empty($bomData)) {
            return [
                'verified_mpns' => [],
                'missing_mpns' => [],
                'verified_percent' => 100
            ];
        }
        
        // Extract MPNs from BOM
        $mpns = array_filter(array_column($bomData, 'mpn'));
        
        if (empty($mpns)) {
            return [
                'verified_mpns' => [],
                'missing_mpns' => [],
                'verified_percent' => 100
            ];
        }
        
        // Query COO declarations
        $declarations = $this->cooSupplierDeclRepository->createQueryBuilder('c')
            ->where('c.mpn IN (:mpns)')
            ->andWhere('c.isVerified = true')
            ->setParameter('mpns', $mpns)
            ->getQuery()
            ->getResult();
        
        // Build verified MPN list
        $verifiedMpns = [];
        foreach ($declarations as $decl) {
            $verifiedMpns[] = $decl->getMpn();
        }
        
        // Determine missing MPNs
        $missingMpns = array_diff($mpns, $verifiedMpns);
        
        // Calculate verification percentage
        $verifiedPercent = count($mpns) > 0 
            ? round((count($verifiedMpns) / count($mpns)) * 100, 1)
            : 100;
        
        return [
            'verified_mpns' => array_values($verifiedMpns),
            'missing_mpns' => array_values($missingMpns),
            'verified_percent' => $verifiedPercent
        ];
    }

    /**
     * Get applicable FTA agreements for origin-destination pair
     *
     * Determines the FTA agreement name from the country pair, then queries the
     * fta_rules table using actual entity fields:
     *   - f.ftaAgreement (string) — FTA agreement name
     *   - f.effectiveDate (date) — when the rule takes effect
     *   - f.expiryDate (date, nullable) — when the rule expires
     *
     * @param string $originCountry Origin country code (e.g., 'MA')
     * @param string $destinationCountry Destination country code (e.g., 'US')
     * @return array Array of FtaRule entities
     */
    public function getApplicableFtas(string $originCountry, string $destinationCountry): array
    {
        $ftaAgreement = $this->determineFtaAgreement($originCountry, $destinationCountry);
        
        if ($ftaAgreement === null) {
            return [];
        }
        
        $today = new \DateTime('now', new \DateTimeZone('UTC'));
        
        return $this->ftaRuleRepository->createQueryBuilder('f')
            ->where('f.ftaAgreement = :agreement')
            ->andWhere('f.effectiveDate <= :today')
            ->andWhere('f.expiryDate IS NULL OR f.expiryDate >= :today')
            ->setParameter('agreement', $ftaAgreement)
            ->setParameter('today', $today)
            ->orderBy('f.effectiveDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Determine the FTA agreement name from a country pair
     *
     * Maps origin/destination country codes to known FTA agreements.
     * The fta_rules table stores agreement names in f.ftaAgreement,
     * so this method resolves the applicable agreement for the country pair.
     *
     * Supported FTAs:
     *   - Morocco-US FTA (MA ↔ US)
     *   - USMCA (US ↔ CA ↔ MX)
     *   - EU-Morocco Association Agreement (MA ↔ EU member states)
     *   - CETA (CA ↔ EU member states)
     *   - EU-Turkey Customs Union (TR ↔ EU member states)
     *
     * @param string $originCountry
     * @param string $destinationCountry
     * @return string|null The FTA agreement name, or null if no agreement applies
     */
    private function determineFtaAgreement(string $originCountry, string $destinationCountry): ?string
    {
        $origin = strtoupper($originCountry);
        $dest = strtoupper($destinationCountry);
        
        // Direct country-pair mapping for known FTAs
        $ftaMap = [
            'MA-US' => 'Morocco-US FTA',
            'US-MA' => 'Morocco-US FTA',
            'US-CA' => 'USMCA',
            'CA-US' => 'USMCA',
            'US-MX' => 'USMCA',
            'MX-US' => 'USMCA',
            'CA-MX' => 'USMCA',
            'MX-CA' => 'USMCA',
        ];
        
        $pairKey = $origin . '-' . $dest;
        if (isset($ftaMap[$pairKey])) {
            return $ftaMap[$pairKey];
        }
        
        // EU member states
        $euCountries = [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        ];
        
        // EU-Morocco Association Agreement: Morocco ↔ any EU member state
        if ($origin === 'MA' && in_array($dest, $euCountries, true)) {
            return 'EU-Morocco Association Agreement';
        }
        if (in_array($origin, $euCountries, true) && $dest === 'MA') {
            return 'EU-Morocco Association Agreement';
        }
        
        // CETA: Canada ↔ any EU member state
        if ($origin === 'CA' && in_array($dest, $euCountries, true)) {
            return 'CETA';
        }
        if (in_array($origin, $euCountries, true) && $dest === 'CA') {
            return 'CETA';
        }
        
        // EU-Turkey Customs Union: Turkey ↔ any EU member state
        if ($origin === 'TR' && in_array($dest, $euCountries, true)) {
            return 'EU-Turkey Customs Union';
        }
        if (in_array($origin, $euCountries, true) && $dest === 'TR') {
            return 'EU-Turkey Customs Union';
        }
        
        return null;
    }

    /**
     * Determine if watermark needed on FTA pack PDF
     * 
     * @param array $eligibilityResult Result from checkEligibility()
     * @return array Watermark decision: ['watermark' => true, 'text' => 'CONDITIONAL - VERIFY BEFORE SUBMISSION', 'reason' => 'Missing COO declarations for 3 MPNs']
     * 
     * TODO Implementation:
     * - If status = 'CONDITIONAL': watermark = true, text = 'CONDITIONAL - VERIFY BEFORE SUBMISSION'
     * - If status = 'INELIGIBLE': watermark = true, text = 'NOT FTA ELIGIBLE - USE MFN RATE'
     * - If status = 'ELIGIBLE': watermark = false
     * - Add reason from eligibilityResult.missing_evidence
     */
    public function shouldWatermark(array $eligibilityResult): array
    {
        if ($eligibilityResult['status'] === 'CONDITIONAL') {
            return [
                'watermark' => true,
                'text' => 'CONDITIONAL - VERIFY BEFORE SUBMISSION',
                'reason' => implode(', ', $eligibilityResult['missing_evidence'] ?? []),
            ];
        }

        if ($eligibilityResult['status'] === 'INELIGIBLE') {
            return [
                'watermark' => true,
                'text' => 'NOT FTA ELIGIBLE - USE MFN RATE',
                'reason' => $eligibilityResult['basis'] ?? 'Does not meet ROO requirements',
            ];
        }

        return ['watermark' => false, 'text' => null, 'reason' => null];
    }
}
