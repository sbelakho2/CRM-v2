<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Contact;
use App\Entity\Company;
use App\Entity\EmailCampaign;
use PHPUnit\Framework\TestCase;

class ContactTest extends TestCase
{
    private Contact $contact;

    protected function setUp(): void
    {
        $this->contact = new Contact();
    }

    public function testContactInitialState(): void
    {
        $this->assertNull($this->contact->getId());
        $this->assertNull($this->contact->getFirstName());
        $this->assertNull($this->contact->getLastName());
        $this->assertNull($this->contact->getEmail());
        $this->assertNull($this->contact->getCompany());
    }

    public function testFullNameGeneration(): void
    {
        $this->contact->setFirstName('John');
        $this->contact->setLastName('Doe');
        
        $this->assertEquals('John Doe', $this->contact->getFullName());
    }

    public function testEmailMutator(): void
    {
        $email = 'john.doe@example.com';
        $this->contact->setEmail($email);
        
        $this->assertEquals($email, $this->contact->getEmail());
    }

    public function testCompanyAssociation(): void
    {
        $company = new Company();
        $company->setName('Test Company');
        
        $this->contact->setCompany($company);
        
        $this->assertEquals($company, $this->contact->getCompany());
        $this->assertTrue($company->getContacts()->contains($this->contact));
    }

    public function testEmailCampaignAssociation(): void
    {
        $campaign = new EmailCampaign();
        $campaign->setName('Test Campaign');
        
        $this->contact->addEmailCampaign($campaign);
        
        $this->assertTrue($this->contact->getEmailCampaigns()->contains($campaign));
        $this->assertTrue($campaign->getContacts()->contains($this->contact));
    }

    public function testRemoveEmailCampaign(): void
    {
        $campaign = new EmailCampaign();
        $this->contact->addEmailCampaign($campaign);
        $this->contact->removeEmailCampaign($campaign);
        
        $this->assertFalse($this->contact->getEmailCampaigns()->contains($campaign));
        $this->assertFalse($campaign->getContacts()->contains($this->contact));
    }

    public function testJobTitleMutator(): void
    {
        $this->contact->setJobTitle('Procurement Engineer');
        $this->assertEquals('Procurement Engineer', $this->contact->getJobTitle());
    }

    public function testPhoneMutator(): void
    {
        $this->contact->setPhone('+212-123-456-789');
        $this->assertEquals('+212-123-456-789', $this->contact->getPhone());
    }

    public function testLinkedInUrlMutator(): void
    {
        $url = 'https://linkedin.com/in/johndoe';
        $this->contact->setLinkedInUrl($url);
        $this->assertEquals($url, $this->contact->getLinkedInUrl());
    }

    public function testSourceMutator(): void
    {
        $this->contact->setSource('LinkedIn');
        $this->assertEquals('LinkedIn', $this->contact->getSource());
    }

    public function testPrimaryContactFlag(): void
    {
        $this->assertFalse($this->contact->isPrimaryContact());
        $this->contact->setPrimaryContact(true);
        $this->assertTrue($this->contact->isPrimaryContact());
    }

    public function testNotesMutator(): void
    {
        $notes = 'Key decision maker for procurement';
        $this->contact->setNotes($notes);
        $this->assertEquals($notes, $this->contact->getNotes());
    }

    public function testRoleMutator(): void
    {
        $this->contact->setRole('Decision Maker');
        $this->assertEquals('Decision Maker', $this->contact->getRole());
    }

    public function testCreatedAtIsSetOnConstruction(): void
    {
        $contact = new Contact();
        $this->assertInstanceOf(\DateTimeInterface::class, $contact->getCreatedAt());
    }

    public function testUpdatedAtMutator(): void
    {
        $date = new \DateTime('2025-06-01');
        $this->contact->setUpdatedAt($date);
        $this->assertEquals($date, $this->contact->getUpdatedAt());
    }

    public function testOnPreUpdateSetsUpdatedAt(): void
    {
        $this->assertNull($this->contact->getUpdatedAt());
        $this->contact->onPreUpdate();
        $this->assertInstanceOf(\DateTimeInterface::class, $this->contact->getUpdatedAt());
    }

    public function testActivityCollectionInitialized(): void
    {
        $this->assertCount(0, $this->contact->getActivities());
    }
}