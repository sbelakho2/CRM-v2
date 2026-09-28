<?php

namespace App\Tests\Functional\Regression;

use App\Entity\Activity;
use App\Entity\Company;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression test for user lifecycle.
 *
 * History: the delete action first relied on DB-level ON DELETE CASCADE
 * (silently destroying everything the employee had created), then on
 * reassign-then-delete (falsifying attribution by rewriting history to
 * another user). The supported lifecycle is now DEACTIVATE: the user row and
 * every historical reference to it are preserved exactly as they were, and
 * login is blocked. Optional pseudonymization erases personal data while
 * keeping the row and its primary key.
 */
class UserDeleteReassignTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['users', 'companies', 'activities', 'leads', 'audit_logs'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testDeactivatingUserPreservesHistoryAndAttribution(): void
    {
        $admin = $this->createUser('admin-delete@example.com', ['ROLE_ADMIN'], 'Admin', 'Remover');
        $victim = $this->createUser('victim@example.com', ['ROLE_SALES'], 'Raihana', 'Lembardi');

        $company = new Company();
        $company->setName('Deactivate Co');
        $company->setAccountTier(Company::TIER_C);
        $company->setSector('Industrial');
        $company->setPipelineStage(Company::STAGE_PROSPECT);
        $this->entityManager->persist($company);
        $this->entityManager->flush();

        // Data owned by the deactivated user that must survive untouched.
        $activity = new Activity();
        $activity->setType('Call');
        $activity->setNotes('Work the victim entered');
        $activity->setActivityDate(new \DateTime('2026-09-01 10:00:00'));
        $activity->setCompany($company);
        $activity->setUser($victim);
        $this->entityManager->persist($activity);

        // A lead whose owner is recorded by display name — attribution is
        // historical fact and must NOT be rewritten to someone else.
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO leads (company_name, owner_rep, created_at) VALUES ('Lead of victim', 'Raihana Lembardi', NOW())"
        );
        $this->entityManager->flush();

        $victimId = $victim->getId();
        $adminId = $admin->getId();
        $this->entityManager->clear();

        $this->client->loginUser($this->loadUser('admin-delete@example.com'));
        $crawler = $this->client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();
        $token = $crawler
            ->filter('form[action="/admin/users/' . $victimId . '/delete"] input[name="_token"]')
            ->attr('value');

        $this->client->request('POST', '/admin/users/' . $victimId . '/delete', [
            '_token' => $token,
        ]);
        $this->assertResponseRedirects();

        $connection = $this->entityManager->getConnection();

        // The account still exists — deactivated, never deleted.
        $row = $connection->fetchAssociative('SELECT id, active, deactivated_at, deactivated_by_id FROM users WHERE id = ?', [$victimId]);
        $this->assertNotNull($row, 'The user row must be preserved');
        $this->assertSame(0, (int) $row['active'], 'Deactivated users must not be able to log in');
        $this->assertNotNull($row['deactivated_at']);
        $this->assertSame($adminId, (int) $row['deactivated_by_id']);

        // History untouched: attribution stays with the original user.
        $this->assertSame(
            $victimId,
            (int) $connection->fetchOne('SELECT user_id FROM activities WHERE notes = ?', ['Work the victim entered']),
            'Activity attribution must NOT be reassigned to the acting admin'
        );
        $this->assertSame(
            1,
            (int) $connection->fetchOne("SELECT COUNT(*) FROM leads WHERE owner_rep = 'Raihana Lembardi'"),
            'Owner-rep references are historical facts and must not be rewritten'
        );
    }

    public function testDeactivatingWithPseudonymizeKeepsRowAndAttribution(): void
    {
        $admin = $this->createUser('admin2@example.com', ['ROLE_ADMIN'], 'Admin', 'Two');
        $victim = $this->createUser('victim2@example.com', ['ROLE_SALES'], 'Other', 'Person');

        $company = new Company();
        $company->setName('Pseudonymize Co');
        $company->setAccountTier(Company::TIER_C);
        $company->setSector('Industrial');
        $company->setPipelineStage(Company::STAGE_PROSPECT);
        $this->entityManager->persist($company);
        $this->entityManager->flush();

        $activity = new Activity();
        $activity->setType('Email');
        $activity->setNotes('Belongs to the deactivated user');
        $activity->setActivityDate(new \DateTime('2026-09-02 10:00:00'));
        $activity->setCompany($company);
        $activity->setUser($victim);
        $this->entityManager->persist($activity);
        $this->entityManager->flush();

        $victimId = $victim->getId();
        $this->entityManager->clear();

        $this->client->loginUser($this->loadUser('admin2@example.com'));
        $crawler = $this->client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();
        $token = $crawler
            ->filter('form[action="/admin/users/' . $victimId . '/delete"] input[name="_token"]')
            ->attr('value');

        $this->client->request('POST', '/admin/users/' . $victimId . '/delete', [
            '_token' => $token,
            'pseudonymize' => '1',
        ]);
        $this->assertResponseRedirects();

        $connection = $this->entityManager->getConnection();

        // Row kept, personal data erased, attribution intact.
        $row = $connection->fetchAssociative('SELECT id, email, first_name FROM users WHERE id = ?', [$victimId]);
        $this->assertNotNull($row, 'Pseudonymization keeps the row (and its PK) forever');
        $this->assertSame('Former', $row['first_name']);
        $this->assertStringContainsString('@invalid.local', (string) $row['email']);
        $this->assertSame(
            $victimId,
            (int) $connection->fetchOne('SELECT user_id FROM activities WHERE notes = ?', ['Belongs to the deactivated user']),
            'Historical attribution survives pseudonymization'
        );
    }

    public function testAdminCannotDeactivateThemselves(): void
    {
        $admin = $this->createUser('admin3@example.com', ['ROLE_ADMIN'], 'Admin', 'Three');
        $adminId = $admin->getId();
        $this->entityManager->clear();

        $this->client->loginUser($this->loadUser('admin3@example.com'));
        $crawler = $this->client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();
        $token = $crawler
            ->filter('form[action="/admin/users/' . $adminId . '/delete"] input[name="_token"]')
            ->attr('value');

        $this->client->request('POST', '/admin/users/' . $adminId . '/delete', ['_token' => $token]);
        $this->assertResponseRedirects();

        $connection = $this->entityManager->getConnection();
        $this->assertSame(
            1,
            (int) $connection->fetchOne('SELECT active FROM users WHERE id = ?', [$adminId]),
            'Self-deactivation must be refused'
        );
    }

    private function createUser(string $email, array $roles, string $firstName, string $lastName): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setIsVerified(true);
        $user->setPassword(self::getContainer()->get('security.password_hasher')->hashPassword($user, 'test-password'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function loadUser(string $email): User
    {
        return self::getContainer()->get(\Symfony\Component\Security\Core\User\UserProviderInterface::class)
            ->loadUserByIdentifier($email);
    }
}
