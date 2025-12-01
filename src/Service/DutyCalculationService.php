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
        // Step 1: Look up tariff rate
        $tariffRate = $this->tariffRateRepository->createQueryBuilder('tr')
            ->where('tr.hsCode = :hsCode')
            ->andWhere('tr.destinationCountry = :dest')
            ->andWhere('tr.originCountry = :origin')
            ->andWhere('tr.effectiveDate <= :today')
            ->andWhere('tr.expiryDate IS NULL OR tr.expiryDate >= :today')
            ->setParameter('hsCode', $htsCode)
            ->setParameter('dest', $destinationCountry)
            ->setParameter('origin', $originCountry)
            ->setParameter('today', new \DateTime())
            ->orderBy('tr.effectiveDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        if (!$tariffRate) {
            throw new \RuntimeException(
                "Tariff rate not found for HTS: $htsCode, Origin: $originCountry, Destination: $destinationCountry"
            );
        }
        
        // Step 2: Determine which rate to use (FTA or MFN)
        $dutyRate = 0.0;
        $ftaSavings = null;
        $method = 'MFN';
        
        if ($useFta && $tariffRate->getFtaRate() !== null) {
            // Use FTA preferential rate
            $dutyRate = (float) $tariffRate->getFtaRate();
            $mfnRate = (float) ($tariffRate->getMfnRate() ?? $tariffRate->getDutyRate());
            $ftaSavings = $customsValue * ($mfnRate - $dutyRate) / 100;
            $method = 'FTA';
        } else {
            // Use MFN standard rate
            $dutyRate = (float) ($tariffRate->getMfnRate() ?? $tariffRate->getDutyRate());
        }
        
        // Step 3: Calculate duty amount (ad-valorem)
        $dutyAmount = $customsValue * ($dutyRate / 100);
        
        // Step 4: Calculate VAT if DDP incoterm
        $vatRate = 0.0;
        $vatAmount = 0.0;
        
        if ($incoterm === 'DDP') {
            $vatData = $this->calculateVat($customsValue, $dutyAmount, $destinationCountry);
            $vatRate = $vatData['vatRate'];
            $vatAmount = $vatData['vatAmount'];
        }
        
        return [
            'dutyRate' => $dutyRate,
            'dutyAmount' => round($dutyAmount, 2),
            'vatRate' => $vatRate,
            'vatAmount' => round($vatAmount, 2),
            'totalTax' => round($dutyAmount + $vatAmount, 2),
            'method' => $method,
            'ftaSavings' => $ftaSavings ? round($ftaSavings, 2) : null,
            'ftaAgreement' => $useFta ? $tariffRate->getFtaAgreement() : null,
            'breakdown' => [
                'customsValue' => $customsValue,
                'hsCode' => $htsCode,
                'originCountry' => $originCountry,
                'destinationCountry' => $destinationCountry,
                'quantity' => $quantity,
                'uom' => $uom
            ]
        ];
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
        // Query tariff_rates for latest MFN rate
        $tariffRate = $this->tariffRateRepository->createQueryBuilder('tr')
            ->where('tr.hsCode = :hsCode')
            ->andWhere('tr.destinationCountry = :dest')
            ->andWhere('tr.effectiveDate <= :today')
            ->andWhere('tr.expiryDate IS NULL OR tr.expiryDate >= :today')
            ->setParameter('hsCode', $htsCode)
            ->setParameter('dest', $destinationCountry)
            ->setParameter('today', new \DateTime())
            ->orderBy('tr.effectiveDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        if (!$tariffRate) {
            throw new \RuntimeException(
                "Tariff rate not found for HTS: $htsCode, Destination: $destinationCountry"
            );
        }
        
        // Use MFN rate if available, otherwise fallback to standard duty rate
        $dutyRate = (float) ($tariffRate->getMfnRate() ?? $tariffRate->getDutyRate());
        
        // Calculate duty amount (ad-valorem)
        $dutyAmount = $customsValue * ($dutyRate / 100);
        
        return [
            'dutyRate' => $dutyRate,
            'dutyAmount' => round($dutyAmount, 2),
            'dutyType' => 'AD_VALOREM', // Simplified for now
            'specificRate' => null,
            'specificUom' => null
        ];
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
        // Country-specific VAT rates
        $vatRates = [
            'MA' => 20.0,  // Morocco
            'FR' => 20.0,  // France
            'DE' => 19.0,  // Germany
            'ES' => 21.0,  // Spain
            'IT' => 22.0,  // Italy
            'NL' => 21.0,  // Netherlands
            'BE' => 21.0,  // Belgium
            'UK' => 20.0,  // United Kingdom
            'US' => 0.0,   // USA (no federal VAT)
            'CN' => 13.0,  // China
            'IN' => 18.0,  // India
            'TR' => 18.0,  // Turkey
        ];
        
        $vatRate = $vatRates[$destinationCountry] ?? 0.0;
        
        // Calculate VAT base (duty-inclusive)
        $vatBase = $customsValue + $dutyAmount;
        
        // Calculate VAT amount
        $vatAmount = $vatBase * ($vatRate / 100);
        
        return [
            'vatRate' => $vatRate,
            'vatAmount' => round($vatAmount, 2),
            'vatBase' => round($vatBase, 2)
        ];
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
