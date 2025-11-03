<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Creates a new admin user'
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Admin email')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Admin password')
            ->addOption('firstName', null, InputOption::VALUE_REQUIRED, 'Admin first name')
            ->addOption('lastName', null, InputOption::VALUE_REQUIRED, 'Admin last name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        // Get or prompt for required values
        $email = $input->getOption('email') ?? $io->ask('Email');
        $password = $input->getOption('password') ?? $io->askHidden('Password');
        $firstName = $input->getOption('firstName') ?? $io->ask('First Name');
        $lastName = $input->getOption('lastName') ?? $io->ask('Last Name');

        // Check if user exists
        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser) {
            $io->error('User with this email already exists.');
            return Command::FAILURE;
        }

        // Create new admin user
        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setActive(true);

        // Save to database
        try {
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $io->success(sprintf('Admin user "%s" created successfully.', $email));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to create admin user: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
    //php bin/console app:create-admin
}