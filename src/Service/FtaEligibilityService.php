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
     * @param string $originCountry Origin country code (e.g., 'MA')
     * @param string $destinationCountry Destination country code (e.g., 'US', 'FR')
     * @param array $bomData BOM data with HTS codes and COO info
     * @param float $totalValue Total shipment value
     * @return array Eligibility result: ['eligible' => true, 'status' => 'ELIGIBLE|CONDITIONAL|INELIGIBLE', 'fta_agreement' => 'MA-US FTA', 'basis' => 'Regional value content 60%', 'confidence' => 95, 'missing_evidence' => [], 'declaration_template' => '...']
     * 
     * TODO Implementation:
     * 1. Identify applicable FTA agreement:
     *    → Query fta_rules WHERE origin_country = :origin AND destination_country = :dest AND is_active = true
     *    → Order by effective_date DESC
     * 2. Evaluate ROO requirements:
     *    → Call evaluateRoo() for each FTA rule
     * 3. Check COO supplier declarations:
     *    → Query coo_supplier_decls for BOM MPNs
     *    → Verify country_of_origin matches FTA requirements
     * 4. Calculate regional value content (if required):
     *    → Formula: RVC = ((Total Value - Non-Originating Materials) / Total Value) * 100
     *    → Check if RVC >= minimum_value_content_percent from FTA rule
     * 5. Determine eligibility status:
     *    → ELIGIBLE: All requirements met
     *    → CONDITIONAL: Requirements met but missing proof (no supplier COO decls)
     *    → INELIGIBLE: Requirements not met
     * 6. Return eligibility object
     */
    public function checkEligibility(
        string $originCountry,
        string $destinationCountry,
        array $bomData,
        float $totalValue
    ): array {
        // TODO: Implement FTA eligibility checking
        throw new \RuntimeException('FTA eligibility checking not yet implemented');
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
        // TODO: Implement ROO evaluation
        throw new \RuntimeException('ROO evaluation not yet implemented');
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
    public function generateDeclarationTemplate(FtaRule $ftaRule, array $eligibilityResult, array $shipmentData): string
    {
        // TODO: Implement declaration template generation
        throw new \RuntimeException('Declaration template generation not yet implemented');
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
        string $ftaRegion
    ): array {
        // TODO: Implement RVC calculation
        throw new \RuntimeException('RVC calculation not yet implemented');
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
        // TODO: Implement COO verification
        throw new \RuntimeException('COO verification not yet implemented');
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
        // TODO: Implement FTA lookup
        throw new \RuntimeException('FTA lookup not yet implemented');
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
