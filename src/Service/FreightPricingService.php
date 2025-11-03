<?php

namespace App\Service;

use App\Repository\FreightTableRepository;
use App\Repository\RoutePreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;

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
 * - Insurance: typically 0.5% of CIF value
 * 
 * Used by:
 * - QuoteEstimatorController for landed-cost estimates
 * - RouteSelectionService for route comparison
 */
class FreightPricingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FreightTableRepository $freightTableRepository,
        private RoutePreferenceRepository $routePreferenceRepository,
        private RouteSelectionService $routeSelectionService
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
     *   breakdown: array
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
        // TODO: Implement freight calculation
        // 
        // Steps:
        // 1. Calculate chargeable weight (for AIR/LCL):
        //    $chargeableWeight = $this->getChargeableWeight($weightKg, $volumeM3, $mode);
        // 
        // 2. Query freight_table for rate:
        //    SELECT * FROM freight_table
        //    WHERE lane_code = :lane
        //    AND mode = :mode
        //    AND asof <= :today
        //    ORDER BY asof DESC LIMIT 1
        // 
        // 3. Calculate freight cost based on mode:
        //    - AIR: cost = chargeableWeight * rate_per_kg
        //    - LCL: cost = volumeM3 * rate_per_cbm
        //    - FCL: cost = flat_rate (20GP, 40GP, or 40HQ)
        // 
        // 4. Calculate insurance (0.5% of goods value):
        //    $insurance = $this->calculateInsurance($goodsValue);
        // 
        // 5. Return freight breakdown:
        //    return [
        //        'freightCost' => round($freightCost, 2),
        //        'chargeableWeight' => $chargeableWeight ? round($chargeableWeight, 2) : null,
        //        'volumetricWeight' => $volumetricWeight ? round($volumetricWeight, 2) : null,
        //        'ratePerUnit' => (float) $ratePerUnit,
        //        'insurance' => round($insurance, 2),
        //        'totalCost' => round($freightCost + $insurance, 2),
        //        'breakdown' => [
        //            'laneCode' => $laneCode,
        //            'mode' => $mode,
        //            'actualWeight' => $weightKg,
        //            'volume' => $volumeM3,
        //            'containerType' => $containerType,
        //            'goodsValue' => $goodsValue
        //        ]
        //    ];

        throw new \RuntimeException('Feature not yet implemented');
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
        // Fully implemented helper method
        
        // CIF value = Cost + Insurance + Freight
        // Insurance is typically 0.5% of (goodsValue + freightCost)
        $cifBase = $goodsValue + $freightCost;
        return $cifBase * 0.005; // 0.5%
    }

    /**
     * Get freight rate for a specific lane and mode
     * 
     * @param string $laneCode - Lane code
     * @param string $mode - Freight mode
     * @param \DateTime|null $asofDate - As-of date (defaults to today)
     * 
     * @return array{
     *   ratePerKg: float|null,
     *   ratePerCbm: float|null,
     *   flatRate: float|null,
     *   containerType: string|null,
     *   asof: \DateTime
     * }
     */
    public function getFreightRate(
        string $laneCode,
        string $mode,
        ?\DateTime $asofDate = null
    ): array {
        // TODO: Implement freight rate lookup
        // 
        // Steps:
        // 1. Default asofDate to today if not provided:
        //    $asofDate = $asofDate ?? new \DateTime();
        // 
        // 2. Query freight_table for rate:
        //    $qb = $this->freightTableRepository->createQueryBuilder('ft');
        //    $freightRate = $qb
        //        ->where('ft.laneCode = :lane')
        //        ->andWhere('ft.mode = :mode')
        //        ->andWhere('ft.asof <= :asof')
        //        ->setParameter('lane', $laneCode)
        //        ->setParameter('mode', $mode)
        //        ->setParameter('asof', $asofDate)
        //        ->orderBy('ft.asof', 'DESC')
        //        ->setMaxResults(1)
        //        ->getQuery()
        //        ->getOneOrNullResult();
        // 
        // 3. Return rate data:
        //    if (!$freightRate) {
        //        throw new \RuntimeException("No freight rate found for lane $laneCode, mode $mode");
        //    }
        // 
        //    return [
        //        'ratePerKg' => $freightRate->getRatePerKg(),
        //        'ratePerCbm' => $freightRate->getRatePerCbm(),
        //        'flatRate' => $freightRate->getFlatRate(),
        //        'containerType' => $freightRate->getContainerType(),
        //        'asof' => $freightRate->getAsof()
        //    ];

        throw new \RuntimeException('Feature not yet implemented');
    }

    /**
     * Compare freight costs across multiple routes
     * 
     * @param string $destinationCountry - Destination country code
     * @param float $weightKg - Shipment weight
     * @param float $volumeM3 - Shipment volume
     * @param float $goodsValue - Goods value
     * 
     * @return array - Array of route comparisons sorted by total cost
     */
    public function compareRoutePricing(
        string $destinationCountry,
        float $weightKg,
        float $volumeM3,
        float $goodsValue
    ): array {
        // TODO: Implement route comparison
        // 
        // Steps:
        // 1. Get all ranked routes for destination:
        //    $routes = $this->routeSelectionService->rankRoutes($destinationCountry);
        // 
        // 2. Evaluate optimal mode for weight/volume:
        //    $mode = $this->routeSelectionService->evaluateModeByWeight($weightKg, $volumeM3);
        // 
        // 3. Calculate freight cost for each route:
        //    $comparisons = [];
        //    foreach ($routes as $route) {
        //        $laneCode = $route->getLaneCode();
        //        $cost = $this->calculateFreight($laneCode, $mode, $weightKg, $volumeM3, $goodsValue);
        //        $comparisons[] = [
        //            'laneCode' => $laneCode,
        //            'mode' => $mode,
        //            'cost' => $cost,
        //            'rank' => $route->getRank()
        //        ];
        //    }
        // 
        // 4. Sort by total cost:
        //    usort($comparisons, fn($a, $b) => $a['cost']['totalCost'] <=> $b['cost']['totalCost']);
        // 
        // 5. Return sorted comparisons:
        //    return $comparisons;

        throw new \RuntimeException('Feature not yet implemented');
    }

    /**
     * Estimate freight cost for quick quote (uses default route)
     * 
     * @param string $destinationCountry - Destination country code
     * @param float $weightKg - Shipment weight
     * @param float $volumeM3 - Shipment volume
     * 
     * @return array - Simplified freight estimate
     */
    public function quickEstimate(
        string $destinationCountry,
        float $weightKg,
        float $volumeM3
    ): array {
        // TODO: Implement quick estimate
        // 
        // Steps:
        // 1. Select optimal route:
        //    $route = $this->routeSelectionService->selectOptimalRoute($destinationCountry, null, $weightKg, $volumeM3);
        // 
        // 2. Determine mode:
        //    $mode = $this->routeSelectionService->evaluateModeByWeight($weightKg, $volumeM3);
        // 
        // 3. Calculate freight (assume $10k goods value for insurance):
        //    $cost = $this->calculateFreight($route['laneCode'], $mode, $weightKg, $volumeM3, 10000);
        // 
        // 4. Return simplified estimate:
        //    return [
        //        'estimatedFreight' => $cost['freightCost'],
        //        'estimatedInsurance' => $cost['insurance'],
        //        'mode' => $mode,
        //        'laneCode' => $route['laneCode']
        //    ];

        throw new \RuntimeException('Feature not yet implemented');
    }
}
