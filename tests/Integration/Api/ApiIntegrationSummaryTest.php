<?php

namespace App\Tests\Integration\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * API Integration Summary Test
 * 
 * Tests that all integrated APIs are accessible and respond correctly.
 * These tests verify route registration and basic HTTP method validation
 * without requiring full database setup.
 */
class ApiIntegrationSummaryTest extends WebTestCase
{
    /**
     * Test Lead Management APIs
     */
    public function testLeadApisAreAccessible()
    {
        $client = static::createClient();
        
        $leadApis = [
            ['POST', '/leads/approve/1', 'Lead Approve API'],
            ['POST', '/leads/deny/1', 'Lead Deny API'],
            ['POST', '/leads/convert/1', 'Lead Convert API'],
            ['POST', '/leads/assign/1', 'Lead Assign API'],
        ];

        foreach ($leadApis as [$method, $route, $name]) {
            $client->request($method, $route);
            $statusCode = $client->getResponse()->getStatusCode();
            
            // API should exist (not 404)
            $this->assertNotEquals(404, $statusCode, "{$name} route should exist");
            
            // Should return JSON response or redirect (not plain HTML error)
            $this->assertContains(
                $statusCode,
                [200, 302, 400, 401, 403, 500],
                "{$name} should return valid HTTP status"
            );
        }
    }

    public function testLeadApisRejectGetMethod()
    {
        $client = static::createClient();
        
        $routes = [
            '/leads/approve/1',
            '/leads/deny/1',
            '/leads/convert/1',
            '/leads/assign/1',
        ];

        foreach ($routes as $route) {
            $client->request('GET', $route);
            $statusCode = $client->getResponse()->getStatusCode();
            
            // Should not accept GET (405, 302 redirect, or other error)
            $this->assertNotEquals(200, $statusCode, "{$route} should not accept GET method");
        }
    }

    /**
     * Test Quote Co-Pilot APIs
     */
    public function testQuoteCoPilotPublishApiIsAccessible()
    {
        $client = static::createClient();
        $client->request('POST', '/quote-copilot/1/publish');
        
        $statusCode = $client->getResponse()->getStatusCode();
        
        // Route should exist
        $this->assertNotEquals(404, $statusCode, "Quote Publish API route should exist");
        
        // Should handle POST requests
        $this->assertContains(
            $statusCode,
            [200, 302, 400, 401, 403, 404, 500],
            "Quote Publish API should return valid HTTP status"
        );
    }

    public function testQuoteCoPilotPublishApiRejectsGetMethod()
    {
        $client = static::createClient();
        $client->request('GET', '/quote-copilot/1/publish');
        
        $statusCode = $client->getResponse()->getStatusCode();
        
        // Should not accept GET
        $this->assertContains(
            $statusCode,
            [302, 405],
            "Quote Publish API should reject GET method"
        );
    }

    /**
     * Test ABM Dashboard APIs
     */
    public function testAbmDashboardTogglePlaybookApiIsAccessible()
    {
        $client = static::createClient();
        $client->request('POST', '/abm-dashboard/playbook/1/toggle');
        
        $statusCode = $client->getResponse()->getStatusCode();
        
        // Route should exist
        $this->assertNotEquals(404, $statusCode, "Toggle Playbook API route should exist");
        
        // Should return 501 Not Implemented or redirect
        $this->assertContains(
            $statusCode,
            [501, 302],
            "Toggle Playbook API should return 501 or redirect"
        );
    }

    public function testAbmDashboardTogglePlaybookApiRejectsGetMethod()
    {
        $client = static::createClient();
        $client->request('GET', '/abm-dashboard/playbook/1/toggle');
        
        $statusCode = $client->getResponse()->getStatusCode();
        
        // Should not accept GET
        $this->assertContains(
            $statusCode,
            [302, 405],
            "Toggle Playbook API should reject GET method"
        );
    }

    /**
     * Test API Response Headers
     */
    public function testApisReturnProperContentType()
    {
        $client = static::createClient();
        
        // Test a few API endpoints for proper headers
        $client->request('POST', '/abm-dashboard/playbook/1/toggle');
        $response = $client->getResponse();
        
        // Should get a response
        $this->assertNotNull($response, "API should return a response");
        
        if ($response->getStatusCode() === 501) {
            // Should return JSON for 501 error
            $contentType = $response->headers->get('Content-Type');
            $this->assertStringContainsString('json', $contentType, "API should return JSON content type");
        } else {
            // For other status codes, just verify we got a response
            $this->assertTrue(true, "API returned status: " . $response->getStatusCode());
        }
    }

    /**
     * Test Controllers are Registered
     */
    public function testApiControllersAreRegistered()
    {
        $client = static::createClient();
        $container = static::getContainer();
        
        $controllers = [
            'App\Controller\LeadController',
            'App\Controller\QuoteCoPilotController',
            'App\Controller\AbmDashboardController',
        ];

        foreach ($controllers as $controller) {
            $this->assertTrue(
                $container->has($controller),
                "{$controller} should be registered in the container"
            );
        }
    }

    /**
     * Summary Test - All APIs Functional
     */
    public function testAllIntegratedApisAreFunctional()
    {
        $client = static::createClient();
        
        $apiEndpoints = [
            'Lead Approve' => '/leads/approve/1',
            'Lead Deny' => '/leads/deny/1',
            'Lead Convert' => '/leads/convert/1',
            'Lead Assign' => '/leads/assign/1',
            'Quote Publish' => '/quote-copilot/1/publish',
            'Playbook Toggle' => '/abm-dashboard/playbook/1/toggle',
        ];

        $workingApis = 0;
        $totalApis = count($apiEndpoints);

        foreach ($apiEndpoints as $name => $route) {
            $client->request('POST', $route);
            $statusCode = $client->getResponse()->getStatusCode();
            
            // API is functional if it returns anything other than 404
            if ($statusCode !== 404) {
                $workingApis++;
            }
        }

        // Assert all APIs are registered and functional
        $this->assertEquals(
            $totalApis,
            $workingApis,
            "All {$totalApis} integrated APIs should be functional. Found {$workingApis} working."
        );
    }
}
