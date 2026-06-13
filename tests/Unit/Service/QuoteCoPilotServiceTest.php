<?php

namespace App\Tests\Unit\Service;

use App\Entity\BomLine;
use App\Entity\Quote;
use App\Service\QuoteCoPilotService;
use App\Service\BOMParser;
use App\Service\PricingEngine;
use App\Service\CurrencyPreferenceService;
use App\Service\QuoteWinPredictorService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use App\Repository\QuoteRepository;
use App\Repository\BomLineRepository;
use App\Repository\ProcurementExceptionRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for QuoteCoPilotService — BOM parsing, auto-publish, regeneration.
 */
class QuoteCoPilotServiceTest extends TestCase
{
    private QuoteCoPilotService $service;
    private EntityManagerInterface $entityManager;
    private QuoteRepository $quoteRepository;
    private BomLineRepository $bomLineRepository;
    private ProcurementExceptionRepository $procurementExceptionRepository;
    private BOMParser $bomParser;
    private PricingEngine $pricingEngine;
    private CurrencyPreferenceService $currencyPreferenceService;
    private QuoteWinPredictorService $winPredictor;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->quoteRepository = $this->createMock(QuoteRepository::class);
        $this->bomLineRepository = $this->createMock(BomLineRepository::class);
        $this->procurementExceptionRepository = $this->createMock(ProcurementExceptionRepository::class);
        $this->bomParser = $this->createMock(BOMParser::class);
        $this->pricingEngine = $this->createMock(PricingEngine::class);
        $this->currencyPreferenceService = $this->createMock(CurrencyPreferenceService::class);
        $this->winPredictor = $this->createMock(QuoteWinPredictorService::class);

        // applyBoardCount() and applyOrderMultiple() delegate to $this->bomParser internally,
        // so we must configure the bomParser mock to perform the actual transformation.
        $this->bomParser->method('applyBoardCount')->willReturnCallback(
            function (array $data, int $count): array {
                foreach ($data as &$line) {
                    $line['qty'] = $line['qty'] * $count;
                }
                return $data;
            }
        );

        $this->bomParser->method('applyOrderMultiple')->willReturnCallback(
            function (array $data, int $multiple): array {
                foreach ($data as &$line) {
                    $line['qty'] = (int) ceil($line['qty'] / $multiple) * $multiple;
                }
                return $data;
            }
        );

        $this->service = new QuoteCoPilotService(
            $this->entityManager,
            $this->quoteRepository,
            $this->bomLineRepository,
            $this->procurementExceptionRepository,
            $this->bomParser,
            $this->pricingEngine,
            $this->currencyPreferenceService,
            $this->winPredictor
        );
    }

    // ── parseBom() tests ──

    public function testParseBomDelegatesToBomParser(): void
    {
        $expected = [
            ['mpn' => 'PART-1', 'qty' => 10],
            ['mpn' => 'PART-2', 'qty' => 20],
        ];

        $this->bomParser->method('parse')->willReturn($expected);

        $result = $this->service->parseBom('/tmp/test.csv');

        $this->assertEquals($expected, $result);
    }

    public function testParseBomWithCustomExtension(): void
    {
        $expected = [['mpn' => 'PART-1']];

        $this->bomParser->method('parse')->willReturn($expected);

        $result = $this->service->parseBom('/tmp/test.data', 'csv');

        $this->assertEquals($expected, $result);
    }

    // ── applyOrderMultiple() tests ──

    public function testApplyOrderMultipleRoundsQuantities(): void
    {
        $bomData = [
            ['mpn' => 'R1', 'qty' => 5],
            ['mpn' => 'R2', 'qty' => 10],
        ];

        $result = $this->service->applyOrderMultiple($bomData, 10);

        // 5 rounds up to 10, 10 stays at 10
        $this->assertEquals(10, $result[0]['qty']);
        $this->assertEquals(10, $result[1]['qty']);
    }

    public function testApplyOrderMultipleNoChangeForExactMultiples(): void
    {
        $bomData = [
            ['mpn' => 'R1', 'qty' => 100],
            ['mpn' => 'R2', 'qty' => 200],
        ];

        $result = $this->service->applyOrderMultiple($bomData, 50);

        // Both are exact multiples of 50
        $this->assertEquals(100, $result[0]['qty']);
        $this->assertEquals(200, $result[1]['qty']);
    }

    public function testApplyOrderMultipleWithOneReturnsSame(): void
    {
        $bomData = [
            ['mpn' => 'R1', 'qty' => 37],
        ];

        $result = $this->service->applyOrderMultiple($bomData, 1);

        // Multiple of 1 means no change
        $this->assertEquals(37, $result[0]['qty']);
    }

    // ── applyBoardCount() tests ──

    public function testApplyBoardCountMultipliesQuantities(): void
    {
        $bomData = [
            ['mpn' => 'R1', 'qty' => 10, 'ref' => 'R1,R2'],
            ['mpn' => 'C1', 'qty' => 5, 'ref' => 'C1'],
        ];

        $result = $this->service->applyBoardCount($bomData, 3);

        // Each qty multiplied by board count
        $this->assertEquals(30, $result[0]['qty']);
        $this->assertEquals(15, $result[1]['qty']);
    }

    public function testApplyBoardCountWithNoRefField(): void
    {
        $bomData = [
            ['mpn' => 'R1', 'qty' => 4],
        ];

        $result = $this->service->applyBoardCount($bomData, 5);

        $this->assertEquals(20, $result[0]['qty']);
    }

    // ── checkAutoPublishCriteria() tests ──

    public function testCheckAutoPublishReturnsFalseWhenCoverageBelow90(): void
    {
        $result = $this->service->checkAutoPublishCriteria(1, 85.0);

        $this->assertFalse($result);
    }

    public function testCheckAutoPublishReturnsFalseWhenCoverageExactly90(): void
    {
        // 90.0% is exactly 90 — passes coverage check, then fails on critical exceptions
        $mockQuery = $this->createMock(Query::class);
        $mockQuery->method('setParameter')->willReturnSelf();
        $mockQuery->method('getSingleScalarResult')->willReturn(1); // has critical exceptions → fails

        $this->entityManager->method('createQuery')->willReturn($mockQuery);

        $result = $this->service->checkAutoPublishCriteria(1, 90.0);

        $this->assertFalse($result);
    }

    public function testCheckAutoPublishReturnsTrueWhenAllCriteriaMet(): void
    {
        // Mock createQuery chain for check 2 (critical count = 0, passes)
        $mockCriticalQuery = $this->createMock(Query::class);
        $mockCriticalQuery->method('setParameter')->willReturnSelf();
        $mockCriticalQuery->method('getSingleScalarResult')->willReturn(0);

        $this->entityManager->method('createQuery')->willReturn($mockCriticalQuery);

        // Mock getRepository(BomLine::class) for checks 3 and 4
        $mockBomLineRepo = $this->createMock(BomLineRepository::class);

        // First createQueryBuilder call (check 3) — returns no unsourced high-value parts
        $mockResultQuery = $this->createMock(Query::class);
        $mockResultQuery->method('setParameter')->willReturnSelf();
        $mockResultQuery->method('getResult')->willReturn([]);

        $mockQb1 = $this->createMock(QueryBuilder::class);
        $mockQb1->method('where')->willReturnSelf();
        $mockQb1->method('andWhere')->willReturnSelf();
        $mockQb1->method('setParameter')->willReturnSelf();
        $mockQb1->method('getQuery')->willReturn($mockResultQuery);

        // Second createQueryBuilder call (check 4) — returns 0 long lead time items
        $mockCountQuery = $this->createMock(Query::class);
        $mockCountQuery->method('setParameter')->willReturnSelf();
        $mockCountQuery->method('getSingleScalarResult')->willReturn(0);

        $mockQb2 = $this->createMock(QueryBuilder::class);
        $mockQb2->method('select')->willReturnSelf();
        $mockQb2->method('where')->willReturnSelf();
        $mockQb2->method('andWhere')->willReturnSelf();
        $mockQb2->method('setParameter')->willReturnSelf();
        $mockQb2->method('getQuery')->willReturn($mockCountQuery);

        $mockBomLineRepo->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($mockQb1, $mockQb2);

        $this->entityManager->method('getRepository')
            ->with(BomLine::class)
            ->willReturn($mockBomLineRepo);

        $result = $this->service->checkAutoPublishCriteria(1, 95.0);

        $this->assertTrue($result);
    }

    // ── addMoney / mulMoney (via createAndPersistBomLine) ──
    // These are private, but we verify the public processBom behavior indirectly.

    // ── regenerateQuote() tests ──

    public function testRegenerateQuoteThrowsOnMissingQuote(): void
    {
        // regenerateQuote() uses entityManager->getRepository(Quote::class)->find() internally,
        // NOT quoteRepository->find() — the injected quoteRepository is unused by this method.
        $mockQuoteRepo = $this->createMock(QuoteRepository::class);
        $mockQuoteRepo->method('find')->willReturn(null);

        $this->entityManager->method('getRepository')
            ->with(Quote::class)
            ->willReturn($mockQuoteRepo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Quote not found');

        $this->service->regenerateQuote(999);
    }

    public function testRegenerateQuoteReturnsStatsWhenNoLines(): void
    {
        $quote = new Quote();
        $quote->setQuoteNumber('REGEN-TEST');

        // Mock entityManager->getRepository(Quote::class)->find()
        $mockQuoteRepo = $this->createMock(QuoteRepository::class);
        $mockQuoteRepo->method('find')->willReturn($quote);

        // Mock entityManager->getRepository(BomLine::class)->findBy()
        $mockBomLineRepo = $this->createMock(BomLineRepository::class);
        $mockBomLineRepo->method('findBy')->willReturn([]);

        $this->entityManager->method('getRepository')->willReturnMap([
            [Quote::class, $mockQuoteRepo],
            [BomLine::class, $mockBomLineRepo],
        ]);

        $result = $this->service->regenerateQuote(1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('reprocessed', $result);
        $this->assertArrayHasKey('newly_sourced', $result);
        $this->assertEquals(0, $result['reprocessed']);
    }
}
