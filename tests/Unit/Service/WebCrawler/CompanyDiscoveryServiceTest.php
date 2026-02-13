<?php

namespace App\Tests\Unit\Service\WebCrawler;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use App\Service\WebCrawler\CompanyDiscoveryService;
use App\Service\WebCrawler\GoogleDorkService;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CompanyDiscoveryServiceTest extends TestCase
{
    private CompanyDiscoveryService $service;
    private EntityManagerInterface $em;
    private CompanyRepository $companyRepo;
    private GoogleDorkService $googleDork;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->companyRepo = $this->createMock(CompanyRepository::class);
        $this->googleDork = $this->createMock(GoogleDorkService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new CompanyDiscoveryService(
            $this->em,
            $this->companyRepo,
            $this->googleDork,
            $this->logger
        );
    }

    /**
     * Set up createQueryBuilder mock so case-insensitive name dedup works.
     * Returns null (no match) by default.
     */
    private function mockQueryBuilder(?Company $returnValue = null): void
    {
        $query = $this->createMock(AbstractQuery::class);
        $query->method('getOneOrNullResult')->willReturn($returnValue);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->companyRepo->method('createQueryBuilder')->willReturn($qb);
    }

    public function testDiscoverCompaniesCallsGoogleDork(): void
    {
        $sector = 'Automotive';
        $location = 'Tanger Free Zone';

        $googleResults = [
            ['name' => 'Company A', 'website' => 'https://companya.com'],
            ['name' => 'Company B', 'website' => 'https://companyb.com'],
        ];

        $this->googleDork->expects($this->once())
            ->method('searchCompanies')
            ->with($sector, $location)
            ->willReturn($googleResults);

        $this->companyRepo->method('findOneBy')
            ->willReturn(null); // No existing companies

        $this->companyRepo->method('findAll')->willReturn([]);
        $this->mockQueryBuilder(null);

        $this->em->expects($this->exactly(2))->method('persist');
        $this->em->expects($this->exactly(2))->method('flush');

        $result = $this->service->discoverCompanies($sector, $location);

        $this->assertCount(2, $result);
    }

    public function testDiscoverCompaniesSkipsDuplicates(): void
    {
        $sector = 'Automotive';
        $location = 'Tanger Free Zone';

        $googleResults = [
            ['name' => 'Existing Company', 'website' => 'https://existing.com'],
            ['name' => 'New Company', 'website' => 'https://new.com'],
        ];

        $this->googleDork->method('searchCompanies')
            ->willReturn($googleResults);

        // First company exists (by domain), second is new
        $existingCompany = new Company();
        $existingCompany->setName('Existing Company');
        $existingCompany->setWebsite('https://existing.com');

        // findAll returns existing companies for domain-level dedup
        $this->companyRepo->expects($this->once())
            ->method('findAll')
            ->willReturn([$existingCompany]);

        $this->mockQueryBuilder(null);

        // Should only persist the new company
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $result = $this->service->discoverCompanies($sector, $location);

        $this->assertCount(1, $result);
        $this->assertEquals('New Company', $result[0]->getName());
    }

    public function testSavedCompanyHasCorrectDefaults(): void
    {
        $sector = 'Aerospace';
        $location = 'Atlantic Free Zone Kenitra';

        $googleResults = [
            [
                'name' => 'Test Aerospace Company',
                'website' => 'https://testaero.com',
            ],
        ];

        $this->googleDork->method('searchCompanies')
            ->willReturn($googleResults);

        $this->companyRepo->method('findOneBy')
            ->willReturn(null);

        $this->companyRepo->method('findAll')->willReturn([]);
        $this->mockQueryBuilder(null);

        $capturedCompany = null;
        $this->em->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function($company) use (&$capturedCompany) {
                $capturedCompany = $company;
            });

        $this->em->expects($this->once())->method('flush');

        $this->service->discoverCompanies($sector, $location);

        $this->assertNotNull($capturedCompany);
        $this->assertEquals('Test Aerospace Company', $capturedCompany->getName());
        $this->assertEquals($sector, $capturedCompany->getSector());
        $this->assertEquals($location, $capturedCompany->getPhysicalSite());
        $this->assertEquals('https://testaero.com', $capturedCompany->getWebsite());
        $this->assertEquals('Prospect', $capturedCompany->getPipelineStage());
        $this->assertEquals('C', $capturedCompany->getAccountTier());
        $this->assertStringContainsString('Auto-discovered by webcrawler', $capturedCompany->getSourceNotes());
    }

    public function testDiscoverCompaniesLogsProgress(): void
    {
        $sector = 'Industrial';
        $location = 'Casablanca';

        $this->googleDork->method('searchCompanies')
            ->willReturn([]);

        $this->companyRepo->method('findAll')->willReturn([]);

        $this->logger->expects($this->exactly(2))
            ->method('info')
            ->withConsecutive(
                [
                    'Starting company discovery',
                    $this->arrayHasKey('sector')
                ],
                [
                    'Company discovery completed',
                    $this->arrayHasKey('discovered')
                ]
            );

        $this->service->discoverCompanies($sector, $location);
    }

    public function testEnrichCompanyDataFindsWebsite(): void
    {
        $company = new Company();
        $company->setName('Test Company');
        // No website set, so enrichment should look it up

        $expectedWebsite = 'https://test.com';

        $this->googleDork->expects($this->once())
            ->method('findCompanyWebsite')
            ->with('Test Company')
            ->willReturn($expectedWebsite);

        $this->em->expects($this->once())->method('flush');

        $this->service->enrichCompanyData($company);

        $this->assertEquals($expectedWebsite, $company->getWebsite());
    }

    public function testEnrichCompanyDataDoesNothingWhenWebsiteExists(): void
    {
        $company = new Company();
        $company->setName('Test Company');
        $company->setWebsite('https://test.com');

        // Should NOT call findCompanyWebsite since website already exists
        $this->googleDork->expects($this->never())
            ->method('findCompanyWebsite');

        $this->em->expects($this->once())->method('flush');

        $this->service->enrichCompanyData($company);
    }

    public function testEnrichCompanyDataHandlesNullWebsiteResult(): void
    {
        $company = new Company();
        $company->setName('Test Company');

        $this->googleDork->method('findCompanyWebsite')
            ->willReturn(null);

        $this->em->expects($this->once())->method('flush');

        $this->service->enrichCompanyData($company);

        $this->assertNull($company->getWebsite());
    }

    public function testDiscoverCompaniesSkipsInvalidData(): void
    {
        $googleResults = [
            ['name' => 'Valid Company', 'website' => 'https://valid.com'],
            'not an array',  // Invalid
            ['no_name_key' => 'bad'],  // Missing name key
        ];

        $this->googleDork->method('searchCompanies')
            ->willReturn($googleResults);

        $this->companyRepo->method('findOneBy')
            ->willReturn(null);

        $this->companyRepo->method('findAll')->willReturn([]);
        $this->mockQueryBuilder(null);

        // Should only persist the valid company
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $result = $this->service->discoverCompanies('Automotive', 'Morocco');

        $this->assertCount(1, $result);
    }

    public function testDiscoverCompaniesReturnsEmptyForNoResults(): void
    {
        $this->googleDork->method('searchCompanies')
            ->willReturn([]);

        $this->companyRepo->method('findAll')->willReturn([]);

        $result = $this->service->discoverCompanies('Automotive', 'Morocco');

        $this->assertCount(0, $result);
    }
}
