<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Company;
use App\Entity\Contact;
use PHPUnit\Framework\TestCase;

class CompanyTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        $this->company = new Company();
    }

    public function testCompanyInitialState(): void
    {
        $this->assertNull($this->company->getId());
        $this->assertNull($this->company->getName());
        $this->assertEmpty($this->company->getContacts());
    }

    public function testCompanyNameMutator(): void
    {
        $name = "Test Company";
        $this->company->setName($name);
        $this->assertEquals($name, $this->company->getName());
    }

    public function testAddContact(): void
    {
        $contact = new Contact();
        $contact->setFirstName('John');
        $contact->setLastName('Doe');
        
        $this->company->addContact($contact);
        
        $this->assertCount(1, $this->company->getContacts());
        $this->assertTrue($this->company->getContacts()->contains($contact));
        $this->assertEquals($this->company, $contact->getCompany());
    }

    public function testRemoveContact(): void
    {
        $contact = new Contact();
        $this->company->addContact($contact);
        $this->company->removeContact($contact);
        
        $this->assertCount(0, $this->company->getContacts());
        $this->assertNull($contact->getCompany());
    }

    public function testSectorMutator(): void
    {
        $this->company->setSector('Automotive');
        $this->assertEquals('Automotive', $this->company->getSector());
    }

    public function testSectorRejectsInvalidValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->company->setSector('NotARealSector');
    }

    public function testSectorAcceptsNull(): void
    {
        $this->company->setSector(null);
        $this->assertNull($this->company->getSector());
    }

    public function testOnPreUpdateSetsUpdatedAt(): void
    {
        $this->assertNull($this->company->getUpdatedAt());
        $this->company->onPreUpdate();
        $this->assertInstanceOf(\DateTimeInterface::class, $this->company->getUpdatedAt());
    }

    public function testAccountTierDefaults(): void
    {
        $this->assertEquals('C', $this->company->getAccountTier());
    }

    public function testAccountTierMutator(): void
    {
        $this->company->setAccountTier('A');
        $this->assertEquals('A', $this->company->getAccountTier());
    }

    public function testRegionMutator(): void
    {
        $this->company->setRegion('TAC');
        $this->assertEquals('TAC', $this->company->getRegion());
    }

    public function testPipelineStageDefaults(): void
    {
        $this->assertEquals('Prospect', $this->company->getPipelineStage());
    }

    public function testPipelineStageMutator(): void
    {
        $this->company->setPipelineStage('SQL');
        $this->assertEquals('SQL', $this->company->getPipelineStage());
    }

    public function testWebsiteMutator(): void
    {
        $this->company->setWebsite('https://example.com');
        $this->assertEquals('https://example.com', $this->company->getWebsite());
    }

    public function testAddressMutator(): void
    {
        $this->company->setAddress('123 Industrial Park');
        $this->assertEquals('123 Industrial Park', $this->company->getAddress());
    }

    public function testCityMutator(): void
    {
        $this->company->setCity('Tangier');
        $this->assertEquals('Tangier', $this->company->getCity());
    }

    public function testCountryMutator(): void
    {
        $this->company->setCountry('Morocco');
        $this->assertEquals('Morocco', $this->company->getCountry());
    }

    public function testLinkedInUrlMutator(): void
    {
        $url = 'https://linkedin.com/company/test';
        $this->company->setLinkedInUrl($url);
        $this->assertEquals($url, $this->company->getLinkedInUrl());
    }

    public function testNotesMutator(): void
    {
        $notes = 'High-value automotive supplier';
        $this->company->setNotes($notes);
        $this->assertEquals($notes, $this->company->getNotes());
    }

    public function testPhysicalSiteMutator(): void
    {
        $this->company->setPhysicalSite('TFZ');
        $this->assertEquals('TFZ', $this->company->getPhysicalSite());
    }

    public function testLegalNameMutator(): void
    {
        $this->company->setLegalName('ACME Corporation Ltd');
        $this->assertEquals('ACME Corporation Ltd', $this->company->getLegalName());
    }

    public function testGoogleDriveLinkMutator(): void
    {
        $link = 'https://drive.google.com/folder/123';
        $this->company->setGoogleDriveLink($link);
        $this->assertEquals($link, $this->company->getGoogleDriveLink());
    }

    public function testCreatedAtIsSetOnConstruction(): void
    {
        $company = new Company();
        $this->assertInstanceOf(\DateTimeInterface::class, $company->getCreatedAt());
    }

    public function testUpdatedAtMutator(): void
    {
        $date = new \DateTime('2025-11-12');
        $this->company->setUpdatedAt($date);
        $this->assertEquals($date, $this->company->getUpdatedAt());
    }

    public function testActivitiesCollectionInitialized(): void
    {
        $this->assertCount(0, $this->company->getActivities());
    }

    public function testRfqsCollectionInitialized(): void
    {
        $this->assertCount(0, $this->company->getRfqs());
    }
}