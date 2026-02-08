<?php

namespace App\Tests\Unit\Entity;

use App\Entity\EmailCampaign;
use App\Entity\EmailSegment;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the new properties added to EmailCampaign entity
 */
class EmailCampaignNewFieldsTest extends TestCase
{
    private EmailCampaign $campaign;

    protected function setUp(): void
    {
        $this->campaign = new EmailCampaign();
    }

    public function testCreatedAtSetInConstructor(): void
    {
        $now = new \DateTime();
        $diff = $now->getTimestamp() - $this->campaign->getCreatedAt()->getTimestamp();
        $this->assertLessThanOrEqual(2, abs($diff));
    }

    public function testSubjectMutator(): void
    {
        $this->assertNull($this->campaign->getSubject());
        $this->campaign->setSubject('Q4 Product Launch');
        $this->assertEquals('Q4 Product Launch', $this->campaign->getSubject());
    }

    public function testFromNameMutator(): void
    {
        $this->assertNull($this->campaign->getFromName());
        $this->campaign->setFromName('STARZ Sales Team');
        $this->assertEquals('STARZ Sales Team', $this->campaign->getFromName());
    }

    public function testFromEmailMutator(): void
    {
        $this->assertNull($this->campaign->getFromEmail());
        $this->campaign->setFromEmail('sales@starzelectronics.site');
        $this->assertEquals('sales@starzelectronics.site', $this->campaign->getFromEmail());
    }

    public function testBodyHtmlMutator(): void
    {
        $this->assertNull($this->campaign->getBodyHtml());
        $html = '<h1>Hello</h1><p>Welcome to our offer.</p>';
        $this->campaign->setBodyHtml($html);
        $this->assertEquals($html, $this->campaign->getBodyHtml());
    }

    public function testValidStatusValues(): void
    {
        foreach (EmailCampaign::VALID_STATUSES as $status) {
            $this->campaign->setStatus($status);
            $this->assertEquals($status, $this->campaign->getStatus());
        }
    }

    public function testInvalidStatusThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid campaign status');
        $this->campaign->setStatus('invalid_status');
    }

    public function testStatusConstants(): void
    {
        $expected = ['draft', 'sending', 'paused', 'completed', 'cancelled'];
        $this->assertEquals($expected, EmailCampaign::VALID_STATUSES);
    }

    public function testTypeMutator(): void
    {
        $this->assertNotNull($this->campaign->getType());
        $this->campaign->setType('drip');
        $this->assertEquals('drip', $this->campaign->getType());
    }

    public function testTriggerTypeMutator(): void
    {
        $this->assertNull($this->campaign->getTriggerType());
        $this->campaign->setTriggerType('event');
        $this->assertEquals('event', $this->campaign->getTriggerType());
    }

    public function testTriggerConditionsMutator(): void
    {
        $this->assertNull($this->campaign->getTriggerConditions());
        $conditions = ['field' => 'status', 'operator' => 'equals', 'value' => 'new'];
        $this->campaign->setTriggerConditions($conditions);
        $this->assertEquals($conditions, $this->campaign->getTriggerConditions());
    }

    public function testSendTimeOptimizationMutator(): void
    {
        $this->assertFalse($this->campaign->isSendTimeOptimization());
        $this->campaign->setSendTimeOptimization(true);
        $this->assertTrue($this->campaign->isSendTimeOptimization());
    }

    public function testSentAtMutator(): void
    {
        $this->assertNull($this->campaign->getSentAt());
        $date = new \DateTime();
        $this->campaign->setSentAt($date);
        $this->assertEquals($date, $this->campaign->getSentAt());
    }

    public function testCreatedAtMutator(): void
    {
        $date = new \DateTime('2025-01-01');
        $this->campaign->setCreatedAt($date);
        $this->assertEquals($date, $this->campaign->getCreatedAt());
    }

    public function testUpdatedAtMutator(): void
    {
        $this->assertNull($this->campaign->getUpdatedAt());
        $date = new \DateTime();
        $this->campaign->setUpdatedAt($date);
        $this->assertEquals($date, $this->campaign->getUpdatedAt());
    }

    public function testOnPreUpdateSetsUpdatedAt(): void
    {
        $this->assertNull($this->campaign->getUpdatedAt());
        $this->campaign->onPreUpdate();
        $this->assertInstanceOf(\DateTimeInterface::class, $this->campaign->getUpdatedAt());
    }

    public function testSegmentRelationship(): void
    {
        $this->assertNull($this->campaign->getSegment());
        $segment = new EmailSegment();
        $this->campaign->setSegment($segment);
        $this->assertSame($segment, $this->campaign->getSegment());
    }

    public function testNullableSegment(): void
    {
        $segment = new EmailSegment();
        $this->campaign->setSegment($segment);
        $this->assertNotNull($this->campaign->getSegment());
        
        $this->campaign->setSegment(null);
        $this->assertNull($this->campaign->getSegment());
    }

    public function testAbTestVariantsNullable(): void
    {
        $this->campaign->setAbTestVariants([]);
        $this->assertEmpty($this->campaign->getAbTestVariants());
    }

    public function testSubjectWithSpecialCharacters(): void
    {
        $subject = 'Prix spéciaux pour Q4 2025 — offre limitée! 🎯';
        $this->campaign->setSubject($subject);
        $this->assertEquals($subject, $this->campaign->getSubject());
    }

    public function testFromEmailValidFormat(): void
    {
        $this->campaign->setFromEmail('user@domain.com');
        $this->assertEquals('user@domain.com', $this->campaign->getFromEmail());
    }

    public function testBodyHtmlWithComplexContent(): void
    {
        $html = '<html><body><h1>Title</h1><p style="color: #333;">Content with <strong>HTML</strong></p><a href="https://example.com">Link</a></body></html>';
        $this->campaign->setBodyHtml($html);
        $this->assertEquals($html, $this->campaign->getBodyHtml());
    }
}
