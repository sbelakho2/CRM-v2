<?php

namespace App\Tests\Unit\Service;

use App\Service\LlmEnrichmentService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class LlmEnrichmentServiceTest extends TestCase
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function createService(?string $apiKey = 'test-key'): LlmEnrichmentService
    {
        return new LlmEnrichmentService(
            $this->httpClient,
            $this->logger,
            $apiKey
        );
    }

    // ================================================================
    // Configuration Tests
    // ================================================================

    public function testIsConfiguredReturnsFalseWithoutApiKey()
    {
        $service = $this->createService(null);
        $this->assertFalse($service->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWithEmptyApiKey()
    {
        $service = $this->createService('');
        $this->assertFalse($service->isConfigured());
    }

    public function testIsConfiguredReturnsTrueWithApiKey()
    {
        $service = $this->createService('sk-test-key');
        $this->assertTrue($service->isConfigured());
    }

    // ================================================================
    // Graceful Degradation Tests (service not configured)
    // ================================================================

    public function testEnrichLeadReturnsNullWhenNotConfigured()
    {
        $service = $this->createService(null);
        
        $result = $service->enrichLeadFromScrapedContent('Test Corp', 'Some about text');
        $this->assertNull($result);
    }

    public function testExtractContactsReturnsEmptyWhenNotConfigured()
    {
        $service = $this->createService(null);
        
        $result = $service->extractContactsFromText('Contact: john@test.com');
        $this->assertEmpty($result);
    }

    public function testClassifyIndustryReturnsNullWhenNotConfigured()
    {
        $service = $this->createService(null);
        
        $result = $service->classifyIndustry('Test Corp', 'Makes automotive parts');
        $this->assertNull($result);
    }

    // ================================================================
    // Successful API Response Tests
    // ================================================================

    public function testEnrichLeadParsesValidJsonResponse()
    {
        $jsonResponse = json_encode([
            'summary' => 'Leading EMS provider',
            'industry' => 'Automotive',
            'key_person' => ['name' => 'John Doe', 'role' => 'CEO'],
            'confidence' => 'high',
        ]);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'choices' => [
                ['message' => ['content' => $jsonResponse]]
            ]
        ]);

        $this->httpClient->method('request')->willReturn($response);

        $service = $this->createService('sk-test');
        $result = $service->enrichLeadFromScrapedContent('Test Corp', 'About text');

        $this->assertIsArray($result);
        $this->assertEquals('Leading EMS provider', $result['summary']);
        $this->assertEquals('Automotive', $result['industry']);
    }

    public function testClassifyIndustryReturnsIndustryName()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'choices' => [
                ['message' => ['content' => 'Automotive']]
            ]
        ]);

        $this->httpClient->method('request')->willReturn($response);

        $service = $this->createService('sk-test');
        $result = $service->classifyIndustry('Test Corp', 'Car parts manufacturer');

        $this->assertEquals('Automotive', $result);
    }

    // ================================================================
    // Error Handling Tests
    // ================================================================

    public function testEnrichLeadReturnsNullOnApiFailure()
    {
        $this->httpClient->method('request')
            ->willThrowException(new \RuntimeException('Connection timeout'));

        // Logger should be called with error
        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $service = $this->createService('sk-test');
        $result = $service->enrichLeadFromScrapedContent('Test Corp', 'About text');

        $this->assertNull($result);
    }

    public function testExtractContactsReturnsEmptyOnApiFailure()
    {
        $this->httpClient->method('request')
            ->willThrowException(new \RuntimeException('Server error'));

        $service = $this->createService('sk-test');
        $result = $service->extractContactsFromText('Contact info here');

        $this->assertEmpty($result);
    }

    // ================================================================
    // JSON Parsing Tests
    // ================================================================

    public function testParsesNestedJsonFromResponse()
    {
        $nestedJson = json_encode([
            'summary' => 'Test',
            'key_person' => ['name' => 'Jane', 'role' => 'CTO'],
        ]);

        // Wrap in markdown code block as LLMs sometimes do
        $wrappedResponse = "Here is the result:\n```json\n{$nestedJson}\n```";

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'choices' => [
                ['message' => ['content' => $wrappedResponse]]
            ]
        ]);

        $this->httpClient->method('request')->willReturn($response);

        $service = $this->createService('sk-test');
        $result = $service->enrichLeadFromScrapedContent('Test Corp', 'About text');

        $this->assertIsArray($result);
        $this->assertEquals('Test', $result['summary']);
    }

    // ================================================================
    // Method Signature Tests
    // ================================================================

    public function testEnrichLeadAcceptsContactNames()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'choices' => [
                ['message' => ['content' => '{"summary": "test"}']]
            ]
        ]);

        $this->httpClient->method('request')->willReturn($response);

        $service = $this->createService('sk-test');
        $result = $service->enrichLeadFromScrapedContent(
            'Test Corp',
            'About text',
            ['John Doe', 'Jane Smith']
        );

        $this->assertIsArray($result);
    }
}
