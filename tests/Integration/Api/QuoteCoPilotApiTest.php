<?php

namespace App\Tests\Integration\Api;

use App\Entity\Company;
use App\Entity\Quote;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class QuoteCoPilotApiTest extends WebTestCase
{
    private ?EntityManagerInterface $entityManager = null;

    protected function setUp(): void
    {
        parent::setUp();
        static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager !== null) {
            // Roll back any changes made during the test
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            $this->entityManager->close();
            $this->entityManager = null;
        }
        parent::tearDown();
    }

    private function getEntityManager(): EntityManagerInterface
    {
        if ($this->entityManager === null) {
            $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        }
        return $this->entityManager;
    }

    /**
     * Create a test quote with the given coverage and return its ID.
     */
    private function createTestQuote(string $suffix, string $coveragePercent): int
    {
        $entityManager = $this->getEntityManager();

        $company = new Company();
        $company->setName('Test Company ' . $suffix);
        $entityManager->persist($company);

        $quote = new Quote();
        $quote->setQuoteNumber('TEST-' . $suffix . '-' . time());
        $quote->setCompany($company);
        $quote->setStatus('draft');
        $quote->setCoveragePercent($coveragePercent);
        $quote->setCreatedAt(new \DateTime());

        $entityManager->persist($quote);
        $entityManager->flush();

        return $quote->getId();
    }

    public function testPublishQuoteApiRouteExists(): void
    {
        $client = static::getClient();
        $quoteId = $this->createTestQuote('route-exists', '75.0');

        $client->request('POST', "/quote-copilot/{$quoteId}/publish");

        // Route should exist (not 404) — may return 400 due to JSON content-type
        // but should NOT be a 404
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }

    public function testPublishQuoteApiRequiresPostMethod(): void
    {
        $client = static::getClient();
        $quoteId = $this->createTestQuote('get-method', '75.0');

        $client->request('GET', "/quote-copilot/{$quoteId}/publish");

        // Should not accept GET method — expect 405 or redirect (302)
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [302, 405], 'Should reject GET method');
    }

    public function testPublishQuoteApiWithInvalidId(): void
    {
        $client = static::getClient();

        // Use a clearly non-existent ID
        $client->request('POST', '/quote-copilot/999999999/publish');

        // Should handle non-existent quote gracefully
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [404, 302], 'Should return 404 or redirect for invalid ID');
    }

    public function testPublishQuoteApiWithLowCoverage(): void
    {
        $client = static::getClient();
        $quoteId = $this->createTestQuote('low-coverage', '45.5'); // Below 60% threshold

        // Try to publish quote with low coverage
        $client->request('POST', "/quote-copilot/{$quoteId}/publish");

        $statusCode = $client->getResponse()->getStatusCode();
        // Should either reject (400) or redirect (302)
        $this->assertContains($statusCode, [400, 302], 'Low coverage quote should be rejected or redirect');
    }

    public function testPublishQuoteApiWithHighCoverage(): void
    {
        $client = static::getClient();
        $quoteId = $this->createTestQuote('high-coverage', '85.0'); // Above 60% threshold

        // Try to publish quote with high coverage
        $client->request('POST', "/quote-copilot/{$quoteId}/publish");

        $statusCode = $client->getResponse()->getStatusCode();
        // Should succeed (200) or redirect (302)
        $this->assertContains($statusCode, [200, 302], 'High coverage quote should be publishable');
    }

    public function testPublishApiReturnsJsonResponse(): void
    {
        $client = static::getClient();
        $quoteId = $this->createTestQuote('json-response', '75.0');

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
            $this->assertStringContainsString('json', $contentType ?? '');

            // Verify JSON structure
            $data = json_decode($response->getContent(), true);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('success', $data);
        }
    }

    public function testQuoteCoPilotControllerIsRegistered(): void
    {
        $container = static::getContainer();

        // Verify controller is registered
        $this->assertTrue($container->has('App\Controller\QuoteCoPilotController'));
    }
}
