<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RoutePreference;
use App\Entity\FreightTable;
use App\Repository\RoutePreferenceRepository;
use App\Repository\FreightTableRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * RouteSelectionService
 * 
 * Selects optimal shipping routes and freight modes based on:
 * - Destination country
 * - Weight and volume
 * - Route preferences (ranked by priority)
 * - Freight mode thresholds (AIR/LCL/FCL)
 * 
 * Mode Selection Logic:
 * - AIR: < 50 kg
 * - LCL (Less than Container Load): 50-15,000 kg
 * - FCL (Full Container Load): > 15,000 kg
 * 
 * Used by: Landed-Cost Estimator, Quote Co-Pilot
 */
class RouteSelectionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RoutePreferenceRepository $routePreferenceRepository,
        private FreightTableRepository $freightTableRepository,
        private CurrencyPreferenceService $currencyPreferenceService,
        private \App\Service\DutyCalculationService $dutyCalculationService,
    ) {}

    /**
     * Select optimal route for shipment
     * 
     * @param string $destinationCountry Destination country code (e.g., 'US', 'FR', 'MA')
     * @param float $weightKg Total weight in kilograms
     * @param float $volumeM3 Total volume in cubic meters
     * @param string|null $origin Origin country code (default: 'MA' for Morocco)
    * @return array Route details: ['route_code' => 'MA-US-AIR-001', 'mode' => 'AIR', 'origin_port' => 'CMN', 'destination_port' => 'JFK', 'transit_days' => 5, 'freight_cost' => 1250.00, 'currency' => 'EUR']
     * 
     * Implementation:
     * 1. Get ranked routes from route_preferences table:
     *    → WHERE destination_country = :destinationCountry AND is_active = true
     *    → ORDER BY rank ASC
     * 2. Determine freight mode based on weight/volume:
     *    → Call evaluateModeByWeight(weightKg, volumeM3)
     * 3. For each route (in rank order):
     *    → Check if mode matches route.preferred_mode (or route allows any mode)
     *    → Check weight/volume thresholds (route.min_weight_kg, route.max_weight_kg)
     *    → Query freight_tables for cost data
     *    → If found, return route details
     * 4. If no matching route, fall back to default route or throw exception
     */
    public function selectOptimalRoute(
        string $destinationCountry,
        float $weightKg,
        float $volumeM3,
        ?string $origin = 'MA',
        ?string $htsCode = null,
        ?float $goodsValue = null,
        ?string $uom = 'KG',
        ?float $quantity = null
    ): array {
        // 1. Get ranked routes from route_preferences
        $routes = $this->routePreferenceRepository->createQueryBuilder('r')
            ->where('r.destinationCountry = :dest')

            ->andWhere('r.isActive = true')
            ->setParameter('dest', $destinationCountry)

            ->orderBy('r.rank', 'ASC')
            ->getQuery()
            ->getResult();
        
        if (empty($routes)) {
            throw new \RuntimeException("No active routes found to {$destinationCountry}");
        }

        // GLOBAL landed-cost optimum: every active route for the lane is
        // evaluated (freight + duty) and the minimum chosen — not merely
        // the first feasible route in rank order. Rank order is retained
        // only as a tiebreaker among equal landed costs.
        
        // 2. Determine freight mode
        $recommendedMode = $this->evaluateModeByWeight($weightKg, $volumeM3);
        $unresolvedCandidates = [];

        // 3. Evaluate EVERY feasible candidate on total landed cost
        //    (freight + duty); choose the global minimum. Rank breaks ties.
        $best = null;
        foreach ($routes as $route) {
            $preferredMode = $route->getMode();
            if ($preferredMode && $preferredMode !== $recommendedMode && $preferredMode !== 'ANY') {
                continue;
            }
            if (!$this->validateRoute($route, $weightKg, $volumeM3)) {
                continue;
            }

            try {
                $freightCost = $this->getFreightCost(
                    $route->getLaneCode(),
                    $recommendedMode,
                    $weightKg,
                    $volumeM3
                );
            } catch (\Exception $e) {
                continue; // no pricing for this candidate
            }

            // Landed cost requires the REAL product HTS, goods value (CIF
            // basis) and quantity/UOM. Freight-cost-as-customs-value and a
            // hard-coded HTS made "duty unavailable" mathematically equal to
            // zero duty — silently understating landed costs. Without the
            // inputs, the candidate carries duty_basis=unresolved and is
            // EXCLUDED from landed-cost ranking (reported, never ranked as
            // if duty were free).
            $dutyAmount = 0.0;
            $dutyBasis = 'unresolved';
            $dutyInputsKnown = $htsCode !== null
                && $goodsValue !== null
                && $goodsValue > 0.0
                && $quantity !== null
                && $quantity > 0.0;

            if ($dutyInputsKnown) {
                try {
                    $duty = $this->dutyCalculationService->calculateDuty(
                        $htsCode,
                        $goodsValue,
                        $quantity,
                        $uom ?? 'KG',
                        $destinationCountry,
                        $origin
                    );
                    if (isset($duty['dutyAmount'])) {
                        $dutyAmount = (float) $duty['dutyAmount'];
                        $dutyBasis = $duty['method'] ?? 'MFN';
                    }
                } catch (\Throwable) {
                    // tariff data unavailable: stays unresolved
                }
            }

            $laneDetails = $this->parseLaneCode($route->getLaneCode());
            $landed = (float) $freightCost['cost'] + $dutyAmount;

            // Unresolved mandatory landed-cost components exclude the
            // candidate from the comparison (it stays in the report).
            if ($dutyBasis === 'unresolved') {
                $unresolvedCandidates[] = [
                    'route_code' => $route->getLaneCode(),
                    'mode' => $recommendedMode,
                    'origin_port' => $laneDetails['origin_port'],
                    'destination_port' => $laneDetails['destination_port'],
                    'transit_days' => 7,
                    'freight_cost' => $freightCost['cost'],
                    'duty_basis' => 'unresolved',
                    'total_landed_cost' => null,
                    'currency' => $freightCost['currency'] ?? 'USD',
                    'rank' => $route->getRank(),
                ];
                continue;
            }

            $candidate = [
                'route_code' => $route->getLaneCode(),
                'mode' => $recommendedMode,
                'origin_port' => $laneDetails['origin_port'],
                'destination_port' => $laneDetails['destination_port'],
                'transit_days' => 7 /* transit time lives in freight_tables, not the preference */ ?? 7,
                'freight_cost' => $freightCost['cost'],
                'duty_amount' => $dutyAmount,
                'duty_basis' => $dutyBasis,
                'total_landed_cost' => round($landed, 2),
                'currency' => $freightCost['currency'] ?? 'USD',
                'rank' => $route->getRank(),
            ];

            if (
                $best === null
                || $candidate['total_landed_cost'] < $best['total_landed_cost']
                || (
                    $candidate['total_landed_cost'] === $best['total_landed_cost']
                    && ($candidate['rank'] ?? PHP_INT_MAX) < ($best['rank'] ?? PHP_INT_MAX)
                )
            ) {
                $best = $candidate;
            }
        }

        if ($best === null) {
            if ($unresolvedCandidates !== []) {
                throw new \RuntimeException(sprintf(
                    'Routes exist to %s but duty inputs (HTS, goods value, quantity) are missing — landed cost cannot be ranked. Provide htsCode/goodsValue/quantity; candidates: %s',
                    $destinationCountry,
                    implode(', ', array_column($unresolvedCandidates, 'route_code'))
                ));
            }

            throw new \RuntimeException("No feasible, priced route found to {$destinationCountry}");
        }

        return $best + ['duty_unresolved_candidates' => $unresolvedCandidates];
    }

    /**
     * Evaluate freight mode based on weight and volume
     * 
     * @param float $weightKg Total weight in kilograms
     * @param float $volumeM3 Total volume in cubic meters
     * @return string Freight mode (AIR|LCL|FCL)
     * 
     * TODO Implementation:
     * 1. Calculate chargeable weight (max of actual weight and volumetric weight)
     *    → Volumetric weight = volumeM3 * 167 (for air) or volumeM3 * 1000 (for sea)
     * 2. Apply mode thresholds:
     *    → If chargeable weight < 50 kg: return 'AIR'
     *    → If chargeable weight 50-15,000 kg: return 'LCL'
     *    → If chargeable weight > 15,000 kg: return 'FCL'
     * 3. Consider volume constraints:
     *    → If volumeM3 > 67 m³ (full 40' container): force 'FCL'
     *    → If volumeM3 > 33 m³ (full 20' container): consider 'FCL'
     */
    public function evaluateModeByWeight(float $weightKg, float $volumeM3): string
    {
        // Calculate volumetric weight (air freight standard: 167 kg/m³)
        $volumetricWeightKg = $volumeM3 * 167;
        
        // Chargeable weight is the greater of actual or volumetric
        $chargeableWeight = max($weightKg, $volumetricWeightKg);
        
        // Apply thresholds
        if ($chargeableWeight < 50) {
            return 'AIR';
        } elseif ($chargeableWeight <= 15000) {
            // Check if volume fits in container
            if ($volumeM3 > 67) {
                return 'FCL'; // Exceeds 40' container, must use FCL
            }
            return 'LCL';
        } else {
            return 'FCL';
        }
    }

    /**
     * Get all available routes for destination (ranked)
     * 
     * @param string $destinationCountry Destination country code
     * @param string|null $origin Origin country code
     * @return array Array of RoutePreference entities (ranked)
     * 
     * TODO Implementation:
     * 1. Query route_preferences table
     * 2. Filter by destination_country and origin (if provided)
     * 3. Filter by is_active = true
     * 4. Order by rank ASC
     * 5. Return array of RoutePreference entities
     */
    public function rankRoutes(string $destinationCountry, ?string $origin = 'MA'): array
    {
        // originCountry is NOT a mapped property on RoutePreference —
        // origin lives in the lane code (originPort). The $origin parameter
        // is retained for API compatibility; lane-level filtering happens
        // via the port prefix when callers need it.
        return $this->routePreferenceRepository->createQueryBuilder('r')
            ->where('r.destinationCountry = :dest')
            ->andWhere('r.isActive = true')
            ->setParameter('dest', $destinationCountry)
            ->orderBy('r.rank', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get freight cost for specific route and mode
     * 
     * @param string $routeCode Route code (e.g., 'MA-US-AIR-001')
     * @param string $mode Freight mode (AIR|LCL|FCL)
     * @param float $weightKg Chargeable weight in kg
     * @param float $volumeM3 Volume in cubic meters
    * @return array Freight details: ['cost' => 1250.00, 'currency' => 'EUR', 'transit_days' => 5, 'surcharges' => [...]]
     * 
     * TODO Implementation:
     * 1. Query freight_tables table:
     *    → WHERE route_code = :routeCode AND mode = :mode AND is_active = true
     *    → ORDER BY effective_date DESC LIMIT 1 (get latest rate)
     * 2. Apply rate structure:
     *    → If AIR: cost = base_rate + (weightKg * per_kg_rate)
     *    → If LCL: cost = base_rate + (volumeM3 * per_cbm_rate)
     *    → If FCL: cost = flat container rate (20GP/40GP/40HQ)
     * 3. Add surcharges (fuel, security, handling, etc.)
     * 4. Return cost breakdown
     */
    public function getFreightCost(string $routeCode, string $mode, float $weightKg, float $volumeM3): array
    {
        // 1. Query freight_tables for latest rate
        $freightRate = $this->freightTableRepository->createQueryBuilder('f')
            ->where('f.routeCode = :routeCode')
            ->andWhere('f.mode = :mode')
            ->andWhere('f.isActive = true')
            ->setParameter('routeCode', $routeCode)
            ->setParameter('mode', $mode)
            ->orderBy('f.effectiveDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        if (!$freightRate) {
            throw new \RuntimeException("No freight rate found for route {$routeCode} mode {$mode}");
        }
        
        // 2. Calculate cost based on mode
        $baseCost = $freightRate->getBaseRate() ?? 0;
        $cost = $baseCost;
        
        switch ($mode) {
            case 'AIR':
                $perKgRate = $freightRate->getPerKgRate() ?? 0;
                $cost += $weightKg * $perKgRate;
                break;
                
            case 'LCL':
                $perCbmRate = $freightRate->getPerCbmRate() ?? 0;
                $cost += $volumeM3 * $perCbmRate;
                break;
                
            case 'FCL':
                // FCL uses container flat rate
                $containerType = $volumeM3 > 67 ? '40HQ' : ($volumeM3 > 33 ? '40GP' : '20GP');
                $cost = $freightRate->getContainerRate() ?? $baseCost;
                break;
        }
        
        // 3. Add surcharges
        $surcharges = [];
        
        if ($fuelSurcharge = $freightRate->getFuelSurcharge()) {
            $surcharges['fuel'] = $cost * ($fuelSurcharge / 100);
        }
        
        if ($securitySurcharge = $freightRate->getSecuritySurcharge()) {
            $surcharges['security'] = $securitySurcharge;
        }
        
        $totalSurcharges = array_sum($surcharges);
        $totalCost = $cost + $totalSurcharges;
        
        // 4. Return cost breakdown
        return [
            'cost' => round($totalCost, 2),
            'base_cost' => round($cost, 2),
            'currency' => $freightRate->getCurrency() ?? $this->currencyPreferenceService->getDisplayCurrency(),
            'transit_days' => $freightRate->getTransitDays() ?? 7,
            'surcharges' => $surcharges
        ];
    }

    /** @var array{0: string, 1: float, 2: float, 3: string}|null */
    private ?array $compareDutyInputs = null;

    /**
     * Compare candidate routes on TOTAL LANDED COST (freight + duty).
     *
     * @param array{0: string, 1: float, 2: float, 3: string}|null $dutyInputs
     *        [htsCode, goodsValue, quantity, uom] — the real product inputs.
     *        Without them, candidates report duty_basis=unresolved and
     *        total_landed_cost=null and sort AFTER every ranked candidate —
     *        "duty unknown" is never equivalent to zero duty.
     */
    public function compareRoutes(string $destinationCountry, float $weightKg, float $volumeM3, ?array $dutyInputs = null): array
    {
        $this->compareDutyInputs = $dutyInputs;

        // 1. Get all ranked routes
        $routes = $this->rankRoutes($destinationCountry);

        if (empty($routes)) {
            return [];
        }

        // 2. Evaluate each route
        $comparisons = [];

        foreach ($routes as $route) {
            try {
                $mode = $this->evaluateModeByWeight($weightKg, $volumeM3);

                if (!$this->validateRoute($route, $weightKg, $volumeM3)) {
                    continue;
                }

                $freightCost = $this->getFreightCost(
                    $route->getLaneCode(),
                    $mode,
                    $weightKg,
                    $volumeM3
                );

                $laneDetails = $this->parseLaneCode($route->getLaneCode());

                // ── SAFE duty semantics ──
                $dutyAmount = 0.0;
                $dutyBasis = 'unresolved';
                if ($this->compareDutyInputs !== null) {
                    [$cHts, $cValue, $cQty, $cUom] = $this->compareDutyInputs;
                    try {
                        $duty = $this->dutyCalculationService->calculateDuty(
                            $cHts,
                            $cValue,
                            $cQty,
                            $cUom,
                            $destinationCountry,
                            'MA'
                        );
                        if (isset($duty['dutyAmount'])) {
                            $dutyAmount = (float) $duty['dutyAmount'];
                            $dutyBasis = $duty['method'] ?? 'MFN';
                        }
                    } catch (\Throwable) {
                        // tariff data unavailable: stays unresolved
                    }
                }

                $totalLanded = $dutyBasis === 'unresolved'
                    ? null
                    : round((float) $freightCost['cost'] + $dutyAmount, 2);

                $comparisons[] = [
                    'route_code' => $route->getLaneCode(),
                    'mode' => $mode,
                    'rank' => $route->getRank(),
                    'origin_port' => $laneDetails['origin_port'],
                    'destination_port' => $laneDetails['destination_port'],
                    'transit_days' => $freightCost['transit_days'],
                    'freight_cost' => $freightCost['cost'],
                    'duty_amount' => $dutyAmount,
                    'duty_basis' => $dutyBasis,
                    'total_landed_cost' => $totalLanded,
                    'currency' => $freightCost['currency'],
                ];
            } catch (\Exception $e) {
                // Skip routes that don't have pricing
                continue;
            }
        }

        // 3. Sort by TOTAL LANDED COST; UNRESOLVED candidates sort last
        // (never as zero); ties broken by transit time.
        usort($comparisons, fn ($a, $b) =>
            (($a['total_landed_cost'] ?? PHP_FLOAT_MAX) <=> ($b['total_landed_cost'] ?? PHP_FLOAT_MAX)) !== 0
                ? ($a['total_landed_cost'] ?? PHP_FLOAT_MAX) <=> ($b['total_landed_cost'] ?? PHP_FLOAT_MAX)
                : $a['transit_days'] <=> $b['transit_days']
        );

        return $comparisons;
    }

    /**
     * Validate route supports given weight/volume
     * 
     * @param RoutePreference $route Route to validate
     * @param float $weightKg Weight in kg
     * @param float $volumeM3 Volume in m³
     * @return bool True if route supports shipment
     * 
     * Implementation:
     * 1. Check route.min_weight_kg <= weightKg <= route.max_weight_kg
     * 2. Check route.min_volume_m3 <= volumeM3 <= route.max_volume_m3
     * 3. Check mode compatibility
     * 4. Return true if all checks pass
     */
    public function validateRoute(RoutePreference $route, float $weightKg, float $volumeM3): bool
    {
        // The REAL entity carries single weightThresholdKg / volumeThresholdM3
        // values (the mode switch-over points for this preference), not
        // min/max pairs. Treat the threshold as the upper bound for the
        // preference's feasibility.
        $weightThreshold = $route->getWeightThresholdKg();
        if ($weightThreshold !== null && $weightThreshold !== '' && $weightKg > (float) $weightThreshold) {
            return false;
        }

        $volumeThreshold = $route->getVolumeThresholdM3();
        if ($volumeThreshold !== null && $volumeThreshold !== '' && $volumeM3 > (float) $volumeThreshold) {
            return false;
        }

        return true;
    }

    /**
     * Parse lane code to extract ports
     * 
     * @param string $laneCode Lane code (e.g., 'CMN-JFK', 'TAN-CDG-ORY')
     * @return array ['origin_port' => 'CMN', 'destination_port' => 'JFK', 'transit_ports' => []]
     * 
     * Implementation:
     * - Split by '-' delimiter
     * - First element: origin_port
     * - Last element: destination_port
     * - Middle elements: transit_ports
     */
    public function parseLaneCode(string $laneCode): array
    {
        $ports = explode('-', $laneCode);
        
        return [
            'origin_port' => $ports[0] ?? null,
            'destination_port' => $ports[count($ports) - 1] ?? null,
            'transit_ports' => array_slice($ports, 1, -1),
        ];
    }
}
