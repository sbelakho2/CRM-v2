<?php

namespace App\Tests\Functional\Regression;

use App\Entity\TariffRate;
use App\Service\DutyCalculationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Critical-surface coverage: the duty engine is customs math — wrong
 * numbers are border fines. Exact-arithmetic expectations for every duty
 * TYPE (ad_valorem, specific, compound), the VAT ladder (duty-inclusive
 * base), MFN application, FTA savings, and the fail-closed branches
 * (unknown country, missing specific rate, no tariff row).
 */
class DutyMathTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private DutyCalculationService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->service = new DutyCalculationService(
            $this->em,
            $this->em->getRepository(TariffRate::class),
            $this->em->getRepository(\App\Entity\FtaRule::class),
            new \App\Service\FtaEligibilityService(
                $this->em,
                $this->em->getRepository(\App\Entity\FtaRule::class),
                $this->em->getRepository(\App\Entity\CooSupplierDecl::class)
            )
        );

        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['tariff_rates'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function seedTariff(string $hsCode, string $dest, string $mfnRate, string $dutyType = 'ad_valorem', ?string $specificRate = null, ?string $ftaRate = null): TariffRate
    {
        $tariff = new TariffRate();
        $tariff->setHsCode($hsCode);
        $tariff->setOriginCountry('MA');
        $tariff->setDestinationCountry($dest);
        $tariff->setDutyRate($mfnRate);
        $tariff->setMfnRate($mfnRate);
        if ($ftaRate !== null) {
            $tariff->setFtaRate($ftaRate);
            $tariff->setFtaAgreement('Morocco-US FTA');
        }
        $tariff->setDutyType($dutyType);
        if ($specificRate !== null) {
            $tariff->setSpecificRate($specificRate);
        }
        $tariff->setEffectiveDate(new \DateTimeImmutable('-30 days'));
        $this->em->persist($tariff);

        return $tariff;
    }

    public function testAdValoremDutyIsExactPercentageOfCustomsValue(): void
    {
        $this->seedTariff('847330', 'US', '2.5');
        $this->em->flush();
        $this->em->clear();

        $result = $this->service->calculateDuty('8473.30.51', 10000.0, 100.0, 'EA', 'US', 'MA');

        $this->assertSame(2.5, $result['dutyRate']);
        $this->assertSame(250.0, $result['dutyAmount'], '2.5% of 10,000 = 250.00');
        $this->assertSame('MFN', $result['method']);
    }

    public function testSpecificDutyIsQuantityTimesSpecificRate(): void
    {
        $this->seedTariff('854110', 'US', '0', 'specific', '1.50');
        $this->em->flush();
        $this->em->clear();

        $result = $this->service->calculateDuty('8541.10', 5000.0, 100.0, 'EA', 'US', 'MA');

        $this->assertSame(150.0, $result['dutyAmount'], 'specific: 100 EA × $1.50 = 150.00 — value must NOT scale it');
    }

    public function testSpecificDutyWithoutQuantityFailsClosed(): void
    {
        $this->seedTariff('854110', 'US', '0', 'specific', '1.50');
        $this->em->flush();
        $this->em->clear();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires a specific rate and a positive quantity');
        $this->service->calculateDuty('8541.10', 5000.0, 0.0, 'EA', 'US', 'MA');
    }

    public function testCompoundDutySumsAdValoremAndSpecificParts(): void
    {
        // 2% of 5,000 = 100 + 100 EA × 0.50 = 50 → 150.00
        $this->seedTariff('854190', 'US', '2.0', 'compound', '0.50');
        $this->em->flush();
        $this->em->clear();

        $result = $this->service->calculateDuty('8541.90', 5000.0, 100.0, 'EA', 'US', 'MA');

        $this->assertSame(150.0, $result['dutyAmount'], 'compound = ad valorem part + specific part');
    }

    public function testMfnRateAppliesLatestEffectiveRow(): void
    {
        $old = $this->seedTariff('901380', 'US', '4.0');
        $old->setEffectiveDate(new \DateTimeImmutable('-90 days'));
        $this->seedTariff('901380', 'US', '1.8');
        $this->em->flush();
        $this->em->clear();

        $result = $this->service->applyMfnRate('901380', 'US', 1000.0, 1.0, 'EA');

        $this->assertSame(1.8, $result['dutyRate'], 'newest effective row must win');
        $this->assertSame(18.0, $result['dutyAmount']);
    }

    public function testCalculateVatUsesDutyInclusiveBaseAndFailsClosedOnUnknownCountry(): void
    {
        // MA: 20% on (1000 + 250 duty) = 250.00
        $ma = $this->service->calculateVat(1000.0, 250.0, 'MA');
        $this->assertSame(20.0, $ma['vatRate']);
        $this->assertSame(250.0, $ma['vatAmount']);

        // US: no federal VAT
        $us = $this->service->calculateVat(1000.0, 250.0, 'US');
        $this->assertSame(0.0, $us['vatRate']);
        $this->assertSame(0.0, $us['vatAmount']);

        $this->expectException(\RuntimeException::class);
        $this->service->calculateVat(1000.0, 250.0, 'ZZ');
    }

    public function testFtaPreferentialRateProducesClampedSavings(): void
    {
        // MFN 2.5% vs FTA 0.0% on 10,000 → savings 250.00, method FTA.
        $this->seedTariff('847330', 'US', '2.5', 'ad_valorem', null, '0.0');
        $this->em->flush();
        $this->em->clear();

        $result = $this->service->calculateDuty('8473.30.51', 10000.0, 100.0, 'EA', 'US', 'MA', true);

        $this->assertSame('FTA', $result['method']);
        $this->assertSame(0.0, $result['dutyAmount']);
        $this->assertSame(250.0, $result['ftaSavings']);
    }

    public function testBadFtaDataCannotProduceNegativeSavings(): void
    {
        // Preferential rate ABOVE MFN (data error): savings clamp at 0.
        $this->seedTariff('847331', 'US', '1.0', 'ad_valorem', null, '2.0');
        $this->em->flush();
        $this->em->clear();

        $result = $this->service->calculateDuty('8473.31', 10000.0, 100.0, 'EA', 'US', 'MA', true);

        $this->assertGreaterThanOrEqual(0.0, $result['ftaSavings'] ?? 0.0, 'negative savings must be clamped');
    }

    public function testApplyFtaRateWithoutAgreementReturnsNullRateAndNoDeclaration(): void
    {
        $result = $this->service->applyFtaRate('847330', 'MA', 'US', 10000.0, []);

        $this->assertNull($result['dutyRate']);
        $this->assertNull($result['ftaAgreement']);
        $this->assertFalse($result['requiresDeclaration']);
    }

    public function testMissingTariffRowFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tariff rate not found');
        $this->service->calculateDuty('9999.99', 1000.0, 1.0, 'EA', 'US', 'MA');
    }

    public function testHtsFallbackWalksToChapterLevel(): void
    {
        // Exact 10-digit missing; chapter (4-digit) row exists.
        $this->seedTariff('8471', 'US', '0.0');
        $this->em->flush();
        $this->em->clear();

        $result = $this->service->calculateDuty('8471.50.20', 2000.0, 1.0, 'EA', 'US', 'MA');

        $this->assertSame(0.0, $result['dutyAmount'], 'fallback to chapter row must still resolve a duty, not fail');
    }

    public function testFtaSavingsWithoutAnyFtaFrameworkIsExactlyZero(): void
    {
        // MFN resolves (2.5% of 10,000 = 250) but no FTA rules exist in the
        // registry: the customer must be told NOT eligible with exactly 0
        // savings — never negative, never the MFN duty as "savings".
        // applyMfnRate queries the EXACT hs string — no fallback walk here.
        $this->seedTariff('8473.32', 'US', '2.5');
        $this->em->flush();
        $this->em->clear();

        $result = $this->service->calculateFtaSavings('8473.32', 10000.0, 100.0, 'EA', 'US', 'MA');

        $this->assertFalse($result['eligible']);
        $this->assertSame(250.0, $result['mfnDuty']);
        $this->assertSame(0, $result['savings']);
        $this->assertSame(0, $result['savingsPercent']);
    }

    public function testFtaSavingsWithoutMfnRowReportsErrorNotSavings(): void
    {
        $result = $this->service->calculateFtaSavings('9999.98', 10000.0, 100.0, 'EA', 'US', 'MA');

        $this->assertFalse($result['eligible']);
        $this->assertArrayHasKey('error', $result, 'no MFN baseline → savings are incomputable, reported as error');
        $this->assertArrayNotHasKey('savings', $result);
    }
}
