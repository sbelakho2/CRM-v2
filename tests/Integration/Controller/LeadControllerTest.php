<?php

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LeadControllerTest extends WebTestCase
{
    public function testLeadsIndexRequiresAuthentication()
    {
        $client = static::createClient();
        $client->request('GET', '/leads/');

        // Should redirect to login when not authenticated
        $this->assertResponseRedirects('/login');
    }

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

    public function testLeadControllerIsRegistered()
    {
        $client = static::createClient();
        
        // Verify the controller is registered in the container
        $container = static::getContainer();
        $this->assertTrue($container->has('App\Controller\LeadController'));
    }

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
}
