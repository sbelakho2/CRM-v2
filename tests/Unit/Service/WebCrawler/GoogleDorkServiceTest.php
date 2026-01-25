<?php

namespace App\Tests\Unit\Service\WebCrawler;

use App\Service\WebCrawler\GoogleDorkService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoogleDorkServiceTest extends TestCase
{
    private GoogleDorkService $service;
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new GoogleDorkService($this->httpClient, $this->logger);
    }

    public function testSearchCompaniesLogsCorrectly(): void
    {
        $sector = 'Automotive';
        $location = 'Tanger Free Zone';

        $captured = [];
        $this->logger->method('info')->willReturnCallback(function ($message) use (&$captured) {
            $captured[] = $message;
        });

        $result = $this->service->searchCompanies($sector, $location);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertNotEmpty($captured);
    }

    public function testSearchCompaniesWithoutLocation(): void
    {
        $sector = 'Aerospace';

        $result = $this->service->searchCompanies($sector);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testFindCompanyWebsiteLogsSearch(): void
    {
        $companyName = 'Test Company';

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                'Website search',
                $this->callback(function($context) use ($companyName) {
                    return $context['company'] === $companyName
                        && isset($context['url'])
                        && str_contains($context['url'], urlencode($companyName . ' Morocco official website'));
                })
            );

        $result = $this->service->findCompanyWebsite($companyName);

        // Currently returns null without API integration
        $this->assertNull($result);
    }

    public function testFindSupplierPortalGeneratesMultipleQueries(): void
    {
        $companyName = 'Test Company';

        // Expect multiple debug log calls for different dork queries
        $this->logger->expects($this->atLeast(4))
            ->method('debug')
            ->with('Portal search', $this->isType('array'));

        $result = $this->service->findSupplierPortal($companyName);

        // Currently returns null without API integration
        $this->assertNull($result);
    }

    public function testFindContactEmailsWithDomain(): void
    {
        $companyName = 'Test Company';
        $domain = 'testcompany.com';

        // Should generate queries with the provided domain
        $this->logger->expects($this->atLeast(3))
            ->method('debug')
            ->with('Email search', $this->isType('array'));

        $result = $this->service->findContactEmails($companyName, $domain);

        $this->assertIsArray($result);
    }

    public function testFindContactEmailsWithoutDomain(): void
    {
        $companyName = 'Test Company';

        // Should try to guess domain
        $result = $this->service->findContactEmails($companyName);

        $this->assertIsArray($result);
    }

    public function testFindCertificationsGeneratesQueries(): void
    {
        $companyName = 'Test Company';

        // Should generate queries for different certifications
        $this->logger->expects($this->atLeast(4))
            ->method('debug')
            ->with('Certification search', $this->isType('array'));

        $result = $this->service->findCertifications($companyName);

        $this->assertIsArray($result);
    }

    public function testSearchCompaniesGeneratesSectorSpecificQueries(): void
    {
        $automotiveSector = 'Automotive';
        $aerospaceSector = 'Aerospace';

        $auto = $this->service->searchCompanies($automotiveSector, 'Morocco');
        $aero = $this->service->searchCompanies($aerospaceSector, 'Morocco');

        $this->assertNotEmpty($auto);
        $this->assertNotEmpty($aero);
        $this->assertNotEquals($auto, $aero);
    }

    public function testSearchCompaniesHandlesAllTargetSectors(): void
    {
        $sectors = ['Automotive', 'Aerospace', 'Industrial', 'Rail', 'Renewables', 'Power Electronics'];

        foreach ($sectors as $sector) {
            $this->logger->expects($this->atLeastOnce())
                ->method('info');

            $result = $this->service->searchCompanies($sector, 'Morocco');
            
            $this->assertIsArray($result);
        }
    }

    public function testFindSupplierPortalGeneratesCorrectDorkPatterns(): void
    {
        $companyName = 'TestCompany';

        $capturedQueries = [];
        $this->logger->method('debug')
            ->willReturnCallback(function($message, $context) use (&$capturedQueries) {
                if ($message === 'Portal search' && isset($context['query'])) {
                    $capturedQueries[] = $context['query'];
                }
            });

        $this->service->findSupplierPortal($companyName);

        // Verify some expected patterns are present
        $this->assertNotEmpty($capturedQueries);
        $hasSupplierQuery = false;
        foreach ($capturedQueries as $query) {
            if (str_contains($query, 'supplier')) {
                $hasSupplierQuery = true;
                break;
            }
        }
        $this->assertTrue($hasSupplierQuery, 'Should generate supplier-related queries');
    }
}
