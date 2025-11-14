<?php

namespace App\Tests\Unit\Service;

use App\Service\EmailSegmentService;
use App\Entity\EmailSegment;
use App\Entity\Contact;
use App\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class EmailSegmentServiceTest extends TestCase
{
    public function testValidateFilterRulesEmptyAndInvalid(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $segmentRepo = $this->createMock(\App\Repository\EmailSegmentRepository::class);
        $contactRepo = $this->createMock(\App\Repository\ContactRepository::class);

        $service = new EmailSegmentService($em, $segmentRepo, $contactRepo);

        $this->assertSame([], $service->validateFilterRules([]));

        $errors = $service->validateFilterRules(['operator' => 'XOR', 'rules' => []]);
        $this->assertNotEmpty($errors);

        $errors2 = $service->validateFilterRules(['operator' => 'AND', 'rules' => [['operator' => '=']]]);
        $this->assertNotEmpty($errors2);
    }

    public function testCreateSegmentCalculatesContactCountAndPersists(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $segmentRepo = $this->createMock(\App\Repository\EmailSegmentRepository::class);
        $contactRepo = $this->createMock(\App\Repository\ContactRepository::class);

        $contactRepo->expects($this->once())->method('count')->with([])->willReturn(5);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = new EmailSegmentService($em, $segmentRepo, $contactRepo);

        $segment = $service->createSegment('Test Segment', 'desc', [], true);

        $this->assertInstanceOf(EmailSegment::class, $segment);
        $this->assertEquals(5, $segment->getContactCount());
    }

    public function testGetSegmentContactsAndContactMatchesSegment(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $segmentRepo = $this->createMock(\App\Repository\EmailSegmentRepository::class);
        $contactRepo = $this->createMock(\App\Repository\ContactRepository::class);

        $company = new Company();
        $company->setName('Acme Corp');
        $company->setCountry('US');

        $c1 = new Contact();
        $c1->setFirstName('Alice');
        $c1->setLastName('Smith');
        $c1->setEmail('alice@acme.com');
        $c1->setCompany($company);

        $c2 = new Contact();
        $c2->setFirstName('Bob');
        $c2->setLastName('Jones');
        $c2->setEmail('bob@example.com');

        $contactRepo->method('findAll')->willReturn([$c1, $c2]);

        $service = new EmailSegmentService($em, $segmentRepo, $contactRepo);

        $segment = new EmailSegment();
        $segment->setFilterRulesJson([
            'operator' => 'AND',
            'rules' => [
                ['field' => 'email', 'operator' => 'contains', 'value' => '@acme.com']
            ]
        ]);

        $matches = $service->getSegmentContacts($segment);

        $this->assertCount(1, $matches);
        $this->assertSame('alice@acme.com', $matches[0]->getEmail());

        // Test contactMatchesSegment directly
        $this->assertTrue($service->contactMatchesSegment($c1, $segment));
        $this->assertFalse($service->contactMatchesSegment($c2, $segment));
    }

    public function testEvaluateRuleCompanyFieldAndOperators(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $segmentRepo = $this->createMock(\App\Repository\EmailSegmentRepository::class);
        $contactRepo = $this->createMock(\App\Repository\ContactRepository::class);

        $company = new Company();
        $company->setName('Globex');
        $company->setCountry('US');

        $c = new Contact();
        $c->setFirstName('Charlie');
        $c->setEmail('charlie@globex.com');
        $c->setCompany($company);

        $service = new EmailSegmentService($em, $segmentRepo, $contactRepo);

        $segment = new EmailSegment();
        $segment->setFilterRulesJson(['operator' => 'AND', 'rules' => [
            ['field' => 'company.name', 'operator' => 'contains', 'value' => 'globex'],
            ['field' => 'firstName', 'operator' => 'starts_with', 'value' => 'Char']
        ]]);

        $this->assertTrue($service->contactMatchesSegment($c, $segment));
    }
}
