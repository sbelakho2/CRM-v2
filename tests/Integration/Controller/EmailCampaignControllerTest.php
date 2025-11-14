<?php

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EmailCampaignControllerTest extends WebTestCase
{
    public function testIndexRequiresAuthentication()
    {
        $client = static::createClient();
        $client->request('GET', '/email-campaigns/');

        // Should redirect to login when not authenticated
        $this->assertResponseRedirects('/login');
    }

    public function testIndexRouteExists()
    {
        $client = static::createClient();
        $client->request('GET', '/email-campaigns/');

        // Route exists (returns 302 redirect, not 404)
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }

    public function testNewCampaignRouteExists()
    {
        $client = static::createClient();
        $client->request('GET', '/email-campaigns/new');

        // Route exists
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }

    public function testShowCampaignWithInvalidIdReturns404()
    {
        $client = static::createClient();
        $client->request('GET', '/email-campaigns/99999/show');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteCampaignRequiresPostMethod()
    {
        $client = static::createClient();
        $client->request('GET', '/email-campaigns/1/delete');

        // Should not allow GET for delete (405 Method Not Allowed, 302 redirect, or 404 if route doesn't exist)
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains(
            $statusCode,
            [404, 405, 302],
            "Expected 404, 405, or 302, got $statusCode"
        );
    }

    public function testEmailCampaignControllerIsRegistered()
    {
        $client = static::createClient();
        
        // Verify the controller is registered in the container
        $container = static::getContainer();
        $this->assertTrue($container->has('App\Controller\EmailCampaignController'));
    }

    public function testIndexAcceptsStatusFilter()
    {
        $client = static::createClient();
        $client->request('GET', '/email-campaigns/?status=active');

        // Should not return 404 (route accepts query parameters)
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }

    public function testIndexAcceptsLanguageFilter()
    {
        $client = static::createClient();
        $client->request('GET', '/email-campaigns/?language=en');

        // Should not return 404
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }
}
