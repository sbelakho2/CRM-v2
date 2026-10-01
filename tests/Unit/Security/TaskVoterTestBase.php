<?php

namespace App\Tests\Unit\Security;

use App\Entity\Task;
use App\Entity\User;
use App\Security\Voter\TaskVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Shared fixtures for the TaskVoter matrix. Users are NOT persisted: the
 * voter compares association identities, so ids are forced via reflection
 * (this also proves the voter cannot be fooled by two unpersisted users
 * both having NULL ids — each fixture gets a distinct forced id).
 */
abstract class TaskVoterTestBase extends TestCase
{
    protected Security $security;

    protected function user(int $id, array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail("u{$id}@example.com");
        $user->setFirstName('U');
        $user->setLastName((string) $id);
        $user->setPassword('x');
        $user->setRoles($roles);

        $property = new \ReflectionProperty(User::class, 'id');
        $property->setValue($user, $id);

        return $user;
    }

    protected function task(User $creator, User $assignee): Task
    {
        $task = new Task();
        $task->setTitle('voter matrix');
        $task->setCreatedBy($creator);
        $task->setAssignedTo($assignee);

        return $task;
    }

    protected function securityMock(bool $adminForNormalToken = false, bool $adminForAdminToken = true): Security
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (string $attribute) => $attribute === 'ROLE_ADMIN' ? $adminForAdminToken : false
        );

        return $security;
    }

    protected function tokenFor(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    protected function vote(User $principal, ?object $subject, string $attribute): int
    {
        // Admin principal detection is via the token's roles; the Security
        // mock grants ROLE_ADMIN to any principal carrying the role.
        $isAdmin = in_array('ROLE_ADMIN', $principal->getRoles(), true);
        $voter = new TaskVoter($this->securityMock(false, $isAdmin));

        return $voter->vote($this->tokenFor($principal), $subject, [$attribute]);
    }
}
