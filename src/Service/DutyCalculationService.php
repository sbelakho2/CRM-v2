<?php

namespace App\Service;

use App\Entity\TariffRate;
use App\Entity\FtaRule;
use App\Repository\TariffRateRepository;
use App\Repository\FtaRuleRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * DutyCalculationService
 * 
 * Calculates import duties and taxes for shipments.
 * Supports:
 * - FTA preferential rates (0% or reduced)
 * - MFN (Most Favored Nation) standard rates
 * - Ad-valorem duty (% of customs value)
 * - Specific duty ($ per unit/kg)
 * - Mixed duty (greater of ad-valorem or specific)
 * - VAT/GST for DDP Incoterms
 * 
 * Used by:
 * - QuoteEstimatorController for landed-cost calculations
 * - FtaEligibilityService for duty savings analysis
 */
class DutyCalculationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TariffRateRepository $tariffRateRepository,
        private FtaRuleRepository $ftaRuleRepository,
        private FtaEligibilityService $ftaEligibilityService
    ) {}

    /**
     * Calculate total duty and tax for a shipment
     * 
     * @param string $htsCode - HTS code (e.g., "8473.30.51")
     * @param float $customsValue - Customs value (FOB or CIF depending on country)
     * @param float $quantity - Quantity of goods
     * @param string $uom - Unit of measure (EA, KG, etc.)
     * @param string $destinationCountry - ISO 2-letter country code
     * @param string $originCountry - ISO 2-letter country code
     * @param bool $useFta - Whether to apply FTA rate (if eligible)
     * @param string|null $incoterm - Incoterm (DDP requires VAT/GST calculation)
     * 
     * @return array{
     *   dutyRate: float,
     *   dutyAmount: float,
     *   vatRate: float,
     *   vatAmount: float,
     *   totalTax: float,
     *   method: string,
     *   ftaSavings: float|null,
     *   breakdown: array
     * }
     */
    public function calculateDuty(
        string $htsCode,
        float $customsValue,
        float $quantity,
        string $uom,
        string $destinationCountry,
        string $originCountry,
        bool $useFta = false,
        ?string $incoterm = null
    ): array {
        // TODO: Implement duty calculation
        // 
        // Steps:
        // 1. Query tariff_rates table for HTS code + destination country
        //    SELECT * FROM tariff_rates 
        //    WHERE hts_code = :hts AND destination_country = :dest
        //    AND asof <= :today ORDER BY asof DESC LIMIT 1
        // 
        // 2. If useFta = true, check FTA eligibility:
        //    - Call ftaEligibilityService->checkEligibility()
        //    - If ELIGIBLE, query fta_rules for preferential rate
        //    - If CONDITIONAL or INELIGIBLE, fall back to MFN
        // 
        // 3. Calculate duty amount based on duty_type:
        //    - AD_VALOREM: dutyAmount = customsValue * (dutyRate / 100)
        //    - SPECIFIC: dutyAmount = quantity * specificRate (convert UOM if needed)
        //    - MIXED: dutyAmount = max(adValoremAmount, specificAmount)
        // 
        // 4. If incoterm = 'DDP', calculate VAT/GST:
        //    - vatBase = customsValue + dutyAmount (duty inclusive base)
        //    - vatAmount = vatBase * (vatRate / 100)
        //    - Get VAT rate from tariff_rates.vat_rate (if null, default to 0)
        // 
        // 5. Calculate FTA savings (if applicable):
        //    - ftaSavings = mfnDutyAmount - ftaDutyAmount
        // 
        // 6. Return breakdown:
        //    return [
        //        'dutyRate' => (float) $dutyRate,
        //        'dutyAmount' => round($dutyAmount, 2),
        //        'vatRate' => (float) $vatRate,
        //        'vatAmount' => round($vatAmount, 2),
        //        'totalTax' => round($dutyAmount + $vatAmount, 2),
        //        'method' => $useFta ? 'FTA' : 'MFN',
        //        'ftaSavings' => $ftaSavings ? round($ftaSavings, 2) : null,
        //        'breakdown' => [
        //            'customsValue' => $customsValue,
        //            'dutyType' => $tariffRate->getDutyType(), // AD_VALOREM, SPECIFIC, MIXED
        //            'specificRate' => $tariffRate->getSpecificRate(),
        //            'specificUom' => $tariffRate->getSpecificUom(),
        //            'htsCode' => $htsCode,
        //            'originCountry' => $originCountry,
        //            'destinationCountry' => $destinationCountry
        //        ]
        //    ];

        throw new \RuntimeException('Feature not yet implemented');
    }

    /**
     * Apply FTA preferential rate
     * 
     * @param string $htsCode - HTS code
     * @param string $originCountry - Origin country
     * @param string $destinationCountry - Destination country
     * @param float $customsValue - Customs value
     * @param array $eligibilityResult - Result from FtaEligibilityService
     * 
     * @return array{
     *   dutyRate: float,
     *   ftaAgreement: string,
     *   requiresDeclaration: bool,
     *   declarationTemplate: string|null
     * }
     */
    public function applyFtaRate(
        string $htsCode,
        string $originCountry,
        string $destinationCountry,
        float $customsValue,
        array $eligibilityResult
    ): array {
        // TODO: Implement FTA rate application
        // 
        // Steps:
        // 1. Query fta_rules table:
        //    SELECT * FROM fta_rules
        //    WHERE origin_country = :origin 
        //    AND destination_country = :dest
        //    AND hts_code = :hts
        //    AND effective_from <= :today
        //    AND (effective_to IS NULL OR effective_to >= :today)
        //    LIMIT 1
        // 
        // 2. Get preferential_rate (usually 0% or reduced %)
        // 
        // 3. Check if declaration required:
        //    - If eligibility = 'CONDITIONAL', requiresDeclaration = true
        //    - Generate declaration template using ftaEligibilityService->generateDeclarationTemplate()
        // 
        // 4. Return FTA rate data:
        //    return [
        //        'dutyRate' => (float) $ftaRule->getPreferentialRate(),
        //        'ftaAgreement' => $ftaRule->getFtaAgreement(), // e.g., 'Morocco-US FTA'
        //        'requiresDeclaration' => $requiresDeclaration,
        //        'declarationTemplate' => $declarationTemplate ?? null
        //    ];

        throw new \RuntimeException('Feature not yet implemented');
    }

    /**
     * Apply MFN (Most Favored Nation) standard rate
     * 
     * @param string $htsCode - HTS code
     * @param string $destinationCountry - Destination country
     * @param float $customsValue - Customs value
     * @param float $quantity - Quantity
     * @param string $uom - Unit of measure
     * 
     * @return array{
     *   dutyRate: float,
     *   dutyAmount: float,
     *   dutyType: string,
     *   specificRate: float|null,
     *   specificUom: string|null
     * }
     */
    public function applyMfnRate(
        string $htsCode,
        string $destinationCountry,
        float $customsValue,
        float $quantity,
        string $uom
    ): array {
        // TODO: Implement MFN rate lookup
        // 
        // Steps:
        // 1. Query tariff_rates for latest MFN rate:
        //    $tariffRate = $this->tariffRateRepository->findOneBy([
        //        'htsCode' => $htsCode,
        //        'destinationCountry' => $destinationCountry
        //    ], ['asof' => 'DESC']);
        // 
        // 2. Calculate duty based on duty_type:
        //    - AD_VALOREM: dutyAmount = customsValue * (dutyRate / 100)
        //    - SPECIFIC: dutyAmount = quantity * specificRate (convert UOM)
        //    - MIXED: dutyAmount = max(adValorem, specific)
        // 
        // 3. Handle UOM conversion if specificUom != uom:
        //    - KG → G: multiply by 1000
        //    - EA → DOZEN: divide by 12
        //    - etc. (add conversions as needed)
        // 
        // 4. Return MFN rate data:
        //    return [
        //        'dutyRate' => (float) $tariffRate->getDutyRate(),
        //        'dutyAmount' => round($dutyAmount, 2),
        //        'dutyType' => $tariffRate->getDutyType(), // AD_VALOREM, SPECIFIC, MIXED
        //        'specificRate' => $tariffRate->getSpecificRate(),
        //        'specificUom' => $tariffRate->getSpecificUom()
        //    ];

        throw new \RuntimeException('Feature not yet implemented');
    }

    /**
     * Calculate VAT/GST for DDP shipments
     * 
     * @param float $customsValue - Customs value
     * @param float $dutyAmount - Calculated duty amount
     * @param string $destinationCountry - Destination country
     * 
     * @return array{
     *   vatRate: float,
     *   vatAmount: float,
     *   vatBase: float
     * }
     */
    public function calculateVat(
        float $customsValue,
        float $dutyAmount,
        string $destinationCountry
    ): array {
        // TODO: Implement VAT calculation
        // 
        // Steps:
        // 1. Get VAT rate from tariff_rates table (or country-specific VAT table):
        //    - Morocco: 20%
        //    - USA: 0% (no federal VAT, state sales tax handled separately)
        //    - EU countries: 15-27% (country-specific)
        // 
        // 2. Calculate VAT base (duty-inclusive):
        //    vatBase = customsValue + dutyAmount
        // 
        // 3. Calculate VAT amount:
        //    vatAmount = vatBase * (vatRate / 100)
        // 
        // 4. Return VAT data:
        //    return [
        //        'vatRate' => (float) $vatRate,
        //        'vatAmount' => round($vatAmount, 2),
        //        'vatBase' => round($vatBase, 2)
        //    ];

        throw new \RuntimeException('Feature not yet implemented');
    }

    /**
     * Calculate duty savings from using FTA vs MFN
     * 
     * @param string $htsCode - HTS code
     * @param float $customsValue - Customs value
     * @param float $quantity - Quantity
     * @param string $uom - Unit of measure
     * @param string $destinationCountry - Destination country
     * @param string $originCountry - Origin country
     * 
     * @return array{
     *   mfnDuty: float,
     *   ftaDuty: float,
     *   savings: float,
     *   savingsPercent: float,
     *   eligible: bool
     * }
     */
    public function calculateFtaSavings(
        string $htsCode,
        float $customsValue,
        float $quantity,
        string $uom,
        string $destinationCountry,
        string $originCountry
    ): array {
        // TODO: Implement FTA savings calculation
        // 
        // Steps:
        // 1. Calculate MFN duty:
        //    $mfnResult = $this->applyMfnRate($htsCode, $destinationCountry, $customsValue, $quantity, $uom);
        //    $mfnDuty = $mfnResult['dutyAmount'];
        // 
        // 2. Check FTA eligibility:
        //    $eligibility = $this->ftaEligibilityService->checkEligibility(...);
        //    if ($eligibility['eligible'] !== 'ELIGIBLE') {
        //        return ['eligible' => false, ...];
        //    }
        // 
        // 3. Calculate FTA duty:
        //    $ftaResult = $this->applyFtaRate($htsCode, $originCountry, $destinationCountry, $customsValue, $eligibility);
        //    $ftaDuty = $customsValue * ($ftaResult['dutyRate'] / 100);
        // 
        // 4. Calculate savings:
        //    $savings = $mfnDuty - $ftaDuty;
        //    $savingsPercent = $mfnDuty > 0 ? ($savings / $mfnDuty) * 100 : 0;
        // 
        // 5. Return savings data:
        //    return [
        //        'mfnDuty' => round($mfnDuty, 2),
        //        'ftaDuty' => round($ftaDuty, 2),
        //        'savings' => round($savings, 2),
        //        'savingsPercent' => round($savingsPercent, 1),
        //        'eligible' => true
        //    ];

        throw new \RuntimeException('Feature not yet implemented');
    }
}
