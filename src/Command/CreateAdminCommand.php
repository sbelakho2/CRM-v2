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
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Creates a new admin user'
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private TranslatorInterface $translator
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription($this->translator->trans('command.create_admin.description'))
            ->addOption('email', null, InputOption::VALUE_REQUIRED, $this->translator->trans('command.create_admin.option.email'))
            ->addOption('password', null, InputOption::VALUE_REQUIRED, $this->translator->trans('command.create_admin.option.password'))
            ->addOption('firstName', null, InputOption::VALUE_REQUIRED, $this->translator->trans('command.create_admin.option.first_name'))
            ->addOption('lastName', null, InputOption::VALUE_REQUIRED, $this->translator->trans('command.create_admin.option.last_name'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        // Get or prompt for required values
        $email = $input->getOption('email') ?? $io->ask($this->translator->trans('command.create_admin.prompt.email'));
        $password = $input->getOption('password') ?? $io->askHidden($this->translator->trans('command.create_admin.prompt.password'));
        $firstName = $input->getOption('firstName') ?? $io->ask($this->translator->trans('command.create_admin.prompt.first_name'));
        $lastName = $input->getOption('lastName') ?? $io->ask($this->translator->trans('command.create_admin.prompt.last_name'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error("Invalid email address: {$email}");
            return Command::FAILURE;
        }

        // Check if user exists
        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser) {
            $io->error($this->translator->trans('command.create_admin.error.exists'));
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

            $io->success($this->translator->trans('command.create_admin.success', ['%email%' => $email]));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($this->translator->trans('command.create_admin.error.failed', ['%message%' => $e->getMessage()]));
            return Command::FAILURE;
        }
    }
    //php bin/console app:create-admin
}