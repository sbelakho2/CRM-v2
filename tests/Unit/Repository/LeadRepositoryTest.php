<?php

namespace App\Tests\Unit\Repository;

use App\Entity\Lead;
use App\Repository\LeadRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;
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

    public function testFindByRegionMethodSignature()
    {
        $reflection = new \ReflectionMethod(LeadRepository::class, 'findByRegion');
        
        $this->assertEquals('findByRegion', $reflection->getName());
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertEquals('regionTag', $parameters[0]->getName());
        
        // Check return type
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    public function testFindPendingLeadsMethodSignature()
    {
        $reflection = new \ReflectionMethod(LeadRepository::class, 'findPendingLeads');
        
        $this->assertEquals('findPendingLeads', $reflection->getName());
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

    public function testFindByScoreThresholdMethodSignature()
    {
        $reflection = new \ReflectionMethod(LeadRepository::class, 'findByScoreThreshold');
        
        $this->assertEquals('findByScoreThreshold', $reflection->getName());
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(2, $parameters);
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
        
        $this->assertEquals('findByDupeKey', $reflection->getName());
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertEquals('dupeKey', $parameters[0]->getName());
        
        // Check return type (nullable Lead)
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertTrue($returnType->allowsNull());
    }

    public function testGetStatsByRegionMethodSignature()
    {
        $reflection = new \ReflectionMethod(LeadRepository::class, 'getStatsByRegion');
        
        $this->assertEquals('getStatsByRegion', $reflection->getName());
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(0, $parameters);
        
        // Check return type
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    public function testGetTopLeadsMethodSignature()
    {
        $reflection = new \ReflectionMethod(LeadRepository::class, 'getTopLeads');
        
        $this->assertEquals('getTopLeads', $reflection->getName());
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(2, $parameters);
        $this->assertEquals('limit', $parameters[0]->getName());
        $this->assertEquals('regionTag', $parameters[1]->getName());
        
        // Check default values
        $this->assertTrue($parameters[0]->isDefaultValueAvailable());
        $this->assertEquals(50, $parameters[0]->getDefaultValue());
        
        $this->assertTrue($parameters[1]->allowsNull());
        
        // Check return type
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    public function testGetApprovalRateMethodSignature()
    {
        $reflection = new \ReflectionMethod(LeadRepository::class, 'getApprovalRate');
        
        $this->assertEquals('getApprovalRate', $reflection->getName());
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(2, $parameters);
        $this->assertEquals('regionTag', $parameters[0]->getName());
        $this->assertEquals('since', $parameters[1]->getName());
        
        // Check nullable parameters
        $this->assertTrue($parameters[0]->allowsNull());
        $this->assertTrue($parameters[1]->allowsNull());
        
        // Check return type
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
}
