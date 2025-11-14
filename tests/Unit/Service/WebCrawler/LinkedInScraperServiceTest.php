<?php

namespace App\Tests\Unit\Service\WebCrawler;

use App\Entity\Company;
use App\Service\WebCrawler\LinkedInScraperService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LinkedInScraperServiceTest extends TestCase
{
    private LinkedInScraperService $service;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new LinkedInScraperService($this->logger);
    }

    public function testSearchCompaniesLogsCorrectly(): void
    {
        $sector = 'Automotive';
        $location = 'Tanger Free Zone';

        $this->logger->expects($this->atLeast(1))
            ->method('info')
            ->withConsecutive(
                [
                    'LinkedIn search for companies',
                    ['sector' => $sector, 'location' => $location]
                ]
            );

        $result = $this->service->searchCompanies($sector, $location);

        $this->assertIsArray($result);
    }

    public function testSearchCompaniesWithoutLocation(): void
    {
        $sector = 'Industrial';

        $this->logger->expects($this->atLeast(1))
            ->method('info');

        $result = $this->service->searchCompanies($sector);

        $this->assertIsArray($result);
    }

    public function testFindCompanyProfileLogsSearch(): void
    {
        $companyName = 'Test Company';

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                'LinkedIn company search URL',
                $this->callback(function($context) use ($companyName) {
                    return $context['company'] === $companyName
                        && isset($context['url'])
                        && str_contains($context['url'], 'linkedin.com');
                })
            );

        $result = $this->service->findCompanyProfile($companyName);

        // Currently returns null without API integration
        $this->assertNull($result);
    }

    public function testFindContactsAtCompanyGeneratesSearchUrls(): void
    {
        $company = new Company();
        $company->setName('Test Automotive Company');

        $this->logger->expects($this->exactly(2))
            ->method('info');

        $result = $this->service->findContactsAtCompany($company);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        
        // Verify structure of returned search URLs
        foreach ($result as $searchUrl) {
            $this->assertArrayHasKey('title', $searchUrl);
            $this->assertArrayHasKey('url', $searchUrl);
            $this->assertStringContainsString('linkedin.com', $searchUrl['url']);
        }
    }

    public function testFindContactsAtCompanyIncludesAllProcurementRoles(): void
    {
        $company = new Company();
        $company->setName('Aerospace Company');

        $result = $this->service->findContactsAtCompany($company);

        // Should generate URLs for multiple procurement titles
        $this->assertGreaterThan(1, count($result));
        
        // Check that we have different titles
        $titles = array_column($result, 'title');
        $this->assertContains('Procurement Engineer', $titles);
        $this->assertContains('Buyer', $titles);
    }

    public function testSearchCompaniesHandlesDifferentSectors(): void
    {
        $sectors = ['Automotive', 'Aerospace', 'Industrial', 'Rail', 'Renewables', 'Power Electronics'];

        foreach ($sectors as $sector) {
            $this->logger->expects($this->atLeastOnce())
                ->method('info');

            $result = $this->service->searchCompanies($sector, 'Morocco');
            
            $this->assertIsArray($result);
        }
    }

    public function testExtractContactDataReturnsCorrectStructure(): void
    {
        $linkedInUrl = 'https://linkedin.com/in/john-doe';

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Extracting contact data', ['url' => $linkedInUrl]);

        $result = $this->service->extractContactData($linkedInUrl);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('linkedin_url', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('company', $result);
        $this->assertArrayHasKey('source', $result);
        
        $this->assertEquals($linkedInUrl, $result['linkedin_url']);
        $this->assertEquals('LinkedIn', $result['source']);
    }

    public function testSearchCompaniesGeneratesMoroccoFocusedTerms(): void
    {
        $sector = 'Automotive';
        $location = 'Tanger Free Zone';

        $capturedUrls = [];
        $this->logger->method('info')
            ->willReturnCallback(function($message, $context) use (&$capturedUrls) {
                if ($message === 'Generated LinkedIn search URLs' && isset($context['urls'])) {
                    $capturedUrls = $context['urls'];
                }
            });

        $this->service->searchCompanies($sector, $location);

        // Verify Morocco-related terms are included
        $allUrlsString = implode(' ', $capturedUrls);
        $this->assertStringContainsString('Morocco', $allUrlsString);
    }

    public function testFindContactsAtCompanyLogsCompanyName(): void
    {
        $company = new Company();
        $companyName = 'Specific Test Company';
        $company->setName($companyName);

        $this->logger->expects($this->exactly(2))
            ->method('info')
            ->withConsecutive(
                [
                    'Searching for contacts at company',
                    ['company' => $companyName]
                ],
                [
                    'Generated contact search URLs',
                    $this->callback(function($context) use ($companyName) {
                        return $context['company'] === $companyName
                            && isset($context['count'])
                            && isset($context['urls']);
                    })
                ]
            );

        $this->service->findContactsAtCompany($company);
    }

    public function testExtractContactDataHandlesVariousUrlFormats(): void
    {
        $urls = [
            'https://linkedin.com/in/john-doe',
            'https://www.linkedin.com/in/jane-smith',
            'linkedin.com/in/bob-jones',
        ];

        foreach ($urls as $url) {
            $result = $this->service->extractContactData($url);
            
            $this->assertIsArray($result);
            $this->assertEquals($url, $result['linkedin_url']);
        }
    }
}
