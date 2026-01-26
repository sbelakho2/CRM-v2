<?php

namespace App\Service;

use App\Entity\FtaRule;
use App\Entity\CooSupplierDecl;
use App\Entity\Estimate;
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
 * Used by: Landed-Cost Estimator, Quote Co-Pilot
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
        $minimumRvc = $ftaRule->getMinimumValueContentPercent() ?? 0;
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
     * @param FtaRule $ftaRule FTA rule entity
     * @param array $bomData BOM data
     * @return array ROO evaluation: ['passes' => true, 'method' => 'Change in tariff heading', 'details' => '...']
     * 
     * TODO Implementation:
     * 1. Parse ftaRule.rule_logic_json to get ROO requirements
     * 2. Common ROO methods:
     *    a) Change in Tariff Heading (CTH):
     *       → Check if final product HTS differs from input materials HTS at chapter/heading level
     *    b) Change in Tariff Classification (CTC):
     *       → More strict than CTH, requires change at tariff line level
     *    c) Regional Value Content (RVC):
     *       → Calculate % of value from FTA region
     *    d) Wholly Obtained:
     *       → Product must be 100% from FTA region
     *    e) Specific Process:
     *       → Product must undergo specific manufacturing process in FTA region
     * 3. Apply rule to BOM data
     * 4. Return pass/fail with details
     */
    public function evaluateRoo(FtaRule $ftaRule, array $bomData): array
    {
        // Parse rule logic (simplified for now)
        $ruleLogic = $ftaRule->getRuleLogicJson();
        
        if (!$ruleLogic) {
            // Default: assume passes if no specific logic defined
            return [
                'passes' => true,
                'method' => 'No specific ROO requirements defined',
                'details' => 'FTA rule does not specify ROO evaluation logic'
            ];
        }
        
        try {
            $logic = json_decode($ruleLogic, true);
            $method = $logic['method'] ?? 'UNKNOWN';
            
            switch ($method) {
                case 'CTH': // Change in Tariff Heading
                case 'CTC': // Change in Tariff Classification
                    // Simplified: check if BOM has different HTS codes
                    $htsCodes = array_unique(array_filter(array_column($bomData, 'hts_code')));
                    $passes = count($htsCodes) > 1 || empty($bomData);
                    
                    return [
                        'passes' => $passes,
                        'method' => $method,
                        'details' => $passes 
                            ? 'Tariff classification change detected'
                            : 'No tariff classification change'
                    ];
                    
                case 'RVC': // Regional Value Content
                    // This is checked separately in checkEligibility
                    return [
                        'passes' => true,
                        'method' => 'Regional Value Content',
                        'details' => 'RVC will be checked separately'
                    ];
                    
                case 'WHOLLY_OBTAINED':
                    // All materials must be from FTA region
                    $allFromRegion = true;
                    foreach ($bomData as $item) {
                        if (isset($item['country_of_origin']) && 
                            $item['country_of_origin'] !== $ftaRule->getOriginCountry()) {
                            $allFromRegion = false;
                            break;
                        }
                    }
                    
                    return [
                        'passes' => $allFromRegion,
                        'method' => 'Wholly Obtained',
                        'details' => $allFromRegion
                            ? 'All materials from FTA region'
                            : 'Some materials from outside FTA region'
                    ];
                    
                default:
                    // Unknown method - assume passes
                    return [
                        'passes' => true,
                        'method' => $method,
                        'details' => 'ROO evaluation method not implemented'
                    ];
            }
        } catch (\Exception $e) {
            // If parsing fails, assume passes with warning
            return [
                'passes' => true,
                'method' => 'UNKNOWN',
                'details' => 'ROO evaluation error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate FTA declaration template
     * 
     * @param FtaRule $ftaRule FTA rule entity
     * @param array $eligibilityResult Result from checkEligibility()
     * @param array $shipmentData Shipment details (invoice number, date, exporter, importer, etc.)
     * @return string Pre-filled declaration text
     * 
     * TODO Implementation:
     * 1. Get ftaRule.declaration_template (full legal text)
     * 2. Replace variables with actual data:
     *    → {{exporter_name}}, {{exporter_address}}, {{importer_name}}, {{importer_address}}
     *    → {{invoice_number}}, {{invoice_date}}, {{invoice_value}}, {{currency}}
     *    → {{origin_country}}, {{destination_country}}, {{hs_codes}}
     *    → {{roo_basis}} (e.g., "Change in tariff heading from 8542.31 to 8542.39")
     *    → {{rvc_percentage}} (if applicable)
     * 3. Add required legal language:
     *    → "I certify that the goods described in this document..."
     *    → Penalties for false statements
     *    → Signature/date lines
     * 4. Return formatted declaration (plain text or HTML)
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
        // Simplified mapping - in production, query from database
        $regionMap = [
            'MA-US FTA' => ['MA', 'US'],
            'Morocco-US FTA' => ['MA', 'US'],
            'EU' => ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'],
            'NAFTA' => ['US', 'CA', 'MX'],
            'USMCA' => ['US', 'CA', 'MX'],
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
     * @param string $originCountry Origin country code
     * @param string $destinationCountry Destination country code
     * @return array Array of FtaRule entities
     * 
     * TODO Implementation:
     * 1. Query fta_rules table
     * 2. Filter by origin_country and destination_country
     * 3. Filter by is_active = true
     * 4. Filter by effective_date <= today AND (expiry_date IS NULL OR expiry_date >= today)
     * 5. Order by effective_date DESC
     * 6. Return array of FTA rules
     */
    public function getApplicableFtas(string $originCountry, string $destinationCountry): array
    {
        $today = new \DateTime();
        
        return $this->ftaRuleRepository->createQueryBuilder('f')
            ->where('f.originCountry = :origin')
            ->andWhere('f.destinationCountry = :dest')
            ->andWhere('f.isActive = true')
            ->andWhere('f.effectiveFrom <= :today')
            ->andWhere('f.effectiveTo IS NULL OR f.effectiveTo >= :today')
            ->setParameter('origin', $originCountry)
            ->setParameter('dest', $destinationCountry)
            ->setParameter('today', $today)
            ->orderBy('f.effectiveFrom', 'DESC')
            ->getQuery()
            ->getResult();
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
