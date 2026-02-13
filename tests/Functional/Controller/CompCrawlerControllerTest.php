<?php

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CompCrawlerControllerTest extends WebTestCase
{
    public function testDashboardIsAccessible(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        // Find or create an admin user
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'admin@starz.ma']);
        if (!$user) {
            $user = new User();
            $user->setEmail('admin@starz.ma');
            $user->setFirstName('Admin');
            $user->setLastName('User');
            $user->setRoles(['ROLE_ADMIN']);
            $user->setPassword('password');
            $em->persist($user);
            $em->flush();
        }

        $client->loginUser($user);

        $client->request('GET', '/comp-crawler');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Competitor');
    }

    public function testListIsAccessible(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'admin@starz.ma']);
        if (!$user) {
            $user = new User();
            $user->setEmail('admin@starz.ma');
            $user->setFirstName('Admin');
            $user->setLastName('User');
            $user->setRoles(['ROLE_ADMIN']);
            $user->setPassword('password');
            $em->persist($user);
            $em->flush();
        }
        $client->loginUser($user);

        $client->request('GET', '/comp-crawler/list');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Competitor');
    }

    public function testDetailIsAccessible(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'admin@starz.ma']);
        if (!$user) {
            $user = new User();
            $user->setEmail('admin@starz.ma');
            $user->setFirstName('Admin');
            $user->setLastName('User');
            $user->setRoles(['ROLE_ADMIN']);
            $user->setPassword('password');
            $em->persist($user);
            $em->flush();
        }
        $client->loginUser($user);

        // Find a competitor or create one
        $competitor = $em->getRepository(\App\Entity\Competitor::class)->findOneBy([]);
        if (!$competitor) {
            $competitor = new \App\Entity\Competitor();
            $competitor->setName('Test Competitor');
            $competitor->setCanonicalDomain('test.com');
            $competitor->setStatus(\App\Entity\Competitor::STATUS_CANDIDATE);
            $competitor->setCreatedAt(new \DateTime());
            $competitor->setUpdatedAt(new \DateTime());
            $competitor->setDiscoveredAt(new \DateTime());
            $em->persist($competitor);
            $em->flush();
        }

        $client->request('GET', '/comp-crawler/' . $competitor->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', $competitor->getName());
    }
}
