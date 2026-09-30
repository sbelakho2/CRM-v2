<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FreightTable;
use App\Entity\RoutePreference;
use App\Repository\FreightTableRepository;
use App\Repository\RoutePreferenceRepository;
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
 * - AIR: < 50 kg chargeable
 * - LCL (Less than Container Load): 50-15,000 kg chargeable
 * - FCL (Full Container Load): > 15,000 kg chargeable
 *
 * Freight pricing reads the REAL freight_tables model:
 *   origin_port / destination_port / transport_mode / container_type /
 *   cost_per_unit / currency / transit_days / effective_date / expiry_date /
 *   is_active. Pricing semantics of cost_per_unit by mode:
 *   - AIR: per chargeable kg (max of actual weight and volume × 167 kg/m³)
 *   - LCL: per chargeable m³ (max of volume and weight/1000)
 *   - FCL: flat rate per container (20GP / 40GP / 40HQ by volume)
 *
 * Used by: Landed-Cost Estimator, Quote Co-Pilot, FreightPricingService
 */
class RouteSelectionService
{
    /**
     * Origin-country → eligible origin ports. RoutePreference stores a
     * port-level origin (lane code); this deterministic map answers which
     * ports serve which origin country — every route lookup honors the
     * $origin argument instead of silently returning Morocco lanes.
     */
    private const ORIGIN_PORTS = [
        'MA' => ['CMN', 'TNG', 'CAS', 'MRZ', 'AGA'], // Casablanca, Tanger, Casablanca, Mohammedia, Agadir
        'US' => ['JFK', 'LAX', 'NYC', 'ORD', 'SAV'],
        'EU' => ['RTM', 'HAM', 'FRA', 'ANR', 'LEH'],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RoutePreferenceRepository $routePreferenceRepository,
        private FreightTableRepository $freightTableRepository,
        private CurrencyPreferenceService $currencyPreferenceService,
        private DutyCalculationService $dutyCalculationService,
        private CurrencyConverter $currencyConverter,
    ) {}

    /**
     * Select optimal route for shipment — the GLOBAL landed-cost optimum.
     *
     * Every active route for the requested origin→destination lane is
     * evaluated (freight + duty, normalized into ONE base currency) and the
     * minimum chosen. Rank order only breaks ties among equal landed costs.
     *
     * @param string $destinationCountry Destination country code (e.g., 'US', 'FR', 'MA')
     * @param float $weightKg Total weight in kilograms
     * @param float $volumeM3 Total volume in cubic meters
     * @param string|null $origin Origin country code (default: 'MA' for Morocco)
     * @return array Selected route: route_code, mode, origin_port,
     *               destination_port, transit_days, freight_cost,
     *               total_landed_cost, currency (+ duty_unresolved_candidates)
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
        // Single route-lookup path (origin-honoring) shared with compareRoutes.
        $routes = $this->rankRoutes($destinationCountry, $origin);

        if (empty($routes)) {
            throw new \RuntimeException("No active routes found to {$destinationCountry}");
        }

        $recommendedMode = $this->evaluateModeByWeight($weightKg, $volumeM3);

        $dutyInputs = ($htsCode !== null && $goodsValue !== null && $goodsValue > 0.0 && $quantity !== null && $quantity > 0.0)
            ? [$htsCode, $goodsValue, $quantity, $uom ?? 'KG']
            : null;

        $ranked = [];
        $unresolved = [];
        foreach ($routes as $route) {
            if (!$this->modeFamilyMatches($route->getMode(), $recommendedMode)) {
                continue;
            }
            if (!$this->validateRoute($route, $weightKg, $volumeM3)) {
                continue;
            }

            $candidate = $this->evaluateLaneCandidate(
                $route,
                $recommendedMode,
                $weightKg,
                $volumeM3,
                $destinationCountry,
                $origin ?? 'MA',
                $dutyInputs
            );

            if ($candidate === null) {
                continue; // no freight pricing for this lane
            }
            if ($candidate['total_landed_cost'] === null) {
                $unresolved[] = $candidate;
                continue;
            }
            $ranked[] = $candidate;
        }

        if ($ranked === []) {
            if ($unresolved !== []) {
                $reasons = array_unique(array_column($unresolved, 'unresolved_reason'));
                throw new \RuntimeException(sprintf(
                    'Routes exist to %s but landed cost cannot be ranked (%s). Candidates: %s',
                    $destinationCountry,
                    implode(', ', $reasons),
                    implode(', ', array_column($unresolved, 'route_code'))
                ));
            }

            throw new \RuntimeException("No feasible, priced route found to {$destinationCountry}");
        }

        // Minimum landed cost wins; lower preference rank breaks ties.
        usort($ranked, fn (array $a, array $b) =>
            [$a['total_landed_cost'], $a['rank'] ?? PHP_INT_MAX] <=> [$b['total_landed_cost'], $b['rank'] ?? PHP_INT_MAX]
        );

        $best = $ranked[0];

        return $best + ['duty_unresolved_candidates' => $unresolved];
    }

    /**
     * Evaluate freight mode based on weight and volume
     *
     * Chargeable weight = max(actual weight, volumetric weight); volumetric
     * = volumeM3 × 167 kg/m³ (air standard). Thresholds: <50 kg → AIR,
     * ≤15,000 kg → LCL (FCL when volume exceeds a 40' container), else FCL.
     */
    public function evaluateModeByWeight(float $weightKg, float $volumeM3): string
    {
        $volumetricWeightKg = $volumeM3 * 167;
        $chargeableWeight = max($weightKg, $volumetricWeightKg);

        if ($chargeableWeight < 50) {
            return 'AIR';
        } elseif ($chargeableWeight <= 15000) {
            if ($volumeM3 > 67) {
                return 'FCL'; // Exceeds 40' container, must use FCL
            }
            return 'LCL';
        } else {
            return 'FCL';
        }
    }

    /**
     * Get all available routes for destination (ranked), honoring origin.
     *
     * @return RoutePreference[] Active routes to $destinationCountry from
     *                          $origin's eligible ports, ordered by rank ASC.
     */
    public function rankRoutes(string $destinationCountry, ?string $origin = 'MA'): array
    {
        $qb = $this->routePreferenceRepository->createQueryBuilder('r')
            ->where('r.destinationCountry = :dest')
            ->andWhere('r.isActive = true')
            ->setParameter('dest', $destinationCountry);

        $origin = strtoupper($origin ?? 'MA');
        $ports = self::ORIGIN_PORTS[$origin] ?? null;
        if ($ports === null) {
            throw new \RuntimeException(sprintf(
                'Origin "%s" is not supported (eligible: %s). Model the origin ports before routing from it.',
                $origin,
                implode(', ', array_keys(self::ORIGIN_PORTS))
            ));
        }

        $expr = $qb->expr();
        $ors = [];
        foreach ($ports as $i => $port) {
            $ors[] = $expr->like('r.originPort', ':originPort' . $i);
            $qb->setParameter('originPort' . $i, $port . '%');
        }
        $qb->andWhere($expr->orX(...$ors));

        return $qb
            ->orderBy('r.rank', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get freight cost for a lane and mode from the real freight_tables model.
     *
     * Rate row selection: active + effective (effectiveDate ≤ today and
     * expiryDate null or ≥ today) rows for the lane's origin/destination
     * ports and the mode's transport family; the NEWEST effectiveDate wins.
     * FCL additionally selects the container tier matching the shipment
     * volume (20GP ≤ 33 m³, 40GP ≤ 67 m³, 40HQ above), falling back to any
     * container-tier row on the lane.
     *
     * @return array{cost: float, base_cost: float, rate_per_unit: float,
     *               currency: string, transit_days: int, container_type: ?string,
     *               carrier: ?string, effective_date: ?string, surcharges: array}
     */
    public function getFreightCost(string $routeCode, string $mode, float $weightKg, float $volumeM3): array
    {
        $lane = $this->parseLaneCode($routeCode);
        $originPort = $lane['origin_port'];
        $destinationPort = $lane['destination_port'];
        if (!$originPort || !$destinationPort) {
            throw new \InvalidArgumentException("Invalid lane code format: {$routeCode} (expected ORIGIN-DESTINATION)");
        }

        $normalizedMode = strtoupper($mode);
        if (!in_array($normalizedMode, ['AIR', 'LCL', 'FCL'], true)) {
            throw new \InvalidArgumentException("Unsupported freight mode: {$mode} (expected AIR, LCL or FCL)");
        }

        /** @var list<FreightTable> $rows */
        $rows = $this->freightTableRepository->createQueryBuilder('f')
            ->where('f.originPort LIKE :origin')
            ->andWhere('f.destinationPort LIKE :destination')
            ->andWhere('f.transportMode IN (:modes)')
            ->andWhere('f.isActive = true')
            ->andWhere('f.effectiveDate <= :today')
            ->andWhere('f.expiryDate IS NULL OR f.expiryDate >= :today')
            ->setParameter('origin', $originPort . '%')
            ->setParameter('destination', $destinationPort . '%')
            ->setParameter('modes', $this->transportModeFamily($normalizedMode))
            ->setParameter('today', new \DateTime())
            ->orderBy('f.effectiveDate', 'DESC')
            ->addOrderBy('f.id', 'DESC')
            ->getQuery()
            ->getResult();

        $rate = $this->pickFreightRow($rows, $normalizedMode, $volumeM3);

        if (!$rate instanceof FreightTable) {
            throw new \RuntimeException("No freight rate found for route {$routeCode} mode {$mode}");
        }

        $costPerUnit = (float) $rate->getCostPerUnit();
        $surcharges = [];

        $cost = match ($normalizedMode) {
            'AIR' => max($weightKg, $volumeM3 * 167.0) * $costPerUnit, // chargeable weight
            'LCL' => max($volumeM3, $weightKg / 1000.0) * $costPerUnit, // chargeable volume
            'FCL' => $costPerUnit, // flat per-container rate
        };

        if ($normalizedMode === 'AIR') {
            $surcharges['chargeable_weight_kg'] = round(max($weightKg, $volumeM3 * 167.0), 2);
        } elseif ($normalizedMode === 'LCL') {
            $surcharges['chargeable_volume_m3'] = round(max($volumeM3, $weightKg / 1000.0), 2);
        }

        return [
            'cost' => round($cost, 2),
            'base_cost' => round($cost, 2),
            'rate_per_unit' => $costPerUnit,
            'currency' => $rate->getCurrency() ?? $this->currencyPreferenceService->getDisplayCurrency(),
            'transit_days' => $rate->getTransitDays() ?? 7,
            'container_type' => $rate->getContainerType(),
            'carrier' => $rate->getCarrier(),
            'effective_date' => $rate->getEffectiveDate()?->format('Y-m-d'),
            'surcharges' => $surcharges,
        ];
    }

    /**
     * Compare candidate routes on TOTAL LANDED COST (freight + duty).
     *
     * Uses the SAME candidate evaluation as selectOptimalRoute() — one
     * internal evaluator, so the two entry points can never disagree on
     * currency or duty semantics.
     *
     * @param array{0: string, 1: float, 2: float, 3: string}|null $dutyInputs
     *        [htsCode, goodsValue, quantity, uom] — the real product inputs.
     *        Without them (or without tariff data), candidates report
     *        duty_basis=unresolved, total_landed_cost=null and sort AFTER
     *        every ranked candidate — "duty unknown" is never zero duty.
     */
    public function compareRoutes(
        string $destinationCountry,
        float $weightKg,
        float $volumeM3,
        ?array $dutyInputs = null,
        ?string $origin = 'MA'
    ): array {
        $routes = $this->rankRoutes($destinationCountry, $origin);
        if (empty($routes)) {
            return [];
        }

        $mode = $this->evaluateModeByWeight($weightKg, $volumeM3);

        $comparisons = [];
        foreach ($routes as $route) {
            if (!$this->modeFamilyMatches($route->getMode(), $mode)) {
                continue;
            }
            if (!$this->validateRoute($route, $weightKg, $volumeM3)) {
                continue;
            }

            $candidate = $this->evaluateLaneCandidate(
                $route,
                $mode,
                $weightKg,
                $volumeM3,
                $destinationCountry,
                strtoupper($origin ?? 'MA'),
                $dutyInputs
            );

            if ($candidate !== null) {
                $comparisons[] = $candidate;
            }
        }

        // Ranked candidates by landed cost; unresolved (null total) sort
        // last — never as zero; ties broken by transit time.
        usort($comparisons, fn (array $a, array $b) =>
            (($a['total_landed_cost'] ?? PHP_FLOAT_MAX) <=> ($b['total_landed_cost'] ?? PHP_FLOAT_MAX)) !== 0
                ? ($a['total_landed_cost'] ?? PHP_FLOAT_MAX) <=> ($b['total_landed_cost'] ?? PHP_FLOAT_MAX)
                : $a['transit_days'] <=> $b['transit_days']
        );

        return $comparisons;
    }

    // ──────────────────────────────────────────────────
    // Internal: shared candidate evaluation
    // ──────────────────────────────────────────────────

    /**
     * ONE internal candidate evaluator used by BOTH selectOptimalRoute()
     * and compareRoutes(): freight from the real freight model, duty from
     * real inputs, and currency normalization that FAILS CLOSED — an
     * unresolvable FX pair excludes the candidate (flagged), never compares
     * it 1:1 against another currency.
     *
     * @param array{0: string, 1: float, 2: float, 3: string}|null $dutyInputs
     * @return array|null null when the lane has no applicable freight rate;
     *                    otherwise a candidate row whose total_landed_cost is
     *                    null + unresolved_reason set when it cannot be ranked.
     */
    private function evaluateLaneCandidate(
        RoutePreference $route,
        string $mode,
        float $weightKg,
        float $volumeM3,
        string $destinationCountry,
        string $origin,
        ?array $dutyInputs
    ): ?array {
        try {
            $freightCost = $this->getFreightCost((string) $route->getLaneCode(), $mode, $weightKg, $volumeM3);
        } catch (\Throwable) {
            return null; // no pricing for this lane
        }

        $laneDetails = $this->parseLaneCode((string) $route->getLaneCode());
        $baseCurrency = strtoupper($this->currencyConverter->getDisplayCurrency());

        $candidate = [
            'route_code' => $route->getLaneCode(),
            'mode' => $mode,
            'origin_port' => $laneDetails['origin_port'],
            'destination_port' => $laneDetails['destination_port'],
            'transit_days' => $freightCost['transit_days'] ?? 7,
            'freight_cost' => $freightCost['cost'],
            'freight_currency' => $freightCost['currency'],
            'container_type' => $freightCost['container_type'] ?? null,
            'rank' => $route->getRank(),
            'base_currency' => $baseCurrency,
            'total_landed_cost' => null,
            'unresolved_reason' => null,
        ];

        // ── Duty: real inputs only; unresolved is never zero ──
        $dutyAmount = 0.0;
        $dutyBasis = 'unresolved';
        if ($dutyInputs !== null) {
            [$htsCode, $goodsValue, $quantity, $uom] = $dutyInputs;
            try {
                $duty = $this->dutyCalculationService->calculateDuty(
                    $htsCode,
                    $goodsValue,
                    $quantity,
                    $uom,
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

        $candidate['duty_amount'] = $dutyAmount;
        $candidate['duty_basis'] = $dutyBasis;

        if ($dutyBasis === 'unresolved') {
            $candidate['unresolved_reason'] = $dutyInputs === null
                ? 'duty_inputs_missing'
                : 'tariff_unresolved';
            return $candidate;
        }

        // ── Currency normalization: FAIL CLOSED for ranking ──
        $rateCurrency = strtoupper((string) ($freightCost['currency'] ?? 'USD'));
        try {
            $freightNormalized = $this->currencyConverter->convertOrFail((float) $freightCost['cost'], $rateCurrency, $baseCurrency);
            $dutyNormalized = $this->currencyConverter->convertOrFail($dutyAmount, $baseCurrency, $baseCurrency);
        } catch (\RuntimeException $e) {
            $candidate['unresolved_reason'] = 'fx_unresolved: ' . $e->getMessage();
            return $candidate;
        }

        $candidate['freight_cost_base'] = round($freightNormalized, 2);
        $candidate['currency'] = $baseCurrency;
        $candidate['total_landed_cost'] = round($freightNormalized + $dutyNormalized, 2);

        return $candidate;
    }

    /**
     * RoutePreference.mode values (AIR/OCEAN/RAIL/TRUCK — or ANY) must match
     * the recommended mode's transport family: AIR↔AIR, OCEAN↔LCL/FCL.
     */
    private function modeFamilyMatches(?string $preferredMode, string $recommendedMode): bool
    {
        if ($preferredMode === null || trim($preferredMode) === '') {
            return true;
        }
        $preferred = strtoupper(trim($preferredMode));
        if ($preferred === 'ANY') {
            return true;
        }

        $family = match ($recommendedMode) {
            'AIR' => ['AIR'],
            'LCL', 'FCL' => ['OCEAN', 'LCL', 'FCL', 'SEA'],
            default => [strtoupper($recommendedMode)],
        };

        return in_array($preferred, $family, true);
    }

    /**
     * freight_tables.transport_mode uses either the operational values
     * (AIR/LCL/FCL as imported from datasets) or the carrier family
     * (Air/Ocean). Map the commercial mode to its family for row matching —
     * MySQL's default collation makes the comparison case-insensitive.
     *
     * @return list<string>
     */
    private function transportModeFamily(string $mode): array
    {
        return match ($mode) {
            'AIR' => ['AIR'],
            default => ['LCL', 'FCL', 'OCEAN'],
        };
    }

    /**
     * Pick the applicable row: for FCL prefer the container tier matching
     * the shipment volume, then any container-tier row; for LCL prefer an
     * LCL-typed row; AIR takes the newest row. Rows arrive newest-first.
     *
     * @param list<FreightTable> $rows
     */
    private function pickFreightRow(array $rows, string $mode, float $volumeM3): ?FreightTable
    {
        if ($mode === 'FCL') {
            $desired = $volumeM3 > 67 ? '40HQ' : ($volumeM3 > 33 ? '40GP' : '20GP');
            foreach ($rows as $row) {
                if (strtoupper((string) $row->getContainerType()) === $desired) {
                    return $row;
                }
            }
            foreach ($rows as $row) {
                if (in_array(strtoupper((string) $row->getContainerType()), ['20GP', '40GP', '40HQ'], true)) {
                    return $row;
                }
            }

            return null;
        }

        if ($mode === 'LCL') {
            foreach ($rows as $row) {
                if (strtoupper((string) $row->getContainerType()) === 'LCL') {
                    return $row;
                }
            }
        }

        return $rows[0] ?? null;
    }

    /**
     * Validate route supports given weight/volume
     *
     * The REAL entity carries single weightThresholdKg / volumeThresholdM3
     * values (the mode switch-over points for this preference), not min/max
     * pairs. The threshold is the upper bound for the preference's feasibility.
     */
    public function validateRoute(RoutePreference $route, float $weightKg, float $volumeM3): bool
    {
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
     * @return array{origin_port: ?string, destination_port: ?string, transit_ports: list<string>}
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
