<?php

namespace App\Tests\Unit\Repository;

use App\Entity\AbmHit;
use App\Entity\Company;
use App\Repository\AbmHitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\AbstractQuery;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Statement;
use Doctrine\DBAL\Result;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

class AbmHitRepositoryTest extends TestCase
{
    private ManagerRegistry $registry;
    private EntityManagerInterface $entityManager;
    private AbmHitRepository $repository;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->registry->method('getManagerForClass')
            ->willReturn($this->entityManager);

        $this->repository = new AbmHitRepository($this->registry);
    }

    public function testFindByCompanyExists()
    {
        $this->assertTrue(method_exists($this->repository, 'findByCompany'));
    }

    public function testFindUnidentifiedExists()
    {
        $this->assertTrue(method_exists($this->repository, 'findUnidentified'));
    }

    public function testFindPendingPlaybookExists()
    {
        $this->assertTrue(method_exists($this->repository, 'findPendingPlaybook'));
    }

    public function testCountHitsPerDayExists()
    {
        $this->assertTrue(method_exists($this->repository, 'countHitsPerDay'));
    }

    public function testFindByCompanyMethodSignature()
    {
        $reflection = new \ReflectionMethod(AbmHitRepository::class, 'findByCompany');
        
        $this->assertEquals('findByCompany', $reflection->getName());
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(2, $parameters);
        $this->assertEquals('company', $parameters[0]->getName());
        $this->assertEquals('limit', $parameters[1]->getName());
        
        // Check default value for limit parameter
        $this->assertTrue($parameters[1]->isDefaultValueAvailable());
        $this->assertEquals(100, $parameters[1]->getDefaultValue());
    }

    public function testFindUnidentifiedMethodSignature()
    {
        $reflection = new \ReflectionMethod(AbmHitRepository::class, 'findUnidentified');
        
        $this->assertEquals('findUnidentified', $reflection->getName());
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertEquals('limit', $parameters[0]->getName());
        
        // Check default value
        $this->assertTrue($parameters[0]->isDefaultValueAvailable());
        $this->assertEquals(100, $parameters[0]->getDefaultValue());
    }

    public function testFindPendingPlaybookMethodSignature()
    {
        $reflection = new \ReflectionMethod(AbmHitRepository::class, 'findPendingPlaybook');
        
        $this->assertEquals('findPendingPlaybook', $reflection->getName());
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertEquals('limit', $parameters[0]->getName());
        
        // Check default value
        $this->assertTrue($parameters[0]->isDefaultValueAvailable());
        $this->assertEquals(100, $parameters[0]->getDefaultValue());
    }

    public function testCountHitsPerDayMethodSignature()
    {
        $reflection = new \ReflectionMethod(AbmHitRepository::class, 'countHitsPerDay');
        
        $this->assertEquals('countHitsPerDay', $reflection->getName());
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(2, $parameters);
        $this->assertEquals('startDate', $parameters[0]->getName());
        $this->assertEquals('endDate', $parameters[1]->getName());
        
        // Check that parameters expect DateTimeInterface
        $this->assertEquals('DateTimeInterface', $parameters[0]->getType()->getName());
        $this->assertEquals('DateTimeInterface', $parameters[1]->getType()->getName());
    }

    public function testRepositoryExtendsServiceEntityRepository()
    {
        $this->assertInstanceOf(
            'Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository',
            $this->repository
        );
    }

    public function testFindByCompanyReturnTypeIsArray()
    {
        $reflection = new \ReflectionMethod(AbmHitRepository::class, 'findByCompany');
        $returnType = $reflection->getReturnType();
        
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    public function testFindUnidentifiedReturnTypeIsArray()
    {
        $reflection = new \ReflectionMethod(AbmHitRepository::class, 'findUnidentified');
        $returnType = $reflection->getReturnType();
        
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    public function testFindPendingPlaybookReturnTypeIsArray()
    {
        $reflection = new \ReflectionMethod(AbmHitRepository::class, 'findPendingPlaybook');
        $returnType = $reflection->getReturnType();
        
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    public function testCountHitsPerDayReturnTypeIsArray()
    {
        $reflection = new \ReflectionMethod(AbmHitRepository::class, 'countHitsPerDay');
        $returnType = $reflection->getReturnType();
        
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }
}
