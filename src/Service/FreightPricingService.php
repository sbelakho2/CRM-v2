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
        $laneParts = explode('-', $laneCode, 2);
        if (count($laneParts) !== 2) {
            throw new \InvalidArgumentException("Invalid lane code format: $laneCode (expected ORIGIN-DESTINATION)");
        }
        
        [$originPort, $destinationPort] = $laneParts;
        
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
        $asofDate = $asofDate ?? new \DateTime();
        
        // Parse lane code to origin/destination
        $laneParts = explode('-', $laneCode, 2);
        if (count($laneParts) !== 2) {
            throw new \InvalidArgumentException("Invalid lane code format: $laneCode");
        }
        
        [$originPort, $destinationPort] = $laneParts;
        
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
                $laneCode = $route->getLaneCode();
                $cost = $this->calculateFreight($laneCode, $mode, $weightKg, $volumeM3, $goodsValue);
                
                $comparisons[] = [
                    'laneCode' => $laneCode,
                    'routeCode' => $route->getRouteCode(),
                    'mode' => $mode,
                    'rank' => $route->getRank(),
                    'freight_cost' => $cost['freightCost'],
                    'insurance' => $cost['insurance'],
                    'total_cost' => $cost['totalCost'],
                    'transit_days' => $route->getTransitDays()
                ];
            } catch (\Exception $e) {
                // Skip routes without pricing
                continue;
            }
        }
        
        // 4. Sort by total cost
        usort($comparisons, fn($a, $b) => $a['total_cost'] <=> $b['total_cost']);
        
        return $comparisons;
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
            $laneCode = $route['origin_port'] . '-' . $route['destination_port'];
            $cost = $this->calculateFreight($laneCode, $mode, $weightKg, $volumeM3, 10000);
            
            // 4. Return simplified estimate
            return [
                'estimatedFreight' => $cost['freightCost'],
                'estimatedInsurance' => $cost['insurance'],
                'totalEstimate' => $cost['totalCost'],
                'mode' => $mode,
                'routeCode' => $route['route_code'],
                'transitDays' => $route['transit_days'],
                'currency' => $route['currency']
            ];
        } catch (\Exception $e) {
            // Fallback to generic estimate if no route found
            $mode = $this->routeSelectionService->evaluateModeByWeight($weightKg, $volumeM3);
            
            // Generic rates per mode
            $genericRates = [
                'AIR' => 5.0,  // $5/kg
                'LCL' => 50.0, // $50/cbm
                'FCL' => 2000.0 // $2000/container
            ];
            
            $freight = match($mode) {
                'AIR' => $weightKg * $genericRates['AIR'],
                'LCL' => $volumeM3 * $genericRates['LCL'],
                'FCL' => $genericRates['FCL'],
                default => 0
            };
            
            $insurance = 10000 * 0.003; // 0.3% of $10k
            
            return [
                'estimatedFreight' => round($freight, 2),
                'estimatedInsurance' => round($insurance, 2),
                'totalEstimate' => round($freight + $insurance, 2),
                'mode' => $mode,
                'routeCode' => 'GENERIC',
                'transitDays' => 14,
                'currency' => 'USD',
                'note' => 'Generic estimate - no specific route found'
            ];
        }
    }
}
