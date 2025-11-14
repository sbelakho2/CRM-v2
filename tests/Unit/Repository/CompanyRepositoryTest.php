<?php

namespace App\Tests\Unit\Repository;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

class CompanyRepositoryTest extends TestCase
{
    private ManagerRegistry $registry;
    private EntityManagerInterface $entityManager;
    private CompanyRepository $repository;
    private QueryBuilder $queryBuilder;
    private AbstractQuery $query;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->query = $this->createMock(AbstractQuery::class);

        // Setup registry to return entity manager
        $this->registry->method('getManagerForClass')
            ->willReturn($this->entityManager);

        $this->repository = new CompanyRepository($this->registry);
    }

    public function testCountByStageAndPeriod()
    {
        $this->assertTrue(method_exists($this->repository, 'countByStageAndPeriod'));
    }

    public function testFindBySectorAndTier()
    {
        $this->assertTrue(method_exists($this->repository, 'findBySectorAndTier'));
    }

    public function testCountBySector()
    {
        $this->assertTrue(method_exists($this->repository, 'countBySector'));
    }

    public function testCountByPipelineStage()
    {
        $this->assertTrue(method_exists($this->repository, 'countByPipelineStage'));
    }

    /**
     * Test method signatures and parameter types
     */
    public function testCountByStageAndPeriodMethodSignature()
    {
        $reflection = new \ReflectionMethod(CompanyRepository::class, 'countByStageAndPeriod');
        
        $this->assertEquals('countByStageAndPeriod', $reflection->getName());
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(3, $parameters);
        $this->assertEquals('stage', $parameters[0]->getName());
        $this->assertEquals('startDate', $parameters[1]->getName());
        $this->assertEquals('endDate', $parameters[2]->getName());
    }

    public function testFindBySectorAndTierMethodSignature()
    {
        $reflection = new \ReflectionMethod(CompanyRepository::class, 'findBySectorAndTier');
        
        $this->assertEquals('findBySectorAndTier', $reflection->getName());
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(2, $parameters);
        $this->assertEquals('sector', $parameters[0]->getName());
        $this->assertEquals('tier', $parameters[1]->getName());
    }

    public function testCountBySectorMethodSignature()
    {
        $reflection = new \ReflectionMethod(CompanyRepository::class, 'countBySector');
        
        $this->assertEquals('countBySector', $reflection->getName());
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertEquals('sector', $parameters[0]->getName());
    }

    public function testCountByPipelineStageMethodSignature()
    {
        $reflection = new \ReflectionMethod(CompanyRepository::class, 'countByPipelineStage');
        
        $this->assertEquals('countByPipelineStage', $reflection->getName());
        $this->assertTrue($reflection->isPublic());
        
        $parameters = $reflection->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertEquals('stage', $parameters[0]->getName());
    }

    public function testRepositoryExtendsServiceEntityRepository()
    {
        $this->assertInstanceOf(
            'Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository',
            $this->repository
        );
    }
}
