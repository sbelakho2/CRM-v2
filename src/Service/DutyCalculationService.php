<?php

declare(strict_types=1);

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
 * - QuoteCoPilotController for landed-cost calculations
 * - FtaEligibilityService for duty savings analysis
 */
/**
 * @phpstan-import-type EligibilityResult from \App\Service\FtaEligibilityService
 */
class DutyCalculationService
{
    public function __construct(
        /** Kept for future direct DQL; lookups currently go through the tariff repository. */
        protected EntityManagerInterface $entityManager,
        private TariffRateRepository $tariffRateRepository,
        /** Reserved for direct FTA rule lookups; not read yet. */
        protected FtaRuleRepository $ftaRuleRepository,
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
     *   ftaAgreement: string|null,
     *   breakdown: array<string, mixed>
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
        // Step 1: Look up tariff rate with parent HTS fallback.
        // Try exact HTS code first, then heading (6 digits), chapter (4 digits),
        // and finally section (2 digits) to ensure we always find a rate.
        $htsLevels = $this->buildHtsFallbackLevels($htsCode);
        $tariffRate = null;
        $matchedLevel = null;
        $matchedHtsCode = null;
        
        foreach ($htsLevels as $level => $code) {
            $tariffRate = $this->findTariffRate($code, $destinationCountry, $originCountry);
            if ($tariffRate !== null) {
                $matchedLevel = $level;
                $matchedHtsCode = $code;
                break;
            }
        }
        
        if (!$tariffRate) {
            throw new \RuntimeException(
                "Tariff rate not found for HTS: $htsCode or any parent heading/chapter. " .
                "Origin: $originCountry, Destination: $destinationCountry"
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
            // Clamp savings at 0: a preferential rate above MFN (bad data)
            // must never produce negative savings.
            $ftaSavings = max(0.0, $customsValue * ($mfnRate - $dutyRate) / 100);
            $method = 'FTA';
        } else {
            // Use MFN standard rate
            $dutyRate = (float) ($tariffRate->getMfnRate() ?? $tariffRate->getDutyRate());
        }
        
        // Step 3: Calculate the duty amount by the tariff's TYPE.
        //
        // A customs engine must not silently treat every tariff as a
        // percentage: the data model carries duty_type on the tariff row
        // ('ad_valorem' | 'specific' | 'compound'); unsupported/unknown
        // types raise an explicit unresolved result instead of returning a
        // silently-understated ad-valorem figure.
        $dutyType = strtolower((string) ($tariffRate->getDutyType() ?? 'ad_valorem'));
        $specificRate = $tariffRate->getSpecificRate(); // $ per UOM
        $specificAmount = 0.0;

        if ($dutyType === 'specific' || $dutyType === 'compound') {
            if ($specificRate === null || $quantity <= 0.0) {
                throw new \RuntimeException(sprintf(
                    'Tariff %s requires a specific rate and a positive quantity/UOM (%s); got rate=%s qty=%s.',
                    $dutyType,
                    $uom,
                    $specificRate === null ? 'null' : (string) $specificRate,
                    $quantity
                ));
            }
            $specificAmount = $quantity * (float) $specificRate;
        }

        $adValoremAmount = $customsValue * ($dutyRate / 100);

        $dutyAmount = match ($dutyType) {
            'ad_valorem' => $adValoremAmount,
            'specific' => $specificAmount,
            'compound' => $adValoremAmount + $specificAmount,
            default => throw new \RuntimeException(sprintf('Unsupported duty type "%s" for HTS %s — result unresolved rather than guessed.', $dutyType, $htsCode)),
        };
        
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
     * Looks up the preferential duty rate from the tariff_rates table
     * for the given HTS code under the applicable FTA agreement.
     * The FTA agreement name is determined from the eligibility result.
     *
     * @param string $htsCode - HTS code (e.g., "8473.30.51")
     * @param string $originCountry - Origin country code (ISO 2-letter)
     * @param string $destinationCountry - Destination country code (ISO 2-letter)
     * @param float $customsValue - Customs value
     * @param EligibilityResult $eligibilityResult - Result from FtaEligibilityService::checkEligibility()
     *
     * @return array{
     *   dutyRate: float|null,
     *   ftaAgreement: string|null,
     *   requiresDeclaration: bool,
     *   declarationTemplate: string|null,
     *   notFound: bool
     * }
     */
    public function applyFtaRate(
        string $htsCode,
        string $originCountry,
        string $destinationCountry,
        float $customsValue,
        array $eligibilityResult
    ): array {
        // 1. Get FTA agreement from eligibility result (absent = no agreement)
        $ftaAgreement = $eligibilityResult['fta_agreement'] ?? null;
        
        if (!$ftaAgreement) {
            return [
                'dutyRate' => null,
                'ftaAgreement' => null,
                'requiresDeclaration' => false,
                'declarationTemplate' => null,
                'notFound' => true,
            ];
        }
        
        // 2. Look up the tariff rate with FTA preferential rate
        // The tariff_rates table stores fta_rate alongside the standard MFN rate
        $today = new \DateTime('now', new \DateTimeZone('UTC'));
        
        /** @var TariffRate|null $tariffRate */
        $tariffRate = $this->tariffRateRepository->createQueryBuilder('tr')
            ->where('tr.hsCode = :hsCode')
            ->andWhere('tr.destinationCountry = :dest')
            ->andWhere('tr.originCountry = :origin')
            ->andWhere('tr.effectiveDate <= :today')
            ->andWhere('tr.expiryDate IS NULL OR tr.expiryDate >= :today')
            ->setParameter('hsCode', $htsCode)
            ->setParameter('dest', $destinationCountry)
            ->setParameter('origin', $originCountry)
            ->setParameter('today', $today)
            ->orderBy('tr.effectiveDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($tariffRate === null || $tariffRate->getFtaRate() === null) {
            // No FTA preferential rate found for this HTS code
            return [
                'dutyRate' => null,
                'ftaAgreement' => $ftaAgreement,
                'requiresDeclaration' => false,
                'declarationTemplate' => null,
                'notFound' => true,
            ];
        }
        
        $preferentialRate = (float) $tariffRate->getFtaRate();
        
        // 3. Check if declaration required
        $eligibilityStatus = $eligibilityResult['eligible'];
        $requiresDeclaration = in_array($eligibilityStatus, ['CONDITIONAL', 'ELIGIBLE']);
        
        $declarationTemplate = null;
        if ($requiresDeclaration) {
            try {
                $declarationTemplate = $this->ftaEligibilityService->generateDeclarationTemplate(
                    $htsCode,
                    $originCountry,
                    $destinationCountry,
                    $eligibilityResult
                );
            } catch (\Exception $e) {
                // If template generation fails, continue without it
                $declarationTemplate = null;
            }
        }
        
        // 4. Return FTA rate data
        return [
            'dutyRate' => $preferentialRate,
            'ftaAgreement' => $ftaAgreement,
            'requiresDeclaration' => $requiresDeclaration,
            'declarationTemplate' => $declarationTemplate,
            'notFound' => false,
        ];
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
        /** @var TariffRate|null $tariffRate */
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

        if ($tariffRate === null) {
            throw new \RuntimeException(
                "Tariff rate not found for HTS: $htsCode, Destination: $destinationCountry"
            );
        }
        
        // Use MFN rate if available, otherwise fallback to standard duty rate
        $dutyRate = (float) ($tariffRate->getMfnRate() ?? $tariffRate->getDutyRate());

        // TYPED duty model — the same semantics calculateDuty() implements:
        // ad_valorem / specific ($ per UOM) / compound, with an explicit
        // unresolved result for unknown types (never silently ad-valorem).
        $dutyType = strtolower((string) ($tariffRate->getDutyType() ?? 'ad_valorem'));
        $specificRate = $tariffRate->getSpecificRate();

        $specificAmount = 0.0;
        if ($dutyType === 'specific' || $dutyType === 'compound') {
            if ($specificRate === null || $quantity <= 0.0) {
                throw new \RuntimeException(sprintf(
                    'Tariff %s requires a specific rate and positive quantity/UOM (%s) for HTS %s.',
                    $dutyType,
                    $uom,
                    $htsCode
                ));
            }
            $specificAmount = $quantity * (float) $specificRate;
        }

        $adValoremAmount = $customsValue * ($dutyRate / 100);

        $dutyAmount = match ($dutyType) {
            'ad_valorem' => $adValoremAmount,
            'specific' => $specificAmount,
            'compound' => $adValoremAmount + $specificAmount,
            default => throw new \RuntimeException(sprintf('Unsupported duty type "%s" for HTS %s — unresolved rather than guessed.', $dutyType, $htsCode)),
        };

        return [
            'dutyRate' => $dutyRate,
            'dutyAmount' => round($dutyAmount, 2),
            'dutyType' => strtoupper($dutyType),
            'specificRate' => $specificRate !== null ? (float) $specificRate : null,
            'specificUom' => in_array($dutyType, ['specific', 'compound'], true) ? $uom : null
        ];
    }

    private const DEFAULT_VAT_RATES = [
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
     * 
     * @throws \RuntimeException when the destination country has no configured
     *         VAT rate — an unknown destination must never silently produce a
     *         0% VAT quote (understated landed cost in DDP).
     */
    public function calculateVat(
        float $customsValue,
        float $dutyAmount,
        string $destinationCountry
    ): array {
        $vatRate = self::DEFAULT_VAT_RATES[$destinationCountry] ?? null;
        
        if ($vatRate === null) {
            throw new \RuntimeException(
                "No VAT rate configured for destination country: {$destinationCountry}"
            );
        }
        
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
     *   mfnDuty?: float,
     *   ftaDuty?: float,
     *   savings?: float,
     *   savingsPercent?: float,
     *   eligible: bool,
     *   error?: string,
     *   conditional?: bool,
     *   reason?: string,
     *   potentialSavingsIfQualified?: null,
     *   ftaAgreement?: string|null,
     *   requiresDeclaration?: bool
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
        // 1. Calculate MFN duty (standard rate)
        try {
            $mfnResult = $this->applyMfnRate(
                $htsCode,
                $destinationCountry,
                $customsValue,
                $quantity,
                $uom
            );
            $mfnDuty = $mfnResult['dutyAmount'];
        } catch (\Exception $e) {
            // If MFN rate not found, cannot calculate savings
            return [
                'eligible' => false,
                'error' => 'MFN rate not found: ' . $e->getMessage()
            ];
        }
        
        // 2. Check FTA eligibility
        try {
            /** @var EligibilityResult $eligibility */
            $eligibility = $this->ftaEligibilityService->checkEligibility(
                $originCountry,
                $destinationCountry
            );
            
            $eligibleStatus = $eligibility['eligible'];

            if ($eligibleStatus !== 'ELIGIBLE') {
                // CONDITIONAL means missing evidence / unverified rules of
                // origin: it reports POTENTIAL savings for review but never
                // applies preferential duty to quoted landed cost.
                if ($eligibleStatus === 'CONDITIONAL') {
                    return [
                        'eligible' => false,
                        'conditional' => true,
                        // checkEligibility() carries no 'reason' key: the former `?? default` always resolved to the default.
                        'reason' => 'FTA eligibility conditional on unverified evidence',
                        'mfnDuty' => round($mfnDuty, 2),
                        'ftaDuty' => round($mfnDuty, 2),
                        'savings' => 0,
                        'savingsPercent' => 0,
                        'potentialSavingsIfQualified' => null, // computable only with a verified claim
                    ];
                }

                return [
                    'eligible' => false,
                    'reason' => 'Not eligible for FTA',
                    'mfnDuty' => round($mfnDuty, 2),
                    'ftaDuty' => round($mfnDuty, 2),
                    'savings' => 0,
                    'savingsPercent' => 0
                ];
            }
        } catch (\Exception $e) {
            // If eligibility check fails, assume not eligible
            return [
                'eligible' => false,
                'error' => 'Eligibility check failed: ' . $e->getMessage(),
                'mfnDuty' => round($mfnDuty, 2)
            ];
        }
        
        // 3. Calculate FTA duty (preferential rate)
        try {
            $ftaResult = $this->applyFtaRate(
                $htsCode,
                $originCountry,
                $destinationCountry,
                $customsValue,
                $eligibility
            );
            
            if ($ftaResult['notFound']) {
                // FTA rule not found
                return [
                    'eligible' => false,
                    'reason' => 'FTA rule not found',
                    'mfnDuty' => round($mfnDuty, 2),
                    'ftaDuty' => round($mfnDuty, 2),
                    'savings' => 0,
                    'savingsPercent' => 0
                ];
            }
            
            $ftaDutyRate = $ftaResult['dutyRate'] ?? 0.0;
            $ftaDuty = $customsValue * ($ftaDutyRate / 100);
            
        } catch (\Exception $e) {
            return [
                'eligible' => false,
                'error' => 'FTA rate calculation failed: ' . $e->getMessage(),
                'mfnDuty' => round($mfnDuty, 2)
            ];
        }
        
        // 4. Calculate savings
        $savings = $mfnDuty - $ftaDuty;
        $savingsPercent = $mfnDuty > 0 ? ($savings / $mfnDuty) * 100 : 0;
        
        // 5. Return savings data
        return [
            'mfnDuty' => round($mfnDuty, 2),
            'ftaDuty' => round($ftaDuty, 2),
            'savings' => round($savings, 2),
            'savingsPercent' => round($savingsPercent, 1),
            'eligible' => true,
            'ftaAgreement' => $ftaResult['ftaAgreement'] ?? null,
            'requiresDeclaration' => $ftaResult['requiresDeclaration']
        ];
    }

    /**
     * Build HTS fallback levels for hierarchical rate lookup.
     *
     * Strips non-numeric characters (dots, spaces), then tries:
     * 1. Exact code (10 digits)
     * 2. Heading level (first 6 digits)
     * 3. Chapter level (first 4 digits)
     * 4. Section level (first 2 digits)
     *
     * @return array<string, string> Level name => HTS code fragment
     */
    private function buildHtsFallbackLevels(string $htsCode): array
    {
        // Strip non-digit characters for clean hierarchical parsing
        $clean = preg_replace('/[^0-9]/', '', $htsCode);
        if ($clean === null) {
            return []; // null only on PCRE error
        }

        $levels = [];
        if (strlen($clean) >= 10) {
            $levels['exact_10'] = $clean;
        }
        if (strlen($clean) >= 6) {
            $levels['heading_6'] = substr($clean, 0, 6);
        }
        if (strlen($clean) >= 4) {
            $levels['chapter_4'] = substr($clean, 0, 4);
        }
        if (strlen($clean) >= 2) {
            $levels['section_2'] = substr($clean, 0, 2);
        }

        return $levels;
    }

    /**
     * Find a tariff rate for the given HTS code, destination, and origin.
     *
     * @return TariffRate|null The TariffRate entity, or null if not found
     */
    private function findTariffRate(string $hsCode, string $destinationCountry, string $originCountry): ?object
    {
        /** @var TariffRate|null $tariffRate */
        $tariffRate = $this->tariffRateRepository->createQueryBuilder('tr')
            ->where('tr.hsCode = :hsCode')
            ->andWhere('tr.destinationCountry = :dest')
            ->andWhere('tr.originCountry = :origin')
            ->andWhere('tr.effectiveDate <= :today')
            ->andWhere('tr.expiryDate IS NULL OR tr.expiryDate >= :today')
            ->setParameter('hsCode', $hsCode)
            ->setParameter('dest', $destinationCountry)
            ->setParameter('origin', $originCountry)
            ->setParameter('today', new \DateTime())
            ->orderBy('tr.effectiveDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $tariffRate;
    }
}
