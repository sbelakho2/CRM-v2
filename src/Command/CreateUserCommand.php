<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-user',
    description: 'Create a new CRM user',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private UserRepository $userRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, 'User email')
            ->addArgument('firstName', InputArgument::OPTIONAL, 'First name')
            ->addArgument('lastName', InputArgument::OPTIONAL, 'Last name')
            ->addArgument('password', InputArgument::OPTIONAL, 'Password')
            ->addOption('role', null, InputOption::VALUE_OPTIONAL, 'Role (Field Rep, Digital Rep, Sales Ops, Admin)', 'Field Rep')
            ->addOption('territory', null, InputOption::VALUE_OPTIONAL, 'Territory (Morocco, EU, Global)', 'Morocco')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Create user with Admin role');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email') ?? $io->ask('Email');
        $firstName = $input->getArgument('firstName') ?? $io->ask('First Name');
        $lastName = $input->getArgument('lastName') ?? $io->ask('Last Name');
        $password = $input->getArgument('password') ?? $io->askHidden('Password');
        
        $isAdmin = $input->getOption('admin');
        $role = $isAdmin ? 'Admin' : $input->getOption('role');
        $territory = $input->getOption('territory');

        // Check if user already exists
        if ($this->userRepository->findOneBy(['email' => $email])) {
            $io->error("User with email {$email} already exists.");
            return Command::FAILURE;
        }

        // Create user
        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setRole($role);
        $user->setTerritory($territory);
        $user->setActive(true);

        // Set roles based on role type
        $roles = match($role) {
            'Admin' => ['ROLE_ADMIN'],
            'Sales Ops' => ['ROLE_SALES_OPS', 'ROLE_USER'],
            'Digital Rep' => ['ROLE_DIGITAL_REP', 'ROLE_USER'],
            'Field Rep' => ['ROLE_FIELD_REP', 'ROLE_USER'],
            default => ['ROLE_USER'],
        };
        $user->setRoles($roles);

        // Hash password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        // Persist
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success("User {$email} created successfully!");
        $io->table(
            ['Field', 'Value'],
            [
                ['Email', $user->getEmail()],
                ['Name', $user->getFullName()],
                ['Role', $user->getRole()],
                ['Territory', $user->getTerritory()],
                ['Symfony Roles', implode(', ', $user->getRoles())],
            ]
        );

        return Command::SUCCESS;
    }
}
