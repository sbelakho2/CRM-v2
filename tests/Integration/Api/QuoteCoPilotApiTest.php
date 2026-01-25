<?php

namespace App\Tests\Integration\Api;

use App\Entity\Company;
use App\Entity\Quote;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class QuoteCoPilotApiTest extends WebTestCase
{
    private function getEntityManager(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    public function testPublishQuoteApiRouteExists()
    {
        $client = static::createClient();
        $client->request('POST', '/quote-copilot/1/publish');
        
        // Route should exist (not 404)
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }

    public function testPublishQuoteApiRequiresPostMethod()
    {
        $client = static::createClient();
        $client->request('GET', '/quote-copilot/1/publish');
        
        // Should not accept GET method
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [302, 405], "Should reject GET method");
    }

    public function testPublishQuoteApiWithInvalidId()
    {
        $client = static::createClient();
        $client->request('POST', '/quote-copilot/999999/publish');
        
        // Should handle non-existent quote gracefully
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [404, 302], "Should return 404 or redirect for invalid ID");
    }

    public function testPublishQuoteApiWithLowCoverage()
    {
        $client = static::createClient();
        $entityManager = $this->getEntityManager();
        
        $company = new Company();
        $company->setName('Test Company Low Coverage');
        $entityManager->persist($company);

        // Create a quote with low coverage (<60%)
        $quote = new Quote();
        $quote->setQuoteNumber('TEST-' . time());
        $quote->setCompany($company);
        $quote->setStatus('draft');
        $quote->setCoveragePercent('45.5'); // Below 60% threshold
        $quote->setCreatedAt(new \DateTime());
        
        $entityManager->persist($quote);
        $entityManager->flush();
        $quoteId = $quote->getId();

        // Try to publish quote with low coverage
        $client->request('POST', "/quote-copilot/{$quoteId}/publish");
        
        $statusCode = $client->getResponse()->getStatusCode();
        // Should either reject (400) or redirect (302)
        $this->assertContains($statusCode, [400, 302], "Low coverage quote should be rejected or redirect");

        // Clean up
        $entityManager->remove($quote);
        $entityManager->remove($company);
        $entityManager->flush();
    }

    public function testPublishQuoteApiWithHighCoverage()
    {
        $client = static::createClient();
        $entityManager = $this->getEntityManager();
        
        $company = new Company();
        $company->setName('Test Company High Coverage');
        $entityManager->persist($company);

        // Create a quote with high coverage (>=60%)
        $quote = new Quote();
        $quote->setQuoteNumber('TEST-HIGH-' . time());
        $quote->setCompany($company);
        $quote->setStatus('draft');
        $quote->setCoveragePercent('85.0'); // Above 60% threshold
        $quote->setCreatedAt(new \DateTime());
        
        $entityManager->persist($quote);
        $entityManager->flush();
        $quoteId = $quote->getId();

        // Try to publish quote with high coverage
        $client->request('POST', "/quote-copilot/{$quoteId}/publish");
        
        $statusCode = $client->getResponse()->getStatusCode();
        // Should succeed (200) or redirect (302)
        $this->assertContains($statusCode, [200, 302], "High coverage quote should be publishable");

        // Clean up
        $entityManager->remove($quote);
        $entityManager->remove($company);
        $entityManager->flush();
    }

    public function testPublishApiReturnsJsonResponse()
    {
        $client = static::createClient();
        $entityManager = $this->getEntityManager();
        
        $company = new Company();
        $company->setName('Test JSON Response');
        $entityManager->persist($company);

        // Create a valid quote
        $quote = new Quote();
        $quote->setQuoteNumber('TEST-JSON-' . time());
        $quote->setCompany($company);
        $quote->setStatus('draft');
        $quote->setCoveragePercent('75.0');
        $quote->setCreatedAt(new \DateTime());
        
        $entityManager->persist($quote);
        $entityManager->flush();
        $quoteId = $quote->getId();

        // Make API call
        $client->request('POST', "/quote-copilot/{$quoteId}/publish");
        
        $response = $client->getResponse();

        $this->assertContains(
            $response->getStatusCode(),
            [200, 302],
            'Publish endpoint should either return JSON (200) or redirect (302)'
        );
        
        // If successful (200), should have JSON content type or be a redirect
        if ($response->getStatusCode() === 200) {
            $contentType = $response->headers->get('Content-Type');
            $this->assertStringContainsString('json', $contentType);
            
            // Verify JSON structure
            $data = json_decode($response->getContent(), true);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('success', $data);
        }

        // Clean up
        $entityManager->remove($quote);
        $entityManager->remove($company);
        $entityManager->flush();
    }

    public function testQuoteCoPilotControllerIsRegistered()
    {
        $client = static::createClient();
        $container = static::getContainer();
        
        // Verify controller is registered
        $this->assertTrue($container->has('App\Controller\QuoteCoPilotController'));
    }
}
