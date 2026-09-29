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
        ?string $origin = 'MA'
    ): array {
        // 1. Get ranked routes from route_preferences
        $routes = $this->routePreferenceRepository->createQueryBuilder('r')
            ->where('r.destinationCountry = :dest')
            ->andWhere('r.originCountry = :origin')
            ->andWhere('r.isActive = true')
            ->setParameter('dest', $destinationCountry)
            ->setParameter('origin', $origin)
            ->orderBy('r.rank', 'ASC')
            ->getQuery()
            ->getResult();
        
        if (empty($routes)) {
            throw new \RuntimeException("No active routes found for {$origin} to {$destinationCountry}");
        }

        // GLOBAL landed-cost optimum: every active route for the lane is
        // evaluated (freight + duty) and the minimum chosen — not merely
        // the first feasible route in rank order. Rank order is retained
        // only as a tiebreaker among equal landed costs.
        
        // 2. Determine freight mode
        $recommendedMode = $this->evaluateModeByWeight($weightKg, $volumeM3);

        // 3. Evaluate EVERY feasible candidate on total landed cost
        //    (freight + duty); choose the global minimum. Rank breaks ties.
        $best = null;
        foreach ($routes as $route) {
            $preferredMode = $route->getPreferredMode();
            if ($preferredMode && $preferredMode !== $recommendedMode && $preferredMode !== 'ANY') {
                continue;
            }
            if (!$this->validateRoute($route, $weightKg, $volumeM3)) {
                continue;
            }

            try {
                $freightCost = $this->getFreightCost(
                    $route->getRouteCode(),
                    $recommendedMode,
                    $weightKg,
                    $volumeM3
                );
            } catch (\Exception $e) {
                continue; // no pricing for this candidate
            }

            $customsValue = (float) $freightCost['cost'];
            $dutyAmount = 0.0;
            $dutyBasis = 'unavailable';
            try {
                $duty = $this->dutyCalculationService->calculateDuty(
                    '9902.00.00',
                    $customsValue,
                    $weightKg,
                    'KG',
                    $destinationCountry,
                    $origin
                );
                if (isset($duty['dutyAmount'])) {
                    $dutyAmount = (float) $duty['dutyAmount'];
                    $dutyBasis = $duty['method'] ?? 'MFN';
                }
            } catch (\Throwable) {
                // duty data unavailable for the lane: reported, not assumed zero
            }

            $laneDetails = $this->parseLaneCode($route->getLaneCode());
            $landed = (float) $freightCost['cost'] + $dutyAmount;

            $candidate = [
                'route_code' => $route->getRouteCode(),
                'mode' => $recommendedMode,
                'origin_port' => $laneDetails['origin_port'],
                'destination_port' => $laneDetails['destination_port'],
                'transit_days' => $route->getTransitDays() ?? 7,
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
            throw new \RuntimeException("No feasible, priced route found for {$origin} to {$destinationCountry}");
        }

        return $best;
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
        // Check weight constraints
        $minWeight = $route->getMinWeightKg();
        $maxWeight = $route->getMaxWeightKg();
        
        if ($minWeight !== null && $weightKg < $minWeight) {
            return false;
        }
        
        if ($maxWeight !== null && $weightKg > $maxWeight) {
            return false;
        }
        
        // Check volume constraints
        $minVolume = $route->getMinVolumeM3();
        $maxVolume = $route->getMaxVolumeM3();
        
        if ($minVolume !== null && $volumeM3 < $minVolume) {
            return false;
        }
        
        if ($maxVolume !== null && $volumeM3 > $maxVolume) {
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
