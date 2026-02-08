<?php

namespace App\Tests\Unit;

use App\Entity\Activity;
use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\Quote;
use App\Entity\RFQ;
use App\Entity\RfqLineItem;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive test suite verifying all bug fixes from the CRM audit.
 * 
 * Each test method documents which audit issue it verifies.
 */
class BugFixVerificationTest extends TestCase
{
    // ============================================================
    // CRITICAL: RFQ ↔ RfqLineItem OneToMany relationship
    // Audit Issue: RfqLineItem declares inversedBy: 'lineItems' on 
    // ManyToOne to RFQ but RFQ entity had no matching $lineItems collection
    // ============================================================

    public function testRfqHasLineItemsCollection(): void
    {
        $rfq = new RFQ();
        $this->assertCount(0, $rfq->getLineItems());
    }

    public function testRfqCanAddAndRemoveLineItems(): void
    {
        $rfq = new RFQ();
        $lineItem = $this->createMock(RfqLineItem::class);
        $lineItem->method('getRfq')->willReturn($rfq);
        $lineItem->expects($this->atLeastOnce())->method('setRfq');

        $rfq->addLineItem($lineItem);
        $this->assertCount(1, $rfq->getLineItems());

        $rfq->removeLineItem($lineItem);
        $this->assertCount(0, $rfq->getLineItems());
    }

    // ============================================================
    // CRITICAL: Quote.apiVersions type mismatch
    // Audit Issue: Column type was 'text' with ?string property
    // but getter/setter used ?array - now fixed to json/?array
    // ============================================================

    public function testQuoteApiVersionsIsArray(): void
    {
        $quote = new Quote();
        $this->assertNull($quote->getApiVersions());

        $versions = ['mouser' => 'v1.2', 'digikey' => 'v3.0'];
        $quote->setApiVersions($versions);
        
        $result = $quote->getApiVersions();
        $this->assertIsArray($result);
        $this->assertEquals('v1.2', $result['mouser']);
    }

    // ============================================================
    // CRITICAL: Quote.generateQuoteNumber collision risk
    // Audit Issue: Used rand(1,9999) with unique constraint = collision risk
    // Now uses uniqid for uniqueness
    // ============================================================

    public function testQuoteNumberUniqueness(): void
    {
        $numbers = [];
        for ($i = 0; $i < 100; $i++) {
            $quote = new Quote();
            $num = $quote->getQuoteNumber();
            $this->assertNotContains($num, $numbers, "Collision detected: {$num}");
            $numbers[] = $num;
        }
    }

    public function testQuoteNumberFormat(): void
    {
        $quote = new Quote();
        $this->assertMatchesRegularExpression('/^QTE-\d{4}-[A-Z0-9]{6}$/', $quote->getQuoteNumber());
    }

    // ============================================================
    // HIGH: Missing PreUpdate lifecycle callbacks
    // Audit Issue: Entities had $updatedAt but no automatic update
    // ============================================================

    public function testRfqPreUpdateCallback(): void
    {
        $rfq = new RFQ();
        $this->assertNull($rfq->getUpdatedAt());
        $rfq->onPreUpdate();
        $this->assertInstanceOf(\DateTimeInterface::class, $rfq->getUpdatedAt());
    }

    public function testQuotePreUpdateCallback(): void
    {
        $quote = new Quote();
        $this->assertNull($quote->getUpdatedAt());
        $quote->onPreUpdate();
        $this->assertInstanceOf(\DateTimeInterface::class, $quote->getUpdatedAt());
    }

    public function testCompanyPreUpdateCallback(): void
    {
        $company = new Company();
        $this->assertNull($company->getUpdatedAt());
        $company->onPreUpdate();
        $this->assertInstanceOf(\DateTimeInterface::class, $company->getUpdatedAt());
    }

    public function testContactPreUpdateCallback(): void
    {
        $contact = new Contact();
        $this->assertNull($contact->getUpdatedAt());
        $contact->onPreUpdate();
        $this->assertInstanceOf(\DateTimeInterface::class, $contact->getUpdatedAt());
    }

    public function testActivityPreUpdateCallback(): void
    {
        $activity = new Activity();
        $this->assertNull($activity->getUpdatedAt());
        $activity->onPreUpdate();
        $this->assertInstanceOf(\DateTimeInterface::class, $activity->getUpdatedAt());
    }

    // ============================================================
    // HIGH: Missing $updatedAt field on Contact & Activity
    // Audit Issue: Contact and Activity had no $updatedAt at all
    // ============================================================

    public function testContactHasUpdatedAtField(): void
    {
        $contact = new Contact();
        $date = new \DateTime('2025-06-01');
        $contact->setUpdatedAt($date);
        $this->assertEquals($date, $contact->getUpdatedAt());
    }

    public function testActivityHasUpdatedAtField(): void
    {
        $activity = new Activity();
        $date = new \DateTime('2025-06-01');
        $activity->setUpdatedAt($date);
        $this->assertEquals($date, $activity->getUpdatedAt());
    }

    // ============================================================
    // HIGH: RFQ.setLossReason silently accepted invalid values
    // Now throws InvalidArgumentException
    // ============================================================

    public function testRfqSetLossReasonValidatesInput(): void
    {
        $rfq = new RFQ();
        
        // Valid reasons should work
        $rfq->setLossReason('price');
        $this->assertEquals('price', $rfq->getLossReason());
        
        // Null should work
        $rfq->setLossReason(null);
        $this->assertNull($rfq->getLossReason());

        // Invalid should throw
        $this->expectException(\InvalidArgumentException::class);
        $rfq->setLossReason('nonexistent_reason');
    }

    // ============================================================
    // HIGH: Activity.setStatus silently accepted invalid values
    // Now validates and normalizes or throws
    // ============================================================

    public function testActivitySetStatusValidatesInput(): void
    {
        $activity = new Activity();
        
        // Valid statuses
        $activity->setStatus('Open');
        $this->assertEquals('Open', $activity->getStatus());
        
        // Case normalization
        $activity->setStatus('completed');
        $this->assertEquals('Completed', $activity->getStatus());

        // Invalid should throw
        $this->expectException(\InvalidArgumentException::class);
        $activity->setStatus('totally_invalid');
    }

    // ============================================================
    // HIGH: Company.setSector had no validation
    // Now validates against VALID_SECTORS
    // ============================================================

    public function testCompanySetSectorValidatesInput(): void
    {
        $company = new Company();
        
        // Valid sector
        $company->setSector('Automotive');
        $this->assertEquals('Automotive', $company->getSector());
        
        // Null should work (sector is nullable)
        $company->setSector(null);
        $this->assertNull($company->getSector());

        // Invalid should throw
        $this->expectException(\InvalidArgumentException::class);
        $company->setSector('InvalidSector');
    }

    // ============================================================
    // Verify existing validations still work
    // ============================================================

    public function testCompanySetAccountTierValidation(): void
    {
        $company = new Company();
        $company->setAccountTier('A');
        $this->assertEquals('A', $company->getAccountTier());

        $this->expectException(\InvalidArgumentException::class);
        $company->setAccountTier('D');
    }

    public function testCompanySetPipelineStageValidation(): void
    {
        $company = new Company();
        $company->setPipelineStage('SQL');
        $this->assertEquals('SQL', $company->getPipelineStage());

        $this->expectException(\InvalidArgumentException::class);
        $company->setPipelineStage('InvalidStage');
    }

    // ============================================================
    // Verify all LOSS_REASONS are accepted
    // ============================================================

    public function testAllLossReasonsAccepted(): void
    {
        $rfq = new RFQ();
        foreach (RFQ::LOSS_REASONS as $reason) {
            $rfq->setLossReason($reason);
            $this->assertEquals($reason, $rfq->getLossReason());
        }
    }

    // ============================================================
    // Verify all VALID_SECTORS accepted
    // ============================================================

    public function testAllValidSectorsAccepted(): void
    {
        $company = new Company();
        foreach (Company::VALID_SECTORS as $sector) {
            $company->setSector($sector);
            $this->assertEquals($sector, $company->getSector());
        }
    }

    // ============================================================
    // Verify RFQ $updatedAt field (new addition)
    // ============================================================

    public function testRfqHasUpdatedAtField(): void
    {
        $rfq = new RFQ();
        $this->assertNull($rfq->getUpdatedAt());
        $date = new \DateTime('2025-06-01');
        $rfq->setUpdatedAt($date);
        $this->assertEquals($date, $rfq->getUpdatedAt());
    }

    // ============================================================
    // Verify Activity outcome validation via setOutcomeCategory
    // ============================================================

    public function testActivityOutcomeCategoryValidation(): void
    {
        $activity = new Activity();
        
        // Valid outcomes
        foreach (Activity::OUTCOMES as $outcome) {
            $activity->setOutcomeCategory($outcome);
            $this->assertEquals($outcome, $activity->getOutcome());
        }

        // Invalid should throw
        $this->expectException(\InvalidArgumentException::class);
        $activity->setOutcomeCategory('invalid_outcome');
    }

    // ============================================================
    // Verify constructor initializations
    // ============================================================

    public function testRfqConstructorInitializesLineItems(): void
    {
        $rfq = new RFQ();
        $this->assertNotNull($rfq->getLineItems());
        $this->assertCount(0, $rfq->getLineItems());
        $this->assertInstanceOf(\DateTimeInterface::class, $rfq->getCreatedAt());
    }

    public function testQuoteConstructorInitializesCollections(): void
    {
        $quote = new Quote();
        $this->assertNotNull($quote->getPartBreakdowns());
        $this->assertNotNull($quote->getBomLines());
        $this->assertInstanceOf(\DateTimeInterface::class, $quote->getCreatedAt());
        $this->assertNotNull($quote->getQuoteNumber());
    }

    public function testContactConstructorInitializations(): void
    {
        $contact = new Contact();
        $this->assertInstanceOf(\DateTimeInterface::class, $contact->getCreatedAt());
        $this->assertNotNull($contact->getActivities());
        $this->assertNotNull($contact->getEmailCampaigns());
    }

    public function testActivityConstructorInitializations(): void
    {
        $activity = new Activity();
        $this->assertInstanceOf(\DateTimeInterface::class, $activity->getCreatedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $activity->getActivityDate());
    }
}
