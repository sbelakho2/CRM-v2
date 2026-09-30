<?php

namespace App\Tests\Functional\Regression;

use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Round-8 P0-3 security regression: task authorization was broken repo-wide.
 * Any ROLE_USER could (a) read any task by numeric ID (show had NO check),
 * (b) bypass the user scope with /tasks?all=1, (c) read every board via
 * /tasks/kanban?all=1 (which had no admin condition at all). Plus: archived
 * tasks were not excluded from any live repository path, statistics
 * overwrote instead of accumulated, and status transitions could leave
 * status=todo with completedAt set.
 */
class TaskAuthorizationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['tasks', 'users'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    // ──────────────────────────────────────────────────
    // Fixtures
    // ──────────────────────────────────────────────────

    private function makeUser(string $email, array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setFirstName(ucfirst(substr($email, 0, 4)));
        $user->setLastName('Tester');
        $user->setPassword('x');
        $user->setRoles($roles);
        $user->setActive(true);
        $this->em->persist($user);

        return $user;
    }

    private function makeTask(User $creator, User $assignee, string $title, array $overrides = []): Task
    {
        $task = new Task();
        $task->setTitle($title);
        $task->setCreatedBy($creator);
        $task->setAssignedTo($assignee);
        $task->setDueDate(new \DateTime('+3 days'));
        foreach ($overrides as $setter => $value) {
            $task->{$setter}($value);
        }
        $this->em->persist($task);

        return $task;
    }

    private function csrfToken(string $tokenId): string
    {
        // The CSRF token manager stores tokens in the session; push a fresh
        // request with a session onto the stack to make it usable outside a
        // normal request cycle.
        $container = $this->client->getContainer();
        $session = $container->get('session.factory')->createSession();
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->setSession($session);
        $container->get('request_stack')->push($request);
        try {
            return $container->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        } finally {
            $container->get('request_stack')->pop();
        }
    }

    private function flush(): void
    {
        $this->em->flush();
        $this->em->clear();
    }

    // ──────────────────────────────────────────────────
    // IDOR: cross-user access matrix (A creates, B attacks, admin passes)
    // ──────────────────────────────────────────────────

    private function crossUserFixtures(): array
    {
        $alice = $this->makeUser('alice-t1@example.com');
        $bob = $this->makeUser('bob-t1@example.com');
        $this->em->flush();

        $aliceTask = $this->makeTask($alice, $alice, 'ALICES-SECRET-TASK');
        $bobTask = $this->makeTask($bob, $bob, 'BOBS-OWN-TASK');
        $this->flush();

        return [$alice, $bob, $aliceTask, $bobTask];
    }

    public function testUserCannotReadAnotherUsersTask(): void
    {
        [, $bob, $aliceTask] = $this->crossUserFixtures();

        $this->client->loginUser($this->em->find(User::class, $bob->getId()));
        $this->client->request('GET', '/tasks/' . $aliceTask->getId());

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testUserCannotToggleOrCompleteAnotherUsersTask(): void
    {
        [, $bob, $aliceTask] = $this->crossUserFixtures();
        $taskId = $aliceTask->getId();

        $this->client->loginUser($this->em->find(User::class, $bob->getId()));

        $this->client->request('POST', "/tasks/{$taskId}/toggle", ['_token' => $this->csrfToken('toggle' . $taskId)]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', "/tasks/{$taskId}/complete", ['_token' => $this->csrfToken('complete' . $taskId)]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', "/tasks/{$taskId}/delete", ['_token' => $this->csrfToken('delete' . $taskId)]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request(
            'POST',
            '/tasks/api/update-status',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'taskId' => $taskId,
                'status' => Task::STATUS_DONE,
                '_token' => $this->csrfToken('update_status'),
            ])
        );
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // Nothing changed.
        $this->em->clear();
        $task = $this->em->find(Task::class, $taskId);
        $this->assertSame(Task::STATUS_TODO, $task->getStatus());
        $this->assertNull($task->getCompletedAt());
        $this->assertNull($task->getArchivedAt());
    }

    public function testUserCannotEditAnotherUsersTaskPage(): void
    {
        [, $bob, $aliceTask] = $this->crossUserFixtures();

        $this->client->loginUser($this->em->find(User::class, $bob->getId()));
        $this->client->request('GET', '/tasks/' . $aliceTask->getId() . '/edit');

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAllParamDoesNotLeakOtherUsersTasksToNonAdmin(): void
    {
        [$alice, $bob, $aliceTask, $bobTask] = $this->crossUserFixtures();

        $this->client->loginUser($this->em->find(User::class, $bob->getId()));

        $this->client->request('GET', '/tasks?all=1');
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('BOBS-OWN-TASK', $content);
        $this->assertStringNotContainsString('ALICES-SECRET-TASK', $content, '?all=1 must not defeat the user scope for non-admins');

        $this->client->request('GET', '/tasks/kanban?all=1');
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('BOBS-OWN-TASK', $content);
        $this->assertStringNotContainsString('ALICES-SECRET-TASK', $content, 'kanban?all=1 must stay user-scoped for non-admins');

        // Sanity: default (no all) shows own task too.
        $this->client->request('GET', '/tasks');
        $this->assertStringContainsString('BOBS-OWN-TASK', $this->client->getResponse()->getContent());
    }

    public function testAdminSeesAllTasks(): void
    {
        [$alice, , $aliceTask, $bobTask] = $this->crossUserFixtures();
        $admin = $this->makeUser('admin-t1@example.com', ['ROLE_ADMIN']);
        $this->em->flush();

        $this->client->loginUser($this->em->find(User::class, $admin->getId()));

        $this->client->request('GET', '/tasks?all=1');
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('ALICES-SECRET-TASK', $content);
        $this->assertStringContainsString('BOBS-OWN-TASK', $content);

        // Admin can read a single task owned by someone else.
        $this->client->request('GET', '/tasks/' . $aliceTask->getId());
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    // ──────────────────────────────────────────────────
    // Archive invisibility across every live path
    // ──────────────────────────────────────────────────

    public function testArchivedTaskIsAbsentFromEveryLiveQuery(): void
    {
        $owner = $this->makeUser('owner-t2@example.com');
        $this->em->flush();

        $company = new \App\Entity\Company();
        $company->setName('Archive Guard Co');
        $company->setAccountTier('C');
        $this->em->persist($company);

        $recurring = $this->makeTask($owner, $owner, 'RECURRING-ARCHIVED', [
            'setIsRecurring' => true,
            'setRecurringFrequency' => 'weekly',
            'setCompany' => $company,
        ]);
        $recurring->setStatus(Task::STATUS_DONE);
        $recurring->setCompletedAt(new \DateTime());
        $this->em->flush();

        $recurring->archive();
        $this->em->flush();
        $this->em->clear();

        /** @var TaskRepository $repo */
        $repo = $this->em->getRepository(Task::class);
        $owner = $this->em->find(User::class, $owner->getId());
        $company = $this->em->find(\App\Entity\Company::class, $company->getId());

        $this->assertCount(0, $repo->findByUser($owner));
        $this->assertCount(0, $repo->findByCompany($company));
        $this->assertArrayNotHasKey('RECURRING-ARCHIVED', array_flip(array_map(fn ($t) => $t->getTitle(), $repo->findGroupedByStatus(null)[Task::STATUS_DONE] ?? [])));
        $this->assertCount(0, $repo->findOverdue(null));
        $this->assertCount(0, $repo->findDueToday(null));
        $this->assertCount(0, $repo->findDueThisWeek(null));
        $this->assertCount(0, $repo->findPendingReminders());
        $this->assertCount(0, $repo->findRecurringTasksToDuplicate(), 'archived recurring task must not feed recurrence automation');
        $this->assertCount(0, $repo->search('RECURRING-ARCHIVED', null));

        $stats = $repo->getStatistics(null);
        $this->assertSame(0, $stats['total']);

        // Explicit archive browser is the ONLY surface that sees it.
        $archived = $repo->findArchived();
        $this->assertCount(1, $archived);
        $this->assertSame('RECURRING-ARCHIVED', $archived[0]->getTitle());
    }

    // ──────────────────────────────────────────────────
    // Statistics aggregation (+= not last-group-wins)
    // ──────────────────────────────────────────────────

    public function testStatisticsAccumulateAcrossStatusGroups(): void
    {
        $owner = $this->makeUser('stats-t3@example.com');
        $this->em->flush();

        $yesterday = new \DateTime('-1 day');
        // Overdue spread across TWO status groups — the old code kept only
        // whichever group Doctrine returned last.
        $this->makeTask($owner, $owner, 'OVERDUE-TODO', ['setDueDate' => $yesterday, 'setStatus' => Task::STATUS_TODO]);
        $this->makeTask($owner, $owner, 'OVERDUE-INPROGRESS', ['setDueDate' => $yesterday, 'setStatus' => Task::STATUS_IN_PROGRESS]);
        $this->makeTask($owner, $owner, 'DUE-TODAY', ['setDueDate' => new \DateTime('today'), 'setStatus' => Task::STATUS_TODO]);
        $this->flush();

        /** @var TaskRepository $repo */
        $repo = $this->em->getRepository(Task::class);
        $stats = $repo->getStatistics($this->em->find(User::class, $owner->getId()));

        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['overdue'], 'overdue must sum across status groups');
        $this->assertSame(1, $stats['due_today']);
        $this->assertSame(2, $stats['by_status'][Task::STATUS_TODO]);
        $this->assertSame(1, $stats['by_status'][Task::STATUS_IN_PROGRESS]);
    }

    // ──────────────────────────────────────────────────
    // Lifecycle transitions
    // ──────────────────────────────────────────────────

    public function testTransitionToMaintainsCompletedAtInvariant(): void
    {
        $task = new Task();
        $task->setTitle('lifecycle');

        $task->transitionTo(Task::STATUS_DONE);
        $this->assertNotNull($task->getCompletedAt());

        // DONE → TODO must CLEAR completedAt (previously corrupted state).
        $task->transitionTo(Task::STATUS_TODO);
        $this->assertNull($task->getCompletedAt());

        $task->transitionTo(Task::STATUS_IN_PROGRESS);
        $this->assertNull($task->getCompletedAt());
    }

    public function testArchivedTaskRejectsWorkflowMutation(): void
    {
        $task = new Task();
        $task->setTitle('archived-guard');
        $task->transitionTo(Task::STATUS_DONE);
        $task->archive();

        $this->expectException(\LogicException::class);
        $task->transitionTo(Task::STATUS_TODO);
    }

    public function testUnknownStatusIsRejected(): void
    {
        $task = new Task();
        $task->setTitle('bad-status');

        $this->expectException(\InvalidArgumentException::class);
        $task->transitionTo('super_done');
    }
}
