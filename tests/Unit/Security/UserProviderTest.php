<?php

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\UserProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * Email verification gate: unverified users (except admins) must not be
 * able to authenticate, per the account-verification remediation.
 */
class UserProviderTest extends TestCase
{
    private function providerReturning(?User $user): UserProvider
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findOneByEmailCaseInsensitive')->willReturn($user);
        return new UserProvider($repo);
    }

    private function user(string $email, bool $verified, bool $active = true, array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setActive($active);
        $user->setIsVerified($verified);
        return $user;
    }

    public function testUnverifiedUserIsRejected(): void
    {
        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->providerReturning($this->user('pending@example.com', false))
            ->loadUserByIdentifier('pending@example.com');
    }

    public function testVerifiedUserIsAccepted(): void
    {
        $user = $this->providerReturning($this->user('ok@example.com', true))
            ->loadUserByIdentifier('ok@example.com');
        $this->assertSame('ok@example.com', $user->getUserIdentifier());
    }

    public function testUnverifiedAdminIsAccepted(): void
    {
        $user = $this->providerReturning($this->user('admin@example.com', false, true, ['ROLE_ADMIN']))
            ->loadUserByIdentifier('admin@example.com');
        $this->assertSame('admin@example.com', $user->getUserIdentifier());
    }

    public function testUnverifiedSuperAdminIsAccepted(): void
    {
        $user = $this->providerReturning($this->user('root@example.com', false, true, ['ROLE_SUPER_ADMIN']))
            ->loadUserByIdentifier('root@example.com');
        $this->assertSame('root@example.com', $user->getUserIdentifier());
    }

    public function testUnknownUserThrows(): void
    {
        $this->expectException(UserNotFoundException::class);
        $this->providerReturning(null)->loadUserByIdentifier('nobody@example.com');
    }

    public function testInactiveUserThrows(): void
    {
        $this->expectException(UserNotFoundException::class);
        $this->providerReturning($this->user('disabled@example.com', true, false))
            ->loadUserByIdentifier('disabled@example.com');
    }
}
