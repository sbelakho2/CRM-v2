<?php

namespace App\Tests\Functional\Controller;

use App\Entity\EmailCampaign;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;

class EmailCampaignControllerTest extends WebTestCase
{
    private $client;
    private $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        
        // Create schema
        \App\Tests\Bootstrap\TestDatabaseSchema::createSchema($this->entityManager->getConnection());
        
        // Create a test user and log in
        $this->loginUser();
    }

    private function loginUser(): void
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setPassword('$2y$13$9UmWR.BDzgbAJEzpkjq9suqeNiIIA6dGpmOe0Em/ClzFAZEIWMOCq');
        $user->setRoles(['ROLE_USER']);
        $user->setFirstName('Test');
        $user->setLastName('User');

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);
    }

    public function testIndex(): void
    {
        $this->client->request('GET', '/email-campaigns/');
        
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Email Campaigns');
    }

    public function testNewCampaign(): void
    {
        $crawler = $this->client->request('GET', '/email-campaigns/new');
        
        $this->assertResponseIsSuccessful();
        
        $form = $crawler->filter('form')->form([
            'email_campaign[name]' => 'Test Campaign',
            'email_campaign[language]' => 'EN',
        ]);
        
        $this->client->submit($form);
        
        $this->assertResponseRedirects('/email-campaigns/'.$this->entityManager->getRepository(EmailCampaign::class)->findOneBy(['name' => 'Test Campaign'])->getId());
        
        $campaign = $this->entityManager->getRepository(EmailCampaign::class)
            ->findOneBy(['name' => 'Test Campaign']);
            
        $this->assertNotNull($campaign);
        $this->assertEquals('EN', $campaign->getLanguage());
    }

    public function testShowCampaign(): void
    {
        // Create a test campaign
        $campaign = new EmailCampaign();
        $campaign->setName('Test Campaign');
        $campaign->setLanguage('EN');
        
        $this->entityManager->persist($campaign);
        $this->entityManager->flush();
        
        $this->client->request('GET', '/email-campaigns/'.$campaign->getId());
        
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Test Campaign');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up the database
        $this->entityManager->createQuery('DELETE FROM App\Entity\EmailCampaign')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        
        $this->entityManager->close();
        $this->entityManager = null;
    }
}