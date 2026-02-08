<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Lead;
use App\Message\LeadDeepScrapeMessage;
use App\MessageHandler\LeadDeepScrapeMessageHandler;
use App\Repository\LeadRepository;
use App\Service\DeepScrapingService;
use App\Service\LlmEnrichmentService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LeadDeepScrapeMessageHandlerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private DeepScrapingService $scrapingService;
    private LoggerInterface $logger;
    private LlmEnrichmentService $llmService;
    private LeadRepository $leadRepo;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->scrapingService = $this->createMock(DeepScrapingService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->llmService = $this->createMock(LlmEnrichmentService::class);
        $this->leadRepo = $this->createMock(LeadRepository::class);
        
        $this->entityManager->method('getRepository')
            ->with(Lead::class)
            ->willReturn($this->leadRepo);
    }

    private function createHandler(?LlmEnrichmentService $llmService = null): LeadDeepScrapeMessageHandler
    {
        return new LeadDeepScrapeMessageHandler(
            $this->entityManager,
            $this->scrapingService,
            $this->logger,
            $llmService
        );
    }

    private function createMessage(int $leadId = 1, string $url = 'https://test.com'): LeadDeepScrapeMessage
    {
        return new LeadDeepScrapeMessage($leadId, $url);
    }

    // ================================================================
    // Lead Not Found Tests
    // ================================================================

    public function testHandlerLogsWarningWhenLeadNotFound()
    {
        $this->leadRepo->method('find')->willReturn(null);
        
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Lead not found for deep scrape', $this->anything());
        
        // Should NOT call flush
        $this->entityManager->expects($this->never())->method('flush');
        
        $handler = $this->createHandler();
        $handler($this->createMessage(999));
    }

    // ================================================================
    // Successful Scrape Tests
    // ================================================================

    public function testHandlerSetsScrapingMetadataOnSuccess()
    {
        $lead = new Lead();
        $lead->setCompanyName('Test Corp');
        
        $this->leadRepo->method('find')->willReturn($lead);
        $this->entityManager->expects($this->once())->method('refresh')->with($lead);
        
        $this->scrapingService->method('scrapeWebsite')->willReturn([
            'emails' => ['info@test.com'],
            'phones' => ['+1234567890'],
            'contact_names' => ['John Doe'],
            'about_text' => 'A great company',
            'social_links' => [],
            'pages_scraped' => 3,
            'scraping_method' => 'panther',
            'has_contact_form' => true,
        ]);
        
        $this->entityManager->expects($this->once())->method('flush');
        
        $handler = $this->createHandler();
        $handler($this->createMessage());
        
        // Verify scraping metadata was set
        $this->assertEquals('panther', $lead->getScrapingMethod());
        $this->assertEquals(3, $lead->getPagesScraped());
        $this->assertNotNull($lead->getLastScrapedAt());
        $this->assertTrue($lead->hasContactForm());
    }

    public function testHandlerMergesEmailsWithExisting()
    {
        $lead = new Lead();
        $lead->setCompanyName('Test Corp');
        $lead->setContactEmailsPublic(['old@test.com']);
        
        $this->leadRepo->method('find')->willReturn($lead);
        
        $this->scrapingService->method('scrapeWebsite')->willReturn([
            'emails' => ['new@test.com', 'old@test.com'],
            'phones' => [],
            'contact_names' => [],
            'about_text' => '',
            'social_links' => [],
        ]);
        
        $this->entityManager->expects($this->once())->method('flush');
        
        $handler = $this->createHandler();
        $handler($this->createMessage());
        
        $emails = $lead->getContactEmailsPublic();
        $this->assertContains('old@test.com', $emails);
        $this->assertContains('new@test.com', $emails);
    }

    public function testHandlerBoostsScoreOnContactInfoFound()
    {
        $lead = new Lead();
        $lead->setCompanyName('Test Corp');
        $lead->setLeadScore(50);
        
        $this->leadRepo->method('find')->willReturn($lead);
        
        $this->scrapingService->method('scrapeWebsite')->willReturn([
            'emails' => ['info@test.com'],
            'phones' => ['+1234567890'],
            'contact_names' => ['John Doe'],
            'about_text' => '',
            'social_links' => [],
        ]);
        
        $this->entityManager->expects($this->once())->method('flush');
        
        $handler = $this->createHandler();
        $handler($this->createMessage());
        
        // Score should be boosted: +10 (emails) +5 (phones) +5 (names) = 70
        $this->assertEquals(70, $lead->getLeadScore());
    }

    public function testHandlerCapsScoreAt100()
    {
        $lead = new Lead();
        $lead->setCompanyName('Test Corp');
        $lead->setLeadScore(95);
        
        $this->leadRepo->method('find')->willReturn($lead);
        
        $this->scrapingService->method('scrapeWebsite')->willReturn([
            'emails' => ['info@test.com'],
            'phones' => ['+1234567890'],
            'contact_names' => ['John Doe'],
            'about_text' => '',
            'social_links' => [],
        ]);
        
        $handler = $this->createHandler();
        $handler($this->createMessage());
        
        $this->assertEquals(100, $lead->getLeadScore());
    }

    // ================================================================
    // Error Handling Tests
    // ================================================================

    public function testHandlerRecordsFailureInNotes()
    {
        $lead = new Lead();
        $lead->setCompanyName('Test Corp');
        
        $this->leadRepo->method('find')->willReturn($lead);
        
        $this->scrapingService->method('scrapeWebsite')
            ->willThrowException(new \RuntimeException('Connection failed'));
        
        $this->logger->expects($this->once())
            ->method('error')
            ->with('Deep scrape failed for lead', $this->anything());
        
        $this->entityManager->expects($this->once())->method('flush');
        
        $handler = $this->createHandler();
        $handler($this->createMessage());
        
        $this->assertStringContainsString('Deep scrape failed', $lead->getNotesAuto());
        $this->assertStringContainsString('Connection failed', $lead->getNotesAuto());
    }

    // ================================================================
    // Refresh / Race Condition Tests
    // ================================================================

    public function testHandlerRefreshesEntityBeforeProcessing()
    {
        $lead = new Lead();
        $lead->setCompanyName('Test Corp');
        
        $this->leadRepo->method('find')->willReturn($lead);
        
        // Entity manager refresh should be called
        $this->entityManager->expects($this->once())
            ->method('refresh')
            ->with($lead);
        
        $this->scrapingService->method('scrapeWebsite')->willReturn([
            'emails' => [],
            'phones' => [],
            'contact_names' => [],
            'about_text' => '',
            'social_links' => [],
        ]);
        
        $handler = $this->createHandler();
        $handler($this->createMessage());
    }
}
