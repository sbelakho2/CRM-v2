<?php

namespace App\Tests\Functional\Regression;

use App\Entity\Activity;
use App\Entity\Company;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression test for user deletion.
 *
 * The delete action used to rely on DB-level ON DELETE CASCADE rules, so
 * deleting an account silently destroyed everything the employee had created
 * (activities, tasks, calendar events, meeting slots, notifications, report
 * definitions) and orphaned the rest (audit trail, assigned tasks, owner-rep
 * names). Deletion must instead reassign every reference to another user.
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

    public function testDeletingUserReassignsTheirDataInsteadOfDestroyingIt(): void
    {
        $admin = $this->createUser('admin-delete@example.com', ['ROLE_ADMIN'], 'Admin', 'Remover');
        $victim = $this->createUser('victim@example.com', ['ROLE_SALES'], 'Raihana', 'Lembardi');
        $receiver = $this->createUser('receiver@example.com', ['ROLE_SALES'], 'Khawla', 'Touati');

        $company = new Company();
        $company->setName('Reassign Co');
        $company->setAccountTier(Company::TIER_C);
        $company->setSector('Industrial');
        $company->setPipelineStage(Company::STAGE_PROSPECT);
        $this->entityManager->persist($company);
        $this->entityManager->flush();

        // Data owned by the victim that the old delete would have destroyed.
        $activity = new Activity();
        $activity->setType('Call');
        $activity->setNotes('Work the victim entered');
        $activity->setActivityDate(new \DateTime('2026-09-01 10:00:00'));
        $activity->setCompany($company);
        $activity->setUser($victim);
        $this->entityManager->persist($activity);

        // A lead whose owner is recorded by display name.
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO leads (company_name, owner_rep, created_at) VALUES ('Lead of victim', 'Raihana Lembardi', NOW())"
        );
        $this->entityManager->flush();

        // In production the audit rows carry the acting user; setUp-time rows
        // have no request user, so attribute them to the victim here.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE audit_logs SET user_id = :u WHERE user_id IS NULL OR user_id = :admin',
            ['u' => $victim->getId(), 'admin' => $admin->getId()]
        );

        $victimId = $victim->getId();
        $receiverId = $receiver->getId();
        $activityId = $activity->getId();
        $this->entityManager->clear();

        $this->client->loginUser($this->loadUser('admin-delete@example.com'));
        $crawler = $this->client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();
        $token = $crawler->filter('form[action="/admin/users/' . $victimId . '/delete"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/users/' . $victimId . '/delete', [
            '_token' => $token,
            'reassign_to' => (string) $receiverId,
        ]);
        $this->assertResponseRedirects();

        $connection = $this->entityManager->getConnection();

        // The account is gone …
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM users WHERE id = ?', [$victimId]));

        // … but nothing the user created was destroyed.
        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM activities WHERE id = ?', [$activityId]));
        $this->assertSame(
            $receiverId,
            (int) $connection->fetchOne('SELECT user_id FROM activities WHERE id = ?', [$activityId]),
            'Activities must be reassigned, not deleted'
        );
        $this->assertSame(
            1,
            (int) $connection->fetchOne("SELECT COUNT(*) FROM leads WHERE owner_rep = 'Khawla Touati'"),
            'Owner-rep name references must be reassigned'
        );
        // (Rows created directly in setUp have no acting user, so scope the
        // limbo check to the deleted user's own records.)
        $this->assertSame(
            0,
            (int) $connection->fetchOne(
                "SELECT COUNT(*) FROM audit_logs WHERE user_id IS NULL AND entity_type = 'Activity' AND entity_id = ?",
                [$activityId]
            ),
            'Audit trail must follow the reassignment, never fall into limbo'
        );
        $this->assertGreaterThanOrEqual(
            1,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_logs WHERE user_id = ?', [$receiverId]),
            'The deleted user\'s audit trail must be reassigned to the target'
        );
    }

    public function testDeletingUserWithoutTargetReassignsToActingAdmin(): void
    {
        $admin = $this->createUser('admin2@example.com', ['ROLE_ADMIN'], 'Admin', 'Two');
        $victim = $this->createUser('victim2@example.com', ['ROLE_SALES'], 'Other', 'Person');

        $company = new Company();
        $company->setName('Default Target Co');
        $company->setAccountTier(Company::TIER_C);
        $company->setSector('Industrial');
        $company->setPipelineStage(Company::STAGE_PROSPECT);
        $this->entityManager->persist($company);
        $this->entityManager->flush();

        $activity = new Activity();
        $activity->setType('Email');
        $activity->setNotes('Belongs to the deleted user');
        $activity->setActivityDate(new \DateTime('2026-09-02 10:00:00'));
        $activity->setCompany($company);
        $activity->setUser($victim);
        $this->entityManager->persist($activity);
        $this->entityManager->flush();

        $victimId = $victim->getId();
        $adminId = $admin->getId();
        $this->entityManager->clear();

        $this->client->loginUser($this->loadUser('admin2@example.com'));
        $crawler = $this->client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();
        $token = $crawler->filter('form[action="/admin/users/' . $victimId . '/delete"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/users/' . $victimId . '/delete', ['_token' => $token]);
        $this->assertResponseRedirects();

        $connection = $this->entityManager->getConnection();
        $this->assertSame(
            $adminId,
            (int) $connection->fetchOne('SELECT user_id FROM activities WHERE notes = ?', ['Belongs to the deleted user']),
            'Without an explicit target the acting admin receives the data'
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
