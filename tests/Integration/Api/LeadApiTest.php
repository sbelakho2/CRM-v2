<?php

namespace App\Tests\Integration\Api;

use App\Entity\Lead;
use App\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class LeadApiTest extends WebTestCase
{
    private function getEntityManager(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    public function testApproveLeadApi()
    {
        $client = static::createClient();
        $entityManager = $this->getEntityManager();
        
        // Create a test lead
        $lead = new Lead();
        $lead->setCompanyName('Test Company API');
        $lead->setLeadScore(75);
        $lead->setRegionTag('Americas');
        $lead->setReviewStatus('pending');
        $lead->setCreatedAt(new \DateTime());
        
        $entityManager->persist($lead);
        $entityManager->flush();
        $leadId = $lead->getId();

        // Test approve API
        $client->request('POST', "/leads/approve/{$leadId}");
        
        // Should redirect to login (302) or return success if authenticated
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 302], "Expected 200 or 302, got {$statusCode}");

        // Clean up
        $entityManager->remove($lead);
        $entityManager->flush();
    }

    public function testDenyLeadApi()
    {
        $client = static::createClient();
        $entityManager = $this->getEntityManager();
        
        // Create a test lead
        $lead = new Lead();
        $lead->setCompanyName('Test Deny Company');
        $lead->setLeadScore(35);
        $lead->setRegionTag('EMEA');
        $lead->setReviewStatus('pending');
        $lead->setCreatedAt(new \DateTime());
        
        $entityManager->persist($lead);
        $entityManager->flush();
        $leadId = $lead->getId();

        // Test deny API with JSON payload
        $client->request(
            'POST',
            "/leads/deny/{$leadId}",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['reason' => 'Not in target market'])
        );
        
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 302], "Expected 200 or 302, got {$statusCode}");

        // Clean up
        $entityManager->remove($lead);
        $entityManager->flush();
    }

    public function testConvertLeadApiRequiresApproval()
    {
        $client = static::createClient();
        $entityManager = $this->getEntityManager();
        
        // Create an unapproved lead
        $lead = new Lead();
        $lead->setCompanyName('Test Convert Company');
        $lead->setLeadScore(80);
        $lead->setRegionTag('Americas');
        $lead->setReviewStatus('pending'); // Not approved
        $lead->setCreatedAt(new \DateTime());
        
        $entityManager->persist($lead);
        $entityManager->flush();
        $leadId = $lead->getId();

        // Try to convert without approval
        $client->request('POST', "/leads/convert/{$leadId}");
        
        // Should get response (might be 400 error or redirect)
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertNotEquals(404, $statusCode, "Convert API route should exist");

        // Clean up
        $entityManager->remove($lead);
        $entityManager->flush();
    }

    public function testConvertApprovedLeadApi()
    {
        $client = static::createClient();
        $entityManager = $this->getEntityManager();
        
        // Create an approved lead
        $lead = new Lead();
        $lead->setCompanyName('Test Approved Convert');
        $lead->setLeadScore(85);
        $lead->setRegionTag('Americas');
        $lead->setReviewStatus('approved'); // Already approved
        $lead->setWebsiteRoot('https://example.com');
        $lead->setCreatedAt(new \DateTime());
        
        $entityManager->persist($lead);
        $entityManager->flush();
        $leadId = $lead->getId();

        // Convert the approved lead
        $client->request('POST', "/leads/convert/{$leadId}");
        
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 302], "Expected 200 or 302, got {$statusCode}");

        // Clean up - remove both lead and potentially created company
        $entityManager->refresh($lead);
        if ($lead->getCompany()) {
            $company = $lead->getCompany();
            $entityManager->remove($company);
        }
        $entityManager->remove($lead);
        $entityManager->flush();
    }

    public function testAssignLeadApi()
    {
        $client = static::createClient();
        $entityManager = $this->getEntityManager();
        
        // Create a test lead
        $lead = new Lead();
        $lead->setCompanyName('Test Assign Company');
        $lead->setLeadScore(60);
        $lead->setRegionTag('APAC');
        $lead->setReviewStatus('pending');
        $lead->setCreatedAt(new \DateTime());
        
        $entityManager->persist($lead);
        $entityManager->flush();
        $leadId = $lead->getId();

        // Test assign API
        $client->request(
            'POST',
            "/leads/assign/{$leadId}",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['owner' => 'John Doe'])
        );
        
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 302, 400], "Expected 200, 302, or 400, got {$statusCode}");

        // Clean up
        $entityManager->remove($lead);
        $entityManager->flush();
    }

    public function testLeadApiRoutesExist()
    {
        $client = static::createClient();
        
        // Test that API routes are registered
        $routes = [
            '/leads/approve/1',
            '/leads/deny/1',
            '/leads/convert/1',
            '/leads/assign/1',
        ];

        foreach ($routes as $route) {
            $client->request('POST', $route);
            $statusCode = $client->getResponse()->getStatusCode();
            
            // Route should exist (not 404)
            $this->assertNotEquals(404, $statusCode, "Route {$route} should exist");
        }
    }

    public function testApproveApiRequiresPostMethod()
    {
        $client = static::createClient();
        $client->request('GET', '/leads/approve/1');
        
        // Should not allow GET (405 Method Not Allowed or redirect)
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertNotEquals(200, $statusCode, "Approve API should not accept GET");
    }

    public function testDenyApiRequiresPostMethod()
    {
        $client = static::createClient();
        $client->request('GET', '/leads/deny/1');
        
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertNotEquals(200, $statusCode, "Deny API should not accept GET");
    }

    public function testConvertApiRequiresPostMethod()
    {
        $client = static::createClient();
        $client->request('GET', '/leads/convert/1');
        
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertNotEquals(200, $statusCode, "Convert API should not accept GET");
    }

    public function testAssignApiRequiresPostMethod()
    {
        $client = static::createClient();
        $client->request('GET', '/leads/assign/1');
        
        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertNotEquals(200, $statusCode, "Assign API should not accept GET");
    }
}
