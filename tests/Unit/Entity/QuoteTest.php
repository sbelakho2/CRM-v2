<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\Quote;
use App\Entity\RFQ;
use PHPUnit\Framework\TestCase;

class QuoteTest extends TestCase
{
    private Quote $quote;

    protected function setUp(): void
    {
        $this->quote = new Quote();
    }

    public function testQuoteInitialState(): void
    {
        $this->assertNull($this->quote->getId());
        $this->assertNull($this->quote->getCompany());
        $this->assertEquals('draft', $this->quote->getStatus());
        $this->assertEquals('0.00', $this->quote->getTotalCost());
        $this->assertEquals('USD', $this->quote->getCurrency());
        $this->assertFalse($this->quote->isAutoPublished());
        $this->assertFalse($this->quote->isInteractiveEnabled());
    }

    // ============================================================
    // FIX VERIFICATION: generateQuoteNumber uses uniqid
    // ============================================================

    public function testQuoteNumberGeneratedOnConstruction(): void
    {
        $quote = new Quote();
        $this->assertNotNull($quote->getQuoteNumber());
        $this->assertStringStartsWith('QTE-' . date('Y') . '-', $quote->getQuoteNumber());
    }

    public function testQuoteNumberIsUnique(): void
    {
        $numbers = [];
        for ($i = 0; $i < 50; $i++) {
            $quote = new Quote();
            $number = $quote->getQuoteNumber();
            $this->assertNotContains($number, $numbers, "Duplicate quote number: {$number}");
            $numbers[] = $number;
        }
    }

    public function testQuoteNumberFormat(): void
    {
        $quote = new Quote();
        $number = $quote->getQuoteNumber();
        // Format: QTE-YYYY-XXXXXX (6 hex chars from uniqid)
        $this->assertMatchesRegularExpression('/^QTE-\d{4}-[A-Z0-9]{6}$/', $number);
    }

    // ============================================================
    // FIX VERIFICATION: apiVersions is array (json type)
    // ============================================================

    public function testApiVersionsMutator(): void
    {
        $versions = ['mouser' => 'v1.2', 'digikey' => 'v3.0', 'lcsc' => 'v2.1'];
        $this->quote->setApiVersions($versions);
        $this->assertEquals($versions, $this->quote->getApiVersions());
        $this->assertIsArray($this->quote->getApiVersions());
    }

    public function testApiVersionsNull(): void
    {
        $this->assertNull($this->quote->getApiVersions());
        $this->quote->setApiVersions(null);
        $this->assertNull($this->quote->getApiVersions());
    }

    // ============================================================
    // FIX VERIFICATION: PreUpdate lifecycle callback
    // ============================================================

    public function testOnPreUpdateSetsUpdatedAt(): void
    {
        $this->assertNull($this->quote->getUpdatedAt());
        $this->quote->onPreUpdate();
        $this->assertInstanceOf(\DateTimeInterface::class, $this->quote->getUpdatedAt());
    }

    // ============================================================
    // Interactive Live Quote tests
    // ============================================================

    public function testGeneratePublicToken(): void
    {
        $this->assertNull($this->quote->getPublicToken());
        $this->quote->generatePublicToken();
        $this->assertNotNull($this->quote->getPublicToken());
        $this->assertEquals(64, strlen($this->quote->getPublicToken())); // 32 bytes = 64 hex chars
        $this->assertNotNull($this->quote->getTokenExpiresAt());
    }

    public function testTokenExpirationDefault30Days(): void
    {
        $this->quote->generatePublicToken();
        $expiresAt = $this->quote->getTokenExpiresAt();
        $now = new \DateTime();
        $diff = $now->diff($expiresAt);
        $this->assertGreaterThanOrEqual(29, $diff->days);
        $this->assertLessThanOrEqual(31, $diff->days);
    }

    public function testIsTokenValidWhenValid(): void
    {
        $this->quote->generatePublicToken(30);
        $this->assertTrue($this->quote->isTokenValid());
    }

    public function testIsTokenValidWhenExpired(): void
    {
        $this->quote->setPublicToken('test-token');
        $this->quote->setTokenExpiresAt(new \DateTime('-1 day'));
        $this->assertFalse($this->quote->isTokenValid());
    }

    public function testIsTokenValidWhenNoToken(): void
    {
        $this->assertFalse($this->quote->isTokenValid());
    }

    public function testIncrementViewCount(): void
    {
        $this->assertEquals(0, $this->quote->getViewCount());
        $this->quote->incrementViewCount();
        $this->assertEquals(1, $this->quote->getViewCount());
        $this->assertNotNull($this->quote->getLastViewedAt());
    }

    public function testQuantityOptionsMutator(): void
    {
        $options = [100, 500, 1000, 5000];
        $this->quote->setQuantityOptions($options);
        $this->assertEquals($options, $this->quote->getQuantityOptions());
    }

    // ============================================================
    // Standard field tests
    // ============================================================

    public function testCompanyMutator(): void
    {
        $company = new Company();
        $company->setName('Test Co');
        $this->quote->setCompany($company);
        $this->assertEquals($company, $this->quote->getCompany());
    }

    public function testContactMutator(): void
    {
        $contact = new Contact();
        $contact->setFirstName('John');
        $contact->setLastName('Doe');
        $this->quote->setContact($contact);
        $this->assertEquals($contact, $this->quote->getContact());
    }

    public function testRfqMutator(): void
    {
        $rfq = new RFQ();
        $this->quote->setRfq($rfq);
        $this->assertEquals($rfq, $this->quote->getRfq());
    }

    public function testStatusMutator(): void
    {
        $this->quote->setStatus('approved');
        $this->assertEquals('approved', $this->quote->getStatus());
    }

    public function testTotalCostMutator(): void
    {
        $this->quote->setTotalCost('25000.50');
        $this->assertEquals('25000.50', $this->quote->getTotalCost());
    }

    public function testShipToCountryMutator(): void
    {
        $this->quote->setShipToCountry('US');
        $this->assertEquals('US', $this->quote->getShipToCountry());
    }

    public function testIncotermsMutator(): void
    {
        $this->quote->setIncoterms('DDP');
        $this->assertEquals('DDP', $this->quote->getIncoterms());
    }

    public function testCoveragePercentMutator(): void
    {
        $this->quote->setCoveragePercent('85.50');
        $this->assertEquals('85.50', $this->quote->getCoveragePercent());
    }

    public function testCreatedAtSetOnConstruction(): void
    {
        $this->assertInstanceOf(\DateTimeInterface::class, $this->quote->getCreatedAt());
    }

    public function testUpdatedAtMutator(): void
    {
        $date = new \DateTime('2025-06-01');
        $this->quote->setUpdatedAt($date);
        $this->assertEquals($date, $this->quote->getUpdatedAt());
    }

    public function testPartBreakdownsCollectionInitialized(): void
    {
        $this->assertCount(0, $this->quote->getPartBreakdowns());
    }

    public function testBomLinesCollectionInitialized(): void
    {
        $this->assertCount(0, $this->quote->getBomLines());
    }
}
