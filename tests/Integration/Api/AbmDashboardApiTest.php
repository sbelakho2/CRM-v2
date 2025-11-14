<?php

namespace App\Tests\Integration\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AbmDashboardApiTest extends WebTestCase
{
    public function testTogglePlaybookApiRouteExists()
    {
        $client = static::createClient();
        $client->request('POST', '/abm-dashboard/playbook/1/toggle');
        
        // Route should exist (not 404)
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }

    public function testTogglePlaybookApiRequiresPostMethod()
    {
        $client = static::createClient();
        $client->request('GET', '/abm-dashboard/playbook/1/toggle');
        
        // Should not accept GET method
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [302, 405], "Should reject GET method");
    }

    public function testTogglePlaybookApiReturnsNotImplemented()
    {
        $client = static::createClient();
        $client->request('POST', '/abm-dashboard/playbook/1/toggle');
        
        $statusCode = $client->getResponse()->getStatusCode();
        
        // Should return 501 (Not Implemented) or redirect to login (302)
        $this->assertContains(
            $statusCode,
            [501, 302],
            "Expected 501 Not Implemented or 302 redirect, got {$statusCode}"
        );
    }

    public function testTogglePlaybookApiReturnsJsonWhenImplemented()
    {
        $client = static::createClient();
        $client->request('POST', '/abm-dashboard/playbook/1/toggle');
        
        $response = $client->getResponse();
        
        // If it returns JSON (not redirect), verify structure
        if ($response->getStatusCode() !== 302) {
            $contentType = $response->headers->get('Content-Type');
            $this->assertStringContainsString('json', $contentType);
            
            $data = json_decode($response->getContent(), true);
            $this->assertIsArray($data);
            
            // Should have error key (since feature not implemented)
            $this->assertArrayHasKey('error', $data);
        }
    }

    public function testAbmDashboardControllerIsRegistered()
    {
        $client = static::createClient();
        $container = static::getContainer();
        
        // Verify controller is registered
        $this->assertTrue($container->has('App\Controller\AbmDashboardController'));
    }

    public function testAbmDashboardIndexRouteExists()
    {
        $client = static::createClient();
        $client->request('GET', '/abm-dashboard');
        
        // Main dashboard route should exist
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }
}
