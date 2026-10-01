<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[AsCommand(
    name: 'app:test-email',
    description: 'Test email configuration and send a test email',
)]
class TestEmailCommand extends Command
{
    public function __construct(
        private MailerInterface $mailer,
        private string $mailerFromAddress,
        private string $mailerFromName,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Recipient email address')
            ->setHelp('This command tests the email configuration by sending a test email.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        /** @var mixed $to */
        $to = $input->getOption('to');
        if (!$to) {
            $io->error('Please provide a recipient email address with --to option');
            return Command::FAILURE;
        }

        $io->title('Email Configuration Test');
        
        // Display current configuration
        $io->section('Current Configuration');
        $io->table(
            ['Setting', 'Value'],
            [
                ['From Address', $this->mailerFromAddress],
                ['From Name', $this->mailerFromName],
                ['To Address', $to],
            ]
        );

        // Create test email
        $email = (new Email())
            ->from($this->mailerFromAddress)
            ->to($to)
            ->subject('Test Email - Email Configuration Verification')
            ->text('This is a test email to verify SMTP configuration is working correctly.')
            ->html('<p>This is a test email to verify <strong>SMTP configuration</strong> is working correctly.</p>');

        // Attempt to send
        $io->section('Sending Test Email');
        
        try {
            $this->mailer->send($email);
            $io->success('Email sent successfully!');
            $io->note('Check your inbox at: ' . $to);
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to send email');
            $io->section('Error Details');
            $io->writeln('<error>Error Type:</error> ' . get_class($e));
            $io->writeln('<error>Error Message:</error> ' . $e->getMessage());
            
            if (str_contains($e->getMessage(), 'Sender mismatch')) {
                $io->section('Troubleshooting: Sender Mismatch');
                $io->writeln([
                    'The "Sender mismatch" error means:',
                    '1. The From address must match the SMTP authenticated account',
                    '2. Infomaniak may require domain verification (SPF/DKIM)',
                    '3. The email address may not be authorized to send',
                    '',
                    '<comment>Solutions:</comment>',
                    '• Verify that contact@starzelectronics.site is the exact SMTP account',
                    '• Check Infomaniak panel for authorized sender addresses',
                    '• Set up SPF record: "v=spf1 include:spf.infomaniak.ch ~all"',
                    '• Consider switching to Mailgun (5k emails/month free)',
                ]);
            }
            
            return Command::FAILURE;
        }
    }
}
