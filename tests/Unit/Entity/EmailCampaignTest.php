<?php

namespace App\Tests\Unit\Entity;

use App\Entity\EmailCampaign;
use App\Entity\EmailTemplate;
use App\Entity\EmailSend;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

class EmailCampaignTest extends TestCase
{
    private EmailCampaign $campaign;

    protected function setUp(): void
    {
        $this->campaign = new EmailCampaign();
    }

    public function testEmailCampaignInitialState(): void
    {
        $this->assertNull($this->campaign->getId());
        $this->assertNull($this->campaign->getName());
        $this->assertEmpty($this->campaign->getEmailSends());
        $this->assertFalse($this->campaign->isActive());
    }

    public function testNameMutator(): void
    {
        $name = "Test Campaign";
        $this->campaign->setName($name);
        $this->assertEquals($name, $this->campaign->getName());
    }

    public function testAddEmailSend(): void
    {
        $emailSend = new EmailSend();
        $this->campaign->addEmailSend($emailSend);
        
        $this->assertCount(1, $this->campaign->getEmailSends());
        $this->assertTrue($this->campaign->getEmailSends()->contains($emailSend));
        $this->assertEquals($this->campaign, $emailSend->getCampaign());
    }

    public function testSetTemplate(): void
    {
        $template = new EmailTemplate();
        $template->setName('Test Template');
        
        $this->campaign->setTemplate($template);
        $this->assertEquals($template, $this->campaign->getTemplate());
    }

    public function testScheduling(): void
    {
        $scheduledAt = new DateTimeImmutable();
        $this->campaign->setScheduledAt($scheduledAt);
        
        $this->assertEquals($scheduledAt, $this->campaign->getScheduledAt());
    }

    public function testToggleActive(): void
    {
        $this->assertFalse($this->campaign->isActive());
        
        $this->campaign->setActive(true);
        $this->assertTrue($this->campaign->isActive());
        
        $this->campaign->setActive(false);
        $this->assertFalse($this->campaign->isActive());
    }

    public function testLanguageMutator(): void
    {
        $this->campaign->setLanguage('FR');
        $this->assertEquals('FR', $this->campaign->getLanguage());

        $this->campaign->setLanguage('EN');
        $this->assertEquals('EN', $this->campaign->getLanguage());
    }

    public function testDescriptionMutator(): void
    {
        $desc = 'Campaign targeting automotive sector';
        $this->campaign->setDescription($desc);
        $this->assertEquals($desc, $this->campaign->getDescription());
    }

    public function testTouchCountMutator(): void
    {
        $this->campaign->setTouchCount(3);
        $this->assertEquals(3, $this->campaign->getTouchCount());
    }

    public function testTouchTemplates(): void
    {
        $templates = [1, 2, 3, 4, 5];
        $this->campaign->setTouchTemplates($templates);
        $this->assertEquals($templates, $this->campaign->getTouchTemplates());
    }

    public function testAbTestVariants(): void
    {
        $variants = [
            ['subject' => 'Variant A', 'weight' => 50],
            ['subject' => 'Variant B', 'weight' => 50],
        ];
        $this->campaign->setAbTestVariants($variants);
        $this->assertEquals($variants, $this->campaign->getAbTestVariants());
    }

    public function testRemoveEmailSend(): void
    {
        $emailSend = new EmailSend();
        $this->campaign->addEmailSend($emailSend);
        $this->assertCount(1, $this->campaign->getEmailSends());

        $this->campaign->removeEmailSend($emailSend);
        $this->assertCount(0, $this->campaign->getEmailSends());
    }

    public function testContactCollection(): void
    {
        $contact = new \App\Entity\Contact();
        $contact->setFirstName('Jane');
        $contact->setLastName('Smith');

        $this->campaign->addContact($contact);
        $this->assertCount(1, $this->campaign->getContacts());
        $this->assertTrue($this->campaign->getContacts()->contains($contact));

        $this->campaign->removeContact($contact);
        $this->assertCount(0, $this->campaign->getContacts());
    }
}