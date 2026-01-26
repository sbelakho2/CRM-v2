<?php

namespace App\Tests\Unit\Service;

use App\Entity\Lead;
use App\Entity\Quote;
use App\Entity\Activity;
use App\Entity\PriceHistory;
use App\Repository\LeadRepository;
use App\Repository\QuoteRepository;
use App\Repository\ActivityRepository;
use App\Repository\PriceHistoryRepository;
use App\Service\CommandCenterService;
use App\Service\CurrencyConverter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the Command Center Service
 * 
 * Tests the Command Center dashboard functionality including:
 * - Lead inflow data aggregation
 * - Quotes status tracking
 * - Supply alerts monitoring
 * - Key metrics calculation
 */
class CommandCenterServiceTest extends TestCase
{
    private CommandCenterService $service;
    private EntityManagerInterface $entityManager;
    private LeadRepository $leadRepository;
    private QuoteRepository $quoteRepository;
    private ActivityRepository $activityRepository;
    private LoggerInterface $logger;
    private CurrencyConverter $currencyConverter;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->leadRepository = $this->createMock(LeadRepository::class);
        $this->quoteRepository = $this->createMock(QuoteRepository::class);
        $this->activityRepository = $this->createMock(ActivityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->currencyConverter = $this->createMock(CurrencyConverter::class);
        
        // Mock currency converter to return the same value (no conversion)
        $this->currencyConverter->method('convert')
            ->willReturnCallback(fn($amount) => $amount);
        
        // Mock the getRepository method for PriceHistory
        $priceHistoryRepo = $this->createMock(PriceHistoryRepository::class);
        $priceHistoryRepo->method('createQueryBuilder')
            ->willReturnCallback(function() {
                return $this->createMockQueryBuilder([]);
            });
        
        $this->entityManager->method('getRepository')
            ->willReturn($priceHistoryRepo);
        
        $this->service = new CommandCenterService(
            $this->entityManager,
            $this->leadRepository,
            $this->quoteRepository,
            $this->activityRepository,
            $this->logger,
            $this->currencyConverter
        );
    }

    public function testGetCommandCenterDataReturnsAllSections(): void
    {
        // Setup mock repositories - need to handle both scalar and array results
        $this->leadRepository->method('createQueryBuilder')
            ->willReturnCallback(function() {
                return $this->createMockQueryBuilderWithBoth(0, []);
            });
        
        $this->quoteRepository->method('createQueryBuilder')
            ->willReturnCallback(function() {
                return $this->createMockQueryBuilderWithBoth(0, []);
            });
        
        $this->activityRepository->method('createQueryBuilder')
            ->willReturnCallback(function() {
                return $this->createMockQueryBuilderWithBoth(0, []);
            });
        
        $result = $this->service->getCommandCenterData();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('lead_inflow', $result);
        $this->assertArrayHasKey('quotes_status', $result);
        $this->assertArrayHasKey('supply_alerts', $result);
        $this->assertArrayHasKey('activity_feed', $result);
        $this->assertArrayHasKey('key_metrics', $result);
    }

    public function testGetLeadInflowDataReturnsExpectedStructure(): void
    {
        $this->leadRepository->method('createQueryBuilder')
            ->willReturnCallback(function() {
                return $this->createMockQueryBuilderWithBoth(0, []);
            });
        
        $result = $this->service->getLeadInflowData();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('today', $result['summary']);
        $this->assertArrayHasKey('this_week', $result['summary']);
        $this->assertArrayHasKey('pending_review', $result['summary']);
        $this->assertArrayHasKey('by_region', $result);
        $this->assertArrayHasKey('high_priority_leads', $result);
    }

    public function testGetQuotesStatusDataReturnsExpectedStructure(): void
    {
        $this->quoteRepository->method('createQueryBuilder')
            ->willReturnCallback(function() {
                return $this->createMockQueryBuilderWithBoth(0, []);
            });
        
        $result = $this->service->getQuotesStatusData();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('pending_approval', $result['summary']);
        $this->assertArrayHasKey('active_interactive', $result['summary']);
        $this->assertArrayHasKey('high_value_count', $result['summary']);
        $this->assertArrayHasKey('pipeline_value', $result['summary']);
        $this->assertArrayHasKey('by_status', $result);
    }

    public function testGetSupplyAlertsDataReturnsExpectedStructure(): void
    {
        $this->quoteRepository->method('createQueryBuilder')
            ->willReturnCallback(function() {
                return $this->createMockQueryBuilderWithBoth(0, []);
            });
        
        $result = $this->service->getSupplyAlertsData();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('total_alerts', $result['summary']);
        $this->assertArrayHasKey('critical', $result['summary']);
        $this->assertArrayHasKey('warning', $result['summary']);
        $this->assertArrayHasKey('alerts', $result);
    }

    public function testGetKeyMetricsReturnsExpectedStructure(): void
    {
        $this->leadRepository->method('createQueryBuilder')
            ->willReturnCallback(function() {
                return $this->createMockQueryBuilderWithBoth(0, []);
            });
        
        $this->quoteRepository->method('createQueryBuilder')
            ->willReturnCallback(function() {
                return $this->createMockQueryBuilderWithBoth(0, []);
            });
        
        $result = $this->service->getKeyMetrics();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('lead_conversion_rate', $result);
        $this->assertArrayHasKey('quote_win_rate', $result);
        $this->assertArrayHasKey('avg_quote_value', $result);
        $this->assertArrayHasKey('pipeline_total', $result);
        $this->assertArrayHasKey('leads_this_week', $result);
        $this->assertArrayHasKey('quotes_this_week', $result);
    }

    public function testGetAlertCountReturnsInteger(): void
    {
        $this->quoteRepository->method('createQueryBuilder')
            ->willReturnCallback(function() {
                return $this->createMockQueryBuilderWithBoth(0, []);
            });
        
        $result = $this->service->getAlertCount();
        
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(0, $result);
    }

    public function testGetRecentActivityFeedReturnsArray(): void
    {
        $this->activityRepository->method('createQueryBuilder')
            ->willReturnCallback(function() {
                return $this->createMockQueryBuilderWithBoth(0, []);
            });
        
        $result = $this->service->getRecentActivityFeed();
        
        $this->assertIsArray($result);
    }

    public function testGetActionItemsReturnsArray(): void
    {
        $this->leadRepository->method('createQueryBuilder')
            ->willReturnCallback(function() {
                return $this->createMockQueryBuilderWithBoth(0, []);
            });
        
        $this->quoteRepository->method('createQueryBuilder')
            ->willReturnCallback(function() {
                return $this->createMockQueryBuilderWithBoth(0, []);
            });
        
        $result = $this->service->getActionItems();
        
        $this->assertIsArray($result);
    }

    public function testLeadInflowSummaryHasNumericValues(): void
    {
        $this->leadRepository->method('createQueryBuilder')
            ->willReturnCallback(function() {
                return $this->createMockQueryBuilderWithBoth(5, []);
            });
        
        $result = $this->service->getLeadInflowData();
        
        $this->assertIsInt($result['summary']['today']);
        $this->assertIsInt($result['summary']['this_week']);
        $this->assertIsInt($result['summary']['pending_review']);
    }

    public function testQuotesStatusSummaryHasNumericValues(): void
    {
        $this->quoteRepository->method('createQueryBuilder')
            ->willReturnCallback(function() {
                return $this->createMockQueryBuilderWithBoth(0, []);
            });
        
        $result = $this->service->getQuotesStatusData();
        
        $this->assertIsInt($result['summary']['pending_approval']);
        $this->assertIsInt($result['summary']['active_interactive']);
        $this->assertIsFloat($result['summary']['pipeline_value']);
    }

    public function testKeyMetricsHasCorrectTypes(): void
    {
        $this->leadRepository->method('createQueryBuilder')
            ->willReturnCallback(function() {
                return $this->createMockQueryBuilderWithBoth(0, []);
            });
        
        $this->quoteRepository->method('createQueryBuilder')
            ->willReturnCallback(function() {
                return $this->createMockQueryBuilderWithBoth(0, []);
            });
        
        $result = $this->service->getKeyMetrics();
        
        $this->assertIsNumeric($result['lead_conversion_rate']);
        $this->assertIsNumeric($result['quote_win_rate']);
        $this->assertIsNumeric($result['avg_quote_value']);
        $this->assertIsNumeric($result['pipeline_total']);
        $this->assertIsInt($result['leads_this_week']);
        $this->assertIsInt($result['quotes_this_week']);
    }

    public function testSupplyAlertsSummaryCountsAreNonNegative(): void
    {
        $this->quoteRepository->method('createQueryBuilder')
            ->willReturnCallback(function() {
                return $this->createMockQueryBuilderWithBoth(0, []);
            });
        
        $result = $this->service->getSupplyAlertsData();
        
        $this->assertGreaterThanOrEqual(0, $result['summary']['total_alerts']);
        $this->assertGreaterThanOrEqual(0, $result['summary']['critical']);
        $this->assertGreaterThanOrEqual(0, $result['summary']['warning']);
    }

    /**
     * Helper to create a mock query builder that handles both scalar and array results
     */
    private function createMockQueryBuilderWithBoth(mixed $scalarValue, array $arrayValue)
    {
        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $query = $this->createMock(\Doctrine\ORM\AbstractQuery::class);
        
        $qb->method('select')->willReturn($qb);
        $qb->method('from')->willReturn($qb);
        $qb->method('where')->willReturn($qb);
        $qb->method('andWhere')->willReturn($qb);
        $qb->method('orWhere')->willReturn($qb);
        $qb->method('setParameter')->willReturn($qb);
        $qb->method('orderBy')->willReturn($qb);
        $qb->method('addOrderBy')->willReturn($qb);
        $qb->method('setMaxResults')->willReturn($qb);
        $qb->method('groupBy')->willReturn($qb);
        $qb->method('join')->willReturn($qb);
        $qb->method('leftJoin')->willReturn($qb);
        $qb->method('getQuery')->willReturn($query);
        
        // Return array for getResult, scalar for getSingleScalarResult
        $query->method('getResult')->willReturn($arrayValue);
        $query->method('getArrayResult')->willReturn($arrayValue);
        $query->method('getSingleScalarResult')->willReturn($scalarValue);
        
        return $qb;
    }

    /**
     * @deprecated Use createMockQueryBuilderWithBoth instead
     */
    private function createMockQueryBuilder($returnValue)
    {
        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $query = $this->createMock(\Doctrine\ORM\AbstractQuery::class);
        
        $qb->method('select')->willReturn($qb);
        $qb->method('from')->willReturn($qb);
        $qb->method('where')->willReturn($qb);
        $qb->method('andWhere')->willReturn($qb);
        $qb->method('orWhere')->willReturn($qb);
        $qb->method('setParameter')->willReturn($qb);
        $qb->method('orderBy')->willReturn($qb);
        $qb->method('addOrderBy')->willReturn($qb);
        $qb->method('setMaxResults')->willReturn($qb);
        $qb->method('groupBy')->willReturn($qb);
        $qb->method('join')->willReturn($qb);
        $qb->method('leftJoin')->willReturn($qb);
        $qb->method('getQuery')->willReturn($query);
        
        if (is_array($returnValue)) {
            $query->method('getResult')->willReturn($returnValue);
            $query->method('getArrayResult')->willReturn($returnValue);
        } else {
            $query->method('getSingleScalarResult')->willReturn($returnValue);
        }
        
        return $qb;
    }
}
