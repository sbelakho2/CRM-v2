<?php

namespace App\Tests\Unit\Service;

use App\Entity\FxRate;
use App\Service\CurrencyConversionService;
use App\Service\CurrencyConverter;
use App\Service\CurrencyPreferenceService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Critical-surface coverage: the TWO conversion grades. The round-8 audit
 * proved the difference is money: convertForDisplay() may degrade to the
 * unconverted amount (a display nuisance), while convertOrFail() must
 * THROW so financial ranking (freight, landed cost) can EXCLUDE a
 * candidate whose currency cannot be normalized — silently comparing
 * €900 to $950 1:1 is the exact bug the split exists to prevent.
 *
 * Runs against real fx_rates rows on the throwaway MySQL, with the live
 * FX fetcher disabled so expectations are deterministic.
 */
class CurrencyConverterGradesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CurrencyConverter $converter;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->em->getConnection()->executeStatement('TRUNCATE TABLE fx_rates');

        $preference = new CurrencyPreferenceService(
            self::getContainer()->get(Security::class)
        );
        // No live fetcher: unknown pairs are genuinely unknown.
        $conversion = new CurrencyConversionService(
            $this->em->getRepository(FxRate::class),
            new NullLogger(),
            null
        );
        $this->converter = new CurrencyConverter($conversion, $preference, new NullLogger());
    }

    private function seedFx(string $from, string $to, string $rate): void
    {
        $fx = new FxRate();
        $fx->setFromCurrency($from);
        $fx->setToCurrency($to);
        $fx->setRate($rate);
        $fx->setAsof(new \DateTime());
        $this->em->persist($fx);
        $this->em->flush();
        $this->em->clear();
    }

    public function testConvertOrFailResolvesDatabaseRatesExactly(): void
    {
        $this->seedFx('EUR', 'USD', '1.10');

        $this->assertSame(110.0, $this->converter->convertOrFail(100.0, 'EUR', 'USD'));
    }

    public function testConvertOrFailResolvesInverseStoredRates(): void
    {
        // Only JPY→USD is stored; USD→JPY must derive 1/rate.
        $this->seedFx('JPY', 'USD', '0.0065');

        $this->assertSame(1000.0, $this->converter->convertOrFail(6.5, 'USD', 'JPY'), 'inverse rate: 6.5 USD ÷ 0.0065 = 1000 JPY');
    }

    public function testConvertOrFailThrowsOnUnknownPair(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No exchange rate available');
        $this->converter->convertOrFail(100.0, 'ZZZ', 'USD');
    }

    public function testConvertOrFailSameCurrencyIsIdentity(): void
    {
        $this->assertSame(42.5, $this->converter->convertOrFail(42.5, 'MAD', 'MAD'));
    }

    public function testConvertForDisplayDegradesGracefullyOnUnknownPair(): void
    {
        // Presentation grade: returns the UNCONVERTED amount instead of
        // throwing — a wrong number on screen is recoverable, a wrong
        // number in a ranking is not.
        $this->assertSame(100.0, $this->converter->convertForDisplay(100.0, 'ZZZ', 'USD'));
    }

    public function testConvertForDisplayMatchesBusinessGradeWhenResolvable(): void
    {
        $this->seedFx('EUR', 'USD', '1.10');

        $this->assertSame(110.0, $this->converter->convertForDisplay(100.0, 'EUR', 'USD'));
    }
}
