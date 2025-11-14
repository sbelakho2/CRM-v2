<?php

namespace App\Tests\Unit\Service\WebCrawler;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use App\Service\WebCrawler\CompanyDiscoveryService;
use App\Service\WebCrawler\GoogleDorkService;
use App\Service\WebCrawler\LinkedInScraperService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CompanyDiscoveryServiceTest extends TestCase
{
    private CompanyDiscoveryService $service;
    private EntityManagerInterface $em;
    private CompanyRepository $companyRepo;
    private LinkedInScraperService $linkedInScraper;
    private GoogleDorkService $googleDork;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->companyRepo = $this->createMock(CompanyRepository::class);
        $this->linkedInScraper = $this->createMock(LinkedInScraperService::class);
        $this->googleDork = $this->createMock(GoogleDorkService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new CompanyDiscoveryService(
            $this->em,
            $this->companyRepo,
            $this->linkedInScraper,
            $this->googleDork,
            $this->logger
        );
    }

    public function testDiscoverCompaniesCallsBothServices(): void
    {
        $sector = 'Automotive';
        $location = 'Tanger Free Zone';

        $googleResults = [
            ['name' => 'Company A', 'website' => 'https://companya.com'],
        ];

        $linkedInResults = [
            ['name' => 'Company B', 'linkedin_url' => 'https://linkedin.com/company/b'],
        ];

        $this->googleDork->expects($this->once())
            ->method('searchCompanies')
            ->with($sector, $location)
            ->willReturn($googleResults);

        $this->linkedInScraper->expects($this->once())
            ->method('searchCompanies')
            ->with($sector, $location)
            ->willReturn($linkedInResults);

        $this->companyRepo->method('findOneBy')
            ->willReturn(null); // No existing companies

        $this->em->expects($this->exactly(2))->method('persist');
        $this->em->expects($this->once())->method('flush');

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

        $this->linkedInScraper->method('searchCompanies')
            ->willReturn([]);

        // First company exists, second is new
        $existingCompany = new Company();
        $existingCompany->setName('Existing Company');

        $this->companyRepo->expects($this->exactly(2))
            ->method('findOneBy')
            ->willReturnCallback(function($criteria) use ($existingCompany) {
                return $criteria['name'] === 'Existing Company' ? $existingCompany : null;
            });

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
                'linkedin_url' => 'https://linkedin.com/company/testaero'
            ],
        ];

        $this->googleDork->method('searchCompanies')
            ->willReturn($googleResults);

        $this->linkedInScraper->method('searchCompanies')
            ->willReturn([]);

        $this->companyRepo->method('findOneBy')
            ->willReturn(null);

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
        $this->assertEquals('https://linkedin.com/company/testaero', $capturedCompany->getLinkedinCompanyUrl());
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

        $this->linkedInScraper->method('searchCompanies')
            ->willReturn([]);

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

    public function testEnrichCompanyDataFindsLinkedInProfile(): void
    {
        $company = new Company();
        $company->setName('Test Company');
        $company->setWebsite('https://test.com');

        $expectedLinkedInUrl = 'https://linkedin.com/company/test';

        $this->linkedInScraper->expects($this->once())
            ->method('findCompanyProfile')
            ->with('Test Company')
            ->willReturn($expectedLinkedInUrl);

        $this->googleDork->expects($this->never())
            ->method('findCompanyWebsite');

        $this->em->expects($this->once())->method('flush');

        $this->service->enrichCompanyData($company);

        $this->assertEquals($expectedLinkedInUrl, $company->getLinkedinCompanyUrl());
    }

    public function testEnrichCompanyDataFindsWebsite(): void
    {
        $company = new Company();
        $company->setName('Test Company');
        $company->setLinkedinCompanyUrl('https://linkedin.com/company/test');

        $expectedWebsite = 'https://test.com';

        $this->googleDork->expects($this->once())
            ->method('findCompanyWebsite')
            ->with('Test Company')
            ->willReturn($expectedWebsite);

        $this->linkedInScraper->expects($this->never())
            ->method('findCompanyProfile');

        $this->em->expects($this->once())->method('flush');

        $this->service->enrichCompanyData($company);

        $this->assertEquals($expectedWebsite, $company->getWebsite());
    }

    public function testEnrichCompanyDataFindsBothWhenMissing(): void
    {
        $company = new Company();
        $company->setName('Test Company');

        $this->linkedInScraper->expects($this->once())
            ->method('findCompanyProfile')
            ->willReturn('https://linkedin.com/company/test');

        $this->googleDork->expects($this->once())
            ->method('findCompanyWebsite')
            ->willReturn('https://test.com');

        $this->em->expects($this->once())->method('flush');

        $this->service->enrichCompanyData($company);

        $this->assertNotNull($company->getLinkedinCompanyUrl());
        $this->assertNotNull($company->getWebsite());
    }

    public function testEnrichCompanyDataDoesNothingWhenDataComplete(): void
    {
        $company = new Company();
        $company->setName('Test Company');
        $company->setLinkedinCompanyUrl('https://linkedin.com/company/test');
        $company->setWebsite('https://test.com');

        $this->linkedInScraper->expects($this->never())
            ->method('findCompanyProfile');

        $this->googleDork->expects($this->never())
            ->method('findCompanyWebsite');

        $this->em->expects($this->once())->method('flush');

        $this->service->enrichCompanyData($company);
    }

    public function testEnrichCompanyDataHandlesNullResults(): void
    {
        $company = new Company();
        $company->setName('Test Company');

        $this->linkedInScraper->method('findCompanyProfile')
            ->willReturn(null);

        $this->googleDork->method('findCompanyWebsite')
            ->willReturn(null);

        $this->em->expects($this->once())->method('flush');

        $this->service->enrichCompanyData($company);

        $this->assertNull($company->getLinkedinCompanyUrl());
        $this->assertNull($company->getWebsite());
    }
}
