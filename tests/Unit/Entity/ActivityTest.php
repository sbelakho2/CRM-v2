<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Activity;
use App\Entity\Company;
use App\Entity\Contact;
use PHPUnit\Framework\TestCase;

class ActivityTest extends TestCase
{
    private Activity $activity;

    protected function setUp(): void
    {
        $this->activity = new Activity();
    }

    public function testActivityInitialState(): void
    {
        $this->assertNull($this->activity->getId());
        $this->assertNull($this->activity->getType());
        $this->assertEquals(Activity::STATUS_OPEN, $this->activity->getStatus());
        $this->assertNull($this->activity->getOutcome());
    }

    public function testCreatedAtSetOnConstruction(): void
    {
        $activity = new Activity();
        $this->assertInstanceOf(\DateTimeInterface::class, $activity->getCreatedAt());
    }

    public function testActivityDateSetOnConstruction(): void
    {
        $activity = new Activity();
        $this->assertInstanceOf(\DateTimeInterface::class, $activity->getActivityDate());
    }

    // ============================================================
    // FIX VERIFICATION: $updatedAt field and PreUpdate callback
    // ============================================================

    public function testUpdatedAtMutator(): void
    {
        $this->assertNull($this->activity->getUpdatedAt());
        $date = new \DateTime('2025-06-01');
        $this->activity->setUpdatedAt($date);
        $this->assertEquals($date, $this->activity->getUpdatedAt());
    }

    public function testOnPreUpdateSetsUpdatedAt(): void
    {
        $this->assertNull($this->activity->getUpdatedAt());
        $this->activity->onPreUpdate();
        $this->assertInstanceOf(\DateTimeInterface::class, $this->activity->getUpdatedAt());
    }

    // ============================================================
    // FIX VERIFICATION: setStatus validation
    // ============================================================

    public function testSetStatusAcceptsValidStatuses(): void
    {
        foreach (Activity::STATUSES as $status) {
            $this->activity->setStatus($status);
            $this->assertEquals($status, $this->activity->getStatus());
        }
    }

    public function testSetStatusNormalizesCase(): void
    {
        $this->activity->setStatus('open');
        $this->assertEquals('Open', $this->activity->getStatus());

        $this->activity->setStatus('completed');
        $this->assertEquals('Completed', $this->activity->getStatus());

        $this->activity->setStatus('CANCELLED');
        $this->assertEquals('Cancelled', $this->activity->getStatus());
    }

    public function testSetStatusRejectsInvalidStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid activity status');
        $this->activity->setStatus('invalid_status');
    }

    public function testSetStatusAcceptsNull(): void
    {
        $this->activity->setStatus(null);
        $this->assertNull($this->activity->getStatus());
    }

    // ============================================================
    // Standard mutator tests
    // ============================================================

    public function testTypeMutator(): void
    {
        $this->activity->setType('Call');
        $this->assertEquals('Call', $this->activity->getType());
    }

    public function testSubjectMutator(): void
    {
        $this->activity->setSubject('Follow-up call with procurement');
        $this->assertEquals('Follow-up call with procurement', $this->activity->getSubject());
    }

    public function testOutcomeMutator(): void
    {
        $this->activity->setOutcome('positive');
        $this->assertEquals('positive', $this->activity->getOutcome());
    }

    public function testOutcomeDetailMutator(): void
    {
        $this->activity->setOutcomeDetail('Meeting booked for next week');
        $this->assertEquals('Meeting booked for next week', $this->activity->getOutcomeDetail());
    }

    public function testSetOutcomeCategoryValid(): void
    {
        foreach (Activity::OUTCOMES as $outcome) {
            $this->activity->setOutcomeCategory($outcome);
            $this->assertEquals($outcome, $this->activity->getOutcome());
        }
    }

    public function testSetOutcomeCategoryInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid outcome category');
        $this->activity->setOutcomeCategory('invalid');
    }

    public function testDurationMinutesMutator(): void
    {
        $this->activity->setDurationMinutes(45);
        $this->assertEquals(45, $this->activity->getDurationMinutes());
        $this->assertEquals(45, $this->activity->getDuration());
    }

    public function testFormattedDuration(): void
    {
        $this->assertEquals('N/A', $this->activity->getFormattedDuration());

        $this->activity->setDurationMinutes(90);
        $this->assertEquals('1h 30m', $this->activity->getFormattedDuration());

        $this->activity->setDurationMinutes(60);
        $this->assertEquals('1h', $this->activity->getFormattedDuration());

        $this->activity->setDurationMinutes(30);
        $this->assertEquals('30m', $this->activity->getFormattedDuration());
    }

    public function testCompanyMutator(): void
    {
        $company = new Company();
        $company->setName('Test Co');
        $this->activity->setCompany($company);
        $this->assertEquals($company, $this->activity->getCompany());
    }

    public function testContactMutator(): void
    {
        $contact = new Contact();
        $contact->setFirstName('John');
        $this->activity->setContact($contact);
        $this->assertEquals($contact, $this->activity->getContact());
    }

    public function testCompleteMethod(): void
    {
        $this->activity->complete('positive', 'Great call');
        $this->assertEquals(Activity::STATUS_COMPLETED, $this->activity->getStatus());
        $this->assertEquals('positive', $this->activity->getOutcome());
        $this->assertEquals('Great call', $this->activity->getOutcomeDetail());
        $this->assertTrue($this->activity->isCompleted());
    }

    public function testIsOpenAndIsCompleted(): void
    {
        $this->assertTrue($this->activity->isOpen());
        $this->assertFalse($this->activity->isCompleted());

        $this->activity->setStatus('Completed');
        $this->assertFalse($this->activity->isOpen());
        $this->assertTrue($this->activity->isCompleted());
    }

    public function testIsPositiveOutcome(): void
    {
        $this->assertFalse($this->activity->isPositiveOutcome());
        $this->activity->setOutcome('positive');
        $this->assertTrue($this->activity->isPositiveOutcome());
    }

    public function testConstants(): void
    {
        $this->assertCount(5, Activity::OUTCOMES);
        $this->assertCount(4, Activity::STATUSES);
        $this->assertContains('positive', Activity::OUTCOMES);
        $this->assertContains('Open', Activity::STATUSES);
    }
}
