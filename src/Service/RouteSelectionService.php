<?php

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
        private FreightTableRepository $freightTableRepository
    ) {}

    /**
     * Select optimal route for shipment
     * 
     * @param string $destinationCountry Destination country code (e.g., 'US', 'FR', 'MA')
     * @param float $weightKg Total weight in kilograms
     * @param float $volumeM3 Total volume in cubic meters
     * @param string|null $origin Origin country code (default: 'MA' for Morocco)
     * @return array Route details: ['route_code' => 'MA-US-AIR-001', 'mode' => 'AIR', 'origin_port' => 'CMN', 'destination_port' => 'JFK', 'transit_days' => 5, 'freight_cost' => 1250.00, 'currency' => 'USD']
     * 
     * TODO Implementation:
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
        // TODO: Implement optimal route selection
        throw new \RuntimeException('Route selection not yet implemented');
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
        // TODO: Implement route ranking
        return $this->routePreferenceRepository->findRankedRoutes($destinationCountry);
    }

    /**
     * Get freight cost for specific route and mode
     * 
     * @param string $routeCode Route code (e.g., 'MA-US-AIR-001')
     * @param string $mode Freight mode (AIR|LCL|FCL)
     * @param float $weightKg Chargeable weight in kg
     * @param float $volumeM3 Volume in cubic meters
     * @return array Freight details: ['cost' => 1250.00, 'currency' => 'USD', 'transit_days' => 5, 'surcharges' => [...]]
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
        // TODO: Implement freight cost calculation
        throw new \RuntimeException('Freight cost calculation not yet implemented');
    }

    /**
     * Compare all available routes for destination
     * 
     * @param string $destinationCountry Destination country code
     * @param float $weightKg Total weight in kg
     * @param float $volumeM3 Total volume in m³
     * @return array Array of route comparisons (sorted by total cost)
     * 
     * TODO Implementation:
     * 1. Get all ranked routes for destination
     * 2. For each route:
     *    → Determine appropriate mode
     *    → Get freight cost
     *    → Calculate total landed cost (freight + duty + other)
     * 3. Sort by total cost ascending
     * 4. Return comparison array
     */
    public function compareRoutes(string $destinationCountry, float $weightKg, float $volumeM3): array
    {
        // TODO: Implement route comparison
        throw new \RuntimeException('Route comparison not yet implemented');
    }

    /**
     * Validate route supports given weight/volume
     * 
     * @param RoutePreference $route Route to validate
     * @param float $weightKg Weight in kg
     * @param float $volumeM3 Volume in m³
     * @return bool True if route supports shipment
     * 
     * TODO Implementation:
     * 1. Check route.min_weight_kg <= weightKg <= route.max_weight_kg
     * 2. Check route.min_volume_m3 <= volumeM3 <= route.max_volume_m3
     * 3. Check mode compatibility
     * 4. Return true if all checks pass
     */
    public function validateRoute(RoutePreference $route, float $weightKg, float $volumeM3): bool
    {
        // TODO: Implement route validation
        throw new \RuntimeException('Route validation not yet implemented');
    }

    /**
     * Parse lane code to extract ports
     * 
     * @param string $laneCode Lane code (e.g., 'CMN-JFK', 'TAN-CDG-ORY')
     * @return array ['origin_port' => 'CMN', 'destination_port' => 'JFK', 'transit_ports' => []]
     * 
     * TODO Implementation:
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
