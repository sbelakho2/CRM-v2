<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\FreightTableRepository;
use App\Repository\RoutePreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * FreightPricingService
 * 
 * Calculates international freight costs for air, LCL, and FCL shipments.
 * 
 * Key concepts:
 * - Chargeable weight: max(actual weight, volumetric weight)
 * - Volumetric weight: (L x W x H cm³) / 5000 for air, / 6000 for sea
 * - Air freight: $/kg based on chargeable weight
 * - LCL (Less than Container Load): $/cbm (cubic meter)
 * - FCL (Full Container Load): flat rate per 20'/40' container
 * - Insurance: 0.5% of CIF value (single rate used everywhere)
 * 
 * Used by:
 * - QuoteCoPilotController for freight cost calculations
 * - RouteSelectionService for route comparison
 */
class FreightPricingService
{
    private const GENERIC_RATE_AIR_PER_KG = 5.0;
    private const GENERIC_RATE_LCL_PER_CBM = 50.0;
    private const GENERIC_RATE_FCL_PER_CONTAINER = 2000.0;

    /**
     * Insurance rate applied to the CIF base (goods value + freight).
     * Single source of truth for calculateInsurance() and quickEstimate().
     */
    private const INSURANCE_RATE = 0.005; // 0.5%

    public function __construct(

        /**
         * Not read yet; kept for future persistence of computed rates.
         */
        protected EntityManagerInterface $entityManager,
        private FreightTableRepository $freightTableRepository,

        /**
         * Not read yet; kept for future route-preference lookups.
         */
        protected RoutePreferenceRepository $routePreferenceRepository,
        private RouteSelectionService $routeSelectionService,
        private CurrencyPreferenceService $currencyPreferenceService,
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * Calculate freight cost for a shipment
     * 
     * @param string $laneCode - Lane code (e.g., "SHA-JFK-NYC", "SZX-LAX")
     * @param string $mode - Freight mode (AIR, LCL, FCL)
     * @param float $weightKg - Actual weight in kilograms
     * @param float $volumeM3 - Volume in cubic meters
     * @param float $goodsValue - Value of goods (for insurance calculation)
     * @param string|null $containerType - Container type for FCL (20GP, 40GP, 40HQ)
     * 
     * @return array{
     *   freightCost: float,
     *   chargeableWeight: float|null,
     *   volumetricWeight: float|null,
     *   ratePerUnit: float,
     *   insurance: float,
     *   totalCost: float,
     *   transitDays: int|null,
     *   carrier: string|null,
     *   breakdown: array<string, mixed>
     * }
     */
    public function calculateFreight(
        string $laneCode,
        string $mode,
        float $weightKg,
        float $volumeM3,
        float $goodsValue,
        ?string $containerType = null
    ): array {
        // Step 1: Calculate chargeable weight
        $chargeableWeight = $this->getChargeableWeight($weightKg, $volumeM3, $mode);
        $volumetricWeight = null;
        
        if ($mode === 'AIR') {
            $volumetricWeight = $volumeM3 * 167;
        } elseif ($mode === 'LCL') {
            $volumetricWeight = $weightKg / 1000; // Weight-based volume
        }
        
        // Step 2: Query freight_table for rate - need to parse lane_code to origin/destination
        // Lane code format: "ORIGIN-DESTINATION" (e.g., "Tangier-Rotterdam")
        // Some codes have multi-hyphen destinations like "MA-US-AIR-001", so we split
        // fully and take first segment as origin, last as destination.
        $laneParts = explode('-', $laneCode);
        if (count($laneParts) < 2) {
            throw new \InvalidArgumentException("Invalid lane code format: $laneCode (expected ORIGIN-DESTINATION)");
        }
        
        $originPort = $laneParts[0];
        $destinationPort = implode('-', array_slice($laneParts, 1));
        
        /** @var \App\Entity\FreightTable|null $freightRate */
        $freightRate = $this->freightTableRepository->createQueryBuilder('ft')
            ->where('ft.originPort = :origin')
            ->andWhere('ft.destinationPort = :dest')
            ->andWhere('ft.transportMode = :mode')
            ->andWhere('ft.effectiveDate <= :today')
            ->andWhere('ft.expiryDate IS NULL OR ft.expiryDate >= :today')
            ->setParameter('origin', $originPort)
            ->setParameter('dest', $destinationPort)
            ->setParameter('mode', $mode === 'AIR' ? 'Air' : ($mode === 'FCL' || $mode === 'LCL' ? 'Ocean' : $mode))
            ->setParameter('today', new \DateTime())
            ->orderBy('ft.effectiveDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        if (!$freightRate) {
            throw new \RuntimeException(
                "No freight rate found for route $laneCode, mode $mode"
            );
        }
        
        // Step 3: Calculate freight cost based on mode
        $freightCost = 0.0;
        $ratePerUnit = 0.0;
        
        if ($mode === 'AIR') {
            // Air freight charged by chargeable weight (kg)
            $costPerKg = (float) $freightRate->getCostPerUnit();
            $freightCost = $chargeableWeight * $costPerKg;
            $ratePerUnit = $costPerKg;
            
        } elseif ($mode === 'LCL') {
            // LCL charged by volume (m³)
            $costPerCbm = (float) $freightRate->getCostPerUnit();
            $freightCost = $chargeableWeight * $costPerCbm; // chargeableWeight is in m³ for LCL
            $ratePerUnit = $costPerCbm;
            
        } elseif ($mode === 'FCL') {
            // FCL flat rate per container
            $freightCost = (float) $freightRate->getCostPerUnit();
            $ratePerUnit = $freightCost;
        }
        
        // Step 4: Calculate insurance
        $insurance = $this->calculateInsurance($goodsValue, $freightCost);
        
        return [
            'freightCost' => round($freightCost, 2),
            'chargeableWeight' => $chargeableWeight ? round($chargeableWeight, 2) : null,
            'volumetricWeight' => $volumetricWeight ? round($volumetricWeight, 2) : null,
            'ratePerUnit' => round($ratePerUnit, 2),
            'insurance' => round($insurance, 2),
            'totalCost' => round($freightCost + $insurance, 2),
            'transitDays' => $freightRate->getTransitDays(),
            'carrier' => $freightRate->getCarrier(),
            'breakdown' => [
                'laneCode' => $laneCode,
                'mode' => $mode,
                'actualWeight' => $weightKg,
                'volume' => $volumeM3,
                'containerType' => $containerType ?? $freightRate->getContainerType(),
                'goodsValue' => $goodsValue
            ]
        ];
    }

    /**
     * Calculate chargeable weight (max of actual and volumetric)
     * 
     * @param float $weightKg - Actual weight in kg
     * @param float $volumeM3 - Volume in cubic meters
     * @param string $mode - Freight mode (AIR, LCL, FCL)
     * 
     * @return float - Chargeable weight in kg (for AIR) or m³ (for LCL)
     */
    public function getChargeableWeight(float $weightKg, float $volumeM3, string $mode): float
    {
        // Fully implemented helper method
        
        if ($mode === 'FCL') {
            // FCL doesn't use chargeable weight (flat container rate)
            return 0.0;
        }

        if ($mode === 'AIR') {
            // Air volumetric weight: volume (m³) * 167 kg/m³
            // (Derived from 1 m³ = 1,000,000 cm³ / 6000 cm³/kg)
            $volumetricWeight = $volumeM3 * 167;
            return max($weightKg, $volumetricWeight);
        }

        if ($mode === 'LCL') {
            // LCL uses volume (m³) directly, but compare with weight-based volume
            // Assume 1 ton = 1 m³ (shipping standard)
            $weightBasedVolume = $weightKg / 1000; // Convert kg to tons (m³ equivalent)
            return max($volumeM3, $weightBasedVolume);
        }

        throw new \InvalidArgumentException("Invalid mode: $mode (must be AIR, LCL, or FCL)");
    }

    /**
     * Calculate insurance cost (0.5% of CIF value)
     * 
     * @param float $goodsValue - Value of goods
     * @param float $freightCost - Freight cost (optional, for CIF calculation)
     * 
     * @return float - Insurance cost
     */
    public function calculateInsurance(float $goodsValue, float $freightCost = 0.0): float
    {
        // CIF value = Cost + Insurance + Freight
        // Insurance is 0.5% of (goodsValue + freightCost) — the same rate the
        // quickEstimate() fallback uses (single INSURANCE_RATE constant).
        $cifBase = $goodsValue + $freightCost;
        return $cifBase * self::INSURANCE_RATE;
    }

    /**
     * Get freight rate for a specific lane and mode
     * 
     * @param string $laneCode - Lane code
     * @param string $mode - Freight mode
     * @param \DateTime|null $asofDate - As-of date (defaults to today)
     * 
     * @return array{
     *   costPerUnit: float,
     *   currency: string|null,
     *   containerType: string|null,
     *   transitDays: int|null,
     *   carrier: string|null,
     *   effectiveDate: \DateTimeInterface|null
     * }
     */
    public function getFreightRate(
        string $laneCode,
        string $mode,
        ?\DateTime $asofDate = null
    ): array {
        $asofDate = $asofDate ?? new \DateTime();
        
        // Parse lane code to origin/destination
        $laneParts = explode('-', $laneCode, 2);
        if (count($laneParts) !== 2) {
            throw new \InvalidArgumentException("Invalid lane code format: $laneCode");
        }
        
        [$originPort, $destinationPort] = $laneParts;
        
        /** @var \App\Entity\FreightTable|null $freightRate */
        $freightRate = $this->freightTableRepository->createQueryBuilder('ft')
            ->where('ft.originPort = :origin')
            ->andWhere('ft.destinationPort = :dest')
            ->andWhere('ft.transportMode = :mode')
            ->andWhere('ft.effectiveDate <= :asof')
            ->setParameter('origin', $originPort)
            ->setParameter('dest', $destinationPort)
            ->setParameter('mode', $mode)
            ->setParameter('asof', $asofDate)
            ->orderBy('ft.effectiveDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        if (!$freightRate) {
            throw new \RuntimeException("No freight rate found for lane $laneCode, mode $mode");
        }
        
        return [
            'costPerUnit' => (float) $freightRate->getCostPerUnit(),
            'currency' => $freightRate->getCurrency(),
            'containerType' => $freightRate->getContainerType(),
            'transitDays' => $freightRate->getTransitDays(),
            'carrier' => $freightRate->getCarrier(),
            'effectiveDate' => $freightRate->getEffectiveDate()
        ];
    }

    /**
     * Compare freight costs across multiple routes
     * 
     * @param string $destinationCountry - Destination country code
     * @param float $weightKg - Shipment weight
     * @param float $volumeM3 - Shipment volume
     * @param float $goodsValue - Goods value
     * 
     * @return list<array{laneCode: string, mode: string, rank: int|null, freight_cost: float, insurance: float, total_cost: float}> Array of route comparisons sorted by total cost
     */
    public function compareRoutePricing(
        string $destinationCountry,
        float $weightKg,
        float $volumeM3,
        float $goodsValue
    ): array {

        // 1. Get all ranked routes
        $routes = $this->routeSelectionService->rankRoutes($destinationCountry);
        
        if (empty($routes)) {
            return [];
        }
        
        // 2. Evaluate optimal mode
        $mode = $this->routeSelectionService->evaluateModeByWeight($weightKg, $volumeM3);
        
        // 3. Calculate freight cost for each route
        $comparisons = [];
        
        foreach ($routes as $route) {
            try {
                $laneCode = (string) $route->getLaneCode();
                if ($laneCode === '') {
                    throw new \RuntimeException('Route preference has an empty lane code');
                }
                $cost = $this->calculateFreight($laneCode, $mode, $weightKg, $volumeM3, $goodsValue);
                
                $comparisons[] = [
                    'laneCode' => $laneCode,
                    'mode' => $mode,
                    'rank' => $route->getRank(),
                    'freight_cost' => $cost['freightCost'],
                    'insurance' => $cost['insurance'],
                    'total_cost' => $cost['totalCost'],
                ];
            } catch (\Throwable $e) {
                // Skip routes without pricing — but log each skip so silent
                // route dropouts are visible in the logs.
                $this->logger?->warning('Route skipped in freight comparison (no pricing)', [
                    'lane_code' => $route->getLaneCode(),
                    'mode' => $mode,
                    'destination' => $destinationCountry,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // 4. Sort by total cost
        usort($comparisons, fn(array $a, array $b) => $a['total_cost'] <=> $b['total_cost']);
        
        return $comparisons;
    }

    /**
     * Estimate freight cost for quick quote (uses default route)
     * 
     * @param string $destinationCountry - Destination country code
     * @param float $weightKg - Shipment weight
     * @param float $volumeM3 - Shipment volume
     * 
     * @return array{estimatedFreight: float, estimatedInsurance: float, totalEstimate: float, mode: string, routeCode: string, transitDays: int|string|null, currency: string|null, note?: string} Simplified freight estimate
     */
    public function quickEstimate(
        string $destinationCountry,
        float $weightKg,
        float $volumeM3
    ): array {

        try {
            // 1. Select optimal route
            $route = $this->routeSelectionService->selectOptimalRoute(
                $destinationCountry,
                $weightKg,
                $volumeM3
            );
            
            // 2. Mode is already determined by selectOptimalRoute
            $mode = $route['mode'];
            
            // 3. Calculate freight (assume $10k goods value)
            $laneCode = ($route['origin_port'] ?? '') . '-' . ($route['destination_port'] ?? '');
            $cost = $this->calculateFreight($laneCode, $mode, $weightKg, $volumeM3, 10000);
            
            // 4. Return simplified estimate
            return [
                'estimatedFreight' => $cost['freightCost'],
                'estimatedInsurance' => $cost['insurance'],
                'totalEstimate' => $cost['totalCost'],
                'mode' => $mode,
                'routeCode' => (string) ($route['route_code'] ?? ''),
                'transitDays' => $route['transit_days'],
                'currency' => $route['currency'] ?? $this->currencyPreferenceService->getDisplayCurrency(),
            ];
        } catch (\Exception $e) {
            // Fallback to generic estimate if no route found
            $mode = $this->routeSelectionService->evaluateModeByWeight($weightKg, $volumeM3);
            
            $freight = match($mode) {
                'AIR' => $weightKg * self::GENERIC_RATE_AIR_PER_KG,
                'LCL' => $volumeM3 * self::GENERIC_RATE_LCL_PER_CBM,
                'FCL' => self::GENERIC_RATE_FCL_PER_CONTAINER,
                default => 0
            };
            
            $insurance = 10000 * self::INSURANCE_RATE; // 0.5% of $10k — same rate as calculateInsurance()
            
            return [
                'estimatedFreight' => round($freight, 2),
                'estimatedInsurance' => round($insurance, 2),
                'totalEstimate' => round($freight + $insurance, 2),
                'mode' => $mode,
                'routeCode' => 'GENERIC',
                'transitDays' => 14,
                'currency' => $this->currencyPreferenceService->getDisplayCurrency(),
                'note' => 'Generic estimate - no specific route found'
            ];
        }
    }
}
