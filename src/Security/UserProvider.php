<?php

namespace App\Security;

use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class UserProvider implements UserProviderInterface
{
    public function __construct(
        private UserRepository $userRepository
    ) {}

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->userRepository->findOneByEmailCaseInsensitive($identifier);

        if (!$user) {
            throw new UserNotFoundException(sprintf('User with email "%s" not found.', $identifier));
        }

        if (!$user->isActive()) {
            throw new UserNotFoundException('User account is disabled.');
        }

        if (!$user->isVerified() && !in_array('ROLE_ADMIN', $user->getRoles(), true) && !in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            throw new CustomUserMessageAccountStatusException('Your email address has not been verified yet. Please check your inbox for the verification link.');
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === \App\Entity\User::class || is_subclass_of($class, \App\Entity\User::class);
    }
}
