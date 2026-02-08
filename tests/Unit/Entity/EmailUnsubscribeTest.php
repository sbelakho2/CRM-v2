<?php

namespace App\Tests\Unit\Entity;

use App\Entity\EmailUnsubscribe;
use App\Entity\Contact;
use PHPUnit\Framework\TestCase;

class EmailUnsubscribeTest extends TestCase
{
    private EmailUnsubscribe $unsubscribe;

    protected function setUp(): void
    {
        $this->unsubscribe = new EmailUnsubscribe();
    }

    public function testInitialState(): void
    {
        $this->assertNull($this->unsubscribe->getId());
        $this->assertNull($this->unsubscribe->getContact());
        $this->assertNull($this->unsubscribe->getEmail());
        $this->assertNull($this->unsubscribe->getReason());
        $this->assertNull($this->unsubscribe->getFeedbackText());
        $this->assertInstanceOf(\DateTimeInterface::class, $this->unsubscribe->getUnsubscribedAt());
        $this->assertNull($this->unsubscribe->getIpAddress());
        $this->assertNull($this->unsubscribe->getUserAgent());
    }

    public function testEmailMutator(): void
    {
        $result = $this->unsubscribe->setEmail('test@example.com');
        $this->assertEquals('test@example.com', $this->unsubscribe->getEmail());
        $this->assertSame($this->unsubscribe, $result);
    }

    public function testContactMutator(): void
    {
        $contact = new Contact();
        $contact->setFirstName('John');
        $contact->setLastName('Doe');
        
        $result = $this->unsubscribe->setContact($contact);
        $this->assertEquals($contact, $this->unsubscribe->getContact());
        $this->assertSame($this->unsubscribe, $result);
    }

    public function testNullableContact(): void
    {
        $contact = new Contact();
        $this->unsubscribe->setContact($contact);
        $this->assertNotNull($this->unsubscribe->getContact());
        
        $this->unsubscribe->setContact(null);
        $this->assertNull($this->unsubscribe->getContact());
    }

    public function testReasonMutator(): void
    {
        $result = $this->unsubscribe->setReason(EmailUnsubscribe::REASON_TOO_FREQUENT);
        $this->assertEquals('TOO_FREQUENT', $this->unsubscribe->getReason());
        $this->assertSame($this->unsubscribe, $result);
    }

    public function testReasonConstants(): void
    {
        $this->assertEquals('NO_LONGER_INTERESTED', EmailUnsubscribe::REASON_NO_LONGER_INTERESTED);
        $this->assertEquals('TOO_FREQUENT', EmailUnsubscribe::REASON_TOO_FREQUENT);
        $this->assertEquals('IRRELEVANT', EmailUnsubscribe::REASON_IRRELEVANT);
        $this->assertEquals('NEVER_SUBSCRIBED', EmailUnsubscribe::REASON_NEVER_SUBSCRIBED);
        $this->assertEquals('hard_bounce', EmailUnsubscribe::REASON_HARD_BOUNCE);
        $this->assertEquals('soft_bounce_limit', EmailUnsubscribe::REASON_SOFT_BOUNCE_LIMIT);
        $this->assertEquals('spam_complaint', EmailUnsubscribe::REASON_SPAM_COMPLAINT);
        $this->assertEquals('MANUAL', EmailUnsubscribe::REASON_MANUAL);
    }

    public function testValidReasonsArray(): void
    {
        $this->assertCount(8, EmailUnsubscribe::VALID_REASONS);
        foreach (EmailUnsubscribe::VALID_REASONS as $reason) {
            $this->assertIsString($reason);
        }
    }

    public function testFeedbackTextMutator(): void
    {
        $result = $this->unsubscribe->setFeedbackText('Too many emails, please stop.');
        $this->assertEquals('Too many emails, please stop.', $this->unsubscribe->getFeedbackText());
        $this->assertSame($this->unsubscribe, $result);
    }

    public function testUnsubscribedAtDefaultsToNow(): void
    {
        $now = new \DateTime();
        $diff = $now->getTimestamp() - $this->unsubscribe->getUnsubscribedAt()->getTimestamp();
        $this->assertLessThanOrEqual(2, abs($diff));
    }

    public function testUnsubscribedAtMutator(): void
    {
        $date = new \DateTime('2025-01-01 12:00:00');
        $result = $this->unsubscribe->setUnsubscribedAt($date);
        $this->assertEquals($date, $this->unsubscribe->getUnsubscribedAt());
        $this->assertSame($this->unsubscribe, $result);
    }

    public function testIpAddressMutator(): void
    {
        $result = $this->unsubscribe->setIpAddress('192.168.1.100');
        $this->assertEquals('192.168.1.100', $this->unsubscribe->getIpAddress());
        $this->assertSame($this->unsubscribe, $result);
    }

    public function testIpv6Address(): void
    {
        $ipv6 = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';
        $this->unsubscribe->setIpAddress($ipv6);
        $this->assertEquals($ipv6, $this->unsubscribe->getIpAddress());
    }

    public function testUserAgentMutator(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
        $result = $this->unsubscribe->setUserAgent($ua);
        $this->assertEquals($ua, $this->unsubscribe->getUserAgent());
        $this->assertSame($this->unsubscribe, $result);
    }

    public function testFluentInterface(): void
    {
        $result = $this->unsubscribe
            ->setEmail('test@example.com')
            ->setReason(EmailUnsubscribe::REASON_NO_LONGER_INTERESTED)
            ->setFeedbackText('Not interested anymore')
            ->setIpAddress('10.0.0.1')
            ->setUserAgent('TestBot/1.0');

        $this->assertSame($this->unsubscribe, $result);
        $this->assertEquals('test@example.com', $result->getEmail());
        $this->assertEquals('NO_LONGER_INTERESTED', $result->getReason());
    }
}
