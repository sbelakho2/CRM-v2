<?php

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DashboardControllerTest extends WebTestCase
{
    public function testDashboardRequiresAuthentication()
    {
        $client = static::createClient();
        $client->request('GET', '/');

        // Should redirect to login when not authenticated
        $this->assertResponseRedirects('/login');
    }

    public function testDashboardRouteExists()
    {
        $client = static::createClient();
        $client->request('GET', '/');

        // Route exists (returns 302 redirect, not 404)
        $this->assertNotEquals(404, $client->getResponse()->getStatusCode());
    }

    public function testDashboardControllerIsRegistered()
    {
        $client = static::createClient();
        
        // Verify the controller is registered in the container
        $container = static::getContainer();
        $this->assertTrue($container->has('App\Controller\DashboardController'));
    }
}
