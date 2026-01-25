<?php

namespace App\Tests\Unit\Service;

use App\Entity\Quote;
use App\Entity\BomLine;
use App\Repository\QuoteRepository;
use App\Service\InteractiveLiveQuoteService;
use App\Service\PricingEngine;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the Interactive Live Quote Service
 * 
 * Tests the interactive quote functionality including:
 * - Token generation and validation
 * - Tier pricing calculations
 * - Quote acceptance workflow
 * - View tracking
 */
class InteractiveLiveQuoteServiceTest extends TestCase
{
    private InteractiveLiveQuoteService $service;
    private EntityManagerInterface $entityManager;
    private QuoteRepository $quoteRepository;
    private PricingEngine $pricingEngine;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->quoteRepository = $this->createMock(QuoteRepository::class);
        $this->pricingEngine = $this->createMock(PricingEngine::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->service = new InteractiveLiveQuoteService(
            $this->entityManager,
            $this->quoteRepository,
            $this->pricingEngine,
            $this->logger
        );
    }

    /**
     * Helper to create a real Quote entity
     */
    private function createRealQuote(array $attributes = []): Quote
    {
        $quote = new Quote();
        $quote->setQuoteNumber($attributes['quote_number'] ?? 'Q-2024-001');
        $quote->setQuantity($attributes['quantity'] ?? 1000);
        $quote->setCurrency($attributes['currency'] ?? 'USD');
        $quote->setStatus($attributes['status'] ?? 'draft');
        
        if (isset($attributes['bom_data'])) {
            $quote->setBomDataJson(json_encode($attributes['bom_data']));
        }
        
        if (isset($attributes['interactive_enabled'])) {
            $quote->setInteractiveEnabled($attributes['interactive_enabled']);
        }
        
        if (isset($attributes['public_token'])) {
            $quote->setPublicToken($attributes['public_token']);
            $quote->setTokenExpiresAt(new \DateTime('+7 days'));
        }
        
        return $quote;
    }

    public function testEnableInteractiveModeGeneratesToken(): void
    {
        $quote = $this->createRealQuote(['quote_number' => 'Q-TEST-001']);
        
        $this->entityManager->expects($this->once())
            ->method('flush');
        
        $result = $this->service->enableInteractiveMode($quote);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertArrayHasKey('url', $result);
        $this->assertTrue($quote->isInteractiveEnabled());
        $this->assertNotNull($quote->getPublicToken());
        $this->assertNotNull($quote->getTokenExpiresAt());
    }

    public function testEnableInteractiveModeWithCustomExpiry(): void
    {
        $quote = $this->createRealQuote(['quote_number' => 'Q-TEST-002']);
        
        $this->entityManager->expects($this->once())
            ->method('flush');
        
        // null for quantityTiers, 7 for expirationDays
        $result = $this->service->enableInteractiveMode($quote, null, 7);
        
        $expiresAt = $quote->getTokenExpiresAt();
        $expectedExpiry = new \DateTime('+7 days');
        
        // Within 1 hour tolerance
        $this->assertLessThan(3600, abs($expiresAt->getTimestamp() - $expectedExpiry->getTimestamp()));
    }

    public function testEnableInteractiveModeWithCustomTiers(): void
    {
        $quote = $this->createRealQuote(['quote_number' => 'Q-TEST-003']);
        
        $this->entityManager->expects($this->once())
            ->method('flush');
        
        $customTiers = [50, 100, 500, 1000];
        $result = $this->service->enableInteractiveMode($quote, $customTiers);
        
        $this->assertArrayHasKey('quantity_tiers', $result);
        $this->assertEquals($customTiers, $quote->getQuantityOptions());
    }

    public function testDisableInteractiveModeClearsToken(): void
    {
        $quote = $this->createRealQuote(['quote_number' => 'Q-TEST-004']);
        $quote->generatePublicToken();
        $quote->setInteractiveEnabled(true);
        
        $this->entityManager->expects($this->once())
            ->method('flush');
        
        $this->service->disableInteractiveMode($quote);
        
        $this->assertFalse($quote->isInteractiveEnabled());
        $this->assertNull($quote->getPublicToken());
        $this->assertNull($quote->getTokenExpiresAt());
    }

    public function testGetQuoteByTokenReturnsQuoteWhenValid(): void
    {
        $token = bin2hex(random_bytes(32));
        
        $quote = $this->createRealQuote([
            'public_token' => $token,
            'interactive_enabled' => true,
        ]);
        
        $this->quoteRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['publicToken' => $token])
            ->willReturn($quote);
        
        // Need to allow flush for incrementViewCount
        $this->entityManager->method('flush');
        
        $result = $this->service->getQuoteByToken($token);
        
        $this->assertSame($quote, $result);
    }

    public function testGetQuoteByTokenReturnsNullWhenNotFound(): void
    {
        $token = bin2hex(random_bytes(32));
        
        $this->quoteRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['publicToken' => $token])
            ->willReturn(null);
        
        $result = $this->service->getQuoteByToken($token);
        
        $this->assertNull($result);
    }

    public function testGetQuoteByTokenReturnsNullWhenExpired(): void
    {
        $token = bin2hex(random_bytes(32));
        
        // Create a quote with expired token
        $quote = $this->createRealQuote(['interactive_enabled' => true]);
        $quote->setPublicToken($token);
        $quote->setTokenExpiresAt(new \DateTime('-1 day')); // Expired
        
        $this->quoteRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['publicToken' => $token])
            ->willReturn($quote);
        
        $result = $this->service->getQuoteByToken($token);
        
        $this->assertNull($result);
    }

    public function testCalculateTierPricingReturnsStructuredResult(): void
    {
        $quote = $this->createRealQuote([
            'quantity' => 1000,
            'bom_data' => [
                'lines' => [
                    ['mpn' => 'TEST-001', 'quantity' => 1, 'unit_price' => 5.00],
                    ['mpn' => 'TEST-002', 'quantity' => 2, 'unit_price' => 10.00],
                ]
            ],
        ]);
        $quote->setQuantityOptions([100, 500, 1000, 5000]);
        
        $result = $this->service->calculateTierPricing($quote);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('tiers', $result);
        $this->assertArrayHasKey('base_quantity', $result);
        $this->assertArrayHasKey('currency', $result);
    }

    public function testRequestQuoteAtQuantityCreatesRequest(): void
    {
        $quote = $this->createRealQuote([
            'quote_number' => 'Q-2024-042',
            'bom_data' => [
                'lines' => [
                    ['mpn' => 'TEST-001', 'quantity' => 1, 'unit_price' => 5.00],
                ]
            ],
        ]);
        
        $this->logger->expects($this->atLeastOnce())
            ->method('info');
        
        $result = $this->service->requestQuoteAtQuantity($quote, 5000, 'Need expedited delivery');
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('quote_number', $result);
        $this->assertArrayHasKey('requested_quantity', $result);
        $this->assertArrayHasKey('estimated_unit_price', $result);
        $this->assertArrayHasKey('estimated_total', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('next_steps', $result);
        $this->assertEquals(5000, $result['requested_quantity']);
        $this->assertEquals('request_received', $result['status']);
    }

    public function testAcceptQuoteReturnsAcceptanceInfo(): void
    {
        $quote = $this->createRealQuote([
            'quote_number' => 'Q-ACCEPT-001',
            'quantity' => 1000,
            'bom_data' => ['lines' => [['mpn' => 'TEST', 'quantity' => 1, 'unit_price' => 10.0]]]
        ]);
        
        $this->entityManager->expects($this->once())
            ->method('flush');
        
        $this->logger->expects($this->atLeastOnce())
            ->method('info');
        
        $customerInfo = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '+1-555-1234',
            'po_number' => 'PO-2024-001',
        ];
        
        $result = $this->service->acceptQuote($quote, 1000, $customerInfo);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('quote_number', $result);
        $this->assertArrayHasKey('accepted_quantity', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('next_steps', $result);
        $this->assertEquals('accepted', $result['status']);
        $this->assertEquals(1000, $result['accepted_quantity']);
    }

    public function testTokenGenerationIsSecure(): void
    {
        $quote1 = $this->createRealQuote(['quote_number' => 'Q-1']);
        $quote2 = $this->createRealQuote(['quote_number' => 'Q-2']);
        
        $quote1->generatePublicToken();
        $quote2->generatePublicToken();
        
        // Tokens should be unique
        $this->assertNotEquals($quote1->getPublicToken(), $quote2->getPublicToken());
        
        // Token should be 64 hex characters (256 bits)
        $this->assertEquals(64, strlen($quote1->getPublicToken()));
        $this->assertEquals(64, strlen($quote2->getPublicToken()));
        
        // Token should be hexadecimal
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $quote1->getPublicToken());
    }

    public function testViewCountIncrementsOnAccess(): void
    {
        $quote = $this->createRealQuote(['quote_number' => 'Q-VIEW-TEST']);
        
        $initialCount = $quote->getViewCount();
        $quote->incrementViewCount();
        
        $this->assertEquals($initialCount + 1, $quote->getViewCount());
        $this->assertNotNull($quote->getLastViewedAt());
    }

    public function testQuantityOptionsAreStored(): void
    {
        $quote = $this->createRealQuote(['quote_number' => 'Q-OPTIONS-TEST']);
        
        $options = [100, 250, 500, 1000, 2500];
        $quote->setQuantityOptions($options);
        
        $this->assertEquals($options, $quote->getQuantityOptions());
    }

    public function testInteractiveEnabledFlagWorks(): void
    {
        $quote = $this->createRealQuote();
        
        $this->assertFalse($quote->isInteractiveEnabled());
        
        $quote->setInteractiveEnabled(true);
        $this->assertTrue($quote->isInteractiveEnabled());
        
        $quote->setInteractiveEnabled(false);
        $this->assertFalse($quote->isInteractiveEnabled());
    }

    public function testTokenValidationCheckExpiration(): void
    {
        $quote = $this->createRealQuote();
        
        // Token without expiration should be invalid
        $quote->setPublicToken(bin2hex(random_bytes(32)));
        $quote->setTokenExpiresAt(null);
        $this->assertFalse($quote->isTokenValid());
        
        // Expired token should be invalid
        $quote->setTokenExpiresAt(new \DateTime('-1 hour'));
        $this->assertFalse($quote->isTokenValid());
        
        // Future expiration should be valid
        $quote->setTokenExpiresAt(new \DateTime('+1 hour'));
        $this->assertTrue($quote->isTokenValid());
    }
}
