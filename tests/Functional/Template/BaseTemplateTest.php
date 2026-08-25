<?php

namespace App\Tests\Functional\Template;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base template functional tests.
 *
 * Boots the full application against the bootstrap test schema, seeds a
 * minimal ROLE_USER, and asserts the base template shell (sidebar, status
 * bar, bezel frame) renders on the dashboard.
 */
class BaseTemplateTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \App\Tests\Bootstrap\TestDatabaseSchema::createSchema($this->entityManager->getConnection());
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $connection->executeStatement('TRUNCATE TABLE users');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $this->seed();
    }

    private function seed(): void
    {
        $passwordHasher = self::getContainer()->get('security.password_hasher');

        $user = new User();
        $user->setEmail('template@example.com');
        $user->setPassword($passwordHasher->hashPassword($user, 'test-password'));
        $user->setRoles(['ROLE_USER']);
        $user->setFirstName('Template');
        $user->setLastName('Tester');
        $user->setIsVerified(true);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);
    }

    public function testDashboardRendersBaseTemplateShell(): void
    {
        $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('<!DOCTYPE html>', $content);
    }

    public function testBaseTemplateRendersSidebar(): void
    {
        $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.rams-sidebar');
    }

    public function testBaseTemplateRendersStatusBarAndBezel(): void
    {
        $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.rams-status-bar');
        $this->assertSelectorExists('.rams-bezel');
    }
}
