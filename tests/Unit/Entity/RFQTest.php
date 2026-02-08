<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Company;
use App\Entity\RFQ;
use App\Entity\RfqLineItem;
use PHPUnit\Framework\TestCase;

class RFQTest extends TestCase
{
    private RFQ $rfq;

    protected function setUp(): void
    {
        $this->rfq = new RFQ();
    }

    public function testRfqInitialState(): void
    {
        $this->assertNull($this->rfq->getId());
        $this->assertNull($this->rfq->getCompany());
        $this->assertEquals('Pending', $this->rfq->getStatus());
        $this->assertEquals('Standard RFQ', $this->rfq->getType());
        $this->assertEquals('EUR', $this->rfq->getCurrency());
        $this->assertFalse($this->rfq->isNdaSent());
        $this->assertFalse($this->rfq->isNdaExecuted());
    }

    public function testCreatedAtSetOnConstruction(): void
    {
        $rfq = new RFQ();
        $this->assertInstanceOf(\DateTimeInterface::class, $rfq->getCreatedAt());
    }

    // ============================================================
    // FIX VERIFICATION: $lineItems collection
    // ============================================================

    public function testLineItemsCollectionInitialized(): void
    {
        $rfq = new RFQ();
        $this->assertCount(0, $rfq->getLineItems());
    }

    public function testAddLineItem(): void
    {
        $lineItem = $this->createMock(RfqLineItem::class);
        $lineItem->expects($this->once())->method('setRfq')->with($this->rfq);

        $this->rfq->addLineItem($lineItem);
        $this->assertCount(1, $this->rfq->getLineItems());
        $this->assertTrue($this->rfq->getLineItems()->contains($lineItem));
    }

    public function testAddDuplicateLineItemIgnored(): void
    {
        $lineItem = $this->createMock(RfqLineItem::class);
        $lineItem->expects($this->once())->method('setRfq');

        $this->rfq->addLineItem($lineItem);
        $this->rfq->addLineItem($lineItem); // duplicate
        $this->assertCount(1, $this->rfq->getLineItems());
    }

    public function testRemoveLineItem(): void
    {
        $lineItem = $this->createMock(RfqLineItem::class);
        $lineItem->method('getRfq')->willReturn($this->rfq);
        $lineItem->expects($this->atLeastOnce())->method('setRfq');

        $this->rfq->addLineItem($lineItem);
        $this->rfq->removeLineItem($lineItem);
        $this->assertCount(0, $this->rfq->getLineItems());
    }

    // ============================================================
    // FIX VERIFICATION: $updatedAt field
    // ============================================================

    public function testUpdatedAtMutator(): void
    {
        $this->assertNull($this->rfq->getUpdatedAt());
        $date = new \DateTime('2025-06-01');
        $this->rfq->setUpdatedAt($date);
        $this->assertEquals($date, $this->rfq->getUpdatedAt());
    }

    public function testOnPreUpdateSetsUpdatedAt(): void
    {
        $this->assertNull($this->rfq->getUpdatedAt());
        $this->rfq->onPreUpdate();
        $this->assertInstanceOf(\DateTimeInterface::class, $this->rfq->getUpdatedAt());
    }

    // ============================================================
    // FIX VERIFICATION: setLossReason validation
    // ============================================================

    public function testSetLossReasonAcceptsValidReason(): void
    {
        foreach (RFQ::LOSS_REASONS as $reason) {
            $this->rfq->setLossReason($reason);
            $this->assertEquals($reason, $this->rfq->getLossReason());
        }
    }

    public function testSetLossReasonAcceptsNull(): void
    {
        $this->rfq->setLossReason(null);
        $this->assertNull($this->rfq->getLossReason());
    }

    public function testSetLossReasonRejectsInvalidReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid loss reason');
        $this->rfq->setLossReason('invalid_reason');
    }

    // ============================================================
    // Existing functionality verification
    // ============================================================

    public function testCompanyMutator(): void
    {
        $company = new Company();
        $company->setName('Test Co');
        $this->rfq->setCompany($company);
        $this->assertEquals($company, $this->rfq->getCompany());
    }

    public function testStatusMutator(): void
    {
        $this->rfq->setStatus('Won');
        $this->assertEquals('Won', $this->rfq->getStatus());
    }

    public function testEstimatedValueMutator(): void
    {
        $this->rfq->setEstimatedValue('150000.00');
        $this->assertEquals('150000.00', $this->rfq->getEstimatedValue());
    }

    public function testCurrencyUppercase(): void
    {
        $this->rfq->setCurrency('usd');
        $this->assertEquals('USD', $this->rfq->getCurrency());
    }

    public function testCurrencyNull(): void
    {
        $this->rfq->setCurrency(null);
        $this->assertNull($this->rfq->getCurrency());
    }

    public function testMarkLost(): void
    {
        $this->rfq->markLost('price', 'Competitor X', 'They were 10% cheaper');
        $this->assertEquals('Lost', $this->rfq->getStatus());
        $this->assertEquals('price', $this->rfq->getLossReason());
        $this->assertEquals('Competitor X', $this->rfq->getCompetitorWon());
        $this->assertEquals('They were 10% cheaper', $this->rfq->getLossReasonDetail());
        $this->assertInstanceOf(\DateTimeInterface::class, $this->rfq->getDecisionDate());
        $this->assertTrue($this->rfq->isLost());
    }

    public function testMarkWon(): void
    {
        $this->rfq->markWon('Best technical capability');
        $this->assertEquals('Won', $this->rfq->getStatus());
        $this->assertEquals('Best technical capability', $this->rfq->getWinFactors());
        $this->assertInstanceOf(\DateTimeInterface::class, $this->rfq->getAwardDate());
        $this->assertInstanceOf(\DateTimeInterface::class, $this->rfq->getDecisionDate());
        $this->assertTrue($this->rfq->isWon());
    }

    public function testIsWonAndIsLost(): void
    {
        $this->assertFalse($this->rfq->isWon());
        $this->assertFalse($this->rfq->isLost());

        $this->rfq->setStatus('Won');
        $this->assertTrue($this->rfq->isWon());
        $this->assertFalse($this->rfq->isLost());

        $this->rfq->setStatus('Lost');
        $this->assertFalse($this->rfq->isWon());
        $this->assertTrue($this->rfq->isLost());
    }

    public function testGetPriceDifferencePercent(): void
    {
        $this->rfq->setEstimatedValue('110.00');
        $this->rfq->setWinningBidAmount('100.00');
        $this->assertEquals(10.0, $this->rfq->getPriceDifferencePercent());
    }

    public function testGetPriceDifferencePercentReturnsNullWhenMissingData(): void
    {
        $this->assertNull($this->rfq->getPriceDifferencePercent());
    }

    public function testNdaFields(): void
    {
        $this->rfq->setNdaSent(true);
        $this->assertTrue($this->rfq->isNdaSent());

        $date = new \DateTime('2025-01-15');
        $this->rfq->setNdaDate($date);
        $this->assertEquals($date, $this->rfq->getNdaDate());

        $this->rfq->setNdaExecuted(true);
        $this->assertTrue($this->rfq->isNdaExecuted());
    }

    public function testSopDateMutator(): void
    {
        $date = new \DateTime('2026-06-01');
        $this->rfq->setSopDate($date);
        $this->assertEquals($date, $this->rfq->getSopDate());
    }

    public function testTechnicalScopeMutator(): void
    {
        $scope = 'PCB assembly for automotive ECU';
        $this->rfq->setTechnicalScope($scope);
        $this->assertEquals($scope, $this->rfq->getTechnicalScope());
    }

    public function testVolumeAnnualMutator(): void
    {
        $this->rfq->setVolumeAnnual(50000);
        $this->assertEquals(50000, $this->rfq->getVolumeAnnual());
    }

    public function testLossReasonConstants(): void
    {
        $this->assertCount(10, RFQ::LOSS_REASONS);
        $this->assertContains('price', RFQ::LOSS_REASONS);
        $this->assertContains('lead_time', RFQ::LOSS_REASONS);
        $this->assertContains('technical_capability', RFQ::LOSS_REASONS);
        $this->assertContains('other', RFQ::LOSS_REASONS);
    }
}
