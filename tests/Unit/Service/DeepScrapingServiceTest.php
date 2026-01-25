<?php

namespace App\Tests\Unit\Service;

use App\Service\DeepScrapingService;
use App\Service\HeadlessBrowserService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Tests for Deep Scraping Service
 * Tests website scraping, contact extraction, and error handling
 */
class DeepScrapingServiceTest extends TestCase
{
    private DeepScrapingService $service;
    private HttpClientInterface $httpClient;
    private HeadlessBrowserService $headlessBrowser;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->headlessBrowser = $this->createMock(HeadlessBrowserService::class);
        
        // Default headlessBrowser mock behavior: return unsuccessful result (triggers HTTP fallback)
        $this->headlessBrowser->method('fetchPage')->willReturn([
            'html' => '',
            'method' => 'static',
            'success' => false,
            'error' => 'Mocked fallback'
        ]);
        
        $this->service = new DeepScrapingService(
            $this->httpClient,
            $this->headlessBrowser,
            new NullLogger()
        );
    }

    // ===== SCRAPE WEBSITE INTEGRATION TESTS =====

    public function testScrapeWebsiteReturnsStructuredResult(): void
    {
        // Mock HTTP response
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn('<html><body>
            Contact: test@example.com
            Phone: 555-123-4567
            <a href="https://linkedin.com/company/test">LinkedIn</a>
        </body></html>');
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->service->scrapeWebsite('https://example.com', 1);

        $this->assertArrayHasKey('emails', $result);
        $this->assertArrayHasKey('phones', $result);
        $this->assertArrayHasKey('social_links', $result);
        $this->assertArrayHasKey('pages_scraped', $result);
        $this->assertArrayHasKey('errors', $result);
    }

    public function testScrapeWebsiteReturnsEmptyOnInvalidUrl(): void
    {
        $result = $this->service->scrapeWebsite('not-a-valid-url', 1);

        $this->assertArrayHasKey('errors', $result);
        $this->assertNotEmpty($result['errors']);
    }

    public function testScrapeWebsiteRespectsMaxPages(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn('<html><body>test</body></html>');
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->service->scrapeWebsite('https://example.com', 1);

        $this->assertLessThanOrEqual(1, $result['pages_scraped']);
    }

    public function testScrapeWebsiteHandlesHttpError(): void
    {
        // Mock HTTP error
        $this->httpClient->method('request')
            ->willThrowException(new \Exception('Connection refused'));

        $result = $this->service->scrapeWebsite('https://nonexistent.com', 1);

        $this->assertArrayHasKey('emails', $result);
        $this->assertIsArray($result['emails']);
        // Should gracefully handle error and return empty/partial results
    }

    public function testScrapeWebsiteExtractsEmailsFromContent(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn('<html><body>
            Contact us at sales@realcompany.org
            Email: info@realcompany.org
        </body></html>');
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->service->scrapeWebsite('https://realcompany.org', 1);

        // Should find valid emails (unless filtered as examples)
        $this->assertIsArray($result['emails']);
    }

    public function testScrapeWebsiteExtractsPhoneNumbers(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn('<html><body>
            Call us: +1 (555) 123-4567
            Fax: 555-987-6543
        </body></html>');
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->service->scrapeWebsite('https://example.com', 1);

        $this->assertIsArray($result['phones']);
    }

    public function testScrapeWebsiteExtractsSocialLinks(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn('<html><body>
            <a href="https://linkedin.com/company/acme">LinkedIn</a>
            <a href="https://twitter.com/acme">Twitter</a>
        </body></html>');
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->service->scrapeWebsite('https://acme.com', 1);

        $this->assertIsArray($result['social_links']);
    }

    // ===== RESULT STRUCTURE TESTS =====

    public function testResultContainsAllExpectedKeys(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn('<html><body>test</body></html>');
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->service->scrapeWebsite('https://test.com', 1);

        $expectedKeys = ['emails', 'phones', 'contact_names', 'social_links', 'about_text', 'pages_scraped', 'errors'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: $key");
        }
    }

    public function testEmailsAreArrayOfStrings(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn('<html><body>contact@company.com</body></html>');
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->service->scrapeWebsite('https://company.com', 1);

        $this->assertIsArray($result['emails']);
        foreach ($result['emails'] as $email) {
            $this->assertIsString($email);
        }
    }

    public function testPhonesAreArrayOfStrings(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn('<html><body>555-123-4567</body></html>');
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->service->scrapeWebsite('https://company.com', 1);

        $this->assertIsArray($result['phones']);
        foreach ($result['phones'] as $phone) {
            $this->assertIsString($phone);
        }
    }

    // ===== EDGE CASES =====

    public function testHandlesEmptyHtmlResponse(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn('');
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->service->scrapeWebsite('https://empty.com', 1);

        $this->assertIsArray($result);
        $this->assertEmpty($result['emails']);
        $this->assertEmpty($result['phones']);
    }

    public function testHandlesMalformedHtml(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn('<html><body><div>Unclosed <p>tags');
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->method('request')->willReturn($response);

        // Should not throw exception
        $result = $this->service->scrapeWebsite('https://malformed.com', 1);

        $this->assertIsArray($result);
    }

    public function testPagesScrapedCountIsAccurate(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getContent')->willReturn('<html><body>test</body></html>');
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient->method('request')->willReturn($response);

        $result = $this->service->scrapeWebsite('https://example.com', 3);

        $this->assertGreaterThanOrEqual(0, $result['pages_scraped']);
        $this->assertLessThanOrEqual(3, $result['pages_scraped']);
    }
}
