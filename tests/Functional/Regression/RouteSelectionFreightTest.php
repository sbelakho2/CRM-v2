<?php

namespace App\Tests\Functional\Regression;

use App\Entity\FreightTable;
use App\Entity\FxRate;
use App\Entity\RoutePreference;
use App\Entity\TariffRate;
use App\Service\DutyCalculationService;
use App\Service\RouteSelectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Round-8 P0-1: RouteSelectionService was written against an IMAGINARY
 * freight schema — it queried f.routeCode / f.mode (properties that do not
 * exist on FreightTable) and called six getters (getBaseRate, getPerKgRate,
 * getPerCbmRate, getContainerRate, getFuelSurcharge, getSecuritySurcharge)
 * that the entity never had. Green CI missed it because no test executed
 * the Doctrine paths.
 *
 * These tests run every critical query path against the real MySQL test
 * schema, with exact-cost assertions per mode, effectiveness/inactive row
 * filtering, container-tier selection, mixed-currency ranking (fail-closed
 * FX), origin honoring, and a metadata reflection check that every DQL
 * property used by the service actually exists.
 */
class RouteSelectionFreightTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private RouteSelectionService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        // RouteSelectionService and its collaborators are inlined at container
        // compile time — build the graph explicitly, exactly as the container wires it.
        $currencyPreference = new \App\Service\CurrencyPreferenceService(
            self::getContainer()->get(\Symfony\Bundle\SecurityBundle\Security::class)
        );
        // Currency conversion WITHOUT the live-fetcher: deterministic, no
        // network — unknown pairs must fail closed, never 1:1.
        $currencyConverter = new \App\Service\CurrencyConverter(
            new \App\Service\CurrencyConversionService(
                $this->em->getRepository(\App\Entity\FxRate::class),
                new \Psr\Log\NullLogger(),
                null
            ),
            $currencyPreference
        );
        $dutyService = new DutyCalculationService(
            $this->em,
            $this->em->getRepository(\App\Entity\TariffRate::class),
            $this->em->getRepository(\App\Entity\FtaRule::class),
            new \App\Service\FtaEligibilityService(
                $this->em,
                $this->em->getRepository(\App\Entity\FtaRule::class),
                $this->em->getRepository(\App\Entity\CooSupplierDecl::class)
            )
        );

        // RouteSelectionService is inlined at container compile time — build
        // it explicitly from the same wired collaborators the container uses.
        $this->service = new RouteSelectionService(
            $this->em,
            $this->em->getRepository(RoutePreference::class),
            $this->em->getRepository(FreightTable::class),
            $currencyPreference,
            $dutyService,
            $currencyConverter,
        );

        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['freight_tables', 'route_preferences', 'fx_rates', 'tariff_rates'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    // ──────────────────────────────────────────────────
    // Fixtures
    // ──────────────────────────────────────────────────

    private function makeLane(string $laneCode, string $destinationCountry = 'US', int $rank = 1, ?string $mode = 'OCEAN', ?string $originPort = 'CMN'): RoutePreference
    {
        $route = new RoutePreference();
        $route->setDestinationCountry($destinationCountry);
        $route->setLaneCode($laneCode);
        $route->setRank($rank);
        $route->setMode($mode);
        $route->setOriginPort($originPort);
        $route->setDestinationPort(explode('-', $laneCode)[1] ?? null);
        $this->em->persist($route);

        return $route;
    }

    private function makeFreightRow(
        string $originPort,
        string $destinationPort,
        string $transportMode,
        string $containerType,
        string $costPerUnit,
        string $currency = 'USD',
        ?int $transitDays = 7,
        ?\DateTimeImmutable $effectiveFrom = null,
        ?\DateTimeImmutable $effectiveTo = null,
        bool $active = true
    ): FreightTable {
        $row = new FreightTable();
        $row->setOriginPort($originPort);
        $row->setDestinationPort($destinationPort);
        $row->setTransportMode($transportMode);
        $row->setContainerType($containerType);
        $row->setCostPerUnit($costPerUnit);
        $row->setCurrency($currency);
        $row->setTransitDays($transitDays);
        $row->setEffectiveDate($effectiveFrom ?? new \DateTimeImmutable('-30 days'));
        if ($effectiveTo !== null) {
            $row->setExpiryDate($effectiveTo);
        }
        $row->setIsActive($active);
        $this->em->persist($row);

        return $row;
    }

    private function makeFxRate(string $from, string $to, string $rate): FxRate
    {
        $fx = new FxRate();
        $fx->setFromCurrency($from);
        $fx->setToCurrency($to);
        $fx->setRate($rate);
        $fx->setAsof(new \DateTime());
        $this->em->persist($fx);

        return $fx;
    }

    private function makeTariff(string $hsCode, string $dest, string $origin, string $mfnRate): TariffRate
    {
        $tariff = new TariffRate();
        $tariff->setHsCode($hsCode);
        $tariff->setOriginCountry($origin);
        $tariff->setDestinationCountry($dest);
        $tariff->setDutyRate($mfnRate);
        $tariff->setMfnRate($mfnRate);
        $tariff->setDutyType('ad_valorem');
        $tariff->setEffectiveDate(new \DateTimeImmutable('-30 days'));
        $this->em->persist($tariff);

        return $tariff;
    }

    private function flush(): void
    {
        $this->em->flush();
        $this->em->clear();
    }

    // ──────────────────────────────────────────────────
    // Freight pricing semantics (real MySQL queries)
    // ──────────────────────────────────────────────────

    public function testAirFreightChargesPerChargeableKg(): void
    {
        $this->makeLane('CMN-JFK', mode: 'AIR');
        // 100 kg actual, 0.2 m³ (volumetric 33.4 kg) → chargeable = 100 kg.
        $this->makeFreightRow('CMN', 'JFK', 'AIR', 'LOOSE', '4.50', 'USD', 5);
        $this->flush();

        $cost = $this->service->getFreightCost('CMN-JFK', 'AIR', 100.0, 0.2);

        $this->assertSame(450.0, $cost['cost']);
        $this->assertSame(5, $cost['transit_days']); // non-default transit survives
        $this->assertSame('USD', $cost['currency']);
    }

    public function testAirVolumetricWeightDrivesChargeableWeight(): void
    {
        $this->makeLane('CMN-JFK', mode: 'AIR');
        $this->makeFreightRow('CMN', 'JFK', 'AIR', 'LOOSE', '4.50', 'USD', 5);
        $this->flush();

        // 10 kg actual but 1 m³ → volumetric 167 kg → chargeable 167.
        $cost = $this->service->getFreightCost('CMN-JFK', 'AIR', 10.0, 1.0);

        $this->assertSame(167.0 * 4.50, $cost['cost']);
    }

    public function testLclFreightChargesPerCubicMeter(): void
    {
        $this->makeLane('CMN-JFK');
        $this->makeFreightRow('CMN', 'JFK', 'LCL', 'LCL', '45.00', 'USD', 21);
        $this->flush();

        // 2 m³ vs 500 kg (0.5 t) → chargeable volume = 2 m³.
        $cost = $this->service->getFreightCost('CMN-JFK', 'LCL', 500.0, 2.0);

        $this->assertSame(90.0, $cost['cost']);
        $this->assertSame(21, $cost['transit_days']);
    }

    public function testFclSelectsContainerTierByVolume(): void
    {
        $this->makeLane('CMN-JFK');
        $this->makeFreightRow('CMN', 'JFK', 'FCL', '20GP', '1500.00', 'USD', 18);
        $this->makeFreightRow('CMN', 'JFK', 'FCL', '40GP', '2200.00', 'USD', 18);
        $this->makeFreightRow('CMN', 'JFK', 'FCL', '40HQ', '2400.00', 'USD', 18);
        $this->flush();

        $this->assertSame(1500.0, $this->service->getFreightCost('CMN-JFK', 'FCL', 5000.0, 10.0)['cost']);
        $this->assertSame(2200.0, $this->service->getFreightCost('CMN-JFK', 'FCL', 9000.0, 45.0)['cost']);
        $this->assertSame(2400.0, $this->service->getFreightCost('CMN-JFK', 'FCL', 12000.0, 70.0)['cost']);
    }

    public function testExpiredRateIsIgnored(): void
    {
        $this->makeLane('CMN-JFK');
        $this->makeFreightRow('CMN', 'JFK', 'LCL', 'LCL', '45.00', 'USD', 21, null, new \DateTimeImmutable('-1 day'));
        $this->flush();

        $this->expectException(\RuntimeException::class);
        $this->service->getFreightCost('CMN-JFK', 'LCL', 500.0, 2.0);
    }

    public function testInactiveRateIsIgnored(): void
    {
        $this->makeLane('CMN-JFK');
        $this->makeFreightRow('CMN', 'JFK', 'LCL', 'LCL', '45.00', 'USD', 21, null, null, false);
        $this->flush();

        $this->expectException(\RuntimeException::class);
        $this->service->getFreightCost('CMN-JFK', 'LCL', 500.0, 2.0);
    }

    public function testNewestEffectiveRateWins(): void
    {
        $this->makeLane('CMN-JFK');
        $this->makeFreightRow('CMN', 'JFK', 'LCL', 'LCL', '45.00', 'USD', 21, new \DateTimeImmutable('-90 days'));
        $this->makeFreightRow('CMN', 'JFK', 'LCL', 'LCL', '52.00', 'USD', 19, new \DateTimeImmutable('-5 days'));
        $this->flush();

        $cost = $this->service->getFreightCost('CMN-JFK', 'LCL', 500.0, 2.0);

        $this->assertSame(104.0, $cost['cost']); // 2 × 52.00 newest rate
        $this->assertSame(19, $cost['transit_days']);
    }

    public function testFutureEffectiveRateIsIgnored(): void
    {
        $this->makeLane('CMN-JFK');
        $this->makeFreightRow('CMN', 'JFK', 'LCL', 'LCL', '45.00', 'USD', 21, new \DateTimeImmutable('+10 days'));
        $this->flush();

        $this->expectException(\RuntimeException::class);
        $this->service->getFreightCost('CMN-JFK', 'LCL', 500.0, 2.0);
    }

    public function testMissingLaneFailsCleanly(): void
    {
        $this->flush();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No freight rate found');
        $this->service->getFreightCost('CMN-NOWHERE', 'LCL', 500.0, 2.0);
    }

    // ──────────────────────────────────────────────────
    // Route selection: duty, origin, FX semantics
    // ──────────────────────────────────────────────────

    private function seedLandedCostScenario(): void
    {
        // Two lanes to US: cheap freight (EUR) + expensive freight (USD).
        $this->makeLane('CMN-JFK', rank: 1);
        $this->makeLane('TNG-NYC', rank: 2);
        $this->makeFreightRow('CMN', 'JFK', 'LCL', 'LCL', '45.00', 'EUR', 20);
        $this->makeFreightRow('TNG', 'NYC', 'LCL', 'LCL', '60.00', 'USD', 24);
        // FX: 1 EUR = 1.10 USD (base/display currency USD assumed).
        $this->makeFxRate('EUR', 'USD', '1.10');
        // Tariff so duty resolves: 2.5% MFN MA→US on 8473.30.
        $this->makeTariff('847330', 'US', 'MA', '2.5');
        $this->flush();
    }

    public function testSelectOptimalRouteHonorsOrigin(): void
    {
        $this->seedLandedCostScenario();

        // MA origin: only CMN/TNG lanes are eligible — both are, and the
        // EUR lane at 2 m³ (90 EUR = 99 USD) beats TNG (120 USD).
        $route = $this->service->selectOptimalRoute(
            'US', 500.0, 2.0, 'MA', '8473.30.51', 10000.0, 'EA', 100.0
        );

        $this->assertSame('CMN-JFK', $route['route_code']);
        $this->assertSame(99.0, $route['freight_cost_base']); // 2 m³ × 45 EUR = 90 EUR × 1.10
    }

    public function testSelectOptimalRouteExcludesOtherOriginLanes(): void
    {
        // Only a US-origin lane exists to US; an MA caller must NOT get it.
        $this->makeLane('JFK-LAX', rank: 1, originPort: 'JFK');
        $this->makeFreightRow('JFK', 'LAX', 'LCL', 'LCL', '10.00', 'USD', 3);
        $this->flush();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active routes found');
        $this->service->selectOptimalRoute('US', 500.0, 2.0, 'MA', '847330', 10000.0, 'EA', 100.0);
    }

    public function testUnsupportedOriginFailsLoudly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not supported');
        $this->service->rankRoutes('US', 'ZZ');
    }

    public function testMixedCurrencyRankingNormalizesBeforeComparing(): void
    {
        $this->seedLandedCostScenario();

        // Raw numbers would rank CMN (45) below TNG (60) — but 45 EUR is
        // 49.5 USD + duty vs 60 USD + duty; the EUR lane must still win on
        // NORMALIZED landed cost and carry the normalized freight figure.
        $comparisons = $this->service->compareRoutes(
            'US', 500.0, 2.0, ['847330', 10000.0, 100.0, 'EA'], 'MA'
        );

        $this->assertCount(2, $comparisons);
        $this->assertSame('CMN-JFK', $comparisons[0]['route_code']);
        $this->assertSame('USD', $comparisons[0]['currency']);
        $this->assertSame(99.0, $comparisons[0]['freight_cost_base']);
        // Duty: 2.5% of 10,000 = 250 — identical on both lanes, so ranking
        // is freight-driven and both totals include it.
        $this->assertSame(349.0, $comparisons[0]['total_landed_cost']);
        $this->assertSame(370.0, $comparisons[1]['total_landed_cost']);
    }

    public function testMissingFxExcludesCandidateInsteadOfOneToOne(): void
    {
        // CMN lane priced in an UNKNOWN currency (no FX row, no fallback
        // table entry, no live fetcher): it must be excluded (unresolved),
        // never silently compared 1:1 against the USD lane.
        $this->makeLane('CMN-JFK', rank: 1);
        $this->makeLane('TNG-NYC', rank: 2);
        $this->makeFreightRow('CMN', 'JFK', 'LCL', 'LCL', '45.00', 'ZZZ', 20);
        $this->makeFreightRow('TNG', 'NYC', 'LCL', 'LCL', '60.00', 'USD', 24);
        $this->makeTariff('847330', 'US', 'MA', '2.5');
        $this->flush();

        $comparisons = $this->service->compareRoutes(
            'US', 500.0, 2.0, ['847330', 10000.0, 100.0, 'EA'], 'MA'
        );

        $this->assertCount(2, $comparisons);
        $ranked = array_values(array_filter($comparisons, fn ($c) => $c['total_landed_cost'] !== null));
        $this->assertCount(1, $ranked, 'unknown-currency lane must not be ranked without FX data');
        $this->assertSame('TNG-NYC', $ranked[0]['route_code']);

        $unresolved = array_values(array_filter($comparisons, fn ($c) => $c['total_landed_cost'] === null));
        $this->assertSame('CMN-JFK', $unresolved[0]['route_code']);
        $this->assertStringContainsString('fx_unresolved', (string) $unresolved[0]['unresolved_reason']);
    }

    public function testMissingDutyInputsExcludeCandidatesFromRanking(): void
    {
        $this->seedLandedCostScenario();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('landed cost cannot be ranked');
        $this->service->selectOptimalRoute('US', 500.0, 2.0, 'MA'); // no HTS/value/qty
    }

    public function testCompareRoutesWithoutDutyInputsSortsUnresolvedLast(): void
    {
        $this->seedLandedCostScenario();

        $comparisons = $this->service->compareRoutes('US', 500.0, 2.0, null, 'MA');

        $this->assertCount(2, $comparisons);
        $this->assertSame('unresolved', $comparisons[0]['duty_basis']);
        $this->assertNull($comparisons[0]['total_landed_cost']);
    }

    // ──────────────────────────────────────────────────
    // Schema-contract reflection gate
    // ──────────────────────────────────────────────────

    public function testEveryDqlPropertyUsedByServiceExistsInMetadata(): void
    {
        // The P0 bug: DQL strings referencing properties that don't exist
        // (f.routeCode, f.mode) fail only at RUNTIME. This reflection gate
        // fails at test time instead.
        $freightProps = $this->em->getClassMetadata(FreightTable::class)->getFieldNames();
        $routeProps = $this->em->getClassMetadata(RoutePreference::class)->getFieldNames();

        foreach (['originPort', 'destinationPort', 'transportMode', 'containerType', 'costPerUnit', 'currency', 'transitDays', 'effectiveDate', 'expiryDate', 'isActive'] as $prop) {
            $this->assertContains($prop, $freightProps, "FreightTable::{$prop} used by RouteSelectionService DQL");
        }
        foreach (['destinationCountry', 'laneCode', 'rank', 'originPort', 'mode', 'isActive'] as $prop) {
            $this->assertContains($prop, $routeProps, "RoutePreference::{$prop} used by RouteSelectionService DQL");
        }

        // And the pricing path actually executes against MySQL for every mode.
        $this->makeLane('CMN-JFK');
        $this->makeFreightRow('CMN', 'JFK', 'AIR', 'LOOSE', '4.50', 'USD', 5);
        $this->makeFreightRow('CMN', 'JFK', 'LCL', 'LCL', '45.00', 'USD', 21);
        $this->makeFreightRow('CMN', 'JFK', 'FCL', '20GP', '1500.00', 'USD', 18);
        $this->flush();

        $this->assertSame(450.0, $this->service->getFreightCost('CMN-JFK', 'AIR', 100.0, 0.2)['cost']);
        $this->assertSame(90.0, $this->service->getFreightCost('CMN-JFK', 'LCL', 500.0, 2.0)['cost']);
        $this->assertSame(1500.0, $this->service->getFreightCost('CMN-JFK', 'FCL', 5000.0, 10.0)['cost']);
    }

    // ──────────────────────────────────────────────────
    // Direct mode/threshold/lane-code unit expectations
    // ──────────────────────────────────────────────────

    public function testModeThresholdsAreExact(): void
    {
        // 10 kg, 0.1 m³ → volumetric 16.7 < 50 → AIR
        $this->assertSame('AIR', $this->service->evaluateModeByWeight(10.0, 0.1));
        // 100 kg → LCL
        $this->assertSame('LCL', $this->service->evaluateModeByWeight(100.0, 0.1));
        // volumetric pushes over: 1 m³ = 167 kg chargeable → LCL (not > 15000)
        $this->assertSame('LCL', $this->service->evaluateModeByWeight(10.0, 1.0));
        // 20,000 kg → FCL
        $this->assertSame('FCL', $this->service->evaluateModeByWeight(20000.0, 1.0));
        // volume exceeds a 40' container → forced FCL even at low weight
        $this->assertSame('FCL', $this->service->evaluateModeByWeight(100.0, 70.0));
    }

    public function testValidateRouteTreatsThresholdsAsInclusiveUpperBounds(): void
    {
        $route = new RoutePreference();
        $route->setDestinationCountry('US');
        $route->setLaneCode('CMN-JFK');
        $route->setRank(1);
        $route->setMode('OCEAN');
        $route->setWeightThresholdKg('100.00');
        $route->setVolumeThresholdM3('2.00');

        $this->assertTrue($this->service->validateRoute($route, 100.0, 2.0), 'exactly AT the threshold is feasible');
        $this->assertFalse($this->service->validateRoute($route, 100.01, 2.0), 'weight above threshold is infeasible');
        $this->assertFalse($this->service->validateRoute($route, 50.0, 2.01), 'volume above threshold is infeasible');

        $unbounded = new RoutePreference();
        $unbounded->setDestinationCountry('US');
        $unbounded->setLaneCode('CMN-JFK');
        $unbounded->setRank(2);
        $unbounded->setMode('OCEAN');
        $this->assertTrue($this->service->validateRoute($unbounded, 999999.0, 999999.0), 'null thresholds mean no bound');
    }

    public function testParseLaneCodeHandlesMultiHopLanes(): void
    {
        $simple = $this->service->parseLaneCode('CMN-JFK');
        $this->assertSame('CMN', $simple['origin_port']);
        $this->assertSame('JFK', $simple['destination_port']);
        $this->assertSame([], $simple['transit_ports']);

        $multi = $this->service->parseLaneCode('TAN-CDG-ORY');
        $this->assertSame('TAN', $multi['origin_port']);
        $this->assertSame('ORY', $multi['destination_port']);
        $this->assertSame(['CDG'], $multi['transit_ports']);
    }
}
