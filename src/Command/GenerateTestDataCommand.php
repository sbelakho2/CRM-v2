<?php

namespace App\Command;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\Activity;
use App\Entity\Lead;
use App\Entity\RFQ;
use App\Entity\ComplianceDocument;
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
    name: 'app:generate-test-data',
    description: 'Generate test data for development and testing'
)]
class GenerateTestDataCommand extends Command
{
    private array $sectors = ['Aerospace', 'Automotive', 'Electronics', 'Medical Devices', 'Industrial Equipment'];
    private array $stages = ['Prospect', 'MQL', 'SQL', 'SQO', 'Proposal', 'Award'];
    private array $activityTypes = ['Call', 'Email', 'Meeting', 'Follow-up'];
    private array $outcomes = ['Successful', 'Follow-up Required', 'No Answer', 'Completed'];
    
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Test user email (random if not provided)')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Test user password (random if not provided)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Generating Test Data');

        $email = $input->getOption('email') ?? 'test_' . bin2hex(random_bytes(4)) . '@example.com';
        $password = $input->getOption('password') ?? bin2hex(random_bytes(6));

        // Create test user if not exists
        $user = $this->createTestUser($io, $email, $password);

        $this->em->beginTransaction();
        try {
            // Generate companies
            $io->section('Creating Companies...');
            $companies = $this->generateCompanies(15);
            $io->success(sprintf('Created %d companies', count($companies)));

            // Generate contacts
            $io->section('Creating Contacts...');
            $contacts = $this->generateContacts($companies, 30);
            $io->success(sprintf('Created %d contacts', count($contacts)));

            // Generate activities
            $io->section('Creating Activities...');
            $activities = $this->generateActivities($companies, $contacts, $user, 50);
            $io->success(sprintf('Created %d activities', count($activities)));

            // Generate leads
            $io->section('Creating Leads...');
            $leads = $this->generateLeads(20);
            $io->success(sprintf('Created %d leads', count($leads)));

            // Generate RFQs
            $io->section('Creating RFQs...');
            $rfqs = $this->generateRFQs($companies, 10);
            $io->success(sprintf('Created %d RFQs', count($rfqs)));

            // Generate compliance documents
            $io->section('Creating Compliance Documents...');
            $compliance = $this->generateCompliance($companies, 25);
            $io->success(sprintf('Created %d compliance documents', count($compliance)));

            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();
            $io->error('Test data generation failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success('All test data generated successfully!');
        
        return Command::SUCCESS;
    }

    private function createTestUser(SymfonyStyle $io, string $email, string $password): User
    {
        $existingUser = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        
        if ($existingUser) {
            $io->note('Test user already exists: ' . $email);
            return $existingUser;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setActive(true);

        $this->em->persist($user);
        $this->em->flush();

        $io->success('Created test user: ' . $email . ' / password: ' . $password);
        
        return $user;
    }

    private function generateCompanies(int $count): array
    {
        $companies = [];
        $companyNames = [
            'Airbus Defense', 'Boeing Suppliers', 'Tesla Manufacturing', 'Medtronic Systems',
            'Siemens Industrial', 'GE Aviation', 'Rolls-Royce Parts', 'Safran Components',
            'Honeywell Aerospace', 'Collins Aerospace', 'Parker Hannifin', 'Eaton Corporation',
            'BAE Systems', 'Lockheed Martin', 'Northrop Grumman'
        ];

        for ($i = 0; $i < $count; $i++) {
            $company = new Company();
            $company->setName($companyNames[$i] ?? "Company " . ($i + 1));
            $company->setSector($this->sectors[array_rand($this->sectors)]);
            $company->setPipelineStage($this->stages[array_rand($this->stages)]);
            $company->setWebsite("https://www.example-company-{$i}.com");
            $company->setAddress("123 Business St, Suite " . ($i + 1));
            $company->setCity(['Casablanca', 'Rabat', 'Tangier', 'Marrakech'][array_rand(['Casablanca', 'Rabat', 'Tangier', 'Marrakech'])]);
            $company->setCountry('Morocco');
            $company->setRegion('Africa');
            $company->setAccountTier(['Tier 1', 'Tier 2', 'Tier 3'][array_rand(['Tier 1', 'Tier 2', 'Tier 3'])]);
            $company->setNotes('Generated test company for development');
            $company->setCreatedAt(new \DateTime('-' . rand(1, 180) . ' days'));

            $this->em->persist($company);
            $companies[] = $company;
        }

        $this->em->flush();
        return $companies;
    }

    private function generateContacts(array $companies, int $count): array
    {
        $contacts = [];
        $firstNames = ['Ahmed', 'Fatima', 'Mohamed', 'Aicha', 'Youssef', 'Khadija', 'Omar', 'Salma'];
        $lastNames = ['Alami', 'Benali', 'Chakir', 'Darif', 'El Amrani', 'Fassi', 'Gharbi', 'Hilali'];

        for ($i = 0; $i < $count; $i++) {
            $contact = new Contact();
            $firstName = $firstNames[array_rand($firstNames)];
            $lastName = $lastNames[array_rand($lastNames)];
            
            $contact->setFirstName($firstName);
            $contact->setLastName($lastName);
            $contact->setEmail(strtolower($firstName . '.' . $lastName . $i . '@example.com'));
            $contact->setPhone('+212 6' . rand(60, 69) . ' ' . rand(100000, 999999));
            $contact->setJobTitle(['CEO', 'CTO', 'Procurement Manager', 'Director of Operations', 'Supply Chain Manager'][array_rand(['CEO', 'CTO', 'Procurement Manager', 'Director of Operations', 'Supply Chain Manager'])]);
            $contact->setCompany($companies[array_rand($companies)]);

            $this->em->persist($contact);
            $contacts[] = $contact;
        }

        $this->em->flush();
        return $contacts;
    }

    private function generateActivities(array $companies, array $contacts, User $user, int $count): array
    {
        $activities = [];

        // Refresh contacts to ensure they're managed. merge() is removed in
        // modern ORM — re-fetch by ID instead of merging detached instances.
        foreach ($contacts as $key => $contact) {
            $contacts[$key] = $this->em->find(\App\Entity\Contact::class, $contact->getId()) ?? $contact;
        }
        
        for ($i = 0; $i < $count; $i++) {
            $activity = new Activity();
            $type = $this->activityTypes[array_rand($this->activityTypes)];
            $activity->setType($type);
            $activity->setDescription('Discussion about ' . ['project requirements', 'pricing', 'delivery schedule', 'quality standards'][array_rand(['project requirements', 'pricing', 'delivery schedule', 'quality standards'])] . '. Detailed notes about the ' . strtolower($type) . ' with the client.');
            $activity->setNotes('Follow-up: ' . ['Call back next week', 'Send proposal', 'Schedule meeting', 'No action needed'][array_rand(['Call back next week', 'Send proposal', 'Schedule meeting', 'No action needed'])]);
            $activity->setOutcome($this->outcomes[array_rand($this->outcomes)]);
            $activity->setCompany($companies[array_rand($companies)]);
            $contact = $contacts[array_rand($contacts)];
            if ($this->em->contains($contact)) {
                $activity->setContact($contact);
            }
            $activity->setUser($user);
            $activity->setActivityDate(new \DateTime('-' . rand(1, 90) . ' days'));

            $this->em->persist($activity);
            $activities[] = $activity;
        }

        $this->em->flush();
        return $activities;
    }

    private function generateLeads(int $count): array
    {
        $leads = [];

        for ($i = 0; $i < $count; $i++) {
            $lead = new Lead();
            $lead->setCompanyName("Prospect Company " . ($i + 1));
            $lead->setWebsiteRoot("https://www.prospect-{$i}.com");
            $lead->setLeadUrl("https://www.prospect-{$i}.com/contact");
            $lead->setSectorTags([$this->sectors[array_rand($this->sectors)]]);
            $lead->setRegionTag('Africa');
            $lead->setReviewStatus(['pending', 'approved', 'rejected'][array_rand(['pending', 'approved', 'rejected'])]);
            $lead->setLeadScore(rand(50, 100));
            $lead->setCreatedAt(new \DateTime('-' . rand(1, 60) . ' days'));

            $this->em->persist($lead);
            $leads[] = $lead;
        }

        $this->em->flush();
        return $leads;
    }

    private function generateRFQs(array $companies, int $count): array
    {
        $rfqs = [];
        $statuses = ['Draft', 'Submitted', 'In Review', 'Won', 'Lost'];
        $types = ['Standard', 'Prototype', 'Production', 'Custom'];

        for ($i = 0; $i < $count; $i++) {
            $rfq = new RFQ();
            $rfq->setRfqNumber('RFQ-' . date('Y') . '-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT));
            $rfq->setCompany($companies[array_rand($companies)]);
            $rfq->setType($types[array_rand($types)]);
            $rfq->setTechnicalScope('RFQ for ' . ['Machined Parts', 'Sheet Metal', 'Assemblies', 'Prototypes'][array_rand(['Machined Parts', 'Sheet Metal', 'Assemblies', 'Prototypes'])] . '. Request for quotation on manufacturing components.');
            $rfq->setStatus($statuses[array_rand($statuses)]);
            $rfq->setEstimatedValue((string)rand(10000, 500000));
            $rfq->setVolumeAnnual(rand(100, 10000));
            $rfq->setNdaSent(rand(0, 1) === 1);
            $rfq->setNdaExecuted(rand(0, 1) === 1);
            $rfq->setSopDate(new \DateTime('+' . rand(30, 180) . ' days'));
            $rfq->setRfqDate(new \DateTime('-' . rand(1, 30) . ' days'));
            $rfq->setNotes('Generated test RFQ for development');
            $rfq->setCreatedAt(new \DateTime('-' . rand(1, 90) . ' days'));

            $this->em->persist($rfq);
            $rfqs[] = $rfq;
        }

        $this->em->flush();
        return $rfqs;
    }

    private function generateCompliance(array $companies, int $count): array
    {
        $documents = [];
        $docTypes = ['ISO 9001', 'AS9100', 'ISO 14001', 'Quality Manual'];

        for ($i = 0; $i < $count; $i++) {
            $doc = new ComplianceDocument();
            $doc->setCompany($companies[array_rand($companies)]);
            $docType = $docTypes[array_rand($docTypes)];
            $doc->setDocumentType($docType);
            $doc->setRequired(true);
            $doc->setProvided(rand(0, 1) === 1);
            
            if ($doc->isProvided()) {
                $doc->setFilePath($docType . '_certificate_' . $i . '.pdf');
                $doc->setUploadedAt(new \DateTime('-' . rand(1, 180) . ' days'));
                $doc->setExpiryDate(new \DateTime('+' . rand(30, 730) . ' days'));
            }

            $this->em->persist($doc);
            $documents[] = $doc;
        }

        $this->em->flush();
        return $documents;
    }
}
