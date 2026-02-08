<?php

namespace App\Tests\Unit\Repository;

use App\Entity\Lead;
use App\Repository\LeadRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\AbstractQuery;
use PHPUnit\Framework\TestCase;

class LeadRepositoryTest extends TestCase
{
    private ManagerRegistry $registry;
    private EntityManagerInterface $entityManager;
    private LeadRepository $repository;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->registry->method('getManagerForClass')
            ->willReturn($this->entityManager);

        $this->repository = new LeadRepository($this->registry);
    }

    // ================================================================
    // Method Existence & Signature Tests
    // ================================================================

    public function testFindByRegionExists()
    {
        $this->assertTrue(method_exists($this->repository, 'findByRegion'));
    }

    public function testFindPendingLeadsExists()
    {
        $this->assertTrue(method_exists($this->repository, 'findPendingLeads'));
    }

    public function testFindByScoreThresholdExists()
    {
        $this->assertTrue(method_exists($this->repository, 'findByScoreThreshold'));
    }

    public function testFindByDupeKeyExists()
    {
        $this->assertTrue(method_exists($this->repository, 'findByDupeKey'));
    }

    public function testGetStatsByRegionExists()
    {
        $this->assertTrue(method_exists($this->repository, 'getStatsByRegion'));
    }

    public function testGetTopLeadsExists()
    {
        $this->assertTrue(method_exists($this->repository, 'getTopLeads'));
    }

    public function testGetApprovalRateExists()
    {
        $this->assertTrue(method_exists($this->repository, 'getApprovalRate'));
    }

    public function testFindByWebsiteRootExists()
    {
        $this->assertTrue(method_exists($this->repository, 'findByWebsiteRoot'));
    }

    public function testCountLeadsNeedingEnrichmentExists()
    {
        $this->assertTrue(method_exists($this->repository, 'countLeadsNeedingEnrichment'));
    }

    // ================================================================
    // Method Signature Tests
    // ================================================================

    public function testFindByRegionMethodSignature()
    {
        $reflection = new \ReflectionMethod(LeadRepository::class, 'findByRegion');
        
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertGreaterThanOrEqual(1, count($parameters));
        $this->assertEquals('regionTag', $parameters[0]->getName());
        
        // Check return type
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    public function testFindByRegionAcceptsPaginationParameters()
    {
        $reflection = new \ReflectionMethod(LeadRepository::class, 'findByRegion');
        $parameters = $reflection->getParameters();
        
        // Should accept page and limit with defaults
        $this->assertGreaterThanOrEqual(3, count($parameters));
        $this->assertEquals('page', $parameters[1]->getName());
        $this->assertTrue($parameters[1]->isDefaultValueAvailable());
        $this->assertEquals(1, $parameters[1]->getDefaultValue());
        
        $this->assertEquals('limit', $parameters[2]->getName());
        $this->assertTrue($parameters[2]->isDefaultValueAvailable());
        $this->assertEquals(50, $parameters[2]->getDefaultValue());
    }

    public function testFindPendingLeadsMethodSignature()
    {
        $reflection = new \ReflectionMethod(LeadRepository::class, 'findPendingLeads');
        
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertEquals('regionTag', $parameters[0]->getName());
        
        // Check nullable parameter
        $this->assertTrue($parameters[0]->allowsNull());
        $this->assertTrue($parameters[0]->isDefaultValueAvailable());
        $this->assertNull($parameters[0]->getDefaultValue());
        
        // Check return type
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    public function testFindByScoreThresholdAcceptsPagination()
    {
        $reflection = new \ReflectionMethod(LeadRepository::class, 'findByScoreThreshold');
        
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertGreaterThanOrEqual(2, count($parameters));
        $this->assertEquals('minScore', $parameters[0]->getName());
        $this->assertEquals('regionTag', $parameters[1]->getName());
        
        // Check nullable parameter
        $this->assertTrue($parameters[1]->allowsNull());
        
        // Check return type
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    public function testFindByDupeKeyMethodSignature()
    {
        $reflection = new \ReflectionMethod(LeadRepository::class, 'findByDupeKey');
        
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertEquals('dupeKey', $parameters[0]->getName());
        
        // Check return type (nullable Lead)
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertTrue($returnType->allowsNull());
    }

    public function testFindByWebsiteRootMethodSignature()
    {
        $reflection = new \ReflectionMethod(LeadRepository::class, 'findByWebsiteRoot');
        
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertEquals('websiteRoot', $parameters[0]->getName());
        
        // Returns nullable Lead
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertTrue($returnType->allowsNull());
    }

    public function testGetStatsByRegionMethodSignature()
    {
        $reflection = new \ReflectionMethod(LeadRepository::class, 'getStatsByRegion');
        
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(0, $parameters);
        
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    public function testGetTopLeadsMethodSignature()
    {
        $reflection = new \ReflectionMethod(LeadRepository::class, 'getTopLeads');
        
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(2, $parameters);
        $this->assertEquals('limit', $parameters[0]->getName());
        $this->assertEquals('regionTag', $parameters[1]->getName());
        
        $this->assertTrue($parameters[0]->isDefaultValueAvailable());
        $this->assertEquals(50, $parameters[0]->getDefaultValue());
        $this->assertTrue($parameters[1]->allowsNull());
        
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    public function testGetApprovalRateMethodSignature()
    {
        $reflection = new \ReflectionMethod(LeadRepository::class, 'getApprovalRate');
        
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(2, $parameters);
        $this->assertEquals('regionTag', $parameters[0]->getName());
        $this->assertEquals('since', $parameters[1]->getName());
        
        $this->assertTrue($parameters[0]->allowsNull());
        $this->assertTrue($parameters[1]->allowsNull());
        
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    public function testRepositoryExtendsServiceEntityRepository()
    {
        $this->assertInstanceOf(
            'Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository',
            $this->repository
        );
    }

    // ================================================================
    // Lead Entity Tests (comprehensive field testing)
    // ================================================================

    public function testLeadEntityDefaults()
    {
        $lead = new Lead();
        
        $this->assertNull($lead->getId());
        $this->assertEquals('pending', $lead->getReviewStatus());
        $this->assertFalse($lead->hasContactForm());
        $this->assertNotNull($lead->getCreatedAt());
        $this->assertNull($lead->getLeadScore());
    }

    public function testLeadEntityFluentInterface()
    {
        $lead = new Lead();
        
        $result = $lead->setCompanyName('Test Corp')
            ->setWebsiteRoot('https://test.com')
            ->setLeadScore(75)
            ->setRegionTag('morocco')
            ->setReviewStatus('approved');
        
        $this->assertSame($lead, $result);
        $this->assertEquals('Test Corp', $lead->getCompanyName());
        $this->assertEquals('https://test.com', $lead->getWebsiteRoot());
        $this->assertEquals(75, $lead->getLeadScore());
        $this->assertEquals('morocco', $lead->getRegionTag());
        $this->assertEquals('approved', $lead->getReviewStatus());
    }

    public function testLeadScrapingMetadata()
    {
        $lead = new Lead();
        $now = new \DateTime();
        
        $lead->setScrapingMethod('panther');
        $lead->setPagesScraped(5);
        $lead->setLastScrapedAt($now);
        $lead->setHasContactForm(true);
        
        $this->assertEquals('panther', $lead->getScrapingMethod());
        $this->assertEquals(5, $lead->getPagesScraped());
        $this->assertSame($now, $lead->getLastScrapedAt());
        $this->assertTrue($lead->hasContactForm());
        $this->assertTrue($lead->getHasContactForm());
        $this->assertTrue($lead->wasScrapedWithHeadless());
    }

    public function testLeadHasContactInfo()
    {
        $lead = new Lead();
        
        // No contact info initially
        $this->assertFalse($lead->hasContactInfo());
        
        // With emails
        $lead->setContactEmailsPublic(['test@example.com']);
        $this->assertTrue($lead->hasContactInfo());
        
        // Reset, try with contact form
        $lead->setContactEmailsPublic(null);
        $lead->setHasContactForm(true);
        $this->assertTrue($lead->hasContactInfo());
        
        // Reset, try with contact form URL
        $lead->setHasContactForm(false);
        $lead->setContactFormUrl('https://test.com/contact');
        $this->assertTrue($lead->hasContactInfo());
    }

    public function testLeadSourceColumn()
    {
        $lead = new Lead();
        
        $lead->setSource('Google Search: automotive EMS');
        $this->assertEquals('Google Search: automotive EMS', $lead->getSource());
        
        // Source and description are now independent
        $lead->setDescription('A great automotive company');
        $this->assertEquals('Google Search: automotive EMS', $lead->getSource());
        $this->assertEquals('A great automotive company', $lead->getDescription());
    }

    public function testLeadAliasMethodsWork()
    {
        $lead = new Lead();
        
        $lead->setWebsite('https://example.com');
        $this->assertEquals('https://example.com', $lead->getWebsite());
        $this->assertEquals('https://example.com', $lead->getWebsiteRoot());
    }

    public function testLeadJsonFields()
    {
        $lead = new Lead();
        
        $lead->setSectorTags(['automotive', 'aerospace']);
        $this->assertEquals(['automotive', 'aerospace'], $lead->getSectorTags());
        
        $lead->setFitSignals(['pcba' => true, 'smt' => true]);
        $this->assertEquals(['pcba' => true, 'smt' => true], $lead->getFitSignals());
        
        $lead->setQualityStack(['ISO 9001', 'IATF 16949']);
        $this->assertEquals(['ISO 9001', 'IATF 16949'], $lead->getQualityStack());
    }
}
