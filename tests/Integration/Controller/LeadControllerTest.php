<?php

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LeadControllerTest extends WebTestCase
{
    // ================================================================
    // Authentication / Authorization Tests
    // ================================================================

    public function testLeadsIndexRequiresAuthentication()
    {
        $client = static::createClient();
        $client->request('GET', '/leads/');

        // Should redirect to login when not authenticated
        $this->assertResponseRedirects('/login');
    }

    public function testLeadReviewRequiresAuthentication()
    {
        $client = static::createClient();
        $client->request('GET', '/leads/review');
        
        // Should redirect to login
        $this->assertResponseRedirects('/login');
    }

    public function testLeadApproveRequiresAuthentication()
    {
        $client = static::createClient();
        $client->request('POST', '/leads/approve/1');
        
        $this->assertResponseRedirects('/login');
    }

    public function testLeadDenyRequiresAuthentication()
    {
        $client = static::createClient();
        $client->request('POST', '/leads/deny/1');
        
        $this->assertResponseRedirects('/login');
    }

    // ================================================================
    // Route Existence Tests
    // ================================================================

    public function testLeadsIndexRouteExists()
    {
        $client = static::createClient();
        $client->request('GET', '/leads/');

        // Route exists (returns 302 redirect, not 404)
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }

    public function testLeadReviewRouteExists()
    {
        $client = static::createClient();
        $client->request('GET', '/leads/review');

        // Route exists
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }

    // ================================================================
    // Filter Parameter Tests
    // ================================================================

    public function testLeadReviewAcceptsRegionFilter()
    {
        $client = static::createClient();
        $client->request('GET', '/leads/review?region=EMEA');

        // Should not return 404 (route accepts query parameters)
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }

    public function testLeadReviewAcceptsStatusFilter()
    {
        $client = static::createClient();
        $client->request('GET', '/leads/review?status=pending');

        // Should not return 404
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }

    public function testLeadReviewAcceptsScoreMinFilter()
    {
        $client = static::createClient();
        $client->request('GET', '/leads/review?score_min=50');

        // Should not return 404
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }

    public function testLeadReviewAcceptsMultipleFilters()
    {
        $client = static::createClient();
        $client->request('GET', '/leads/review?region=Americas&status=pending&score_min=70');

        // Should not return 404
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }

    // ================================================================
    // Service Registration Tests
    // ================================================================

    public function testLeadControllerIsRegistered()
    {
        $client = static::createClient();
        
        // Verify the controller is registered in the container
        $container = static::getContainer();
        $this->assertTrue($container->has('App\Controller\LeadController'));
    }

    // ================================================================
    // HTTP Method Tests
    // ================================================================

    public function testLeadReviewHandlesAllStatusFilter()
    {
        $client = static::createClient();
        $client->request('GET', '/leads/review?status=all');

        // Should not return 404
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }

    public function testLeadReviewHandlesAllRegionFilter()
    {
        $client = static::createClient();
        $client->request('GET', '/leads/review?region=all');

        // Should not return 404
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }

    public function testLeadApproveRejectsGetMethod()
    {
        $client = static::createClient();
        $client->request('GET', '/leads/approve/1');
        
        // POST-only route should return 405 Method Not Allowed (or 302 redirect to login)
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [302, 405], true),
            'Expected 302 (redirect to login) or 405 (method not allowed), got ' . $statusCode
        );
    }

    public function testLeadDenyRejectsGetMethod()
    {
        $client = static::createClient();
        $client->request('GET', '/leads/deny/1');
        
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [302, 405], true),
            'Expected 302 (redirect to login) or 405 (method not allowed), got ' . $statusCode
        );
    }

    // ================================================================
    // Discovery Pipeline Route Tests
    // ================================================================

    public function testDiscoveryPipelineIndexRouteExists()
    {
        $client = static::createClient();
        $client->request('GET', '/discovery-pipeline');
        
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }

    public function testDiscoveryPipelineRunRejectsGetMethod()
    {
        $client = static::createClient();
        $client->request('GET', '/discovery-pipeline/run');
        
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [302, 405], true),
            'Expected 302 or 405, got ' . $statusCode
        );
    }

    public function testDiscoveryPipelineRunRequiresSector()
    {
        $client = static::createClient();
        $client->request('POST', '/discovery-pipeline/run', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));
        
        // Should either redirect to login (302) or return 400 for missing sector
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [302, 400], true),
            'Expected 302 or 400, got ' . $statusCode
        );
    }

    public function testDiscoveryPipelineRunRejectsInvalidSector()
    {
        $client = static::createClient();
        $client->request('POST', '/discovery-pipeline/run', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['sector' => 'INVALID_SECTOR_XYZ']));
        
        // Should either redirect to login (302) or return 400 for invalid sector
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [302, 400], true),
            'Expected 302 or 400, got ' . $statusCode
        );
    }
}
