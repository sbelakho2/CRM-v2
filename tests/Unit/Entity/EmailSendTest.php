<?php

namespace App\Tests\Unit\Entity;

use App\Entity\EmailSend;
use App\Entity\EmailCampaign;
use App\Entity\Contact;
use PHPUnit\Framework\TestCase;

class EmailSendTest extends TestCase
{
    private EmailSend $send;

    protected function setUp(): void
    {
        $this->send = new EmailSend();
    }

    public function testInitialState(): void
    {
        $this->assertNull($this->send->getId());
        $this->assertNull($this->send->getCampaign());
        $this->assertNull($this->send->getContact());
        $this->assertFalse($this->send->isOpened());
        $this->assertFalse($this->send->isClicked());
        $this->assertFalse($this->send->isReplied());
        $this->assertFalse($this->send->isBounced());
        $this->assertNull($this->send->getSentAt());
        $this->assertNull($this->send->getEmailAddress());
        $this->assertNull($this->send->getScheduledAt());
        $this->assertNull($this->send->getOpenedAt());
        $this->assertNull($this->send->getClickedAt());
        $this->assertEquals(0, $this->send->getRetryCount());
        $this->assertNull($this->send->getFailureReason());
        $this->assertEquals('queued', $this->send->getStatus());
    }

    public function testEmailAddressMutator(): void
    {
        $this->send->setEmailAddress('test@example.com');
        $this->assertEquals('test@example.com', $this->send->getEmailAddress());
    }

    public function testEmailAddressFallsBackToContact(): void
    {
        $contact = $this->createMock(Contact::class);
        $contact->method('getEmail')->willReturn('contact@example.com');
        
        $this->send->setContact($contact);
        $this->assertEquals('contact@example.com', $this->send->getEmailAddress());
    }

    public function testExplicitEmailAddressOverridesContact(): void
    {
        $contact = $this->createMock(Contact::class);
        $contact->method('getEmail')->willReturn('contact@example.com');
        
        $this->send->setContact($contact);
        $this->send->setEmailAddress('explicit@example.com');
        $this->assertEquals('explicit@example.com', $this->send->getEmailAddress());
    }

    public function testValidStatusValues(): void
    {
        foreach (EmailSend::VALID_STATUSES as $status) {
            $this->send->setStatus($status);
            $this->assertEquals($status, $this->send->getStatus());
        }
    }

    public function testInvalidStatusThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid send status');
        $this->send->setStatus('invalid_status');
    }

    public function testStatusConstants(): void
    {
        $expected = ['queued', 'sending', 'sent', 'failed', 'cancelled', 'bounced'];
        $this->assertEquals($expected, EmailSend::VALID_STATUSES);
    }

    public function testScheduledAtMutator(): void
    {
        $date = new \DateTime('2025-06-15 10:00:00');
        $this->send->setScheduledAt($date);
        $this->assertEquals($date, $this->send->getScheduledAt());
    }

    public function testOpenedAtMutator(): void
    {
        $date = new \DateTime();
        $this->send->setOpenedAt($date);
        $this->assertEquals($date, $this->send->getOpenedAt());
    }

    public function testClickedAtMutator(): void
    {
        $date = new \DateTime();
        $this->send->setClickedAt($date);
        $this->assertEquals($date, $this->send->getClickedAt());
    }

    public function testRetryCountMutator(): void
    {
        $this->send->setRetryCount(3);
        $this->assertEquals(3, $this->send->getRetryCount());
    }

    public function testFailureReasonMutator(): void
    {
        $this->send->setFailureReason('Connection timeout');
        $this->assertEquals('Connection timeout', $this->send->getFailureReason());
    }

    public function testSentAtNullable(): void
    {
        $this->assertNull($this->send->getSentAt());
        
        $date = new \DateTime();
        $this->send->setSentAt($date);
        $this->assertEquals($date, $this->send->getSentAt());
    }

    public function testCampaignRelationship(): void
    {
        $campaign = new EmailCampaign();
        $campaign->setName('Test Campaign');
        
        $this->send->setCampaign($campaign);
        $this->assertEquals($campaign, $this->send->getCampaign());
    }

    public function testContactRelationship(): void
    {
        $contact = new Contact();
        $contact->setFirstName('John');
        $contact->setLastName('Doe');
        
        $this->send->setContact($contact);
        $this->assertEquals($contact, $this->send->getContact());
    }

    public function testTouchNumberMutator(): void
    {
        $this->send->setTouchNumber(3);
        $this->assertEquals(3, $this->send->getTouchNumber());
    }

    public function testOpenedFlagMutator(): void
    {
        $this->assertFalse($this->send->isOpened());
        $this->send->setOpened(true);
        $this->assertTrue($this->send->isOpened());
    }

    public function testClickedFlagMutator(): void
    {
        $this->assertFalse($this->send->isClicked());
        $this->send->setClicked(true);
        $this->assertTrue($this->send->isClicked());
    }

    public function testRepliedFlagMutator(): void
    {
        $this->assertFalse($this->send->isReplied());
        $this->send->setReplied(true);
        $this->assertTrue($this->send->isReplied());
    }

    public function testBouncedFlagMutator(): void
    {
        $this->assertFalse($this->send->isBounced());
        $this->send->setBounced(true);
        $this->assertTrue($this->send->isBounced());
    }
}
